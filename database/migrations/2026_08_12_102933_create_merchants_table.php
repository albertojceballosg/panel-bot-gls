<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchants', function (Blueprint $table) {
            $table->id();

            // Tal cual viene del maestro: es lo que sirve el JSON del contrato
            // (§3, "sin normalizar"). Normalizarlo es cosa del bot.
            $table->string('name');

            // Postgres es case-sensitive, así que la unicidad del nombre hay que
            // hacerla explícita. Al ser columna generada se mantiene sola y no
            // se puede desincronizar del nombre.
            //
            // Alcance deliberadamente limitado (§4): es un guardarraíl contra
            // duplicados evidentes en el panel, NO un sustituto del cruce del
            // bot, que además quita sufijos (S.L / SLU) y hace fuzzy. Duplicar
            // esa lógica aquí sería tenerla en dos repos y que se separen.
            $table->string('normalized_name')
                ->storedAs("upper(regexp_replace(trim(name), '\\s+', ' ', 'g'))");

            // El SourceDepartment del portal. Opcional: 11 de los 93 comercios
            // no lo tienen (§3).
            $table->integer('code')->nullable();

            // El comercio pertenece a la RUTA, no al mensajero. Es lo que hace
            // que cambiar de mensajero no toque el maestro: la ruta y sus
            // comercios sobreviven a la persona que la conducía (§4).
            //
            // Obligatoria: un comercio sin ruta no se puede agrupar, y el bot
            // rechaza los que vienen sin ella (§3).
            //
            // restrictOnDelete: con borrado pasivo esta FK sólo se dispara ante
            // un forceDelete. El caso normal lo corta el modelo, que se niega a
            // borrar una ruta que todavía tiene comercios vivos.
            $table->foreignId('route_id')
                ->constrained('routes')
                ->restrictOnDelete();

            $table->softDeletes();
            $table->timestamps();
        });

        // Únicos sólo entre los vivos: dar de baja un comercio tiene que
        // liberar su nombre y su código para poder volver a darlo de alta.
        DB::statement('CREATE UNIQUE INDEX merchants_normalized_name_unique ON merchants (normalized_name) WHERE deleted_at IS NULL');

        // En Postgres un índice único ya deja pasar varios NULL, que es lo que
        // pide "único cuando no es nulo"; el WHERE sólo añade lo de los vivos.
        DB::statement('CREATE UNIQUE INDEX merchants_code_unique ON merchants (code) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('merchants');
    }
};
