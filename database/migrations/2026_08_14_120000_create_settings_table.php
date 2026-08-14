<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parámetros que el cliente ajusta por módulo (CONTEXTO.md §7, fase 11).
 *
 * **Una fila por parámetro**, no una columna por parámetro ni un JSON por
 * módulo. Las tres opciones se pensaron:
 *
 * - Una tabla por módulo, con sus columnas tipadas, es lo que hace el resto del
 *   esquema (§4) y sería lo coherente… si esto fuera negocio. No lo es: son
 *   ajustes de pantalla, y cada nuevo umbral costaría una migración.
 * - Un `jsonb` por módulo deja el historial ilegible: `audit_logs` enseñaría un
 *   volcado entero donde cambió un número, y la pantalla de auditoría (§4) vive
 *   de decir «Campo / Antes / Después».
 * - Fila por parámetro: el historial sale gratis y bien —una entrada por
 *   parámetro que cambió, y sólo por los que cambiaron— y añadir uno nuevo es
 *   una línea en `SettingsCatalog`.
 *
 * Lo que se paga: los valores viajan como texto y el tipo lo pone el catálogo.
 * Es asumible porque **nada de esto lo escribe una máquina**: lo teclea una
 * persona en un formulario que valida antes de guardar.
 *
 * No hay valores por defecto en la base a propósito: los defectos viven en el
 * catálogo, así que una tabla vacía es un panel perfectamente configurado y no
 * hay que sembrar nada al desplegar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();

            // El módulo al que ajusta, en la clave del catálogo:
            // `capacity-calendar`. No es una FK a nada — los módulos son código,
            // no filas.
            $table->string('module');
            $table->string('key');

            // Texto: el tipo lo declara el catálogo y lo valida el formulario.
            // Nullable porque un parámetro puede vaciarse para volver al valor
            // por defecto sin dejar una fila mintiendo con una cadena vacía.
            $table->text('value')->nullable();

            $table->timestamps();

            $table->unique(['module', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
