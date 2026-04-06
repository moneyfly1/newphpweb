<?php
declare (strict_types = 1);

namespace app\service\payment;

use think\facade\Log;

/**
 * Stripe 支付网关
 * https://stripe.com/
 */
class StripeService extends PaymentGateway
{
    protected string $code = 'stripe';
    protected string $name = 'Stripe';
    protected array $features = [
        'card' => true,        // 信用卡支付
        'wallet' => true,      // 电子钱包
        'refund' => true,      // 支持退款
        'recurring' => true,   // 支持订阅
    ];

    public function validateConfig(): bool
    {
        return $this->requireConfig(['api_key', 'public_key']);
    }

    public function createPayment(array $params): array
    {
        if (!$this->validateConfig()) {
            throw new \RuntimeException('Stripe 配置不完整');
        }

        // Stripe 集成需要 stripe-php SDK
        // 这里提供基础框架
        return [
            'code'        => 'stripe',
            'trade_no'    => $params['trade_no'],
            'amount'      => $params['amount'],
            'payment_url' => 'https://checkout.stripe.com/example',  // 实际需要生成 Stripe checkout session
            'method'      => 'redirect',
            'session_id'  => null,
        ];
    }

    public function verifyCallback(array $callback): bool
    {
        // Stripe使用webhook签名验证
        $signature = $callback['stripe_signature'] ?? '';
        $payload = $callback['payload'] ?? '';
        
        $secret = $this->config['webhook_secret'] ?? '';
        if (empty($secret)) {
            return false;
        }

        $computedSignature = hash_hmac('sha256', $payload, $secret);
        return hash_equals($computedSignature, $signature);
    }

    public function handleCallback(array $callback): array
    {
        if (!$this->verifyCallback($callback)) {
            return [
                'success' => false,
                'message' => 'Stripe 签名验证失败',
                'code' => 'SIGNATURE_ERROR',
            ];
        }

        $event = $callback['type'] ?? '';
        
        if ($event === 'charge.succeeded') {
            return [
                'success' => true,
                'message' => '支付成功',
                'status' => 'paid',
            ];
        } elseif ($event === 'charge.failed') {
            return [
                'success' => false,
                'message' => '支付失败',
                'status' => 'failed',
            ];
        }

        return [
            'success' => false,
            'message' => '未知事件类型: ' . $event,
        ];
    }

    public function queryOrder(string $tradeNo): array
    {
        return [
            'status' => 'unknown',
            'message' => 'Stripe 订单查询需要集成 Stripe SDK',
        ];
    }
}
