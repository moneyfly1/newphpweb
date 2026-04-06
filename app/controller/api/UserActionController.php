<?php
declare (strict_types = 1);

namespace app\controller\api;

use app\BaseController;
use app\service\payment\PaymentService;
use think\facade\Log;

class UserActionController extends BaseController
{
    public function checkin()
    {
        $this->requireCsrf();

        return $this->jsonSuccess('签到结果已更新。', $this->panel->checkin());
    }

    public function verifyCoupon()
    {
        $this->requireCsrf();

        $data = $this->request->only(['code', 'amount']);
        $coupon = $this->panel->verifyCoupon((string) ($data['code'] ?? ''), (float) ($data['amount'] ?? 0));
        if (!$coupon) {
            return $this->jsonError('优惠码无效或不满足使用条件。', 422);
        }

        return $this->jsonSuccess('优惠码验证成功。', $coupon);
    }

    public function createOrder()
    {
        $this->requireCsrf();

        $data = $this->request->only(['package_id', 'device_count', 'month_count', 'coupon_code', 'payment_method']);
        $this->validate($data, [
            'package_id'     => 'require|number',
            'device_count'   => 'require|number|min:1',
            'month_count'    => 'require|number|min:1',
            'payment_method' => 'require',
        ]);

        try {
            $result = $this->panel->createOrder($data);
        } catch (\RuntimeException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        }

        return $this->jsonSuccess('订单已生成。', $result);
    }

    public function paymentStatus(string $no)
    {
        try {
            $result = $this->panel->paymentStatus($no);
        } catch (\RuntimeException $exception) {
            return $this->jsonError($exception->getMessage(), 404);
        }

        return $this->jsonSuccess('轮询成功。', $result);
    }

    public function cancelOrder(string $no)
    {
        $this->requireCsrf();

        try {
            $result = $this->panel->cancelOrder($no);
        } catch (\RuntimeException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        }

        return $this->jsonSuccess('订单已取消。', $result);
    }

    public function toggleSetting()
    {
        $this->requireCsrf();

        $key = (string) $this->request->post('key');
        $value = filter_var($this->request->post('value'), FILTER_VALIDATE_BOOLEAN);

        try {
            $result = $this->panel->toggleSetting($key, $value);
        } catch (\RuntimeException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        }

        return $this->jsonSuccess('设置已保存。', $result);
    }

    public function createTicket()
    {
        $this->requireCsrf();

        $data = $this->request->only(['subject', 'content']);
        $this->validate($data, [
            'subject' => 'require|min:1|max:100',
            'content' => 'require|min:1|max:2000',
        ]);

        return $this->jsonSuccess('工单已提交。', $this->panel->createTicket($data));
    }

    public function saveProfile()
    {
        $this->requireCsrf();

        $data = $this->request->only(['name', 'telegram', 'timezone']);
        $this->validate($data, [
            'name'     => 'require|min:1|max:60',
            'telegram' => 'max:60',
            'timezone' => 'max:60',
        ]);

        try {
            $result = $this->panel->saveProfile($data);
        } catch (\RuntimeException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        }

        return $this->jsonSuccess('资料已保存。', $result);
    }

    public function updatePassword()
    {
        $this->requireCsrf();

        $data = $this->request->only(['old_password', 'new_password', 'confirm_password']);
        $this->validate($data, [
            'old_password'     => 'require|min:8',
            'new_password'     => 'require|min:8',
            'confirm_password' => 'require|min:8',
        ]);

        try {
            $result = $this->panel->updatePassword($data);
        } catch (\RuntimeException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        }

        return $this->jsonSuccess('密码已更新。', $result);
    }

    public function resetSubscription()
    {
        $this->requireCsrf();

        try {
            $result = $this->panel->resetSubscription();
        } catch (\RuntimeException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        }

        return $this->jsonSuccess('订阅链接已重置。', $result);
    }

