<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La ruta es la entidad duradera del maestro (CONTEXTO.md §4).
     *
     * Los mensajeros rotan —uno deja la empresa y entra otro— pero la ruta y
     * los comercios que la componen siguen ahí. Por eso la ruta es una tabla
     * propia y no un número suelto colgado del mensajero: si lo fuese, dar de
     * baja al mensajero se llevaría por delante la definición de la ruta.
     */
    public function up(): void
    {
        Schema::create('routes', function (Blueprint $table) {
            $table->id();

            // Nombre libre, renombrable desde el panel. Hoy el maestro las
            // llama "1".."6", pero eso son etiquetas, no identidad: la
            // identidad es el id.
            $table->string('name');

            $table->softDeletes();
            $table->timestamps();
        });

        // Único sólo entre las vivas. Un índice único normal contaría también
        // las borradas, así que un nombre liberado al dar de baja una ruta no
        // se podría reutilizar nunca. Postgres lo resuelve con un índice
        // parcial; Laravel no lo expresa en el Blueprint, de ahí el SQL.
        DB::statement('CREATE UNIQUE INDEX routes_name_unique ON routes (name) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('routes');
    }
};
