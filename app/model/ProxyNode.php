<?php
declare (strict_types = 1);

namespace app\model;

class ProxyNode extends BaseModel
{
    protected $name = 'proxy_nodes';

    protected $type = [
        'subscription_id'    => 'integer',
        'port'               => 'integer',
        'alter_id'           => 'integer',
        'tls'                => 'boolean',
        'priority'           => 'integer',
        'is_active'          => 'boolean',
        'latency_ms'         => 'integer',
        'settings_json'      => 'json',
        'last_check_at'      => 'datetime',
    ];

    protected $json = ['settings_json'];

    /**
     * 关联订阅
     */
    public function subscription()
    {
        return $this->belongsTo(Subscription::class, 'subscription_id', 'id');
    }

    /**
     * 批量创建节点
     */
    public static function createNodes(array $nodes, ?int $subscriptionId = null): int
    {
        $count = 0;
        foreach ($nodes as $node) {
            $node['subscription_id'] = $subscriptionId;
            self::create($node);
            $count++;
        }
        return $count;
    }

    /**
     * 获取活跃节点
     */
    public static function getActiveNodes(?int $subscriptionId = null)
    {
        $query = self::where('is_active', 1);
        if ($subscriptionId) {
            $query->where('subscription_id', $subscriptionId);
        }
        return $query->select();
    }

    /**
     * 按协议分组
     */
    public static function groupByProtocol()
    {
        return self::field('protocol, COUNT(*) as count')
            ->where('is_active', 1)
            ->group('protocol')
            ->select();
    }

    /**
     * 查找重复节点（同一订阅，相同服务器+端口+协议）
     */
    public static function findDuplicates(int $subscriptionId, string $protocol, string $server, int $port)
    {
        return self::where('subscription_id', $subscriptionId)
            ->where('protocol', $protocol)
            ->where('server', $server)
            ->where('port', $port)
            ->select();
    }

    /**
     * 删除过期节点（按最后检查时间）
     */
    public static function deleteStaleNodes(int $days = 30): int
    {
        $date = date('Y-m-d H:i:s', time() - $days * 86400);
        return self::where('last_check_at', '<', $date)->delete();
    }

    /**
     * 转换为链接格式（用于订阅生成）
     */
    public function toLink(): string
    {
        return match ($this->protocol) {
            'vmess' => $this->toVMessLink(),
            'vless' => $this->toVLESSLink(),
            'ss' => $this->toSSLink(),
            'ssr' => $this->toSSRLink(),
            'trojan' => $this->toTrojanLink(),
            default => '',
        };
    }

    private function toVMessLink(): string
    {
        $data = [
            'v' => 2,
            'ps' => $this->name,
            'add' => $this->server,
            'port' => $this->port,
            'id' => $this->uuid,
            'aid' => $this->alter_id ?? 0,
            'net' => $this->network ?? 'tcp',
            'type' => $this->settings_json['type'] ?? 'none',
            'host' => $this->host ?? '',
            'path' => $this->path ?? '',
            'tls' => $this->tls ? 'tls' : '',
            'sni' => $this->sni ?? '',
        ];
        return 'vmess://' . base64_encode(json_encode($data));
    }

    private function toVLESSLink(): string
    {
        $params = [];
        if ($this->tls) $params['security'] = 'tls';
        if ($this->sni) $params['sni'] = $this->sni;
        if ($this->network) $params['type'] = $this->network;
        
        $paramStr = !empty($params) ? '?' . http_build_query($params) : '';
        $userInfo = $this->uuid . '@' . $this->server . ':' . $this->port;
        return 'vless://' . $userInfo . $paramStr . '#' . urlencode($this->name);
    }

    private function toSSLink(): string
    {
        $userInfo = base64_encode($this->method . ':' . $this->password);
        return 'ss://' . $userInfo . '@' . $this->server . ':' . $this->port . '#' . urlencode($this->name);
    }

    private function toSSRLink(): string
    {
        $params = [
            'obfs' => $this->obfs ?? 'plain',
            'protocol' => $this->settings_json['protocol'] ?? 'origin',
            'remarks' => base64_encode($this->name),
        ];

        $queryStr = implode(':', [
            $this->server,
            $this->port,
            $this->settings_json['protocol'] ?? 'origin',
            $this->method,
            $this->obfs ?? 'plain',
            base64_encode($this->password),
        ]);

        return 'ssr://' . base64_encode($queryStr) . '/?' . http_build_query($params);
    }

    private function toTrojanLink(): string
    {
        $params = [];
        if ($this->tls) $params['security'] = 'tls';
        if ($this->sni) $params['sni'] = $this->sni;

        $paramStr = !empty($params) ? '?' . http_build_query($params) : '';
        return 'trojan://' . $this->password . '@' . $this->server . ':' . $this->port . $paramStr . '#' . urlencode($this->name);
    }
}