    public function convertSubscriptionBalance()
    {
        $this->requireCsrf();

        try {
            $result = $this->panel->convertSubscriptionBalance();
        } catch (\RuntimeException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        }

        return $this->jsonSuccess('剩余天数已折算。', $result);
    }

    public function sendSubscriptionEmail()
    {
        $this->requireCsrf();

        return $this->jsonSuccess('订阅邮件已处理。', $this->panel->sendSubscriptionEmail());
    }

    public function clearSubscriptionDevices()
    {
        $this->requireCsrf();

        try {
            $result = $this->panel->clearSubscriptionDevices();
        } catch (\RuntimeException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        }

        return $this->jsonSuccess('设备已清理。', $result);
    }

    public function saveNotifications()
    {
        $this->requireCsrf();

        $data = $this->request->only(['email_order', 'email_subscription', 'email_ticket', 
            'email_announcement', 'notification_frequency']);
        
        try {
            $result = $this->panel->saveUserNotificationPreferences($this->userId, $data);
        } catch (\RuntimeException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        }

        return $this->jsonSuccess('通知偏好已保存。', $result);
    }

    public function savePrivacy()
    {
        $this->requireCsrf();

        $data = $this->request->only(['profile_public', 'email_hidden', 'marketing_email']);
        
        try {
            $result = $this->panel->saveUserPrivacySettings($this->userId, $data);
        } catch (\RuntimeException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        }

        return $this->jsonSuccess('隐私设置已保存。', $result);
    }

    public function savePreferences()
    {
        $this->requireCsrf();

        $data = $this->request->only(['timezone', 'language', 'dark_mode']);
        
        try {
            $result = $this->panel->saveUserPreferences($this->userId, $data);
        } catch (\RuntimeException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        }

        return $this->jsonSuccess('偏好设置已保存。', $result);
    }

    public function exportData()
    {
        $this->requireCsrf();

        try {
            $result = $this->panel->exportUserData($this->userId);
        } catch (\RuntimeException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        }

        return $this->jsonSuccess('数据导出成功。', ['data' => $result]);
    }

    public function loginHistory()
    {
        try {
            $result = $this->panel->userLoginHistory($this->userId, 10);
        } catch (\RuntimeException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        }

        return $this->jsonSuccess('登录历史已获取。', $result);
    }

    public function userSubscriptions()
    {
        try {
            $result = $this->panel->getUserSubscriptions($this->userId);
        } catch (\RuntimeException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        }

        return $this->jsonSuccess('订阅列表已获取。', $result);
    }

    public function freezeAccount()
    {
        $this->requireCsrf();

        $reason = (string) $this->request->post('reason', '用户主动冻结');

        try {
            $result = $this->panel->freezeUserAccount($this->userId, $reason);
        } catch (\RuntimeException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        }

        return $this->jsonSuccess('账户已冻结。', $result);
    }

    public function deleteAccount()
    {
        $this->requireCsrf();

        try {
            $result = $this->panel->deleteUserAccount($this->userId);
            // 清除登录状态
            session()->destroy();
        } catch (\RuntimeException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        }

        return $this->jsonSuccess('账户已删除。', $result);
    }

    /**
     * 获取可用支付方式
     */
    public function paymentMethods()
    {
        try {
            $methods = PaymentService::getEnabledMethods();
            return $this->jsonSuccess('支付方式已获取。', $methods);
        } catch (\Exception $e) {
            return $this->jsonError($e->getMessage(), 500);
        }
    }

