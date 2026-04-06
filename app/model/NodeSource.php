<?php
declare (strict_types = 1);

namespace app\model;

class NodeSource extends BaseModel
{
    protected $name = 'node_sources';

    protected $type = [
        'priority'               => 'integer',
        'is_enabled'             => 'boolean',
        'last_fetch_node_count'  => 'integer',
        'fetch_interval_minutes' => 'integer',
        'timeout_seconds'        => 'integer',
        'last_fetch_at'          => 'datetime',
    ];

    /**
     * 获取启用的源
     */
    public static function getEnabled()
    {
        return self::where('is_enabled', 1)->order('priority', 'desc')->select();
    }

    /**
     * 检查是否需要更新
     */
    public function needsFetch(): bool
    {
        if (!$this->last_fetch_at) {
            return true;
        }

        $interval = $this->fetch_interval_minutes ?? 60;
        $lastFetch = strtotime($this->last_fetch_at);
        return time() - $lastFetch > ($interval * 60);
    }

    /**
     * 更新采集时间和节点数
     */
    public function recordFetch(int $nodeCount, ?string $error = null): void
    {
        $this->last_fetch_at = date('Y-m-d H:i:s');
        $this->last_fetch_node_count = $nodeCount;
        if ($error) {
            $this->last_error = $error;
        }
        $this->save();
    }

    /**
     * 获取下一个应该采集的源
     */
    public static function getNext()
    {
        $sources = self::where('is_enabled', 1)->order('priority', 'desc')->select();
        
        foreach ($sources as $source) {
            if ($source->needsFetch()) {
                return $source;
            }
        }

        return null;
    }
}
