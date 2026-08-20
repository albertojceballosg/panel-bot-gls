<?php

use App\Models\Expense;
use App\Support\CrudScreen;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * El catálogo de conceptos de gasto (CONTEXTO.md §7, fase 15).
 *
 * **Aquí no hay dinero.** Esta pantalla mantiene el vocabulario —«Gasolina», «Pago al
 * transportista», «Mantenimiento»— y los importes se ponen en «Gastos por ruta», porque son
 * de la ruta y no del concepto: cada transportista cobra lo suyo. Ver `RouteExpense`.
 *
 * Son cuatro o cinco filas que casi nunca se tocan, y por eso la pantalla es tan escueta.
 * Existe para que «Gasolina» sea una sola cosa en toda la base: con el nombre escrito a mano
 * en cada línea de gasto, preguntar cuánto se va en gasolina no tendría respuesta.
 *
 * Casi todo lo mecánico —formulario, búsqueda, paginación, baja, reactivación, cerrojo de
 * doble envío y transacción— lo pone `CrudScreen`, igual que en rutas y comercios. Aquí sólo
 * queda lo propio: los campos, la consulta del listado y la validación en caliente.
 */
new #[Layout('components.layouts.app')] class extends Component
{
    use CrudScreen;

    public const POR_PAGINA = 10;

    public string $name = '';

    public string $description = '';

    protected function model(): string
    {
        return Expense::class;
    }

    protected function formFields(): array
    {
        return ['name', 'description'];
    }

    protected function fillForm($record): void
    {
        $this->name = $record->name;
        $this->description = (string) $record->description;
    }

    protected function label(): string
    {
        return 'concepto';
    }

    protected function permissionModule(): string
    {
        return 'expenses';
    }

    /**
     * Las reglas del formulario, que Livewire usa tanto en `validate()` como en
     * `validateOnly()`. Salen del modelo —única fuente— y no se reescriben aquí.
     */
    protected function rules(): array
    {
        return Expense::rules($this->editing);
    }

    /**
     * Validación en caliente: cada campo se comprueba al salir de él, sin esperar a que se
     * pulse Guardar. Es la mitad de cara al usuario; la de verdad es la de `save()`, que
     * vuelve a validarlo todo en el servidor porque a este componente se llega desde el
     * navegador.
     *
     * Sólo los campos del formulario: `search` y `showingTrashed` también pasan por aquí y no
     * tienen regla ninguna.
     */
    public function updated(string $field): void
    {
        if (in_array($field, $this->formFields(), true)) {
            $this->validateOnly($field);
        }
    }

    public function with(): array
    {
        return [
            'expenses' => Expense::query()
                ->when($this->showingTrashed, fn ($q) => $q->withTrashed())
                ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                    ->where('name', 'ilike', $this->likeTerm())
                    ->orWhere('description', 'ilike', $this->likeTerm())))
                // En cuántas líneas de gasto se usa. De una tirada: sin esto es una consulta
                // por fila, y además es lo que decide si el concepto se puede retirar.
                ->withCount('routeExpenses')
                ->orderBy('name')
                ->paginate(self::POR_PAGINA),
        ];
    }

    public function save(): void
    {
        // Esconder el botón no basta: a este método se llega desde el navegador.
        $this->authorizeManage();

        // Validación en el servidor, la que manda. Las reglas viven en el modelo.
        $this->validate();

        $editando = $this->editing !== null;

        // `transactionally` es el cerrojo de doble envío por fuera y la transacción por
        // dentro: dos envíos a la vez sólo escriben una vez, y el historial entra con la fila.
        $hecho = $this->transactionally($this->lockKey('save'), fn () => Expense::withTrashed()
            ->findOr($this->editing ?? 0, fn () => new Expense)
            ->fill([
                'name' => trim($this->name),
                // Cadena vacía a nulo: «sin descripción» es una sola cosa en la base, no dos.
                'description' => trim($this->description) === '' ? null : trim($this->description),
            ])
            ->save());

        if (! $hecho) {
            return;
        }

        $this->cancel();
        $this->toast($editando ? 'Concepto actualizado.' : 'Concepto creado.');
    }
}; ?>

