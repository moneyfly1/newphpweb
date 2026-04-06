<?php
declare (strict_types = 1);

namespace app\model;

class SubscriptionAccessLog extends BaseModel
{
    protected $name = 'subscription_access_logs';

    protected $type = [
        'subscription_id'  => 'integer',
        'response_bytes'   => 'integer',
        'response_time_ms' => 'integer',
        'status_code'      => 'integer',
        'accessed_at'      => 'datetime',
    ];

    /**
     * 访问的订阅
     */
    public function subscription()
    {
        return $this->belongsTo(Subscription::class, 'subscription_id', 'id');
    }

    /**
     * 获取某个日期范围内的统计
     */
    public static function statsInDateRange($startDate, $endDate)
    {
        return static::whereBetween('accessed_at', [$startDate, $endDate])->select();
    }

    /**
     * 按格式分组统计
     */
    public static function groupByFormat($days = 7)
    {
        $date = date('Y-m-d H:i:s', time() - $days * 86400);
        return static::where('accessed_at', '>=', $date)
            ->field('format, COUNT(*) as count')
            ->group('format')
            ->select();
    }

    /**
     * 按协议类型统计
     */
    public static function groupByProtocol($days = 7)
    {
        $date = date('Y-m-d H:i:s', time() - $days * 86400);
        return static::alias('a')
            ->join('subscriptions s', 'a.subscription_id = s.id')
            ->where('a.accessed_at', '>=', $date)
            ->field('a.format, COUNT(*) as count')
            ->group('a.format')
            ->select();
    }
}
