<?php
declare (strict_types = 1);

namespace app\service\payment;

use think\facade\Log;

/**
 * 支付网关协调服务
 */
class PaymentGatewayService
{
    public static function initiate(
        string $gatewayCode,
        string $type,
        int $userId,
        float $amount,
        array $params = []
    ): array {
        return PaymentService::initiateGatewayPayment($gatewayCode, $type, $userId, $amount, $params);
    }

    public static function handleCallback(string $gatewayCode, array $callback): array
    {
        return PaymentService::handleGatewayCallback($gatewayCode, $callback);
    }

    public static function queryOrder(string $gatewayCode, string $tradeNo): array
    {
        try {
            $gateway = PaymentGatewayFactory::create($gatewayCode);
            return $gateway->queryOrder($tradeNo);
        } catch (\Throwable $e) {
            Log::error("查询订单失败 ({$gatewayCode}, {$tradeNo}): " . $e->getMessage());
            return [
                'status'  => 'unknown',
                'message' => '查询失败',
                'error'   => $e->getMessage(),
            ];
        }
    }

    public static function getEnabledGateways(): array
    {
        return PaymentGatewayFactory::getEnabledGateways();
    }

    public static function getGatewayFeatures(string $gatewayCode): array
    {
        try {
            $gateway = PaymentGatewayFactory::create($gatewayCode);
            return $gateway->getFeatures();
        } catch (\Throwable $e) {
            return [];
        }
    }
}
