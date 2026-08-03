<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A single operator-editable configuration value.
 *
 * Read these through SettingsRepository, which handles casting and caching.
 * Reading the model directly gives you an untyped string.
 */
final class Setting extends Model
{
    /** @use HasFactory<SettingFactory> */
    use HasFactory;

    protected $fillable = [
        'key',
        'value',
        'type',
        'group',
        'label',
        'description',
        'is_placeholder',
    ];

    protected function casts(): array
    {
        return [
            'is_placeholder' => 'boolean',
        ];
    }
}
