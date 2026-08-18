<?php

use App\Models\PickupRoute;
use App\Support\CrudScreen;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * CRUD de rutas de recogida (CONTEXTO.md §7, fase 3, módulo 2).
 *
 * La ruta es la entidad duradera del maestro: los mensajeros rotan, la ruta y
 * sus comercios siguen (§4). Por eso el borrado va con red y se puede deshacer.
 */
new #[Layout('components.layouts.app')] class extends Component
{
    use CrudScreen;

    public const POR_PAGINA = 10;

    public string $name = '';

    protected function model(): string
    {
        return PickupRoute::class;
    }

    protected function formFields(): array
    {
        return ['name'];
    }

    protected function fillForm($record): void
    {
        $this->name = $record->name;
    }

    protected function label(): string
    {
        return 'ruta';
    }

    protected function permissionModule(): string
    {
        return 'pickup-routes';
    }

    protected function feminine(): bool
    {
        return true;
    }

    public function with(): array
    {
        return [
            'pickupRoutes' => PickupRoute::query()
                ->when($this->showingTrashed, fn ($q) => $q->withTrashed())
                ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                    ->where('name', 'ilike', $this->likeTerm())
                    ->orWhereHas('courier', fn ($q) => $q->where('name', 'ilike', $this->likeTerm()))))
                // Cuenta y mensajero de una tirada: sin esto son dos consultas
                // por fila y la pantalla crece con el maestro.
                ->withCount('merchants')
                ->with('courier')
                ->orderBy('name')
                ->paginate(self::POR_PAGINA),
        ];
    }

    public function save(): void
    {
        // Esconder el botón no basta: a este método se llega desde el navegador.
        $this->authorizeManage();

        // Validación en el servidor. Las reglas viven en el modelo para que no
        // se reescriban aquí ni en el resto de pantallas (§7, fase 1).
        $this->validate(PickupRoute::rules($this->editing));

        $editando = $this->editing !== null;

        $hecho = $this->transactionally($this->lockKey('save'), fn () => PickupRoute::withTrashed()
            ->findOr($this->editing ?? 0, fn () => new PickupRoute)
            ->fill(['name' => $this->name])
            ->save());

        if (! $hecho) {
            return;
        }

        $this->cancel();
        $this->toast($editando ? 'Ruta actualizada.' : 'Ruta creada.');
    }
}; ?>

