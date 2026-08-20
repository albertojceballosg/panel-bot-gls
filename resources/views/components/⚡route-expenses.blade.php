<?php

use App\Models\Expense;
use App\Models\PickupRoute;
use App\Models\RouteExpense;
use App\Support\CrudScreen;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Lo que cuesta cada ruta al mes (CONTEXTO.md §7, fase 15).
 *
 * Es la pantalla donde está el dinero: el catálogo de «Conceptos de gasto» sólo pone los
 * nombres. Aquí cada línea dice **qué concepto, en qué ruta, cuánto al mes y en qué meses**.
 *
 * **Todo se mira por mes**, y el mes es el estado de la pantalla: se enseñan a la vez las
 * líneas recurrentes vigentes en ese mes y las puntuales de ese mes, que es exactamente lo
 * que hace el scope `inMonth`. Sin el mes delante, un total no significaría nada —mezclaría
 * el sueldo de todos los meses con la reparación de uno—.
 */
new #[Layout('components.layouts.app')] class extends Component
{
    use CrudScreen;

    public const POR_PAGINA = 15;

    /** El mes que se está mirando, `aaaa-mm`. Arranca en el actual. */
    public string $month = '';

    /** Filtro del listado, aparte de la búsqueda por texto. */
    public string $routeFilter = '';

    public string $pickup_route_id = '';

    public string $expense_id = '';

    /** Texto y no `float`: es lo que hay en el `<input>`, y un campo vacío no es un cero. */
    public string $amount = '';

    /**
     * Lo que el cliente pidió poder distinguir: el sueldo se cobra todos los meses, el
     * mantenimiento del camión puede pasar éste y ninguno más. Por defecto recurrente, que es
     * la mayoría de lo que se da de alta.
     */
    public bool $recurrent = true;

    public string $starts_on = '';

    /** Vacío es «sigue vigente». Sólo lo usa un recurrente. */
    public string $ends_on = '';

    public function mount(): void
    {
        $this->month = now()->format('Y-m');
    }

    protected function model(): string
    {
        return RouteExpense::class;
    }

    protected function formFields(): array
    {
        return ['pickup_route_id', 'expense_id', 'amount', 'recurrent', 'starts_on', 'ends_on'];
    }

    protected function fillForm($record): void
    {
        $this->pickup_route_id = (string) $record->pickup_route_id;
        $this->expense_id = (string) $record->expense_id;
        // Con dos decimales y punto: es lo que espera un `<input type="number">`, y así al
        // reabrir un gasto de 12,50 € no aparece «12.5».
        $this->amount = number_format($record->amount, 2, '.', '');
        $this->recurrent = $record->recurrent;
        $this->starts_on = $record->starts_on->format('Y-m');
        $this->ends_on = $record->ends_on?->format('Y-m') ?? '';
    }

    protected function label(): string
    {
        return 'gasto';
    }

    protected function permissionModule(): string
    {
        return 'expenses';
    }

    /**
     * Las reglas salen del modelo, única fuente. Se le pasan los otros campos del formulario
     * porque la de solape necesita saber contra qué ruta y qué concepto compara.
     */
    protected function rules(): array
    {
        return RouteExpense::rules($this->editing, [
            'pickup_route_id' => $this->pickup_route_id,
            'expense_id' => $this->expense_id,
            'ends_on' => $this->endsOn(),
        ]);
    }

    /**
     * El mes de fin que se va a guardar. Un gasto puntual **empieza y acaba en el mismo mes**:
     * el formulario no lo pregunta dos veces, lo deduce. Un recurrente sin fin sigue vigente.
     */
    private function endsOn(): string
    {
        return $this->recurrent ? $this->ends_on : $this->starts_on;
    }

    /**
     * Validación en caliente: cada campo se comprueba al salir de él. La que manda sigue
     * siendo la de `save()`, que revalida entero en el servidor.
     */
    public function updated(string $field): void
    {
        if (in_array($field, $this->formFields(), true)) {
            $this->validateOnly($field);
        }
    }

    public function updatedMonth(): void
    {
        $this->resetPage();
    }

    public function updatedRouteFilter(): void
    {
        $this->resetPage();
    }

    /** Al mes anterior o al siguiente, que es como se navega esto de verdad. */
    public function shiftMonth(int $months): void
    {
        $this->month = RouteExpense::month($this->month)->addMonths($months)->format('Y-m');
        $this->resetPage();
    }

    public function with(): array
    {
        $query = RouteExpense::query()
            ->when($this->showingTrashed, fn ($q) => $q->withTrashed())
            // El mes es el marco de todo lo que se enseña: recurrentes vigentes y puntuales
            // de ese mes, de una vez.
            ->inMonth($this->month)
            ->when($this->routeFilter !== '', fn ($q) => $q->where('pickup_route_id', $this->routeFilter))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->whereHas('expense', fn ($q) => $q->where('name', 'ilike', $this->likeTerm()))
                ->orWhereHas('pickupRoute', fn ($q) => $q->where('name', 'ilike', $this->likeTerm()))));

        // Por ruta y, dentro, por concepto: es como se lee una hoja de gastos. Con subconsulta
        // y no con un join para no arrastrar columnas ajenas al modelo.
        $ordenada = (clone $query)
            ->orderBy(PickupRoute::select('name')->whereColumn('pickup_routes.id', 'route_expenses.pickup_route_id'))
            ->orderBy(Expense::select('name')->whereColumn('expenses.id', 'route_expenses.expense_id'));

        return [
            'routeExpenses' => $ordenada->with(['pickupRoute', 'expense'])->paginate(self::POR_PAGINA),

            // El total del mes que se está mirando, no el de la página.
            'total' => (float) (clone $query)->sum('amount'),

            'pickupRoutes' => PickupRoute::orderBy('name')->get(),
            'expenses' => Expense::orderBy('name')->get(),
        ];
    }

    /** Los euros como se leen aquí: «1.234,50 €». */
    public function euros(float $importe): string
    {
        return number_format($importe, 2, ',', '.').' €';
    }

    /** «agosto de 2026», para la cabecera. */
    public function monthLabel(?string $month = null): string
    {
        return RouteExpense::month($month ?? $this->month)->translatedFormat('F \d\e Y');
    }

    /** Cómo se lee el periodo de una línea en el listado. */
    public function periodLabel(RouteExpense $linea): string
    {
        if (! $linea->recurrent) {
            return 'sólo '.$this->monthLabel($linea->starts_on->format('Y-m'));
        }

        return $linea->ends_on === null
            ? 'desde '.$this->monthLabel($linea->starts_on->format('Y-m'))
            : sprintf(
                'de %s a %s',
                $this->monthLabel($linea->starts_on->format('Y-m')),
                $this->monthLabel($linea->ends_on->format('Y-m')),
            );
    }

    public function save(): void
    {
        // Esconder el botón no basta: a este método se llega desde el navegador.
        $this->authorizeManage();

        // Antes de validar: un puntual acaba donde empieza, y la regla de solape tiene que
        // comparar contra el periodo de verdad y no contra un fin vacío.
        if (! $this->recurrent) {
            $this->ends_on = $this->starts_on;
        }

        // Validación en el servidor, la que manda. Las reglas viven en el modelo.
        $this->validate();

        $editando = $this->editing !== null;

        // `transactionally` es el cerrojo de doble envío por fuera y la transacción por
        // dentro: dos envíos a la vez sólo escriben una vez, y el historial entra con la fila.
        $hecho = $this->transactionally($this->lockKey('save'), fn () => RouteExpense::withTrashed()
            ->findOr($this->editing ?? 0, fn () => new RouteExpense)
            ->fill([
                'pickup_route_id' => $this->pickup_route_id,
                'expense_id' => $this->expense_id,

                // Con los dos decimales puestos, que es como lo guarda la columna: si no, el
                // historial enseñaría «850.00 → 900» y parecería que cambió el formato.
                'amount' => number_format((float) $this->amount, 2, '.', ''),

                'recurrent' => $this->recurrent,

                // Al día 1: la convención de la tabla la impone `RouteExpense::month()`.
                'starts_on' => RouteExpense::month($this->starts_on),
                'ends_on' => $this->ends_on === '' ? null : RouteExpense::month($this->ends_on),
            ])
            ->save());

        if (! $hecho) {
            return;
        }

        $this->cancel();
        $this->toast($editando ? 'Gasto actualizado.' : 'Gasto creado.');
    }

    /** Un alta ya viene con el mes que se está mirando puesto: es lo que se va a teclear. */
    public function create(): void
    {
        $this->authorizeManage();

        $this->reset('editing', ...$this->formFields());
        $this->resetValidation();
        $this->starts_on = $this->month;
        $this->showingForm = true;
    }
}; ?>

