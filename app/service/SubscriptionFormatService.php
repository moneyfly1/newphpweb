<?php
declare (strict_types = 1);

namespace app\service;

use app\model\SubscriptionFormatConfig;
use app\model\Subscription;
use app\model\ProxyNode;

/**
 * 订阅格式生成服务
 * 支持多种订阅格式的生成和转换
 */
class SubscriptionFormatService
{
    public const FORMAT_CLASH = 'clash';
    public const FORMAT_BASE64 = 'base64';
    public const FORMAT_SSR = 'ssr';
    public const FORMAT_SURGE = 'surge';
    public const FORMAT_QUANTUMULT = 'quantumult';
    public const FORMAT_QUANTUMULTX = 'quantumultx';
    public const FORMAT_LOON = 'loon';
    public const FORMAT_SHADOWROCKET = 'shadowrocket';
    public const FORMAT_UNICODE = 'unicode';
    public const FORMAT_USABLE = 'usable';
    public const FORMAT_V2RAYN = 'v2rayn';
    public const FORMAT_SINGBOX = 'singbox';
    public const FORMAT_HIDDIFY = 'hiddify';

    private array $formatRegistry = [];
    private AppConfigService $config;

    public function __construct()
    {
        $this->config = app(AppConfigService::class);
        $this->initializeFormatRegistry();
    }

    private function initializeFormatRegistry(): void
    {
        $configs = SubscriptionFormatConfig::getEnabledFormats();
        foreach ($configs as $config) {
            $this->formatRegistry[$config->format_code] = [
                'name'                => $config->format_name,
                'description'         => $config->description,
                'supported_protocols' => $config->supported_protocols ?? [],
                'features'            => $config->features ?? [],
            ];
        }
    }

    public function getAvailableFormats(): array
    {
        return $this->formatRegistry;
    }

    public function getFormatInfo(string $format): ?array
    {
        return $this->formatRegistry[$format] ?? null;
    }

    public function generateSubscription(string $format, $nodes, array $subscriptionInfo = []): string
    {
        if (!isset($this->formatRegistry[$format])) {
            throw new \RuntimeException("不支持的订阅格式: $format");
        }

        $nodeArray = $this->convertNodesToArray($nodes);
        $metadataNodes = $this->buildMetadataNodes($subscriptionInfo);
        $allNodes = array_merge($metadataNodes, $nodeArray);

        return match ($format) {
            self::FORMAT_CLASH => $this->generateClash($allNodes, $subscriptionInfo),
            self::FORMAT_BASE64 => $this->generateBase64($allNodes),
            self::FORMAT_SSR => $this->generateSSR($allNodes),
            self::FORMAT_SURGE => $this->generateSurge($allNodes, $subscriptionInfo),
            self::FORMAT_QUANTUMULT => $this->generateQuantumult($allNodes, $subscriptionInfo),
            self::FORMAT_QUANTUMULTX => $this->generateQuantumultX($allNodes, $subscriptionInfo),
            self::FORMAT_LOON => $this->generateLoon($allNodes, $subscriptionInfo),
            self::FORMAT_SHADOWROCKET => $this->generateShadowrocket($allNodes, $subscriptionInfo),
            self::FORMAT_UNICODE => $this->generateUnicode($allNodes),
            self::FORMAT_USABLE => $this->generateUsable($allNodes),
            self::FORMAT_V2RAYN => $this->generateV2rayn($allNodes),
            self::FORMAT_SINGBOX => $this->generateSingbox($allNodes, $subscriptionInfo),
            self::FORMAT_HIDDIFY => $this->generateHiddify($allNodes, $subscriptionInfo),
            default => throw new \RuntimeException("不支持的订阅格式: $format"),
        };
    }

