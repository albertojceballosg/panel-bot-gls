<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\PickupRoute;
use App\Models\RouteExpense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Lo que cuesta cada ruta al mes (CONTEXTO.md §7, fase 15).
 *
 * Aquí está el dinero y aquí está el riesgo. Lo que fija este test, por encima del CRUD:
 *
 * - **El importe es de la ruta, no del concepto**: la misma «Gasolina» cuesta distinto en la
 *   Ruta 1 y en la Ruta 3, que es la razón de que esta tabla exista.
 * - **Recurrente frente a puntual**: el sueldo aparece todos los meses, la reparación sólo en
 *   el suyo. Es lo que el cliente pidió poder distinguir.
 * - **Un mes no arrastra lo que no le toca**: ni lo que empezó después, ni lo que ya se cerró.
 * - **Nada se solapa**: dos líneas del mismo concepto en la misma ruta pisándose duplicarían
 *   el gasto de un mes sin que nadie lo notase.
 */
class RouteExpensesCrudTest extends TestCase
{
    use RefreshDatabase;

    private PickupRoute $ruta;

    private Expense $gasolina;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());

        $this->ruta = PickupRoute::create(['name' => '1']);
        $this->gasolina = Expense::create(['name' => 'Gasolina']);
    }

    private function linea(array $extra = []): RouteExpense
    {
        return RouteExpense::create(array_merge([
            'pickup_route_id' => $this->ruta->id,
            'expense_id' => $this->gasolina->id,
            'amount' => '400.00',
            'recurrent' => true,
            'starts_on' => '2026-08-01',
            'ends_on' => null,
        ], $extra));
    }

    /** El formulario, con el mes tal como lo manda un `<input type="month">`. */
    private function nueva(array $campos = []): Testable
    {
        $componente = Livewire::test('route-expenses')->set('month', '2026-08')->call('create');

        foreach (array_merge([
            'pickup_route_id' => (string) $this->ruta->id,
            'expense_id' => (string) $this->gasolina->id,
            'amount' => '400',
            'recurrent' => true,
            'starts_on' => '2026-08',
            'ends_on' => '',
        ], $campos) as $campo => $valor) {
            $componente->set($campo, $valor);
        }

        return $componente;
    }

    // --- Acceso ---------------------------------------------------------------------

    public function test_it_is_behind_the_session(): void
    {
        auth()->logout();

        $this->get('/route-expenses')->assertRedirect('/login');
    }

    public function test_the_page_renders_with_its_layout(): void
    {
        $this->get('/route-expenses')
            ->assertOk()
            ->assertSee('Gastos por ruta')
            ->assertSee('Configuraciones');
    }

    public function test_it_opens_on_the_current_month(): void
    {
        Livewire::test('route-expenses')->assertSet('month', now()->format('Y-m'));
    }

    // --- El importe es de la ruta ------------------------------------------------------

    /** La razón de que esta tabla exista: el mismo concepto, dos rutas, dos importes. */
    public function test_the_same_concept_costs_a_different_amount_in_each_route(): void
    {
        $otra = PickupRoute::create(['name' => '3']);

        $this->linea(['amount' => '400.00']);
        $this->linea(['pickup_route_id' => $otra->id, 'amount' => '550.00']);

        Livewire::test('route-expenses')
            ->set('month', '2026-08')
            ->assertViewHas('total', 950.0)
            ->assertSee('400,00 €')
            ->assertSee('550,00 €');
    }

    /**
     * El caso que planteó el cliente: una ruta con un concepto y otra con dos. Cada una suma
     * los suyos, que es lo que se ve al filtrar por ruta.
     */
    public function test_each_route_adds_up_only_its_own_concepts(): void
    {
        $otra = PickupRoute::create(['name' => '3']);
        $mantenimiento = Expense::create(['name' => 'Mantenimiento']);

        $this->linea(['amount' => '400.00']);
        $this->linea(['pickup_route_id' => $otra->id, 'amount' => '550.00']);
        $this->linea(['pickup_route_id' => $otra->id, 'expense_id' => $mantenimiento->id, 'amount' => '300.00']);

        Livewire::test('route-expenses')
            ->set('month', '2026-08')
            ->set('routeFilter', (string) $this->ruta->id)
            ->assertViewHas('total', 400.0)
            ->set('routeFilter', (string) $otra->id)
            ->assertViewHas('total', 850.0);
    }

    // --- Recurrente frente a puntual ----------------------------------------------------

    /** El sueldo: se dio de alta en agosto y en octubre sigue ahí. */
    public function test_a_recurring_expense_shows_up_in_every_later_month(): void
    {
        $this->linea(['recurrent' => true, 'starts_on' => '2026-08-01', 'ends_on' => null]);

        foreach (['2026-08', '2026-09', '2026-10'] as $mes) {
            Livewire::test('route-expenses')
                ->set('month', $mes)
                ->assertViewHas('total', 400.0);
        }
    }

    /** El mantenimiento del camión: pasó en agosto y en septiembre ya no está. */
    public function test_a_one_off_expense_only_counts_in_its_month(): void
    {
        $this->linea(['recurrent' => false, 'starts_on' => '2026-08-01', 'ends_on' => '2026-08-01']);

        Livewire::test('route-expenses')->set('month', '2026-08')->assertViewHas('total', 400.0);
        Livewire::test('route-expenses')->set('month', '2026-09')->assertViewHas('total', 0.0);
    }

    /** Un recurrente tampoco aparece antes de empezar. */
    public function test_a_recurring_expense_does_not_reach_backwards(): void
    {
        $this->linea(['starts_on' => '2026-08-01']);

        Livewire::test('route-expenses')->set('month', '2026-07')->assertViewHas('total', 0.0);
    }

    /** Y deja de aparecer en cuanto se cierra, con el mes de fin incluido. */
    public function test_a_closed_recurring_expense_stops_after_its_last_month(): void
    {
        $this->linea(['starts_on' => '2026-08-01', 'ends_on' => '2026-09-01']);

        Livewire::test('route-expenses')->set('month', '2026-09')->assertViewHas('total', 400.0);
        Livewire::test('route-expenses')->set('month', '2026-10')->assertViewHas('total', 0.0);
    }

    /**
     * Una subida de sueldo no reescribe el pasado: se cierra la línea vieja y se abre otra.
     * Agosto tiene que seguir diciendo lo que costó agosto.
     */
    public function test_closing_a_line_and_opening_another_keeps_each_month_honest(): void
    {
        $this->linea(['amount' => '1200.00', 'starts_on' => '2026-08-01', 'ends_on' => '2026-08-01']);
        $this->linea(['amount' => '1300.00', 'starts_on' => '2026-09-01', 'ends_on' => null]);

        Livewire::test('route-expenses')->set('month', '2026-08')->assertViewHas('total', 1200.0);
        Livewire::test('route-expenses')->set('month', '2026-09')->assertViewHas('total', 1300.0);
    }

    public function test_the_listing_tells_apart_a_recurring_line_from_a_one_off(): void
    {
        $this->linea(['recurrent' => true]);

        Livewire::test('route-expenses')
            ->set('month', '2026-08')
            ->assertSee('todos los meses')
            ->assertSee('desde agosto de 2026');

        RouteExpense::query()->delete();
        $this->linea(['recurrent' => false, 'ends_on' => '2026-08-01']);

        Livewire::test('route-expenses')
            ->set('month', '2026-08')
            ->assertSee('puntual')
            ->assertSee('sólo agosto de 2026');
    }

    // --- Alta y edición -------------------------------------------------------------------

    public function test_it_creates_a_recurring_expense(): void
    {
        $this->nueva(['amount' => '432.10'])->call('save')->assertHasNoErrors();

        $linea = RouteExpense::sole();
        $this->assertSame(432.10, $linea->amount);
        $this->assertTrue($linea->recurrent);
        $this->assertSame('2026-08-01', $linea->starts_on->toDateString());
        // Sin fin: sigue vigente.
        $this->assertNull($linea->ends_on);
    }

    /** Un puntual no pregunta el mes de fin: acaba donde empieza, y lo pone la pantalla. */
    public function test_a_one_off_expense_closes_itself_in_its_own_month(): void
    {
        $this->nueva(['recurrent' => false, 'starts_on' => '2026-08', 'ends_on' => ''])
            ->call('save')
            ->assertHasNoErrors();

        $linea = RouteExpense::sole();
        $this->assertFalse($linea->recurrent);
        $this->assertSame('2026-08-01', $linea->ends_on->toDateString());
    }

    /** El céntimo tiene que llegar entero a la columna: son euros. */
    public function test_it_keeps_the_two_decimals(): void
    {
        $this->nueva(['amount' => '1234.56'])->call('save')->assertHasNoErrors();

        $this->assertSame('1234.56', RouteExpense::sole()->getRawOriginal('amount'));
    }

    /** El mes se guarda siempre en el día 1: es la convención de la tabla. */
    public function test_the_month_is_stored_on_the_first_day(): void
    {
        $this->nueva(['starts_on' => '2026-08', 'ends_on' => '2026-12'])
            ->call('save')
            ->assertHasNoErrors();

        $linea = RouteExpense::sole();
        $this->assertSame('2026-08-01', $linea->getRawOriginal('starts_on'));
        $this->assertSame('2026-12-01', $linea->getRawOriginal('ends_on'));
    }

    /** El alta llega con el mes que se está mirando puesto: es el que se va a teclear. */
    public function test_the_form_opens_on_the_month_being_looked_at(): void
    {
        Livewire::test('route-expenses')
            ->set('month', '2026-11')
            ->call('create')
            ->assertSet('starts_on', '2026-11');
    }

    public function test_it_edits_a_line_without_creating_a_second_one(): void
    {
        $linea = $this->linea(['amount' => '400.00']);

        Livewire::test('route-expenses')
            ->call('edit', $linea->id)
            ->assertSet('amount', '400.00')
            ->assertSet('starts_on', '2026-08')
            ->assertSet('ends_on', '')
            ->set('amount', '450')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(450.0, $linea->refresh()->amount);
        $this->assertSame(1, RouteExpense::count());
    }

    // --- Validación en el servidor ------------------------------------------------------

    public function test_the_route_and_the_concept_are_required(): void
    {
        $this->nueva(['pickup_route_id' => '', 'expense_id' => ''])
            ->call('save')
            ->assertHasErrors(['pickup_route_id', 'expense_id']);

        $this->assertSame(0, RouteExpense::count());
    }

    public function test_a_deleted_route_cannot_be_charged(): void
    {
        $this->ruta->delete();

        $this->nueva()->call('save')->assertHasErrors('pickup_route_id');
    }

    public function test_a_deleted_concept_cannot_be_used(): void
    {
        $this->gasolina->delete();

        $this->nueva()->call('save')->assertHasErrors('expense_id');
    }

    public function test_the_amount_is_required_and_never_negative(): void
    {
        $this->nueva(['amount' => ''])->call('save')->assertHasErrors('amount');
        $this->nueva(['amount' => '-1'])->call('save')->assertHasErrors('amount');
        $this->nueva(['amount' => '10.005'])->call('save')->assertHasErrors('amount');
        $this->nueva(['amount' => '100000000'])->call('save')->assertHasErrors('amount');

        $this->assertSame(0, RouteExpense::count());
    }

    /** El cero vale: un concepto que este mes no ha costado nada y se quiere dejar dicho. */
    public function test_a_zero_amount_is_accepted(): void
    {
        $this->nueva(['amount' => '0'])->call('save')->assertHasNoErrors();

        $this->assertSame('0.00', RouteExpense::sole()->getRawOriginal('amount'));
    }

    public function test_the_end_month_cannot_be_before_the_start(): void
    {
        $this->nueva(['starts_on' => '2026-09', 'ends_on' => '2026-08'])
            ->call('save')
            ->assertHasErrors('ends_on');

        $this->assertSame(0, RouteExpense::count());
    }

    public function test_the_same_end_month_as_the_start_is_fine(): void
    {
        $this->nueva(['starts_on' => '2026-08', 'ends_on' => '2026-08'])
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_the_validation_messages_are_in_spanish(): void
    {
        // El panel está entero en castellano, y `after_or_equal` y `date_format` son reglas
        // que nacen con esta pantalla: sin su línea en lang/es decían «The ends on field…».
        $errores = $this->nueva(['starts_on' => '2026-09', 'ends_on' => '2026-08'])
            ->call('save')
            ->errors();

        $this->assertSame(
            'El campo mes de fin no puede ser anterior a mes de inicio.',
            $errores->first('ends_on'),
        );
    }

    // --- Solapes ---------------------------------------------------------------------------

    /**
     * Dos líneas abiertas del mismo concepto en la misma ruta duplicarían el gasto de todos
     * los meses siguientes, y el total no diría en ningún sitio que está contando dos veces.
     */
    public function test_it_refuses_two_open_lines_of_the_same_concept_in_the_same_route(): void
    {
        $this->linea(['starts_on' => '2026-08-01', 'ends_on' => null]);

        $this->nueva(['starts_on' => '2026-09'])
            ->call('save')
            ->assertHasErrors('starts_on');

        $this->assertSame(1, RouteExpense::count());
    }

    public function test_it_refuses_a_line_that_overlaps_a_closed_period(): void
    {
        $this->linea(['starts_on' => '2026-08-01', 'ends_on' => '2026-10-01']);

        // Empieza dentro del periodo de la otra.
        $this->nueva(['starts_on' => '2026-09', 'ends_on' => '2026-12'])
            ->call('save')
            ->assertHasErrors('starts_on');
    }

    /** Pero encadenar dos periodos que no se tocan es exactamente lo que hay que poder hacer. */
    public function test_it_allows_a_line_that_starts_after_the_previous_one_closed(): void
    {
        $this->linea(['amount' => '1200.00', 'starts_on' => '2026-08-01', 'ends_on' => '2026-08-01']);

        $this->nueva(['amount' => '1300', 'starts_on' => '2026-09'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(2, RouteExpense::count());
    }

    /** El solape es por ruta y concepto: la misma gasolina en otra ruta no estorba. */
    public function test_the_same_concept_in_another_route_is_not_an_overlap(): void
    {
        $otra = PickupRoute::create(['name' => '3']);
        $this->linea(['pickup_route_id' => $otra->id]);

        $this->nueva()->call('save')->assertHasNoErrors();

        $this->assertSame(2, RouteExpense::count());
    }

    public function test_another_concept_in_the_same_route_is_not_an_overlap(): void
    {
        $this->linea();
        $mantenimiento = Expense::create(['name' => 'Mantenimiento']);

        $this->nueva(['expense_id' => (string) $mantenimiento->id])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(2, RouteExpense::count());
    }

    /** Editar una línea sin moverla no puede chocar consigo misma. */
    public function test_editing_a_line_does_not_clash_with_itself(): void
    {
        $linea = $this->linea();

        Livewire::test('route-expenses')
            ->call('edit', $linea->id)
            ->set('amount', '450')
            ->call('save')
            ->assertHasNoErrors();
    }

    /** Y una línea retirada deja el hueco libre. */
    public function test_a_retired_line_does_not_block_the_period(): void
    {
        $this->linea()->delete();

        $this->nueva()->call('save')->assertHasNoErrors();
    }

    // --- Baja pasiva ---------------------------------------------------------------------

    public function test_a_line_can_be_retired_and_brought_back(): void
    {
        $linea = $this->linea();

        Livewire::test('route-expenses')->set('month', '2026-08')->call('delete', $linea->id);
        $this->assertSoftDeleted($linea);

        Livewire::test('route-expenses')->set('month', '2026-08')->call('restore', $linea->id);
        $this->assertNotSoftDeleted($linea->refresh());
    }

    public function test_a_retired_line_stops_counting_in_the_total(): void
    {
        $this->linea()->delete();

        Livewire::test('route-expenses')->set('month', '2026-08')->assertViewHas('total', 0.0);
    }

    public function test_retired_lines_are_hidden_unless_you_ask_for_them(): void
    {
        $mantenimiento = Expense::create(['name' => 'Mantenimiento']);
        $this->linea();
        $this->linea(['expense_id' => $mantenimiento->id])->delete();

        Livewire::test('route-expenses')
            ->set('month', '2026-08')
            ->assertSee('Gasolina')
            ->assertDontSee('Mantenimiento')
            ->set('showingTrashed', true)
            ->assertSee('Mantenimiento');
    }

    // --- Navegación por meses y filtros -----------------------------------------------------

    public function test_it_walks_to_the_previous_and_next_month(): void
    {
        Livewire::test('route-expenses')
            ->set('month', '2026-08')
            ->call('shiftMonth', 1)
            ->assertSet('month', '2026-09')
            ->call('shiftMonth', -2)
            ->assertSet('month', '2026-07');
    }

    public function test_it_filters_by_route(): void
    {
        $otra = PickupRoute::create(['name' => '3']);
        $this->linea(['amount' => '400.00']);
        $this->linea(['pickup_route_id' => $otra->id, 'amount' => '550.00']);

        Livewire::test('route-expenses')
            ->set('month', '2026-08')
            ->set('routeFilter', (string) $otra->id)
            ->assertViewHas('total', 550.0);
    }

    public function test_it_searches_by_concept_and_by_route(): void
    {
        $mantenimiento = Expense::create(['name' => 'Mantenimiento']);
        $this->linea(['amount' => '400.00']);
        $this->linea(['expense_id' => $mantenimiento->id, 'amount' => '300.00']);

        Livewire::test('route-expenses')
            ->set('month', '2026-08')
            ->set('search', 'manteni')
            ->assertViewHas('total', 300.0);
    }

    public function test_changing_the_month_sends_you_back_to_the_first_page(): void
    {
        foreach (range(1, 20) as $i) {
            $concepto = Expense::create(['name' => sprintf('Concepto %02d', $i)]);
            $this->linea(['expense_id' => $concepto->id]);
        }

        Livewire::test('route-expenses')
            ->set('month', '2026-08')
            ->call('gotoPage', 2)
            ->assertViewHas('routeExpenses', fn ($p) => $p->currentPage() === 2)
            ->call('shiftMonth', 1)
            ->assertViewHas('routeExpenses', fn ($p) => $p->currentPage() === 1);
    }

    // --- Doble envío -------------------------------------------------------------------------

    public function test_saving_twice_does_not_create_two_lines(): void
    {
        $this->nueva()->call('save')->call('save');

        // Sin cerrojo, el segundo envío crearía una línea idéntica — o chocaría
        // con la regla de solape, que es la otra mitad de la red.
        $this->assertSame(1, RouteExpense::count());
    }

    // --- Historial ------------------------------------------------------------------------------

    public function test_raising_the_amount_is_written_in_the_history(): void
    {
        $linea = $this->linea(['amount' => '400.00']);

        Livewire::test('route-expenses')
            ->call('edit', $linea->id)
            ->set('amount', '450')
            ->call('save');

        // Toca dinero: quién lo subió y desde cuánto tiene que quedar escrito (§4).
        $log = $linea->auditLogs()->latest('id')->first();

        $this->assertSame('400.00', $log->before['amount']);
        $this->assertSame('450.00', $log->after['amount']);
        $this->assertSame(auth()->user()->email, $log->user_email);
    }

    // --- Transacción ------------------------------------------------------------------------------

    public function test_the_save_runs_inside_a_transaction(): void
    {
        $nivel = null;
        RouteExpense::created(function () use (&$nivel) {
            $nivel = DB::transactionLevel();
        });

        $this->nueva()->call('save');

        $this->assertNotNull($nivel, 'No llegó a crearse la línea.');
        $this->assertGreaterThan(0, $nivel, 'El guardado corrió fuera de una transacción.');
    }

    // --- Integridad con el resto del maestro ---------------------------------------------------

    /**
     * Una ruta con gastos no se da de baja: sus líneas seguirían sumando en los totales por
     * concepto apuntando a una ruta que ya no aparece en ninguna pantalla.
     */
    public function test_a_route_with_expenses_cannot_be_deleted_and_says_why(): void
    {
        $this->linea();

        Livewire::test('pickup-routes')
            ->call('delete', $this->ruta->id)
            ->assertDispatched('toast', fn ($nombre, $params) => $params['type'] === 'error'
                && str_contains($params['message'], 'todavía tiene 1 gasto'));

        $this->assertNotSoftDeleted($this->ruta);
    }

    // --- Permisos ---------------------------------------------------------------------------------

    public function test_an_account_without_the_permission_is_left_at_the_door(): void
    {
        $this->actingAs(User::factory()->withoutRole()->create());

        $this->get('/route-expenses')->assertForbidden();
    }

    public function test_a_read_only_account_cannot_write_through_livewire(): void
    {
        $linea = $this->linea();

        $usuario = User::factory()->withoutRole()->create();
        $usuario->givePermissionTo('expenses.view');
        $this->actingAs($usuario);

        Livewire::test('route-expenses')->call('create')->assertForbidden();
        Livewire::test('route-expenses')->call('edit', $linea->id)->assertForbidden();
        Livewire::test('route-expenses')->call('confirmDelete', $linea->id)->assertForbidden();
        Livewire::test('route-expenses')->call('delete', $linea->id)->assertForbidden();
        Livewire::test('route-expenses')->call('restore', $linea->id)->assertForbidden();
        Livewire::test('route-expenses')->call('save')->assertForbidden();

        $this->assertNotSoftDeleted($linea);
    }
}
