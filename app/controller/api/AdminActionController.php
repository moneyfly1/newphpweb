<?php
declare (strict_types = 1);

namespace app\controller\api;

use app\BaseController;
use app\service\payment\PaymentService;

class AdminActionController extends BaseController
{
    public function savePackage()
    {
        $this->requireCsrf();

        try {
            $data = $this->panel->savePackage($this->request->post());
        } catch (\RuntimeException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        }

        return $this->jsonSuccess('套餐已保存。', $data);
    }

    public function saveCoupon()
    {
        $this->requireCsrf();

        try {
            $data = $this->panel->saveCoupon($this->request->post());
        } catch (\RuntimeException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        }

        return $this->jsonSuccess('优惠券已保存。', $data);
    }

    public function updateUserNote(int $id)
    {
        $this->requireCsrf();

        if ($id <= 0) {
            return $this->jsonError('用户ID无效', 422);
        }

        try {
            \app\model\User::where('id', $id)->update(['note' => trim((string) $this->request->post('note', ''))]);
        } catch (\Exception $e) {
            return $this->jsonError($e->getMessage(), 422);
        }

        return $this->jsonSuccess('备注已保存。');
    }

    public function updateUserStatus(int $id)
    {
        $this->requireCsrf();

        try {
            $data = $this->panel->updateUserStatus($id, filter_var($this->request->post('enabled'), FILTER_VALIDATE_BOOLEAN));
        } catch (\RuntimeException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        }

        return $this->jsonSuccess('用户状态已更新。', $data);
    }

    public function batchUsers()
    {
        $this->requireCsrf();

        try {
            $ids = $this->request->post('ids/a', []);
            if ($ids === [] && $this->request->post('ids')) {
                $ids = array_filter(array_map('trim', explode(',', (string) $this->request->post('ids'))));
            }
            $action = (string) $this->request->post('action');
            $data = $this->panel->batchUsers($ids, $action);
        } catch (\RuntimeException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        }

        return $this->jsonSuccess('批量操作已执行。', $data);
    }

    public function batchOrders()
    {
        $this->requireCsrf();

        try {
            $nos = $this->request->post('ids/a', []);
            $action = (string) $this->request->post('action');
            foreach ($nos as $no) {
                $this->panel->updateOrderStatus((string) $no, $action);
            }
        } catch (\RuntimeException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        }

        return $this->jsonSuccess('订单批量操作已执行。');
    }

    public function batchSubscriptions()
    {
        $this->requireCsrf();

        try {
            $ids = $this->request->post('ids/a', []);
            $action = (string) $this->request->post('action');
            foreach ($ids as $id) {
                if ($action === 'extend30') {
                    $this->panel->extendSubscription((int) $id, 30);
                } elseif ($action === 'reset') {
                    $this->panel->resetSubscriptionByAdmin((int) $id);
                } elseif ($action === 'clear') {
                    $this->panel->clearSubscriptionDevicesByAdmin((int) $id);
                }
            }
        } catch (\RuntimeException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        }

        return $this->jsonSuccess('订阅批量操作已执行。');
    }

    public function batchTickets()
    {
        $this->requireCsrf();

        try {
            $ids = $this->request->post('ids/a', []);
            $action = (string) $this->request->post('action');
            foreach ($ids as $id) {
                $ticket = \app\model\Ticket::find((int) $id);
                if (!$ticket) {
                    continue;
                }
                if ($action === 'close') {
                    $ticket->status = 'closed';
                    $ticket->save();
                } elseif ($action === 'processing') {
                    $ticket->status = 'in_progress';
                    $ticket->save();
                }
            }
        } catch (\RuntimeException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        }

        return $this->jsonSuccess('工单批量操作已执行。');
    }

    public function batchPackages()
    {
        $this->requireCsrf();

        try {
            $ids = $this->request->post('ids/a', []);
            $action = (string) $this->request->post('action');
            foreach ($ids as $id) {
                $package = \app\model\Package::find((int) $id);
                if (!$package) {
                    continue;
                }
                if ($action === 'enable') {
                    $package->is_active = 1;
                } elseif ($action === 'disable') {
                    $package->is_active = 0;
                }
                $package->save();
            }
        } catch (\RuntimeException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        }

        return $this->jsonSuccess('套餐批量操作已执行。');
    }

    public function batchCoupons()
    {
        $this->requireCsrf();

        try {
            $ids = $this->request->post('ids/a', []);
            $action = (string) $this->request->post('action');
            foreach ($ids as $id) {
                $coupon = \app\model\Coupon::find((int) $id);
                if (!$coupon) {
                    continue;
                }
                if ($action === 'enable') {
                    $coupon->status = 1;
                } elseif ($action === 'disable') {
                    $coupon->status = 0;
                }
                $coupon->save();
            }
        } catch (\RuntimeException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        }

        return $this->jsonSuccess('优惠券批量操作已执行。');
    }

    public function updateOrderStatus(string $no)
    {
        $this->requireCsrf();

        try {
            $data = $this->panel->updateOrderStatus($no, (string) $this->request->post('action'));
        } catch (\RuntimeException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        }

        return $this->jsonSuccess('订单状态已更新。', $data);
    }

