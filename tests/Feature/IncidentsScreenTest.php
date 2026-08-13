<?php

namespace Tests\Feature;

use App\Models\RunPackage;
use App\Models\IncidentRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Listado de jornadas (CONTEXTO.md §7, fase 6.C).
 *
 * Va aparte de los CRUD porque no lo es: aquí no se da de alta nada. Lo que
 * sube el bot es de sólo lectura para el panel.
 */
class IncidentsScreenTest extends TestCase
{
    use RefreshDatabase, MakesIncidents;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_it_is_behind_the_session(): void
    {
        auth()->logout();

        $this->get('/incidents')->assertRedirect('/login');
    }

    public function test_the_sidebar_offers_it_inside_the_operations_group(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Operaciones')
            ->assertSee('Incidencias')
            ->assertSee('href="'.route('incidents').'"', escape: false);
    }

    /**
     * El grupo nace abierto si estás dentro. `wire:navigate` reconstruye la
     * página y el estado de Alpine no sobrevive al salto, así que sin esto el
     * grupo se cerraría justo al llegar a la pantalla que abriste desde él.
     */
    public function test_the_operations_group_opens_when_you_are_inside_it(): void
    {
        $this->get('/incidents')->assertOk()->assertSee('abierto: true', escape: false);

        $this->get('/merchants')->assertOk()->assertSee('abierto: false', escape: false);
    }

    public function test_it_says_so_when_no_run_has_arrived(): void
    {
        $this->get('/incidents')
            ->assertOk()
            ->assertSee('Todavía no ha llegado ninguna jornada');
    }

    public function test_each_run_links_to_its_detail(): void
    {
        $this->storedRun();

        $this->get('/incidents')
            ->assertOk()
            ->assertSee(route('incident-run', '2026-08-03'), escape: false);
    }

    /**
     * La cifra grande es la de hallazgos firmes, no la de incidencias: poner
     * 168 en grande anuncia un incendio que el propio bot no sostiene.
     */
    public function test_it_counts_the_firm_findings_apart(): void
    {
        $corrida = $this->storedRun();
        $this->incident($corrida, '1', confidence: RunPackage::CONFIDENCE_HIGH);
        $this->incident($corrida, '2', confidence: RunPackage::CONFIDENCE_LOW);
        $this->incident($corrida, '3', confidence: RunPackage::CONFIDENCE_LOW);

        // Dos columnas y dos cifras distintas: 3 incidencias, de las que el bot
        // sostiene 1. Poner sólo el total anunciaría un incendio que él mismo
        // no sostiene — del 03/08 real, 168 contra 8.
        $jornada = \Livewire\Livewire::test('incidents')
            ->assertSee('Incidencias')
            ->assertSee('Firmes')
            ->viewData('jornadas')
            ->first();

        $this->assertSame(3, $jornada->incidencias);
        $this->assertSame(1, $jornada->firmes);
    }

    /** Una retirada dejó de venir en un reenvío: no cuenta, pero no se borra. */
    public function test_withdrawn_incidents_do_not_count(): void
    {
        $corrida = $this->storedRun();
        $this->incident($corrida, '1');
        $this->incident($corrida, '2')->update(['withdrawn_at' => now()]);

        // La columna «Incidencias» dice 1, no 2: la retirada sigue en la base
        // pero fuera del recuento.
        $this->assertSame(1, $corrida->currentIncidents()->count());
        $this->assertSame(2, $corrida->packages()->count());

        $this->get('/incidents')->assertOk()->assertSee('03/08/2026');
    }

    public function test_an_unreliable_run_says_so(): void
    {
        $this->storedRun(reliable: false);

        $this->get('/incidents')->assertOk()->assertSee('no fiable');
    }

    /** El listado es una tabla paginada, con su recuento en el pie. */
    public function test_it_is_a_table_with_a_footer_count(): void
    {
        $this->storedRun(date: '2026-08-03');
        $this->storedRun(date: '2026-08-04');

        $this->get('/incidents')
            ->assertOk()
            ->assertSee('<table', escape: false)
            ->assertSeeInOrder(['Jornada', 'Evaluados', 'Incidencias', 'Firmes'])
            ->assertSee('2 jornadas');
    }

    public function test_the_date_filter_narrows_the_list(): void
    {
        $this->storedRun(date: '2026-08-03');
        $this->storedRun(date: '2026-08-10');

        $this->get('/incidents?desde=2026-08-05')
            ->assertOk()
            ->assertSee('10/08/2026')
            ->assertDontSee('03/08/2026');

        $this->get('/incidents?hasta=2026-08-05')
            ->assertOk()
            ->assertSee('03/08/2026')
            ->assertDontSee('10/08/2026');
    }

    public function test_the_filter_explains_an_empty_result(): void
    {
        $this->storedRun(date: '2026-08-03');

        $this->get('/incidents?desde=2026-09-01')
            ->assertOk()
            ->assertSee('Ninguna jornada en esas fechas');
    }

    /**
     * La fecha imposible llega por la URL, no por el `<input type="date">`.
     * Sin el guardarraíl, Postgres revienta y el cliente ve un 500.
     */
    public function test_a_broken_date_in_the_url_does_not_blow_up(): void
    {
        $this->storedRun();

        $this->get('/incidents?desde=lo-que-sea')
            ->assertOk()
            ->assertSee('03/08/2026');
    }

    /** El listado crece una fila al día: una consulta, no una por jornada. */
    public function test_it_does_not_query_once_per_run(): void
    {
        foreach (['2026-08-01', '2026-08-02', '2026-08-03', '2026-08-04'] as $fecha) {
            $corrida = $this->storedRun(date: $fecha);
            $this->incident($corrida, '1');
        }

        $consultas = 0;
        \DB::listen(function () use (&$consultas) {
            $consultas++;
        });

        $this->get('/incidents')->assertOk();

        $this->assertLessThan(10, $consultas, "El listado disparó {$consultas} consultas.");
    }
}
