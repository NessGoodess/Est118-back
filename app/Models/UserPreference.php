<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPreference extends Model
{
    public const THEMES = ['light', 'dark'];

    public const SIDEBAR_MODES = ['expanded', 'compact'];

    /** Private shell sidebar surface presets (CSS data-sidebar-surface). */
    public const SHELL_SIDEBAR_SURFACES = ['default', 'panel', 'brand', 'primary'];

    /** Private shell main/content surface presets (CSS data-main-surface). */
    public const SHELL_MAIN_SURFACES = ['default', 'muted', 'brand_soft', 'primary'];

    public const IDLE_MIN = 15;

    public const IDLE_MAX = 480;

    public const DEFAULT_IDLE_MINUTES = 60;

    protected $fillable = [
        'user_id',
        'theme',
        'sidebar_mode',
        'idle_logout_minutes',
        'shell_colors',
    ];

    protected function casts(): array
    {
        return [
            'idle_logout_minutes' => 'integer',
            'shell_colors' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array{sidebar: string, main: string}
     */
    public static function defaultShellColors(): array
    {
        return [
            'sidebar' => 'default',
            'main' => 'default',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $input
     * @return array{sidebar: string, main: string}
     */
    public static function normalizeShellColors(?array $input, ?array $base = null): array
    {
        $merged = array_merge(self::defaultShellColors(), $base ?? [], $input ?? []);

        $sidebar = is_string($merged['sidebar'] ?? null) ? $merged['sidebar'] : 'default';
        $main = is_string($merged['main'] ?? null) ? $merged['main'] : 'default';

        if (! in_array($sidebar, self::SHELL_SIDEBAR_SURFACES, true)) {
            $sidebar = 'default';
        }
        if (! in_array($main, self::SHELL_MAIN_SURFACES, true)) {
            $main = 'default';
        }

        return [
            'sidebar' => $sidebar,
            'main' => $main,
        ];
    }

    /**
     * @return array{
     *     theme: string,
     *     sidebar_mode: string,
     *     idle_logout_minutes: int|null,
     *     shell_colors: array{sidebar: string, main: string}
     * }
     */
    public static function defaults(): array
    {
        return [
            'theme' => 'light',
            'sidebar_mode' => 'expanded',
            'idle_logout_minutes' => self::DEFAULT_IDLE_MINUTES,
            'shell_colors' => self::defaultShellColors(),
        ];
    }

    /**
     * @return array{
     *     theme: string,
     *     sidebar_mode: string,
     *     idle_logout_minutes: int|null,
     *     shell_colors: array{sidebar: string, main: string}
     * }
     */
    public function toSessionArray(): array
    {
        return [
            'theme' => $this->theme ?: 'light',
            'sidebar_mode' => $this->sidebar_mode ?: 'expanded',
            'idle_logout_minutes' => $this->idle_logout_minutes,
            'shell_colors' => self::normalizeShellColors(
                is_array($this->shell_colors) ? $this->shell_colors : null
            ),
        ];
    }
}
