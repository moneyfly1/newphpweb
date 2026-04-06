<?php
declare(strict_types=1);

namespace app\service;

use app\model\BalanceLog;
use app\model\Coupon;
use app\model\Order;
use app\model\Package;
use app\model\Payment;
use app\model\Subscription;
use app\model\User;
use think\facade\Db;
use app\service\NotificationService;

class OrderService
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly AppConfigService $config
    ) {}

    private function currentUser(): User
    {
        $user = User::find($this->currentUserId());
        if (!$user) {
            throw new \RuntimeException('用户不存在。');
        }
        return $user;
    }

    private function currentUserId(): int
    {
        return (int) ($this->auth->user()['id'] ?? 0);
    }

    public function orders(): array
    {
        return [
            'orders' => Order::where('user_id', $this->currentUserId())
                ->order('id', 'desc')
                ->select()
                ->map(fn (Order $order): array => $this->decorateOrder($order))
                ->all(),
        ];
    }

    public function createOrder(array $payload): array
    {
        $user = $this->currentUser();
        $package = Package::find((int) $payload['package_id']);
        if (!$package || (int) $package->is_active !== 1) {
            throw new \RuntimeException('套餐不存在或已下架。');
        }

        $deviceCount = max(1, (int) $payload['device_count']);
        $monthCount = max(1, (int) $payload['month_count']);
        $extraPrice = (float) $this->config->get('business.extra_device_price', 8);
        $extraDevices = max(0, $deviceCount - (int) $package->device_limit);
        $original = round(((float) $package->price_monthly * $monthCount) + ($extraDevices * $extraPrice * $monthCount), 2);
        $coupon = $this->verifyCoupon((string) ($payload['coupon_code'] ?? ''), $original);
        $discount = (float) ($coupon['discount'] ?? 0.0);
        $payable = round(max(0, $original - $discount), 2);
        $orderNo = 'CB' . date('ymdHis') . random_int(10, 99);
        $method = (string) $payload['payment_method'];

        Db::transaction(function () use ($user, $package, $deviceCount, $monthCount, $original, $discount, $payable, $orderNo, $method): void {
            $order = Order::create([
                'no'              => $orderNo,
                'user_id'         => $user->id,
                'package_id'      => $package->id,
                'type'            => 'custom',
                'status'          => $method === 'balance' ? 'paid' : 'pending',
                'device_count'    => $deviceCount,
                'month_count'     => $monthCount,
                'amount_original' => $original,
                'discount_amount' => $discount,
                'amount_payable'  => $payable,
                'payment_method'  => $method,
                'paid_at'         => $method === 'balance' ? date('Y-m-d H:i:s') : null,
                'pay_deadline_at' => date('Y-m-d H:i:s', strtotime('+30 minutes')),
            ]);

            if ($method === 'balance') {
                if ((float) $user->balance < $payable) {
                    throw new \RuntimeException('余额不足，请切换支付方式。');
                }
                $before = (float) $user->balance;
                $after = $before - $payable;
                User::update(['id' => $user->id, 'balance' => $after]);
                BalanceLog::create([
                    'user_id' => $user->id, 'type' => 'consume', 'amount' => -$payable,
                    'balance_before' => $before, 'balance_after' => $after,
                    'ref_type' => 'order', 'ref_id' => $order->id, 'remark' => '余额支付',
                ]);
                $this->applyPaidOrder($order);
                return;
            }

            Payment::create([
                'order_id' => $order->id, 'user_id' => $user->id, 'channel' => $method,
                'trade_no' => 'PAY' . $orderNo, 'amount' => $payable, 'status' => 'pending',
                'qrcode_url' => 'pay://' . $orderNo,
            ]);
        });

        $this->auth->refreshFromDatabase((int) $user->id);

        // 通知
        $notifyEvent = $method === 'balance' ? NotificationService::ORDER_PAID : NotificationService::ORDER_CREATED;
        NotificationService::notify((int) $user->id, $notifyEvent, [
            '订单号' => $orderNo, '金额' => $this->money($payable), '支付方式' => $this->paymentMethodLabel($method),
        ]);

        return [
            'order_no'       => $orderNo,
            'status'         => $method === 'balance' ? 'paid' : 'pending',
            'status_label'   => $method === 'balance' ? '已支付' : '待支付',
            'amount_label'   => $this->money($payable),
            'payment_method' => $this->paymentMethodLabel($method),
            'polling_needed' => $method !== 'balance',
            'coupon_label'   => $coupon['label'] ?? '',
            'discount_label' => $coupon['discount_label'] ?? '¥0.00',
            'balance_label'  => $this->money((float) User::find($user->id)?->balance),
        ];
    }

    public function paymentStatus(string $no): array
    {
        $order = Order::where('no', $no)->where('user_id', $this->currentUserId())->find();
        if (!$order) { throw new \RuntimeException('订单不存在。'); }
        return [
            'status' => $order->status,
            'status_label' => $this->orderStatusLabel((string) $order->status),
            'jump' => $order->status === 'paid' ? '/orders?status=paid' : null,
        ];
    }

    public function cancelOrder(string $no): array
    {
        $order = Order::where('no', $no)->where('user_id', $this->currentUserId())->find();
        if (!$order || $order->status !== 'pending') { throw new \RuntimeException('当前订单不可取消。'); }
        $order->status = 'cancelled';
        $order->cancelled_at = date('Y-m-d H:i:s');
        $order->save();
        Payment::where('order_id', $order->id)->update(['status' => 'closed']);
        NotificationService::notify((int) $order->user_id, NotificationService::ORDER_CANCELLED, ['订单号' => $no]);
        return $this->decorateOrder($order);
    }

    public function adminOrders(array $filters = []): array
    {
        $filters = $this->normalizeListFilters($filters);
        $query = Order::order('id', 'desc');
        if (!empty($filters['keyword'])) {
            $keyword = $filters['keyword'];
            $userIds = User::where('email', 'like', '%' . $keyword . '%')
                ->whereOr('nickname', 'like', '%' . $keyword . '%')->column('id');
            $query->where(function ($q) use ($keyword, $userIds) {
                $q->where('no', 'like', '%' . $keyword . '%')->whereOr('payment_method', 'like', '%' . $keyword . '%');
                if (is_numeric($keyword)) { $q->whereOr('user_id', (int) $keyword); }
                if (!empty($userIds)) { $q->whereOr('user_id', 'in', $userIds); }
            });
        }
        if (!empty($filters['status'])) { $query->where('status', $filters['status']); }
        if (!empty($filters['type'])) { $query->where('payment_method', $filters['type']); }
        if (!empty($filters['date_from'])) { $query->where('created_at', '>=', $filters['date_from'] . ' 00:00:00'); }
        if (!empty($filters['date_to'])) { $query->where('created_at', '<=', $filters['date_to'] . ' 23:59:59'); }

        return $query->select()->map(fn (Order $order): array => [
            'id' => $order->id, 'no' => $order->no,
            'user' => $this->userName((int) $order->user_id), 'user_id' => (int) $order->user_id,
            'amount' => $this->money((float) $order->amount_payable),
            'status' => $this->orderStatusLabel((string) $order->status), 'status_key' => $order->status,
            'payment' => $this->paymentMethodLabel((string) $order->payment_method),
            'payment_key' => (string) $order->payment_method, 'created_at' => (string) $order->created_at,
        ])->all();
    }

    public function updateOrderStatus(string $orderNo, string $action): array
    {
        $order = Order::where('no', $orderNo)->find();
        if (!$order) { throw new \RuntimeException('订单不存在。'); }
        if ($action === 'paid') {
            $order->status = 'paid'; $order->paid_at = date('Y-m-d H:i:s'); $order->save();
            Payment::where('order_id', $order->id)->update(['status' => 'success', 'paid_at' => date('Y-m-d H:i:s')]);
            $this->applyPaidOrder($order);
        } elseif ($action === 'cancelled') {
            $order->status = 'cancelled'; $order->cancelled_at = date('Y-m-d H:i:s'); $order->save();
            Payment::where('order_id', $order->id)->update(['status' => 'closed']);
        } elseif ($action === 'refunded') {
            $order->status = 'refunded'; $order->save();
            Payment::where('order_id', $order->id)->update(['status' => 'failed']);
        } else { throw new \RuntimeException('不支持的订单动作。'); }
        $eventMap = ['paid' => NotificationService::ORDER_PAID, 'cancelled' => NotificationService::ORDER_CANCELLED, 'refunded' => NotificationService::ORDER_REFUNDED];
        if (isset($eventMap[$action])) {
            NotificationService::notify((int) $order->user_id, $eventMap[$action], ['订单号' => $orderNo, '状态' => $order->status]);
        }
        return ['status' => $order->status];
    }

    public function verifyCoupon(string $code, float $orderAmount = 0): array
    {
        if ($code === '') { return ['valid' => false, 'discount' => 0, 'label' => '', 'discount_label' => '¥0.00']; }
        $coupon = Coupon::where('code', $code)->where('status', 1)->find();
        if (!$coupon) { throw new \RuntimeException('优惠券不存在或已失效。'); }
        if ($coupon->end_at && strtotime((string) $coupon->end_at) < time()) { throw new \RuntimeException('优惠券已过期。'); }
        if ($coupon->total_limit > 0 && (int) $coupon->used_count >= (int) $coupon->total_limit) { throw new \RuntimeException('优惠券已被领完。'); }
        if ($orderAmount > 0 && $orderAmount < (float) $coupon->min_order_amount) {
            throw new \RuntimeException('订单金额未达到优惠券最低要求 ¥' . number_format((float) $coupon->min_order_amount, 2));
        }
        $discount = $coupon->discount_type === 'percent'
            ? round($orderAmount * (float) $coupon->discount_value / 100, 2)
            : (float) $coupon->discount_value;
        if ($coupon->max_discount_amount && $discount > (float) $coupon->max_discount_amount) {
            $discount = (float) $coupon->max_discount_amount;
        }
        return ['valid' => true, 'code' => $coupon->code, 'discount' => $discount,
            'label' => $coupon->name ?: $coupon->code, 'discount_label' => '- ¥' . number_format($discount, 2)];
    }

    private function applyPaidOrder(Order $order): void
    {
        $package = Package::find((int) $order->package_id);
        if (!$package) { return; }
        $subscription = Subscription::where('user_id', (int) $order->user_id)->order('id', 'desc')->find();
        $token = $subscription?->sub_token ?: bin2hex(random_bytes(8));
        $base = $subscription && $subscription->expire_at ? strtotime((string) $subscription->expire_at) : time();
        $expireAt = date('Y-m-d H:i:s', strtotime('+' . max(1, (int) $order->month_count) * 30 . ' days', max(time(), $base)));
        if ($subscription) {
            $subscription->package_id = $package->id; $subscription->source_order_id = $order->id;
            $subscription->status = 'active'; $subscription->sub_token = $token;
            $subscription->sub_url = $this->config->baseUrl() . '/sub/' . $token;
            $subscription->device_limit = max((int) $order->device_count, (int) $package->device_limit);
            $subscription->traffic_total_gb = (int) $package->traffic_limit_gb;
            $subscription->expire_at = $expireAt; $subscription->save();
            return;
        }
        Subscription::create([
            'user_id' => $order->user_id, 'package_id' => $package->id, 'source_order_id' => $order->id,
            'status' => 'active', 'sub_token' => $token, 'sub_url' => $this->config->baseUrl() . '/sub/' . $token,
            'device_limit' => max((int) $order->device_count, (int) $package->device_limit),
            'used_devices' => 0, 'traffic_total_gb' => (int) $package->traffic_limit_gb,
            'traffic_used_gb' => 0, 'start_at' => date('Y-m-d H:i:s'), 'expire_at' => $expireAt,
        ]);
    }

    private function decorateOrder(Order $order): array
    {
        return [
            'no' => $order->no, 'type' => $order->type,
            'package_name' => $this->packageName((int) $order->package_id),
            'status' => $order->status, 'status_label' => $this->orderStatusLabel((string) $order->status),
            'status_tone' => match ($order->status) { 'paid' => 'success', 'cancelled', 'refunded' => 'muted', default => 'warning' },
            'payment_method' => $this->paymentMethodLabel((string) $order->payment_method),
            'device_count' => (int) $order->device_count, 'month_count' => (int) $order->month_count,
            'amount_original' => (float) $order->amount_original, 'discount_amount' => (float) $order->discount_amount,
            'amount_payable' => (float) $order->amount_payable, 'created_at' => (string) $order->created_at,
            'paid_at' => $order->paid_at ? (string) $order->paid_at : null,
            'amount_label' => $this->money((float) $order->amount_payable),
        ];
    }

    private function money(float $amount): string { return '¥' . number_format($amount, 2); }
    private function packageName(int $id): string { return $id > 0 ? (Package::find($id)?->name ?: '已删除套餐') : '无套餐'; }
    private function orderStatusLabel(string $s): string { return match ($s) { 'pending' => '待支付', 'paid' => '已支付', 'cancelled' => '已取消', 'refunded' => '已退款', default => $s }; }
    private function paymentMethodLabel(string $m): string { return match ($m) { 'balance' => '余额', 'alipay' => '支付宝', 'wechat' => '微信', 'manual' => '人工转账', 'stripe' => 'Stripe', 'usdt' => 'USDT', default => $m ?: '未选择' }; }
    private function userName(int $uid): string { $u = User::find($uid); return $u ? ((string) ($u->nickname ?: $u->email)) : '未知用户'; }
    private function normalizeListFilters(array $f): array { return ['keyword' => trim((string) ($f['keyword'] ?? '')), 'status' => trim((string) ($f['status'] ?? '')), 'type' => trim((string) ($f['type'] ?? '')), 'date_from' => trim((string) ($f['date_from'] ?? '')), 'date_to' => trim((string) ($f['date_to'] ?? ''))]; }
}