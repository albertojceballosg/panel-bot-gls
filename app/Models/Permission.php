<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * Un permiso del panel (CONTEXTO.md §7, fase 12).
 *
 * Extiende el del paquete por dos cosas: la **descripción** en castellano que
 * lee el formulario de un rol, y el **historial** de §4 —crear un permiso o
 * borrarlo cambia lo que la aplicación es capaz de comprobar, y eso no puede
 * pasar sin rastro. `config/permission.php` apunta aquí.
 *
 * **Los del catálogo son del código, no del cliente**: `PermissionCatalog` los
 * siembra y la pantalla no deja renombrarlos ni borrarlos, porque su nombre está
 * escrito en `routes/web.php` y en las pantallas. Los que se crean a mano sí son
 * suyos enteros.
 */
class Permission extends SpatiePermission
{
    use Auditable;

    /** El `guard_name` siempre es 'web' y sólo ensuciaría el historial. */
    protected function auditExclude(): array
    {
        return ['guard_name'];
    }

    /** El módulo al que pertenece: lo que va antes del punto. */
    public function module(): string
    {
        return str_contains($this->name, '.') ? strstr($this->name, '.', true) : $this->name;
    }
}
