<?php
declare (strict_types = 1);

namespace app\controller\api;

use app\BaseController;
use app\service\payment\PaymentGatewayService;
use think\facade\Log;

/**
 * 支付网关 API
 * 用户端获取可用支付方式
 */
class PaymentGatewayController extends BaseController
{
    public function enabledGateways()
    {
        try {
            $gateways = PaymentGatewayService::getEnabledGateways();
            $formatted = [];
            foreach ($gateways as $code => $info) {
                $formatted[] = [
                    'code'     => $code,
                    'name'     => $info['name'] ?? '',
                    'features' => $info['features'] ?? [],
                ];
            }

            return $this->jsonSuccess('获取成功', [
                'gateways' => $formatted,
                'count'    => count($formatted),
            ]);
        } catch (\Throwable $e) {
            Log::error('获取支付网关列表失败: ' . $e->getMessage());
            return $this->jsonError('获取失败', 500);
        }
    }

    public function getFeatures(string $code)
    {
        try {
            $features = PaymentGatewayService::getGatewayFeatures($code);
            if ($features === [] && !array_key_exists($code, PaymentGatewayService::getEnabledGateways())) {
                return $this->jsonError("网关不存在: {$code}", 404);
            }
            return $this->jsonSuccess('获取成功', [
                'code'     => $code,
                'features' => $features,
            ]);
        } catch (\Throwable $e) {
            Log::error("获取网关特性失败 ({$code}): " . $e->getMessage());
            return $this->jsonError('获取失败', 500);
        }
    }

    public function getInfo(string $code)
    {
        try {
            $gateways = PaymentGatewayService::getEnabledGateways();
            if (!isset($gateways[$code])) {
                return $this->jsonError("网关不存在: {$code}", 404);
            }

            return $this->jsonSuccess('获取成功', $gateways[$code]);
        } catch (\Throwable $e) {
            Log::error("获取网关信息失败 ({$code}): " . $e->getMessage());
            return $this->jsonError('获取失败', 500);
        }
    }
}
