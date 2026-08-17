<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\PickupRoute;
use App\Models\RunPackage;
use App\Support\IncidentPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * La versión 2 del payload (CONTEXTO.md §3.1, 17/08/2026).
 *
 * La forma del JSON no cambió; cambió lo que significan tres campos, y por eso
 * hace falta un test aparte: los de `IncidentIntakeTest` fijan la v1 y seguirían
 * pasando aunque el panel interpretase la v2 al revés.
 *
 * Lo que cambia, y es lo que se fija aquí:
 *
 * - `ruta_observada` sale de la **ventana** de cada ruta (su pico de densidad
 *   ±10 min) y no de la mayoría de la tanda. El bot sólo acusa si exactamente
 *   una ventana ajena contiene esa hora.
 * - `rutas_compatibles` son las otras rutas cuya ventana también la contiene, y
 *   **sólo viene cuando hay dos o más**. En la v1 podía traer la misma ruta que
 *   ya iba en `ruta_observada`, así que contarlas juntas duplicaba.
 * - `motivo_confianza` admite un valor nuevo, `ventana_compartida`, distinto de
 *   `tanda_compartida`: aquél habla de la jornada y éste de la hora concreta.
 */
class IncidentIntakeV2Test extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'un-token-de-prueba-cualquiera';

    private PickupRoute $ruta1;

    private PickupRoute $ruta4;

    protected function setUp(): void
    {
        parent::setUp();
        config(['panel.bot_token' => self::TOKEN]);

        $this->ruta1 = PickupRoute::create(['name' => '1']);
        $this->ruta4 = PickupRoute::create(['name' => '4']);
        Merchant::create(['name' => 'BOHOCHIQUE', 'pickup_route_id' => $this->ruta1->id]);
    }

    /** El caso real del 10/08/2026 que motivó el cambio, reducido a dos paquetes. */
    private function send(): TestResponse
    {
        $asignada = ['id' => $this->ruta1->id, 'nombre' => 'Ruta 1', 'mensajero' => 'Benjamin GLS'];

        return $this->postJson('/api/incidencias', [
            'version' => 2,
            'corrida' => [
                'fecha' => '2026-08-10', 'generado' => '2026-08-17T11:00:00+02:00',
                'fiable' => true, 'maestro' => '2026-08-17T07:00:00+02:00',
                'tolerancia_min' => 20, 'umbral_tanda_min' => 5,
                'envios' => 1040, 'evaluados' => 611, 'incidencias' => 2,
                'sin_hora_cinta' => 45, 'sin_ruta' => 384,
            ],
            'paquetes' => [
                ['expedicion' => '1337079320', 'codigo' => '61326306331020',
                    'comercio' => ['id' => null, 'nombre' => 'BOHOCHIQUE'],
                    'ruta_asignada' => $asignada, 'hora_cinta' => '2026-08-10T19:32:42+00:00'],
                ['expedicion' => '1337079999', 'codigo' => '61326306999999',
                    'comercio' => ['id' => null, 'nombre' => 'LUSTIG 1985, S.L.'],
                    'ruta_asignada' => $asignada, 'hora_cinta' => '2026-08-10T19:05:17+00:00'],
            ],
            'incidencias' => [
                // Encaje inequívoco: acusa, y `rutas_compatibles` va vacío.
                ['expedicion' => '1337079320', 'codigo' => '61326306331020',
                    'comercio' => ['id' => null, 'nombre' => 'BOHOCHIQUE'],
                    'ruta_asignada' => $asignada,
                    'ruta_observada' => ['id' => $this->ruta4->id, 'nombre' => 'Ruta 4'],
                    'tipo' => 'tanda_de_otra_ruta', 'hora_cinta' => '2026-08-10T19:32:42+00:00',
                    'desvio_min' => -25.7, 'volumen_m3' => 0.072,
                    'rutas_compatibles' => [], 'confianza' => 'baja',
                    'motivo_confianza' => ['tanda_compartida']],

                // Ambigua: no acusa a nadie y lo dice con el motivo nuevo.
                ['expedicion' => '1337079999', 'codigo' => '61326306999999',
                    'comercio' => ['id' => null, 'nombre' => 'LUSTIG 1985, S.L.'],
                    'ruta_asignada' => $asignada, 'ruta_observada' => null,
                    'tipo' => 'fuera_de_tanda', 'hora_cinta' => '2026-08-10T19:05:17+00:00',
                    'desvio_min' => -53.0, 'volumen_m3' => null,
                    'rutas_compatibles' => [
                        ['id' => null, 'nombre' => 'Ruta 3'],
                        ['id' => $this->ruta4->id, 'nombre' => 'Ruta 4'],
                    ],
                    'confianza' => 'baja',
                    'motivo_confianza' => ['tanda_compartida', 'ventana_compartida']],
            ],
            'alertas' => [],
        ], ['Authorization' => 'Bearer '.self::TOKEN]);
    }

    public function test_guarda_la_jornada_y_anota_la_version(): void
    {
        $this->send()->assertOk();

        $this->assertSame(2, RunPackage::firstOrFail()->run->payload_version);
    }

    public function test_una_incidencia_inequivoca_acusa_y_no_repite_la_ruta_acusada(): void
    {
        $this->send();

        $fila = RunPackage::where('shipment_id', '1337079320')->firstOrFail();

        $this->assertSame($this->ruta4->id, $fila->observed_route_id);
        $this->assertSame('Ruta 4', $fila->observed_route_name);
        // Lo que la v1 hacía mal: la ruta acusada venía también aquí, y una
        // pantalla que sumase las dos listas la contaba dos veces.
        $this->assertSame([], $fila->compatible_routes);
    }

    public function test_una_incidencia_ambigua_no_señala_a_nadie(): void
    {
        $this->send();

        $fila = RunPackage::where('shipment_id', '1337079999')->firstOrFail();

        $this->assertNull($fila->observed_route_id);
        $this->assertNull($fila->observed_route_name);
        $this->assertCount(2, $fila->compatible_routes);
    }

    public function test_el_motivo_nuevo_se_guarda_y_se_lee_en_castellano(): void
    {
        $this->send();

        $fila = RunPackage::where('shipment_id', '1337079999')->firstOrFail();
        $this->assertContains('ventana_compartida', $fila->confidence_reasons);

        // No basta con guardarlo: la pantalla tiene que poder explicarlo. Si
        // alguien añade el motivo al bot y no aquí, sale la clave con el guión
        // bajo quitado y la fila deja de decir nada.
        $frase = IncidentPresenter::reason('ventana_compartida');
        $this->assertStringNotContainsString('_', $frase);
        $this->assertNotSame('ventana compartida', $frase);
    }
}