<div>
    <x-ui.page-header title="Conceptos de gasto"
                      description="El vocabulario común: gasolina, sueldos, mantenimiento… Los importes de cada ruta se ponen en «Gastos por ruta».">
        <x-slot:actions>
            {{-- Sin permiso de escritura, la pantalla se lee y ya (§7, fase 12). --}}
            @if ($this->canManage())
                <x-ui.button wire:click="create">
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Nuevo concepto
                </x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card padding="p-0">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-6 py-3">
            <div class="relative min-w-64 flex-1">
                <svg class="pointer-events-none absolute top-2.5 left-3 size-4 text-slate-400"
                     fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z" />
                </svg>
                <x-ui.input wire:model.live.debounce.300ms="search" class="pl-9"
                            placeholder="Buscar por nombre o descripción…" aria-label="Buscar" />
            </div>

            <label class="flex items-center gap-2 text-sm whitespace-nowrap text-slate-600">
                <input type="checkbox" wire:model.live="showingTrashed"
                       class="size-4 rounded border-slate-300 text-brand-500 focus:ring-brand-500">
                Ver dados de baja
            </label>
        </div>

        @if ($expenses->isEmpty())
            <x-ui.empty-state :title="$search !== '' ? 'Ningún gasto coincide' : 'Todavía no hay gastos'"
                              :description="$search !== ''
                                  ? 'Prueba con otro nombre o con una palabra de la descripción.'
                                  : 'Da de alta el primero —«Gasolina», «Pago al transportista»— para poder repartirlo entre las rutas.'">
                {{-- Sólo el de quitar el filtro. El de «Nuevo gasto» no se repite aquí:
                     ya está arriba, en la cabecera, y a dos palmos de distancia. --}}
                @if ($search !== '')
                    <x-slot:actions>
                        <x-ui.button variant="secondary" wire:click="$set('search', '')">Quitar el filtro</x-ui.button>
                    </x-slot:actions>
                @endif
            </x-ui.empty-state>
        @else
            {{-- En móvil la tabla no cabe: que desborde ella y no la página. --}}
            <div class="overflow-x-auto">
                <table class="w-full min-w-2xl text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-left text-xs tracking-wider text-slate-500 uppercase">
                            <th class="px-6 py-3 font-semibold">Concepto</th>
                            <th class="px-6 py-3 font-semibold">Descripción</th>
                            <th class="px-6 py-3 text-right font-semibold">En uso</th>
                            <th class="px-6 py-3"><span class="sr-only">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($expenses as $expense)
                            <tr wire:key="gasto-{{ $expense->id }}" class="hover:bg-slate-50/75">
                                <td class="px-6 py-3">
                                    <span class="font-medium text-shell-900">{{ $expense->name }}</span>

                                    @if ($expense->trashed())
                                        <span class="ml-2 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500">
                                            dado de baja
                                        </span>
                                    @endif
                                </td>

                                <td class="px-6 py-3">
                                    @if ($expense->description)
                                        <span class="text-slate-700">{{ $expense->description }}</span>
                                    @else
                                        <span class="text-slate-400">sin descripción</span>
                                    @endif
                                </td>

                                {{-- En cuántas líneas de gasto aparece. Es lo que explica por
                                     qué un concepto no se deja retirar. --}}
                                <td class="px-6 py-3 text-right tabular-nums text-slate-700">
                                    @if ($expense->route_expenses_count === 0)
                                        <span class="text-slate-400">sin usar</span>
                                    @else
                                        {{ $expense->route_expenses_count }}{{ $expense->route_expenses_count === 1 ? ' gasto' : ' gastos' }}
                                    @endif
                                </td>

                                <td class="px-6 py-3">
                                    <div class="flex justify-end gap-1">
                                        @if ($this->canManage())
                                            @if ($expense->trashed())
                                                <x-ui.icon-button label="Reactivar"
                                                                  wire:click="restore({{ $expense->id }})"
                                                                  wire:loading.attr="disabled">
                                                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                              d="M16.023 9.348h4.992V4.356M2.985 19.644v-4.992h4.992M19.5 9a7.5 7.5 0 00-13.02-3.02L2.985 9m0 6a7.5 7.5 0 0013.02 3.02l3.495-3.02" />
                                                    </svg>
                                                </x-ui.icon-button>
                                            @else
                                                <x-ui.icon-button label="Editar"
                                                                  wire:click="edit({{ $expense->id }})"
                                                                  wire:loading.attr="disabled">
                                                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                              d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" />
                                                    </svg>
                                                </x-ui.icon-button>

                                                <x-ui.icon-button label="Dar de baja" variant="danger"
                                                                  wire:click="confirmDelete({{ $expense->id }})"
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
                    {{ $expenses->total() }} {{ $expenses->total() === 1 ? 'concepto' : 'conceptos' }}
                </p>

                {{ $expenses->links() }}
            </div>
        @endif
    </x-ui.card>

    @if ($showingForm)
        <x-ui.modal :title="$editing ? 'Editar concepto' : 'Nuevo concepto'"
                    description="Sólo el nombre y, si hace falta, una descripción. El importe se pone luego en cada ruta.">
            {{-- `wire:submit` y no un `wire:click`: así también se envía con Enter, y el
                 navegador aplica antes sus propias restricciones (`required`, `min`, `step`),
                 que son la primera barrera. Las de verdad son las del servidor. --}}
            <form wire:submit="save" id="form-gasto" class="space-y-4">
                <x-ui.field label="Nombre del concepto" for="name" :error="$errors->first('name')"
                            hint="Cómo lo llamáis: «Gasolina», «Pago al transportista», «Mantenimiento»…">
                    {{-- `.blur` para que el campo se valide al salir de él y no en cada tecla:
                         un «ya existe» parpadeando mientras se escribe estorba más que ayuda. --}}
                    <x-ui.input wire:model.blur="name" id="name" :invalid="$errors->has('name')"
                                required maxlength="255" autocomplete="off" autofocus />
                </x-ui.field>

                <x-ui.field label="Descripción" for="description" :error="$errors->first('description')"
                            hint="Opcional. Qué entra en este concepto, para que todo el mundo lo use igual.">
                    <x-ui.textarea wire:model.blur="description" id="description"
                                   :invalid="$errors->has('description')" maxlength="1000" rows="3" />
                </x-ui.field>
            </form>

            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="cancel" wire:loading.attr="disabled">
                    Cancelar
                </x-ui.button>

                {{-- La mitad de cliente del doble envío: se desactiva mientras `save` está en
                     vuelo. La otra mitad es el cerrojo del servidor, que es el que de verdad
                     lo impide. --}}
                <x-ui.button type="submit" form="form-gasto"
                             wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">{{ $editing ? 'Guardar' : 'Crear' }}</span>
                    <span wire:loading wire:target="save">Guardando…</span>
                </x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($confirmingDeletion && $objetivo = $this->deletionTarget())
        <x-ui.confirm-modal title="Dar de baja el concepto" :name="$objetivo->name"
                            confirm="delete({{ $confirmingDeletion }})">
            Dejará de poder elegirse al crear un gasto. Podrás reactivarlo cuando
            quieras{{ $objetivo->routeExpenses()->count() > 0 ? ', pero primero hay que retirar los gastos que lo usan' : '' }}.
        </x-ui.confirm-modal>
    @endif

</div>
