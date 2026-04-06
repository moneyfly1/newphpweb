<?php
declare (strict_types = 1);

namespace app\service;

/**
 * 节点解析服务 - 支持多种代理协议解析
 * 支持: VMess, VLESS, SS, SSR, Trojan, Hysteria, TUIC 等
 */
class NodeParserService
{
    /**
     * 支持的协议前缀
     */
    private const PROTOCOLS = [
        'vmess://',
        'vless://',
        'ss://',
        'ssr://',
        'trojan://',
        'hysteria://',
        'tuic://',
        'socks5://',
    ];

    /**
     * 解析缓存
     */
    private array $cache = [];
    private const CACHE_TTL = 300; // 5分钟

    /**
     * 解析节点链接
     */
    public function parseLink(string $link): ?array
    {
        $link = trim($link);

        if (empty($link)) {
            return null;
        }

        // 检查缓存
        if (isset($this->cache[$link])) {
            $cached = $this->cache[$link];
            if ($cached['expires'] > time()) {
                return $cached['data'];
            } else {
                unset($this->cache[$link]);
            }
        }

        $protocol = $this->resolveProtocol($link);

        $node = match ($protocol) {
            'vmess' => $this->parseVMess($link),
            'vless' => $this->parseVLESS($link),
            'ss' => $this->parseSS($link),
            'ssr' => $this->parseSSR($link),
            'trojan' => $this->parseTrojan($link),
            'hysteria' => $this->parseHysteria($link),
            'tuic' => $this->parseTUIC($link),
            'socks5' => $this->parseSocks($link),
            default => null,
        };

        // 缓存结果
        if ($node) {
            $this->cache[$link] = [
                'data'    => $node,
                'expires' => time() + self::CACHE_TTL,
            ];
        }

        return $node;
    }

    /**
     * 批量解析链接（使用 worker pool 思想）
     */
    public function parseLinks(array $links, int $workers = 5): array
    {
        $results = [];
        $chunkSize = ceil(count($links) / $workers);

        foreach (array_chunk($links, $chunkSize) as $chunk) {
            foreach ($chunk as $link) {
                $node = $this->parseLink($link);
                $error = null;
                if ($node === null) {
                    $error = '解析失败';
                } elseif (!$this->validateNode($node)) {
                    $error = '节点验证失败: 缺少必填字段或格式不正确';
                    $node = null;
                }
                $results[] = [
                    'link'  => $link,
                    'node'  => $node,
                    'error' => $error,
                ];
            }
        }

        return $results;
    }

    private function validateNode(?array $node): bool
    {
        if ($node === null) { return false; }
        $server = trim((string) ($node['server'] ?? $node['host'] ?? ''));
        $port = (int) ($node['port'] ?? 0);
        $protocol = trim((string) ($node['protocol'] ?? ''));

        // Required fields
        if ($server === '' || $port <= 0 || $port > 65535 || $protocol === '') {
            return false;
        }
        // Server format: must not contain spaces or special chars
        if (preg_match('/[\s<>]/', $server)) {
            return false;
        }
        return true;
    }

    /**
     * 确定协议类型
     */
    private function resolveProtocol(string $link): ?string
    {
        foreach (self::PROTOCOLS as $protocol) {
            if (str_starts_with(strtolower($link), $protocol)) {
                return rtrim($protocol, '://');
            }
        }
        return null;
    }

    /**
     * ==========================================
     * VMess 协议解析
     * ==========================================
     */
    private function parseVMess(string $link): ?array
    {
        $encoded = str_replace('vmess://', '', $link);
        $decoded = base64_decode($encoded, true);

        if (!$decoded) {
            return null;
        }

        $data = json_decode($decoded, true);
        if (!$data || !isset($data['add'], $data['port'], $data['id'])) {
            return null;
        }

        return [
            'protocol' => 'vmess',
            'name'     => $data['ps'] ?? 'VMess-' . $data['add'],
            'server'   => $data['add'],
            'port'     => (int) $data['port'],
            'uuid'     => $data['id'],
            'alter_id' => (int) ($data['aid'] ?? 0),
            'cipher'   => $data['scu'] ?? $data['scy'] ?? 'auto',
            'network'  => $data['net'] ?? 'tcp',
            'host'     => $data['host'] ?? $data['add'],
            'path'     => $data['path'] ?? '/',
            'tls'      => ($data['tls'] ?? '') === 'tls',
            'sni'      => $data['sni'] ?? ($data['host'] ?? ''),
            'settings_json' => [
                'type' => $data['type'] ?? 'none',
                'security' => $data['scy'] ?? 'auto',
            ],
        ];
    }

    /**
     * ==========================================
     * VLESS 协议解析  
     * ==========================================
     */
    private function parseVLESS(string $link): ?array
    {
        $url = parse_url($link);
        if (!$url || !isset($url['user'], $url['host'], $url['port'])) {
            return null;
        }

        $query = [];
        if (isset($url['query'])) {
            parse_str($url['query'], $query);
        }

        $name = isset($url['fragment']) ? urldecode($url['fragment']) : 'VLESS';

        return [
            'protocol' => 'vless',
            'name'     => $name,
            'server'   => $url['host'],
            'port'     => (int) $url['port'],
            'uuid'     => $url['user'],
            'network'  => $query['type'] ?? 'tcp',
            'tls'      => ($query['security'] ?? '') === 'tls',
            'sni'      => $query['sni'] ?? $url['host'],
            'settings_json' => [
                'security'   => $query['security'] ?? 'none',
                'encryption' => $query['encryption'] ?? 'none',
                'flow'       => $query['flow'] ?? '',
            ],
        ];
    }

