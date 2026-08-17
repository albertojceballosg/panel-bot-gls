<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las rutas que descargaban en el mismo bloque que este paquete (payload v3).
 *
 * Va aparte de `compatible_routes` y no en la misma columna porque responden a preguntas
 * de distinta finura, y la pantalla necesita saber cuál de las dos reservas está
 * explicando: `tanda_compartida` habla del bloque de descarga —media hora— y
 * `ventana_compartida` del instante concreto del paquete.
 *
 * Sin esto la pantalla sólo podía decir «dos furgonetas descargaron juntas» sin nombrarlas,
 * y quien leía tenía que ir a buscar cuáles a las alertas de la jornada.
 *
 * Por defecto `[]` y no nulo: las jornadas guardadas antes de la v3 no traían el dato, y
 * una lista vacía se pinta igual que la ausencia sin obligar a distinguir null de vacío en
 * cada vista.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('run_packages', function (Blueprint $table) {
            $table->json('batch_shared_routes')->default('[]')->after('compatible_routes');
        });
    }

    public function down(): void
    {
        Schema::table('run_packages', function (Blueprint $table) {
            $table->dropColumn('batch_shared_routes');
        });
    }
};