<div>
    <x-ui.page-header title="Rutas de recogida"
                      description="Cada ruta agrupa los comercios por los que pasa una furgoneta. El bot las usa para detectar incidencias.">
        <x-slot:actions>
            {{-- Sin permiso de escritura, la pantalla se lee y ya (§7, fase 12). --}}
            @if ($this->canManage())
                <x-ui.button wire:click="create">
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Nueva ruta
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
                            placeholder="Buscar por ruta o UT…" aria-label="Buscar" />
            </div>

            <label class="flex items-center gap-2 text-sm whitespace-nowrap text-slate-600">
                <input type="checkbox" wire:model.live="showingTrashed"
                       class="size-4 rounded border-slate-300 text-brand-500 focus:ring-brand-500">
                Ver dadas de baja
            </label>
        </div>

        @if ($pickupRoutes->isEmpty())
            <x-ui.empty-state :title="$search !== '' ? 'Ninguna ruta coincide' : 'Todavía no hay rutas'"
                              :description="$search !== ''
                                  ? 'Prueba con otro nombre de ruta o de UT.'
                                  : 'Crea la primera para poder asignarle UT y comercios.'">
                <x-slot:actions>
                    @if ($search !== '')
                        <x-ui.button variant="secondary" wire:click="$set('search', '')">Quitar el filtro</x-ui.button>
                    @elseif ($this->canManage())
                        <x-ui.button wire:click="create">Nueva ruta</x-ui.button>
                    @endif
                </x-slot:actions>
            </x-ui.empty-state>
        @else
            {{-- En móvil la tabla no cabe: que desborde ella y no la página. --}}
            <div class="overflow-x-auto">
                <table class="w-full min-w-2xl text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-left text-xs tracking-wider text-slate-500 uppercase">
                            <th class="px-6 py-3 font-semibold">Ruta</th>
                            <th class="px-6 py-3 font-semibold">UT</th>
                            <th class="px-6 py-3 text-right font-semibold">Comercios</th>
                            <th class="px-6 py-3"><span class="sr-only">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($pickupRoutes as $pickupRoute)
                            <tr wire:key="ruta-{{ $pickupRoute->id }}" class="hover:bg-slate-50/75">
                                <td class="px-6 py-3">
                                    <span class="font-medium text-shell-900">{{ $pickupRoute->name }}</span>

                                    @if ($pickupRoute->trashed())
                                        <span class="ml-2 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500">
                                            dada de baja
                                        </span>
                                    @endif
                                </td>

                                <td class="px-6 py-3">
                                    @if ($pickupRoute->courier)
                                        <span class="text-slate-700">{{ $pickupRoute->courier->name }}</span>
                                    @else
                                        <span class="text-slate-400">sin asignar</span>
                                    @endif
                                </td>

                                <td class="px-6 py-3 text-right tabular-nums text-slate-700">
                                    {{ $pickupRoute->merchants_count }}
                                </td>

                                <td class="px-6 py-3">
                                    <div class="flex justify-end gap-1">
                                        @if ($this->canManage())
                                            @if ($pickupRoute->trashed())
                                                <x-ui.icon-button label="Reactivar"
                                                                  wire:click="restore({{ $pickupRoute->id }})"
                                                                  wire:loading.attr="disabled">
                                                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                              d="M16.023 9.348h4.992V4.356M2.985 19.644v-4.992h4.992M19.5 9a7.5 7.5 0 00-13.02-3.02L2.985 9m0 6a7.5 7.5 0 0013.02 3.02l3.495-3.02" />
                                                    </svg>
                                                </x-ui.icon-button>
                                            @else
                                                <x-ui.icon-button label="Editar"
                                                                  wire:click="edit({{ $pickupRoute->id }})"
                                                                  wire:loading.attr="disabled">
                                                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                              d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" />
                                                    </svg>
                                                </x-ui.icon-button>

                                                <x-ui.icon-button label="Dar de baja" variant="danger"
                                                                  wire:click="confirmDelete({{ $pickupRoute->id }})"
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
                    {{ $pickupRoutes->total() }} {{ $pickupRoutes->total() === 1 ? 'ruta' : 'rutas' }}
                </p>

                {{ $pickupRoutes->links() }}
            </div>
        @endif
    </x-ui.card>

    @if ($showingForm)
        <x-ui.modal :title="$editing ? 'Editar ruta' : 'Nueva ruta'"
                    description="El nombre es libre y se puede cambiar cuando quieras.">
            <form wire:submit="save" id="form-ruta">
                <x-ui.field label="Nombre de la ruta" for="name" :error="$errors->first('name')"
                            hint="Hoy se llaman «1» a «6», pero es texto libre.">
                    <x-ui.input wire:model="name" id="name" :invalid="$errors->has('name')" autofocus />
                </x-ui.field>
            </form>

            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="cancel" wire:loading.attr="disabled">
                    Cancelar
                </x-ui.button>

                {{-- La mitad de cliente del doble envío: se desactiva mientras
                     `save` está en vuelo. La otra mitad es el cerrojo del
                     servidor, que es el que de verdad lo impide. --}}
                <x-ui.button type="submit" form="form-ruta"
                             wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">{{ $editing ? 'Guardar' : 'Crear' }}</span>
                    <span wire:loading wire:target="save">Guardando…</span>
                </x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($confirmingDeletion && $objetivo = $this->deletionTarget())
        <x-ui.confirm-modal title="Dar de baja la ruta" :name="$objetivo->name"
                            confirm="delete({{ $confirmingDeletion }})">
            Dejará de aparecer en el listado y de servirse al bot. Podrás reactivarla cuando
            quieras{{ $objetivo->merchants()->count() > 0 ? ', pero primero hay que mover sus comercios a otra ruta' : '' }}.
        </x-ui.confirm-modal>
    @endif

</div>
