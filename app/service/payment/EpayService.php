<?php
declare (strict_types = 1);

namespace app\service\payment;

use think\facade\Log;

/**
 * Epay 支付网关
 * https://www.epay.com/
 */
class EpayService extends PaymentGateway
{
    protected string $code = 'epay';
    protected string $name = 'Epay';
    protected array $features = [
        'card' => true,        // 卡支付
        'wallet' => true,      // 电子钱包
        'bank' => true,        // 银行转账
        'refund' => true,      // 支持退款
        'recurring' => false,  // 不支持订阅
    ];

    public function validateConfig(): bool
    {
        return $this->requireConfig(['merchant_id', 'api_key', 'api_url']);
    }

    public function createPayment(array $params): array
    {
        if (!$this->validateConfig()) {
            throw new \RuntimeException('Epay 配置不完整');
        }

        $outTradeNo = $params['trade_no'] ?? '';
        $money = number_format((float) ($params['amount'] ?? 0), 2, '.', '');
        $name = $params['subject'] ?? '订单支付';
        $type = $params['pay_type'] ?? 'alipay';
        $notifyUrl = $params['notify_url'] ?? '';
        $returnUrl = $params['return_url'] ?? '';

        // 构建签名参数
        $signParams = [
            'money'        => $money,
            'name'         => $name,
            'notify_url'   => $notifyUrl,
            'out_trade_no' => $outTradeNo,
            'pid'          => (string) $this->config['merchant_id'],
            'return_url'   => $returnUrl,
            'type'         => $type,
        ];

        // 过滤空值
        $signParams = array_filter($signParams, fn ($v) => $v !== '' && $v !== null);
        ksort($signParams);

        $parts = [];
        foreach ($signParams as $key => $value) {
            $parts[] = $key . '=' . $value;
        }
        $sign = md5(implode('&', $parts) . $this->config['api_key']);

        $requestParams = array_merge($signParams, [
            'sign'      => $sign,
            'sign_type' => 'MD5',
        ]);

        $apiUrl = rtrim($this->config['api_url'], '/');
        $paymentUrl = $apiUrl . '/submit.php?' . http_build_query($requestParams);

        return [
            'code'        => 'epay',
            'trade_no'    => $outTradeNo,
            'amount'      => (float) $money,
            'payment_url' => $paymentUrl,
            'method'      => 'redirect',
            'type'        => $type,
        ];
    }

    public function verifyCallback(array $callback): bool
    {
        // Epay 签名验证
        $sign = $callback['sign'] ?? '';
        unset($callback['sign'], $callback['sign_type']);

        // 过滤空值
        $callback = array_filter($callback, fn ($v) => $v !== '' && $v !== null);

        // 排序参数
        ksort($callback);
        $parts = [];
        foreach ($callback as $key => $value) {
            $parts[] = $key . '=' . $value;
        }
        $signStr = implode('&', $parts) . $this->config['api_key'];

        $computedSign = md5($signStr);
        return hash_equals($computedSign, $sign);
    }

    public function handleCallback(array $callback): array
    {
        if (!$this->verifyCallback($callback)) {
            return [
                'success' => false,
                'message' => 'Epay 签名验证失败',
                'code' => 'SIGNATURE_ERROR',
            ];
        }

        $tradeStatus = $callback['trade_status'] ?? '';

        if ($tradeStatus === 'TRADE_SUCCESS') {
            return [
                'success'    => true,
                'message'    => '支付成功',
                'trade_no'   => $callback['out_trade_no'] ?? '',
                'status'     => 'paid',
                'amount'     => (float) ($callback['money'] ?? 0),
                'channel_no' => $callback['trade_no'] ?? '',
                'pay_type'   => $callback['type'] ?? '',
            ];
        }

        return [
            'success'  => false,
            'message'  => '交易状态: ' . $tradeStatus,
            'trade_no' => $callback['out_trade_no'] ?? '',
            'status'   => $tradeStatus,
        ];
    }

    public function queryOrder(string $tradeNo): array
    {
        return [
            'status' => 'unknown',
            'message' => 'Epay 订单查询需要集成 Epay API',
        ];
    }
}
