<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Antes que el usuario inicial: sin los roles sembrados no habría
        // ninguno que darle (§7, fase 12).
        $this->call(RolesAndPermissionsSeeder::class);

        // Credenciales desde el .env, nunca desde el repo (CONTEXTO.md §10).
        $this->call(InitialUserSeeder::class);

        // Necesita database/seeders/data/merchants.csv, que no está en el repo
        // por confidencialidad (CONTEXTO.md §9). Sin él, revienta y lo explica.
        $this->call(RouteMasterSeeder::class);
    }
}