<div>
    <x-ui.page-header title="Gastos por ruta"
                      description="Lo que le cuesta cada ruta al mes: sueldos, gasolina, mantenimiento… Los conceptos salen del catálogo de «Conceptos de gasto».">
        <x-slot:actions>
            {{-- Sin permiso de escritura, la pantalla se lee y ya (§7, fase 12). --}}
            @if ($this->canManage())
                <x-ui.button wire:click="create">
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Nuevo gasto
                </x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    {{-- El mes, que es el marco de todo lo de abajo. Va en su propia barra y no perdido entre
         los filtros: sin él, ninguna de las cifras de esta pantalla significa nada. --}}
    <x-ui.card class="mb-4">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-center gap-2">
                <x-ui.icon-button label="Mes anterior" wire:click="shiftMonth(-1)" wire:loading.attr="disabled">
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
                    </svg>
                </x-ui.icon-button>

                <label for="mes" class="sr-only">Mes</label>
                <input type="month" id="mes" wire:model.live="month"
                       class="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-brand-500 focus:ring-1 focus:ring-brand-500 focus:outline-none">

                <x-ui.icon-button label="Mes siguiente" wire:click="shiftMonth(1)" wire:loading.attr="disabled">
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                    </svg>
                </x-ui.icon-button>

                <span class="ml-1 text-sm text-slate-500">{{ $this->monthLabel() }}</span>
            </div>

            <p class="text-sm text-slate-600">
                Total del mes
                <span class="ml-1 text-lg font-semibold text-shell-900">{{ $this->euros($total) }}</span>
            </p>
        </div>
    </x-ui.card>

    <x-ui.card padding="p-0">
        {{-- Los tres en una línea: el buscador se estira y los otros dos se quedan con su
             ancho. Sin `justify-between`, que con tres elementos abre huecos raros. --}}
        <div class="flex flex-wrap items-center gap-3 border-b border-slate-200 px-6 py-3">
            <div class="relative min-w-56 flex-1">
                <svg class="pointer-events-none absolute top-2.5 left-3 size-4 text-slate-400"
                     fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z" />
                </svg>
                <x-ui.input wire:model.live.debounce.300ms="search" class="pl-9"
                            placeholder="Buscar por concepto o ruta…" aria-label="Buscar" />
            </div>

            {{-- En su propio contenedor: `x-ui.select` es `w-full` por dentro, así que una
                 clase de ancho suelta no gana y el desplegable se comía la fila entera. --}}
            <div class="w-48 shrink-0">
                <x-ui.searchable-select wire:model.live="routeFilter" aria-label="Filtrar por ruta"
                                        :options="$pickupRoutes->pluck('name', 'id')->all()"
                                        :value="$routeFilter"
                                        placeholder="Todas las rutas"
                                        search-placeholder="Buscar una ruta…" />
            </div>

            <label class="flex shrink-0 items-center gap-2 text-sm whitespace-nowrap text-slate-600">
                <input type="checkbox" wire:model.live="showingTrashed"
                       class="size-4 rounded border-slate-300 text-brand-500 focus:ring-brand-500">
                Ver retirados
            </label>
        </div>

        @if ($routeExpenses->isEmpty())
            <x-ui.empty-state title="No hay gastos en {{ $this->monthLabel() }}"
                              :description="$search !== '' || $routeFilter !== ''
                                  ? 'Prueba a quitar el filtro o a cambiar de mes.'
                                  : 'Los gastos recurrentes de otros meses tampoco aplican aquí. Añade el primero de este mes.'">
                @if ($search !== '' || $routeFilter !== '')
                    <x-slot:actions>
                        <x-ui.button variant="secondary" wire:click="$set('search', ''); $set('routeFilter', '')">
                            Quitar los filtros
                        </x-ui.button>
                    </x-slot:actions>
                @endif
            </x-ui.empty-state>
        @else
            {{-- En móvil la tabla no cabe: que desborde ella y no la página. --}}
            <div class="overflow-x-auto">
                <table class="w-full min-w-3xl text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-left text-xs tracking-wider text-slate-500 uppercase">
                            <th class="px-6 py-3 font-semibold">Ruta</th>
                            <th class="px-6 py-3 font-semibold">Concepto</th>
                            <th class="px-6 py-3 font-semibold">Periodo</th>
                            <th class="px-6 py-3 text-right font-semibold">Importe / mes</th>
                            <th class="px-6 py-3"><span class="sr-only">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($routeExpenses as $linea)
                            <tr wire:key="gasto-{{ $linea->id }}" class="hover:bg-slate-50/75">
                                <td class="px-6 py-3 font-medium text-shell-900">
                                    {{ $linea->pickupRoute?->name ?? '—' }}
                                </td>

                                <td class="px-6 py-3">
                                    <span class="text-slate-700">{{ $linea->expense?->name ?? '—' }}</span>

                                    @if ($linea->trashed())
                                        <span class="ml-2 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500">
                                            retirado
                                        </span>
                                    @endif
                                </td>

                                <td class="px-6 py-3">
                                    {{-- Recurrente o puntual, que es lo que el cliente pidió
                                         poder distinguir de un vistazo. --}}
                                    @if ($linea->recurrent)
                                        <span class="rounded-full bg-brand-50 px-2 py-0.5 text-xs font-medium text-brand-700">
                                            todos los meses
                                        </span>
                                    @else
                                        <span class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700">
                                            puntual
                                        </span>
                                    @endif

                                    <span class="ml-1 text-xs text-slate-500">{{ $this->periodLabel($linea) }}</span>
                                </td>

                                <td class="px-6 py-3 text-right font-medium tabular-nums text-shell-900">
                                    {{ $this->euros($linea->amount) }}
                                </td>

                                <td class="px-6 py-3">
                                    <div class="flex justify-end gap-1">
                                        @if ($this->canManage())
                                            @if ($linea->trashed())
                                                <x-ui.icon-button label="Reactivar"
                                                                  wire:click="restore({{ $linea->id }})"
                                                                  wire:loading.attr="disabled">
                                                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                              d="M16.023 9.348h4.992V4.356M2.985 19.644v-4.992h4.992M19.5 9a7.5 7.5 0 00-13.02-3.02L2.985 9m0 6a7.5 7.5 0 0013.02 3.02l3.495-3.02" />
                                                    </svg>
                                                </x-ui.icon-button>
                                            @else
                                                <x-ui.icon-button label="Editar"
                                                                  wire:click="edit({{ $linea->id }})"
                                                                  wire:loading.attr="disabled">
                                                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                              d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" />
                                                    </svg>
                                                </x-ui.icon-button>

                                                <x-ui.icon-button label="Retirar" variant="danger"
                                                                  wire:click="confirmDelete({{ $linea->id }})"
                                                                  wire:loading.attr="disabled">
                                                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                              d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                                    </svg>
                                                </x-ui.icon-button>
                                            @endif
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-6 py-3">
                <p class="text-sm text-slate-500">
                    {{ $routeExpenses->total() }} {{ $routeExpenses->total() === 1 ? 'gasto' : 'gastos' }}
                    en {{ $this->monthLabel() }} ·
                    <span class="font-medium text-shell-900">{{ $this->euros($total) }}</span>
                </p>

                {{ $routeExpenses->links() }}
            </div>
        @endif
    </x-ui.card>

    @if ($showingForm)
        <x-ui.modal :title="$editing ? 'Editar gasto' : 'Nuevo gasto'" width="max-w-2xl"
                    description="El importe es lo que cuesta al mes en esa ruta.">
            <form wire:submit="save" id="form-gasto-ruta" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.field label="Ruta" for="pickup_route_id" :error="$errors->first('pickup_route_id')">
                        <x-ui.searchable-select wire:model.blur="pickup_route_id" id="pickup_route_id"
                                                :invalid="$errors->has('pickup_route_id')"
                                                :options="$pickupRoutes->pluck('name', 'id')->all()"
                                                :value="$pickup_route_id"
                                                placeholder="Elige una ruta…"
                                                search-placeholder="Buscar una ruta…" />
                    </x-ui.field>

                    <x-ui.field label="Concepto" for="expense_id" :error="$errors->first('expense_id')"
                                hint="Si falta alguno, se añade en «Conceptos de gasto».">
                        <x-ui.searchable-select wire:model.blur="expense_id" id="expense_id"
                                                :invalid="$errors->has('expense_id')"
                                                :options="$expenses->pluck('name', 'id')->all()"
                                                :value="$expense_id"
                                                placeholder="Elige un concepto…"
                                                search-placeholder="Buscar un concepto…" />
                    </x-ui.field>
                </div>

                <x-ui.field label="Importe al mes (€)" for="amount" :error="$errors->first('amount')"
                            hint="Lo que cuesta este concepto en esta ruta durante un mes.">
                    <x-ui.input wire:model.blur="amount" id="amount" type="number"
                                :invalid="$errors->has('amount')"
                                required min="0" max="99999999.99" step="0.01" inputmode="decimal"
                                placeholder="0,00" />
                </x-ui.field>

                {{-- Lo que separa el sueldo del mantenimiento de este mes. `.live` porque los
                     campos de fecha de abajo cambian según lo que se marque. --}}
                <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3">
                    <input type="checkbox" wire:model.live="recurrent"
                           class="mt-0.5 size-4 rounded border-slate-300 text-brand-500 focus:ring-brand-500">
                    <span class="text-sm">
                        <span class="block font-medium text-slate-700">Se repite todos los meses</span>
                        <span class="block text-xs text-slate-500">
                            Como un sueldo. Quítalo si es un gasto puntual —una reparación, por
                            ejemplo— que sólo cuenta en un mes.
                        </span>
                    </span>
                </label>

                @if ($recurrent)
                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-ui.field label="Desde el mes" for="starts_on" :error="$errors->first('starts_on')">
                            <x-ui.input wire:model.blur="starts_on" id="starts_on" type="month"
                                        :invalid="$errors->has('starts_on')" required />
                        </x-ui.field>

                        <x-ui.field label="Hasta el mes" for="ends_on" :error="$errors->first('ends_on')"
                                    hint="Déjalo vacío mientras siga vigente.">
                            <x-ui.input wire:model.blur="ends_on" id="ends_on" type="month"
                                        :invalid="$errors->has('ends_on')" />
                        </x-ui.field>
                    </div>
                @else
                    <x-ui.field label="Mes" for="starts_on" :error="$errors->first('starts_on')"
                                hint="El gasto contará sólo en este mes.">
                        <x-ui.input wire:model.blur="starts_on" id="starts_on" type="month"
                                    :invalid="$errors->has('starts_on')" required />
                    </x-ui.field>
                @endif
            </form>

            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="cancel" wire:loading.attr="disabled">
                    Cancelar
                </x-ui.button>

                {{-- La mitad de cliente del doble envío: se desactiva mientras `save` está en
                     vuelo. La otra mitad es el cerrojo del servidor, que es el que de verdad
                     lo impide. --}}
                <x-ui.button type="submit" form="form-gasto-ruta"
                             wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">{{ $editing ? 'Guardar' : 'Crear' }}</span>
                    <span wire:loading wire:target="save">Guardando…</span>
                </x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($confirmingDeletion && $objetivo = $this->deletionTarget())
        <x-ui.confirm-modal title="Retirar el gasto"
                            :name="($objetivo->expense?->name ?? 'Gasto').' · '.($objetivo->pickupRoute?->name ?? '')"
                            confirmLabel="Retirar"
                            confirm="delete({{ $confirmingDeletion }})">
            Dejará de sumar en el total de la ruta, en éste y en el resto de meses. Podrás
            reactivarlo cuando quieras.
        </x-ui.confirm-modal>
    @endif

</div>
