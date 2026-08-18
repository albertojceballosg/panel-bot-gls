<?php

namespace Tests;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Los permisos y los roles del catálogo, sembrados antes de cada test que
     * toque la base (§7, fase 12).
     *
     * Sin ellos no hay ningún permiso que conceder y **todas** las pantallas
     * responden 403, con un fallo que no menciona los roles por ninguna parte.
     * `$seed` y `$seeder` los mira `RefreshDatabase`, así que los tests que no
     * usan base —los de `tests/Unit`— no pagan nada.
     */
    protected bool $seed = true;

    protected string $seeder = RolesAndPermissionsSeeder::class;
}
