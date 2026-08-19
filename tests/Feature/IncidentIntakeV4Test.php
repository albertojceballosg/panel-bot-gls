<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\PickupRoute;
use App\Models\RunPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * La versión 4 del payload (CONTEXTO.md §3.1, 19/08/2026): `ganancia`.
 *
 * Un solo campo nuevo —lo facturado por el envío sin IVA, que el bot saca de Envexpress y
 * cruza por código de barras— y aditivo: una v3 sigue entrando igual.
 *
 * Lo que fija este test es el tratamiento del nulo, que es donde está todo el riesgo. Hay
 * dos maneras de no tener el dato y **ninguna de las dos es un cero**:
 *
 * - el envío no aparece en Envexpress (30 de 543 el 07/08/2026),
 * - la corrida es de un bot anterior a la v4 y no manda el campo.
 *
 * Si cualquiera de las dos acabase en `0`, la interfaz sumaría ceros como si fueran euros
 * ganados y toda ruta a la que le falten valoraciones parecería menos rentable de lo que
 * fue. Es el mismo criterio que `volumen_m3` y por el mismo motivo.
 */
class IncidentIntakeV4Test extends TestCase
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
    private function send(array $extra, int $version = 4): TestResponse
    {
        $asignada = ['id' => null, 'nombre' => 'Ruta 1', 'mensajero' => 'Benjamin GLS'];

        return $this->postJson('/api/incidencias', [
            'version' => $version,
            'corrida' => [
                'fecha' => '2026-08-07', 'generado' => '2026-08-19T11:00:00+02:00',
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
                'desvio_min' => -25.7, 'volumen_m3' => 0.129,
                'rutas_compatibles' => [], 'rutas_misma_tanda' => [],
                'confianza' => 'baja', 'motivo_confianza' => ['tanda_compartida'],
            ], $extra)],
            'alertas' => [],
        ], ['Authorization' => 'Bearer '.self::TOKEN]);
    }

    public function test_la_v4_entra_y_guarda_el_importe(): void
    {
        $this->send(['ganancia' => 8.60])->assertOk();

        $fila = RunPackage::firstOrFail();
        $this->assertSame(8.60, $fila->net_revenue);
        $this->assertSame(4, $fila->run->payload_version);
    }

    /**
     * El céntimo tiene que sobrevivir al viaje: son euros y el cliente los va a contrastar
     * contra la pantalla de Envexpress, donde 3,06 € no es 3,1 € ni 3 €.
     */
    public function test_guarda_los_dos_decimales(): void
    {
        $this->send(['ganancia' => 3.06])->assertOk();

        $this->assertSame('3.06', RunPackage::firstOrFail()->getRawOriginal('net_revenue'));
    }

    /**
     * Un envío que no está en Envexpress. **Nulo, no cero**: si esto se guardase como `0`,
     * `count(net_revenue)` lo contaría como envío con dato y la pantalla diría que se sumó
     * sobre más envíos de los que de verdad tenían valoración.
     */
    public function test_un_null_no_acaba_en_cero(): void
    {
        $this->send(['ganancia' => null])->assertOk();

        $fila = RunPackage::firstOrFail();
        $this->assertNull($fila->net_revenue);
        $this->assertSame(0, RunPackage::whereNotNull('net_revenue')->count());
    }

    /** Una corrida de un bot anterior a la v4 no trae el campo y tiene que seguir entrando. */
    public function test_una_jornada_v3_sigue_valiendo(): void
    {
        $this->send([], version: 3)->assertOk();

        $fila = RunPackage::firstOrFail();
        $this->assertNull($fila->net_revenue);
        $this->assertSame(3, $fila->run->payload_version);
    }

    /** No hay ganancias negativas: un importe en negativo es un error del emisor, no un abono. */
    public function test_una_ganancia_negativa_se_rechaza(): void
    {
        // La clave del error lleva el índice: el bot no reintenta un 422, así que tiene
        // que decir **qué** campo de **qué** fila falla (§3.1, regla 4).
        $respuesta = $this->send(['ganancia' => -1])->assertStatus(422);

        $this->assertArrayHasKey('incidencias.0.ganancia', $respuesta->json('detalle'));
        $this->assertSame(0, RunPackage::count());
    }

    /**
     * El campo viaja en `paquetes[]` y no sólo en `incidencias[]`: la ganancia de una ruta
     * es sobre todo la de los envíos que fueron donde debían, que son la mayoría.
     */
    public function test_tambien_lo_guarda_de_un_paquete_sin_incidencia(): void
    {
        $this->postJson('/api/incidencias', [
            'version' => 4,
            'corrida' => [
                'fecha' => '2026-08-07', 'generado' => '2026-08-19T11:00:00+02:00',
                'fiable' => true, 'maestro' => null,
                'tolerancia_min' => 20, 'umbral_tanda_min' => 5,
                'envios' => 2, 'evaluados' => 2, 'incidencias' => 0,
                'sin_hora_cinta' => 0, 'sin_ruta' => 0,
            ],
            'incidencias' => [],
            'paquetes' => [
                [
                    'expedicion' => '1337079321', 'codigo' => '61326306331021',
                    'comercio' => ['id' => null, 'nombre' => 'BOHOCHIQUE'],
                    'ruta_asignada' => ['id' => null, 'nombre' => 'Ruta 1', 'mensajero' => 'Benjamin GLS'],
                    'hora_cinta' => '2026-08-07T19:32:42+00:00', 'ganancia' => 4.15,
                ],
                [
                    'expedicion' => '1337079322', 'codigo' => '61326306331022',
                    'comercio' => ['id' => null, 'nombre' => 'BOHOCHIQUE'],
                    'ruta_asignada' => ['id' => null, 'nombre' => 'Ruta 1', 'mensajero' => 'Benjamin GLS'],
                    'hora_cinta' => '2026-08-07T19:33:10+00:00', 'ganancia' => null,
                ],
            ],
            'alertas' => [],
        ], ['Authorization' => 'Bearer '.self::TOKEN])->assertOk();

        // La suma y su cobertura, que es como la lee la pantalla: 4,15 € sobre 1 de 2.
        $this->assertSame('4.15', (string) RunPackage::sum('net_revenue'));
        $this->assertSame(1, RunPackage::whereNotNull('net_revenue')->count());
        $this->assertSame(2, RunPackage::count());
    }
}
