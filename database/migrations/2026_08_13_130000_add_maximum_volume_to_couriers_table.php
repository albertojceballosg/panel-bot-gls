<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuánto cabe en la furgoneta, en metros cúbicos (CONTEXTO.md §4).
 *
 * `decimal(8,3)` y no otra cosa para que sea **la misma unidad y la misma precisión** que
 * `run_packages.volume_m3` (§3): el día que se quiera contrastar lo que una ruta arrastró
 * contra lo que su furgoneta admite, la comparación es directa y no hay conversión donde
 * equivocarse.
 *
 * **Nulo cuando no se sabe.** Igual que el volumen del envío, un nulo aquí significa «nadie
 * ha declarado la capacidad de esta furgoneta», no «no cabe nada»: las UT que ya existen
 * entran sin capacidad y ponerles cero convertiría un dato que falta en un dato falso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('couriers', function (Blueprint $table) {
            $table->decimal('maximum_volume', 8, 3)->nullable()->after('pickup_route_id');
        });
    }

    public function down(): void
    {
        Schema::table('couriers', function (Blueprint $table) {
            $table->dropColumn('maximum_volume');
        });
    }
};
