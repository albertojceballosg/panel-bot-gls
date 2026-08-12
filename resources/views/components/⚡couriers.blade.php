<?php

use App\Models\Courier;
use App\Models\PickupRoute;
use App\Support\CrudScreen;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * CRUD de mensajeros (CONTEXTO.md §7, fase 3, módulo 3).
 *
 * El mensajero es quien conduce una ruta hoy, no parte de su definición (§4):
 * puede quedarse sin ruta, y darlo de baja no toca ni la ruta ni sus comercios.
 */
new #[Layout('components.layouts.app')] class extends Component
{
    use CrudScreen;

    public const POR_PAGINA = 10;

    public string $name = '';

    /** Cadena y no int: un `<select>` devuelve texto, y '' es "sin ruta". */
    public string $pickup_route_id = '';

    protected function model(): string
    {
        return Courier::class;
    }

    protected function formFields(): array
    {
        return ['name', 'pickup_route_id'];
    }

    protected function fillForm($record): void
    {
        $this->name = $record->name;
        $this->pickup_route_id = (string) ($record->pickup_route_id ?? '');
    }

    protected function label(): string
    {
        return 'mensajero';
    }

    public function with(): array
    {
        return [
            'couriers' => Courier::query()
                ->when($this->showingTrashed, fn ($q) => $q->withTrashed())
                ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                    ->where('name', 'ilike', $this->likeTerm())
                    ->orWhereHas('pickupRoute', fn ($q) => $q->where('name', 'ilike', $this->likeTerm()))))
                ->with('pickupRoute')
                ->orderBy('name')
                ->paginate(self::POR_PAGINA),

            'availableRoutes' => $this->availableRoutes(),
        ];
    }

    /**
     * Sólo las rutas sin conductor, más la del mensajero que se está editando.
     *
     * `couriers.pickup_route_id` es único entre los vivos (§4), así que ofrecer
     * las ocupadas sería ofrecer opciones que la base va a rechazar. La regla
     * de validación lo corta igual; esto evita que llegues a intentarlo.
     */
    private function availableRoutes()
    {
        return PickupRoute::query()
            ->where(fn ($q) => $q
                ->whereDoesntHave('courier')
                ->when($this->editing, fn ($q, $id) => $q
                    ->orWhereHas('courier', fn ($q) => $q->whereKey($id))))
            ->orderBy('name')
            ->get();
    }

    public function save(): void
    {
        // Validación en el servidor, con las reglas del modelo (§7, fase 1).
        $this->validate(Courier::rules($this->editing));

        $editando = $this->editing !== null;

        $hecho = $this->transactionally($this->lockKey('save'), fn () => Courier::withTrashed()
            ->findOr($this->editing ?? 0, fn () => new Courier)
            ->fill([
                'name' => $this->name,
                // '' significa "sin ruta asignada", y en la base es NULL.
                'pickup_route_id' => $this->pickup_route_id === '' ? null : (int) $this->pickup_route_id,
            ])
            ->save());

        if (! $hecho) {
            return;
        }

        $this->cancel();
        session()->flash('ok', $editando ? 'Mensajero actualizado.' : 'Mensajero creado.');
    }
}; ?>