    public function extendSubscription(int $id)
    {
        $this->requireCsrf();

        try {
            $data = $this->panel->extendSubscription($id, (int) $this->request->post('days', 30));
        } catch (\RuntimeException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        }

        return $this->jsonSuccess('订阅已延期。', $data);
    }

    public function resetSubscription(int $id)
    {
        $this->requireCsrf();

        try {
            $data = $this->panel->resetSubscriptionByAdmin($id);
        } catch (\RuntimeException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        }

        return $this->jsonSuccess('订阅已重置。', $data);
    }

    public function updateSubscriptionDeviceLimit(int $id)
    {
        $this->requireCsrf();

        try {
            $data = $this->panel->updateSubscriptionDeviceLimit($id, (int) $this->request->post('device_limit', 0));
        } catch (\RuntimeException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        }

        return $this->jsonSuccess('设备数量已更新。', $data);
    }

    public function updateSubscriptionExpireAt(int $id)
    {
        $this->requireCsrf();

        try {
            $data = $this->panel->updateSubscriptionExpireAt($id, (string) $this->request->post('expire_at', ''));
        } catch (\RuntimeException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        }

        return $this->jsonSuccess('到期时间已更新。', $data);
    }

    public function replyTicket(string $no)
    {
        $this->requireCsrf();

        try {
            $data = $this->panel->replyTicket($no, (string) $this->request->post('content'));
        } catch (\RuntimeException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        }

        return $this->jsonSuccess('工单已回复。', $data);
    }

    public function advancedSettings()
    {
        try {
            $data = $this->panel->advancedSettings();
        } catch (\RuntimeException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        }

        return $this->jsonSuccess('高级设置已获取。', $data);
    }

    public function saveAdvancedSettings()
    {
        $this->requireCsrf();

        try {
            $data = $this->panel->saveAdvancedSettings($this->request->post());
        } catch (\RuntimeException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        }

        return $this->jsonSuccess('高级设置已保存。', $data);
    }

    public function auditLogs()
    {
        $page = (int) $this->request->get('page', 1);
        $limit = (int) $this->request->get('limit', 20);

        try {
            $data = $this->panel->auditLogs($page, $limit);
        } catch (\RuntimeException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        }

        return $this->jsonSuccess('审计日志已获取。', $data);
    }

    public function systemStatistics()
    {
        try {
            $period = $this->request->get('period', '7day');
            $data = $this->panel->systemStatistics($period);
        } catch (\RuntimeException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        }

        return $this->jsonSuccess('系统统计已获取。', $data);
    }

    public function emailQueueStats()
    {
        try {
            $data = $this->panel->getEmailQueueStats();
        } catch (\RuntimeException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        }

        return $this->jsonSuccess('邮件队列统计已获取。', $data);
    }

    public function emailQueueRecent()
    {
        try {
            $limit = (int)$this->request->get('limit', 50);
            $data = $this->panel->getEmailQueueRecent($limit);
        } catch (\RuntimeException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        }

        return $this->jsonSuccess('最近邮件记录已获取。', $data);
    }

    public function emailQueueCleanup()
    {
        $this->requireCsrf();

        try {
            $keepDays = (int)$this->request->post('keep_days', 30);
            $deleted = $this->panel->cleanupSentEmails($keepDays);
        } catch (\RuntimeException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        }

        return $this->jsonSuccess('已清理' . $deleted . '条邮件。', ['deleted' => $deleted]);
    }

    public function emailQueueRetry()
    {
        $this->requireCsrf();

        try {
            $retried = $this->panel->retryFailedEmails(3);
        } catch (\RuntimeException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        }

        return $this->jsonSuccess('已重试' . $retried . '条邮件。', ['retried' => $retried]);
    }

    public function emailQueueDelete(int $id)
    {
        $this->requireCsrf();

        try {
            $this->panel->deleteEmailQueueItem($id);
        } catch (\RuntimeException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        }

        return $this->jsonSuccess('邮件已删除。');
    }

    public function emailQueueStatsByType()
    {
        try {
            $data = $this->panel->getEmailStatsByType();
        } catch (\RuntimeException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        }

        return $this->jsonSuccess('邮件类型统计已获取。', $data);
    }

    public function exportData()
    {
        try {
            $exportService = new \app\service\ExportService();

            $model = (string)$this->request->get('model', 'users'); // users, orders, subscriptions, coupons, tickets, packages
            $format = (string)$this->request->get('format', 'csv'); // csv 或 json
            $filters = $this->request->get('filters/a', []);

            // 调用相应的导出方法
            $methodName = 'export' . ucfirst($model) . ucfirst($format);
            
            if (!method_exists($exportService, $methodName)) {
                throw new \RuntimeException('不支持的导出格式: ' . $model . ' / ' . $format);
            }

            $data = $exportService->$methodName($filters, $format);

            if ($format === 'csv') {
                // 返回 CSV 数据供前端下载
                return $this->jsonSuccess('数据已导出。', [
                    'content' => $data,
                    'filename' => $model . '_' . date('Y-m-d_H-i-s') . '.csv'
                ]);
            } else {
                return $this->jsonSuccess('数据已导出。', $data);
            }

        } catch (\RuntimeException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        }
    }

