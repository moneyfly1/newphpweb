<?php
declare (strict_types = 1);

namespace app\service;

use app\model\ProxyNode;
use app\model\NodeSource;
use app\model\NodeParseLog;
use think\facade\Log;

/**
 * 节点采集服务
 * 负责从远程源采集节点并导入数据库
 */
class NodeCollectorService
{
    private NodeParserService $parser;
    private const NODE_LINK_REGEX = '/(?:^|\s)((?:vmess|vless|trojan|ssr?|hysteria2?|tuic|socks5?|https?|wg):\/\/[^\s\n\r]+)/i';

    public function __construct()
    {
        $this->parser = app(NodeParserService::class);
    }

    /**
     * 从源采集节点
     */
    public function collectFromSource(NodeSource $source, ?int $subscriptionId = null): array
    {
        $startTime = microtime(true);
        
        try {
            // 获取远程订阅内容
            $content = $this->fetchRemoteContent($source->source_url, $source->timeout_seconds ?? 30, $source->auth_header);
            if (!$content) {
                throw new \Exception('无法获取远程内容');
            }

            // 提取节点链接
            $links = $this->extractNodeLinks($content, $source->format);
            if (empty($links)) {
                throw new \Exception('未找到有效的节点链接');
            }

            // 解析所有链接
            $parseResults = $this->parser->parseLinks($links, 5);

            // 导入到数据库
            $stats = $this->importNodes($parseResults, $subscriptionId, $source->source_url);

            // 记录日志
            $duration = (int) ((microtime(true) - $startTime) * 1000);
            $this->recordLog($source->id, count($links), $stats['success'], $stats['failed'], $duration);

            // 更新源信息
            $source->recordFetch($stats['success']);

            return [
                'success'  => true,
                'message'  => "成功采集并导入 {$stats['success']} 个节点",
                'stats'    => $stats,
            ];
        } catch (\Exception $e) {
            $error = $e->getMessage();
            $source->recordFetch(0, $error);
            
            $duration = (int) ((microtime(true) - $startTime) * 1000);
            $this->recordLog($source->id, 0, 0, 0, $duration, $error);

            return [
                'success' => false,
                'message' => "采集失败: {$error}",
                'error'   => $error,
            ];
        }
    }

    /**
     * 从所有源采集
     */
    public function collectFromAllSources(?int $subscriptionId = null): array
    {
        $sources = NodeSource::getEnabled();
        $results = [];

        foreach ($sources as $source) {
            if (!$source->needsFetch()) {
                continue;
            }

            $result = $this->collectFromSource($source, $subscriptionId);
            $results[] = $result;
        }

        return $results;
    }

    /**
     * 从下一个待采集的源采集
     */
    public function collectNext(?int $subscriptionId = null): ?array
    {
        $source = NodeSource::getNext();
        if (!$source) {
            return null;
        }

        return $this->collectFromSource($source, $subscriptionId);
    }

    /**
     * ==========================================
     * 私有方法
     * ==========================================
     */

