<?php

namespace Tests\Feature;

use App\Models\Comercio;
use App\Models\Mensajero;
use App\Models\Ruta;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
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

    private function ruta(string $nombre = '1'): Ruta
    {
        return Ruta::create(['nombre' => $nombre]);
    }

    public function test_la_columna_generada_normaliza_mayusculas_y_espacios(): void
    {
        $comercio = Comercio::create([
            'nombre' => "  Cobo   Family,\tS.L. ",
            'ruta_id' => $this->ruta()->id,
        ]);

        // El nombre se guarda tal cual (el contrato lo sirve "sin normalizar").
        $this->assertSame("  Cobo   Family,\tS.L. ", $comercio->nombre);
        $this->assertSame('COBO FAMILY, S.L.', $comercio->refresh()->nombre_normalizado);
    }

    public function test_no_admite_dos_comercios_que_solo_difieran_en_mayusculas(): void
    {
        $ruta = $this->ruta();
        Comercio::create(['nombre' => 'Zona Joven', 'ruta_id' => $ruta->id]);

        $this->expectException(QueryException::class);
        Comercio::create(['nombre' => 'ZONA  JOVEN', 'ruta_id' => $ruta->id]);
    }

    public function test_el_codigo_es_unico_pero_admite_varios_nulos(): void
    {
        $ruta = $this->ruta();

        // Los 11 comercios sin código del maestro (§3) tienen que caber todos.
        Comercio::create(['nombre' => 'Sin código A', 'ruta_id' => $ruta->id]);
        Comercio::create(['nombre' => 'Sin código B', 'ruta_id' => $ruta->id]);
        $this->assertSame(2, Comercio::whereNull('codigo')->count());

        Comercio::create(['nombre' => 'Good Id S.L', 'codigo' => 287, 'ruta_id' => $ruta->id]);

        $this->expectException(QueryException::class);
        Comercio::create(['nombre' => 'Otro cualquiera', 'codigo' => 287, 'ruta_id' => $ruta->id]);
    }

    // --- Lo que motivó meter `rutas`: la ruta sobrevive al mensajero ---------

    public function test_dar_de_baja_al_mensajero_no_toca_la_ruta_ni_sus_comercios(): void
    {
        $ruta = $this->ruta('3');
        Mensajero::create(['nombre' => 'Freddy GLS', 'ruta_id' => $ruta->id]);
        Comercio::create(['nombre' => 'Cobo Family, S.L.', 'ruta_id' => $ruta->id]);

        Mensajero::where('nombre', 'Freddy GLS')->delete();

        $this->assertSame(1, $ruta->refresh()->comercios()->count());
        $this->assertNull($ruta->mensajero);

        // Y entra otro en su lugar sin tocar un solo comercio.
        Mensajero::create(['nombre' => 'Nuevo GLS', 'ruta_id' => $ruta->id]);
        $this->assertSame('Nuevo GLS', $ruta->refresh()->mensajero->nombre);
        $this->assertSame(1, $ruta->comercios()->count());
    }

    public function test_una_ruta_no_admite_dos_mensajeros(): void
    {
        $ruta = $this->ruta();
        Mensajero::create(['nombre' => 'Benjamin GLS', 'ruta_id' => $ruta->id]);

        // Si no, el `mensajero` del contrato quedaría ambiguo (§3).
        $this->expectException(QueryException::class);
        Mensajero::create(['nombre' => 'BORJA GONZALEZ', 'ruta_id' => $ruta->id]);
    }

    public function test_varios_mensajeros_pueden_estar_sin_ruta(): void
    {
        Mensajero::create(['nombre' => 'Recién llegado']);
        Mensajero::create(['nombre' => 'Otro más']);

        $this->assertSame(2, Mensajero::whereNull('ruta_id')->count());
    }

    public function test_borrar_una_ruta_con_comercios_esta_prohibido(): void
    {
        $ruta = $this->ruta();
        Comercio::create(['nombre' => 'Bohochique', 'ruta_id' => $ruta->id]);

        // Con borrado pasivo la FK no se dispara: lo corta el modelo. Si no,
        // el comercio se quedaría apuntando a una ruta invisible.
        $this->expectException(RuntimeException::class);
        $ruta->delete();
    }

    public function test_una_ruta_vacia_si_se_puede_dar_de_baja(): void
    {
        $ruta = $this->ruta();
        $comercio = Comercio::create(['nombre' => 'Bohochique', 'ruta_id' => $ruta->id]);

        $comercio->delete();
        $ruta->delete();

        $this->assertSoftDeleted($ruta);
        $this->assertSame(0, Ruta::count());
        $this->assertSame(1, Ruta::withTrashed()->count());
    }

    // --- Borrados pasivos ---------------------------------------------------

    public function test_dar_de_baja_un_comercio_lo_saca_del_maestro_sin_perderlo(): void
    {
        $ruta = $this->ruta();
        $comercio = Comercio::create(['nombre' => 'Zona Joven', 'ruta_id' => $ruta->id]);

        $comercio->delete();

        $this->assertSoftDeleted($comercio);
        $this->assertSame(0, $ruta->comercios()->count());
        $this->assertSame(1, $ruta->comercios()->withTrashed()->count());

        $comercio->restore();
        $this->assertSame(1, $ruta->comercios()->count());
    }

    public function test_el_nombre_de_un_comercio_dado_de_baja_queda_libre(): void
    {
        $ruta = $this->ruta();
        Comercio::create(['nombre' => 'Zona Joven', 'codigo' => 287, 'ruta_id' => $ruta->id])->delete();

        // Con un único normal esto reventaría: la fila borrada seguiría
        // ocupando el nombre y el código. El índice parcial lo evita.
        $nuevo = Comercio::create(['nombre' => 'Zona Joven', 'codigo' => 287, 'ruta_id' => $ruta->id]);

        $this->assertTrue($nuevo->exists);
        $this->assertSame(1, Comercio::count());
        $this->assertSame(2, Comercio::withTrashed()->count());
    }

    public function test_el_sustituto_hereda_la_ruta_del_mensajero_dado_de_baja(): void
    {
        $ruta = $this->ruta('3');
        $saliente = Mensajero::create(['nombre' => 'Freddy GLS', 'ruta_id' => $ruta->id]);
        Comercio::create(['nombre' => 'Cobo Family, S.L.', 'ruta_id' => $ruta->id]);

        $saliente->delete();

        // Esto es lo que rompería un índice único normal: la fila del saliente
        // seguiría ocupando ruta_id y el sustituto no podría entrar.
        $entrante = Mensajero::create(['nombre' => 'Nuevo GLS', 'ruta_id' => $ruta->id]);

        $this->assertTrue($entrante->exists);
        $this->assertSame('Nuevo GLS', $ruta->refresh()->mensajero->nombre);
        $this->assertSame(1, $ruta->comercios()->count());
    }

    public function test_la_validacion_no_choca_con_registros_dados_de_baja(): void
    {
        $ruta = $this->ruta();
        Comercio::create(['nombre' => 'Zona Joven', 'ruta_id' => $ruta->id])->delete();

        $validador = Validator::make(
            ['nombre' => 'Zona Joven', 'ruta_id' => $ruta->id],
            Comercio::reglas(),
        );

        $this->assertFalse($validador->fails(), (string) $validador->errors());
    }

    public function test_no_se_puede_asignar_un_comercio_a_una_ruta_dada_de_baja(): void
    {
        $ruta = $this->ruta();
        $ruta->delete();

        $validador = Validator::make(
            ['nombre' => 'Comercio nuevo', 'ruta_id' => $ruta->id],
            Comercio::reglas(),
        );

        $this->assertTrue($validador->fails());
        $this->assertTrue($validador->errors()->has('ruta_id'));
    }

    public function test_el_mensajero_de_un_comercio_sale_de_su_ruta(): void
    {
        $ruta = $this->ruta('5');
        Mensajero::create(['nombre' => 'Pepe Rodriguez', 'ruta_id' => $ruta->id]);
        $comercio = Comercio::create(['nombre' => 'Vintax', 'ruta_id' => $ruta->id]);

        $this->assertSame('Pepe Rodriguez', $comercio->mensajero->nombre);
        $this->assertSame('5', $comercio->ruta->nombre);
        $this->assertSame(1, Mensajero::first()->comercios()->count());
    }

    public function test_una_ruta_sin_mensajero_sigue_teniendo_sus_comercios(): void
    {
        $ruta = $this->ruta('6');
        $comercio = Comercio::create(['nombre' => 'Ledme', 'ruta_id' => $ruta->id]);

        $this->assertNull($comercio->mensajero);
        $this->assertSame(1, $ruta->comercios()->count());
    }

    // --- Validación ---------------------------------------------------------

    public function test_la_validacion_exige_nombre_y_ruta_existente(): void
    {
        $errores = Validator::make(
            ['nombre' => '', 'ruta_id' => 9999],
            Comercio::reglas(),
        )->errors();

        $this->assertTrue($errores->has('nombre'));
        $this->assertTrue($errores->has('ruta_id'));
    }

    public function test_la_validacion_caza_el_duplicado_por_mayusculas(): void
    {
        $ruta = $this->ruta();
        Comercio::create(['nombre' => 'Zona Joven', 'ruta_id' => $ruta->id]);

        $validador = Validator::make(
            ['nombre' => 'zona joven', 'ruta_id' => $ruta->id],
            Comercio::reglas(),
        );

        $this->assertTrue($validador->fails());
        $this->assertSame('Ya existe un comercio con ese nombre.', $validador->errors()->first('nombre'));
    }

    public function test_al_editar_no_choca_consigo_mismo(): void
    {
        $ruta = $this->ruta();
        $comercio = Comercio::create(['nombre' => 'Zona Joven', 'codigo' => 287, 'ruta_id' => $ruta->id]);

        $validador = Validator::make(
            ['nombre' => 'ZONA JOVEN', 'codigo' => 287, 'ruta_id' => $ruta->id],
            Comercio::reglas($comercio->id),
        );

        $this->assertFalse($validador->fails(), (string) $validador->errors());
    }

    public function test_la_validacion_impide_asignar_una_ruta_ya_ocupada(): void
    {
        $ruta = $this->ruta();
        Mensajero::create(['nombre' => 'Benjamin GLS', 'ruta_id' => $ruta->id]);

        $validador = Validator::make(
            ['nombre' => 'BORJA GONZALEZ', 'ruta_id' => $ruta->id],
            Mensajero::reglas(),
        );

        $this->assertTrue($validador->fails());
        $this->assertTrue($validador->errors()->has('ruta_id'));
    }
}
