<?php
declare(strict_types=1);

namespace app\console\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Log;

class Schedule extends Command
{
    protected function configure(): void
    {
        $this->setName('schedule:run')
            ->setDescription('运行定时任务调度器');
    }

    protected function execute(Input $input, Output $output): int
    {
        $tasks = [
            ['name' => '邮件队列处理', 'interval' => 2, 'callback' => [$this, 'processEmailQueue']],
            ['name' => '订阅源采集', 'interval' => 30, 'callback' => [$this, 'collectNodes']],
            ['name' => '订阅过期检查', 'interval' => 60, 'callback' => [$this, 'checkExpiredSubscriptions']],
            ['name' => '数据库备份', 'interval' => 1440, 'callback' => [$this, 'backupDatabase']],
        ];

        $lockDir = runtime_path() . 'schedule/';
        if (!is_dir($lockDir)) { mkdir($lockDir, 0755, true); }

        $ran = 0;
        foreach ($tasks as $task) {
            $lockFile = $lockDir . md5($task['name']) . '.lock';
            if ($this->shouldRun($lockFile, $task['interval'])) {
                $output->info("[" . date('H:i:s') . "] 执行: {$task['name']}");
                try {
                    call_user_func($task['callback'], $output);
                    file_put_contents($lockFile, (string) time());
                    $ran++;
                } catch (\Throwable $e) {
                    $output->error("  失败: " . $e->getMessage());
                    Log::error("定时任务失败 ({$task['name']}): " . $e->getMessage());
                }
            }
        }

        if ($ran === 0) {
            $output->comment("[" . date('H:i:s') . "] 无需执行的任务");
        }

        return 0;
    }

    private function shouldRun(string $lockFile, int $intervalMinutes): bool
    {
        if (!file_exists($lockFile)) { return true; }
        $lastRun = (int) file_get_contents($lockFile);
        return (time() - $lastRun) >= ($intervalMinutes * 60);
    }

    private function processEmailQueue(Output $output): void
    {
        $service = new \app\service\MailService();
        $result = $service->processQueue(50);
        $output->info("  处理了 " . ($result['processed'] ?? 0) . " 封邮件");
    }

    private function collectNodes(Output $output): void
    {
        $service = new \app\service\NodeCollectorService();
        $sources = \app\model\NodeSource::where('is_enabled', 1)->select();
        $collected = 0;
        foreach ($sources as $source) {
            if ($source->needsFetch()) {
                try {
                    $result = $service->collectFromSource($source, (int) $source->subscription_id);
                    $collected += ($result['imported'] ?? 0);
                } catch (\Throwable $e) {
                    $output->warning("  源 {$source->name} 采集失败: " . $e->getMessage());
                }
            }
        }
        $output->info("  采集了 {$collected} 个节点");
    }

    private function checkExpiredSubscriptions(Output $output): void
    {
        // Mark expired subscriptions
        $expired = \app\model\Subscription::where('status', 'active')
            ->where('expire_at', '<', date('Y-m-d H:i:s'))
            ->select();

        foreach ($expired as $sub) {
            $sub->status = 'expired';
            $sub->save();
            \app\service\NotificationService::notify((int) $sub->user_id, \app\service\NotificationService::SUBSCRIPTION_EXPIRED, [
                '套餐' => \app\model\Package::find($sub->package_id)?->name ?? '未知',
                '到期时间' => (string) $sub->expire_at,
            ]);
        }
        $output->info("  标记了 " . $expired->count() . " 个过期订阅");

        // Warn expiring soon (7 days)
        $warningDate = date('Y-m-d H:i:s', strtotime('+7 days'));
        $expiring = \app\model\Subscription::where('status', 'active')
            ->where('expire_at', '>', date('Y-m-d H:i:s'))
            ->where('expire_at', '<', $warningDate)
            ->select();

        $notifiedKey = 'expiry_warned_' . date('Y-m-d');
        foreach ($expiring as $sub) {
            // Avoid duplicate notifications per day
            $warned = \app\model\UserSetting::where('user_id', $sub->user_id)
                ->where('item_key', $notifiedKey)->find();
            if ($warned) { continue; }

            $daysLeft = max(1, (int) ceil((strtotime((string) $sub->expire_at) - time()) / 86400));
            \app\service\NotificationService::notify((int) $sub->user_id, \app\service\NotificationService::SUBSCRIPTION_EXPIRING, [
                '套餐' => \app\model\Package::find($sub->package_id)?->name ?? '未知',
                '到期时间' => (string) $sub->expire_at,
                '剩余天数' => $daysLeft,
            ]);

            \app\model\UserSetting::create([
                'user_id' => $sub->user_id, 'item_key' => $notifiedKey, 'item_value' => '1',
            ]);
        }
        $output->info("  发送了 " . $expiring->count() . " 条到期提醒");
    }

    private function backupDatabase(Output $output): void
    {
        $token = (string) app(\app\service\AppConfigService::class)->get('backup.github_token', '');
        $repo = (string) app(\app\service\AppConfigService::class)->get('backup.github_repo', '');

        if ($token === '' || $repo === '') {
            $output->comment("  GitHub 备份未配置，跳过");
            return;
        }

        // Determine database type
        $dbType = env('DB_TYPE', 'sqlite');
        $backupFile = runtime_path() . 'backup_' . date('Y-m-d') . '.sql';

        if ($dbType === 'sqlite') {
            $dbPath = env('DB_DATABASE', runtime_path() . 'cboard.sqlite');
            if (file_exists($dbPath)) {
                copy($dbPath, $backupFile);
            }
        } else {
            $host = env('DB_HOST', '127.0.0.1');
            $port = env('DB_PORT', '3306');
            $name = env('DB_NAME', 'cboard');
            $user = env('DB_USER', 'root');
            $pass = env('DB_PASS', '');
            exec("mysqldump -h{$host} -P{$port} -u{$user} -p{$pass} {$name} > {$backupFile} 2>&1");
        }

        if (!file_exists($backupFile) || filesize($backupFile) === 0) {
            $output->error("  备份文件生成失败");
            return;
        }

        // Push to GitHub via API
        $content = base64_encode(file_get_contents($backupFile));
        $path = 'backups/backup_' . date('Y-m-d_His') . ($dbType === 'sqlite' ? '.sqlite' : '.sql');

        $payload = json_encode([
            'message' => 'Auto backup ' . date('Y-m-d H:i:s'),
            'content' => $content,
        ]);

        $context = stream_context_create([
            'http' => [
                'method' => 'PUT',
                'header' => "Authorization: token {$token}\r\nContent-Type: application/json\r\nUser-Agent: CBoard-Backup\r\n",
                'content' => $payload,
                'timeout' => 60,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $apiUrl = "https://api.github.com/repos/{$repo}/contents/{$path}";
        $result = @file_get_contents($apiUrl, false, $context);

        @unlink($backupFile);

        if ($result === false) {
            $output->error("  GitHub 推送失败");
            Log::error('数据库备份推送 GitHub 失败');
            return;
        }

        $output->info("  备份已推送到 GitHub: {$repo}/{$path}");
    }
}
