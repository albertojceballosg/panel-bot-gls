<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué permite cada permiso, en castellano (CONTEXTO.md §7, fase 12).
 *
 * La tabla del paquete sólo guarda `name` —`merchants.manage`—, y con eso el
 * formulario de un rol es una lista de claves que hay que traducir de cabeza.
 * La descripción vivía en `PermissionCatalog`, pero desde que los permisos se
 * pueden crear desde el panel hay dos clases de fila y el catálogo sólo explica
 * una: así que la explicación baja a la base, y el seeder la mantiene al día
 * para los del código.
 *
 * Nullable porque un permiso puede nacer sin ella; la pantalla enseña entonces
 * sólo la clave.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            $table->string('description')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
