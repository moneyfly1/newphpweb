<?php
declare (strict_types = 1);

namespace app\model;

class SubscriptionFormatConfig extends BaseModel
{
    protected $name = 'subscription_format_configs';

    protected $type = [
        'supported_protocols' => 'json',
        'features'            => 'json',
        'config_json'         => 'json',
        'is_enabled'          => 'boolean',
        'priority'            => 'integer',
    ];

    /**
     * 获取所有启用的格式
     */
    public static function getEnabledFormats()
    {
        return static::where('is_enabled', 1)
            ->order('priority', 'desc')
            ->select();
    }

    /**
     * 按代码获取格式
     */
    public static function getByCode($code)
    {
        return static::where('format_code', $code)->find();
    }

    /**
     * 获取格式信息数组
     */
    public static function getFormatsArray()
    {
        $formats = self::getEnabledFormats();
        $result = [];
        foreach ($formats as $format) {
            $result[$format->format_code] = [
                'name'                => $format->format_name,
                'description'         => $format->description,
                'supported_protocols' => $format->supported_protocols ?? [],
                'features'            => $format->features ?? [],
                'code'                => $format->format_code,
            ];
        }
        return $result;
    }
}
