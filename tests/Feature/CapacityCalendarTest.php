<?php

namespace Tests\Feature;

use App\Models\Courier;
use App\Models\IncidentRun;
use App\Models\PickupRoute;
use App\Models\RunPackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Calendario de capacidades (CONTEXTO.md §7, fase 6.D). */
class CapacityCalendarTest extends TestCase
{
    use RefreshDatabase;

    /** El lunes de la semana en curso, que es lo que la pantalla abre por defecto. */
    private Carbon $lunes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->lunes = Carbon::today()->startOfWeek(Carbon::MONDAY);
    }

    private function runOn(Carbon $dia, bool $reliable = true): IncidentRun
    {
        return IncidentRun::create([
            'run_date' => $dia->toDateString(),
            'payload_version' => 1,
            'generated_at' => $dia->copy()->setTime(7, 0),
            'reliable' => $reliable,
            'tolerance_minutes' => 15,
            'batch_gap_minutes' => 30,
            'shipments' => 0,
            'evaluated' => 0,
            'incidents_reported' => 0,
            'without_belt_time' => 0,
            'without_route' => 0,
            'alerts' => [],
        ]);
    }

    private function package(IncidentRun $run, ?string $courier, ?float $volume, array $extra = []): RunPackage
    {
        static $n = 0;

        return RunPackage::create(array_merge([
            'incident_run_id' => $run->id,
            'shipment_id' => 'S'.(++$n),
            'merchant_name' => 'Comercio',
            'assigned_courier_name' => $courier,
            'volume_m3' => $volume,
        ], $extra));
    }

    /** @return array<string, mixed>|null La fila de esa UT, tal y como la ve la vista. */
    private function fila($componente, string $label): ?array
    {
        $rows = $componente->viewData('rows');

        return collect($rows)->firstWhere('label', $label);
    }

    public function test_it_is_behind_the_session(): void
    {
        auth()->logout();

        $this->get('/capacity-calendar')->assertRedirect('/login');
    }

    // --- El volumen del día --------------------------------------------------

    public function test_it_adds_up_the_volume_of_each_day_per_courier(): void
    {
        Courier::create(['name' => 'Freddy GLS']);

        $lunes = $this->runOn($this->lunes);
        $this->package($lunes, 'Freddy GLS', 1.5);
        $this->package($lunes, 'Freddy GLS', 2.25);

        $martes = $this->runOn($this->lunes->copy()->addDay());
        $this->package($martes, 'Freddy GLS', 4.0);

        $fila = $this->fila(Livewire::test('capacity-calendar'), 'Freddy GLS');

        $this->assertSame(3.75, $fila['days'][$this->lunes->toDateString()]['volume']);
        $this->assertSame(4.0, $fila['days'][$this->lunes->copy()->addDay()->toDateString()]['volume']);

        // Miércoles no hubo corrida: hueco, no cero.
        $this->assertNull($fila['days'][$this->lunes->copy()->addDays(2)->toDateString()]);
    }

    public function test_each_day_reports_how_much_of_the_van_it_filled(): void
    {
        Courier::create(['name' => 'Freddy GLS', 'maximum_volume' => 10.0]);

        $this->package($this->runOn($this->lunes), 'Freddy GLS', 3.0);
        $this->package($this->runOn($this->lunes->copy()->addDay()), 'Freddy GLS', 12.5);

        $fila = $this->fila(Livewire::test('capacity-calendar'), 'Freddy GLS');

        $this->assertSame(0.3, $fila['days'][$this->lunes->toDateString()]['usage']);

        // Más de uno es un día que no cabía en la furgoneta, que es justo lo que
        // se viene a mirar aquí.
        $this->assertSame(1.25, $fila['days'][$this->lunes->copy()->addDay()->toDateString()]['usage']);

        Livewire::test('capacity-calendar')->assertSee('30 %')->assertSee('125 %');
    }

    public function test_without_a_declared_capacity_there_is_no_usage_to_show(): void
    {
        Courier::create(['name' => 'Freddy GLS']);

        $this->package($this->runOn($this->lunes), 'Freddy GLS', 3.0);

        $celda = $this->fila(Livewire::test('capacity-calendar'), 'Freddy GLS')['days'][$this->lunes->toDateString()];

        $this->assertSame(3.0, $celda['volume']);
        $this->assertNull($celda['usage']);
    }

    public function test_a_capacity_of_zero_does_not_blow_up_the_usage(): void
    {
        // Una furgoneta declarada con cero es un dato mal metido, no un divisor.
        Courier::create(['name' => 'Freddy GLS', 'maximum_volume' => 0]);

        $this->package($this->runOn($this->lunes), 'Freddy GLS', 3.0);

        $celda = $this->fila(Livewire::test('capacity-calendar'), 'Freddy GLS')['days'][$this->lunes->toDateString()];

        $this->assertNull($celda['usage']);
    }

    public function test_it_warns_when_the_sum_does_not_cover_every_shipment(): void
    {
        Courier::create(['name' => 'Freddy GLS', 'maximum_volume' => 10.0]);

        $lunes = $this->runOn($this->lunes);
        $this->package($lunes, 'Freddy GLS', 1.5);
        $this->package($lunes, 'Freddy GLS', null);
        $this->package($lunes, 'Freddy GLS', null);

        $celda = $this->fila(Livewire::test('capacity-calendar'), 'Freddy GLS')['days'][$this->lunes->toDateString()];

        // Un nulo del portal es "no lo sé", así que no suma como cero.
        $this->assertSame(1.5, $celda['volume']);
        $this->assertSame(3, $celda['shipments']);
        $this->assertSame(1, $celda['measured']);

        // El día ocupó más de ese 15 %, y eso no puede quedar callado (§3).
        Livewire::test('capacity-calendar')->assertSee('El portal sólo dio el volumen de 1 de 3 envíos');
    }

    public function test_a_day_without_any_measured_volume_has_no_usage(): void
    {
        Courier::create(['name' => 'Freddy GLS', 'maximum_volume' => 12.0]);

        $this->package($this->runOn($this->lunes), 'Freddy GLS', 6.0);
        $this->package($this->runOn($this->lunes->copy()->addDay()), 'Freddy GLS', null);

        $fila = $this->fila(Livewire::test('capacity-calendar'), 'Freddy GLS');
        $martes = $fila['days'][$this->lunes->copy()->addDay()->toDateString()];

        // El martes hubo trabajo pero no se sabe cuánto ocupó: un 0 % diría que
        // salió de vacío.
        $this->assertSame(0.5, $fila['days'][$this->lunes->toDateString()]['usage']);
        $this->assertNull($martes['volume']);
        $this->assertNull($martes['usage']);
        $this->assertSame(1, $martes['shipments']);
    }

    public function test_withdrawn_packages_do_not_count(): void
    {
        Courier::create(['name' => 'Freddy GLS']);

        $lunes = $this->runOn($this->lunes);
        $this->package($lunes, 'Freddy GLS', 2.0);
        $this->package($lunes, 'Freddy GLS', 9.0, ['withdrawn_at' => now()]);

        $fila = $this->fila(Livewire::test('capacity-calendar'), 'Freddy GLS');

        $this->assertSame(2.0, $fila['days'][$this->lunes->toDateString()]['volume']);
        $this->assertSame(1, $fila['days'][$this->lunes->toDateString()]['shipments']);
    }

    // --- Filas: el maestro y lo que no está en él ----------------------------

    public function test_every_courier_of_the_master_has_a_row_even_without_data(): void
    {
        Courier::create(['name' => 'Sin trabajo esta semana']);

        $fila = $this->fila(Livewire::test('capacity-calendar'), 'Sin trabajo esta semana');

        $this->assertNotNull($fila);
        $this->assertSame([null], array_values(array_unique($fila['days'], SORT_REGULAR)));
    }

    public function test_volume_of_a_courier_no_longer_in_the_master_is_not_hidden(): void
    {
        $this->package($this->runOn($this->lunes), 'Quien se fue', 7.0);

        $fila = $this->fila(Livewire::test('capacity-calendar'), 'Quien se fue');

        $this->assertSame(7.0, $fila['days'][$this->lunes->toDateString()]['volume']);
        $this->assertSame('Ya no está en el maestro', $fila['note']);
    }

    public function test_volume_of_routes_with_nobody_driving_gets_its_own_row(): void
    {
        $this->package($this->runOn($this->lunes), null, 3.0);

        $fila = $this->fila(Livewire::test('capacity-calendar'), 'Sin UT asignada');

        $this->assertSame(3.0, $fila['days'][$this->lunes->toDateString()]['volume']);
    }

    public function test_it_shows_the_declared_capacity_of_the_van(): void
    {
        Courier::create(['name' => 'Freddy GLS', 'maximum_volume' => 12.5]);

        $this->assertSame(12.5, $this->fila(Livewire::test('capacity-calendar'), 'Freddy GLS')['capacity']);
    }

    // --- El filtro de semana --------------------------------------------------

    public function test_it_opens_on_the_current_week(): void
    {
        Courier::create(['name' => 'Freddy GLS']);

        // La de la semana pasada no debe contarse en la de esta.
        $this->package($this->runOn($this->lunes->copy()->subWeek()), 'Freddy GLS', 9.0);
        $this->package($this->runOn($this->lunes), 'Freddy GLS', 2.0);

        $componente = Livewire::test('capacity-calendar')
            ->assertSet('week', '')
            ->assertViewHas('esLaSemanaEnCurso', true);

        $fila = $this->fila($componente, 'Freddy GLS');

        // La de la semana pasada no entra en la fila: sólo el envío de esta.
        $this->assertSame(2.0, $fila['days'][$this->lunes->toDateString()]['volume']);
        $this->assertSame(1, $fila['shipments']);
    }

    public function test_it_moves_a_week_back_and_forth(): void
    {
        Courier::create(['name' => 'Freddy GLS']);

        $anterior = $this->lunes->copy()->subWeek();
        $this->package($this->runOn($anterior), 'Freddy GLS', 9.0);

        $componente = Livewire::test('capacity-calendar')->call('shift', -1);

        $this->assertSame($anterior->toDateString(), $componente->get('week'));
        $this->assertSame(9.0, $this->fila($componente, 'Freddy GLS')['days'][$anterior->toDateString()]['volume']);

        $componente->call('shift', 1)->assertSet('week', $this->lunes->toDateString());
    }

    public function test_any_day_snaps_to_its_monday(): void
    {
        // El filtro es semanal: elegir el jueves es elegir su semana.
        Livewire::test('capacity-calendar')
            ->set('week', $this->lunes->copy()->addDays(3)->toDateString())
            ->assertSet('week', $this->lunes->toDateString());
    }

    public function test_a_broken_week_in_the_url_falls_back_to_the_current_one(): void
    {
        // Llega por la URL, no por el `<input type="date">`: sin la red, un 500.
        Livewire::withQueryParams(['semana' => 'lo-que-sea'])
            ->test('capacity-calendar')
            ->assertOk()
            ->assertViewHas('esLaSemanaEnCurso', true);
    }

    // --- Cómo se lee la semana -----------------------------------------------

    public function test_a_day_without_a_run_is_not_a_day_without_work(): void
    {
        Courier::create(['name' => 'Freddy GLS']);

        Livewire::test('capacity-calendar')->assertSee('sin corrida');
    }

    public function test_an_unreliable_run_is_flagged_on_its_column(): void
    {
        Courier::create(['name' => 'Freddy GLS']);
        $this->runOn($this->lunes, reliable: false);

        Livewire::test('capacity-calendar')->assertSee('no fiable');
    }

    public function test_the_screen_survives_an_empty_master(): void
    {
        Livewire::test('capacity-calendar')
            ->assertOk()
            ->assertSee('Todavía no hay UT');
    }

    // --- Consultas ------------------------------------------------------------

    public function test_the_whole_table_is_three_queries(): void
    {
        $ruta = PickupRoute::create(['name' => '3']);

        foreach (range(1, 5) as $i) {
            Courier::create(['name' => "UT {$i}"]);
        }

        foreach (range(0, 4) as $dia) {
            $run = $this->runOn($this->lunes->copy()->addDays($dia));

            foreach (range(1, 5) as $i) {
                $this->package($run, "UT {$i}", 1.5, ['assigned_route_id' => $ruta->id]);
            }
        }

        $consultas = 0;
        \DB::listen(function () use (&$consultas) {
            $consultas++;
        });

        Livewire::test('capacity-calendar');

        // Corridas, agregado y maestro. Tres, y las mismas tres con 5 UT que
        // con 50: la tabla se arma en SQL, no fila a fila.
        $this->assertSame(3, $consultas);
    }
}
