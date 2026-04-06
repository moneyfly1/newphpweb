<?php
declare (strict_types = 1);

namespace app\service;

use app\model\SystemSetting;

class AppConfigService
{
    private ?array $cache = null;

    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $defaults = config('cboard.settings_defaults', []);
        $settings = [];

        try {
            foreach (SystemSetting::select() as $row) {
                $settings[$row->group_key . '.' . $row->item_key] = $this->decodeValue($row->item_value);
            }
        } catch (\Throwable) {
            $this->cache = $defaults;
            return $this->cache;
        }

        $this->cache = array_replace($defaults, $settings);
        return $this->cache;
    }

    public function get(string $key, mixed $fallback = null): mixed
    {
        $all = $this->all();
        return array_key_exists($key, $all) ? $all[$key] : $fallback;
    }

    public function set(string $group, string $item, mixed $value, int $autoload = 1): void
    {
        $encoded = is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE);

        $setting = SystemSetting::where('group_key', $group)->where('item_key', $item)->find();
        if ($setting) {
            $setting->item_value = $encoded;
            $setting->autoload = $autoload;
            $setting->save();
        } else {
            SystemSetting::create([
                'group_key'  => $group,
                'item_key'   => $item,
                'item_value' => $encoded,
                'autoload'   => $autoload,
            ]);
        }

        $this->cache = null;
    }

    public function paymentMethods(): array
    {
        $enabled = (array) $this->get('payment.enabled_methods', ['balance', 'manual']);
        $defs = config('cboard.payment_method_defs', []);

        return array_values(array_filter($defs, static fn (array $method): bool => in_array($method['code'], $enabled, true)));
    }

    public function clientFormats(): array
    {
        return config('cboard.client_formats', []);
    }

    public function appName(): string
    {
        return (string) $this->get('site.app_name', config('cboard.app_name'));
    }

    public function baseUrl(): string
    {
        return rtrim((string) $this->get('site.base_url', config('cboard.base_url')), '/');
    }

    private function decodeValue(?string $value): mixed
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        if (in_array($trimmed, ['true', 'false'], true)) {
            return $trimmed === 'true';
        }

        if (is_numeric($trimmed)) {
            return str_contains($trimmed, '.') ? (float) $trimmed : (int) $trimmed;
        }

        if (($trimmed[0] ?? '') === '{' || ($trimmed[0] ?? '') === '[') {
            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return $value;
    }
}
