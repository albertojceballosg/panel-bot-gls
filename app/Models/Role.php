<?php

namespace App\Models;

use App\Enums\AuditAction;
use App\Models\Concerns\Auditable;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Un rol del panel (CONTEXTO.md §7, fase 12).
 *
 * Extiende el del paquete para una sola cosa: **que los cambios dejen rastro**
 * (§4). Un rol decide quién entra a las copias de seguridad, así que tocarlo sin
 * que quede escrito sería el peor hueco del historial. `config/permission.php`
 * apunta aquí, de modo que el paquete instancia éste en todas partes.
 *
 * No se hereda el `Permission`: los permisos los define el código
 * (`PermissionCatalog`) y no se crean ni se borran desde el panel, así que no
 * hay cambio de nadie que registrar.
 */
class Role extends SpatieRole
{
    use Auditable;

    /** Siempre 'web': la única otra puerta, `GET /api/rutas`, no tiene sesión (§2). */
    protected function auditExclude(): array
    {
        return ['guard_name'];
    }

    /**
     * Deja en el historial el cambio de permisos.
     *
     * Como el rol de un usuario, los permisos de un rol viven en una tabla
     * pivote y los eventos de Eloquent no los ven: sin esto, «quién le dio a
     * este rol el acceso a las copias» no quedaría escrito en ninguna parte.
     *
     * @param  list<string>  $before
     * @param  list<string>  $after
     */
    public function recordPermissionChange(array $before, array $after): void
    {
        sort($before);
        sort($after);

        if ($before === $after) {
            return;
        }

        $this->writeAudit(
            AuditAction::Update,
            ['permissions' => $before === [] ? '—' : implode(', ', $before)],
            ['permissions' => $after === [] ? '—' : implode(', ', $after)],
        );
    }
}
