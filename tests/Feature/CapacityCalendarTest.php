<?php

namespace Tests\Feature;

use App\Models\Courier;
use App\Models\Expense;
use App\Models\IncidentRun;
use App\Models\PickupRoute;
use App\Models\RouteExpense;
use App\Models\RunPackage;
use App\Models\Setting;
use App\Models\User;
use App\Support\DailyExpenseShare;
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

    public function test_an_incomplete_sum_is_kept_apart_from_the_shipments_it_covers(): void
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

        // La cobertura sigue calculándose y viajando a la vista, pero desde el
        // 17/08/2026 la celda ya no la enseña: el tooltip se quitó a petición
        // (§7, fase 6.D). El día ocupó más de ese 15 % y ahora no se dice.
        Livewire::test('capacity-calendar')->assertDontSee('El portal sólo dio el volumen');
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

    public function test_it_asks_to_be_configured_while_its_settings_are_missing(): void
    {
        Courier::create(['name' => 'Freddy GLS']);

        // No hay valores por defecto (§7, fase 11): inventarle un umbral al
        // cliente cambiaría cómo se lee la tabla sin que él lo haya elegido.
        Livewire::test('capacity-calendar')
            ->assertSee('Esta pantalla está sin configurar')
            ->assertSee('porcentaje mínimo');
    }

    public function test_once_configured_it_stops_asking(): void
    {
        Courier::create(['name' => 'Freddy GLS']);

        foreach ([
            'minimum_percent' => '60',
            'optimal_percent' => '85',
            'bad_color' => '#dc2626',
            'warning_color' => '#d97706',
            'good_color' => '#16a34a',
        ] as $clave => $valor) {
            Setting::create(['module' => 'capacity-calendar', 'key' => $clave, 'value' => $valor]);
        }

        Livewire::test('capacity-calendar')->assertDontSee('Esta pantalla está sin configurar');
    }

    // --- Los tramos configurados (§7, fase 11) -------------------------------

    /** Deja la pantalla configurada, que es como la ve el cliente. */
    private function configurada(int $minimo = 60, int $optimo = 85): void
    {
        foreach ([
            'minimum_percent' => (string) $minimo,
            'optimal_percent' => (string) $optimo,
            'bad_color' => '#dc2626',
            'warning_color' => '#d97706',
            'good_color' => '#16a34a',
        ] as $clave => $valor) {
            Setting::create(['module' => 'capacity-calendar', 'key' => $clave, 'value' => $valor]);
        }
    }

    public function test_each_day_falls_in_one_of_the_configured_bands(): void
    {
        $this->configurada(minimo: 60, optimo: 85);
        Courier::create(['name' => 'Freddy GLS', 'maximum_volume' => 10.0]);

        // 50 %, 70 % y 90 %: uno de cada tramo.
        $this->package($this->runOn($this->lunes), 'Freddy GLS', 5.0);
        $this->package($this->runOn($this->lunes->copy()->addDay()), 'Freddy GLS', 7.0);
        $this->package($this->runOn($this->lunes->copy()->addDays(2)), 'Freddy GLS', 9.0);

        $dias = $this->fila(Livewire::test('capacity-calendar'), 'Freddy GLS')['days'];

        $this->assertSame('bad', $dias[$this->lunes->toDateString()]['band']);
        $this->assertSame('warning', $dias[$this->lunes->copy()->addDay()->toDateString()]['band']);
        $this->assertSame('good', $dias[$this->lunes->copy()->addDays(2)->toDateString()]['band']);
    }

    public function test_the_configured_colour_of_the_band_reaches_the_cell(): void
    {
        $this->configurada();
        Courier::create(['name' => 'Freddy GLS', 'maximum_volume' => 10.0]);
        $this->package($this->runOn($this->lunes), 'Freddy GLS', 9.0);

        Livewire::test('capacity-calendar')->assertSee('color: #16a34a', escape: false);
    }

    public function test_the_band_is_decided_on_the_percentage_that_is_shown(): void
    {
        // 79,6 % se pinta como «80 %»: con el umbral en 80 tiene que caer en el
        // tramo bueno, o el color parecería un error de la pantalla.
        $this->configurada(minimo: 60, optimo: 80);
        Courier::create(['name' => 'Freddy GLS', 'maximum_volume' => 10.0]);
        $this->package($this->runOn($this->lunes), 'Freddy GLS', 7.96);

        $celda = $this->fila(Livewire::test('capacity-calendar'), 'Freddy GLS')['days'][$this->lunes->toDateString()];

        $this->assertSame('good', $celda['band']);
    }

    public function test_without_settings_there_is_no_band_and_no_colour(): void
    {
        Courier::create(['name' => 'Freddy GLS', 'maximum_volume' => 10.0]);
        $this->package($this->runOn($this->lunes), 'Freddy GLS', 9.0);

        $celda = $this->fila(Livewire::test('capacity-calendar'), 'Freddy GLS')['days'][$this->lunes->toDateString()];

        // Sin umbrales no se inventa ninguno (§7, fase 11): la cifra sale, el
        // tramo no.
        $this->assertSame(0.9, $celda['usage']);
        $this->assertNull($celda['band']);
    }

    public function test_a_colour_that_is_not_a_hex_does_not_reach_the_style(): void
    {
        // El formulario lo valida, pero una fila escrita a mano en la base no
        // pasa por él, y de aquí sale un atributo `style`.
        $this->configurada();
        Setting::where('key', 'good_color')->update(['value' => 'javascript:alert(1)']);

        Courier::create(['name' => 'Freddy GLS', 'maximum_volume' => 10.0]);
        $this->package($this->runOn($this->lunes), 'Freddy GLS', 9.0);

        Livewire::test('capacity-calendar')
            ->assertSee('90 %')
            ->assertDontSee('javascript:alert(1)', escape: false);
    }

    public function test_a_day_below_the_minimum_load_is_only_marked_by_its_colour(): void
    {
        $this->configurada(minimo: 60, optimo: 85);
        Courier::create(['name' => 'Freddy GLS', 'maximum_volume' => 10.0]);
        $this->package($this->runOn($this->lunes), 'Freddy GLS', 4.0);

        // El icono de alerta del día flojo se quitó el 18/08/2026 a petición: la
        // cifra la marca el color de su tramo, y pulsarla abre el desglose.
        $componente = Livewire::test('capacity-calendar')
            ->assertSee('40 %')
            ->assertSee('color: #dc2626', escape: false)
            ->assertDontSee('de carga mínima');

        $this->assertSame('bad', $this->fila($componente, 'Freddy GLS')['days'][$this->lunes->toDateString()]['band']);
    }

    public function test_a_day_over_the_capacity_is_marked_beyond_its_colour(): void
    {
        // Con el óptimo en 85, un 125 % cae en el tramo bueno: que no cupiera en
        // la furgoneta ya no lo puede decir el color.
        $this->configurada();
        Courier::create(['name' => 'Freddy GLS', 'maximum_volume' => 10.0]);
        $this->package($this->runOn($this->lunes), 'Freddy GLS', 12.5);

        $celda = $this->fila(Livewire::test('capacity-calendar'), 'Freddy GLS')['days'][$this->lunes->toDateString()];

        $this->assertSame('good', $celda['band']);
        Livewire::test('capacity-calendar')->assertSee('Se pasa de la capacidad declarada');
    }

    public function test_the_screen_survives_an_empty_master(): void
    {
        Livewire::test('capacity-calendar')
            ->assertOk()
            ->assertSee('Todavía no hay UT');
    }

    // --- Lo que enseña la celda (18/08/2026) ---------------------------------

    public function test_the_cell_says_where_its_percentage_comes_from(): void
    {
        Courier::create(['name' => 'Freddy GLS', 'maximum_volume' => 10.0]);

        $lunes = $this->runOn($this->lunes);
        $this->package($lunes, 'Freddy GLS', 3.0);
        $this->package($lunes, 'Freddy GLS', 2.0, ['type' => RunPackage::TYPE_OTHER_ROUTE]);

        $celda = $this->fila(Livewire::test('capacity-calendar'), 'Freddy GLS')['days'][$this->lunes->toDateString()];

        // El reparto es en tanto por uno del día: los dos suman 1, que es lo que
        // se lee al lado del porcentaje.
        $this->assertSame(0.6, $celda['own']);
        $this->assertSame(0.4, $celda['foreign']);
        $this->assertSame(1, $celda['incidents']);

        Livewire::test('capacity-calendar')
            ->assertSee('50 %')
            ->assertSee('60 %')
            ->assertSee('40 %');
    }

    public function test_a_day_with_nothing_out_of_its_route_reads_as_a_hundred_per_cent_its_own(): void
    {
        Courier::create(['name' => 'Freddy GLS', 'maximum_volume' => 10.0]);
        $this->package($this->runOn($this->lunes), 'Freddy GLS', 3.0);

        $celda = $this->fila(Livewire::test('capacity-calendar'), 'Freddy GLS')['days'][$this->lunes->toDateString()];

        // Sin nada fuera, el lado que falta es un cero y no un «no lo sé»: aquí
        // se reparte lo que sí se sabe.
        $this->assertSame(1.0, $celda['own']);
        $this->assertSame(0.0, $celda['foreign']);
        $this->assertSame(0, $celda['incidents']);
    }

    public function test_the_net_volume_is_no_longer_printed_in_the_cell(): void
    {
        // Se quitó el 18/08/2026 a petición: sigue en la fila y en el diálogo,
        // que es donde se va a cuadrar con incidencias.
        Courier::create(['name' => 'Freddy GLS', 'maximum_volume' => 10.0]);
        $this->package($this->runOn($this->lunes), 'Freddy GLS', 3.75);

        $componente = Livewire::test('capacity-calendar')->assertDontSee('3,75');

        $this->assertSame(3.75, $this->fila($componente, 'Freddy GLS')['days'][$this->lunes->toDateString()]['volume']);

        $componente->call('openDetail', 'Freddy GLS', $this->lunes->toDateString())->assertSee('3,75');
    }

    public function test_every_cell_links_to_the_incidents_of_that_route_that_day(): void
    {
        Courier::create(['name' => 'Freddy GLS', 'maximum_volume' => 10.0]);
        $this->package($this->runOn($this->lunes), 'Freddy GLS', 3.0, ['assigned_route_name' => 'Ruta 3']);

        Livewire::test('capacity-calendar')
            ->assertSee(route('incident-run', ['date' => $this->lunes->toDateString(), 'ut' => 'Freddy GLS']));
    }

    public function test_a_cell_without_a_declared_capacity_still_opens_and_splits(): void
    {
        // Sin capacidad no hay porcentaje, pero el día movió volumen y sin el
        // neto en la celda ésa sería la única forma de contar lo que pasó.
        $lunes = $this->runOn($this->lunes);
        $this->package($lunes, 'Quien se fue', 3.0);
        $this->package($lunes, 'Quien se fue', 1.0, ['type' => RunPackage::TYPE_OUT_OF_BATCH]);

        $celda = $this->fila(Livewire::test('capacity-calendar'), 'Quien se fue')['days'][$this->lunes->toDateString()];

        $this->assertNull($celda['usage']);
        $this->assertSame(0.75, $celda['own']);
        $this->assertSame(0.25, $celda['foreign']);

        Livewire::test('capacity-calendar')
            ->call('openDetail', 'Quien se fue', $this->lunes->toDateString())
            ->assertSee('Ocupación del día');
    }

    // --- El desglose de una celda (18/08/2026) --------------------------------

    public function test_clicking_a_percentage_splits_it_between_its_own_route_and_the_rest(): void
    {
        Courier::create(['name' => 'Freddy GLS', 'maximum_volume' => 10.0]);

        $lunes = $this->runOn($this->lunes);
        $this->package($lunes, 'Freddy GLS', 3.0);
        $this->package($lunes, 'Freddy GLS', 1.0, ['type' => RunPackage::TYPE_OTHER_ROUTE]);
        $this->package($lunes, 'Freddy GLS', 1.0, ['type' => RunPackage::TYPE_OUT_OF_BATCH]);

        $detalle = Livewire::test('capacity-calendar')
            ->call('openDetail', 'Freddy GLS', $this->lunes->toDateString())
            ->viewData('detalle');

        $this->assertSame('Freddy GLS', $detalle['label']);
        $this->assertSame(5.0, $detalle['volume']);
        $this->assertSame(0.5, $detalle['usage']);

        [$propio, $ajeno] = $detalle['parts'];

        // Lo que pasó en la tanda de su ruta —`type` nulo— frente a lo que le
        // tocaba y se recogió fuera de ella, de los dos tipos (§3.1).
        $this->assertSame(3.0, $propio['volume']);
        $this->assertSame(0.6, $propio['share']);
        $this->assertSame(1, $propio['shipments']);

        $this->assertSame(2.0, $ajeno['volume']);
        $this->assertSame(0.4, $ajeno['share']);
        $this->assertSame(2, $ajeno['shipments']);

        // Las dos partes reparten el mismo volumen que enseña la celda: sus
        // ocupaciones suman el porcentaje del que se abrió el diálogo.
        $this->assertSame(0.5, $propio['usage'] + $ajeno['usage']);
    }

    public function test_the_breakdown_only_looks_at_that_courier_and_that_day(): void
    {
        Courier::create(['name' => 'Freddy GLS', 'maximum_volume' => 10.0]);

        $lunes = $this->runOn($this->lunes);
        $this->package($lunes, 'Freddy GLS', 2.0);
        $this->package($lunes, 'Otro', 9.0);
        $this->package($lunes, 'Freddy GLS', 8.0, ['withdrawn_at' => now()]);
        $this->package($this->runOn($this->lunes->copy()->addDay()), 'Freddy GLS', 7.0);

        $detalle = Livewire::test('capacity-calendar')
            ->call('openDetail', 'Freddy GLS', $this->lunes->toDateString())
            ->viewData('detalle');

        $this->assertSame(2.0, $detalle['volume']);
        $this->assertSame(1, $detalle['shipments']);
    }

    public function test_the_breakdown_says_how_many_shipments_it_could_measure(): void
    {
        Courier::create(['name' => 'Freddy GLS', 'maximum_volume' => 10.0]);

        $lunes = $this->runOn($this->lunes);
        $this->package($lunes, 'Freddy GLS', 4.0);
        $this->package($lunes, 'Freddy GLS', null, ['type' => RunPackage::TYPE_OTHER_ROUTE]);

        $componente = Livewire::test('capacity-calendar')
            ->call('openDetail', 'Freddy GLS', $this->lunes->toDateString());

        $detalle = $componente->viewData('detalle');

        // El nulo del portal no suma como cero (§3), así que el reparto sólo
        // cubre parte de la jornada y el diálogo lo dice.
        $this->assertSame(2, $detalle['shipments']);
        $this->assertSame(1, $detalle['measured']);
        $this->assertNull($detalle['parts'][1]['volume']);
        $this->assertNull($detalle['parts'][1]['share']);

        // El resto de la frase —«2 envíos del día»— cae en otra línea del Blade.
        $componente->assertSee('El portal dio el volumen de 1 de los');
    }

    public function test_the_row_without_a_courier_can_be_broken_down_too(): void
    {
        $lunes = $this->runOn($this->lunes);
        $this->package($lunes, null, 3.0);
        $this->package($lunes, 'Freddy GLS', 9.0);

        $detalle = Livewire::test('capacity-calendar')
            ->call('openDetail', '', $this->lunes->toDateString())
            ->viewData('detalle');

        // Sin UT no hay capacidad con la que dividir, pero el reparto sigue
        // teniendo sentido: es volumen que existió.
        $this->assertSame('Sin UT asignada', $detalle['label']);
        $this->assertSame(3.0, $detalle['volume']);
        $this->assertNull($detalle['usage']);
        $this->assertSame(1.0, $detalle['parts'][0]['share']);
    }

    public function test_a_cell_without_packages_opens_nothing(): void
    {
        Courier::create(['name' => 'Freddy GLS', 'maximum_volume' => 10.0]);
        $this->runOn($this->lunes);

        Livewire::test('capacity-calendar')
            ->call('openDetail', 'Freddy GLS', $this->lunes->toDateString())
            ->assertOk()
            ->assertViewHas('detalle', null);
    }

    public function test_a_broken_day_in_the_breakdown_does_not_blow_up(): void
    {
        // Los dos parámetros llegan por la red: un día imposible no es un 500.
        Courier::create(['name' => 'Freddy GLS', 'maximum_volume' => 10.0]);

        Livewire::test('capacity-calendar')
            ->call('openDetail', 'Freddy GLS', 'lo-que-sea')
            ->assertOk()
            ->assertViewHas('detalle', null);
    }

    public function test_the_breakdown_closes(): void
    {
        Courier::create(['name' => 'Freddy GLS', 'maximum_volume' => 10.0]);
        $this->package($this->runOn($this->lunes), 'Freddy GLS', 4.0);

        Livewire::test('capacity-calendar')
            ->call('openDetail', 'Freddy GLS', $this->lunes->toDateString())
            ->assertSee('Ocupación del día')
            ->call('closeDetail')
            ->assertSet('detailDay', null)
            ->assertDontSee('Ocupación del día');
    }

    public function test_the_breakdown_leads_to_the_incidents_of_that_day_and_that_route(): void
    {
        Courier::create(['name' => 'Freddy GLS', 'maximum_volume' => 10.0]);

        $lunes = $this->runOn($this->lunes);
        $this->package($lunes, 'Freddy GLS', 3.0, ['assigned_route_name' => 'Ruta 3']);
        $this->package($lunes, 'Otro', 3.0, ['assigned_route_name' => 'Ruta 1']);

        $detalle = Livewire::test('capacity-calendar')
            ->call('openDetail', 'Freddy GLS', $this->lunes->toDateString())
            ->assertSee('Ver las incidencias del día')
            ->viewData('detalle');

        // Se recorre el enlace entero y no sólo su forma: son dos pantallas y
        // el parámetro con el que se entienden vive escrito en las dos.
        $this->get($detalle['incidents'])
            ->assertOk()
            ->assertSee('Resaltando las rutas de')
            ->assertSee('Freddy GLS');
    }

    public function test_the_row_without_a_courier_also_leads_to_its_incidents(): void
    {
        $lunes = $this->runOn($this->lunes);
        $this->package($lunes, null, 3.0, ['assigned_route_name' => 'Ruta 3']);

        $detalle = Livewire::test('capacity-calendar')
            ->call('openDetail', '', $this->lunes->toDateString())
            ->viewData('detalle');

        // El centinela de la fila «Sin UT asignada»: un `?ut=` vacío sería «sin
        // filtro» y la jornada llegaría sin resaltar nada.
        $this->assertStringContainsString('ut=sin-ut', $detalle['incidents']);

        $this->get($detalle['incidents'])
            ->assertOk()
            ->assertSee('Resaltando las rutas de')
            ->assertSee('Sin UT asignada');
    }

    // --- Consultas ------------------------------------------------------------

    public function test_the_whole_table_is_five_queries(): void
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

        // Corridas, agregado, maestro, la configuración de la pantalla (§7, fase 11) y el
        // gasto fijo del mes (fase 15). Cinco, y las mismas cinco con 5 UT que con 50 —y
        // aunque la semana cruce de mes—: la tabla se arma en SQL, no fila a fila.
        $this->assertSame(5, $consultas);
    }

    public function test_the_breakdown_only_costs_two_queries_when_it_is_open(): void
    {
        $ruta = PickupRoute::create(['name' => '1']);
        Courier::create(['name' => 'Freddy GLS', 'maximum_volume' => 10.0, 'pickup_route_id' => $ruta->id]);
        $this->package($this->runOn($this->lunes), 'Freddy GLS', 4.0, ['assigned_route_id' => $ruta->id]);

        $componente = Livewire::test('capacity-calendar');

        $consultas = 0;
        \DB::listen(function () use (&$consultas) {
            $consultas++;
        });

        $componente->call('openDetail', 'Freddy GLS', $this->lunes->toDateString());

        // Las cinco de la tabla, la del reparto y la del desglose de gastos concepto a
        // concepto: con el diálogo cerrado la pantalla no paga ninguna de las dos.
        $this->assertSame(7, $consultas);
    }

    // --- La ganancia en la celda (20/08/2026, a petición del cliente) --------------

    /**
     * Debajo del reparto, lo que se facturó por esos paquetes. Es la misma suma que ya hacía
     * el diálogo, pero en la tabla: se pedía poder recorrer la semana sin abrir una celda.
     */
    public function test_the_cell_adds_up_the_revenue_of_the_day(): void
    {
        Courier::create(['name' => 'Freddy GLS', 'maximum_volume' => 10]);
        $lunes = $this->runOn($this->lunes);

        $this->package($lunes, 'Freddy GLS', 3.0, ['net_revenue' => 10.00]);
        $this->package($lunes, 'Freddy GLS', 1.0, ['type' => RunPackage::TYPE_OTHER_ROUTE, 'net_revenue' => 4.15]);

        $celda = $this->fila(Livewire::test('capacity-calendar'), 'Freddy GLS')['days'][$this->lunes->toDateString()];

        // Las dos mitades del reparto suman en la misma celda: la tabla enseña el día entero.
        $this->assertSame(14.15, (float) $celda['revenue']);
        $this->assertSame(2, $celda['priced']);
    }

    /**
     * La cobertura va aparte del recuento de envíos, y no puede salir del de volumen: son
     * dos huecos distintos —el portal no dio el volumen, Envexpress no tenía el envío— y
     * coinciden por casualidad.
     */
    public function test_the_cell_counts_the_revenue_on_its_own_shipments(): void
    {
        Courier::create(['name' => 'Freddy GLS', 'maximum_volume' => 10]);
        $lunes = $this->runOn($this->lunes);

        $this->package($lunes, 'Freddy GLS', 4.0, ['net_revenue' => 8.60]);
        $this->package($lunes, 'Freddy GLS', 1.0, ['net_revenue' => null]);
        $this->package($lunes, 'Freddy GLS', null, ['net_revenue' => 2.40]);

        $celda = $this->fila(Livewire::test('capacity-calendar'), 'Freddy GLS')['days'][$this->lunes->toDateString()];

        $this->assertSame(3, $celda['shipments']);
        $this->assertSame(2, $celda['measured']);   // los que traen volumen
        $this->assertSame(2, $celda['priced']);     // los que traen ganancia, que son otros
        $this->assertSame(11.00, (float) $celda['revenue']);
    }

    /**
     * Sin una sola valoración —una jornada anterior a la v4 del payload— la celda no dice
     * 0,00 €, que sería mentira: no pinta la línea. Mismo criterio que el volumen (§3.1).
     */
    public function test_a_day_without_any_revenue_has_no_amount_in_the_cell(): void
    {
        Courier::create(['name' => 'Freddy GLS', 'maximum_volume' => 10]);
        $lunes = $this->runOn($this->lunes);

        $this->package($lunes, 'Freddy GLS', 4.0, ['net_revenue' => null]);

        $celda = $this->fila(Livewire::test('capacity-calendar'), 'Freddy GLS')['days'][$this->lunes->toDateString()];

        $this->assertNull($celda['revenue']);
        $this->assertSame(0, $celda['priced']);
    }

    public function test_the_cell_prints_the_amount_in_euros(): void
    {
        Courier::create(['name' => 'Freddy GLS', 'maximum_volume' => 10]);
        $lunes = $this->runOn($this->lunes);

        $this->package($lunes, 'Freddy GLS', 4.0, ['net_revenue' => 1011.86]);

        Livewire::test('capacity-calendar')->assertSee('1.011,86 €');
    }

    /** Cada día el suyo: la ganancia no se arrastra de una columna a otra. */
    public function test_each_day_keeps_its_own_revenue(): void
    {
        Courier::create(['name' => 'Freddy GLS', 'maximum_volume' => 10]);

        $this->package($this->runOn($this->lunes), 'Freddy GLS', 4.0, ['net_revenue' => 10.00]);
        $this->package($this->runOn($this->lunes->copy()->addDay()), 'Freddy GLS', 4.0, ['net_revenue' => 25.50]);

        $fila = $this->fila(Livewire::test('capacity-calendar'), 'Freddy GLS');

        $this->assertSame(10.00, (float) $fila['days'][$this->lunes->toDateString()]['revenue']);
        $this->assertSame(25.50, (float) $fila['days'][$this->lunes->copy()->addDay()->toDateString()]['revenue']);
    }

    // --- El coste del día (20/08/2026, a petición del cliente) ---------------------

    /** Un gasto mensual vivo en esa ruta, para repartir. */
    private function gastoMensual(PickupRoute $ruta, string $importe, ?Carbon $desde = null): RouteExpense
    {
        static $n = 0;

        return RouteExpense::create([
            'pickup_route_id' => $ruta->id,
            'expense_id' => Expense::create(['name' => 'Concepto '.(++$n)])->id,
            'amount' => $importe,
            'recurrent' => true,
            'starts_on' => ($desde ?? $this->lunes)->copy()->startOfMonth()->toDateString(),
            'ends_on' => null,
        ]);
    }

    /**
     * Los gastos se llevan al mes y las corridas son diarias, así que el fijo se reparte
     * entre los días laborables del mes —lunes a viernes— y se le suma el coste real de los
     * envíos de ese día.
     */
    public function test_the_monthly_expense_is_split_across_the_working_days(): void
    {
        $ruta = PickupRoute::create(['name' => '1']);
        Courier::create(['name' => 'Freddy GLS', 'maximum_volume' => 10, 'pickup_route_id' => $ruta->id]);
        $this->gastoMensual($ruta, '1000.00');

        $this->package($this->runOn($this->lunes), 'Freddy GLS', 4.0, ['assigned_route_id' => $ruta->id]);

        $laborables = DailyExpenseShare::workingDays($this->lunes);
        $celda = $this->fila(Livewire::test('capacity-calendar'), 'Freddy GLS')['days'][$this->lunes->toDateString()];

        $this->assertSame(round(1000 / $laborables, 6), round($celda['fixed'], 6));
    }

    /** El divisor es de lunes a viernes: los fines de semana no cuentan. */
    public function test_the_working_days_of_a_month_are_monday_to_friday(): void
    {
        // Agosto de 2026 empieza en sábado y tiene 21 días laborables.
        $this->assertSame(21, DailyExpenseShare::workingDays(Carbon::parse('2026-08-15')));

        // Febrero de 2026, 20.
        $this->assertSame(20, DailyExpenseShare::workingDays(Carbon::parse('2026-02-10')));
    }

    /** Las dos mitades se suman: el fijo prorrateado más lo que costaron los envíos. */
    public function test_the_daily_cost_adds_the_prorated_expense_and_the_real_cost(): void
    {
        $ruta = PickupRoute::create(['name' => '1']);
        Courier::create(['name' => 'Freddy GLS', 'maximum_volume' => 10, 'pickup_route_id' => $ruta->id]);
        $this->gastoMensual($ruta, '1000.00');

        $run = $this->runOn($this->lunes);
        $this->package($run, 'Freddy GLS', 4.0, ['assigned_route_id' => $ruta->id, 'real_cost' => 2.20]);
        $this->package($run, 'Freddy GLS', 1.0, ['assigned_route_id' => $ruta->id, 'real_cost' => 3.30]);

        $fijo = 1000 / DailyExpenseShare::workingDays($this->lunes);
        $celda = $this->fila(Livewire::test('capacity-calendar'), 'Freddy GLS')['days'][$this->lunes->toDateString()];

        $this->assertSame(5.50, round($celda['real'], 2));
        $this->assertSame(2, $celda['costed']);
        $this->assertSame(round($fijo + 5.50, 6), round($celda['cost'], 6));
    }

    /**
     * Sin un solo `real_cost` —hoy, que el bot todavía no manda la v5— el coste es sólo el
     * fijo, y la mitad real se dice «no se sabe» en vez de fingir un cero.
     */
    public function test_without_any_real_cost_only_the_fixed_half_counts(): void
    {
        $ruta = PickupRoute::create(['name' => '1']);
        Courier::create(['name' => 'Freddy GLS', 'maximum_volume' => 10, 'pickup_route_id' => $ruta->id]);
        $this->gastoMensual($ruta, '1000.00');

        $this->package($this->runOn($this->lunes), 'Freddy GLS', 4.0, ['assigned_route_id' => $ruta->id]);

        $celda = $this->fila(Livewire::test('capacity-calendar'), 'Freddy GLS')['days'][$this->lunes->toDateString()];

        $this->assertNull($celda['real']);
        $this->assertSame(0, $celda['costed']);
        $this->assertSame(round($celda['fixed'], 6), round($celda['cost'], 6));
    }

    /** Y una ruta sin gastos dados de alta no pinta coste: cero diría que mantenerla es gratis. */
    public function test_a_route_without_expenses_has_no_cost_at_all(): void
    {
        $ruta = PickupRoute::create(['name' => '1']);
        Courier::create(['name' => 'Freddy GLS', 'maximum_volume' => 10, 'pickup_route_id' => $ruta->id]);

        $this->package($this->runOn($this->lunes), 'Freddy GLS', 4.0, ['assigned_route_id' => $ruta->id]);

        $celda = $this->fila(Livewire::test('capacity-calendar'), 'Freddy GLS')['days'][$this->lunes->toDateString()];

        $this->assertNull($celda['fixed']);
        $this->assertNull($celda['cost']);
    }

    /** Cada UT carga con los gastos de su ruta, no con los de la de al lado. */
    public function test_each_route_carries_only_its_own_expenses(): void
    {
        $suya = PickupRoute::create(['name' => '1']);
        $ajena = PickupRoute::create(['name' => '3']);
        Courier::create(['name' => 'Freddy GLS', 'maximum_volume' => 10, 'pickup_route_id' => $suya->id]);

        $this->gastoMensual($suya, '1000.00');
        $this->gastoMensual($ajena, '9999.00');

        $this->package($this->runOn($this->lunes), 'Freddy GLS', 4.0, ['assigned_route_id' => $suya->id]);

        $celda = $this->fila(Livewire::test('capacity-calendar'), 'Freddy GLS')['days'][$this->lunes->toDateString()];

        $this->assertSame(
            round(1000 / DailyExpenseShare::workingDays($this->lunes), 6),
            round($celda['fixed'], 6),
        );
    }

    /**
     * Un gasto que empieza el mes que viene no cuenta en éste. Es lo que hace que cerrar una
     * línea y abrir otra no reescriba el pasado (fase 15).
     */
    public function test_an_expense_from_another_month_does_not_count(): void
    {
        $ruta = PickupRoute::create(['name' => '1']);
        Courier::create(['name' => 'Freddy GLS', 'maximum_volume' => 10, 'pickup_route_id' => $ruta->id]);
        $this->gastoMensual($ruta, '1000.00', $this->lunes->copy()->addMonth());

        $this->package($this->runOn($this->lunes), 'Freddy GLS', 4.0, ['assigned_route_id' => $ruta->id]);

        $celda = $this->fila(Livewire::test('capacity-calendar'), 'Freddy GLS')['days'][$this->lunes->toDateString()];

        $this->assertNull($celda['fixed']);
    }

    /** Y el importe sale en la celda, restando. */
    public function test_the_cell_prints_the_cost(): void
    {
        $ruta = PickupRoute::create(['name' => '1']);
        Courier::create(['name' => 'Freddy GLS', 'maximum_volume' => 10, 'pickup_route_id' => $ruta->id]);
        $this->gastoMensual($ruta, '1000.00');

        $this->package($this->runOn($this->lunes), 'Freddy GLS', 4.0, ['assigned_route_id' => $ruta->id]);

        $esperado = number_format(1000 / DailyExpenseShare::workingDays($this->lunes), 2, ',', '.');

        Livewire::test('capacity-calendar')->assertSee('−'.$esperado.' €');
    }

    // --- El coste en el desglose (20/08/2026, a petición del cliente) ----------------

    /**
     * En la celda cabe un total; quien abre el diálogo viene a ver **de dónde sale**. Así que
     * el gasto fijo va concepto a concepto, con su importe mensual y lo que le toca al día.
     */
    public function test_the_breakdown_itemises_the_expenses_concept_by_concept(): void
    {
        $ruta = PickupRoute::create(['name' => '1']);
        Courier::create(['name' => 'Freddy GLS', 'maximum_volume' => 10, 'pickup_route_id' => $ruta->id]);

        RouteExpense::create([
            'pickup_route_id' => $ruta->id,
            'expense_id' => Expense::create(['name' => 'Pago al transportista'])->id,
            'amount' => '1200.00', 'recurrent' => true,
            'starts_on' => $this->lunes->copy()->startOfMonth()->toDateString(), 'ends_on' => null,
        ]);
        RouteExpense::create([
            'pickup_route_id' => $ruta->id,
            'expense_id' => Expense::create(['name' => 'Gasolina'])->id,
            'amount' => '400.00', 'recurrent' => true,
            'starts_on' => $this->lunes->copy()->startOfMonth()->toDateString(), 'ends_on' => null,
        ]);

        $this->package($this->runOn($this->lunes), 'Freddy GLS', 4.0, ['assigned_route_id' => $ruta->id]);

        $detalle = Livewire::test('capacity-calendar')
            ->call('openDetail', 'Freddy GLS', $this->lunes->toDateString())
            ->viewData('detalle');

        $laborables = DailyExpenseShare::workingDays($this->lunes);

        $this->assertSame($laborables, $detalle['workingDays']);
        $this->assertCount(2, $detalle['expenses']);

        // De mayor a menor: lo que más pesa es lo primero que se busca.
        $this->assertSame('Pago al transportista', $detalle['expenses'][0]['label']);
        $this->assertSame(1200.0, $detalle['expenses'][0]['monthly']);
        $this->assertSame(round(1200 / $laborables, 6), round($detalle['expenses'][0]['daily'], 6));

        $this->assertSame('Gasolina', $detalle['expenses'][1]['label']);
        $this->assertSame(round(1600 / $laborables, 6), round($detalle['fixed'], 6));
    }

    public function test_the_breakdown_shows_the_itemised_amounts_on_screen(): void
    {
        $ruta = PickupRoute::create(['name' => '1']);
        Courier::create(['name' => 'Freddy GLS', 'maximum_volume' => 10, 'pickup_route_id' => $ruta->id]);

        RouteExpense::create([
            'pickup_route_id' => $ruta->id,
            'expense_id' => Expense::create(['name' => 'Gasolina'])->id,
            'amount' => '400.00', 'recurrent' => true,
            'starts_on' => $this->lunes->copy()->startOfMonth()->toDateString(), 'ends_on' => null,
        ]);

        $this->package($this->runOn($this->lunes), 'Freddy GLS', 4.0, [
            'assigned_route_id' => $ruta->id, 'real_cost' => 2.20,
        ]);

        $laborables = DailyExpenseShare::workingDays($this->lunes);

        Livewire::test('capacity-calendar')
            ->call('openDetail', 'Freddy GLS', $this->lunes->toDateString())
            ->assertSee('Coste del día')
            ->assertSee('Gasolina')
            // Con la división a la vista, para que el número no haya que creérselo.
            ->assertSee('400,00 €/mes ÷ '.$laborables.' días laborables')
            ->assertSee('Coste real de los envíos')
            ->assertSee('2,20 €');
    }

    /** Las dos mitades se suman en el total del diálogo, igual que en la celda. */
    public function test_the_breakdown_totals_both_halves_of_the_cost(): void
    {
        $ruta = PickupRoute::create(['name' => '1']);
        Courier::create(['name' => 'Freddy GLS', 'maximum_volume' => 10, 'pickup_route_id' => $ruta->id]);

        RouteExpense::create([
            'pickup_route_id' => $ruta->id,
            'expense_id' => Expense::create(['name' => 'Gasolina'])->id,
            'amount' => '420.00', 'recurrent' => true,
            'starts_on' => $this->lunes->copy()->startOfMonth()->toDateString(), 'ends_on' => null,
        ]);

        $run = $this->runOn($this->lunes);
        $this->package($run, 'Freddy GLS', 4.0, ['assigned_route_id' => $ruta->id, 'real_cost' => 2.20]);
        $this->package($run, 'Freddy GLS', 1.0, ['assigned_route_id' => $ruta->id, 'real_cost' => null]);

        $detalle = Livewire::test('capacity-calendar')
            ->call('openDetail', 'Freddy GLS', $this->lunes->toDateString())
            ->viewData('detalle');

        $fijo = 420 / DailyExpenseShare::workingDays($this->lunes);

        $this->assertSame(2.20, round($detalle['real'], 2));
        $this->assertSame(1, $detalle['costed']);
        $this->assertSame(2, $detalle['shipments']);
        $this->assertSame(round($fijo + 2.20, 6), round($detalle['cost'], 6));
    }

    /** Y una ruta sin gastos lo dice, en vez de enseñar un cero que sería mentira. */
    public function test_the_breakdown_says_when_the_route_has_no_expenses(): void
    {
        $ruta = PickupRoute::create(['name' => '1']);
        Courier::create(['name' => 'Freddy GLS', 'maximum_volume' => 10, 'pickup_route_id' => $ruta->id]);

        $this->package($this->runOn($this->lunes), 'Freddy GLS', 4.0, [
            'assigned_route_id' => $ruta->id, 'real_cost' => 2.20,
        ]);

        Livewire::test('capacity-calendar')
            ->call('openDetail', 'Freddy GLS', $this->lunes->toDateString())
            ->assertSee('Esta ruta no tiene gastos dados de alta este mes')
            ->assertViewHas('detalle', fn ($d) => $d['fixed'] === null && round($d['cost'], 2) === 2.20);
    }

    // --- La rentabilidad del día (20/08/2026, a petición del cliente) -----------------

    /** Ganancia, gasto fijo y coste real, ya montados, para las cuentas de abajo. */
    private function diaCon(?string $ganancia, string $mensual, ?string $costeReal): array
    {
        $ruta = PickupRoute::create(['name' => '1']);
        Courier::create(['name' => 'Freddy GLS', 'maximum_volume' => 10, 'pickup_route_id' => $ruta->id]);

        RouteExpense::create([
            'pickup_route_id' => $ruta->id,
            'expense_id' => Expense::create(['name' => 'Salario'])->id,
            'amount' => $mensual, 'recurrent' => true,
            'starts_on' => $this->lunes->copy()->startOfMonth()->toDateString(), 'ends_on' => null,
        ]);

        $this->package($this->runOn($this->lunes), 'Freddy GLS', 4.0, [
            'assigned_route_id' => $ruta->id,
            'net_revenue' => $ganancia,
            'real_cost' => $costeReal,
        ]);

        return Livewire::test('capacity-calendar')
            ->call('openDetail', 'Freddy GLS', $this->lunes->toDateString())
            ->viewData('detalle');
    }

    /**
     * `(ganancia − coste) / coste`. **Sobre el coste y no sobre lo facturado**: son dos ratios
     * distintos con los mismos datos —aquí, 50 % frente a 33 %— y a simple vista parecen el
     * mismo, así que el que se enseña tiene que estar fijado.
     */
    public function test_the_profitability_is_the_margin_over_the_cost(): void
    {
        // 21 laborables en agosto de 2026: 420 €/mes son 20 € al día. Con 80 € de coste real,
        // el coste del día son 100 € clavados y la ganancia 150 €.
        $detalle = $this->diaCon('150.00', '420.00', '80.00');

        $this->assertSame(100.0, round($detalle['cost'], 2));
        $this->assertSame(50.0, round($detalle['margin'], 2));
        $this->assertSame(0.5, round($detalle['profitability'], 4));

        // Sobre lo facturado saldría un 33 %: no es el que se enseña.
        $this->assertNotSame(round(50 / 150, 4), round($detalle['profitability'], 4));
    }

    public function test_a_day_that_loses_money_shows_it_in_negative(): void
    {
        $detalle = $this->diaCon('80.00', '420.00', '80.00');

        $this->assertSame(-20.0, round($detalle['margin'], 2));
        $this->assertSame(-0.2, round($detalle['profitability'], 4));
    }

    public function test_the_dialog_prints_the_profitability(): void
    {
        $this->diaCon('150.00', '420.00', '80.00');

        Livewire::test('capacity-calendar')
            ->call('openDetail', 'Freddy GLS', $this->lunes->toDateString())
            ->assertSee('Rentabilidad')
            ->assertSee('+50,00 %')
            ->assertSee('+50,00 € sobre un coste de 100,00 €');
    }

    /**
     * El orden de la tarjeta, que se pidió expresamente: primero el dinero —ganancia, coste y
     * lo que queda— y la ocupación al final, como contexto del reparto que viene debajo.
     */
    public function test_the_card_puts_the_money_first_and_the_occupancy_last(): void
    {
        $this->diaCon('150.00', '420.00', '80.00');

        // Sobre el HTML y no con `assertSeeInOrder`: el de Livewire mira el JSON de la
        // respuesta, donde los acentos van escapados y «Ocupación» no aparece tal cual.
        $html = Livewire::test('capacity-calendar')
            ->call('openDetail', 'Freddy GLS', $this->lunes->toDateString())
            ->html();

        $posiciones = collect(['Ganancia de sus rutas', 'Coste del día', 'Rentabilidad', 'Ocupación del día', 'De su ruta'])
            ->map(function (string $rotulo) use ($html) {
                $donde = mb_strpos($html, $rotulo);
                $this->assertNotFalse($donde, "No se pinta «{$rotulo}».");

                return $donde;
            });

        $this->assertSame($posiciones->sort()->values()->all(), $posiciones->all());
    }

    /**
     * Sin coste no hay divisor: nula, y la fila no se pinta. Un porcentaje sobre un coste que
     * no se sabe sería la cifra más creíble y más falsa de la pantalla.
     */
    public function test_without_a_cost_there_is_no_profitability(): void
    {
        $ruta = PickupRoute::create(['name' => '1']);
        Courier::create(['name' => 'Freddy GLS', 'maximum_volume' => 10, 'pickup_route_id' => $ruta->id]);

        $this->package($this->runOn($this->lunes), 'Freddy GLS', 4.0, [
            'assigned_route_id' => $ruta->id, 'net_revenue' => 150.00,
        ]);

        $detalle = Livewire::test('capacity-calendar')
            ->call('openDetail', 'Freddy GLS', $this->lunes->toDateString())
            ->viewData('detalle');

        $this->assertNull($detalle['cost']);
        $this->assertNull($detalle['margin']);
        $this->assertNull($detalle['profitability']);

        Livewire::test('capacity-calendar')
            ->call('openDetail', 'Freddy GLS', $this->lunes->toDateString())
            ->assertDontSee('Rentabilidad');
    }

    /** Y sin ganancia tampoco: restarle el coste a lo que no se sabe no da un margen. */
    public function test_without_revenue_there_is_no_profitability(): void
    {
        $detalle = $this->diaCon(null, '420.00', '80.00');

        $this->assertNull($detalle['revenue']);
        $this->assertNull($detalle['margin']);
        $this->assertNull($detalle['profitability']);
    }

    /**
     * La ganancia en el desglose (19/08/2026, a petición del cliente).
     *
     * Va partida igual que el volumen porque la pregunta que trae a alguien a este diálogo
     * es la misma: **cuánto de lo que le tocaba a esta UT acabó en otra furgoneta** — sólo
     * que en euros en vez de en metros cúbicos.
     */
    public function test_the_breakdown_splits_the_revenue_like_the_volume(): void
    {
        Courier::create(['name' => 'Freddy GLS', 'maximum_volume' => 10.0]);

        $lunes = $this->runOn($this->lunes);
        $this->package($lunes, 'Freddy GLS', 3.0, ['net_revenue' => 10.00]);
        $this->package($lunes, 'Freddy GLS', 1.0, ['type' => RunPackage::TYPE_OTHER_ROUTE, 'net_revenue' => 4.15]);

        $detalle = Livewire::test('capacity-calendar')
            ->call('openDetail', 'Freddy GLS', $this->lunes->toDateString())
            ->viewData('detalle');

        $this->assertSame(14.15, $detalle['revenue']);
        $this->assertSame(2, $detalle['priced']);

        [$propio, $ajeno] = $detalle['parts'];
        $this->assertSame(10.00, $propio['revenue']);
        $this->assertSame(4.15, $ajeno['revenue']);
    }

    /**
     * Un envío que no está en Envexpress no suma como cero, igual que el volumen: el
     * importe va con **su propia** cuenta, que no tiene por qué ser la del volumen.
     */
    public function test_the_breakdown_counts_the_revenue_on_its_own_shipments(): void
    {
        Courier::create(['name' => 'Freddy GLS', 'maximum_volume' => 10.0]);

        $lunes = $this->runOn($this->lunes);
        // Uno con las dos cosas, uno con volumen pero sin valoración, y uno al revés.
        $this->package($lunes, 'Freddy GLS', 4.0, ['net_revenue' => 8.60]);
        $this->package($lunes, 'Freddy GLS', 1.0, ['net_revenue' => null]);
        $this->package($lunes, 'Freddy GLS', null, ['net_revenue' => 2.40]);

        $detalle = Livewire::test('capacity-calendar')
            ->call('openDetail', 'Freddy GLS', $this->lunes->toDateString())
            ->viewData('detalle');

        $this->assertSame(3, $detalle['shipments']);
        $this->assertSame(2, $detalle['measured']);   // los que traen volumen
        $this->assertSame(2, $detalle['priced']);     // los que traen ganancia, que son otros
        $this->assertSame(11.00, $detalle['revenue']);
    }

    /** Sin una sola valoración el importe es «no se sabe», no 0,00 €. */
    public function test_a_day_without_any_revenue_shows_no_amount(): void
    {
        Courier::create(['name' => 'Freddy GLS', 'maximum_volume' => 10.0]);

        $lunes = $this->runOn($this->lunes);
        $this->package($lunes, 'Freddy GLS', 4.0, ['net_revenue' => null]);

        $componente = Livewire::test('capacity-calendar')
            ->call('openDetail', 'Freddy GLS', $this->lunes->toDateString());

        $this->assertNull($componente->viewData('detalle')['revenue']);
        $componente->assertDontSee('0,00 €');
    }

    /**
     * **«De sus rutas», nunca «del día»** (§7, fase 13.C, regla 2). Aquí sólo están los
     * envíos de esta UT: rotularlo como la ganancia del día sería aún más falso que en la
     * pantalla de la jornada, donde al menos están todas las rutas.
     */
    public function test_the_amount_is_labelled_as_this_courier_routes(): void
    {
        Courier::create(['name' => 'Freddy GLS', 'maximum_volume' => 10.0]);

        $lunes = $this->runOn($this->lunes);
        $this->package($lunes, 'Freddy GLS', 4.0, ['net_revenue' => 8.60]);

        $componente = Livewire::test('capacity-calendar')
            ->call('openDetail', 'Freddy GLS', $this->lunes->toDateString());

        $componente->assertSee('Ganancia de sus rutas');
        $componente->assertSee('8,60 €');
        $componente->assertDontSee('Ganancia del día');
    }

    /**
     * **El dinero no sigue al volumen**, y por eso cada parte enseña su importe y no una
     * fracción del volumen: un envío voluminoso puede facturar poco y uno pequeño mucho.
     * Aquí el volumen va 90/10 y el dinero justo al revés.
     */
    public function test_the_revenue_of_a_part_does_not_follow_its_volume(): void
    {
        Courier::create(['name' => 'Freddy GLS', 'maximum_volume' => 10.0]);

        $lunes = $this->runOn($this->lunes);
        // Un bulto grande y barato con su ruta…
        $this->package($lunes, 'Freddy GLS', 9.0, ['net_revenue' => 5.00]);
        // …y uno pequeño y caro que se fue con otra.
        $this->package($lunes, 'Freddy GLS', 1.0, ['type' => RunPackage::TYPE_OTHER_ROUTE, 'net_revenue' => 15.00]);

        $componente = Livewire::test('capacity-calendar')
            ->call('openDetail', 'Freddy GLS', $this->lunes->toDateString());

        [$propio, $ajeno] = $componente->viewData('detalle')['parts'];

        $this->assertSame(0.9, $propio['share']);
        $this->assertSame(5.00, $propio['revenue']);

        $this->assertSame(0.1, $ajeno['share']);
        $this->assertSame(15.00, $ajeno['revenue']);

        // Lo que se lee en el diálogo es el importe, no un porcentaje del dinero.
        $componente->assertSee('15,00 €');
        $componente->assertSee('ganancia neta');
    }

    /** Sin valoraciones el importe de la parte es «—», no 0,00 €. */
    public function test_a_part_without_any_revenue_shows_a_dash(): void
    {
        Courier::create(['name' => 'Freddy GLS', 'maximum_volume' => 10.0]);

        $lunes = $this->runOn($this->lunes);
        $this->package($lunes, 'Freddy GLS', 4.0, ['net_revenue' => null]);
        $this->package($lunes, 'Freddy GLS', 1.0, ['type' => RunPackage::TYPE_OTHER_ROUTE, 'net_revenue' => null]);

        $componente = Livewire::test('capacity-calendar')
            ->call('openDetail', 'Freddy GLS', $this->lunes->toDateString());

        [$propio, $ajeno] = $componente->viewData('detalle')['parts'];

        $this->assertNull($propio['revenue']);
        $this->assertNull($ajeno['revenue']);
        $componente->assertDontSee('0,00 €');

        // El reparto del volumen sigue estando: no depende del dinero.
        $this->assertSame(0.8, $propio['share']);
    }
}
