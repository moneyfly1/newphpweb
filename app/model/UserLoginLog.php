<?php
declare (strict_types = 1);

namespace app\model;

use think\Model;

class UserLoginLog extends Model
{
    protected $table = 'user_login_logs';

    protected $type = [
        'created_at' => 'datetime',
    ];

    /**
     * 关系：所属用户
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * 获取用户的登录历史
     */
    public static function userHistory(int $userId, int $limit = 50)
    {
        return self::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();
    }

    /**
     * 按国家统计登录次数
     */
    public static function statisticsByCountry()
    {
        return self::selectRaw('country, COUNT(*) as count')
            ->whereNotNull('country')
            ->groupBy('country')
            ->orderByDesc('count')
            ->get()
            ->toArray();
    }

    /**
     * 检测异常登录（新设备、新位置、异常时间）
     */
    public static function detectAnomalies(int $userId): array
    {
        $recentLogins = self::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        $anomalies = [];

        foreach ($recentLogins as $log) {
            if ($log->status === 'failed') {
                continue; // 跳过失败的登录
            }

            // 检测新设备
            $sameDeviceCount = self::where('user_id', $userId)
                ->where('device_type', $log->device_type)
                ->where('browser', $log->browser)
                ->count();

            if ($sameDeviceCount === 1) {
                $anomalies[] = [
                    'type' => 'new_device',
                    'message' => '新设备登录: ' . $log->device_type . ' - ' . $log->browser,
                    'timestamp' => $log->created_at,
                    'ip' => $log->ip_address,
                ];
            }

            // 检测新位置
            $sameLocationCount = self::where('user_id', $userId)
                ->where('country', $log->country)
                ->count();

            if ($sameLocationCount === 1 && $log->country) {
                $anomalies[] = [
                    'type' => 'new_location',
                    'message' => '新地区登录: ' . $log->country . ' - ' . $log->city,
                    'timestamp' => $log->created_at,
                    'location' => $log->city,
                ];
            }

            // 检测异常时间（如凌晨登录）
            $hour = date('H', strtotime($log->created_at));
            if ($hour >= 2 && $hour <= 5) {
                $anomalies[] = [
                    'type' => 'unusual_time',
                    'message' => '异常时间登录: ' . date('H:i', strtotime($log->created_at)),
                    'timestamp' => $log->created_at,
                ];
            }

            // 检测同一小时内多次登录失败
            $failedAttempts = self::where('user_id', $userId)
                ->where('status', 'failed')
                ->where('ip_address', $log->ip_address)
                ->whereRaw("created_at BETWEEN DATE_SUB(?, INTERVAL 1 HOUR) AND ?", [$log->created_at, $log->created_at])
                ->count();

            if ($failedAttempts > 3) {
                $anomalies[] = [
                    'type' => 'brute_force',
                    'message' => '检测到暴力破解尝试: ' . $failedAttempts . ' 次失败登录',
                    'timestamp' => $log->created_at,
                    'ip' => $log->ip_address,
                ];
            }
        }

        return $anomalies;
    }

    /**
     * 记录登录日志
     */
    public static function recordLogin(
        int $userId,
        string $ipAddress,
        string $userAgent,
        ?string $country = null,
        ?string $city = null,
        ?float $latitude = null,
        ?float $longitude = null,
        string $status = 'success',
        ?string $failedReason = null
    ): void
    {
        // 解析用户代理获取设备信息
        $deviceInfo = self::parseUserAgent($userAgent);

        self::create([
            'user_id' => $userId,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'device_type' => $deviceInfo['device_type'],
            'browser' => $deviceInfo['browser'],
            'os' => $deviceInfo['os'],
            'country' => $country,
            'city' => $city,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'status' => $status,
            'failed_reason' => $failedReason,
        ]);
    }

    /**
     * 解析 User-Agent 字符串提取设备信息
     */
    private static function parseUserAgent(string $userAgent): array
    {
        $result = [
            'device_type' => 'Unknown',
            'browser' => 'Unknown',
            'os' => 'Unknown',
        ];

        // 简化的 User-Agent 解析（生产环境建议使用 mobiledetect 库）
        if (strpos($userAgent, 'Mobile') !== false) {
            $result['device_type'] = 'Mobile';
        } elseif (strpos($userAgent, 'Tablet') !== false) {
            $result['device_type'] = 'Tablet';
        } else {
            $result['device_type'] = 'Desktop';
        }

        // 浏览器识别
        if (strpos($userAgent, 'Chrome') !== false) {
            $result['browser'] = 'Chrome';
        } elseif (strpos($userAgent, 'Firefox') !== false) {
            $result['browser'] = 'Firefox';
        } elseif (strpos($userAgent, 'Safari') !== false) {
            $result['browser'] = 'Safari';
        } elseif (strpos($userAgent, 'Edge') !== false) {
            $result['browser'] = 'Edge';
        }

        // 操作系统识别
        if (strpos($userAgent, 'Windows') !== false) {
            $result['os'] = 'Windows';
        } elseif (strpos($userAgent, 'Macintosh') !== false) {
            $result['os'] = 'macOS';
        } elseif (strpos($userAgent, 'Linux') !== false) {
            $result['os'] = 'Linux';
        } elseif (strpos($userAgent, 'iPhone') !== false) {
            $result['os'] = 'iOS';
        } elseif (strpos($userAgent, 'Android') !== false) {
            $result['os'] = 'Android';
        }

        return $result;
    }
}
