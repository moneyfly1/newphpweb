<?php
declare(strict_types=1);

namespace app\service;

use app\model\Subscription;
use think\facade\Db;
use think\facade\Log;

/**
 * 设备识别与管理服务
 * 复刻自老项目 Go 版 DeviceManager
 */
class DeviceService
{
    /**
     * 解析 User-Agent，识别代理客户端
     */
    public static function parseUserAgent(string $ua): array
    {
        $info = [
            'software_name' => 'Unknown', 'software_version' => '',
            'os_name' => 'Unknown', 'os_version' => '',
            'device_model' => '', 'device_brand' => '', 'device_type' => 'unknown',
        ];
        if ($ua === '') { return $info; }
        $lower = strtolower($ua);

        // 识别代理客户端
        $info['software_name'] = self::matchSoftware($ua, $lower);

        // 识别操作系统
        [$info['os_name'], $info['os_version']] = self::parseOS($ua, $lower);
        if ($info['os_name'] === 'Unknown' && $info['software_name'] !== 'Unknown') {
            [$info['os_name'], $info['os_version']] = self::inferOS($info['software_name']);
        }

        // 识别设备
        $info['device_brand'] = self::parseBrand($ua, $lower, $info['os_name']);
        $info['software_version'] = self::parseVersion($ua);
        $info['device_type'] = self::determineType($lower, $info);

        return $info;
    }

    public static function generateFingerprint(string $ua): string
    {
        $info = self::parseUserAgent($ua);
        $features = [];
        if ($info['software_name'] !== 'Unknown') {
            $features[] = 'software:' . $info['software_name'];
            if ($info['software_version'] !== '') { $features[] = 'version:' . $info['software_version']; }
        }
        if ($info['os_name'] !== 'Unknown') {
            $features[] = 'os:' . $info['os_name'];
            if ($info['os_version'] !== '') { $features[] = 'os_version:' . $info['os_version']; }
        }
        if ($info['device_model'] !== '') { $features[] = 'model:' . $info['device_model']; }
        if ($info['device_brand'] !== '') { $features[] = 'brand:' . $info['device_brand']; }
        $str = implode('|', $features);
        return hash('sha256', $str !== '' ? $str : $ua);
    }

