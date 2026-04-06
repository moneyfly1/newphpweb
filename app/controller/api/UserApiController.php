<?php
declare (strict_types = 1);

namespace app\controller\api;

use app\BaseController;
use app\model\Subscription;
use app\model\User;
use app\service\SubscriptionFormatService;

/**
 * 用户 API - 用户端功能接口
 */
class UserApiController extends BaseController
{
    private SubscriptionFormatService $formatter;

    public function initialize()
    {
        parent::initialize();
        $this->formatter = app(SubscriptionFormatService::class);
    }

    public function getSubscription()
    {
        $user = $this->auth->user();
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return $this->jsonError('请先登录', 401);
        }

        $subscription = Subscription::where('user_id', $userId)
            ->where('status', 'active')
            ->order('id', 'desc')
            ->find();

        if (!$subscription) {
            return $this->jsonError('您还没有活跃的订阅', 404);
        }

        return $this->jsonSuccess('订阅信息已获取。', [
            'id' => $subscription->id,
            'token' => $subscription->sub_token,
            'url' => $subscription->sub_url,
            'expire_at' => $subscription->expire_at,
            'device_limit' => (int) $subscription->device_limit,
            'used_devices' => (int) $subscription->used_devices,
            'traffic_total_gb' => (int) $subscription->traffic_total_gb,
            'traffic_used_gb' => (int) $subscription->traffic_used_gb,
            'status' => (string) $subscription->status,
            'created_at' => (string) $subscription->created_at,
        ]);
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

    public function sendSubscriptionEmail()
    {
        $this->requireCsrf();

        return $this->jsonSuccess('订阅邮件已处理。', $this->panel->sendSubscriptionEmail());
    }

    public function getSubscriptionStats()
    {
        $user = $this->auth->user();
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return $this->jsonError('请先登录', 401);
        }

        $subscription = Subscription::where('user_id', $userId)->order('id', 'desc')->find();
        if (!$subscription) {
            return $this->jsonError('订阅不存在', 404);
        }

        $nodes = db('proxy_nodes')
            ->where('subscription_id', $subscription->id)
            ->field('protocol, COUNT(*) as count')
            ->group('protocol')
            ->select();

        $protocols = [];
        foreach ($nodes as $node) {
            $protocols[strtoupper($node['protocol'])] = $node['count'];
        }

