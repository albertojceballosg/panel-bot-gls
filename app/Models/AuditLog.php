<?php

namespace App\Models;

use App\Enums\AuditAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;

/**
 * Una entrada del historial. Inmutable por diseño.
 *
 * @property AuditAction $action
 * @property array|null $before
 * @property array|null $after
 */
class AuditLog extends Model
{
    /** Sólo `created_at`: ver la migración. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'user_email', 'action', 'auditable_type', 'auditable_id', 'before', 'after',
    ];

    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Interruptor para las escrituras masivas que no son cambios de nadie: los
     * seeders cargan 105 registros y sin esto cada `migrate:fresh --seed`
     * dejaría 105 filas de ruido tapando los cambios de verdad.
     *
     * Es lo único que se silencia. Un cambio hecho desde el panel siempre se
     * registra.
     */
    public static bool $recording = true;

    public static function withoutRecording(callable $callback): mixed
    {
        self::$recording = false;

        try {
            return $callback();
        } finally {
            self::$recording = true;
        }
    }

    /**
     * El historial no se reescribe. Si hiciera falta corregir una entrada, es
     * que el historial no vale para lo que existe.
     */
    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException('Una entrada del historial no se modifica.');
        });

        static::deleting(function () {
            throw new RuntimeException('Una entrada del historial no se borra.');
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** withTrashed: el historial de algo dado de baja tiene que seguir leyéndose. */
    public function auditable(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }

    /** Quién lo hizo, en texto, para pintarlo sin más comprobaciones. */
    public function authorName(): string
    {
        return $this->user?->name ?? $this->user_email ?? 'Sistema';
    }
}
