<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Enums\SettingKey;
use App\Models\Setting;
use Illuminate\Support\Collection;

/**
 * Typed, cached access to operator-editable configuration.
 */
interface SettingsRepositoryContract
{
    public function get(SettingKey|string $key, mixed $default = null): mixed;

    public function string(SettingKey|string $key, ?string $default = null): ?string;

    public function integer(SettingKey|string $key, ?int $default = null): ?int;

    /**
     * A monetary or fractional value as a bcmath-safe string, normalised to the
     * configured scale. Never a float.
     */
    public function decimal(SettingKey|string $key, ?string $default = null): ?string;

    public function boolean(SettingKey|string $key, bool $default = false): bool;

    public function set(SettingKey|string $key, mixed $value, bool $isPlaceholder = false): void;

    /**
     * Whether this key still holds a seeded placeholder rather than a real
     * decision by the business.
     *
     * Asked by anything that applies a §15 value to real money, so that the
     * figure can be shown as undecided rather than as though it had been
     * chosen. An unknown key is not a placeholder — it is a missing setting,
     * which is a different fault.
     */
    public function isPlaceholder(SettingKey|string $key): bool;

    /**
     * Settings still holding a seeded placeholder rather than a real decision.
     *
     * @return Collection<int, Setting>
     */
    public function placeholders(): Collection;

    public function flush(): void;
}
