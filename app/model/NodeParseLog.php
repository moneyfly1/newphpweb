<?php
declare (strict_types = 1);

namespace app\model;

class NodeParseLog extends BaseModel
{
    protected $name = 'node_parse_logs';

    protected $type = [
        'source_id'        => 'integer',
        'parsed_node_count' => 'integer',
        'successful_count'  => 'integer',
        'failed_count'      => 'integer',
        'duration_ms'       => 'integer',
        'start_time'        => 'datetime',
        'end_time'          => 'datetime',
    ];

    /**
     * 获取源的日志
     */
    public static function getSourceLogs(int $sourceId, int $limit = 10)
    {
        return self::where('source_id', $sourceId)
            ->order('id', 'desc')
            ->limit($limit)
            ->select();
    }

    /**
     * 获取最近的成功日志
     */
    public static function getLastSuccess(int $sourceId)
    {
        return self::where('source_id', $sourceId)
            ->where('failed_count', 0)
            ->order('id', 'desc')
            ->find();
    }

    /**
     * 统计信息
     */
    public static function getStats(int $days = 7)
    {
        $date = date('Y-m-d H:i:s', time() - $days * 86400);
        
        $records = self::where('start_time', '>=', $date)->select();
        
        $totalNodes = 0;
        $totalSuccess = 0;
        $totalFailed = 0;
        $totalTime = 0;

        foreach ($records as $record) {
            $totalNodes += $record->parsed_node_count ?? 0;
            $totalSuccess += $record->successful_count ?? 0;
            $totalFailed += $record->failed_count ?? 0;
            $totalTime += $record->duration_ms ?? 0;
        }

        return [
            'total_nodes'    => $totalNodes,
            'success_rate'   => $totalNodes > 0 ? round(($totalSuccess / $totalNodes) * 100, 2) : 0,
            'failed_count'   => $totalFailed,
            'avg_time_ms'    => count($records) > 0 ? round($totalTime / count($records), 2) : 0,
            'total_fetches'  => count($records),
        ];
    }
}
