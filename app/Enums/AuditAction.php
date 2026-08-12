<?php

namespace App\Enums;

/**
 * Qué se le hizo a un registro.
 *
 * `Restore` es propia y no se guarda como `Create` —que es lo que hacen otras
 * implementaciones— porque aquí el borrado pasivo es ciudadano de primera:
 * distinguir "se dio de alta" de "se reactivó" es información real.
 */
enum AuditAction: string
{
    case Create = 'CREATE';
    case Update = 'UPDATE';
    case Delete = 'DELETE';
    case Restore = 'RESTORE';

    /** Etiqueta de cara al usuario. */
    public function label(): string
    {
        return match ($this) {
            self::Create => 'Alta',
            self::Update => 'Modificación',
            self::Delete => 'Baja',
            self::Restore => 'Reactivación',
        };
    }
}
