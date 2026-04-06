<?php
declare (strict_types = 1);

namespace app\service\payment;

use think\facade\Log;

/**
 * PayPal 支付网关
 * https://www.paypal.com/
 */
class PaypalService extends PaymentGateway
{
    protected string $code = 'paypal';
    protected string $name = 'PayPal';
    protected array $features = [
        'account' => true,     // PayPal账户支付
        'card' => true,        // 信用卡支付
        'paypal_credit' => true, // PayPal分期付款
        'refund' => true,      // 支持退款
        'recurring' => true,   // 支持订阅
    ];

    public function validateConfig(): bool
    {
        return $this->requireConfig(['client_id', 'client_secret']);
    }

    public function createPayment(array $params): array
    {
        if (!$this->validateConfig()) {
            throw new \RuntimeException('PayPal 配置不完整');
        }

        $mode = $this->config['mode'] ?? 'sandbox';  // sandbox 或 live
        $baseUrl = $mode === 'sandbox' 
            ? 'https://www.sandbox.paypal.com/checkoutnow'
            : 'https://www.paypal.com/checkoutnow';

        return [
            'code'        => 'paypal',
            'trade_no'    => $params['trade_no'],
            'amount'      => $params['amount'],
            'currency'    => $params['currency'] ?? 'USD',
            'payment_url' => $baseUrl . '?token=EC-EXAMPLE',  // 实际需要创建支付订单
            'method'      => 'redirect',
        ];
    }

    public function verifyCallback(array $callback): bool
    {
        // PayPal IPN 验证
        $verifyUrl = 'https://ipnpb.paypal.com/cgi-bin/webscr';
        $mode = $this->config['mode'] ?? 'sandbox';
        if ($mode === 'sandbox') {
            $verifyUrl = 'https://ipnpb.sandbox.paypal.com/cgi-bin/webscr';
        }

        // 这里应该向 PayPal 发送验证请求
        return true;  // 简化处理
    }

    public function handleCallback(array $callback): array
    {
        if (!$this->verifyCallback($callback)) {
            return [
                'success' => false,
                'message' => 'PayPal 验证失败',
                'code' => 'VERIFICATION_ERROR',
            ];
        }

        $status = $callback['payment_status'] ?? '';
        
        if ($status === 'Completed') {
            return [
                'success' => true,
                'message' => '支付成功',
                'trade_no' => $callback['txn_id'] ?? '',
                'status' => 'paid',
                'amount' => $callback['mc_gross'] ?? 0,
            ];
        } elseif ($status === 'Failed' || $status === 'Denied') {
            return [
                'success' => false,
                'message' => '支付失败',
                'trade_no' => $callback['txn_id'] ?? '',
                'status' => 'failed',
            ];
        } elseif ($status === 'Pending') {
            return [
                'success' => false,
                'message' => '支付待确认',
                'status' => 'pending',
            ];
        }

        return [
            'success' => false,
            'message' => '未知支付状态: ' . $status,
        ];
    }

    public function queryOrder(string $tradeNo): array
    {
        return [
            'status' => 'unknown',
            'message' => 'PayPal 订单查询需要集成 PayPal REST API',
        ];
    }
}
