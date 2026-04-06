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

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public static function userHistory(int $userId, int $limit = 50)
    {
        return self::where('user_id', $userId)
            ->order('created_at', 'desc')
            ->limit($limit)
            ->select()
            ->toArray();
    }

    public static function statisticsByCountry()
    {
        return self::field('country, COUNT(*) as count')
            ->where('country', '<>', '')
            ->group('country')
            ->order('count', 'desc')
            ->select()
            ->toArray();
    }

    public static function detectAnomalies(int $userId): array
    {
        $recentLogins = self::where('user_id', $userId)
            ->order('created_at', 'desc')
            ->limit(10)
            ->select();

        $anomalies = [];

        foreach ($recentLogins as $log) {
            if ($log->status === 'failed') {
                continue;
            }

            $sameDeviceCount = self::where('user_id', $userId)
                ->where('device_type', $log->device_type)
                ->count();

            if ($sameDeviceCount === 1) {
                $anomalies[] = [
                    'type' => 'new_device',
                    'message' => '新设备登录: ' . ($log->device_type ?? ''),
                    'timestamp' => $log->created_at,
                    'ip' => $log->ip_address,
                ];
            }

            $sameLocationCount = self::where('user_id', $userId)
                ->where('country', $log->country)
                ->count();

            if ($sameLocationCount === 1 && $log->country) {
                $anomalies[] = [
                    'type' => 'new_location',
                    'message' => '新地区登录: ' . $log->country . ' - ' . ($log->city ?? ''),
                    'timestamp' => $log->created_at,
                    'location' => $log->city ?? '',
                ];
            }

            $hour = (int) date('H', strtotime((string) $log->created_at));
            if ($hour >= 2 && $hour <= 5) {
                $anomalies[] = [
                    'type' => 'unusual_time',
                    'message' => '异常时间登录: ' . date('H:i', strtotime((string) $log->created_at)),
                    'timestamp' => $log->created_at,
                ];
            }

            $failedAttempts = self::where('user_id', $userId)
                ->where('status', 'failed')
                ->where('ip_address', $log->ip_address)
                ->whereRaw("created_at >= datetime(?, '-1 hour') AND created_at <= ?", [(string) $log->created_at, (string) $log->created_at])
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

    public static function recordLogin(
        int $userId,
        string $ipAddress,
        string $userAgent,
        ?string $country = null,
        ?string $city = null,
        string $status = 'success',
        ?string $failedReason = null
    ): void {
        $deviceInfo = self::parseUserAgent($userAgent);

        self::create([
            'user_id' => $userId,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'device_type' => $deviceInfo['device_type'],
            'country' => $country,
            'city' => $city,
            'status' => $status,
            'failed_reason' => $failedReason,
        ]);
    }

    private static function parseUserAgent(string $userAgent): array
    {
        $result = ['device_type' => 'Unknown'];

        if (str_contains($userAgent, 'Mobile')) {
            $result['device_type'] = 'Mobile';
        } elseif (str_contains($userAgent, 'Tablet')) {
            $result['device_type'] = 'Tablet';
        } else {
            $result['device_type'] = 'Desktop';
        }

        return $result;
    }
}
