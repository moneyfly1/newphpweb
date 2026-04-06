<?php
declare (strict_types = 1);

namespace app\service\payment;

use think\facade\Log;

/**
 * 支付宝支付网关
 * 基于支付宝开放平台 SDK (https://github.com/alipay/alipay-sdk-php-all)
 */
class AlipayService extends PaymentGateway
{
    protected string $code = 'alipay';
    protected string $name = '支付宝';
    protected array $features = [
        'qrcode' => true,      // 二维码支付
        'web' => true,         // 网页支付
        'app' => true,         // APP支付
        'refund' => true,      // 支持退款
        'recurring' => false,  // 不支持订阅
    ];

    private string $apiVersion = '1.0';
    private string $charset = 'UTF-8';
    private string $format = 'JSON';
    private string $signType = 'RSA2';

    public function validateConfig(): bool
    {
        return $this->requireConfig(['app_id', 'private_key', 'public_key', 'notify_url']);
    }

    /**
     * 生成支付二维码链接
     * 使用 web 端支付
     */
    public function createPayment(array $params): array
    {
        if (!$this->validateConfig()) {
            throw new \RuntimeException('支付宝配置不完整');
        }

        $bizContent = [
            'out_trade_no'  => $params['trade_no'],
            'total_amount'  => number_format($params['amount'], 2, '.', ''),
            'subject'       => $params['subject'] ?? '账户充值',
            'body'          => $params['body'] ?? '充值金额: ' . $params['amount'],
            'timeout_express' => '15m',
        ];

        $requestParams = [
            'app_id'          => $this->config['app_id'],
            'method'          => 'alipay.trade.page.pay',
            'format'          => $this->format,
            'charset'         => $this->charset,
            'sign_type'       => $this->signType,
            'timestamp'       => date('Y-m-d H:i:s'),
            'version'         => $this->apiVersion,
            'notify_url'      => $this->config['notify_url'],
            'return_url'      => $this->config['return_url'] ?? '',
            'biz_content'     => json_encode($bizContent, JSON_UNESCAPED_UNICODE),
        ];

        $sign = $this->sign($requestParams);
        $requestParams['sign'] = $sign;

        $gateway = $this->config['gateway_url'] ?? 'https://openapi.alipay.com/gateway.do';

        return [
            'code'        => 'alipay',
            'trade_no'    => $params['trade_no'],
            'amount'      => $params['amount'],
            'payment_url' => $gateway . '?' . http_build_query($requestParams),
            'qr_code'     => null,  // 二维码需要额外API调用
            'method'      => 'redirect',  // 重定向到支付宝
        ];
    }

    /**
     * 验证回调签名
     */
    public function verifyCallback(array $callback): bool
    {
        // 获取签名
        $sign = $callback['sign'] ?? '';
        unset($callback['sign'], $callback['sign_type']);

        // 排序并生成待签名字符串
        ksort($callback);
        $content = '';
        foreach ($callback as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            $content .= $key . '=' . $value . '&';
        }
        $content = rtrim($content, '&');

        // 验证签名
        return $this->verify($content, $sign);
    }

    /**
     * 处理支付回调
     */
    public function handleCallback(array $callback): array
    {
        try {
            if (!$this->verifyCallback($callback)) {
                return [
                    'success' => false,
                    'message' => '回调签名验证失败',
                    'code' => 'SIGNATURE_ERROR',
                ];
            }

            $tradeNo = $callback['out_trade_no'] ?? '';
            $status = $callback['trade_status'] ?? '';

            if ($status === 'TRADE_SUCCESS' || $status === 'TRADE_FINISHED') {
                return [
                    'success' => true,
                    'message' => '支付成功',
                    'trade_no' => $tradeNo,
                    'status' => 'paid',
                    'amount' => $callback['total_amount'] ?? 0,
                ];
            } elseif ($status === 'TRADE_CLOSED') {
                return [
                    'success' => false,
                    'message' => '交易已关闭',
                    'trade_no' => $tradeNo,
                    'status' => 'closed',
                ];
            } else {
                return [
                    'success' => false,
                    'message' => '交易状态: ' . $status,
                    'trade_no' => $tradeNo,
                    'status' => $status,
                ];
            }

        } catch (\Exception $e) {
            Log::error('支付宝回调处理异常: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'code' => 'EXCEPTION',
            ];
        }
    }

    /**
     * 查询订单状态
     */
    public function queryOrder(string $tradeNo): array
    {
        try {
            if (!$this->validateConfig()) {
                throw new \RuntimeException('支付宝配置不完整');
            }

            $bizContent = [
                'out_trade_no' => $tradeNo,
            ];

            $requestParams = [
                'app_id'      => $this->config['app_id'],
                'method'      => 'alipay.trade.query',
                'format'      => $this->format,
                'charset'     => $this->charset,
                'sign_type'   => $this->signType,
                'timestamp'   => date('Y-m-d H:i:s'),
                'version'     => $this->apiVersion,
                'biz_content' => json_encode($bizContent, JSON_UNESCAPED_UNICODE),
            ];

            $sign = $this->sign($requestParams);
            $requestParams['sign'] = $sign;

            // 这里需要用 curl 发送 POST 请求到支付宝API
            // 返回示例（实际需要调用真实API）
            return [
                'trade_no'   => '',
                'status'     => 'WAIT_BUYER_PAY',
                'amount'     => 0,
                'paid_time'  => null,
            ];

        } catch (\Exception $e) {
            Log::error('支付宝查询订单失败: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 生成签名
     */
    private function sign(array $params): string
    {
        ksort($params);
        $content = '';
        foreach ($params as $key => $value) {
            if ($value === '' || $value === null || $key === 'sign' || $key === 'sign_type') {
                continue;
            }
            $content .= $key . '=' . $value . '&';
        }
        $content = rtrim($content, '&');

        $privateKey = $this->config['private_key'];
        
        // 如果是文件路径，读取内容
        if (is_file($privateKey)) {
            $privateKey = file_get_contents($privateKey);
        }

        // 转换为 PKEYformat
        $privateKey = str_replace(['-----BEGIN RSA PRIVATE KEY-----', '-----END RSA PRIVATE KEY-----', '\n', '\r'], '', $privateKey);
        $privateKey = '-----BEGIN RSA PRIVATE KEY-----' . PHP_EOL . chunk_split($privateKey, 64, PHP_EOL) . '-----END RSA PRIVATE KEY-----';

        $res = openssl_get_privatekey($privateKey);
        if (!$res) {
            throw new \RuntimeException('支付宝私钥格式有误');
        }

        openssl_sign($content, $sign, $res, OPENSSL_ALGO_SHA256);
        openssl_free_key($res);

        return base64_encode($sign);
    }

    /**
     * 验证签名
     */
    private function verify(string $content, string $signature): bool
    {
        $publicKey = $this->config['public_key'];
        
        // 如果是文件路径，读取内容
        if (is_file($publicKey)) {
            $publicKey = file_get_contents($publicKey);
        }

        // 转换为 PKEY format
        $publicKey = str_replace(['-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----', '\n', '\r'], '', $publicKey);
        $publicKey = '-----BEGIN PUBLIC KEY-----' . PHP_EOL . chunk_split($publicKey, 64, PHP_EOL) . '-----END PUBLIC KEY-----';

        $res = openssl_get_publickey($publicKey);
        if (!$res) {
            throw new \RuntimeException('支付宝公钥格式有误');
        }

        $result = openssl_verify($content, base64_decode($signature), $res, OPENSSL_ALGO_SHA256);
        openssl_free_key($res);

        return $result === 1;
    }
}

