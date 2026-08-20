<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\PickupRoute;
use App\Models\RouteExpense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * El catálogo de conceptos de gasto (CONTEXTO.md §7, fase 15).
 *
 * **Aquí no hay importes.** El dinero vive en `route_expenses` y lo prueba
 * `RouteExpensesCrudTest`; esta pantalla sólo mantiene el vocabulario común, que es lo que
 * permite preguntar cuánto se va en gasolina entre todas las rutas.
 */
class ExpensesCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    private function concepto(string $name = 'Gasolina', ?string $description = null): Expense
    {
        return Expense::create(['name' => $name, 'description' => $description]);
    }

    /** Una línea de gasto que usa el concepto, para probar lo que pasa cuando está en uso. */
    private function lineaCon(Expense $concepto): RouteExpense
    {
        return RouteExpense::create([
            'pickup_route_id' => PickupRoute::create(['name' => '1'])->id,
            'expense_id' => $concepto->id,
            'amount' => '400.00',
            'recurrent' => true,
            'starts_on' => '2026-08-01',
            'ends_on' => null,
        ]);
    }

    // --- Acceso ---------------------------------------------------------------

    public function test_it_is_behind_the_session(): void
    {
        auth()->logout();

        $this->get('/expenses')->assertRedirect('/login');
    }

    public function test_the_page_renders_with_its_layout_and_hangs_from_settings(): void
    {
        // Petición de verdad y no sólo `Livewire::test`: los enlaces nuevos viven
        // en la barra lateral, que un test de componente no pinta.
        $this->get('/expenses')
            ->assertOk()
            ->assertSee('Conceptos de gasto')
            ->assertSee('Gastos por ruta')
            ->assertSee('Configuraciones');
    }

    public function test_the_page_lists_the_concepts(): void
    {
        $this->concepto('Gasolina', 'Gasoil de las furgonetas');

        Livewire::test('expenses')
            ->assertOk()
            ->assertSee('Gasolina')
            ->assertSee('Gasoil de las furgonetas');
    }

    public function test_a_concept_without_a_description_says_so_instead_of_leaving_a_hole(): void
    {
        $this->concepto('Mantenimiento');

        Livewire::test('expenses')->assertSee('sin descripción');
    }

    /** Cuántas líneas de gasto lo usan: es lo que explica que no se deje retirar. */
    public function test_it_says_in_how_many_expenses_a_concept_is_used(): void
    {
        $sinUsar = $this->concepto('Mantenimiento');
        $enUso = $this->concepto('Gasolina');
        $this->lineaCon($enUso);

        Livewire::test('expenses')
            ->assertSee('sin usar')
            ->assertSee('1 gasto')
            ->assertViewHas('expenses', fn ($p) => $p->firstWhere('id', $enUso->id)->route_expenses_count === 1
                && $p->firstWhere('id', $sinUsar->id)->route_expenses_count === 0);
    }

    // --- Alta y edición -------------------------------------------------------

    public function test_it_creates_a_concept(): void
    {
        Livewire::test('expenses')
            ->call('create')
            ->set('name', 'Pago al transportista')
            ->set('description', 'El sueldo mensual')
            ->call('save')
            ->assertHasNoErrors();

        $concepto = Expense::where('name', 'Pago al transportista')->sole();
        $this->assertSame('El sueldo mensual', $concepto->description);
    }

    public function test_the_description_is_optional_and_an_empty_one_is_stored_as_null(): void
    {
        Livewire::test('expenses')
            ->call('create')
            ->set('name', 'Gasolina')
            // Espacios y no cadena vacía: «sin descripción» tiene que ser una
            // sola cosa en la base, no dos que se pinten distinto.
            ->set('description', '   ')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull(Expense::sole()->description);
    }

    public function test_it_edits_a_concept_without_creating_a_second_one(): void
    {
        $concepto = $this->concepto('Gasolina', 'Gasoil');

        Livewire::test('expenses')
            ->call('edit', $concepto->id)
            ->assertSet('name', 'Gasolina')
            ->assertSet('description', 'Gasoil')
            ->set('name', 'Combustible')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Combustible', $concepto->refresh()->name);
        $this->assertSame(1, Expense::count());
    }

    // --- Validación en el servidor --------------------------------------------

    public function test_the_name_is_required(): void
    {
        Livewire::test('expenses')
            ->call('create')
            ->set('name', '')
            ->call('save')
            ->assertHasErrors('name');

        $this->assertSame(0, Expense::count());
    }

    public function test_a_description_longer_than_the_limit_is_rejected(): void
    {
        Livewire::test('expenses')
            ->call('create')
            ->set('name', 'Gasolina')
            ->set('description', str_repeat('a', 1001))
            ->call('save')
            ->assertHasErrors('description');
    }

    public function test_it_rejects_a_duplicate_name(): void
    {
        $this->concepto('Gasolina');

        Livewire::test('expenses')
            ->call('create')
            ->set('name', 'Gasolina')
            ->call('save')
            ->assertHasErrors('name');

        $this->assertSame(1, Expense::count());
    }

    public function test_renaming_a_concept_does_not_clash_with_itself(): void
    {
        $concepto = $this->concepto('Gasolina');

        Livewire::test('expenses')
            ->call('edit', $concepto->id)
            ->set('name', 'Gasolina')
            ->call('save')
            ->assertHasNoErrors();
    }

    /** El nombre de un concepto retirado queda libre: es lo que dice el índice parcial. */
    public function test_the_name_of_a_deleted_concept_can_be_reused(): void
    {
        $this->concepto('Gasolina')->delete();

        Livewire::test('expenses')
            ->call('create')
            ->set('name', 'Gasolina')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(2, Expense::withTrashed()->count());
    }

    public function test_the_validation_messages_are_in_spanish(): void
    {
        $errores = Livewire::test('expenses')
            ->call('create')
            ->set('name', '')
            ->call('save')
            ->errors();

        $this->assertSame('El campo nombre es obligatorio.', $errores->first('name'));
    }

    // --- Validación en caliente, la del formulario ------------------------------

    /**
     * Cada campo se comprueba al salir de él, sin esperar a Guardar. Es la mitad de cara al
     * usuario del «validar en front»: la que manda sigue siendo la de `save()`.
     */
    public function test_a_field_is_validated_as_soon_as_it_is_left(): void
    {
        $this->concepto('Gasolina');

        Livewire::test('expenses')
            ->call('create')
            ->set('name', 'Gasolina')
            ->assertHasErrors('name')
            // Y sin haber llamado a `save`: no hay nada escrito.
            ->assertSet('showingForm', true);

        $this->assertSame(1, Expense::count());
    }

    public function test_fixing_the_field_clears_its_error(): void
    {
        Livewire::test('expenses')
            ->call('create')
            ->set('name', str_repeat('a', 256))
            ->assertHasErrors('name')
            ->set('name', 'Gasolina')
            ->assertHasNoErrors('name');
    }

    /** El buscador no es un campo del formulario y no tiene reglas: no puede reventar aquí. */
    public function test_the_search_box_does_not_go_through_the_form_validation(): void
    {
        Livewire::test('expenses')
            ->set('search', 'lo que sea')
            ->assertHasNoErrors()
            ->assertOk();
    }

    // --- Baja pasiva y reactivación ---------------------------------------------

    public function test_a_concept_can_be_deleted_and_brought_back(): void
    {
        $concepto = $this->concepto();

        Livewire::test('expenses')->call('delete', $concepto->id);
        $this->assertSoftDeleted($concepto);

        Livewire::test('expenses')->call('restore', $concepto->id);
        $this->assertNotSoftDeleted($concepto->refresh());
    }

    /**
     * Un concepto en uso no se retira. Sin esto desaparecería del catálogo dejando líneas de
     * gasto que siguen sumando en los totales apuntando a un nombre invisible.
     */
    public function test_a_concept_in_use_cannot_be_deleted_and_says_why(): void
    {
        $concepto = $this->concepto('Gasolina');
        $this->lineaCon($concepto);

        Livewire::test('expenses')
            ->call('delete', $concepto->id)
            ->assertDispatched('toast', fn ($nombre, $params) => $params['type'] === 'error'
                && str_contains($params['message'], 'todavía se usa en 1 línea de gasto'));

        $this->assertNotSoftDeleted($concepto);
    }

    public function test_retiring_the_lines_frees_the_concept(): void
    {
        $concepto = $this->concepto('Gasolina');
        $linea = $this->lineaCon($concepto);

        $linea->delete();

        Livewire::test('expenses')->call('delete', $concepto->id);

        $this->assertSoftDeleted($concepto);
    }

    public function test_deleted_concepts_are_hidden_unless_you_ask_for_them(): void
    {
        $this->concepto('Vivo');
        $this->concepto('Retirado')->delete();

        Livewire::test('expenses')
            ->assertSee('Vivo')
            ->assertDontSee('Retirado')
            ->set('showingTrashed', true)
            ->assertSee('Retirado');
    }

    public function test_the_trash_icon_only_opens_a_confirmation_that_names_the_record(): void
    {
        $concepto = $this->concepto('Gasolina');

        Livewire::test('expenses')
            ->call('confirmDelete', $concepto->id)
            ->assertSet('confirmingDeletion', $concepto->id)
            ->assertSee('Vas a dar de baja')
            ->assertSee('Gasolina');

        $this->assertNotSoftDeleted($concepto);
    }

    public function test_cancelling_the_confirmation_deletes_nothing(): void
    {
        $concepto = $this->concepto();

        Livewire::test('expenses')
            ->call('confirmDelete', $concepto->id)
            ->call('cancelDelete')
            ->assertSet('confirmingDeletion', null);

        $this->assertNotSoftDeleted($concepto);
    }

    // --- Filtro y paginación -------------------------------------------------------

    public function test_it_shows_ten_rows_per_page(): void
    {
        foreach (range(1, 25) as $i) {
            $this->concepto(sprintf('Concepto %02d', $i));
        }

        Livewire::test('expenses')
            ->assertViewHas('expenses', fn ($p) => $p->count() === 10 && $p->total() === 25)
            ->assertSee('Concepto 01')
            ->assertDontSee('Concepto 11');
    }

    public function test_it_filters_by_name_and_by_description(): void
    {
        $this->concepto('Gasolina', 'Gasoil de las furgonetas');
        $this->concepto('Mantenimiento', 'Revisiones y averías');

        Livewire::test('expenses')
            ->set('search', 'gasol')
            ->assertSee('Gasolina')
            ->assertDontSee('Mantenimiento');

        Livewire::test('expenses')
            ->set('search', 'averías')
            ->assertSee('Mantenimiento')
            ->assertDontSee('Gasolina');
    }

    public function test_the_filter_escapes_wildcards(): void
    {
        $this->concepto('Cien');
        $this->concepto('100%');

        // Sin escapar, «100%» sería un comodín y traería también «Cien».
        Livewire::test('expenses')
            ->set('search', '100%')
            ->assertSee('100%')
            ->assertDontSee('Cien');
    }

    public function test_filtering_sends_you_back_to_the_first_page(): void
    {
        foreach (range(1, 25) as $i) {
            $this->concepto(sprintf('Concepto %02d', $i));
        }

        Livewire::test('expenses')
            ->call('gotoPage', 3)
            ->assertViewHas('expenses', fn ($p) => $p->currentPage() === 3)
            ->set('search', 'Concepto 0')
            ->assertViewHas('expenses', fn ($p) => $p->currentPage() === 1);
    }

    // --- Doble envío ----------------------------------------------------------------

    public function test_saving_twice_does_not_create_two_concepts(): void
    {
        Livewire::test('expenses')
            ->call('create')
            ->set('name', 'Gasolina')
            ->call('save')
            ->call('save');

        // El segundo envío no encuentra `editing`, así que sin cerrojo crearía
        // un segundo concepto con el mismo nombre — o chocaría con el índice.
        $this->assertSame(1, Expense::where('name', 'Gasolina')->count());
    }

    // --- Historial --------------------------------------------------------------------

    public function test_the_changes_reach_the_history_with_an_author(): void
    {
        Livewire::test('expenses')
            ->call('create')
            ->set('name', 'Gasolina')
            ->call('save');

        $log = Expense::where('name', 'Gasolina')->sole()->auditLogs()->sole();

        $this->assertSame(auth()->user()->email, $log->user_email);
    }

    // --- Transacción ---------------------------------------------------------------------

    public function test_the_save_runs_inside_a_transaction(): void
    {
        // Se mira desde dentro del evento del modelo, que es donde también se
        // escribe el historial: así queda probado que ambos van juntos (§4).
        $nivel = null;
        Expense::created(function () use (&$nivel) {
            $nivel = DB::transactionLevel();
        });

        Livewire::test('expenses')
            ->call('create')
            ->set('name', 'Gasolina')
            ->call('save');

        $this->assertNotNull($nivel, 'No llegó a crearse el concepto.');
        $this->assertGreaterThan(0, $nivel, 'El guardado corrió fuera de una transacción.');
    }

    // --- Permisos -----------------------------------------------------------------------

    public function test_an_account_without_the_permission_is_left_at_the_door(): void
    {
        $this->actingAs(User::factory()->withoutRole()->create());

        $this->get('/expenses')->assertForbidden();
    }

    public function test_a_read_only_account_cannot_write_through_livewire(): void
    {
        $usuario = User::factory()->withoutRole()->create();
        $usuario->givePermissionTo('expenses.view');
        $this->actingAs($usuario);

        $concepto = $this->concepto();

        // Cada una es una llamada que el navegador puede hacer aunque el Blade
        // no pinte el botón: esconderlo no protege nada.
        Livewire::test('expenses')->call('create')->assertForbidden();
        Livewire::test('expenses')->call('edit', $concepto->id)->assertForbidden();
        Livewire::test('expenses')->call('confirmDelete', $concepto->id)->assertForbidden();
        Livewire::test('expenses')->call('delete', $concepto->id)->assertForbidden();
        Livewire::test('expenses')->call('restore', $concepto->id)->assertForbidden();
        Livewire::test('expenses')->call('save')->assertForbidden();

        $this->assertNotSoftDeleted($concepto);
    }

    public function test_a_read_only_account_is_not_offered_the_buttons(): void
    {
        $usuario = User::factory()->withoutRole()->create();
        $usuario->givePermissionTo('expenses.view');
        $this->actingAs($usuario);

        $this->concepto('Gasolina');

        Livewire::test('expenses')
            ->assertSee('Gasolina')
            ->assertDontSee('Nuevo concepto');
    }
}
