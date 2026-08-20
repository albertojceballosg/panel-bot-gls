<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\PickupRoute;
use App\Models\RunPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * La versión 5 del payload (CONTEXTO.md §3.1, 20/08/2026): `costes_reales`.
 *
 * Un solo campo nuevo —los «Costes reales» que alguien **teclea a mano** en la ficha del
 * envío en Envexpress, o sea lo que a la agencia le cuesta ese envío— y aditivo: una v4
 * sigue entrando igual.
 *
 * Se parece a `ganancia` en casi todo y **se separa en una cosa, que es la que fija este
 * test**: aquí el `0` es un valor real, no un hueco. Hay 7 envíos con un cero tecleado en
 * cinco días, frente a 671 con la ficha vacía. Un `?:` o un `empty()` en el intake
 * convertirían ese cero en nulo y borrarían lo que una persona escribió; nulo tiene que
 * significar sólo «nadie lo rellenó» —o «la corrida es anterior a la v5»—.
 *
 * Y el nulo, como en la v4, **no es cero al sumar**: quien totalice esta columna tiene que
 * decir sobre cuántos envíos, con su propio contador y no con el de `net_revenue`.
 *
 * No confundir este importe con los otros dos del mismo envío. En el 408622: `ganancia`
 * 2,89 €, el `coste` de las valoraciones 2,29 € —que no viaja en el contrato— y
 * `costes_reales` 2,20 €, que es el que se prueba aquí.
 */
