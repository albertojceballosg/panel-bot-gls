<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comercios', function (Blueprint $table) {
            $table->id();

            // Tal cual viene del maestro: es lo que sirve el JSON del contrato
            // (§3, "sin normalizar"). Normalizarlo es cosa del bot.
            $table->string('nombre');

            // Postgres es case-sensitive, así que la unicidad del nombre hay que
            // hacerla explícita. Al ser columna generada se mantiene sola y no
            // se puede desincronizar del nombre.
            //
            // Alcance deliberadamente limitado (§4): es un guardarraíl contra
            // duplicados evidentes en el panel, NO un sustituto del cruce del
            // bot, que además quita sufijos (S.L / SLU) y hace fuzzy. Duplicar
            // esa lógica aquí sería tenerla en dos repos y que se separen.
            $table->string('nombre_normalizado')
                ->storedAs("upper(regexp_replace(trim(nombre), '\\s+', ' ', 'g'))");
            $table->unique('nombre_normalizado');

            // El SourceDepartment del portal. Opcional: 11 de los 93 comercios
            // no lo tienen (§3). En Postgres un índice único deja pasar varios
            // NULL, que es justo lo que pide "único cuando no es nulo".
            $table->integer('codigo')->nullable()->unique();

            // restrictOnDelete y no cascade: borrar un mensajero no puede
            // llevarse por delante sus comercios en silencio. El maestro que
            // sirve el endpoint es el producto (§2).
            $table->foreignId('mensajero_id')
                ->constrained('mensajeros')
                ->restrictOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comercios');
    }
};
