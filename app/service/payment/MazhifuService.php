<?php
declare (strict_types = 1);

namespace app\service\payment;

use think\facade\Log;

/**
 * 码支付 - 易支付网关
 * 基于易支付标准协议
 */
class MazhifuService extends PaymentGateway
{
    protected string $code = 'mazhifu';
    protected string $name = '码支付';
    protected array $features = [
        'alipay'  => true,
        'wxpay'   => true,
        'qqpay'   => true,
        'qrcode'  => true,
        'web'     => true,
        'mobile'  => true,
    ];

    private string $apiUrl = '';
    private string $submitUrl = '';
    private int $pid = 0;
    private string $key = '';

    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->pid = (int) ($config['pid'] ?? 0);
        $this->key = (string) ($config['key'] ?? '');
        $this->apiUrl = rtrim((string) ($config['api_url'] ?? 'https://mzf.akwl.net/xpay/epay/'), '/');
        $this->submitUrl = (string) ($config['submit_url'] ?? $this->apiUrl . '/submit.php');
    }

    public function validateConfig(): bool
    {
        return $this->pid > 0 && $this->key !== '';
    }

    /**
     * 创建支付订单
     * 返回支付页面 URL 或二维码信息
     */
    public function createPayment(array $params): array
    {
        if (!$this->validateConfig()) {
            throw new \RuntimeException('码支付配置不完整，请检查商户ID和密钥');
        }

        $type = $this->resolvePayType($params['pay_type'] ?? 'alipay');
        $outTradeNo = $params['trade_no'] ?? ('MZF_' . time() . '_' . mt_rand(1000, 9999));
        $money = number_format((float) ($params['amount'] ?? 0), 2, '.', '');
        $name = $params['subject'] ?? '订单支付';
        $notifyUrl = $params['notify_url'] ?? '';
        $returnUrl = $params['return_url'] ?? '';

        // 构建签名参数（按字母排序）
        $signParams = [
            'money'        => $money,
            'name'         => $name,
            'notify_url'   => $notifyUrl,
            'out_trade_no' => $outTradeNo,
            'pid'          => (string) $this->pid,
            'return_url'   => $returnUrl,
            'type'         => $type,
        ];

        $sign = $this->generateSign($signParams);

        // 构建完整请求参数
        $requestParams = array_merge($signParams, [
            'sign'      => $sign,
            'sign_type' => 'MD5',
        ]);

        // 生成支付页面 URL
        $paymentUrl = $this->submitUrl . '?' . http_build_query($requestParams);

        // 同时尝试 MAPI 获取二维码
        $qrCode = null;
        try {
            $mapiUrl = str_replace('submit.php', 'mapi.php', $this->submitUrl);
            $mapiResponse = $this->httpPost($mapiUrl, $requestParams);
            if ($mapiResponse) {
                $data = json_decode($mapiResponse, true);
                if (($data['code'] ?? -1) == 1 && !empty($data['qrcode'])) {
                    $qrCode = $data['qrcode'];
                } elseif (!empty($data['payurl'])) {
                    $qrCode = $data['payurl'];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('码支付MAPI获取二维码失败: ' . $e->getMessage());
        }

        return [
            'code'        => $this->code,
            'trade_no'    => $outTradeNo,
            'amount'      => (float) $money,
            'payment_url' => $paymentUrl,
            'qr_code'     => $qrCode,
            'method'      => $qrCode ? 'qrcode' : 'redirect',
            'type'        => $type,
        ];
    }

    /**
     * 验证回调签名
     */
    public function verifyCallback(array $callback): bool
    {
        $sign = $callback['sign'] ?? '';
        unset($callback['sign'], $callback['sign_type']);

        // 过滤空值并排序
        $filtered = array_filter($callback, fn ($v) => $v !== '' && $v !== null);
        ksort($filtered);

        $expectedSign = $this->generateSign($filtered);
        return hash_equals($expectedSign, $sign);
    }

    /**
     * 处理支付回调
     */
    public function handleCallback(array $callback): array
    {
        try {
            if (!$this->verifyCallback($callback)) {
                return [
                    'success'  => false,
                    'message'  => '签名验证失败',
                    'code'     => 'SIGNATURE_ERROR',
                ];
            }

            $tradeNo = $callback['out_trade_no'] ?? '';
            $tradeStatus = $callback['trade_status'] ?? '';

            if ($tradeStatus === 'TRADE_SUCCESS') {
                return [
                    'success'    => true,
                    'message'    => '支付成功',
                    'trade_no'   => $tradeNo,
                    'status'     => 'paid',
                    'amount'     => (float) ($callback['money'] ?? 0),
                    'channel_no' => $callback['trade_no'] ?? '',
                    'pay_type'   => $callback['type'] ?? '',
                ];
            }

            return [
                'success'  => false,
                'message'  => '交易状态: ' . $tradeStatus,
                'trade_no' => $tradeNo,
                'status'   => $tradeStatus,
            ];
        } catch (\Exception $e) {
            Log::error('码支付回调处理异常: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'code'    => 'EXCEPTION',
            ];
        }
    }

    /**
     * 查询订单状态
     */
    public function queryOrder(string $tradeNo): array
    {
        try {
            $params = [
                'act'          => 'order',
                'pid'          => (string) $this->pid,
                'out_trade_no' => $tradeNo,
            ];
            $params['sign'] = $this->generateSign($params);
            $params['sign_type'] = 'MD5';

            $url = $this->apiUrl . '/api.php?' . http_build_query($params);
            $response = $this->httpGet($url);

            if (!$response) {
                return ['status' => 'unknown', 'message' => '查询失败'];
            }

            $data = json_decode($response, true);
            if (($data['code'] ?? -1) == 1) {
                return [
                    'status'     => $data['status'] == 1 ? 'paid' : 'pending',
                    'trade_no'   => $data['trade_no'] ?? '',
                    'amount'     => (float) ($data['money'] ?? 0),
                    'message'    => '查询成功',
                ];
            }

            return ['status' => 'unknown', 'message' => $data['msg'] ?? '查询失败'];
        } catch (\Throwable $e) {
            return ['status' => 'unknown', 'message' => $e->getMessage()];
        }
    }

    public function getFeatures(): array
    {
        return $this->features;
    }

    /**
     * 生成 MD5 签名
     */
    private function generateSign(array $params): string
    {
        // 按 key 字母排序
        ksort($params);

        // 拼接成 key=value& 格式
        $parts = [];
        foreach ($params as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            $parts[] = $key . '=' . $value;
        }

        $signStr = implode('&', $parts) . $this->key;
        return md5($signStr);
    }

    /**
     * 解析支付类型
     */
    private function resolvePayType(string $type): string
    {
        return match (strtolower($type)) {
            'alipay', 'zfb'     => 'alipay',
            'wxpay', 'wechat'   => 'wxpay',
            'qqpay', 'qq'       => 'qqpay',
            default              => 'alipay',
        };
    }

    /**
     * HTTP POST 请求
     */
    private function httpPost(string $url, array $data): ?string
    {
        $context = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'timeout' => 15,
                'header'  => "User-Agent: CBoard/1.0\r\nContent-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($data),
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $result = @file_get_contents($url, false, $context);
        return $result !== false ? $result : null;
    }

    /**
     * HTTP GET 请求
     */
    private function httpGet(string $url): ?string
    {
        $context = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => 15,
                'header'  => "User-Agent: CBoard/1.0\r\n",
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $result = @file_get_contents($url, false, $context);
        return $result !== false ? $result : null;
    }
}