    /**
     * 获取远程内容
     */
    private function fetchRemoteContent(string $url, int $timeout = 30, ?string $authHeader = null): ?string
    {
        try {
            $headers = ['User-Agent' => 'Mozilla/5.0 (compatible)'];
            
            if ($authHeader) {
                $headers['Authorization'] = $authHeader;
            }

            $context = stream_context_create([
                'http' => [
                    'method'  => 'GET',
                    'timeout' => $timeout,
                    'header'  => implode("\r\n", array_map(fn($k, $v) => "$k: $v", array_keys($headers), $headers)),
                    'follow_location' => true,
                    'max_redirects'   => 5,
                ],
                'https' => [
                    'method'           => 'GET',
                    'timeout'          => $timeout,
                    'header'           => implode("\r\n", array_map(fn($k, $v) => "$k: $v", array_keys($headers), $headers)),
                    'follow_location'  => true,
                    'max_redirects'    => 5,
                    'verify_peer'      => false,
                    'verify_peer_name' => false,
                ],
            ]);

            $content = @file_get_contents($url, false, $context);
            if ($content === false) {
                return null;
            }

            // 如果是 Base64，先解码
            if ($this->isBase64($content)) {
                $decoded = base64_decode($content, true);
                if ($decoded !== false) {
                    $content = $decoded;
                }
            }

            return $content;
        } catch (\Exception $e) {
            Log::warning('获取远程内容失败: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 检查是否 Base64 编码
     */
    private function isBase64(string $str): bool
    {
        if (!preg_match('/^[a-zA-Z0-9+\\/]*={0,2}$/', $str)) {
            return false;
        }

        $decoded = base64_decode($str, true);
        if ($decoded === false) {
            return false;
        }

        // Base64 解码后通常是可见字符或链接格式
        return true;
    }

    /**
     * 提取节点链接
     */
    private function extractNodeLinks(string $content, ?string $format = null): array
    {
        $links = [];

        // 尝试作为 YAML 解析（Clash 格式）
        if ($format === 'clash' || str_contains($content, 'proxies:')) {
            $extracted = $this->extractFromYAML($content);
            if (!empty($extracted)) {
                return $extracted;
            }
        }

        // 使用正则提取所有链接
        preg_match_all(self::NODE_LINK_REGEX, $content, $matches);
        if (!empty($matches[1])) {
            $links = array_unique($matches[1]);
        }

        // 应用过滤正则
        if (!empty($filter)) {
            $links = array_filter($links, fn($link) => preg_match($filter, $link));
        }

        return array_values($links);
    }

    /**
     * 从 YAML 格式提取代理
     */
    private function extractFromYAML(string $content): array
    {
        $links = [];

        // 简单的 YAML 代理提取（不完整的 YAML 解析）
        preg_match_all('/\s+-\s+name:\s*([^\n]+)\n(?:.*?\n)*?\s+server:\s*([^\n]+)\n(?:.*?\n)*?\s+port:\s*(\d+)/i', $content, $matches);

        if (!empty($matches[1])) {
            // 这里需要更复杂的逻辑来重建代理配置
            // 暂时返回空，使用正则提取链接作为备选
        }

        return $links;
    }

    /**
     * 导入节点到数据库
     */
    private function importNodes(array $parseResults, ?int $subscriptionId = null, ?string $sourceUrl = null): array
    {
        $stats = ['success' => 0, 'failed' => 0, 'duplicate' => 0];

        foreach ($parseResults as $result) {
            if ($result['error']) {
                $stats['failed']++;
                continue;
            }

            $node = $result['node'];
            if (!$node) {
                $stats['failed']++;
                continue;
            }

            // 检查重复
            $existing = ProxyNode::findDuplicates(
                $subscriptionId ?? 0,
                $node['protocol'],
                $node['server'],
                $node['port']
            );

            if ($existing->count() > 0) {
                $stats['duplicate']++;
                continue;
            }

            // 保存节点
            try {
                ProxyNode::create([
                    'subscription_id'   => $subscriptionId,
                    'remote_source_url' => $sourceUrl,
                    'protocol'          => $node['protocol'],
                    'name'              => $node['name'],
                    'server'            => $node['server'],
                    'port'              => $node['port'],
                    'method'            => $node['method'] ?? null,
                    'password'          => $node['password'] ?? null,
                    'uuid'              => $node['uuid'] ?? null,
                    'alter_id'          => $node['alter_id'] ?? null,
                    'network'           => $node['network'] ?? 'tcp',
                    'host'              => $node['host'] ?? null,
                    'path'              => $node['path'] ?? null,
                    'sni'               => $node['sni'] ?? null,
                    'obfs'              => $node['obfs'] ?? null,
                    'tls'               => $node['tls'] ?? false,
                    'settings_json'     => $node['settings_json'] ?? [],
                    'is_active'         => 1,
                    'raw_link'          => $result['link'],
                ]);

                $stats['success']++;
            } catch (\Exception $e) {
                Log::warning('保存节点失败: ' . $e->getMessage());
                $stats['failed']++;
            }
        }

        return $stats;
    }

    /**
     * 记录采集日志
     */
    private function recordLog(
        ?int $sourceId,
        int $parsedCount,
        int $successCount,
        int $failedCount,
        int $durationMs,
        ?string $error = null
    ): void {
        try {
            NodeParseLog::create([
                'source_id'        => $sourceId,
                'parsed_node_count' => $parsedCount,
                'successful_count'  => $successCount,
                'failed_count'      => $failedCount,
                'duration_ms'       => $durationMs,
                'start_time'        => date('Y-m-d H:i:s', time()),
                'end_time'          => date('Y-m-d H:i:s'),
                'errors'            => $error,
            ]);
        } catch (\Exception $e) {
            Log::warning('保存解析日志失败: ' . $e->getMessage());
        }
    }
}
