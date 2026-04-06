<?php
declare (strict_types = 1);

namespace app\service\payment;

use app\model\BalanceRecharge;
use app\model\Order;
use app\model\Package;
use app\model\PaymentMethod;
use app\model\Subscription;
use app\model\User;
use think\facade\Db;
use think\facade\Log;
use RuntimeException;

/**
 * 支付处理主服务
 */
class PaymentService
{
    public static function initiateGatewayPayment(
        string $gatewayCode,
        string $type,
        int $userId,
        float $amount,
        array $params = []
    ): array {
        if (!PaymentGatewayFactory::has($gatewayCode)) {
            throw new RuntimeException("无效的支付网关: {$gatewayCode}");
        }

        $tradeNo = self::generateGatewayTradeNo($type);
        $now = date('Y-m-d H:i:s');

        if ($type === 'recharge') {
            $order = BalanceRecharge::create([
                'user_id'        => $userId,
                'amount'         => $amount,
                'payment_method' => $gatewayCode,
                'trade_no'       => $tradeNo,
                'status'         => 'pending',
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);
        } else {
            $order = Order::create([
                'user_id'        => $userId,
                'no'             => $tradeNo,
                'type'           => $type,
                'status'         => 'pending',
                'amount_payable' => $amount,
                'payment_method' => $gatewayCode,
                'meta_json'      => json_encode($params, JSON_UNESCAPED_UNICODE),
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);
        }

        if (!$order) {
            throw new RuntimeException('创建订单失败');
        }

        $gateway = PaymentGatewayFactory::create($gatewayCode);

        // 自动填充回调地址
        $baseUrl = rtrim((string) env('APP_URL', request()->domain()), '/');
        $notifyUrl = $baseUrl . '/api/payment/callback/' . $gatewayCode;
        $returnUrl = $baseUrl . '/orders?paid=' . urlencode($tradeNo);

        $paymentInfo = $gateway->createPayment([
            'trade_no'    => $tradeNo,
            'amount'      => $amount,
            'subject'     => $params['subject'] ?? '支付',
            'description' => $params['description'] ?? '',
            'user_id'     => $userId,
            'currency'    => $params['currency'] ?? 'CNY',
            'notify_url'  => $notifyUrl,
            'return_url'  => $returnUrl,
        ]);

        return [
            'success'      => true,
            'order_id'     => $order->id,
            'trade_no'     => $tradeNo,
            'gateway'      => $gatewayCode,
            'amount'       => $amount,
            'payment_info' => $paymentInfo,
        ];
    }

    public static function handleGatewayCallback(string $gatewayCode, array $callback): array
    {
        try {
            $gateway = PaymentGatewayFactory::create($gatewayCode);
            $result = $gateway->handleCallback($callback);

            if (!$result['success']) {
                return [
                    'success' => false,
                    'message' => $result['message'] ?? '支付处理失败',
                ];
            }

            $tradeNo = $result['trade_no'] ?? '';
            $order = Order::where('no', $tradeNo)->find();
            if (!$order) {
                $order = BalanceRecharge::where('trade_no', $tradeNo)->find();
            }

            if (!$order) {
                Log::warning("订单未找到: {$tradeNo}");
                return ['success' => false, 'message' => '订单不存在'];
            }

            if ((string) $order->status === 'paid') {
                return ['success' => true, 'message' => '订单已支付，无需重复处理', 'duplicate' => true];
            }

            // 校验回调金额与订单金额是否一致
            $callbackAmount = (float) ($result['amount'] ?? 0);
            $orderAmount = (float) ($order->amount ?? $order->amount_payable ?? 0);
            if ($callbackAmount > 0 && abs($callbackAmount - $orderAmount) > 0.01) {
                Log::warning("回调金额不一致: 订单 {$tradeNo}, 订单金额 {$orderAmount}, 回调金额 {$callbackAmount}");
                return ['success' => false, 'message' => '回调金额与订单金额不一致'];
            }

            $order->status = 'paid';
            $order->meta_json = json_encode($result, JSON_UNESCAPED_UNICODE);
            $order->paid_at = date('Y-m-d H:i:s');
            $order->save();

            self::processSettledOrder($order);

            // 通知用户支付成功
            try {
                \app\service\NotificationService::notify((int) $order->user_id, \app\service\NotificationService::ORDER_PAID, [
                    '订单号' => $tradeNo, '金额' => '¥' . number_format((float) ($order->amount ?? $order->amount_payable ?? 0), 2),
                ]);
            } catch (\Throwable $ignore) {}

            return [
                'success' => true,
                'message' => '支付处理成功',
                'order_id' => $order->id,
                'trade_no' => $tradeNo,
            ];
        } catch (\Throwable $e) {
            Log::error("处理支付回调失败 ({$gatewayCode}): " . $e->getMessage());
            return ['success' => false, 'message' => '处理失败: ' . $e->getMessage()];
        }
    }

    public static function createRecharge(int $userId, float $amount, string $paymentMethod): BalanceRecharge
    {
        User::findOrFail($userId);
        $tradeNo = 'RECHARGE_' . time() . '_' . $userId . '_' . mt_rand(1000, 9999);

        $recharge = new BalanceRecharge();
        $recharge->user_id = $userId;
        $recharge->amount = $amount;
        $recharge->payment_method = $paymentMethod;
        $recharge->trade_no = $tradeNo;
        $recharge->status = 'pending';
        $recharge->save();

        return $recharge;
    }

    public static function createAlipayRecharge(int $userId, float $amount): array
    {
        $recharge = self::createRecharge($userId, $amount, 'alipay');
        $paymentMethod = PaymentMethod::getByCode('alipay');
        if (!$paymentMethod || !$paymentMethod->is_enabled) {
            throw new RuntimeException('支付宝支付未启用');
        }

        $config = $paymentMethod->getConfig();
        $alipay = new AlipayService($config);
        $paymentUrl = $alipay->createQrcodePaymentUrl([
            'trade_no' => $recharge->trade_no,
            'amount'   => $amount,
            'subject'  => '账户充值',
            'body'     => '充值金额: ¥' . $amount,
        ]);

        return [
            'recharge_id' => $recharge->id,
            'trade_no'    => $recharge->trade_no,
            'amount'      => $amount,
            'qr_code'     => $paymentUrl['qr_code'] ?? '',
            'payment_url' => $alipay->createWebPaymentUrl([
                'trade_no' => $recharge->trade_no,
                'amount'   => $amount,
                'subject'  => '账户充值',
                'body'     => '充值金额: ¥' . $amount,
            ]),
        ];
    }

    private static function getMazhifuConfig(): array
    {
        $pid = (int) env('MZF_PID', 0);
        $key = (string) env('MZF_KEY', '');
        $apiUrl = (string) env('MZF_API_URL', '');
        $submitUrl = (string) env('MZF_SUBMIT_URL', '');

        if ($pid <= 0 || $key === '') {
            throw new RuntimeException('码支付配置不完整，请在 .env 中设置 MZF_PID 和 MZF_KEY');
        }

        return [
            'pid'        => $pid,
            'key'        => $key,
            'api_url'    => $apiUrl ?: 'https://mzf.akwl.net/xpay/epay/',
            'submit_url' => $submitUrl ?: '',
        ];
    }

    public static function createMazhifuPayment(string $tradeNo, float $amount, string $subject, string $payType, string $notifyUrl, string $returnUrl): array
    {
        $mazhifu = new MazhifuService();
        $mazhifu->initialize(self::getMazhifuConfig());

        return $mazhifu->createPayment([
            'trade_no'   => $tradeNo,
            'amount'     => $amount,
            'subject'    => $subject,
            'pay_type'   => $payType,
            'notify_url' => $notifyUrl,
            'return_url' => $returnUrl,
        ]);
    }

    public static function verifyMazhifuCallback(array $callback): array
    {
        $mazhifu = new MazhifuService();
        $mazhifu->initialize(self::getMazhifuConfig());

        return $mazhifu->handleCallback($callback);
    }

    public static function testAlipayConfig(array $config): array
    {
        $alipay = new AlipayService($config);
        if (!$alipay->validateConfig()) {
            throw new RuntimeException('支付宝配置不完整');
        }

        return [
            'app_id' => $config['app_id'] ?? '',
            'gateway' => $config['gateway_url'] ?? 'https://openapi.alipay.com/gateway.do',
        ];
    }

    public static function handleAlipayCallback(array $callback): array
    {
        try {
            $paymentMethod = PaymentMethod::getByCode('alipay');
            if (!$paymentMethod) {
                throw new RuntimeException('支付宝配置不存在');
            }

            $config = $paymentMethod->getConfig();
            $alipay = new AlipayService($config);
            if (!$alipay->verifyCallback($callback)) {
                throw new RuntimeException('回调签名验证失败');
            }

            $tradeNo = $callback['out_trade_no'] ?? '';
            $status = $callback['trade_status'] ?? '';
            $recharge = BalanceRecharge::getPendingByTradeNo($tradeNo);

            if (!$recharge) {
                $order = Order::where('no', $tradeNo)->findOrEmpty();
                if (!$order) {
                    throw new RuntimeException('订单不存在: ' . $tradeNo);
                }
                return self::handleOrderPayment($order, $callback);
            }

            if ($status === 'TRADE_SUCCESS' || $status === 'TRADE_FINISHED') {
                $recharge->markAsPaid($callback);
                return ['success' => true, 'message' => '充值成功', 'recharge_id' => $recharge->id, 'amount' => $recharge->amount];
            }

            if ($status === 'TRADE_CLOSED') {
                $recharge->markAsFailed('交易关闭');
                return ['success' => false, 'message' => '交易已关闭'];
            }

            return ['success' => false, 'message' => '交易状态: ' . $status];
        } catch (\Exception $e) {
            Log::error('支付宝回调处理失败: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private static function handleOrderPayment(Order $order, array $callback): array
    {
        try {
            $status = $callback['trade_status'] ?? '';
            if ($status !== 'TRADE_SUCCESS' && $status !== 'TRADE_FINISHED') {
                return ['success' => false, 'message' => '支付失败: ' . $status];
            }

            $order->status = 'paid';
            $order->paid_at = date('Y-m-d H:i:s');
            $order->save();
            $result = self::activateSubscription($order);

            return [
                'success' => true,
                'message' => '订单支付成功',
                'order_id' => $order->id,
                'subscription' => $result,
            ];
        } catch (\Exception $e) {
            Log::error('订单支付处理失败: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private static function activateSubscription(Order $order): array
    {
        try {
            $user = User::find($order->user_id);
            $package = Package::find($order->package_id);
            if (!$user || !$package) {
                throw new RuntimeException('用户或套餐不存在');
            }

            $months = (int) ($order->month_count ?? 1);
            $expireAt = date('Y-m-d H:i:s', strtotime("+{$months} months"));
            $subscription = Subscription::where('user_id', $user->id)
                ->where('package_id', $package->id)
                ->findOrEmpty();

            if ($subscription) {
                if ($subscription->expire_at > date('Y-m-d H:i:s')) {
                    $expireAt = date('Y-m-d H:i:s', strtotime("+{$months} months", strtotime($subscription->expire_at)));
                }
                $subscription->device_limit = $order->device_count ?? $package->device_limit;
                $subscription->expire_at = $expireAt;
                $subscription->status = 'active';
                $subscription->save();
            } else {
                $subscription = new Subscription();
                $subscription->user_id = $user->id;
                $subscription->package_id = $package->id;
                $subscription->device_limit = $order->device_count ?? $package->device_limit;
                $subscription->status = 'active';
                $subscription->expire_at = $expireAt;
                $subscription->save();
            }

            $order->subscription_id = $subscription->id;
            $order->save();

            return [
                'subscription_id' => $subscription->id,
                'package_id' => $package->id,
                'expire_at' => $expireAt,
                'device_limit' => $order->device_count ?? $package->device_limit,
            ];
        } catch (\Exception $e) {
            Log::error('激活订阅失败: ' . $e->getMessage());
            throw $e;
        }
    }

    public static function handleBalancePayment(int $userId, float $amount): bool
    {
        try {
            $user = User::findOrFail($userId);
            if ($user->balance < $amount) {
                throw new RuntimeException('账户余额不足');
            }

            $user->decrement('balance', $amount);
            $recharge = new BalanceRecharge();
            $recharge->user_id = $userId;
            $recharge->amount = -$amount;
            $recharge->payment_method = 'balance';
            $recharge->trade_no = 'PAYMENT_' . time() . '_' . $userId;
            $recharge->status = 'paid';
            $recharge->paid_at = date('Y-m-d H:i:s');
            $recharge->save();

            return true;
        } catch (\Exception $e) {
            Log::error('余额支付处理失败: ' . $e->getMessage());
            return false;
        }
    }

    public static function getEnabledMethods(): array
    {
        return PaymentMethod::getEnabled();
    }

    public static function isMethodEnabled(string $code): bool
    {
        $method = PaymentMethod::getByCode($code);
        return $method && (bool) $method->is_enabled;
    }

    private static function generateGatewayTradeNo(string $type): string
    {
        $prefix = match ($type) {
            'recharge' => 'RCH',
            'upgrade'  => 'UPG',
            'purchase' => 'PUR',
            default    => 'ORD',
        };

        return $prefix . '_' . time() . '_' . strtoupper(substr(uniqid(), -6));
    }

    private static function processSettledOrder(Order|BalanceRecharge $order): void
    {
        if ($order instanceof BalanceRecharge) {
            self::processRecharge($order);
            return;
        }

        self::processPurchase($order);
    }

    private static function processRecharge(BalanceRecharge $recharge): void
    {
        Db::transaction(function () use ($recharge) {
            $user = Db::table('users')
                ->where('id', $recharge->user_id)
                ->lock(true)
                ->find();

            $oldBalance = (float) ($user['balance'] ?? 0);
            $newBalance = $oldBalance + $recharge->amount;

            Db::table('users')
                ->where('id', $recharge->user_id)
                ->update(['balance' => $newBalance]);

            Db::table('balance_logs')->insert([
                'user_id'        => $recharge->user_id,
                'type'           => 'recharge',
                'amount'         => $recharge->amount,
                'balance_before' => $oldBalance,
                'balance_after'  => $newBalance,
                'ref_type'       => 'recharge',
                'ref_id'         => $recharge->id,
                'remark'         => '账户充值',
                'created_at'     => date('Y-m-d H:i:s'),
            ]);
        });
    }

    private static function processPurchase(Order $order): void
    {
        $params = json_decode((string) $order->params, true) ?? [];
        $type = $params['type'] ?? 'purchase';
        if ($type === 'upgrade') {
            Log::info("升级订单处理: 用户 {$order->user_id}, 套餐参数: " . json_encode($params, JSON_UNESCAPED_UNICODE));
            return;
        }

        Log::info("购买订单处理: 用户 {$order->user_id}, 商品参数: " . json_encode($params, JSON_UNESCAPED_UNICODE));
    }
}