    public function generateSubscriptionByToken(string $token, string $format): string
    {
        $subscription = Subscription::where('sub_token', $token)->find();
        if (!$subscription) {
            throw new \RuntimeException('订阅不存在');
        }

        if ($subscription->status !== 'active' || ($subscription->expire_at && strtotime($subscription->expire_at) < time())) {
            throw new \RuntimeException('订阅已过期或已禁用');
        }

        $nodes = ProxyNode::where('subscription_id', $subscription->id)
            ->where('is_active', 1)
            ->order('priority', 'desc')
            ->order('id', 'asc')
            ->select();

        $subscriptionInfo = [
            'expire_at'       => $subscription->expire_at,
            'device_limit'    => $subscription->device_limit,
            'device_used'     => $subscription->used_devices,
            'traffic_total_gb'=> $subscription->traffic_total_gb,
            'traffic_used_gb' => $subscription->traffic_used_gb,
        ];

        return $this->generateSubscription($format, $nodes, $subscriptionInfo);
    }

    private function convertNodesToArray($nodes): array
    {
        if (is_array($nodes)) {
            return $nodes;
        }

        $result = [];
        foreach ($nodes as $node) {
            if ($node instanceof ProxyNode) {
                $result[] = $node->toArray();
            } elseif (is_array($node)) {
                $result[] = $node;
            }
        }

        return $result;
    }

    private function buildMetadataNodes(array $subscriptionInfo): array
    {
        $siteUrl = $this->normalizeDisplayUrl((string) $this->config->get('site.subscription_site_url', $this->config->baseUrl()));
        $siteName = trim((string) $this->config->get('site.subscription_site_name', $this->config->appName()));
        $supportQq = trim((string) $this->config->get('site.support_qq', ''));
        $deviceText = ($subscriptionInfo['device_used'] ?? 0) . '/' . ($subscriptionInfo['device_limit'] ?? 0);
        $expireAt = !empty($subscriptionInfo['expire_at']) ? (string) $subscriptionInfo['expire_at'] : '未设置';

        return [
            $this->buildMetadataNode('网站地址：' . ($siteUrl ?: $siteName)),
            $this->buildMetadataNode('设备使用：' . $deviceText),
            $this->buildMetadataNode('到期时间：' . $expireAt),
            $this->buildMetadataNode('客服QQ：' . ($supportQq !== '' ? $supportQq : '未设置')),
        ];
    }

    private function buildMetadataNode(string $label): array
    {
        return [
            'protocol' => 'vless',
            'name' => $label,
            'server' => 'meta.cboard.local',
            'host' => 'meta.cboard.local',
            'port' => 443,
            'uuid' => '00000000-0000-4000-8000-000000000000',
            'tls' => true,
            'network' => 'tcp',
            'cipher' => 'auto',
        ];
    }

    private function normalizeDisplayUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        if (!$parts || empty($parts['host'])) {
            return $url;
        }

        $display = $parts['host'];
        if (!empty($parts['port'])) {
            $display .= ':' . $parts['port'];
        }
        if (!empty($parts['path']) && $parts['path'] !== '/') {
            $display .= $parts['path'];
        }

