<?php
declare (strict_types = 1);

namespace app\controller\api;

use app\BaseController;
use app\model\BalanceRecharge;
use app\model\Order;
use app\service\payment\PaymentGatewayService;
use app\service\payment\PaymentService;
use think\facade\Log;

/**
 * 支付初始化 API
 * 客户端使用此 API 初始化支付
 */
class PaymentController extends BaseController
{
    public function initiate()
    {
        try {
            $data = $this->request->post();
            $gateway = (string) ($data['gateway'] ?? '');
            $type = (string) ($data['type'] ?? 'purchase');
            $amount = (float) ($data['amount'] ?? 0);
            $subject = (string) ($data['subject'] ?? '支付');
            $description = (string) ($data['description'] ?? '');
            $user = $this->auth->user();
            $userId = (int) ($user['id'] ?? 0);

            if ($userId <= 0) {
                return $this->jsonError('需要登录', 401);
            }
            if ($gateway === '' || $amount < 0.01) {
                return $this->jsonError('参数不完整或金额无效', 422);
            }

            $result = PaymentGatewayService::initiate(
                $gateway,
                $type,
                $userId,
                $amount,
                [
                    'subject'     => $subject,
                    'description' => $description,
                    'package_id'  => $data['package_id'] ?? null,
                ]
            );

            return $this->jsonSuccess('支付初始化成功', $result);
        } catch (\Throwable $e) {
            Log::error('支付初始化失败: ' . $e->getMessage());
            return $this->jsonError('支付初始化失败: ' . $e->getMessage(), 422);
        }
    }

    public function callback(string $gateway)
    {
        try {
            if ($gateway === '') {
                return $this->jsonError('网关参数缺失', 422);
            }

            $callback = $this->getCallbackData();
            $result = PaymentGatewayService::handleCallback($gateway, $callback);
            if (!$result['success']) {
                return $this->jsonError($result['message'] ?? '处理失败', 422);
            }

            return $this->jsonSuccess($result['message'] ?? '处理成功', $result);
        } catch (\Throwable $e) {
            Log::error('处理支付回调失败: ' . $e->getMessage());
            return $this->jsonError('处理失败', 422);
        }
    }

    public function queryOrder(string $tradeNo)
    {
        try {
            $user = $this->auth->user();
            $userId = (int) ($user['id'] ?? 0);
            if ($userId <= 0) {
                return $this->jsonError('需要登录', 401);
            }

            $order = $this->findOrder($tradeNo, $userId);
            if (!$order) {
                return $this->jsonError('订单不存在', 404);
            }

            $gateway = (string) ($order->payment_method ?? '');
            $status = $gateway !== '' ? PaymentGatewayService::queryOrder($gateway, $tradeNo) : [];

            return $this->jsonSuccess('查询成功', [
                'trade_no' => $tradeNo,
                'status'   => (string) $order->status,
                'gateway'  => $gateway,
                'amount'   => (float) ($order->amount ?? $order->amount_payable ?? 0),
                'remote'   => $status,
            ]);
        } catch (\Throwable $e) {
            Log::error("查询订单失败 ({$tradeNo}): " . $e->getMessage());
            return $this->jsonError('查询失败', 422);
        }
    }

    protected function getCallbackData(): array
    {
        if (strtoupper((string) $this->request->method()) === 'POST') {
            $data = $this->request->post();
            if ($data !== []) {
                return $data;
            }
        }

        return $this->request->get();
    }

    protected function findOrder(string $tradeNo, int $userId): Order|BalanceRecharge|null
    {
        $recharge = BalanceRecharge::where('trade_no', $tradeNo)
            ->where('user_id', $userId)
            ->find();
        if ($recharge) {
            return $recharge;
        }

        return Order::where('no', $tradeNo)
            ->where('user_id', $userId)
            ->find();
    }

