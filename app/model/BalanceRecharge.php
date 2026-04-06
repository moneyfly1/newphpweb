<?php
declare (strict_types = 1);

namespace app\model;

class BalanceRecharge extends BaseModel
{
    protected $name = 'balance_recharges';

    protected $type = [
        'user_id'    => 'integer',
        'order_id'   => 'integer',
        'amount'     => 'float',
    ];

    /**
     * 关联用户
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * 关联订单
     */
    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }

    /**
     * 获取用户充值历史
     */
    public static function getUserHistory($userId, $page = 1, $limit = 15)
    {
        $offset = ($page - 1) * $limit;
        return self::where('user_id', $userId)
            ->where('status', 'paid')
            ->order('paid_at', 'desc')
            ->limit($limit)
            ->offset($offset)
            ->select()
            ->toArray();
    }

    /**
     * 获取充值总额
     */
    public static function getTotalAmount($userId)
    {
        $result = self::where('user_id', $userId)
            ->where('status', 'paid')
            ->sum('amount');
        return $result ?? 0;
    }

    /**
     * 获取待支付充值
     */
    public static function getPendingByTradeNo($tradeNo)
    {
        return self::where('trade_no', $tradeNo)
            ->where('status', 'pending')
            ->findOrEmpty();
    }

    /**
     * 标记为已支付
     */
    public function markAsPaid($payload = null)
    {
        $this->status = 'paid';
        $this->paid_at = date('Y-m-d H:i:s');
        if ($payload) {
            $this->callback_payload = is_array($payload) ? json_encode($payload) : $payload;
        }
        $this->save();
        
        // 更新用户余额
        User::find($this->user_id)->increment('balance', $this->amount);
        
        return $this;
    }

    /**
     * 标记为失败
     */
    public function markAsFailed($reason = '')
    {
        $this->status = 'failed';
        $this->callback_payload = $reason;
        $this->save();
        return $this;
    }
}
