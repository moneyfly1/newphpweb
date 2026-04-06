<?php
declare (strict_types = 1);

namespace app\service\payment;

/**
 * 支付网关接口
 * 所有支付服务都必须实现此接口
 */
interface PaymentGatewayInterface
{
    /**
     * 获取网关代码
     */
    public function getCode(): string;

    /**
     * 获取网关显示名称
     */
    public function getName(): string;

    /**
     * 初始化网关
     */
    public function initialize(array $config): void;

    /**
     * 创建支付请求
     * 返回包含支付跳转URL或二维码等信息
     */
    public function createPayment(array $params): array;

    /**
     * 验证支付回调
     */
    public function verifyCallback(array $callback): bool;

    /**
     * 处理支付回调
     * 返回回调处理结果
     */
    public function handleCallback(array $callback): array;

    /**
     * 查询订单状态
     */
    public function queryOrder(string $tradeNo): array;

    /**
     * 验证网关配置是否有效
     */
    public function validateConfig(): bool;

    /**
     * 获取网关支持的特性
     */
    public function getFeatures(): array;
}