    /**
     * 创建充值订单
     */
    public function recharge()
    {
        $this->requireCsrf();

        try {
            $amount = (float) $this->request->post('amount', 0);
            $paymentMethod = (string) $this->request->post('payment_method', '');

            if ($amount <= 0) {
                throw new \RuntimeException('充值金额必须大于 0');
            }

            if (!in_array($paymentMethod, ['alipay', 'wechat', 'usdt', 'manual'])) {
                throw new \RuntimeException('无效的支付方式');
            }

            $recharge = \app\service\payment\PaymentService::createRecharge($this->userId, $amount, $paymentMethod);

            $responseData = [
                'recharge_id' => $recharge->id,
                'trade_no'    => $recharge->trade_no,
                'amount'      => $amount,
                'method'      => $paymentMethod,
            ];

            // 不同支付方式返回不同的信息
            if ($paymentMethod === 'alipay') {
                $alipayData = \app\service\payment\PaymentService::createAlipayRecharge($this->userId, $amount);
                $responseData['payment_url'] = $alipayData['payment_url'];
                $responseData['qr_code'] = $alipayData['qr_code'];
            } elseif ($paymentMethod === 'usdt') {
                $method = \app\model\PaymentMethod::getByCode('usdt');
                $config = $method->getConfig();
                $responseData['wallet_address'] = $config['wallet_address'] ?? '';
            } elseif ($paymentMethod === 'manual') {
                $method = \app\model\PaymentMethod::getByCode('manual');
                $config = $method->getConfig();
                $responseData['instruction'] = $config['instruction'] ?? '';
            }

            return $this->jsonSuccess('充值订单已创建。', $responseData);

        } catch (\Exception $e) {
            return $this->jsonError($e->getMessage(), 422);
        }
    }

    /**
     * 查询充值状态
     */
    public function rechargeStatus(int $rechargeId)
    {
        try {
            $recharge = \app\model\BalanceRecharge::findOrFail($rechargeId);

            if ((int) $recharge->user_id !== $this->userId) {
                throw new \RuntimeException('无权查看此充值记录');
            }

            return $this->jsonSuccess('充值状态已获取。', [
                'id'     => $recharge->id,
                'amount' => $recharge->amount,
                'status' => $recharge->status,
                'paid_at' => $recharge->paid_at,
            ]);

        } catch (\Exception $e) {
            return $this->jsonError($e->getMessage(), 422);
        }
    }

    /**
     * 获取充值历史
     */
    public function rechargeHistory()
    {
        try {
            $page = (int) $this->request->get('page', 1);
            $limit = (int) $this->request->get('limit', 10);

            $recharges = \app\model\BalanceRecharge::getUserHistory($this->userId, $page, $limit);
            $total = \app\model\BalanceRecharge::where('user_id', $this->userId)
                ->where('status', 'paid')
                ->count();

            return $this->jsonSuccess('充值历史已获取。', [
                'data' => $recharges,
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
            ]);

        } catch (\Exception $e) {
            return $this->jsonError($e->getMessage(), 500);
        }
    }

