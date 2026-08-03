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
     * Settings still holding a seeded placeholder rather than a real decision.
     *
     * @return Collection<int, Setting>
     */
    public function placeholders(): Collection;

    public function flush(): void;
}