        return $this->jsonSuccess('订阅统计已获取。', [
            'subscription_id' => $subscription->id,
            'token' => $subscription->sub_token,
            'total_nodes' => db('proxy_nodes')->where('subscription_id', $subscription->id)->count(),
            'active_nodes' => db('proxy_nodes')->where('subscription_id', $subscription->id)->where('is_active', 1)->count(),
            'protocols' => $protocols,
        ]);
    }

    public function getSubscriptionFormats()
    {
        $user = $this->auth->user();
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return $this->jsonError('请先登录', 401);
        }

        $subscription = Subscription::where('user_id', $userId)->order('id', 'desc')->find();
        if (!$subscription) {
            return $this->jsonError('订阅不存在', 404);
        }

        $baseUrl = rtrim((string) $this->settings->baseUrl(), '/');
        $token = $subscription->sub_token;
        $formats = [
            'clash' => ['name' => 'Clash', 'url' => "{$baseUrl}/api/subscription/download/{$token}/clash", 'description' => 'YAML 配置格式，支持 Clash、Clash Meta'],
            'base64' => ['name' => 'Base64', 'url' => "{$baseUrl}/api/subscription/download/{$token}/base64", 'description' => '标准 Base64 编码格式'],
            'shadowrocket' => ['name' => 'Shadowrocket', 'url' => "{$baseUrl}/api/subscription/download/{$token}/shadowrocket", 'description' => 'iOS Shadowrocket 应用专用'],
            'surge' => ['name' => 'Surge', 'url' => "{$baseUrl}/api/subscription/download/{$token}/surge", 'description' => 'macOS/iOS Surge 代理配置'],
            'quantumultx' => ['name' => 'Quantumult X', 'url' => "{$baseUrl}/api/subscription/download/{$token}/quantumultx", 'description' => 'iOS Quantumult X 应用专用'],
            'quantumult' => ['name' => 'Quantumult', 'url' => "{$baseUrl}/api/subscription/download/{$token}/quantumult", 'description' => '旧版 Quantumult 格式'],
            'loon' => ['name' => 'Loon', 'url' => "{$baseUrl}/api/subscription/download/{$token}/loon", 'description' => 'iOS Loon 应用专用'],
            'ssr' => ['name' => 'SSR', 'url' => "{$baseUrl}/api/subscription/download/{$token}/ssr", 'description' => 'ShadowsocksR 订阅格式'],
            'unicode' => ['name' => 'Unicode', 'url' => "{$baseUrl}/api/subscription/download/{$token}/unicode", 'description' => 'Unicode 转义格式'],
            'usable' => ['name' => 'Usable', 'url' => "{$baseUrl}/api/subscription/download/{$token}/usable", 'description' => '纯链接列表'],
            'v2rayn' => ['name' => 'V2RayN', 'url' => "{$baseUrl}/api/subscription/download/{$token}/v2rayn", 'description' => 'V2RayN 订阅格式'],
            'singbox' => ['name' => 'Sing-Box', 'url' => "{$baseUrl}/api/subscription/download/{$token}/singbox", 'description' => 'Sing-Box JSON 配置'],
            'hiddify' => ['name' => 'Hiddify', 'url' => "{$baseUrl}/api/subscription/download/{$token}/hiddify", 'description' => 'Hiddify 配置格式'],
        ];

        return $this->jsonSuccess('订阅格式已获取。', ['formats' => $formats]);
    }

    public function downloadSubscription(string $token, string $format = 'clash')
    {
        $validFormats = ['clash', 'base64', 'shadowrocket', 'surge', 'quantumultx', 'quantumult', 'loon', 'ssr', 'unicode', 'usable', 'v2rayn', 'singbox', 'hiddify'];
        if (!in_array($format, $validFormats, true)) {
            return $this->jsonError('不支持的格式', 422);
        }

        $subscription = Subscription::where('sub_token', $token)->find();
        if (!$subscription) {
            return $this->jsonError('订阅不存在或已过期', 404);
        }

        if ($subscription->expire_at && strtotime((string) $subscription->expire_at) < time()) {
            return $this->jsonError('订阅已过期', 403);
        }

        // 设备限制检查
        $ua = (string) $this->request->header('User-Agent', '');
        $ip = (string) $this->request->ip();
        $reject = \app\service\DeviceService::checkLimit((int) $subscription->id, $ua);
        if ($reject !== null) {
            return response($reject, 403, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        // 记录设备访问
        \app\service\DeviceService::recordAccess(
            (int) $subscription->id, (int) $subscription->user_id, $ua, $ip, $format
        );

        try {
            $content = $this->formatter->generateSubscriptionByToken($token, $format);
            $headers = $this->getContentTypeHeaders($format);

            // 添加订阅信息响应头（代理客户端标准）
            $headers = array_merge($headers, $this->getSubscriptionInfoHeaders($subscription));

            return response($content, 200, $headers);
        } catch (\Exception $e) {
            return $this->jsonError('生成订阅失败：' . $e->getMessage(), 500);
        }
    }

    private function getContentTypeHeaders(string $format): array
    {
        $headers = [
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ];

        switch ($format) {
            case 'clash':
                $headers['Content-Type'] = 'application/x-yaml; charset=utf-8';
                $headers['Content-Disposition'] = 'inline; filename="subscription.yaml"';
                break;
            case 'shadowrocket':
                $headers['Content-Type'] = 'application/json; charset=utf-8';
                $headers['Content-Disposition'] = 'inline; filename="subscription.json"';
                break;
            case 'surge':
                $headers['Content-Type'] = 'text/plain; charset=utf-8';
                $headers['Content-Disposition'] = 'inline; filename="subscription.conf"';
                break;
            case 'singbox':
                $headers['Content-Type'] = 'application/json; charset=utf-8';
                $headers['Content-Disposition'] = 'inline; filename="sing-box.json"';
                break;
            case 'hiddify':
                $headers['Content-Type'] = 'application/json; charset=utf-8';
                $headers['Content-Disposition'] = 'inline; filename="hiddify.json"';
                break;
            case 'v2rayn':
                $headers['Content-Type'] = 'text/plain; charset=utf-8';
                $headers['Content-Disposition'] = 'inline; filename="v2rayn.txt"';
                break;
            default:
                $headers['Content-Type'] = 'text/plain; charset=utf-8';
                $headers['Content-Disposition'] = 'inline; filename="subscription.txt"';
                break;
        }

        return $headers;
    }

    /**
     * 生成订阅信息响应头（代理客户端标准）
     * Subscription-Userinfo: upload=0; download=bytes; total=bytes; expire=timestamp
     */
    private function getSubscriptionInfoHeaders(Subscription $sub): array
    {
        $usedBytes = (int) $sub->traffic_used_gb * 1073741824;   // GB -> bytes
        $totalBytes = (int) $sub->traffic_total_gb * 1073741824;
        $expireTs = $sub->expire_at ? strtotime((string) $sub->expire_at) : 0;

        $appName = $this->settings->appName();
        $package = \app\model\Package::find((int) $sub->package_id);

        return [
            'Subscription-Userinfo' => "upload=0; download={$usedBytes}; total={$totalBytes}; expire={$expireTs}",
            'Profile-Title'         => $appName . ($package ? ' - ' . $package->name : ''),
            'Profile-Update-Interval' => '12',
            'Content-Disposition'   => 'inline',
        ];
    }
}