    /**
     * 码支付异步回调（不需要登录）
     * GET/POST /pay/notify
     */
    public function mazhifuNotify()
    {
        try {
            $params = array_merge($this->request->get(), $this->request->post());
            Log::info('码支付回调参数: ' . json_encode($params, JSON_UNESCAPED_UNICODE));

            $result = PaymentService::verifyMazhifuCallback($params);

            if (!$result['success']) {
                Log::warning('码支付回调验证失败: ' . ($result['message'] ?? ''));
                return response('fail', 200);
            }

            $tradeNo = $result['trade_no'] ?? '';

            // 查找订单
            $order = Order::where('no', $tradeNo)->find();
            if ($order && (string) $order->status !== 'paid') {
                $order->status = 'paid';
                $order->paid_at = date('Y-m-d H:i:s');
                $order->payment_no = $result['channel_no'] ?? '';
                $order->save();

                // 激活订阅
                try {
                    $this->activateSubscriptionForOrder($order);
                } catch (\Throwable $e) {
                    Log::error('码支付回调激活订阅失败: ' . $e->getMessage());
                }

                Log::info("码支付回调成功: 订单 {$tradeNo} 已标记为已支付");
                return response('success', 200);
            }

            // 查找充值订单
            $recharge = BalanceRecharge::where('trade_no', $tradeNo)->find();
            if ($recharge && (string) $recharge->status !== 'paid') {
                $recharge->markAsPaid($params);
                Log::info("码支付回调成功: 充值 {$tradeNo} 已标记为已支付");
                return response('success', 200);
            }

            return response('success', 200);
        } catch (\Throwable $e) {
            Log::error('码支付回调异常: ' . $e->getMessage());
            return response('fail', 200);
        }
    }

    /**
     * 码支付同步跳转（不需要登录）
     * GET /pay/return
     */
    public function mazhifuReturn()
    {
        $tradeNo = $this->request->get('out_trade_no', '');
        $tradeStatus = $this->request->get('trade_status', '');

        if ($tradeStatus === 'TRADE_SUCCESS' && $tradeNo !== '') {
            return redirect('/orders?paid=' . urlencode($tradeNo));
        }

        return redirect('/orders');
    }

    /**
     * 激活订阅（内部方法）
     */
    private function activateSubscriptionForOrder(Order $order): void
    {
        $user = \app\model\User::find($order->user_id);
        $package = \app\model\Package::find($order->package_id);
        if (!$user || !$package) {
            return;
        }

        $months = max(1, (int) ($order->month_count ?? 1));
        $expireAt = date('Y-m-d H:i:s', strtotime("+{$months} months"));

        $subscription = \app\model\Subscription::where('user_id', $user->id)
            ->where('package_id', $package->id)
            ->find();

        if ($subscription) {
            if ($subscription->expire_at && strtotime((string) $subscription->expire_at) > time()) {
                $expireAt = date('Y-m-d H:i:s', strtotime("+{$months} months", strtotime((string) $subscription->expire_at)));
            }
            $subscription->device_limit = $order->device_count ?: $package->device_limit;
            $subscription->expire_at = $expireAt;
            $subscription->status = 'active';
            $subscription->save();
        } else {
            $token = bin2hex(random_bytes(8));
            $baseUrl = rtrim((string) app(\app\service\AppConfigService::class)->baseUrl(), '/');
            \app\model\Subscription::create([
                'user_id'          => $user->id,
                'package_id'       => $package->id,
                'source_order_id'  => $order->id,
                'status'           => 'active',
                'sub_token'        => $token,
                'sub_url'          => $baseUrl . '/sub/' . $token,
                'device_limit'     => $order->device_count ?: $package->device_limit,
                'used_devices'     => 0,
                'traffic_total_gb' => 0,
                'traffic_used_gb'  => 0,
                'start_at'         => date('Y-m-d H:i:s'),
                'expire_at'        => $expireAt,
            ]);
        }

        $order->subscription_id = $subscription->id ?? \app\model\Subscription::where('user_id', $user->id)->order('id', 'desc')->value('id');
        $order->save();
    }
}
