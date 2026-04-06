<?php
declare (strict_types = 1);

namespace app\service;

use app\model\Coupon;
use app\model\Order;
use app\model\Package;
use app\model\Subscription;
use app\model\Ticket;
use app\model\User;
use think\facade\Log;

class ExportService
{
    /**
     * 导出用户数据为 CSV
     */
    public static function exportUsersCSV(array $filters = [], string $format = 'csv'): string|array
    {
        try {
            $query = User::query();

            // 应用过滤条件
            if (!empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }
            if (!empty($filters['role'])) {
                $query->where('role', $filters['role']);
            }
            if (!empty($filters['created_since'])) {
                $query->where('created_at', '>=', $filters['created_since']);
            }

            $users = $query->select([
                'id', 'email', 'nickname', 'phone', 'status', 'role', 
                'balance', 'inviter_id', 'created_at', 'updated_at'
            ])->get()->toArray();

            if ($format === 'csv') {
                return self::arrayToCSV($users, ['ID', '邮箱', '昵称', '手机', '状态', '角色', '余额', '邀请者ID', '创建时间', '更新时间']);
            } else {
                return $users;
            }

        } catch (\Exception $e) {
            Log::error('用户数据导出失败: ' . $e->getMessage());
            throw new \RuntimeException('用户数据导出失败: ' . $e->getMessage());
        }
    }

    /**
     * 导出订单数据
     */
    public static function exportOrdersCSV(array $filters = [], string $format = 'csv'): string|array
    {
        try {
            $query = Order::query();

            // 应用过滤条件
            if (!empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }
            if (!empty($filters['payment_method'])) {
                $query->where('payment_method', $filters['payment_method']);
            }
            if (!empty($filters['date_from'])) {
                $query->where('created_at', '>=', $filters['date_from']);
            }
            if (!empty($filters['date_to'])) {
                $query->where('created_at', '<=', $filters['date_to']);
            }

            $orders = $query->with('user:id,email,nickname')
                ->select([
                    'id', 'no', 'user_id', 'package_id', 'coupon_id', 'amount_original',
                    'discount_amount', 'amount_payable', 'payment_method', 'status',
                    'paid_at', 'created_at'
                ])
                ->orderBy('created_at', 'desc')
                ->get()
                ->toArray();

            if ($format === 'csv') {
                $headers = ['订单ID', '订单号', '用户邮箱', '套餐ID', '原价', '折扣', '应付', '支付方式', '状态', '支付时间', '创建时间'];
                $rows = array_map(function ($order) {
                    return [
                        $order['id'],
                        $order['no'],
                        $order['user']['email'] ?? '',
                        $order['package_id'],
                        $order['amount_original'],
                        $order['discount_amount'],
                        $order['amount_payable'],
                        $order['payment_method'],
                        $order['status'],
                        $order['paid_at'] ?? '-',
                        $order['created_at'],
                    ];
                }, $orders);

                return self::arrayToCSV($rows, $headers);
            } else {
                return $orders;
            }

        } catch (\Exception $e) {
            Log::error('订单数据导出失败: ' . $e->getMessage());
            throw new \RuntimeException('订单数据导出失败: ' . $e->getMessage());
        }
    }

    /**
     * 导出订阅数据
     */
    public static function exportSubscriptionsCSV(array $filters = [], string $format = 'csv'): string|array
    {
        try {
            $query = Subscription::query();

            if (!empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }
            if (!empty($filters['package_id'])) {
                $query->where('package_id', $filters['package_id']);
            }

            $subs = $query->with(['user:id,email,nickname', 'package:id,name'])
                ->select([
                    'id', 'user_id', 'package_id', 'expire_at', 'status',
                    'total_reads', 'device_count', 'created_at', 'updated_at'
                ])
                ->get()
                ->toArray();

            if ($format === 'csv') {
                $headers = ['ID', '用户邮箱', '套餐名称', '过期时间', '状态', '总阅读次数', '设备数', '创建时间'];
                $rows = array_map(function ($sub) {
                    return [
                        $sub['id'],
                        $sub['user']['email'] ?? '',
                        $sub['package']['name'] ?? '',
                        $sub['expire_at'],
                        $sub['status'],
                        $sub['total_reads'],
                        $sub['device_count'],
                        $sub['created_at'],
                    ];
                }, $subs);

                return self::arrayToCSV($rows, $headers);
            } else {
                return $subs;
            }

        } catch (\Exception $e) {
            Log::error('订阅数据导出失败: ' . $e->getMessage());
            throw new \RuntimeException('订阅数据导出失败: ' . $e->getMessage());
        }
    }

    /**
     * 导出优惠券数据
     */
    public static function exportCouponsCSV(array $filters = [], string $format = 'csv'): string|array
    {
        try {
            $query = Coupon::query();

            if (!empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }

            $coupons = $query->select([
                'id', 'code', 'type', 'percentage', 'fixed_discount', 'max_uses',
                'used_count', 'valid_from', 'valid_to', 'status', 'created_at'
            ])
            ->get()
            ->toArray();

            if ($format === 'csv') {
                $headers = ['ID', '代码', '类型', '折扣百分比', '固定折扣', '最大使用次数', '已使用', '有效期开始', '有效期结束', '状态', '创建时间'];
                return self::arrayToCSV($coupons, $headers);
            } else {
                return $coupons;
            }

        } catch (\Exception $e) {
            Log::error('优惠券数据导出失败: ' . $e->getMessage());
            throw new \RuntimeException('优惠券数据导出失败: ' . $e->getMessage());
        }
    }