    public static function recordAccess(int $subId, int $userId, string $ua, string $ip, string $subType = ''): ?array
    {
        if (self::isBrowser($ua)) { return null; }
        $fp = self::generateFingerprint($ua);
        $info = self::parseUserAgent($ua);
        $now = date('Y-m-d H:i:s');

        $device = Db::table('devices')->where('subscription_id', $subId)->where('device_fingerprint', $fp)->find();
        if ($device) {
            Db::table('devices')->where('id', $device['id'])->update([
                'last_access' => $now, 'last_seen' => $now, 'ip_address' => $ip,
                'access_count' => (int) $device['access_count'] + 1, 'updated_at' => $now,
            ]);
            return $device;
        }

        $id = Db::table('devices')->insertGetId([
            'subscription_id' => $subId, 'user_id' => $userId,
            'device_fingerprint' => $fp, 'device_hash' => substr($fp, 0, 16),
            'device_ua' => mb_substr($ua, 0, 500), 'ip_address' => $ip,
            'software_name' => $info['software_name'], 'software_version' => $info['software_version'],
            'os_name' => $info['os_name'], 'os_version' => $info['os_version'],
            'device_brand' => $info['device_brand'], 'subscription_type' => $subType,
            'is_active' => 1, 'is_allowed' => 1,
            'first_seen' => $now, 'last_access' => $now, 'last_seen' => $now,
            'access_count' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        self::updateDeviceCount($subId);
        return Db::table('devices')->find($id);
    }

    public static function checkLimit(int $subId, string $ua): ?string
    {
        $sub = Subscription::find($subId);
        if (!$sub) { return '订阅不存在'; }
        $limit = (int) $sub->device_limit;
        if ($limit <= 0) { return '设备数量限制为0，无法使用服务'; }

        $active = (int) Db::table('devices')->where('subscription_id', $subId)->where('is_active', 1)->count();
        if ($active < $limit) { return null; }

        $fp = self::generateFingerprint($ua);
        $exists = Db::table('devices')->where('subscription_id', $subId)->where('device_fingerprint', $fp)->where('is_active', 1)->find();
        if (!$exists) { return "设备数量超过限制(当前{$active}/限制{$limit})，无法添加新设备"; }

        $allowed = Db::table('devices')->where('subscription_id', $subId)->where('is_active', 1)
            ->order('last_access', 'desc')->limit($limit)->column('device_fingerprint');
        if (!in_array($fp, $allowed, true)) {
            return "设备数量超过限制(当前{$active}/限制{$limit})，此设备不在允许范围内";
        }
        return null;
    }

    public static function getDevices(int $subId): array
    {
        return Db::table('devices')->where('subscription_id', $subId)->where('is_active', 1)
            ->order('last_access', 'desc')->select()->toArray();
    }

    public static function removeDevice(int $deviceId): void
    {
        $d = Db::table('devices')->find($deviceId);
        if ($d) {
            Db::table('devices')->where('id', $deviceId)->update(['is_active' => 0, 'updated_at' => date('Y-m-d H:i:s')]);
            self::updateDeviceCount((int) $d['subscription_id']);
        }
    }

    public static function clearDevices(int $subId): int
    {
        $count = Db::table('devices')->where('subscription_id', $subId)->where('is_active', 1)
            ->update(['is_active' => 0, 'updated_at' => date('Y-m-d H:i:s')]);
        self::updateDeviceCount($subId);
        return $count;
    }

    private static function updateDeviceCount(int $subId): void
    {
        $count = (int) Db::table('devices')->where('subscription_id', $subId)->where('is_active', 1)->count();
        Subscription::where('id', $subId)->update(['used_devices' => $count]);
    }

    // ==================== Private: UA 解析 ====================

    private static function matchSoftware(string $ua, string $lower): string
    {
        if (str_contains($lower, 'shadowrocket')) return 'Shadowrocket';
        if (str_contains($lower, 'quantumult%20x') || str_contains($lower, 'quantumultx')) return 'Quantumult X';
        if (str_contains($lower, 'quantumult')) return 'Quantumult';
        if (str_contains($lower, 'surge')) return 'Surge';
        if (str_contains($lower, 'loon')) return 'Loon';
        if (str_contains($lower, 'stash')) return 'Stash';
        if (str_contains($lower, 'v2rayn')) return 'v2rayN';
        if (str_contains($lower, 'v2rayng')) return 'v2rayNG';
        if (str_contains($lower, 'clash for windows') || str_contains($lower, 'clash-windows')) return 'Clash for Windows';
        if (str_contains($lower, 'clash verge') || str_contains($lower, 'clash-verge')) return 'Clash Verge';
        if (str_contains($lower, 'mihomo.party') || str_contains($lower, 'mihomo/')) return 'Mihomo Party';
        if (str_contains($lower, 'clashx pro')) return 'ClashX Pro';
        if (str_contains($lower, 'clashx')) return 'ClashX';
        if (str_contains($lower, 'clash meta') || str_contains($lower, 'clashmeta')) return 'Clash Meta';
        if (str_contains($lower, 'clash')) return 'Clash';
        if (str_contains($lower, 'hiddify')) return 'Hiddify';
        if (str_contains($lower, 'sing-box') || str_contains($lower, 'singbox')) return 'sing-box';
        if (str_contains($lower, 'karing')) return 'Karing';
        if (str_contains($lower, 'nekobox') || str_contains($lower, 'nekoray')) return 'NekoBox';
        if (str_contains($lower, 'surfboard')) return 'Surfboard';
        if (str_contains($lower, 'pharos')) return 'Pharos';
        // iOS 设备特征
        if (preg_match('/iPhone\d+,\d+/', $ua) && (str_contains($lower, 'cfnetwork') || str_contains($lower, 'darwin'))) {
            return 'Shadowrocket';
        }
        return 'Unknown';
    }

    private static function parseOS(string $ua, string $lower): array
    {
        if (preg_match('/iPhone OS (\d+[_.\d]*)/i', $ua, $m)) return ['iOS', str_replace('_', '.', $m[1])];
        if (preg_match('/iPad.*OS (\d+[_.\d]*)/i', $ua, $m)) return ['iPadOS', str_replace('_', '.', $m[1])];
        if (preg_match('/Android[\/\s]?(\d+[\d.]*)/i', $ua, $m)) return ['Android', $m[1]];
        if (preg_match('/Windows NT (\d+\.\d+)/i', $ua, $m)) {
            $ver = match ($m[1]) { '10.0' => '10/11', '6.3' => '8.1', '6.2' => '8', '6.1' => '7', default => $m[1] };
            return ['Windows', $ver];
        }
        if (preg_match('/Mac OS X (\d+[_.\d]*)/i', $ua, $m)) return ['macOS', str_replace('_', '.', $m[1])];
        if (str_contains($lower, 'linux')) return ['Linux', ''];
        if (str_contains($lower, 'darwin')) return ['macOS', ''];
        return ['Unknown', ''];
    }

    private static function inferOS(string $software): array
    {
        return match ($software) {
            'Shadowrocket', 'Quantumult', 'Quantumult X', 'Surge', 'Loon', 'Stash' => ['iOS', ''],
            'v2rayN', 'Clash for Windows', 'Clash Verge' => ['Windows', ''],
            'v2rayNG' => ['Android', ''],
            'ClashX', 'ClashX Pro' => ['macOS', ''],
            default => ['Unknown', ''],
        };
    }

    private static function parseBrand(string $ua, string $lower, string $os): string
    {
        if ($os === 'iOS' || $os === 'iPadOS') return 'Apple';
        if ($os === 'macOS') return 'Apple';
        if (str_contains($lower, 'huawei') || str_contains($lower, 'honor')) return 'Huawei';
        if (str_contains($lower, 'xiaomi') || str_contains($lower, 'redmi')) return 'Xiaomi';
        if (str_contains($lower, 'samsung')) return 'Samsung';
        if (str_contains($lower, 'oppo')) return 'OPPO';
        if (str_contains($lower, 'vivo')) return 'vivo';
        if (str_contains($lower, 'oneplus')) return 'OnePlus';
        if (str_contains($lower, 'pixel')) return 'Google';
        return '';
    }

    private static function parseVersion(string $ua): string
    {
        if (preg_match('/\/(\d+[\d.]*\d)/', $ua, $m)) return $m[1];
        if (preg_match('/\s(\d+\.\d+[\d.]*)/', $ua, $m)) return $m[1];
        return '';
    }

    private static function determineType(string $lower, array $info): string
    {
        if (in_array($info['os_name'], ['iOS', 'iPadOS', 'Android'])) return 'mobile';
        if (in_array($info['os_name'], ['Windows', 'macOS', 'Linux'])) return 'desktop';
        if (str_contains($lower, 'openwrt') || str_contains($lower, 'routeros') || str_contains($lower, 'padavan')) return 'router';
        return 'unknown';
    }

    private static function isBrowser(string $ua): bool
    {
        if ($ua === '') return true;
        $lower = strtolower($ua);
        $browsers = ['mozilla', 'chrome', 'safari', 'firefox', 'edge', 'opera', 'msie', 'webkit'];
        $proxies = ['shadowrocket', 'quantumult', 'surge', 'loon', 'stash', 'v2rayn', 'v2rayng',
            'clash', 'hiddify', 'sing-box', 'singbox', 'mihomo', 'karing', 'nekobox', 'surfboard', 'pharos'];

        foreach ($proxies as $p) { if (str_contains($lower, $p)) return false; }
        foreach ($browsers as $b) { if (str_contains($lower, $b)) return true; }
        return false;
    }
}