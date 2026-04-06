<?php
declare (strict_types = 1);

namespace app\controller\api;

use app\BaseController;
use app\model\ProxyNode;
use app\model\NodeSource;
use app\model\NodeParseLog;
use app\model\Subscription;
use app\service\NodeCollectorService;
use app\service\NodeParserService;
use app\service\SubscriptionFormatService;
use think\Request;

/**
 * 前端管理API - 完整的管理后台接口
 */
class AdminApiController extends BaseController
{
    private NodeCollectorService $collector;
    private NodeParserService $parser;
    private SubscriptionFormatService $formatter;

    public function initialize()
    {
        parent::initialize();
        $this->collector = app(NodeCollectorService::class);
        $this->parser = app(NodeParserService::class);
        $this->formatter = app(SubscriptionFormatService::class);
        $this->requireAdmin();
    }

    /**
     * ==========================================
     * 采集源管理 API
     * ==========================================
     */

    /**
     * 获取所有采集源
     * GET /admin/api/sources
     */
    public function listSources(Request $request)
    {
        $page = (int)$request->param('page', 1);
        $limit = (int)$request->param('limit', 20);
        $search = $request->param('search', '');

        $query = NodeSource::query();
        if ($search) {
            $query->where('name', 'like', "%{$search}%")->orWhere('source_url', 'like', "%{$search}%");
        }

        $total = $query->count();
        $sources = $query
            ->orderBy('priority', 'desc')
            ->orderBy('id', 'desc')
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->select();

        return $this->jsonSuccess([
            'items' => $sources,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    /**
     * 添加采集源
     * POST /admin/api/sources
     */
    public function createSource(Request $request)
    {
        $data = $request->post();

        $rules = [
            'name' => 'require',
            'source_url' => 'require|url',
            'fetch_interval_minutes' => 'require|integer|min:60',
            'timeout_seconds' => 'require|integer|between:5,60',
        ];

        $validate = validate($rules, $data);
        if (!$validate) {
            return $this->jsonError('验证失败：' . $validate);
        }

        $source = NodeSource::create([
            'name' => $data['name'],
            'source_url' => $data['source_url'],
            'source_type' => $data['source_type'] ?? 'subscription',
            'format' => $data['format'] ?? null,
            'fetch_interval_minutes' => $data['fetch_interval_minutes'],
            'timeout_seconds' => $data['timeout_seconds'],
            'priority' => $data['priority'] ?? 0,
            'is_enabled' => $data['is_enabled'] ?? true,
        ]);

        return $this->jsonSuccess(['id' => $source->id], '创建成功');
    }

    /**
     * 更新采集源
     * POST /admin/api/sources/:id
     */
    public function updateSource(Request $request, int $id)
    {
        $source = NodeSource::find($id);
        if (!$source) {
            return $this->jsonError('采集源不存在', 404);
        }

        $data = $request->post();
        unset($data['id'], $data['created_at']);

        $source->save($data);

        return $this->jsonSuccess([], '更新成功');
    }

    /**
     * 删除采集源
     * DELETE /admin/api/sources/:id
     */
    public function deleteSource(int $id)
    {
        $source = NodeSource::find($id);
        if (!$source) {
            return $this->jsonError('采集源不存在', 404);
        }

        // 删除关联的节点
        ProxyNode::where('remote_source_url', $source->source_url)->delete();

        $source->delete();

        return $this->jsonSuccess([], '删除成功');
    }

    /**
     * 采集单个源
     * POST /admin/api/sources/:id/collect
     */
    public function collectOne(int $id)
    {
        $source = NodeSource::find($id);
        if (!$source) {
            return $this->jsonError('采集源不存在', 404);
        }

        if (!$source['is_enabled']) {
            return $this->jsonError('采集源已禁用', 400);
        }

        try {
            $result = $this->collector->collectFromSource($source);
            return $this->jsonSuccess($result, '采集成功');
        } catch (\Exception $e) {
            return $this->jsonError('采集失败：' . $e->getMessage(), 500);
        }
    }

    /**
     * 采集所有源
     * POST /admin/api/sources/collect-all
     */
    public function collectAll()
    {
        try {
            $sources = NodeSource::where('is_enabled', 1)->select();
            $results = [];

            foreach ($sources as $source) {
                $result = $this->collector->collectFromSource($source);
                $results[] = [
                    'source_id' => $source['id'],
                    'name' => $source['name'],
                    'status' => $result['success'] ? 'success' : 'failed',
                    'nodes_collected' => $result['imported_count'] ?? 0,
                ];
            }

            return $this->jsonSuccess(['results' => $results], '采集完成');
        } catch (\Exception $e) {
            return $this->jsonError('采集失败：' . $e->getMessage(), 500);
        }
    }

    /**
     * 获取源的采集日志
     * GET /admin/api/sources/:id/logs
     */
    public function getSourceLogs(Request $request, int $id)
    {
        $limit = (int)$request->param('limit', 10);

        $logs = NodeParseLog::where('source_id', $id)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->select();

        return $this->jsonSuccess(['items' => $logs]);
    }

    /**
     * 获取采集统计
     * GET /admin/api/sources/stats
     */
    public function getSourceStats(Request $request)
    {
        $days = (int)$request->param('days', 7);
        $startDate = date('Y-m-d', time() - $days * 86400);

        $logs = NodeParseLog::where('created_at', '>=', $startDate)->select();

        $totalCollections = count($logs);
        $successCount = 0;
        $totalParsed = 0;
        $totalSuccessful = 0;
        $totalFailed = 0;
        $totalDuration = 0;

        foreach ($logs as $log) {
            if ($log['failed_count'] == 0) {
                $successCount++;
            }
            $totalParsed += $log['parsed_node_count'] ?? 0;
            $totalSuccessful += $log['successful_count'] ?? 0;
            $totalFailed += $log['failed_count'] ?? 0;
            $totalDuration += $log['duration_ms'] ?? 0;
        }

        return $this->jsonSuccess([
            'total_collections' => $totalCollections,
            'success_rate' => $totalCollections > 0 ? ($successCount / $totalCollections * 100) : 0,
            'avg_duration_ms' => $totalCollections > 0 ? ($totalDuration / $totalCollections) : 0,
            'total_nodes_collected' => $totalParsed,
            'failed_collections' => $totalCollections - $successCount,
            'total_nodes_successful' => $totalSuccessful,
            'total_nodes_failed' => $totalFailed,
        ]);
    }

    /**
     * ==========================================
     * 节点管理 API
     * ==========================================
     */

    /**
     * 获取所有节点（分页）
     * GET /admin/api/nodes/all
     */
    public function listAllNodes(Request $request)
    {
        $page = (int)$request->param('page', 1);
        $limit = (int)$request->param('limit', 20);
        $search = $request->param('search', '');

        $query = ProxyNode::query();

        // 搜索
        if ($search) {
            $query->where('name', 'like', "%{$search}%")
                ->orWhere('server', 'like', "%{$search}%");
        }

        // 筛选条件
        if ($protocol = $request->param('protocol')) {
            $query->where('protocol', $protocol);
        }
        if ($isActive = $request->param('is_active')) {
            $query->where('is_active', $isActive);
        }
        if ($isManual = $request->param('is_manual')) {
            $query->where('is_manual', $isManual);
        }
        if ($subId = (int)$request->param('subscription_id')) {
            $query->where('subscription_id', $subId);
        }

        $total = $query->count();
        $nodes = $query
            ->orderBy('id', 'desc')
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->select();

        return $this->jsonSuccess([
            'items' => $nodes,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    /**
     * 获取指定订阅下的节点
     * GET /admin/api/nodes/subscription/:id
     */
    public function getSubscriptionNodes(Request $request, int $id)
    {
        $page = (int)$request->param('page', 1);
        $limit = (int)$request->param('limit', 20);

        $query = ProxyNode::where('subscription_id', $id);

        $total = $query->count();
        $nodes = $query
            ->orderBy('is_active', 'desc')
            ->orderBy('priority', 'desc')
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->select();

        return $this->jsonSuccess([
            'items' => $nodes,
            'total' => $total,
        ]);
    }

    /**
     * 删除单个节点
     * DELETE /admin/api/nodes/:id
     */
    public function deleteNode(int $id)
    {
        $node = ProxyNode::find($id);
        if (!$node) {
            return $this->jsonError('节点不存在', 404);
        }

        $node->delete();
        return $this->jsonSuccess([], '删除成功');
    }

    /**
     * 批量删除节点
     * POST /admin/api/nodes/batch-delete
     */
    public function batchDeleteNodes(Request $request)
    {
        $ids = $request->post('ids');
        if (!is_array($ids) || count($ids) === 0) {
            return $this->jsonError('请选择要删除的节点', 400);
        }

        ProxyNode::whereIn('id', $ids)->delete();

        return $this->jsonSuccess([], count($ids) . ' 个节点已删除');
    }

    /**
     * 批量导入节点
     * POST /admin/api/nodes/batch-import
     */
    public function batchImportNodes(Request $request)
    {
        $linksInput = $request->post('links', '');
        $subscriptionId = (int)$request->post('subscription_id', 0);

        if (empty($linksInput)) {
            return $this->jsonError('请输入节点链接', 400);
        }

        // 分行解析
        $links = preg_split('/[\r\n]+/', trim($linksInput), -1, PREG_SPLIT_NO_EMPTY);

        // 解析所有链接
        $parseResults = [];
        foreach ($links as $link) {
            $result = $this->parser->parseLink(trim($link));
            if ($result['success']) {
                $parseResults[] = ['success' => true, 'data' => $result['data']];
            } else {
                $parseResults[] = ['success' => false, 'error' => $result['error']];
            }
        }

        // 导入到数据库
        $result = $this->collector->importNodes(
            $parseResults,
            $subscriptionId > 0 ? $subscriptionId : null
        );

        return $this->jsonSuccess([
            'imported_count' => $result['imported_count'] ?? 0,
            'failed_count' => $result['failed_count'] ?? 0,
        ], '导入完成');
    }

    /**
     * 更新节点状态
     * POST /admin/api/nodes/:id/status
     */
    public function updateNodeStatus(Request $request, int $id)
    {
        $node = ProxyNode::find($id);
        if (!$node) {
            return $this->jsonError('节点不存在', 404);
        }

        if ($request->has('is_active')) {
            $node['is_active'] = (int)$request->post('is_active');
        }
        if ($request->has('priority')) {
            $node['priority'] = (int)$request->post('priority');
        }

        $node->save();

        return $this->jsonSuccess([], '更新成功');
    }

    /**
     * 获取节点统计
     * GET /admin/api/nodes/stats/:id
     */
    public function getNodeStats(int $subscriptionId)
    {
        $nodes = ProxyNode::where('subscription_id', $subscriptionId)->select();

        $protocols = [];
        $totalActive = 0;

        foreach ($nodes as $node) {
            $protocol = strtoupper($node['protocol']);
            $protocols[$protocol] = ($protocols[$protocol] ?? 0) + 1;

            if ($node['is_active'] == 1) {
                $totalActive++;
            }
        }

        return $this->jsonSuccess([
            'total' => count($nodes),
            'active' => $totalActive,
            'protocol_distribution' => $protocols,
            'avg_priority' => count($nodes) > 0 ? round(array_sum(array_column($nodes, 'priority')) / count($nodes)) : 0,
        ]);
    }

    /**
     * ==========================================
     * 统计和分析 API
     * ==========================================
     */

    /**
     * 获取总体统计
     * GET /admin/api/stats/dashboard
     */
    public function getDashboardStats()
    {
        return $this->jsonSuccess([
            'sources' => [
                'total' => NodeSource::count(),
                'enabled' => NodeSource::where('is_enabled', 1)->count(),
                'disabled' => NodeSource::where('is_enabled', 0)->count(),
            ],
            'nodes' => [
                'total' => ProxyNode::count(),
                'active' => ProxyNode::where('is_active', 1)->count(),
                'inactive' => ProxyNode::where('is_active', 0)->count(),
            ],
            'protocols' => $this->getProtocolDistribution(),
            'collection_success_rate' => $this->getCollectionSuccessRate(),
        ]);
    }

    /**
     * 获取协议分布
     */
    private function getProtocolDistribution()
    {
        $protocols = ProxyNode::field('protocol, COUNT(*) as count')
            ->where('is_active', 1)
            ->group('protocol')
            ->select();

        $result = [];
        foreach ($protocols as $p) {
            $result[strtoupper($p['protocol'])] = $p['count'];
        }
        return $result;
    }

    /**
     * 获取采集成功率
     */
    private function getCollectionSuccessRate()
    {
        $logs = NodeParseLog::where('created_at', '>=', date('Y-m-d', time() - 7 * 86400))
            ->select();

        if (count($logs) === 0) {
            return 100;
        }

        $success = 0;
        foreach ($logs as $log) {
            if ($log['failed_count'] == 0) {
                $success++;
            }
        }

        return round($success / count($logs) * 100, 2);
    }
}
