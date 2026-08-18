<?php

namespace App\Support;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\PickupRoute;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Traduce una entrada de `audit_logs` a algo que se pueda leer.
 *
 * Vive aparte porque lo usan dos sitios: el modal de la ficha de cada registro
 * y el listado de auditoría (§7, fase 3 y módulo 6). Tenerlo en el trait de los
 * CRUD lo dejaba fuera del alcance del listado y obligaba a duplicarlo.
 *
 * Las rutas se cargan una sola vez al construirlo: sin eso, pintar 10 entradas
 * son 10 consultas.
 */
class AuditPresenter
{
    /** @var array<string, string> */
    private const MODULOS = [
        PickupRoute::class => 'Rutas',
        Courier::class => 'UT',
        Merchant::class => 'Comercios',
        User::class => 'Usuarios',
        Role::class => 'Roles y permisos',
        Setting::class => 'Configuraciones',
    ];

    /** @var array<string, string> */
    private const CAMPOS = [
        'name' => 'Nombre',
        'last_name' => 'Apellidos',
        'code' => 'Código',
        'maximum_volume' => 'Volumen máximo (m³)',
        'pickup_route_id' => 'Ruta',
        'email' => 'Correo',

        // Tampoco es una columna: lo escribe `Role::recordPermissionChange()`.
        'permissions' => 'Permisos',

        // No es una columna de `users`: lo escribe `User::recordRoleChange()`
        // (§7, fase 12), porque el rol vive en la tabla pivote del paquete y
        // los eventos de Eloquent no lo ven.
        'role' => 'Rol',
        'email_verified_at' => 'Correo verificado',
        // De `settings`: la fila ya dice qué parámetro es en su nombre, así que
        // la columna sólo tiene que decir qué valor tomó.
        'value' => 'Valor',
        'module' => 'Módulo',
        'key' => 'Parámetro',
    ];

    private function __construct(private Collection $rutas) {}

    public static function make(): self
    {
        // withTrashed: el historial de algo movido a una ruta que luego se dio
        // de baja tiene que seguir leyéndose.
        return new self(PickupRoute::withTrashed()->pluck('name', 'id'));
    }

    /**
     * @return array{action: AuditAction, author: string, at: Carbon, module: string, record: string, changes: list<array{label: string, before: string, after: string}>}
     */
    public function entry(AuditLog $log): array
    {
        return [
            'action' => $log->action,
            'author' => $log->authorName(),
            'at' => $log->created_at,
            'module' => $this->module($log),
            'record' => $this->record($log),
            'changes' => $this->changes($log->before, $log->after),
        ];
    }

    public function module(AuditLog $log): string
    {
        return self::MODULOS[$log->auditable_type] ?? class_basename($log->auditable_type);
    }

    /**
     * Cómo se llamaba el registro.
     *
     * Se tira primero del propio volcado y sólo después de la relación: así la
     * fila sigue siendo legible aunque el registro se haya borrado del todo,
     * que es la misma razón por la que `user_email` está desnormalizado (§4).
     */
    public function record(AuditLog $log): string
    {
        return $log->after['name']
            ?? $log->before['name']
            ?? $log->auditable?->name
            ?? "#{$log->auditable_id}";
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @return list<array{label: string, before: string, after: string}>
     */
    public function changes(?array $before, ?array $after): array
    {
        // `id` no le dice nada a nadie, y en un alta viene en el volcado entero.
        $campos = array_diff(array_keys(($after ?? []) + ($before ?? [])), ['id']);

        $cambios = [];

        foreach ($campos as $campo) {
            $cambios[] = [
                'label' => self::CAMPOS[$campo] ?? $campo,
                'before' => $this->value($campo, $before[$campo] ?? null),
                'after' => $this->value($campo, $after[$campo] ?? null),
            ];
        }

        return $cambios;
    }

    /** Un id de ruta no se enseña en crudo: se enseña su nombre. */
    private function value(string $campo, mixed $valor): string
    {
        if ($valor === null || $valor === '') {
            return '—';
        }

        if ($campo === 'pickup_route_id') {
            return $this->rutas[$valor] ?? "ruta #{$valor}";
        }

        if (is_bool($valor)) {
            return $valor ? 'Sí' : 'No';
        }

        return (string) $valor;
    }
}
