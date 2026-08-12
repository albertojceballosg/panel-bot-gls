<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El mensajero es quien conduce una ruta hoy, no parte de su definición
     * (CONTEXTO.md §4). De ahí que la FK viva aquí y no al revés: el mensajero
     * se puede quedar sin ruta, o desaparecer, y la ruta sigue existiendo con
     * todos sus comercios.
     */
    public function up(): void
    {
        Schema::create('couriers', function (Blueprint $table) {
            $table->id();
            $table->string('name');

            // Nullable: un mensajero recién dado de alta puede no tener ruta
            // asignada todavía.
            //
            // nullOnDelete: borrar la ruta a lo bruto no debe borrar a la
            // persona. Con borrado pasivo esto casi nunca llega a dispararse,
            // pero cubre el forceDelete.
            $table->foreignId('pickup_route_id')
                ->nullable()
                ->constrained('pickup_routes')
                ->nullOnDelete();

            $table->softDeletes();
            $table->timestamps();
        });

        // Ambos únicos sólo entre los vivos: si contasen a los dados de baja,
        // el sustituto de un mensajero no podría heredar ni su nombre ni su
        // ruta, que es exactamente el caso de uso que motivó tener
        // `pickup_routes`.
        DB::statement('CREATE UNIQUE INDEX couriers_name_unique ON couriers (name) WHERE deleted_at IS NULL');

        // Una ruta la lleva un solo mensajero: el contrato sirve un único
        // `mensajero` por comercio (§3), así que dos la dejarían ambigua. El
        // índice parcial además ignora los NULL, de modo que puede haber
        // varios mensajeros sin ruta asignada.
        DB::statement('CREATE UNIQUE INDEX couriers_pickup_route_id_unique ON couriers (pickup_route_id) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('couriers');
    }
};
