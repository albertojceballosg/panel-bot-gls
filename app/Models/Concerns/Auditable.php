<?php

namespace App\Models\Concerns;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;

/**
 * Registra en `audit_logs` lo que le pasa al modelo (CONTEXTO.md §4).
 *
 * Se engancha a los eventos de Eloquent, así que da igual desde dónde venga el
 * cambio: panel, tinker o un comando. No hay que acordarse de llamar a nada.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (self $model) {
            $model->writeAudit(AuditAction::Create, null, $model->auditableSnapshot());
        });

        static::updated(function (self $model) {
            // Sólo lo que cambió, no el registro entero: así la vista puede
            // pintar "Campo / Antes / Después" sin filtrar nada.
            $after = $model->withoutExcluded($model->getChanges());

            // Un guardado que no cambia nada no ensucia el historial. En un
            // formulario esto pasa constantemente.
            //
            // De paso resuelve el restore: `restore()` llama a `save()`, así que
            // dispara `updated` además de `restored`. Como `deleted_at` está
            // excluido, ese update se queda vacío y no genera una fila de más.
            if ($after === []) {
                return;
            }

            $before = Arr::only($model->getRawOriginal(), array_keys($after));

            $model->writeAudit(AuditAction::Update, $before, $after);
        });

        static::deleted(function (self $model) {
            // El registro completo: es lo último que se sabe de él.
            $model->writeAudit(AuditAction::Delete, $model->auditableSnapshot(), null);
        });

        // Sólo si el modelo se da de baja en pasivo. `restored` lo aporta
        // `SoftDeletes`, y registrarlo en un modelo que no lo usa —`Setting`, que
        // no se da de baja: se cambia— revienta durante el arranque del propio
        // modelo, con un error que no menciona ni la auditoría ni el evento.
        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            static::restored(function (self $model) {
                $model->writeAudit(AuditAction::Restore, null, $model->auditableSnapshot());
            });
        }
    }

    /** El historial de este registro, del cambio más reciente al más antiguo. */
    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'auditable')->latest('created_at')->latest('id');
    }

    /**
     * Campos propios del modelo que no deben entrar al historial. Se
     * sobrescribe donde haga falta; es un método y no una propiedad porque PHP
     * no deja redeclarar una propiedad de trait con otro valor por defecto.
     *
     * @return list<string>
     */
    protected function auditExclude(): array
    {
        return [];
    }

    /**
     * Campos fuera del historial.
     *
     * Los `$hidden` del modelo entran aquí a propósito: lo que no se expone en
     * un JSON tampoco debe acabar copiado en una tabla que no se borra nunca.
     * Es lo que mantiene el hash de la contraseña y el `remember_token` de
     * `User` fuera del historial sin una segunda lista que mantener.
     *
     * @return list<string>
     */
    protected function auditExcludedFields(): array
    {
        return array_values(array_unique(array_merge(
            [$this->getCreatedAtColumn(), $this->getUpdatedAtColumn(), 'deleted_at'],
            $this->getHidden(),
            $this->auditExclude(),
        )));
    }

    /** @return array<string, mixed> */
    protected function auditableSnapshot(): array
    {
        return $this->withoutExcluded($this->getAttributes());
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function withoutExcluded(array $attributes): array
    {
        return Arr::except($attributes, $this->auditExcludedFields());
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    protected function writeAudit(AuditAction $action, ?array $before, ?array $after): void
    {
        if (! AuditLog::$recording) {
            return;
        }

        // Fuera de una sesión —seeder, consola, cron— no hay autor y se queda
        // como "Sistema". Es preferible a inventarse uno.
        $user = Auth::user();

        // Sin try/catch a propósito: si el historial no se puede escribir, el
        // cambio no debe darse por hecho. Un maestro que cambia sin dejar
        // rastro es justo el fallo silencioso que §3 llama peor que romperse.
        $this->auditLogs()->create([
            'user_id' => $user?->getKey(),
            'user_email' => $user?->email,
            'action' => $action,
            'before' => $before,
            'after' => $after,
        ]);
    }
}
