<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

class EmailQueue extends Model
{
    protected $table = 'email_queue';

    protected $type = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'sent_at' => 'datetime',
        'scheduled_at' => 'datetime',
    ];

    /**
     * 关系：所属用户
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * 获取待发送的邮件
     */
    public static function getPending(int $limit = 50)
    {
        return self::where('status', 'pending')
            ->where(function ($query) {
                $query->whereNull('scheduled_at')
                    ->orWhere('scheduled_at', '<=', date('Y-m-d H:i:s'));
            })
            ->where('attempts', '<', 3)
            ->orderBy('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * 标记为已发送
     */
    public function markAsSent()
    {
        $this->status = 'sent';
        $this->sent_at = date('Y-m-d H:i:s');
        $this->attempts = $this->attempts + 1;
        return $this->save();
    }

    /**
     * 标记为失败
     */
    public function markAsFailed(string $errorMessage = '')
    {
        $this->status = $this->attempts >= 3 ? 'failed' : 'pending';
        $this->error_message = $errorMessage;
        $this->attempts = $this->attempts + 1;
        
        if ($this->attempts < 3) {
            // 延迟下次重试（指数退避：5分钟、25分钟、2小时）
            $delays = [300, 1500, 7200];
            $nextRetry = time() + $delays[$this->attempts - 1] ?? 7200;
            $this->scheduled_at = date('Y-m-d H:i:s', $nextRetry);
        }
        
        return $this->save();
    }

    /**
     * 检查是否应该重试
     */
    public function shouldRetry(): bool
    {
        return $this->status === 'pending' && $this->attempts < 3;
    }

    /**
     * 按类型统计
     */
    public static function countByType()
    {
        return self::selectRaw('type, COUNT(*) as count, status')
            ->groupBy('type', 'status')
            ->get()
            ->toArray();
    }

    /**
     * 获取队列统计信息
     */
    public static function getQueueStats()
    {
        return [
            'pending' => self::where('status', 'pending')->count(),
            'sent' => self::where('status', 'sent')->count(),
            'failed' => self::where('status', 'failed')->count(),
            'total' => self::count(),
            'success_rate' => self::count() > 0 
                ? round(self::where('status', 'sent')->count() / self::count() * 100, 2)
                : 0,
        ];
    }
}