        return $display;
    }

    private function generateClash(array $nodes, array $subscriptionInfo = []): string
    {
        $proxies = [];
        $proxyNames = [];

        foreach ($nodes as $node) {
            $proxy = $this->nodeToClashProxy($node);
            if ($proxy) {
                $proxies[] = $proxy;
                $proxyNames[] = $node['name'] ?? 'Proxy-' . count($proxyNames);
            }
        }

        $yaml = "# Clash 配置文件\n";
        $yaml .= "# 生成时间: " . date('Y-m-d H:i:s') . "\n";
        $yaml .= "mixed-port: 7890\n";
        $yaml .= "allow-lan: true\n";
        $yaml .= "mode: rule\n";
        $yaml .= "log-level: info\n\n";
        $yaml .= "proxies:\n";
        foreach ($proxies as $proxy) {
            $yaml .= $proxy;
        }

        $safeNames = array_map(fn (string $name): string => "'" . str_replace("'", "\\'", $name) . "'", $proxyNames);
        $yaml .= "\nproxy-groups:\n";
        $yaml .= "  - name: '🔀 自动选择'\n";
        $yaml .= "    type: select\n";
        $yaml .= "    proxies:\n";
        foreach ($safeNames as $safeName) {
            $yaml .= "      - {$safeName}\n";
        }

        return $yaml;
    }

    private function nodeToClashProxy(array $node): string
    {
        $protocol = $node['protocol'] ?? 'ss';
        $proxyName = $node['name'] ?? 'Proxy';
        $proxyName = str_replace("'", "\\'", $proxyName);

        return match ($protocol) {
            'vmess' => $this->buildClashVMess($node, $proxyName),
            'vless' => $this->buildClashVLESS($node, $proxyName),
            'ss' => $this->buildClashSS($node, $proxyName),
            'ssr' => $this->buildClashSSR($node, $proxyName),
            'trojan' => $this->buildClashTrojan($node, $proxyName),
            'hysteria' => $this->buildClashHysteria($node, $proxyName),
            default => '',
        };
    }

    private function buildClashVMess(array $node, string $name): string
    {
        return "  - name: '$name'\n" .
               "    type: vmess\n" .
               "    server: " . ($node['server'] ?? $node['host']) . "\n" .
               "    port: {$node['port']}\n" .
               "    uuid: {$node['uuid']}\n" .
               "    alterId: " . ($node['alter_id'] ?? 0) . "\n" .
               "    cipher: " . ($node['cipher'] ?? 'auto') . "\n" .
               "    tls: " . (($node['tls'] ?? false) ? 'true' : 'false') . "\n";
    }

    private function buildClashVLESS(array $node, string $name): string
    {
        return "  - name: '$name'\n" .
               "    type: vless\n" .
               "    server: " . ($node['server'] ?? $node['host']) . "\n" .
               "    port: {$node['port']}\n" .
               "    uuid: {$node['uuid']}\n" .
               "    tls: " . (($node['tls'] ?? false) ? 'true' : 'false') . "\n";
    }

    private function buildClashSS(array $node, string $name): string
    {
        return "  - name: '$name'\n" .
               "    type: ss\n" .
               "    server: " . ($node['server'] ?? $node['host']) . "\n" .
               "    port: {$node['port']}\n" .
               "    cipher: {$node['method']}\n" .
               "    password: {$node['password']}\n";
    }

    private function buildClashSSR(array $node, string $name): string
    {
        return "  - name: '$name'\n" .
               "    type: ssr\n" .
               "    server: " . ($node['server'] ?? $node['host']) . "\n" .
               "    port: {$node['port']}\n" .
               "    cipher: {$node['method']}\n" .
               "    password: {$node['password']}\n" .
               "    obfs: " . ($node['obfs'] ?? 'plain') . "\n";
    }

    private function buildClashTrojan(array $node, string $name): string
    {
        return "  - name: '$name'\n" .
               "    type: trojan\n" .
               "    server: " . ($node['server'] ?? $node['host']) . "\n" .
               "    port: {$node['port']}\n" .
               "    password: {$node['password']}\n";
    }

    private function buildClashHysteria(array $node, string $name): string
    {
        return "  - name: '$name'\n" .
               "    type: hysteria\n" .
               "    server: " . ($node['server'] ?? $node['host']) . "\n" .
               "    port: {$node['port']}\n" .
               "    auth: {$node['password']}\n" .
               "    up: 100 mbps\n" .
               "    down: 100 mbps\n";
    }

    private function generateBase64(array $nodes): string
    {
        $links = [];
        foreach ($nodes as $node) {
            $link = $this->nodeToLink($node);
            if ($link) {
                $links[] = $link;
            }
        }

        return base64_encode(implode("\n", $links));
    }

    private function nodeToLink(array $node): string
    {
        $protocol = $node['protocol'] ?? 'ss';

        return match ($protocol) {
            'vmess' => $this->buildVMessLink($node),
            'vless' => $this->buildVLESSLink($node),
            'ss' => $this->buildSSLink($node),
            'ssr' => $this->buildSSRLink($node),
            'trojan' => $this->buildTrojanLink($node),
            default => $this->buildGenericLink($node),
        };
    }

    private function buildVMessLink(array $node): string
    {
        $data = [
            'v' => 2,
            'ps' => $node['name'] ?? 'Proxy',
            'add' => $node['server'] ?? $node['host'],
            'port' => $node['port'],
            'id' => $node['uuid'],
            'aid' => $node['alter_id'] ?? 0,
            'net' => $node['network'] ?? 'tcp',
            'type' => $node['type'] ?? 'none',
            'host' => $node['host_param'] ?? ($node['host'] ?? ''),
            'path' => $node['path'] ?? '',
            'tls' => !empty($node['tls']) ? 'tls' : '',
        ];

        return 'vmess://' . base64_encode(json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    private function buildVLESSLink(array $node): string
    {
        $params = [];
        if (!empty($node['encryption'])) $params['encryption'] = $node['encryption'];
        if (!empty($node['flow'])) $params['flow'] = $node['flow'];
        if ($node['tls'] ?? false) $params['security'] = 'tls';
        if (!empty($node['sni'])) $params['sni'] = $node['sni'];
        if (!empty($node['network']) && $node['network'] !== 'tcp') $params['type'] = $node['network'];
        if (!empty($node['path'])) $params['path'] = $node['path'];
        if (!empty($node['host']) && ($node['server'] ?? $node['host']) !== $node['host']) $params['host'] = $node['host'];

        $paramStr = !empty($params) ? '?' . http_build_query($params) : '';
        $userInfo = $node['uuid'] . '@' . ($node['server'] ?? $node['host']) . ':' . $node['port'];

        return 'vless://' . $userInfo . $paramStr . '#' . urlencode($node['name'] ?? 'VLESS');
    }

    private function buildSSLink(array $node): string
    {
        $userInfo = base64_encode(($node['method'] ?? '') . ':' . ($node['password'] ?? ''));
        return 'ss://' . $userInfo . '@' . ($node['server'] ?? $node['host']) . ':' . $node['port'] . '#' . urlencode($node['name'] ?? 'SS');
    }

    private function buildSSRLink(array $node): string
    {
        if (($node['protocol'] ?? '') !== 'ssr') {
            return '';
        }

        $settings = $node['settings_json'] ?? [];
        $protocol = $settings['protocol'] ?? 'origin';
        $queryStr = implode(':', [
            $node['server'] ?? $node['host'],
            $node['port'],
            $protocol,
            $node['method'] ?? '',
            $node['obfs'] ?? 'plain',
            base64_encode($node['password'] ?? ''),
        ]);

        return 'ssr://' . base64_encode($queryStr) . '/?' . http_build_query([
            'obfs' => $node['obfs'] ?? 'plain',
            'protocol' => $protocol,
            'remarks' => base64_encode($node['name'] ?? 'SSR'),
        ]);
    }

    private function buildTrojanLink(array $node): string
    {
        $params = [];
        if ($node['tls'] ?? false) $params['security'] = 'tls';
        if (!empty($node['sni'])) $params['sni'] = $node['sni'];

        $paramStr = !empty($params) ? '?' . http_build_query($params) : '';
        return 'trojan://' . ($node['password'] ?? '') . '@' . ($node['server'] ?? $node['host']) . ':' . $node['port'] . $paramStr . '#' . urlencode($node['name'] ?? 'Trojan');
    }

    private function buildGenericLink(array $node): string
    {
        return ($node['protocol'] ?? 'unknown') . '://' . base64_encode(json_encode($node, JSON_UNESCAPED_UNICODE)) . '#' . urlencode($node['name'] ?? 'Node');
    }

    private function generateSSR(array $nodes): string
    {
        $links = [];
        foreach ($nodes as $node) {
            $link = $this->nodeToLink($node);
            if (str_starts_with($link, 'ssr://')) {
                $links[] = $link;
            }
        }
        return base64_encode(implode("\n", $links));
    }

    private function generateSurge(array $nodes, array $subscriptionInfo = []): string
    {
        $surge = "[General]\n";
        $surge .= "bypass-system = true\n";
        $surge .= "dns-server = 8.8.8.8, 1.1.1.1\n\n";
        $surge .= "[Proxy]\n";
        foreach ($nodes as $node) {
            $proxyLine = $this->nodeToSurgeProxy($node);
            if ($proxyLine) $surge .= $proxyLine;
        }
        $surge .= "\n[Proxy Group]\n";
        $surge .= "♻️ Auto = select, " . $this->getSurgeProxyNames($nodes) . "\n";
        return $surge;
    }

    private function nodeToSurgeProxy(array $node): string
    {
        $protocol = $node['protocol'] ?? 'ss';
        $name = $node['name'] ?? 'Proxy';
        $server = $node['server'] ?? $node['host'];

        return match ($protocol) {
            'ss' => $name . " = ss, {$server}, {$node['port']}, encrypt-method=" . ($node['method'] ?? '') . ", password=" . ($node['password'] ?? '') . "\n",
            'trojan' => $name . " = trojan, {$server}, {$node['port']}, password=" . ($node['password'] ?? '') . "\n",
            'vmess' => $name . " = vmess, {$server}, {$node['port']}, username=" . ($node['uuid'] ?? '') . "\n",
            'vless' => $name . " = vmess, {$server}, {$node['port']}, username=" . ($node['uuid'] ?? '') . ", tls=true, sni=" . ($node['sni'] ?? $server) . "\n",
            default => '',
        };
    }

    private function getSurgeProxyNames(array $nodes): string
    {
        $names = [];
        foreach ($nodes as $node) {
            $names[] = $node['name'] ?? 'Proxy';
        }
        return implode(', ', array_slice($names, 0, 12));
    }

    private function generateQuantumult(array $nodes, array $subscriptionInfo = []): string
    {
        $content = '';
        foreach ($nodes as $node) {
            $link = $this->nodeToQuantumultLink($node);
            if ($link) $content .= $link . "\n";
        }
        return $content;
    }

    private function nodeToQuantumultLink(array $node): string
    {
        $protocol = $node['protocol'] ?? 'ss';
        $name = urlencode($node['name'] ?? 'Proxy');
        $server = $node['server'] ?? $node['host'];

        return match ($protocol) {
            'ss' => "ss={$server}:{$node['port']}, method=" . ($node['method'] ?? '') . ", password=" . ($node['password'] ?? '') . ", obfs=http, fast-open=false, tag=$name",
            'trojan' => "trojan={$server}:{$node['port']}, password=" . ($node['password'] ?? '') . ", obfs=http, fast-open=false, tag=$name",
            'vmess' => "vmess={$server}:{$node['port']}, method=chacha20, password=" . ($node['uuid'] ?? '') . ", obfs=http, fast-open=false, tag=$name",
            'vless' => "vmess={$server}:{$node['port']}, method=none, password=" . ($node['uuid'] ?? '') . ", obfs=http, fast-open=false, tag=$name",
            default => '',
        };
    }

    private function generateQuantumultX(array $nodes, array $subscriptionInfo = []): string
    {
        $content = '';
        foreach ($nodes as $node) {
            $link = $this->nodeToQuantumultXLink($node);
            if ($link) $content .= $link . "\n";
        }
        return $content;
    }

    private function nodeToQuantumultXLink(array $node): string
    {
        $protocol = $node['protocol'] ?? 'ss';
        $name = urlencode($node['name'] ?? 'Proxy');
        $server = $node['server'] ?? $node['host'];

        return match ($protocol) {
            'ss' => "ss={$server}:{$node['port']}, method=" . ($node['method'] ?? '') . ", password=" . ($node['password'] ?? '') . ", tag=$name",
            'vless' => "vless=" . ($node['uuid'] ?? '') . "@{$server}:{$node['port']}, tag=$name",
            'trojan' => "trojan=" . ($node['password'] ?? '') . "@{$server}:{$node['port']}, tag=$name",
            'vmess' => "vmess={$server}:{$node['port']}, method=chacha20, password=" . ($node['uuid'] ?? '') . ", tag=$name",
            default => '',
        };
    }

    private function generateLoon(array $nodes, array $subscriptionInfo = []): string
    {
        $loon = "[Proxy]\n";
        foreach ($nodes as $node) {
            if ($proxyLine = $this->nodeToLoonProxy($node)) {
                $loon .= $proxyLine;
            }
        }
        return $loon;
    }

    private function nodeToLoonProxy(array $node): string
    {
        $protocol = $node['protocol'] ?? 'ss';
        $name = $node['name'] ?? 'Proxy';
        $server = $node['server'] ?? $node['host'];

        return match ($protocol) {
            'ss' => "$name = Shadowsocks, {$server}, {$node['port']}, " . ($node['method'] ?? '') . ", " . ($node['password'] ?? '') . "\n",
            'trojan' => "$name = Trojan, {$server}, {$node['port']}, password=" . ($node['password'] ?? '') . "\n",
            'vmess' => "$name = VMess, {$server}, {$node['port']}, " . ($node['uuid'] ?? '') . "\n",
            'vless' => "$name = VMess, {$server}, {$node['port']}, " . ($node['uuid'] ?? '') . "\n",
            default => '',
        };
    }

    private function generateShadowrocket(array $nodes, array $subscriptionInfo = []): string
    {
        $proxies = [];
        foreach ($nodes as $node) {
            $proxies[] = $this->nodeToShadowrocketProxy($node);
        }

        return json_encode([
            'version' => 1,
            'proxies' => $proxies,
            'groups' => [
                ['name' => '🔀 自动选择', 'proxies' => array_column($proxies, 'remarks')],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function nodeToShadowrocketProxy(array $node): array
    {
        $base = [
            'remarks' => $node['name'] ?? 'Proxy',
            'server' => $node['server'] ?? $node['host'],
            'port' => $node['port'],
        ];

        $protocol = $node['protocol'] ?? 'ss';

        return match ($protocol) {
            'ss' => array_merge($base, ['method' => 'ss', 'password' => $node['password'] ?? '', 'id' => $node['method'] ?? '']),
            'vmess' => array_merge($base, ['method' => 'vmess', 'password' => $node['uuid'] ?? '']),
            'trojan' => array_merge($base, ['method' => 'trojan', 'password' => $node['password'] ?? '']),
            'vless' => array_merge($base, ['method' => 'vless', 'password' => $node['uuid'] ?? '']),
            default => $base,
        };
    }

    private function generateUnicode(array $nodes): string
    {
        $links = [];
        foreach ($nodes as $node) {
            $link = $this->nodeToLink($node);
            if ($link) {
                $links[] = $this->toUnicodeEscape($link);
            }
        }
        return implode("\n", $links);
    }

    private function toUnicodeEscape(string $str): string
    {
        $result = '';
        for ($i = 0; $i < strlen($str); $i++) {
            $char = $str[$i];
            if (ord($char) > 127) {
                $result .= '\\u' . dechex(ord($char));
            } else {
                $result .= $char;
            }
        }
        return $result;
    }

    private function generateUsable(array $nodes): string
    {
        $links = [];
        foreach ($nodes as $node) {
            $link = $this->nodeToLink($node);
            if ($link) {
                $links[] = $link;
            }
        }
        return implode("\n", $links);
    }

    private function generateV2rayn(array $nodes): string
    {
        $links = [];
        foreach ($nodes as $node) {
            $link = $this->nodeToLink($node);
            if ($link) { $links[] = $link; }
        }
        return base64_encode(implode("\n", $links));
    }

    private function generateSingbox(array $nodes, array $subscriptionInfo = []): string
    {
        $outbounds = [];
        $proxyTags = [];

        foreach ($nodes as $node) {
            $protocol = $node['protocol'] ?? '';
            $server = $node['server'] ?? $node['host'] ?? '';
            $port = (int) ($node['port'] ?? 0);
            $name = $node['name'] ?? 'Proxy';
            if ($server === '' || $port === 0) { continue; }

            $out = match ($protocol) {
                'vmess' => [
                    'type' => 'vmess', 'tag' => $name, 'server' => $server, 'server_port' => $port,
                    'uuid' => $node['uuid'] ?? '', 'alter_id' => (int) ($node['alter_id'] ?? 0),
                    'security' => $node['method'] ?? 'auto',
                ],
                'vless' => [
                    'type' => 'vless', 'tag' => $name, 'server' => $server, 'server_port' => $port,
                    'uuid' => $node['uuid'] ?? '', 'flow' => $node['flow'] ?? '',
                ],
                'ss' => [
                    'type' => 'shadowsocks', 'tag' => $name, 'server' => $server, 'server_port' => $port,
                    'method' => $node['method'] ?? 'aes-256-gcm', 'password' => $node['password'] ?? '',
                ],
                'trojan' => [
                    'type' => 'trojan', 'tag' => $name, 'server' => $server, 'server_port' => $port,
                    'password' => $node['password'] ?? '',
                ],
                'hysteria2', 'hysteria' => [
                    'type' => 'hysteria2', 'tag' => $name, 'server' => $server, 'server_port' => $port,
                    'password' => $node['password'] ?? '',
                ],
                'tuic' => [
                    'type' => 'tuic', 'tag' => $name, 'server' => $server, 'server_port' => $port,
                    'uuid' => $node['uuid'] ?? '', 'password' => $node['password'] ?? '',
                    'congestion_control' => $node['congestion_control'] ?? 'bbr',
                ],
                default => null,
            };

            if (!$out) { continue; }

            // TLS
            if (!empty($node['tls']) && $node['tls'] !== '0' && $node['tls'] !== 'none') {
                $out['tls'] = ['enabled' => true, 'server_name' => $node['sni'] ?? $server, 'insecure' => false];
            }

            // Transport
            $network = $node['network'] ?? '';
            if ($network === 'ws') {
                $out['transport'] = ['type' => 'ws', 'path' => $node['path'] ?? '/', 'headers' => ['Host' => $node['host'] ?? $server]];
            } elseif ($network === 'grpc') {
                $out['transport'] = ['type' => 'grpc', 'service_name' => $node['path'] ?? ''];
            }

            $outbounds[] = $out;
            $proxyTags[] = $name;
        }

        // Add selector and direct outbounds
        $config = [
            'log' => ['level' => 'warn'],
            'dns' => ['servers' => [['tag' => 'dns-remote', 'address' => 'https://1.1.1.1/dns-query'], ['tag' => 'dns-local', 'address' => 'local']]],
            'outbounds' => array_merge(
                [['type' => 'selector', 'tag' => 'proxy', 'outbounds' => array_merge(['auto'], $proxyTags)],
                 ['type' => 'urltest', 'tag' => 'auto', 'outbounds' => $proxyTags, 'interval' => '5m'],
                 ['type' => 'direct', 'tag' => 'direct'],
                 ['type' => 'block', 'tag' => 'block'],
                 ['type' => 'dns', 'tag' => 'dns-out']],
                $outbounds
            ),
            'route' => ['rules' => [['protocol' => 'dns', 'outbound' => 'dns-out']], 'final' => 'proxy'],
        ];

        return json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function generateHiddify(array $nodes, array $subscriptionInfo = []): string
    {
        // Hiddify uses sing-box compatible format with additional metadata
        $singboxConfig = json_decode($this->generateSingbox($nodes, $subscriptionInfo), true);

        // Add Hiddify-specific metadata
        $singboxConfig['experimental'] = [
            'clash_api' => ['external_controller' => '127.0.0.1:9090'],
        ];

        $siteName = $subscriptionInfo['site_name'] ?? app(AppConfigService::class)->appName();
        $singboxConfig['_hiddify'] = [
            'name' => $siteName,
            'version' => '1.0',
        ];

        return json_encode($singboxConfig, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
