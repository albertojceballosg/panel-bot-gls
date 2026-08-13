<?php

namespace Tests\Feature;

use App\Models\Courier;
use App\Models\RunPackage;
use App\Models\IncidentRun;
use App\Models\Merchant;
use App\Models\PickupRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * El contrato de `POST /api/incidencias` (CONTEXTO.md §3.1).
 *
 * Como el de `GET /api/rutas`, **no se relaja para que pase un refactor**: fija
 * la forma que manda el bot y, sobre todo, que reenviar una jornada deje el
 * mismo estado que la primera vez.
 */
class IncidentIntakeTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'un-token-de-prueba-cualquiera';

    private PickupRoute $route3;

    private PickupRoute $route1;

    private Merchant $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['panel.bot_token' => self::TOKEN]);

        $this->route3 = PickupRoute::create(['name' => '3']);
        $this->route1 = PickupRoute::create(['name' => '1']);
        Courier::create(['name' => 'Freddy GLS', 'pickup_route_id' => $this->route3->id]);
        $this->merchant = Merchant::create([
            'name' => 'COBO FAMILY, S.L.', 'pickup_route_id' => $this->route3->id,
        ]);
    }

    private function send(array $payload, ?string $token = self::TOKEN): TestResponse
    {
        return $this->postJson('/api/incidencias', $payload, $token === null ? [] : [
            'Authorization' => "Bearer {$token}",
        ]);
    }

    /** Una jornada como la que manda el bot, con las incidencias que se le pasen. */
    private function payload(?array $incidents = null, array $run = []): array
    {
        return [
            'version' => 1,
            'corrida' => array_merge([
                'fecha' => '2026-08-03',
                'generado' => '2026-08-04T09:14:03+02:00',
                'fiable' => true,
                'maestro' => '2026-08-04T07:00:00+02:00',
                'tolerancia_min' => 20,
                'umbral_tanda_min' => 5,
                'envios' => 983,
                'evaluados' => 459,
                'incidencias' => 168,
                'sin_hora_cinta' => 34,
                'sin_ruta' => 490,
            ], $run),
            'incidencias' => $incidents ?? [$this->incident()],
            'alertas' => [[
                'tipo' => 'ruta_dispersa',
                'texto' => '2026-08-03 · Ruta 3: pasó DISPERSA por la cinta…',
                'rutas' => [['id' => $this->route3->id, 'nombre' => '3']],
            ]],
        ];
    }

    private function incident(array $overrides = []): array
    {
        return array_merge([
            'expedicion' => '1334043165',
            'codigo' => '61326305203862',
            'comercio' => ['id' => $this->merchant->id, 'nombre' => 'COBO FAMILY, S.L.'],
            'ruta_asignada' => ['id' => $this->route3->id, 'nombre' => 'Ruta 3', 'mensajero' => 'Freddy GLS'],
            'ruta_observada' => ['id' => $this->route1->id, 'nombre' => 'Ruta 1'],
            'tipo' => RunPackage::TYPE_OTHER_ROUTE,
            'hora_cinta' => '2026-08-03T19:52:52+00:00',
            'desvio_min' => 22.3,
            'rutas_compatibles' => [],
            'confianza' => RunPackage::CONFIDENCE_LOW,
            'motivo_confianza' => ['ruta_dispersa'],
        ], $overrides);
    }

    // --- Autenticación ------------------------------------------------------

    public function test_without_a_token_it_answers_401(): void
    {
        $this->send($this->payload(), token: null)->assertUnauthorized();
        $this->assertDatabaseCount('run_packages', 0);
    }

    public function test_with_the_wrong_token_it_answers_401(): void
    {
        $this->send($this->payload(), 'un-token-que-no-es')->assertUnauthorized();
    }

    // --- Guardado -----------------------------------------------------------

    public function test_it_stores_the_run_and_its_incidents(): void
    {
        $this->send($this->payload())
            ->assertOk()
            ->assertJson(['fecha' => '2026-08-03', 'recibidas' => 1, 'nuevas' => 1, 'retiradas' => 0]);

        $run = IncidentRun::firstOrFail();
        $this->assertSame('2026-08-03', $run->run_date->toDateString());
        $this->assertTrue($run->reliable);
        $this->assertSame(983, $run->shipments);
        $this->assertSame('ruta_dispersa', $run->alerts[0]['tipo']);

        $incident = $run->packages()->firstOrFail();
        $this->assertSame('1334043165', $incident->shipment_id);
        $this->assertSame($this->merchant->id, $incident->merchant_id);
        $this->assertSame($this->route3->id, $incident->assigned_route_id);
        $this->assertSame($this->route1->id, $incident->observed_route_id);
        $this->assertSame(22.3, $incident->deviation_minutes);
        $this->assertSame(['ruta_dispersa'], $incident->confidence_reasons);
        $this->assertNull($incident->withdrawn_at);
    }

    public function test_the_confidence_travels_with_the_incident(): void
    {
        // Sin esto una fila de esta tabla es una acusación firme contra un
        // mensajero. El 03/08, 160 de 168 no lo eran (§3.1).
        $this->send($this->payload([
            $this->incident(['expedicion' => 'A', 'confianza' => RunPackage::CONFIDENCE_LOW,
                'motivo_confianza' => ['ruta_dispersa', 'tanda_compartida']]),
            $this->incident(['expedicion' => 'B', 'confianza' => RunPackage::CONFIDENCE_HIGH,
                'motivo_confianza' => []]),
        ]))->assertOk();

        $incidents = RunPackage::all()->keyBy('shipment_id');
        $this->assertFalse($incidents['A']->isConclusive());
        $this->assertSame(['ruta_dispersa', 'tanda_compartida'], $incidents['A']->confidence_reasons);
        $this->assertTrue($incidents['B']->isConclusive());
    }

    public function test_an_out_of_batch_incident_has_no_observed_route(): void
    {
        // 56 de las 168 del 03/08: el paquete pasó descolgado y no hay a quién
        // señalar. No es lo mismo que acusar a otra ruta.
        $this->send($this->payload([
            $this->incident(['tipo' => RunPackage::TYPE_OUT_OF_BATCH, 'ruta_observada' => null]),
        ]))->assertOk();

        $incident = RunPackage::firstOrFail();
        $this->assertNull($incident->observed_route_id);
        $this->assertNull($incident->observed_route_name);
        $this->assertSame(RunPackage::TYPE_OUT_OF_BATCH, $incident->type);
    }

    // --- Idempotencia: lo que de verdad importa -----------------------------

    public function test_resending_the_same_day_does_not_duplicate(): void
    {
        // El bot repite corridas (a mano, o tras un fallo). Con `insert` en vez
        // de upsert, esto duplicaría la jornada entera (§3.1, regla 1).
        $this->send($this->payload())->assertOk()->assertJson(['nuevas' => 1]);
        $this->send($this->payload())->assertOk()->assertJson(['nuevas' => 0, 'actualizadas' => 1]);

        $this->assertDatabaseCount('incident_runs', 1);
        $this->assertDatabaseCount('run_packages', 1);
    }

    public function test_resending_updates_the_values_that_changed(): void
    {
        $this->send($this->payload())->assertOk();
        $this->send($this->payload(
            [$this->incident(['desvio_min' => 99.9, 'confianza' => RunPackage::CONFIDENCE_HIGH,
                'motivo_confianza' => []])],
            ['fiable' => false],
        ))->assertOk();

        $this->assertDatabaseCount('run_packages', 1);
        $this->assertSame(99.9, RunPackage::firstOrFail()->deviation_minutes);
        $this->assertFalse(IncidentRun::firstOrFail()->reliable);
    }

    public function test_incidents_that_stop_coming_are_withdrawn_not_deleted(): void
    {
        $this->send($this->payload([
            $this->incident(['expedicion' => 'SIGUE']),
            $this->incident(['expedicion' => 'DESAPARECE']),
        ]))->assertOk();

        $this->send($this->payload([$this->incident(['expedicion' => 'SIGUE'])]))
            ->assertOk()
            ->assertJson(['recibidas' => 1, 'retiradas' => 1]);

        // Sigue estando: hay que poder ver que existió y dejó de estar.
        $this->assertDatabaseCount('run_packages', 2);
        $this->assertNotNull(RunPackage::where('shipment_id', 'DESAPARECE')->firstOrFail()->withdrawn_at);
        $this->assertNull(RunPackage::where('shipment_id', 'SIGUE')->firstOrFail()->withdrawn_at);
        $this->assertSame(1, IncidentRun::firstOrFail()->currentIncidents()->count());
    }

    public function test_an_incident_that_comes_back_stops_being_withdrawn(): void
    {
        $this->send($this->payload([$this->incident(['expedicion' => 'VUELVE'])]))->assertOk();
        $this->send($this->payload([]))->assertOk()->assertJson(['retiradas' => 1]);
        $this->send($this->payload([$this->incident(['expedicion' => 'VUELVE'])]))->assertOk();

        $this->assertNull(RunPackage::firstOrFail()->withdrawn_at);
    }

    public function test_two_days_do_not_mix(): void
    {
        $this->send($this->payload([$this->incident(['expedicion' => 'X'])]))->assertOk();
        $this->send($this->payload([$this->incident(['expedicion' => 'Y'])], ['fecha' => '2026-08-04']))
            ->assertOk()
            ->assertJson(['retiradas' => 0]);   // no toca las del día anterior

        $this->assertDatabaseCount('incident_runs', 2);
        $this->assertDatabaseCount('run_packages', 2);
        $this->assertSame(0, RunPackage::whereNotNull('withdrawn_at')->count());
    }

    // --- La foto del día ----------------------------------------------------

    public function test_renaming_a_route_does_not_rewrite_the_past(): void
    {
        $this->send($this->payload())->assertOk();
        $this->route3->update(['name' => 'Centro']);

        $incident = RunPackage::firstOrFail();
        $this->assertSame('Ruta 3', $incident->assigned_route_name);
        $this->assertSame('Freddy GLS', $incident->assigned_courier_name);
        // El enlace sigue apuntando a la entidad real, que ahora se llama otra cosa.
        $this->assertSame('Centro', $incident->assignedRoute->name);
    }

    public function test_it_survives_ids_that_no_longer_exist_here(): void
    {
        // El bot los tomó del maestro de esa mañana; si alguien forzó el borrado
        // entre medias, la incidencia entra igual con su nombre copiado en vez
        // de reventar con un 500 que el bot reintentaría para siempre.
        $this->send($this->payload([
            $this->incident(['comercio' => ['id' => 99999, 'nombre' => 'YA NO ESTÁ']]),
        ]))->assertOk();

        $incident = RunPackage::firstOrFail();
        $this->assertNull($incident->merchant_id);
        $this->assertSame('YA NO ESTÁ', $incident->merchant_name);
    }

    public function test_it_accepts_a_master_without_identifiers(): void
    {
        // El Excel de recambio no tiene ids: el contrato los marca opcionales.
        $this->send($this->payload([
            $this->incident([
                'comercio' => ['id' => null, 'nombre' => 'COBO FAMILY, S.L.'],
                'ruta_asignada' => ['id' => null, 'nombre' => 'Ruta 3', 'mensajero' => 'Freddy GLS'],
                'ruta_observada' => ['id' => null, 'nombre' => 'Ruta 1'],
            ]),
        ]))->assertOk();

        $incident = RunPackage::firstOrFail();
        $this->assertNull($incident->merchant_id);
        $this->assertSame('COBO FAMILY, S.L.', $incident->merchant_name);
        $this->assertSame('Ruta 3', $incident->assigned_route_name);
    }

    // --- Rechazos -----------------------------------------------------------

    public function test_a_broken_payload_is_rejected_saying_which_field(): void
    {
        // El bot no reintenta un 422: si no dice qué falla, el fallo es mudo.
        $response = $this->send(['version' => 1, 'corrida' => ['fecha' => '03/08/2026'],
            'incidencias' => [], 'alertas' => []])
            ->assertStatus(422)
            ->assertJsonStructure(['error', 'detalle']);

        // Las claves del detalle son las del payload, para poder localizar el
        // campo malo sin adivinar. Se leen del JSON en crudo porque llevan punto
        // dentro y `assertJsonPath` lo interpretaría como un nivel más.
        $detail = $response->json('detalle');
        $this->assertArrayHasKey('corrida.fecha', $detail);
        $this->assertStringContainsString('Y-m-d', $detail['corrida.fecha'][0]);
        $this->assertArrayHasKey('corrida.fiable', $detail);

        $this->assertDatabaseCount('incident_runs', 0);
    }

    public function test_an_unknown_confidence_is_rejected(): void
    {
        $this->send($this->payload([$this->incident(['confianza' => 'regular'])]))
            ->assertStatus(422);
    }

    public function test_repeated_shipments_are_rejected(): void
    {
        // Se pisarían entre sí en el upsert y el recuento mentiría.
        $this->send($this->payload([
            $this->incident(['expedicion' => 'IGUAL']),
            $this->incident(['expedicion' => 'IGUAL']),
        ]))->assertStatus(422)->assertJsonPath('detalle.incidencias.0', 'IGUAL');

        $this->assertDatabaseCount('run_packages', 0);
    }

    public function test_nothing_is_stored_if_the_payload_is_rejected(): void
    {
        $this->send($this->payload())->assertOk();
        $this->send($this->payload([$this->incident(['tipo' => 'lo_que_sea'])]))->assertStatus(422);

        // La jornada anterior sigue intacta: un rechazo no deja nada a medias.
        $this->assertDatabaseCount('run_packages', 1);
        $this->assertNull(RunPackage::firstOrFail()->withdrawn_at);
    }

    public function test_an_empty_day_is_valid(): void
    {
        // Un día sin incidencias es una noticia buena, no un error.
        $this->send($this->payload([], ['incidencias' => 0]))
            ->assertOk()
            ->assertJson(['recibidas' => 0, 'nuevas' => 0]);

        $this->assertDatabaseCount('incident_runs', 1);
        $this->assertDatabaseCount('run_packages', 0);
    }
}