<div>
    <x-ui.page-header title="Mensajeros"
                      description="Quién conduce cada ruta hoy. Dar de baja a un mensajero no toca la ruta ni sus comercios.">
        <x-slot:actions>
            <x-ui.button wire:click="create">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                Nuevo mensajero
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('ok'))
        <x-ui.alert type="success" class="mb-4">{{ session('ok') }}</x-ui.alert>
    @endif

    @if (session('error'))
        <x-ui.alert type="error" class="mb-4">{{ session('error') }}</x-ui.alert>
    @endif

    <x-ui.card padding="p-0">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-6 py-3">
            <div class="relative min-w-64 flex-1">
                <svg class="pointer-events-none absolute top-2.5 left-3 size-4 text-slate-400"
                     fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z" />
                </svg>
                <x-ui.input wire:model.live.debounce.300ms="search" class="pl-9"
                            placeholder="Buscar por mensajero o ruta…" aria-label="Buscar" />
            </div>

            <label class="flex items-center gap-2 text-sm whitespace-nowrap text-slate-600">
                <input type="checkbox" wire:model.live="showingTrashed"
                       class="size-4 rounded border-slate-300 text-brand-500 focus:ring-brand-500">
                Ver dados de baja
            </label>
        </div>

        @if ($couriers->isEmpty())
            <x-ui.empty-state :title="$search !== '' ? 'Ningún mensajero coincide' : 'Todavía no hay mensajeros'"
                              :description="$search !== ''
                                  ? 'Prueba con otro nombre de mensajero o de ruta.'
                                  : 'Da de alta al primero y asígnale una ruta.'">
                <x-slot:actions>
                    @if ($search !== '')
                        <x-ui.button variant="secondary" wire:click="$set('search', '')">Quitar el filtro</x-ui.button>
                    @else
                        <x-ui.button wire:click="create">Nuevo mensajero</x-ui.button>
                    @endif
                </x-slot:actions>
            </x-ui.empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-2xl text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-left text-xs tracking-wider text-slate-500 uppercase">
                            <th class="px-6 py-3 font-semibold">Mensajero</th>
                            <th class="px-6 py-3 font-semibold">Ruta que conduce</th>
                            <th class="px-6 py-3"><span class="sr-only">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($couriers as $courier)
                            <tr wire:key="mensajero-{{ $courier->id }}" class="hover:bg-slate-50/75">
                                <td class="px-6 py-3">
                                    <span class="font-medium text-shell-900">{{ $courier->name }}</span>

                                    @if ($courier->trashed())
                                        <span class="ml-2 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500">
                                            dado de baja
                                        </span>
                                    @endif
                                </td>

                                <td class="px-6 py-3">
                                    @if ($courier->pickupRoute)
                                        <span class="rounded-md bg-brand-50 px-2 py-0.5 text-xs font-medium text-brand-700">
                                            {{ $courier->pickupRoute->name }}
                                        </span>
                                    @else
                                        <span class="text-slate-400">sin ruta asignada</span>
                                    @endif
                                </td>

                                <td class="px-6 py-3">
                                    <div class="flex justify-end gap-1">
                                        @if ($courier->trashed())
                                            <x-ui.icon-button label="Reactivar"
                                                              wire:click="restore({{ $courier->id }})"
                                                              wire:loading.attr="disabled">
                                                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                          d="M16.023 9.348h4.992V4.356M2.985 19.644v-4.992h4.992M19.5 9a7.5 7.5 0 00-13.02-3.02L2.985 9m0 6a7.5 7.5 0 0013.02 3.02l3.495-3.02" />
                                                </svg>
                                            </x-ui.icon-button>
                                        @else
                                            <x-ui.icon-button label="Editar"
                                                              wire:click="edit({{ $courier->id }})"
                                                              wire:loading.attr="disabled">
                                                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                          d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" />
                                                </svg>
                                            </x-ui.icon-button>

                                            <x-ui.icon-button label="Dar de baja" variant="danger"
                                                              wire:click="confirmDelete({{ $courier->id }})"
                                                              wire:loading.attr="disabled">
                                                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                          d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                                </svg>
                                            </x-ui.icon-button>
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
                    {{ $couriers->total() }} {{ $couriers->total() === 1 ? 'mensajero' : 'mensajeros' }}
                </p>

                {{ $couriers->links() }}
            </div>
        @endif
    </x-ui.card>

    @if ($showingForm)
        <x-ui.modal :title="$editing ? 'Editar mensajero' : 'Nuevo mensajero'"
                    description="Puede quedarse sin ruta: la ruta existe aunque nadie la conduzca.">
            <form wire:submit="save" id="form-mensajero" class="space-y-4">
                <x-ui.field label="Nombre" for="name" :error="$errors->first('name')">
                    <x-ui.input wire:model="name" id="name" :invalid="$errors->has('name')" autofocus />
                </x-ui.field>

                <x-ui.field label="Ruta que conduce" for="pickup_route_id"
                            :error="$errors->first('pickup_route_id')"
                            hint="Sólo aparecen las rutas sin conductor: una ruta la lleva un solo mensajero.">
                    <x-ui.select wire:model="pickup_route_id" id="pickup_route_id"
                                 :invalid="$errors->has('pickup_route_id')">
                        <option value="">Sin ruta asignada</option>

                        @foreach ($availableRoutes as $pickupRoute)
                            <option value="{{ $pickupRoute->id }}">{{ $pickupRoute->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                @if ($availableRoutes->isEmpty())
                    <p class="text-xs text-slate-500">
                        Todas las rutas tienen ya un mensajero. Libera una quitándosela a quien la lleve.
                    </p>
                @endif
            </form>

            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="cancel" wire:loading.attr="disabled">
                    Cancelar
                </x-ui.button>

                <x-ui.button type="submit" form="form-mensajero"
                             wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">{{ $editing ? 'Guardar' : 'Crear' }}</span>
                    <span wire:loading wire:target="save">Guardando…</span>
                </x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($confirmingDeletion && $objetivo = $this->deletionTarget())
        <x-ui.confirm-modal title="Dar de baja al mensajero" :name="$objetivo->name"
                            confirm="delete({{ $confirmingDeletion }})">
            Su ruta queda libre para otro mensajero, y ni la ruta ni sus comercios se tocan.
            Podrás reactivarlo cuando quieras.
        </x-ui.confirm-modal>
    @endif

</div>
