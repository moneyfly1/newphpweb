<?php
declare (strict_types = 1);

namespace app\model;

class PaymentMethod extends BaseModel
{
    protected $name = 'payment_methods';

    protected $type = [
        'is_enabled'    => 'boolean',
        'need_config'   => 'boolean',
        'config_json'   => 'json',
        'sort_order'    => 'integer',
    ];

    /**
     * 获取所有启用的支付方式
     */
    public static function getEnabled()
    {
        return self::where('is_enabled', 1)
            ->order('sort_order', 'asc')
            ->select()
            ->toArray();
    }

    /**
     * 根据代码获取支付方式
     */
    public static function getByCode($code)
    {
        return self::where('code', $code)->findOrEmpty();
    }

    /**
     * 获取支付方式配置
     */
    public function getConfig(): array
    {
        if (empty($this->config_json)) {
            return [];
        }
        return is_array($this->config_json) ? $this->config_json : json_decode($this->config_json, true) ?? [];
    }

    /**
     * 设置支付方式配置
     */
    public function setConfig(array $config): self
    {
        $this->config_json = json_encode($config, JSON_UNESCAPED_UNICODE);
        return $this;
    }

    /**
     * 判断是否为余额支付
     */
    public function isBalance(): bool
    {
        return $this->code === 'balance';
    }

    /**
     * 判断是否为支付宝
     */
    public function isAlipay(): bool
    {
        return $this->code === 'alipay';
    }

    /**
     * 判断是否为微信支付
     */
    public function isWechat(): bool
    {
        return $this->code === 'wechat';
    }

    /**
     * 获取完整配置信息
     */
    public function getFullConfig(): array
    {
        return [
            'code'        => $this->code,
            'label'       => $this->label,
            'hint'        => $this->hint,
            'is_enabled'  => (bool) $this->is_enabled,
            'config'      => $this->getConfig(),
        ];
    }
}
