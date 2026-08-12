<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        // Necesita database/seeders/data/comercios.csv, que no está en el repo
        // por confidencialidad (CONTEXTO.md §9). Sin él, revienta y lo explica.
        $this->call(MaestroRutasSeeder::class);
    }
}
