<?php
declare (strict_types = 1);

namespace app\console\command;

use app\model\Subscription;
use app\service\SubscriptionFormatService;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;

class ValidateSubscriptionOutput extends Command
{
    protected function configure(): void
    {
        $this->setName('subscription:validate')
            ->setDescription('验证所有订阅输出格式的正确性')
            ->addOption('token', null, Option::VALUE_OPTIONAL, '指定订阅 token');
    }

    protected function execute(Input $input, Output $output): int
    {
        $token = trim((string) $input->getOption('token'));
        $subscription = $token !== ''
            ? Subscription::where('sub_token', $token)->find()
            : Subscription::order('id', 'desc')->find();

        if (!$subscription) {
            $output->error('未找到可验证的订阅。');
            return 1;
        }

        $service = app(SubscriptionFormatService::class);
        $formats = [
            'clash', 'base64', 'v2rayn', 'shadowrocket', 'surge',
            'quantumult', 'quantumultx', 'loon', 'ssr',
            'singbox', 'hiddify', 'unicode', 'usable',
        ];

        $output->writeln('<info>订阅验证开始</info>');
        $output->writeln("订阅ID: {$subscription->id} | Token: {$subscription->sub_token}");
        $output->writeln('');

        $passed = 0;
        $failed = 0;

        foreach ($formats as $format) {
            try {
                $content = $service->generateSubscriptionByToken(
                    (string) $subscription->sub_token, $format
                );
                $result = $this->validateFormat($format, $content);

                if ($result['valid']) {
                    $output->writeln("<info>[PASS]</info> {$format} — {$result['message']} ({$result['size']} bytes)");
                    $passed++;
                } else {
                    $output->writeln("<error>[FAIL]</error> {$format} — {$result['message']}");
                    $failed++;
                }
            } catch (\Throwable $e) {
                $output->writeln("<error>[ERROR]</error> {$format} — " . $e->getMessage());
                $failed++;
            }
        }

        $output->writeln('');
        $output->writeln("<info>验证完成: {$passed} 通过, {$failed} 失败</info>");
        return $failed > 0 ? 1 : 0;
    }

    private function validateFormat(string $format, string $content): array
    {
        $size = strlen($content);
        if ($size === 0) {
            return ['valid' => false, 'message' => '输出为空', 'size' => 0];
        }

        return match ($format) {
            'clash' => $this->validateYaml($content, $size),
            'singbox', 'hiddify' => $this->validateJson($content, $size, 'outbounds'),
            'shadowrocket' => $this->validateJson($content, $size, 'proxies'),
            'base64', 'v2rayn' => $this->validateBase64($content, $size),
            'surge' => $this->validateSurge($content, $size),
            'quantumult', 'quantumultx', 'loon' => $this->validateTextLines($content, $size),
            'ssr' => $this->validateBase64($content, $size),
            default => ['valid' => $size > 0, 'message' => "输出 {$size} bytes", 'size' => $size],
        };
    }

    private function validateYaml(string $content, int $size): array
    {
        if (!str_contains($content, 'proxies:')) {
            return ['valid' => false, 'message' => '缺少 proxies 节', 'size' => $size];
        }
        $proxyCount = substr_count($content, '- name:');
        return ['valid' => $proxyCount > 0, 'message' => "YAML 有效, {$proxyCount} 个节点", 'size' => $size];
    }

    private function validateJson(string $content, int $size, string $requiredKey): array
    {
        $data = json_decode($content, true);
        if ($data === null) {
            return ['valid' => false, 'message' => 'JSON 解析失败: ' . json_last_error_msg(), 'size' => $size];
        }
        if (!isset($data[$requiredKey]) || !is_array($data[$requiredKey])) {
            return ['valid' => false, 'message' => "缺少 {$requiredKey} 字段", 'size' => $size];
        }
        $count = count($data[$requiredKey]);
        return ['valid' => $count > 0, 'message' => "JSON 有效, {$count} 个 {$requiredKey}", 'size' => $size];
    }

    private function validateBase64(string $content, int $size): array
    {
        $decoded = base64_decode($content, true);
        if ($decoded === false) {
            return ['valid' => false, 'message' => 'Base64 解码失败', 'size' => $size];
        }
        $lines = array_filter(explode("\n", $decoded), fn ($l) => trim($l) !== '');
        $linkCount = count($lines);
        return ['valid' => $linkCount > 0, 'message' => "Base64 有效, {$linkCount} 条链接", 'size' => $size];
    }

    private function validateSurge(string $content, int $size): array
    {
        if (!str_contains($content, '[Proxy]')) {
            return ['valid' => false, 'message' => '缺少 [Proxy] section', 'size' => $size];
        }
        $proxyCount = substr_count($content, ' = ');
        return ['valid' => $proxyCount > 0, 'message' => "Surge 有效, ~{$proxyCount} 个代理", 'size' => $size];
    }

    private function validateTextLines(string $content, int $size): array
    {
        $lines = array_filter(explode("\n", $content), fn ($l) => trim($l) !== '');
        $count = count($lines);
        return ['valid' => $count > 0, 'message' => "文本有效, {$count} 行", 'size' => $size];
    }
}
