<?php

namespace App\Models;

use App\Support\RefCache;
use Illuminate\Database\Eloquent\Model;

/** Admin-editable settings, one JSON value per key (e.g. 'final_grades'). Read through RefCache group 'settings'. */
class AppSetting extends Model
{
    protected $table = 'app_settings';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value', 'updated_by'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    protected static function booted(): void
    {
        static::saved(fn () => RefCache::flush('settings'));
        static::deleted(fn () => RefCache::flush('settings'));
    }

    /** The stored value, or $default when the key was never saved. */
    public static function get(string $key, mixed $default = null): mixed
    {
        return RefCache::remember('settings', $key, fn () => static::find($key)?->value) ?? $default;
    }
}