    /**
     * 创建购买订单（选择支付方式）
     */
    public function purchasePackage()
    {
        $this->requireCsrf();

        try {
            $packageId = (int) $this->request->post('package_id', 0);
            $months = (int) $this->request->post('months', 1);
            $paymentMethod = (string) $this->request->post('payment_method', 'balance');
            $couponCode = (string) $this->request->post('coupon_code', '');

            if ($packageId <= 0) {
                throw new \RuntimeException('套餐不存在');
            }

            $package = \app\model\Package::findOrFail($packageId);
            
            if (!$package->is_active) {
                throw new \RuntimeException('此套餐已下架');
            }

            if ($months <= 0 || $months > 24) {
                throw new \RuntimeException('购买月数应在 1-24 之间');
            }

            // 计算价格
            $priceMap = [
                1 => 'price_monthly',
                3 => 'price_quarterly',
                12 => 'price_yearly',
            ];

            $priceField = $priceMap[$months] ?? 'price_monthly';
            if ($months > 1 && !isset($priceMap[$months])) {
                // 按月价计算
                $unitPrice = (float) $package->$priceField;
                $totalPrice = $unitPrice * $months;
            } else {
                $totalPrice = (float) $package->$priceField;
            }

            // 应用优惠券
            $discountAmount = 0;
            if (!empty($couponCode)) {
                $coupon = \app\model\Coupon::where('code', $couponCode)
                    ->where('status', 'active')
                    ->findOrEmpty();

                if (!$coupon) {
                    throw new \RuntimeException('优惠券无效');
                }

                $discountAmount = (float) $coupon->discount_amount;
                $totalPrice = max(0, $totalPrice - $discountAmount);
            }

            // 统一交给面板服务创建订单
            $orderData = [
                'package_id' => $packageId,
                'device_count' => (int) $package->device_limit,
                'month_count' => $months,
                'coupon_code' => $couponCode,
                'payment_method' => $paymentMethod,
            ];

            $result = $this->panel->createOrder($orderData);
            $responseData = [
                'order_id' => $result['order_id'] ?? null,
                'order_no' => $result['order_no'] ?? null,
                'amount' => $result['amount_payable'] ?? $totalPrice,
                'method' => $paymentMethod,
            ];

            if ($paymentMethod === 'balance') {
                $responseData['status'] = 'paid';
                $responseData['subscription'] = [
                    'sub_url' => $result['subscription_url'] ?? '',
                    'status' => 'active',
                ];
            } elseif (in_array($paymentMethod, ['alipay', 'wxpay', 'wechat', 'qqpay'])) {
                // 码支付
                $baseUrl = rtrim((string) $this->settings->baseUrl(), '/');
                $orderNo = $result['order_no'] ?? '';
                $payResult = PaymentService::createMazhifuPayment(
                    $orderNo,
                    (float) ($result['amount_payable'] ?? $totalPrice),
                    '购买套餐: ' . $package->name,
                    $paymentMethod === 'wechat' ? 'wxpay' : $paymentMethod,
                    $baseUrl . '/pay/notify',
                    $baseUrl . '/pay/return'
                );
                $responseData['payment_url'] = $payResult['payment_url'] ?? '';
                $responseData['qr_code'] = $payResult['qr_code'] ?? '';
                $responseData['status'] = 'pending';
            } else {
                $responseData['status'] = 'pending';
            }

            return $this->jsonSuccess('订单已创建。', $responseData);

        } catch (\Exception $e) {
            return $this->jsonError($e->getMessage(), 422);
        }
    }

    /**
     * 激活订阅（内部方法）
     */
    private function activateSubscription(\app\model\Order $order): array
    {
        try {
            $user = \app\model\User::find($order->user_id);
            $package = \app\model\Package::find($order->package_id);

            if (!$user || !$package) {
                throw new \RuntimeException('用户或套餐不存在');
            }

            $months = (int) ($order->month_count ?? 1);
            $expireAt = date('Y-m-d H:i:s', strtotime("+{$months} months"));

            $subscription = \app\model\Subscription::where('user_id', $user->id)
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
                $subscription = new \app\model\Subscription();
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
            \think\facade\Log::error('激活订阅失败: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * 查询订单状态
     */
    public function checkOrderStatus(string $orderNo)
    {
        try {
            $order = \app\model\Order::where('no', $orderNo)->findOrFail();

            if ((int) $order->user_id !== $this->userId) {
                throw new \RuntimeException('无权查看此订单');
            }

            $data = [
                'order_no' => $order->no,
                'status'   => $order->status,
                'amount'   => $order->amount_payable,
                'paid_at'  => $order->paid_at,
            ];

            if ($order->subscription_id) {
                $subscription = \app\model\Subscription::find($order->subscription_id);
                $data['subscription'] = [
                    'id' => $subscription->id,
                    'expire_at' => $subscription->expire_at,
                    'device_limit' => $subscription->device_limit,
                ];
            }

            return $this->jsonSuccess('订单状态已获取。', $data);

        } catch (\Exception $e) {
            return $this->jsonError($e->getMessage(), 422);
        }
    }
}