    public function userLoginHistory(int $userId)
    {
        try {
            $page = (int)$this->request->get('page', 1);
            $limit = (int)$this->request->get('limit', 20);
            $data = $this->panel->getUserLoginHistory($userId, $page, $limit);
        } catch (\RuntimeException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        }

        return $this->jsonSuccess('登录历史已获取。', $data);
    }

    public function userLoginAnomalies(int $userId)
    {
        try {
            $data = $this->panel->getLoginAnomalies($userId);
        } catch (\RuntimeException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        }

        return $this->jsonSuccess('登录异常已获取。', $data);
    }

    public function recentLogins()
    {
        try {
            $limit = (int)$this->request->get('limit', 20);
            $data = $this->panel->getRecentLogins($limit);
        } catch (\RuntimeException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        }

        return $this->jsonSuccess('最近登录已获取。', $data);
    }

    public function loginStatsByCountry()
    {
        try {
            $data = $this->panel->getLoginStatsByCountry();
        } catch (\RuntimeException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        }

        return $this->jsonSuccess('登录地理统计已获取。', $data);
    }

    public function suspiciousLogins()
    {
        try {
            $data = $this->panel->detectSuspiciousLogins();
        } catch (\RuntimeException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        }

        return $this->jsonSuccess('可疑登录已检测。', $data);
    }

    public function batchExport()
    {
        try {
            $models = $this->request->get('models/a', []);
            $format = (string)$this->request->get('format', 'csv');
            $filters = $this->request->get('filters/a', []);

            if (empty($models)) {
                throw new \RuntimeException('至少选择一个要导出的模型');
            }

            if (count($models) === 1) {
                // 单个模型直接导出
                $model = $models[0];
                $methodName = 'export' . ucfirst($model) . 'CSV';
                
                if (!method_exists(\app\service\ExportService::class, $methodName)) {
                    throw new \RuntimeException('不支持导出该模型');
                }

                $content = \app\service\ExportService::$methodName($filters, $format);
                
                // 如果返回的是数组（JSON格式），转换为JSON字符串
                if (is_array($content)) {
                    $content = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                }
                
                return $this->jsonSuccess('数据已导出。', [
                    'content' => $content,
                    'filename' => $model . '_' . date('Y-m-d_H-i-s') . '.' . $format
                ]);
            } else {
                // 多个模型导出为 ZIP
                $zipPath = \app\service\ExportService::batchExport($models, $format, $filters);
                
                // 读取 ZIP 文件内容并编码为 base64 供前端下载
                $zipContent = file_get_contents($zipPath);
                @unlink($zipPath); // 清理临时文件

                return $this->jsonSuccess('数据已导出。', [
                    'content' => base64_encode($zipContent),
                    'filename' => 'batch_export_' . date('Y-m-d_H-i-s') . '.zip',
                    'isZip' => true
                ]);
            }

        } catch (\RuntimeException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        }
    }

    /**
     * 获取所有支付方式
     */
    public function paymentMethods()
    {
        try {
            $methods = \app\model\PaymentMethod::orderBy('sort_order', 'asc')->select();
            return $this->jsonSuccess('支付方式列表已获取。', $methods->toArray());
        } catch (\Exception $e) {
            return $this->jsonError($e->getMessage(), 500);
        }
    }

    /**
     * 更新支付方式配置
     */
    public function updatePaymentMethod(int $id)
    {
        $this->requireCsrf();

        try {
            $method = \app\model\PaymentMethod::findOrFail($id);
            
            $enabled = filter_var($this->request->post('is_enabled'), FILTER_VALIDATE_BOOLEAN);
            $config = $this->request->post('config/a', []);

            // 验证支付宝配置
            if ($method->code === 'alipay' && $enabled && !empty($config)) {
                if (empty($config['app_id'])) {
                    throw new \RuntimeException('支付宝应用ID不能为空');
                }
                if (empty($config['private_key'])) {
                    throw new \RuntimeException('支付宝应用私钥不能为空');
                }
            }

            $method->is_enabled = $enabled;
            if (!empty($config)) {
                $method->setConfig($config);
            }
            $method->save();

            return $this->jsonSuccess('支付方式已更新。', $method->toArray());
        } catch (\Exception $e) {
            return $this->jsonError($e->getMessage(), 422);
        }
    }

    /**
     * 测试支付宝连接
     */
    public function testAlipay()
    {
        try {
            $method = \app\model\PaymentMethod::getByCode('alipay');
            if (!$method) {
                throw new \RuntimeException('支付宝配置不存在');
            }

            $config = $method->getConfig();
            $paymentData = PaymentService::testAlipayConfig($config);
            return $this->jsonSuccess('支付宝配置有效。', $paymentData);
        } catch (\Exception $e) {
            return $this->jsonError('支付宝配置测试失败: ' . $e->getMessage(), 422);
        }
    }
}

