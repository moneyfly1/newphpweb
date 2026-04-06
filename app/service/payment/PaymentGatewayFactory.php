<?php
declare (strict_types = 1);

namespace app\service\payment;

use think\facade\Config;
use think\facade\Log;
use RuntimeException;

/**
 * 支付网关工厂类
 * 负责管理和实例化所有支付网关
 */
class PaymentGatewayFactory
{
    /**
     * 已注册的支付网关映射
     * @var array
     */
    protected static array $gateways = [];

    /**
     * 网关实例缓存
     * @var array
     */
    protected static array $instances = [];

    /**
     * 已加载的网关
     * @var bool
     */
    protected static bool $loaded = false;

    /**
     * 注册支付网关
     * 
     * @param string $code 网关代码
     * @param string $className 网关类名
     */
    public static function register(string $code, string $className): void
    {
        self::$gateways[$code] = $className;
    }

    /**
     * 获取或创建网关实例
     * 
     * @param string $code 网关代码
     * @param array $config 网关配置
     * @return PaymentGatewayInterface
     */
    public static function create(string $code, array $config = []): PaymentGatewayInterface
    {
        self::ensureLoaded();

        if (!isset(self::$gateways[$code])) {
            throw new RuntimeException("无效的支付网关: {$code}");
        }

        // 生成缓存键
        $cacheKey = $code . '_' . md5(json_encode($config));

        // 从缓存返回实例
        if (isset(self::$instances[$cacheKey])) {
            return self::$instances[$cacheKey];
        }

        // 创建新实例
        $className = self::$gateways[$code];
        if (!class_exists($className)) {
            throw new RuntimeException("支付网关类不存在: {$className}");
        }

        $gateway = new $className();
        if (!($gateway instanceof PaymentGatewayInterface)) {
            throw new RuntimeException("网关类必须实现 PaymentGatewayInterface: {$className}");
        }

        // 初始化配置
        if (empty($config)) {
            $config = self::getGatewayConfig($code);
        }
        $gateway->initialize($config);

        // 缓存实例
        self::$instances[$cacheKey] = $gateway;

        return $gateway;
    }

    /**
     * 获取所有已注册的网关
     * 
     * @return array 格式: ['code' => 'className', ...]
     */
    public static function getAllGateways(): array
    {
        self::ensureLoaded();
        return self::$gateways;
    }

    /**
     * 检查网关是否已注册
     * 
     * @param string $code 网关代码
     * @return bool
     */
    public static function has(string $code): bool
    {
        self::ensureLoaded();
        return isset(self::$gateways[$code]);
    }

    /**
     * 获取网关配置
     * 
     * @param string $code 网关代码
     * @return array
     */
    public static function getGatewayConfig(string $code): array
    {
        $paymentConfig = Config::get('payment', []);
        return $paymentConfig[$code] ?? [];
    }

    /**
     * 保存网关配置
     * 
     * @param string $code 网关代码
     * @param array $config 配置数据
     */
    public static function saveGatewayConfig(string $code, array $config): void
    {
        $paymentConfig = Config::get('payment', []);
        $paymentConfig[$code] = $config;
        Config::set(['payment' => $paymentConfig]);

        // 清除缓存实例
        self::$instances = [];
    }

    /**
     * 获取单个网关信息
     * 
     * @param string $code 网关代码
     * @return array 包含 code, name, features 等
     */
    public static function getGatewayInfo(string $code): array
    {
        if (!self::has($code)) {
            throw new RuntimeException("无效的支付网关: {$code}");
        }

        $config = self::getGatewayConfig($code);
        try {
            $gateway = self::create($code, $config);
            return [
                'code'     => $gateway->getCode(),
                'name'     => $gateway->getName(),
                'features' => $gateway->getFeatures(),
                'enabled'  => $config['enabled'] ?? false,
            ];
        } catch (\Throwable $e) {
            Log::error("Failed to get gateway info for {$code}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 获取所有网关信息
     * 
     * @return array
     */
    public static function getAllGatewayInfo(): array
    {
        self::ensureLoaded();
        $info = [];

        foreach (self::$gateways as $code => $class) {
            try {
                $info[$code] = self::getGatewayInfo($code);
            } catch (\Throwable $e) {
                Log::warning("Failed to get info for gateway {$code}: " . $e->getMessage());
            }
        }

        return $info;
    }

    /**
     * 获取已启用的网关列表
     * 
     * @return array
     */
    public static function getEnabledGateways(): array
    {
        self::ensureLoaded();
        $enabled = [];

        foreach (self::$gateways as $code => $class) {
            $config = self::getGatewayConfig($code);
            if ($config['enabled'] ?? false) {
                try {
                    $enabled[$code] = self::getGatewayInfo($code);
                } catch (\Throwable $e) {
                    Log::warning("Failed to get enabled gateway {$code}: " . $e->getMessage());
                }
            }
        }

        return $enabled;
    }

    /**
     * 测试网关配置
     * 
     * @param string $code 网关代码
     * @param array $config 配置数据
     * @return bool
     */
    public static function testGateway(string $code, array $config = []): bool
    {
        try {
            $gateway = self::create($code, $config);
            return $gateway->validateConfig();
        } catch (\Throwable $e) {
            Log::error("Gateway test failed for {$code}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 清除缓存
     */
    public static function clearCache(): void
    {
        self::$instances = [];
    }

    /**
     * 注册默认网关
     */
    protected static function ensureLoaded(): void
    {
        if (self::$loaded) {
            return;
        }

        // 注册所有可用的支付网关
        self::register('alipay', AlipayService::class);
        self::register('stripe', StripeService::class);
        self::register('paypal', PaypalService::class);
        self::register('epay', EpayService::class);
        self::register('usdt', USDTService::class);
        self::register('mazhifu', MazhifuService::class);

        // 允许通过配置文件添加自定义网关
        $customGateways = Config::get('payment.custom_gateways', []);
        foreach ($customGateways as $code => $className) {
            self::register($code, $className);
        }

        self::$loaded = true;
    }
}