    /**
     * 导出工单数据
     */
    public static function exportTicketsCSV(array $filters = [], string $format = 'csv'): string|array
    {
        try {
            $query = Ticket::query();

            if (!empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }
            if (!empty($filters['priority'])) {
                $query->where('priority', $filters['priority']);
            }

            $tickets = $query->with('user:id,email,nickname')
                ->select([
                    'id', 'no', 'user_id', 'subject', 'priority', 'status',
                    'reply_count', 'created_at', 'updated_at'
                ])
                ->orderBy('created_at', 'desc')
                ->get()
                ->toArray();

            if ($format === 'csv') {
                $headers = ['ID', '工单号', '用户邮箱', '主题', '优先级', '状态', '回复数', '创建时间'];
                $rows = array_map(function ($ticket) {
                    return [
                        $ticket['id'],
                        $ticket['no'],
                        $ticket['user']['email'] ?? '',
                        $ticket['subject'],
                        $ticket['priority'],
                        $ticket['status'],
                        $ticket['reply_count'],
                        $ticket['created_at'],
                    ];
                }, $tickets);

                return self::arrayToCSV($rows, $headers);
            } else {
                return $tickets;
            }

        } catch (\Exception $e) {
            Log::error('工单数据导出失败: ' . $e->getMessage());
            throw new \RuntimeException('工单数据导出失败: ' . $e->getMessage());
        }
    }

    /**
     * 导出套餐数据
     */
    public static function exportPackagesCSV(array $filters = [], string $format = 'csv'): string|array
    {
        try {
            $query = Package::query();

            if (!empty($filters['is_active'])) {
                $query->where('is_active', $filters['is_active']);
            }

            $packages = $query->select([
                'id', 'name', 'description', 'price', 'duration_days', 'device_limit',
                'bandwidth_limit', 'is_active', 'sort_order', 'created_at'
            ])->get()->toArray();

            if ($format === 'csv') {
                $headers = ['ID', '名称', '描述', '价格', '有效期（天）', '设备限制', '带宽限制', '是否激活', '排序', '创建时间'];
                return self::arrayToCSV($packages, $headers);
            } else {
                return $packages;
            }

        } catch (\Exception $e) {
            Log::error('套餐数据导出失败: ' . $e->getMessage());
            throw new \RuntimeException('套餐数据导出失败: ' . $e->getMessage());
        }
    }

    /**
     * 将数组转换为 CSV 格式字符串
     */
    public static function arrayToCSV(array $rows, array $headers = []): string
    {
        $csv = '';

        // 添加 BOM 以支持 UTF-8
        $csv .= "\xEF\xBB\xBF";

        // 添加标题行
        if (!empty($headers)) {
            $csv .= implode(',', array_map([self::class, 'sanitizeCSVField'], $headers)) . "\n";
        }

        // 添加数据行
        foreach ($rows as $row) {
            if (is_array($row)) {
                $csv .= implode(',', array_map([self::class, 'sanitizeCSVField'], $row)) . "\n";
            }
        }

        return $csv;
    }

    /**
     * 清理 CSV 字段（处理引号和换行）
     */
    private static function sanitizeCSVField($field): string
    {
        $field = (string) $field;

        // 如果包含逗号、引号或换行，需要用引号包围
        if (strpos($field, ',') !== false || strpos($field, '"') !== false || strpos($field, "\n") !== false) {
            $field = '"' . str_replace('"', '""', $field) . '"';
        }

        return $field;
    }

    /**
     * 批量导出多个模型到 ZIP 文件
     */
    public static function batchExport(array $models = [], $format = 'csv', array $filters = []): string
    {
        try {
            $zipPath = sys_get_temp_dir() . '/batch_export_' . time() . '_' . md5(json_encode($models)) . '.zip';
            $zip = new \ZipArchive();

            if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('无法创建 ZIP 文件');
            }

            // 生成每个模型的导出文件
            foreach ($models as $model) {
                try {
                    $methodName = 'export' . ucfirst($model) . 'CSV';

                    if (!method_exists(self::class, $methodName)) {
                        continue;
                    }

                    // 调用导出方法，获取数据
                    $content = self::$methodName($filters, $format);

                    // 如果返回的是数组（JSON格式），转换为JSON字符串
                    if (is_array($content)) {
                        $content = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                    }

                    $filename = $model . '_' . date('Y-m-d_H-i-s') . '.' . $format;

                    $zip->addFromString($filename, $content);
                } catch (\Exception $e) {
                    \think\facade\Log::error('批量导出 ' . $model . ' 失败: ' . $e->getMessage());
                }
            }

            $zip->close();

            return $zipPath;
        } catch (\Exception $e) {
            \think\facade\Log::error('批量导出失败: ' . $e->getMessage());
            throw new \RuntimeException('批量导出失败: ' . $e->getMessage());
        }
    }

    /**
     * 生成 Excel 下载响应（需要 maatwebsite/excel 包）
     * 这里提供一个简化的实现，生产环境建议使用专门的库
     */
    public static function downloadAsFile(string $content, string $filename, string $format = 'csv'): void
    {
        $mime = $format === 'csv' ? 'text/csv; charset=utf-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($content));
        header('Cache-Control: must-revalidate');
        header('Pragma: public');

        echo $content;
        exit;
    }
}
