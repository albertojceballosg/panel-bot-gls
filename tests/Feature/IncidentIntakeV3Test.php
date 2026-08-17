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
 * La versión 3 del payload (CONTEXTO.md §3.1, 17/08/2026): `rutas_misma_tanda`.
 *
 * Es aditiva —la v2 sigue entrando igual— y existe por una razón de pantalla: hasta ahora,
 * un hallazgo no concluyente por `tanda_compartida` se explicaba con *«dos furgonetas
 * descargaron juntas»* sin decir cuáles, y quien leía tenía que ir a buscarlo a las alertas
 * de la jornada. El bot ya sabía qué rutas eran; sólo faltaba que viajasen.
 *
 * Las dos listas se mantienen separadas a propósito y este test lo fija: `rutas_compatibles`
 * son las que encajan en la hora **concreta** del paquete y `rutas_misma_tanda` las que
 * descargaban en el mismo **bloque**, que dura media hora. Suelen coincidir; cuando no,
 * mezclarlas haría que la pantalla afirmase algo falso.
 */
class IncidentIntakeV3Test extends TestCase
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

    private function send(array $extra): TestResponse
    {
        $asignada = ['id' => null, 'nombre' => 'Ruta 1', 'mensajero' => 'Benjamin GLS'];

        return $this->postJson('/api/incidencias', [
            'version' => 3,
            'corrida' => [
                'fecha' => '2026-08-10', 'generado' => '2026-08-17T11:00:00+02:00',
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
                'tipo' => 'tanda_de_otra_ruta', 'hora_cinta' => '2026-08-10T19:32:42+00:00',
                'desvio_min' => -25.7, 'volumen_m3' => null,
                'rutas_compatibles' => [], 'confianza' => 'baja',
                'motivo_confianza' => ['tanda_compartida'],
            ], $extra)],
            'alertas' => [],
        ], ['Authorization' => 'Bearer '.self::TOKEN]);
    }

    public function test_guarda_las_rutas_del_bloque_y_las_nombra(): void
    {
        $this->send(['rutas_misma_tanda' => [
            ['id' => null, 'nombre' => 'Ruta 3'],
            ['id' => null, 'nombre' => 'Ruta 4'],
        ]])->assertOk();

        $fila = RunPackage::firstOrFail();
        $this->assertCount(2, $fila->batch_shared_routes);
        // La fila ya señala a Ruta 4 en su columna. Repetirla aquí haría que la nota
        // pareciera contradecirla; lo que hace falta es saber cuál era la otra opción.
        $this->assertSame(
            ['podría haber sido Ruta 3, que descargaba en el mismo bloque'],
            IncidentPresenter::reasons($fila),
        );
    }

    /** Sin ruta señalada no hay a quién excluir: se nombran todas. */
    public function test_sin_acusacion_se_nombran_las_dos(): void
    {
        $this->send([
            'ruta_observada' => null,
            'tipo' => 'fuera_de_tanda',
            'rutas_misma_tanda' => [['id' => null, 'nombre' => 'Ruta 3'], ['id' => null, 'nombre' => 'Ruta 4']],
        ])->assertOk();

        $this->assertSame(
            ['Ruta 3 y Ruta 4 descargaron juntas: por la hora no se puede saber cuál lo llevó'],
            IncidentPresenter::reasons(RunPackage::firstOrFail()),
        );
    }

    /** Cada motivo con sus rutas: cruzarlas diría algo que el bot no dijo. */
    public function test_cada_motivo_se_explica_con_su_propia_lista(): void
    {
        $this->send([
            'ruta_observada' => null,
            'tipo' => 'fuera_de_tanda',
            'motivo_confianza' => ['tanda_compartida', 'ventana_compartida'],
            'rutas_misma_tanda' => [['id' => null, 'nombre' => 'Ruta 3'], ['id' => null, 'nombre' => 'Ruta 4']],
            'rutas_compatibles' => [['id' => null, 'nombre' => 'Ruta 5'], ['id' => null, 'nombre' => 'Ruta 6']],
        ])->assertOk();

        $frases = IncidentPresenter::reasons(RunPackage::firstOrFail());

        $this->assertStringContainsString('Ruta 3 y Ruta 4 descargaron juntas', $frases[0]);
        $this->assertStringContainsString('estaban descargando Ruta 5 y Ruta 6', $frases[1]);
    }

    /** Un payload v2 no trae el campo y tiene que seguir entrando y leyéndose. */
    public function test_una_jornada_anterior_sigue_valiendo(): void
    {
        $this->send([])->assertOk();

        $fila = RunPackage::firstOrFail();
        $this->assertSame([], $fila->batch_shared_routes);
        $this->assertSame(
            ['dos furgonetas descargaron juntas: por la hora no se puede saber cuál lo llevó'],
            IncidentPresenter::reasons($fila),
        );
    }
}
