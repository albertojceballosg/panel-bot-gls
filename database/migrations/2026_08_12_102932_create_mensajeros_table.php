<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La ruta cuelga del mensajero, no del comercio (CONTEXTO.md §4): los seis
     * mensajeros del maestro tienen exactamente una ruta cada uno. Si `ruta`
     * fuese columna de `comercios`, dos comercios del mismo mensajero podrían
     * acabar en rutas distintas — un error que el bot no puede detectar y que
     * le haría producir un informe equivocado sin avisar.
     */
    public function up(): void
    {
        Schema::create('mensajeros', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->unique();

            // Nullable porque el contrato (§3) admite `ruta: null` para un
            // mensajero al que todavía no le han asignado número.
            $table->integer('ruta')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mensajeros');
    }
};
