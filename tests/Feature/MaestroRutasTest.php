<?php

namespace Tests\Feature;

use App\Models\Comercio;
use App\Models\Mensajero;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Los guardarraíles del modelo de datos (CONTEXTO.md §4).
 *
 * No sirven de adorno: el panel no puede romper al bot, pero sí puede hacerle
 * producir un informe equivocado en silencio (§3), y estos invariantes son lo
 * que lo impide.
 */
class MaestroRutasTest extends TestCase
{
    use RefreshDatabase;

    private function mensajero(string $nombre = 'Benjamin GLS', ?int $ruta = 1): Mensajero
    {
        return Mensajero::create(['nombre' => $nombre, 'ruta' => $ruta]);
    }

    public function test_la_columna_generada_normaliza_mayusculas_y_espacios(): void
    {
        $comercio = Comercio::create([
            'nombre' => "  Cobo   Family,\tS.L. ",
            'mensajero_id' => $this->mensajero()->id,
        ]);

        // El nombre se guarda tal cual (el contrato lo sirve "sin normalizar").
        $this->assertSame("  Cobo   Family,\tS.L. ", $comercio->nombre);
        $this->assertSame('COBO FAMILY, S.L.', $comercio->refresh()->nombre_normalizado);
    }

    public function test_no_admite_dos_comercios_que_solo_difieran_en_mayusculas(): void
    {
        $mensajero = $this->mensajero();
        Comercio::create(['nombre' => 'Zona Joven', 'mensajero_id' => $mensajero->id]);

        $this->expectException(QueryException::class);
        Comercio::create(['nombre' => 'ZONA  JOVEN', 'mensajero_id' => $mensajero->id]);
    }

    public function test_el_codigo_es_unico_pero_admite_varios_nulos(): void
    {
        $mensajero = $this->mensajero();

        // Los 11 comercios sin código del maestro (§3) tienen que caber todos.
        Comercio::create(['nombre' => 'Sin código A', 'mensajero_id' => $mensajero->id]);
        Comercio::create(['nombre' => 'Sin código B', 'mensajero_id' => $mensajero->id]);
        $this->assertSame(2, Comercio::whereNull('codigo')->count());

        Comercio::create(['nombre' => 'Good Id S.L', 'codigo' => 287, 'mensajero_id' => $mensajero->id]);

        $this->expectException(QueryException::class);
        Comercio::create(['nombre' => 'Otro cualquiera', 'codigo' => 287, 'mensajero_id' => $mensajero->id]);
    }

    public function test_borrar_un_mensajero_con_comercios_esta_prohibido(): void
    {
        $mensajero = $this->mensajero();
        Comercio::create(['nombre' => 'Bohochique', 'mensajero_id' => $mensajero->id]);

        $this->expectException(QueryException::class);
        $mensajero->delete();
    }

    public function test_un_mensajero_tiene_muchos_comercios(): void
    {
        $mensajero = $this->mensajero();
        Comercio::create(['nombre' => 'Uno', 'mensajero_id' => $mensajero->id]);
        Comercio::create(['nombre' => 'Dos', 'mensajero_id' => $mensajero->id]);

        $this->assertCount(2, $mensajero->comercios);
        $this->assertTrue($mensajero->is(Comercio::first()->mensajero));
    }

    public function test_la_ruta_del_mensajero_puede_ser_nula(): void
    {
        // El contrato admite `ruta: null` para un mensajero sin número asignado.
        $this->assertNull($this->mensajero('Nuevo GLS', null)->ruta);
    }

    /** Las reglas de validación de la fase 1, que reutilizará el CRUD. */
    public function test_la_validacion_exige_nombre_y_mensajero_existente(): void
    {
        $errores = Validator::make(
            ['nombre' => '', 'mensajero_id' => 9999],
            Comercio::reglas(),
        )->errors();

        $this->assertTrue($errores->has('nombre'));
        $this->assertTrue($errores->has('mensajero_id'));
    }

    public function test_la_validacion_caza_el_duplicado_por_mayusculas(): void
    {
        $mensajero = $this->mensajero();
        Comercio::create(['nombre' => 'Zona Joven', 'mensajero_id' => $mensajero->id]);

        $validador = Validator::make(
            ['nombre' => 'zona joven', 'mensajero_id' => $mensajero->id],
            Comercio::reglas(),
        );

        $this->assertTrue($validador->fails());
        $this->assertSame('Ya existe un comercio con ese nombre.', $validador->errors()->first('nombre'));
    }

    public function test_al_editar_no_choca_consigo_mismo(): void
    {
        $mensajero = $this->mensajero();
        $comercio = Comercio::create(['nombre' => 'Zona Joven', 'codigo' => 287, 'mensajero_id' => $mensajero->id]);

        $validador = Validator::make(
            ['nombre' => 'ZONA JOVEN', 'codigo' => 287, 'mensajero_id' => $mensajero->id],
            Comercio::reglas($comercio->id),
        );

        $this->assertFalse($validador->fails(), (string) $validador->errors());
    }
}
