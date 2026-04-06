<?php
declare (strict_types = 1);

namespace app\controller\admin\api;

use app\BaseController;
use app\service\payment\PaymentGatewayFactory;
use think\facade\Config;
use think\facade\Log;

/**
 * 支付网关管理 API
 */
class PaymentGatewayController extends BaseController
{
    /**
     * 获取所有支付网关
     */
    public function list()
    {
        try {
            $gateways = PaymentGatewayFactory::getAllGatewayInfo();
            return $this->jsonSuccess('获取成功', $gateways);
        } catch (\Throwable $e) {
            Log::error('获取支付网关列表失败: ' . $e->getMessage());
            return $this->jsonError('获取失败: ' . $e->getMessage(), 500);
        }
    }

    /**
     * 获取已启用的支付网关
     */
    public function enabledList()
    {
        try {
            $gateways = PaymentGatewayFactory::getEnabledGateways();
            return $this->jsonSuccess('获取成功', $gateways);
        } catch (\Throwable $e) {
            Log::error('获取已启用支付网关失败: ' . $e->getMessage());
            return $this->jsonError('获取失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取单个网关配置
     * 
     * @param string $code 网关代码
     */
    public function getConfig(string $code)
    {
        try {
            if (!PaymentGatewayFactory::has($code)) {
                return $this->jsonError("网关不存在: {$code}");
            }

            $config = PaymentGatewayFactory::getGatewayConfig($code);
            $info = PaymentGatewayFactory::getGatewayInfo($code);

            return $this->jsonSuccess('获取成功', [
                'code'     => $code,
                'info'     => $info,
                'config'   => $this->sanitizeConfig($config),
            ]);
        } catch (\Throwable $e) {
            Log::error("获取网关配置失败 ({$code}): " . $e->getMessage());
            return $this->jsonError('获取失败: ' . $e->getMessage());
        }
    }

    /**
     * 保存网关配置
     * 
     * @param string $code 网关代码
     */
    public function saveConfig(string $code)
    {
        try {
            if (!PaymentGatewayFactory::has($code)) {
                return $this->jsonError("网关不存在: {$code}");
            }

            $data = $this->request->post();
            
            if (empty($data)) {
                return $this->jsonError("配置数据为空");
            }

            // 保存配置
            PaymentGatewayFactory::saveGatewayConfig($code, $data);

            Log::info("保存网关配置: {$code}, 数据: " . json_encode($this->sanitizeConfig($data)));

            return $this->jsonSuccess('保存成功');
        } catch (\Throwable $e) {
            Log::error("保存网关配置失败 ({$code}): " . $e->getMessage());
            return $this->jsonError('保存失败: ' . $e->getMessage());
        }
    }

    /**
     * 测试网关配置
     * 
     * @param string $code 网关代码
     */
    public function test(string $code)
    {
        try {
            if (!PaymentGatewayFactory::has($code)) {
                return $this->jsonError("网关不存在: {$code}");
            }

            $config = $this->request->post();
            
            if (empty($config)) {
                $config = PaymentGatewayFactory::getGatewayConfig($code);
            }

            $success = PaymentGatewayFactory::testGateway($code, $config);

            if ($success) {
                return $this->jsonSuccess('配置有效');
            } else {
                return $this->jsonError('配置验证失败');
            }
        } catch (\Throwable $e) {
            Log::error("测试网关配置失败 ({$code}): " . $e->getMessage());
            return $this->jsonError('测试失败: ' . $e->getMessage());
        }
    }

    /**
     * 启用/禁用网关
     * 
     * @param string $code 网关代码
     */
    public function setEnabled(string $code)
    {
        try {
            if (!PaymentGatewayFactory::has($code)) {
                return $this->jsonError("网关不存在: {$code}");
            }

            $enabled = $this->request->post('enabled', false);
            $config = PaymentGatewayFactory::getGatewayConfig($code);
            $config['enabled'] = (bool)$enabled;

            PaymentGatewayFactory::saveGatewayConfig($code, $config);

            Log::info("设置网关状态: {$code}, enabled={$enabled}");

            return $this->jsonSuccess('设置成功');
        } catch (\Throwable $e) {
            Log::error("设置网关状态失败 ({$code}): " . $e->getMessage());
            return $this->jsonError('设置失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取网关配置模板
     * 
     * @param string $code 网关代码
     */
    public function configTemplate(string $code)
    {
        try {
            if (!PaymentGatewayFactory::has($code)) {
                return $this->jsonError("网关不存在: {$code}");
            }

            $template = $this->getConfigSchema($code);
            return $this->jsonSuccess('获取成功', $template);
        } catch (\Throwable $e) {
            Log::error("获取配置模板失败 ({$code}): " . $e->getMessage());
            return $this->jsonError('获取失败: ' . $e->getMessage());
        }
    }

    /**
     * 获取配置表单字段定义
     * 
     * @param string $code 网关代码
     * @return array 字段定义
     */
    protected function getConfigSchema(string $code): array
    {
        return match($code) {
            'alipay' => [
                'app_id' => [
                    'label'      => '应用ID',
                    'type'       => 'text',
                    'required'   => true,
                    'placeholder' => '支付宝应用ID',
                ],
                'private_key' => [
                    'label'      => '私钥',
                    'type'       => 'textarea',
                    'required'   => true,
                    'placeholder' => '商户私钥（RSA2格式）',
                ],
                'public_key' => [
                    'label'      => '支付宝公钥',
                    'type'       => 'textarea',
                    'required'   => true,
                    'placeholder' => '支付宝公钥（RSA2格式）',
                ],
                'notify_url' => [
                    'label'      => '异步回调地址',
                    'type'       => 'text',
                    'required'   => true,
                    'placeholder' => 'https://your-domain.com/api/payment-callback/alipay',
                ],
                'return_url' => [
                    'label'      => '同步跳转地址',
                    'type'       => 'text',
                    'required'   => false,
                    'placeholder' => 'https://your-domain.com/payment-result',
                ],
                'enabled' => [
                    'label'      => '启用',
                    'type'       => 'checkbox',
                    'required'   => false,
                ],
            ],
            'stripe' => [
                'api_key' => [
                    'label'      => 'API 密钥',
                    'type'       => 'password',
                    'required'   => true,
                    'placeholder' => 'sk_live_...',
                ],
                'public_key' => [
                    'label'      => '公钥',
                    'type'       => 'text',
                    'required'   => true,
                    'placeholder' => 'pk_live_...',
                ],
                'webhook_secret' => [
                    'label'      => 'Webhook 密钥',
                    'type'       => 'password',
                    'required'   => false,
                    'placeholder' => 'whsec_...',
                ],
                'enabled' => [
                    'label'      => '启用',
                    'type'       => 'checkbox',
                    'required'   => false,
                ],
            ],
            'paypal' => [
                'client_id' => [
                    'label'      => 'Client ID',
                    'type'       => 'text',
                    'required'   => true,
                    'placeholder' => 'PayPal 应用 ID',
                ],
                'client_secret' => [
                    'label'      => 'Client Secret',
                    'type'       => 'password',
                    'required'   => true,
                    'placeholder' => 'PayPal 应用密钥',
                ],
                'mode' => [
                    'label'      => '模式',
                    'type'       => 'select',
                    'options'    => ['sandbox' => '沙箱', 'production' => '生产'],
                    'required'   => true,
                ],
                'enabled' => [
                    'label'      => '启用',
                    'type'       => 'checkbox',
                    'required'   => false,
                ],
            ],
            'epay' => [
                'merchant_id' => [
                    'label'      => '商户ID',
                    'type'       => 'text',
                    'required'   => true,
                    'placeholder' => 'Epay 商户ID',
                ],
                'api_key' => [
                    'label'      => 'API 密钥',
                    'type'       => 'password',
                    'required'   => true,
                    'placeholder' => 'Epay API 密钥',
                ],
                'enabled' => [
                    'label'      => '启用',
                    'type'       => 'checkbox',
                    'required'   => false,
                ],
            ],
            'usdt' => [
                'wallet_address' => [
                    'label'      => '收款钱包地址',
                    'type'       => 'text',
                    'required'   => true,
                    'placeholder' => '接收 USDT 的钱包地址',
                ],
                'chain_type' => [
                    'label'      => '链类型',
                    'type'       => 'select',
                    'options'    => ['trc20' => 'Tron (TRC20)', 'erc20' => 'Ethereum (ERC20)'],
                    'required'   => true,
                ],
                'required_confirmations' => [
                    'label'      => '所需确认数',
                    'type'       => 'number',
                    'required'   => false,
                    'placeholder' => '6',
                ],
                'enabled' => [
                    'label'      => '启用',
                    'type'       => 'checkbox',
                    'required'   => false,
                ],
            ],
            default => [],
        };
    }

    /**
     * 隐藏敏感配置信息
     * 
     * @param array $config
     * @return array
     */
    protected function sanitizeConfig(array $config): array
    {
        $sensitiveKeys = ['private_key', 'api_key', 'client_secret', 'webhook_secret'];
        $sanitized = $config;

        foreach ($sensitiveKeys as $key) {
            if (isset($sanitized[$key])) {
                $sanitized[$key] = '***' . substr($sanitized[$key], -4);
            }
        }

        return $sanitized;
    }
}
