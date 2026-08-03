<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Contracts\SettingsRepositoryContract;
use App\Enums\SettingKey;
use App\Models\Setting;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Collection;

/**
 * Reads settings once per request cycle and caches them as a single entry.
 *
 * The whole table is small and read on nearly every request, so one cache key
 * holding the lot beats per-key lookups. Any write flushes it.
 */
final class SettingsRepository implements SettingsRepositoryContract
{
    private const CACHE_KEY = 'carhire.settings';

    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

    public function get(SettingKey|string $key, mixed $default = null): mixed
    {
        $raw = $this->all()[$this->keyString($key)] ?? null;

        if ($raw === null) {
            return $default;
        }

        return $this->cast($raw['value'], $raw['type']) ?? $default;
    }

    public function string(SettingKey|string $key, ?string $default = null): ?string
    {
        $value = $this->get($key);

        return $value === null ? $default : (string) $value;
    }

    public function integer(SettingKey|string $key, ?int $default = null): ?int
    {
        $value = $this->get($key);

        return $value === null ? $default : (int) $value;
    }

    public function decimal(SettingKey|string $key, ?string $default = null): ?string
    {
        $value = $this->get($key);

        if ($value === null) {
            return $default === null ? null : $this->normalise($default);
        }

        return $this->normalise((string) $value);
    }

    public function boolean(SettingKey|string $key, bool $default = false): bool
    {
        $value = $this->get($key);

        if ($value === null) {
            return $default;
        }

        return (bool) $value;
    }

    public function set(SettingKey|string $key, mixed $value, bool $isPlaceholder = false): void
    {
        $keyString = $this->keyString($key);

        $stored = is_array($value)
            ? json_encode($value, JSON_THROW_ON_ERROR)
            : (is_bool($value) ? ($value ? '1' : '0') : (string) $value);

        Setting::query()->updateOrCreate(
            ['key' => $keyString],
            ['value' => $stored, 'is_placeholder' => $isPlaceholder],
        );

        $this->flush();
    }

    public function placeholders(): Collection
    {
        return Setting::query()
            ->where('is_placeholder', true)
            ->orderBy('group')
            ->orderBy('key')
            ->get();
    }

    public function flush(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, array{value: ?string, type: string}>
     */
    private function all(): array
    {
        /** @var array<string, array{value: ?string, type: string}> */
        return $this->cache->rememberForever(self::CACHE_KEY, static function (): array {
            return Setting::query()
                ->get(['key', 'value', 'type'])
                ->mapWithKeys(static fn (Setting $setting): array => [
                    $setting->key => ['value' => $setting->value, 'type' => $setting->type],
                ])
                ->all();
        });
    }

    private function cast(?string $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'integer' => (int) $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOL),
            'decimal' => $this->normalise($value),
            'json' => json_decode($value, true, 512, JSON_THROW_ON_ERROR),
            default => $value,
        };
    }

    /**
     * Bring a numeric string to the configured scale.
     *
     * Values arriving from SQL or from a form are unscaled ('300' rather than
     * '300.00'). Comparing or adding those with bcmath without normalising
     * first produces results that look right but fail an exact string
     * assertion — a trap this project has hit before.
     */
    private function normalise(string $value): string
    {
        return bcadd($value, '0', (int) config('carhire.money_scale', 2));
    }

    private function keyString(SettingKey|string $key): string
    {
        return $key instanceof SettingKey ? $key->value : $key;
    }
}
