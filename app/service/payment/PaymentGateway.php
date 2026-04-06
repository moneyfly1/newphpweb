<?php
declare (strict_types = 1);

namespace app\service\payment;

/**
 * 支付网关抽象基类
 */
abstract class PaymentGateway implements PaymentGatewayInterface
{
    protected string $code;
    protected string $name;
    protected array $config = [];
    protected array $features = [];

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function initialize(array $config): void
    {
        $this->config = array_merge($this->config, $config);
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function getFeatures(): array
    {
        return $this->features;
    }

    /**
     * 检查必需的配置参数
     */
    protected function requireConfig(array $keys): bool
    {
        foreach ($keys as $key) {
            if (empty($this->config[$key])) {
                return false;
            }
        }
        return true;
    }

    /**
     * 获取配置值
     */
    protected function getConfigValue(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * 验证网关配置是否有效
     */
    abstract public function validateConfig(): bool;

    /**
     * 创建支付请求
     */
    abstract public function createPayment(array $params): array;

    /**
     * 验证支付回调
     */
    abstract public function verifyCallback(array $callback): bool;

    /**
     * 处理支付回调
     */
    abstract public function handleCallback(array $callback): array;

    /**
     * 查询订单状态
     */
    abstract public function queryOrder(string $tradeNo): array;
}