    /**
     * ==========================================
     * Shadowsocks 协议解析
     * ==========================================
     */
    private function parseSS(string $link): ?array
    {
        $encoded = str_replace('ss://', '', $link);
        
        // 分离 name
        $parts = explode('#', $encoded, 2);
        $name = isset($parts[1]) ? urldecode($parts[1]) : 'SS';
        $encoded = $parts[0];

        // 解码
        $decoded = base64_decode($encoded, true);
        if (!$decoded) {
            return null;
        }

        // 方法:密码@服务器:端口格式
        if (str_contains($decoded, '@')) {
            [$auth, $server] = explode('@', $decoded, 2);
            [$method, $password] = explode(':', $auth, 2);
            [$host, $port] = explode(':', $server, 2);

            return [
                'protocol' => 'ss',
                'name'     => $name,
                'server'   => $host,
                'port'     => (int) $port,
                'method'   => $method,
                'password' => $password,
            ];
        }

        return null;
    }

    /**
     * ==========================================
     * ShadowsocksR 协议解析
     * ==========================================
     */
    private function parseSSR(string $link): ?array
    {
        $encoded = str_replace('ssr://', '', $link);
        
        // 分离参数
        if (str_contains($encoded, '/?')) {
            [$encoded, $paramStr] = explode('/?', $encoded, 2);
            parse_str($paramStr, $params);
        } else {
            $params = [];
        }

        $decoded = base64_decode($encoded, true);
        if (!$decoded) {
            return null;
        }

        // 格式: 服务器:端口:协议:加密方式:混淆:密码Base64
        $parts = explode(':', $decoded);
        if (count($parts) < 6) {
            return null;
        }

        $name = isset($params['remarks']) ? base64_decode($params['remarks'], true) : 'SSR';
        $password = base64_decode($parts[5], true);

        return [
            'protocol'    => 'ssr',
            'name'        => $name ?: 'SSR',
            'server'      => $parts[0],
            'port'        => (int) $parts[1],
            'method'      => $parts[3],
            'password'    => $password ?: '',
            'obfs'        => $parts[4],
            'settings_json' => [
                'protocol'     => $parts[2],
                'obfs_param'   => $params['obfs_param'] ?? '',
                'protocol_param' => $params['protocol_param'] ?? '',
            ],
        ];
    }

    /**
     * ==========================================
     * Trojan 协议解析
     * ==========================================
     */
    private function parseTrojan(string $link): ?array
    {
        $url = parse_url($link);
        if (!$url || !isset($url['user'], $url['host'], $url['port'])) {
            return null;
        }

        $query = [];
        if (isset($url['query'])) {
            parse_str($url['query'], $query);
        }

        $name = isset($url['fragment']) ? urldecode($url['fragment']) : 'Trojan';

        return [
            'protocol' => 'trojan',
            'name'     => $name,
            'server'   => $url['host'],
            'port'     => (int) $url['port'],
            'password' => $url['user'],
            'tls'      => true,
            'sni'      => $query['sni'] ?? $url['host'],
            'settings_json' => [
                'skip-cert-verify' => ($query['allowInsecure'] ?? '0') === '1',
            ],
        ];
    }

    /**
     * ==========================================
     * Hysteria 协议解析
     * ==========================================
     */
    private function parseHysteria(string $link): ?array
    {
        $url = parse_url($link);
        if (!$url || !isset($url['host'], $url['port'])) {
            return null;
        }

        $query = [];
        if (isset($url['query'])) {
            parse_str($url['query'], $query);
        }

        $name = isset($url['fragment']) ? urldecode($url['fragment']) : 'Hysteria';

        return [
            'protocol' => 'hysteria',
            'name'     => $name,
            'server'   => $url['host'],
            'port'     => (int) $url['port'],
            'password' => $url['user'] ?? $query['auth'] ?? '',
            'settings_json' => [
                'up'       => $query['up'] ?? '100 Mbps',
                'down'     => $query['down'] ?? '100 Mbps',
                'obfs'     => $query['obfs'] ?? '',
                'protocol' => $query['protocol'] ?? 'udp',
            ],
        ];
    }

    /**
     * ==========================================
     * TUIC 协议解析
     * ==========================================
     */
    private function parseTUIC(string $link): ?array
    {
        $url = parse_url($link);
        if (!$url || !isset($url['user'], $url['host'], $url['port'])) {
            return null;
        }

        $name = isset($url['fragment']) ? urldecode($url['fragment']) : 'TUIC';

        return [
            'protocol' => 'tuic',
            'name'     => $name,
            'server'   => $url['host'],
            'port'     => (int) $url['port'],
            'uuid'     => $url['user'],
            'password' => $url['pass'] ?? '',
            'settings_json' => [
                'congestion_control' => 'default',
                'udp_relay_mode'     => 'native',
            ],
        ];
    }

    /**
     * ==========================================
     * SOCKS5 协议解析
     * ==========================================
     */
    private function parseSocks(string $link): ?array
    {
        $url = parse_url($link);
        if (!$url || !isset($url['host'], $url['port'])) {
            return null;
        }

        $name = isset($url['fragment']) ? urldecode($url['fragment']) : 'SOCKS5';

        return [
            'protocol' => 'socks5',
            'name'     => $name,
            'server'   => $url['host'],
            'port'     => (int) $url['port'],
            'password' => $url['pass'] ?? '',
            'settings_json' => [
                'auth' => !empty($url['user']),
            ],
        ];
    }

    /**
     * 清空缓存
     */
    public function clearCache(): void
    {
        $this->cache = [];
    }

    /**
     * 获取缓存统计
     */
    public function getCacheStats(): array
    {
        return [
            'cached_links' => count($this->cache),
        ];
    }
}
