<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El volumen del envío, en metros cúbicos (CONTEXTO.md §3.1).
 *
 * **Nulo cuando el portal no lo trae.** GLS devuelve `0` para una parte de los envíos —60 de
 * 983 el 03/08— y el bot traduce ese cero a nulo a propósito: un cero aquí no significa "no
 * ocupa nada", significa "no lo sé". Guardarlo como cero falsearía a la baja el volumen de
 * una ruta y, peor, nadie vería que el dato faltaba.
 *
 * De ahí que cualquier suma en la interfaz tenga que decir **sobre cuántos envíos** se hizo.
 *
 * `decimal(8,3)`: los valores reales van de 0,001 a 1,091 m³ por envío, así que tres
 * decimales es la precisión que da el portal y ocho dígitos dejan sitio de sobra para sumar
 * una jornada entera (el 03/08 fueron 28,3 m³ sobre 464 envíos).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('run_packages', function (Blueprint $table) {
            $table->decimal('volume_m3', 8, 3)->nullable()->after('deviation_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('run_packages', function (Blueprint $table) {
            $table->dropColumn('volume_m3');
        });
    }
};
