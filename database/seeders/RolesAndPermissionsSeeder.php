<?php

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Siembra los permisos y los roles del catálogo (CONTEXTO.md §7, fase 12).
 *
 * **Es idempotente y hay que volver a pasarlo cada vez que crezca
 * `PermissionCatalog`**: los permisos son datos, no esquema, y meterlos en una
 * migración obligaría a una migración nueva por cada pantalla que se añada.
 *
 * **Sólo se sincronizan los permisos del Administrador.** Ese rol se define como
 * «todo lo que exista», así que un módulo nuevo tiene que entrarle solo. Los
 * demás roles se siembran **la primera vez y nunca más**: desde que hay pantalla
 * de roles (§7, fase 12) sus permisos los decide el cliente, y volver a pasar el
 * seeder no puede deshacerle un cambio hecho a mano.
 *
 * Los roles llevan nombre en castellano porque se leen en la pantalla de
 * usuarios; las claves de los permisos, en inglés como el resto del código.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Sin esto, el registrar sirve desde su caché lo que había antes de
        // sembrar y el `syncPermissions` de abajo trabaja a ciegas.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Sembrar el catálogo no es el cambio de nadie, y aquí no hay sesión de
        // la que sacar un autor: sin esto, cada despliegue dejaría en el
        // historial (§4) un puñado de entradas firmadas por «Sistema» tapando
        // los cambios de verdad. Lo que se toca desde la pantalla sí se anota.
        $sinRol = AuditLog::withoutRecording(function () {
            // `updateOrCreate` y no `findOrCreate`: la descripción vive ahora
            // en la tabla (ver su migración) y el catálogo es quien la manda
            // para los permisos del código, así que se refresca en cada pasada.
            foreach (PermissionCatalog::all() as $permiso => $descripcion) {
                Permission::updateOrCreate(
                    ['name' => $permiso, 'guard_name' => 'web'],
                    ['description' => $descripcion],
                );
            }

            // El Administrador se define como «todos los permisos», y desde que
            // se pueden crear a mano (§7, fase 12) eso incluye los que no están
            // en el catálogo: con la lista del catálogo, el seeder le quitaría
            // los creados desde la pantalla en cada despliegue.
            $todos = Permission::where('guard_name', 'web')->pluck('name')->all();

            foreach (PermissionCatalog::roles() as $nombre => $permisos) {
                $permisos = $nombre === PermissionCatalog::ROLE_ADMIN ? $todos : $permisos;

                $rol = Role::where('name', $nombre)->where('guard_name', 'web')->first();

                // Nuevo: nace con lo que dice el catálogo.
                if ($rol === null) {
                    Role::create(['name' => $nombre, 'guard_name' => 'web'])->syncPermissions($permisos);

                    continue;
                }

                // Ya existía: sólo el Administrador se resincroniza, porque su
                // definición es «todos los permisos» y, si no, los de un módulo
                // nuevo nacerían sin que nadie pudiera usarlos. Los demás son ya
                // cosa del cliente desde que hay pantalla de roles.
                if ($nombre === PermissionCatalog::ROLE_ADMIN) {
                    $rol->syncPermissions($permisos);
                }
            }

            // Las cuentas que ya existían se quedan como estaban:
            // administradoras. Antes de esto el panel no tenía roles y
            // cualquiera entraba a todo (§10), así que lo contrario —dejarlas
            // sin rol— sería echar del panel al equipo entero en el despliegue.
            //
            // Sólo alcanza a quien no tiene ninguno, para que volver a pasarlo
            // no le devuelva el Administrador a quien alguien acaba de bajar a
            // Operaciones.
            $sinRol = User::withTrashed()->doesntHave('roles')->get();

            foreach ($sinRol as $usuario) {
                $usuario->assignRole(PermissionCatalog::ROLE_ADMIN);
            }

            return $sinRol;
        });

        $this->command?->info(sprintf(
            '  Roles listos: %s. Cuentas que heredan Administrador: %d.',
            implode(', ', PermissionCatalog::roleNames()),
            $sinRol->count(),
        ));
    }
}
