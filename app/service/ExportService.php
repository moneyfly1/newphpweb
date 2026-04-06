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
    public static function exportUsersCSV(array $filters = [], string $format = 'csv'): string|array
    {
        try {
            $query = User::field('id, email, nickname, status, role, balance, invite_code, created_at, updated_at');

            if (!empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }
            if (!empty($filters['role'])) {
                $query->where('role', $filters['role']);
            }
            if (!empty($filters['created_since'])) {
                $query->where('created_at', '>=', $filters['created_since']);
            }

            $users = $query->select()->toArray();

            if ($format === 'csv') {
                return self::arrayToCSV($users, ['ID', '邮箱', '昵称', '状态', '角色', '余额', '邀请码', '创建时间', '更新时间']);
            }
            return $users;
        } catch (\Exception $e) {
            Log::error('用户数据导出失败: ' . $e->getMessage());
            throw new \RuntimeException('用户数据导出失败: ' . $e->getMessage());
        }
    }

    public static function exportOrdersCSV(array $filters = [], string $format = 'csv'): string|array
    {
        try {
            $query = Order::order('created_at', 'desc');

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

            $orders = $query->select()->toArray();

            // 批量获取用户邮箱
            $userIds = array_unique(array_column($orders, 'user_id'));
            $userMap = [];
            if ($userIds) {
                $users = User::whereIn('id', $userIds)->field('id, email')->select();
                foreach ($users as $u) { $userMap[$u->id] = $u->email; }
            }

            if ($format === 'csv') {
                $headers = ['订单ID', '订单号', '用户邮箱', '套餐ID', '原价', '折扣', '应付', '支付方式', '状态', '支付时间', '创建时间'];
                $rows = array_map(function ($o) use ($userMap) {
                    return [
                        $o['id'], $o['no'], $userMap[$o['user_id']] ?? '',
                        $o['package_id'], $o['amount_original'], $o['discount_amount'],
                        $o['amount_payable'], $o['payment_method'], $o['status'],
                        $o['paid_at'] ?? '-', $o['created_at'],
                    ];
                }, $orders);
                return self::arrayToCSV($rows, $headers);
            }
            return $orders;
        } catch (\Exception $e) {
            Log::error('订单数据导出失败: ' . $e->getMessage());
            throw new \RuntimeException('订单数据导出失败: ' . $e->getMessage());
        }
    }

    // __EXPORT_PLACEHOLDER_2__

    public static function exportSubscriptionsCSV(array $filters = [], string $format = 'csv'): string|array
    {
        try {
            $query = Subscription::order('created_at', 'desc');

            if (!empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }
            if (!empty($filters['package_id'])) {
                $query->where('package_id', $filters['package_id']);
            }

            $subs = $query->select()->toArray();

            $userIds = array_unique(array_column($subs, 'user_id'));
            $pkgIds = array_unique(array_column($subs, 'package_id'));
            $userMap = [];
            $pkgMap = [];
            if ($userIds) {
                foreach (User::whereIn('id', $userIds)->field('id, email')->select() as $u) { $userMap[$u->id] = $u->email; }
            }
            if ($pkgIds) {
                foreach (Package::whereIn('id', $pkgIds)->field('id, name')->select() as $p) { $pkgMap[$p->id] = $p->name; }
            }

            if ($format === 'csv') {
                $headers = ['ID', '用户邮箱', '套餐名称', '过期时间', '状态', '设备限制', '已用设备', '创建时间'];
                $rows = array_map(function ($s) use ($userMap, $pkgMap) {
                    return [
                        $s['id'], $userMap[$s['user_id']] ?? '', $pkgMap[$s['package_id']] ?? '',
                        $s['expire_at'] ?? '', $s['status'], $s['device_limit'], $s['used_devices'], $s['created_at'],
                    ];
                }, $subs);
                return self::arrayToCSV($rows, $headers);
            }
            return $subs;
        } catch (\Exception $e) {
            Log::error('订阅数据导出失败: ' . $e->getMessage());
            throw new \RuntimeException('订阅数据导出失败: ' . $e->getMessage());
        }
    }

    public static function exportTicketsCSV(array $filters = [], string $format = 'csv'): string|array
    {
        try {
            $query = Ticket::order('created_at', 'desc');

            if (!empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }

            $tickets = $query->select()->toArray();

            $userIds = array_unique(array_column($tickets, 'user_id'));
            $userMap = [];
            if ($userIds) {
                foreach (User::whereIn('id', $userIds)->field('id, email')->select() as $u) { $userMap[$u->id] = $u->email; }
            }

            if ($format === 'csv') {
                $headers = ['ID', '工单号', '用户邮箱', '主题', '状态', '创建时间', '更新时间'];
                $rows = array_map(function ($t) use ($userMap) {
                    return [
                        $t['id'], $t['no'] ?? '', $userMap[$t['user_id']] ?? '',
                        $t['subject'] ?? '', $t['status'], $t['created_at'], $t['updated_at'],
                    ];
                }, $tickets);
                return self::arrayToCSV($rows, $headers);
            }
            return $tickets;
        } catch (\Exception $e) {
            Log::error('工单数据导出失败: ' . $e->getMessage());
            throw new \RuntimeException('工单数据导出失败: ' . $e->getMessage());
        }
    }

    public static function exportCouponsCSV(array $filters = [], string $format = 'csv'): string|array
    {
        try {
            $coupons = Coupon::order('created_at', 'desc')->select()->toArray();

            if ($format === 'csv') {
                $headers = ['ID', '优惠码', '名称', '折扣类型', '折扣值', '已用/总量', '状态', '开始时间', '结束时间'];
                $rows = array_map(fn($c) => [
                    $c['id'], $c['code'], $c['name'] ?? '', $c['discount_type'],
                    $c['discount_value'], $c['used_count'] . '/' . $c['total_limit'],
                    $c['status'], $c['start_at'] ?? '-', $c['end_at'] ?? '-',
                ], $coupons);
                return self::arrayToCSV($rows, $headers);
            }
            return $coupons;
        } catch (\Exception $e) {
            Log::error('优惠券数据导出失败: ' . $e->getMessage());
            throw new \RuntimeException('优惠券数据导出失败: ' . $e->getMessage());
        }
    }

    public static function exportPackagesCSV(array $filters = [], string $format = 'csv'): string|array
    {
        try {
            $packages = Package::order('sort_order')->select()->toArray();

            if ($format === 'csv') {
                $headers = ['ID', '名称', '月价', '季价', '年价', '设备限制', '流量(GB)', '状态'];
                $rows = array_map(fn($p) => [
                    $p['id'], $p['name'], $p['price_monthly'], $p['price_quarterly'],
                    $p['price_yearly'], $p['device_limit'], $p['traffic_limit_gb'], $p['is_active'] ? '启用' : '禁用',
                ], $packages);
                return self::arrayToCSV($rows, $headers);
            }
            return $packages;
        } catch (\Exception $e) {
            Log::error('套餐数据导出失败: ' . $e->getMessage());
            throw new \RuntimeException('套餐数据导出失败: ' . $e->getMessage());
        }
    }

    // __EXPORT_PLACEHOLDER_3__

    private static function arrayToCSV(array $rows, array $headers = []): string
    {
        $output = fopen('php://temp', 'r+');
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM

        if (!empty($headers)) {
            fputcsv($output, $headers);
        }

        foreach ($rows as $row) {
            fputcsv($output, is_array($row) ? array_values($row) : array_values((array) $row));
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    public static function batchExport(array $models, array $filters = [], string $format = 'csv'): string
    {
        try {
            $zipPath = runtime_path() . 'temp/export_' . date('Y-m-d_H-i-s') . '.zip';
            $dir = dirname($zipPath);
            if (!is_dir($dir)) { mkdir($dir, 0755, true); }

            $zip = new \ZipArchive();
            if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('无法创建 ZIP 文件');
            }

            foreach ($models as $model) {
                try {
                    $methodName = 'export' . ucfirst($model) . 'CSV';
                    if (!method_exists(self::class, $methodName)) { continue; }

                    $content = self::$methodName($filters, $format);
                    if (is_array($content)) {
                        $content = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                    }

                    $filename = $model . '_' . date('Y-m-d_H-i-s') . '.' . $format;
                    $zip->addFromString($filename, $content);
                } catch (\Exception $e) {
                    Log::error('批量导出 ' . $model . ' 失败: ' . $e->getMessage());
                }
            }

            $zip->close();
            return $zipPath;
        } catch (\Exception $e) {
            Log::error('批量导出失败: ' . $e->getMessage());
            throw new \RuntimeException('批量导出失败: ' . $e->getMessage());
        }
    }

    public static function downloadAsFile(string $content, string $filename, string $format = 'csv'): void
    {
        $mime = $format === 'csv' ? 'text/csv; charset=utf-8' : 'application/octet-stream';

        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($content));
        header('Cache-Control: must-revalidate');
        header('Pragma: public');

        echo $content;
        exit;
    }
}