class IncidentIntakeV5Test extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'un-token-de-prueba-cualquiera';

    protected function setUp(): void
    {
        parent::setUp();
        config(['panel.bot_token' => self::TOKEN]);
        // El id real y no un `1` supuesto: la secuencia de Postgres no siempre arranca ahí.
        $ruta = PickupRoute::create(['name' => '1']);
        Merchant::create(['name' => 'BOHOCHIQUE', 'pickup_route_id' => $ruta->id]);
    }

    /** @param  array<string, mixed>  $extra */
    private function send(array $extra, int $version = 5): TestResponse
    {
        $asignada = ['id' => null, 'nombre' => 'Ruta 1', 'mensajero' => 'Benjamin GLS'];

        return $this->postJson('/api/incidencias', [
            'version' => $version,
            'corrida' => [
                'fecha' => '2026-08-07', 'generado' => '2026-08-20T11:00:00+02:00',
                'fiable' => true, 'maestro' => null,
                'tolerancia_min' => 20, 'umbral_tanda_min' => 5,
                'envios' => 1, 'evaluados' => 1, 'incidencias' => 1,
                'sin_hora_cinta' => 0, 'sin_ruta' => 0,
            ],
            'incidencias' => [array_merge([
                'expedicion' => '1337079320', 'codigo' => '61326306331020',
                'comercio' => ['id' => null, 'nombre' => 'BOHOCHIQUE'],
                'ruta_asignada' => $asignada,
                'ruta_observada' => ['id' => null, 'nombre' => 'Ruta 4'],
                'tipo' => 'tanda_de_otra_ruta', 'hora_cinta' => '2026-08-07T19:32:42+00:00',
                'desvio_min' => -25.7, 'volumen_m3' => 0.129, 'ganancia' => 2.89,
                'rutas_compatibles' => [], 'rutas_misma_tanda' => [],
                'confianza' => 'baja', 'motivo_confianza' => ['tanda_compartida'],
            ], $extra)],
            'alertas' => [],
        ], ['Authorization' => 'Bearer '.self::TOKEN]);
    }

    public function test_la_v5_entra_y_guarda_el_coste(): void
    {
        $this->send(['costes_reales' => 2.20])->assertOk();

        $fila = RunPackage::firstOrFail();
        $this->assertSame(2.20, $fila->real_cost);
        $this->assertSame(5, $fila->run->payload_version);

        // Y no se ha comido la ganancia, que es otro importe del mismo envío.
        $this->assertSame(2.89, $fila->net_revenue);
    }

    /**
     * El céntimo tiene que sobrevivir al viaje: son euros y el cliente los va a contrastar
     * contra la ficha de Envexpress, donde 3,06 € no es 3,1 € ni 3 €.
     */
    public function test_guarda_los_dos_decimales(): void
    {
        $this->send(['costes_reales' => 3.06])->assertOk();

        $this->assertSame('3.06', RunPackage::firstOrFail()->getRawOriginal('real_cost'));
    }

    /**
     * La ficha sin rellenar. **Nulo, no cero**: si esto se guardase como `0`,
     * `count(real_cost)` lo contaría como envío con dato y la pantalla diría que sumó sobre
     * más envíos de los que de verdad tienen coste tecleado.
     */
    public function test_un_null_no_acaba_en_cero(): void
    {
        $this->send(['costes_reales' => null])->assertOk();

        $fila = RunPackage::firstOrFail();
        $this->assertNull($fila->real_cost);
        $this->assertSame(0, RunPackage::whereNotNull('real_cost')->count());
    }

    /**
     * **El del revés, y el que de verdad separa este campo de `ganancia`.** Alguien escribió
     * un `0` en la ficha: eso es un dato, no un hueco, y tiene que llegar entero a la
     * columna. Un `?:` o un `empty()` en el intake lo pasarían a nulo y el envío
     * desaparecería del recuento de «con dato» sin que nadie lo notara.
     */
    public function test_un_cero_tecleado_se_guarda_como_cero_y_no_como_nulo(): void
    {
        $this->send(['costes_reales' => 0])->assertOk();

        $fila = RunPackage::firstOrFail();
        $this->assertNotNull($fila->real_cost);
        $this->assertSame(0.0, $fila->real_cost);
        $this->assertSame('0.00', $fila->getRawOriginal('real_cost'));
        $this->assertSame(1, RunPackage::whereNotNull('real_cost')->count());
    }

    /** Una corrida de un bot anterior a la v5 no trae el campo y tiene que seguir entrando. */
    public function test_una_jornada_v4_sigue_valiendo(): void
    {
        $this->send([], version: 4)->assertOk();

        $fila = RunPackage::firstOrFail();
        $this->assertNull($fila->real_cost);
        $this->assertSame(4, $fila->run->payload_version);
    }

    /** No hay costes negativos: un importe en negativo es un error del emisor, no un abono. */
    public function test_un_coste_negativo_se_rechaza(): void
    {
        // La clave del error lleva el índice: el bot no reintenta un 422, así que tiene
        // que decir **qué** campo de **qué** fila falla (§3.1, regla 4).
        $respuesta = $this->send(['costes_reales' => -1])->assertStatus(422);

        $this->assertArrayHasKey('incidencias.0.costes_reales', $respuesta->json('detalle'));
        $this->assertSame(0, RunPackage::count());
    }

    /**
     * El campo viaja en `paquetes[]` y no sólo en `incidencias[]`: el coste de una ruta es
     * sobre todo el de los envíos que fueron donde debían, que son la mayoría.
     */
    public function test_tambien_lo_guarda_de_un_paquete_sin_incidencia(): void
    {
        $this->postJson('/api/incidencias', [
            'version' => 5,
            'corrida' => [
                'fecha' => '2026-08-07', 'generado' => '2026-08-20T11:00:00+02:00',
                'fiable' => true, 'maestro' => null,
                'tolerancia_min' => 20, 'umbral_tanda_min' => 5,
                'envios' => 3, 'evaluados' => 3, 'incidencias' => 0,
                'sin_hora_cinta' => 0, 'sin_ruta' => 0,
            ],
            'incidencias' => [],
            'paquetes' => [
                [
                    'expedicion' => '1337079321', 'codigo' => '61326306331021',
                    'comercio' => ['id' => null, 'nombre' => 'BOHOCHIQUE'],
                    'ruta_asignada' => ['id' => null, 'nombre' => 'Ruta 1', 'mensajero' => 'Benjamin GLS'],
                    'hora_cinta' => '2026-08-07T19:32:42+00:00',
                    'ganancia' => 4.15, 'costes_reales' => 3.15,
                ],
                [
                    'expedicion' => '1337079322', 'codigo' => '61326306331022',
                    'comercio' => ['id' => null, 'nombre' => 'BOHOCHIQUE'],
                    'ruta_asignada' => ['id' => null, 'nombre' => 'Ruta 1', 'mensajero' => 'Benjamin GLS'],
                    'hora_cinta' => '2026-08-07T19:33:10+00:00',
                    'ganancia' => 2.89, 'costes_reales' => null,
                ],
                [
                    'expedicion' => '1337079323', 'codigo' => '61326306331023',
                    'comercio' => ['id' => null, 'nombre' => 'BOHOCHIQUE'],
                    'ruta_asignada' => ['id' => null, 'nombre' => 'Ruta 1', 'mensajero' => 'Benjamin GLS'],
                    'hora_cinta' => '2026-08-07T19:34:01+00:00',
                    'ganancia' => 1.60, 'costes_reales' => 0,
                ],
            ],
            'alertas' => [],
        ], ['Authorization' => 'Bearer '.self::TOKEN])->assertOk();

        // La suma y su cobertura, que es como la lee la pantalla: 3,15 € sobre 2 de 3 — el
        // cero cuenta como envío con dato, y el nulo no.
        $this->assertSame('3.15', (string) RunPackage::sum('real_cost'));
        $this->assertSame(2, RunPackage::whereNotNull('real_cost')->count());
        $this->assertSame(3, RunPackage::count());

        // La cobertura de los costes es **suya**: los tres envíos traen ganancia y sólo dos
        // traen coste. Reutilizar el contador de `net_revenue` diría 3 y mentiría.
        $this->assertSame(3, RunPackage::whereNotNull('net_revenue')->count());
    }
}
