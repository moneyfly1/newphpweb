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

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public static function getPending(int $limit = 50)
    {
        return self::where('status', 'pending')
            ->where(function ($query) {
                $query->whereNull('scheduled_at')
                    ->whereOr('scheduled_at', '<=', date('Y-m-d H:i:s'));
            })
            ->where('attempts', '<', 3)
            ->order('created_at')
            ->limit($limit)
            ->select();
    }

    public function markAsSent()
    {
        $this->status = 'sent';
        $this->sent_at = date('Y-m-d H:i:s');
        $this->attempts = $this->attempts + 1;
        return $this->save();
    }

    public function markAsFailed(string $errorMessage = '')
    {
        $this->status = $this->attempts >= 3 ? 'failed' : 'pending';
        $this->error_message = $errorMessage;
        $this->attempts = $this->attempts + 1;

        if ($this->attempts < 3) {
            $delays = [300, 1500, 7200];
            $nextRetry = time() + ($delays[$this->attempts - 1] ?? 7200);
            $this->scheduled_at = date('Y-m-d H:i:s', $nextRetry);
        }

        return $this->save();
    }

    public function shouldRetry(): bool
    {
        return $this->status === 'pending' && $this->attempts < 3;
    }

    public static function countByType()
    {
        return self::field('type, status, COUNT(*) as count')
            ->group('type, status')
            ->select()
            ->toArray();
    }

    public static function getQueueStats()
    {
        $total = self::count();
        return [
            'pending' => self::where('status', 'pending')->count(),
            'sent' => self::where('status', 'sent')->count(),
            'failed' => self::where('status', 'failed')->count(),
            'total' => $total,
            'success_rate' => $total > 0
                ? round(self::where('status', 'sent')->count() / $total * 100, 2)
                : 0,
        ];
    }
}
