<?php

use App\Models\Merchant;
use App\Models\PickupRoute;
use App\Support\CrudScreen;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * CRUD de comercios (CONTEXTO.md §7, fase 3, módulo 4).
 *
 * Es el maestro que consume el bot: cada fila de aquí decide a qué ruta se
 * asigna un envío y, por tanto, si se marca como incidencia. De ahí que la
 * unicidad del nombre y la del código estén tan atadas (§4).
 */
new #[Layout('components.layouts.app')] class extends Component
{
    use CrudScreen;

    public const POR_PAGINA = 10;

    public string $name = '';

    /** Nullable: 11 de los 93 comercios no tienen código (§3). */
    public ?string $code = null;

    public string $pickup_route_id = '';

    /** Filtro del listado, aparte de la búsqueda por texto. */
    public string $routeFilter = '';

    protected function model(): string
    {
        return Merchant::class;
    }

    protected function formFields(): array
    {
        return ['name', 'code', 'pickup_route_id'];
    }

    protected function fillForm($record): void
    {
        $this->name = $record->name;
        $this->code = $record->code === null ? null : (string) $record->code;
        $this->pickup_route_id = (string) $record->pickup_route_id;
    }

    protected function label(): string
    {
        return 'comercio';
    }

    public function updatedRouteFilter(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        return [
            'merchants' => Merchant::query()
                ->when($this->showingTrashed, fn ($q) => $q->withTrashed())
                // Por nombre y por código: el código es lo que el cliente tiene
                // delante cuando mira el portal.
                ->when($this->search !== '', fn ($q) => $q->where(fn ($q) => $q
                    ->where('name', 'ilike', $this->likeTerm())
                    ->orWhereRaw('code::text ilike ?', [$this->likeTerm()])))
                ->when($this->routeFilter !== '', fn ($q) => $q->where('pickup_route_id', $this->routeFilter))
                // `pickupRoute.courier` de una tirada: el mensajero es derivado
                // (§4) y sin esto serían dos consultas por fila.
                ->with('pickupRoute.courier')
                ->orderBy('name')
                ->paginate(self::POR_PAGINA),

            // Todas: un comercio pertenece a una ruta y varias comparten ruta,
            // así que aquí no hay nada que reservar.
            'pickupRoutes' => PickupRoute::orderBy('name')->get(),
        ];
    }

    public function save(): void
    {
        // Un `<input>` vacío llega como '', y la regla es `nullable|integer`:
        // sin esto, «sin código» se leería como un entero mal formado.
        if ($this->code === '') {
            $this->code = null;
        }

        // Validación en el servidor, con las reglas del modelo (§7, fase 1).
        $this->validate(Merchant::rules($this->editing));

        $editando = $this->editing !== null;

        $hecho = $this->transactionally($this->lockKey('save'), fn () => Merchant::withTrashed()
            ->findOr($this->editing ?? 0, fn () => new Merchant)
            ->fill([
                'name' => $this->name,
                'code' => $this->code === null ? null : (int) $this->code,
                'pickup_route_id' => (int) $this->pickup_route_id,
            ])
            ->save());

        if (! $hecho) {
            return;
        }

        $this->cancel();
        session()->flash('ok', $editando ? 'Comercio actualizado.' : 'Comercio creado.');
    }
}; ?>

<div>
    <x-ui.page-header title="Comercios"
                      description="El maestro que descarga el bot. Cada comercio pertenece a una ruta, y de ahí sale el mensajero que lo recoge.">
        <x-slot:actions>
            <x-ui.button wire:click="create">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                Nuevo comercio
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
        <div class="flex flex-col gap-3 border-b border-slate-200 px-6 py-3 sm:flex-row sm:items-center">
            <div class="relative min-w-0 flex-1">
                <svg class="pointer-events-none absolute top-2.5 left-3 size-4 text-slate-400"
                     fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z" />
                </svg>
                <x-ui.input wire:model.live.debounce.300ms="search" class="pl-9"
                            placeholder="Buscar por nombre o código…" aria-label="Buscar" />
            </div>

            <x-ui.select wire:model.live="routeFilter" class="sm:w-44" aria-label="Filtrar por ruta">
                <option value="">Todas las rutas</option>

                @foreach ($pickupRoutes as $pickupRoute)
                    <option value="{{ $pickupRoute->id }}">Ruta {{ $pickupRoute->name }}</option>
                @endforeach
            </x-ui.select>

            <label class="flex items-center gap-2 text-sm whitespace-nowrap text-slate-600">
                <input type="checkbox" wire:model.live="showingTrashed"
                       class="size-4 rounded border-slate-300 text-brand-500 focus:ring-brand-500">
                Ver dados de baja
            </label>
        </div>

        @if ($merchants->isEmpty())
            <x-ui.empty-state :title="$search !== '' || $routeFilter !== '' ? 'Ningún comercio coincide' : 'Todavía no hay comercios'"
                              :description="$search !== '' || $routeFilter !== ''
                                  ? 'Prueba con otro nombre, otro código u otra ruta.'
                                  : 'Da de alta el primero y asígnalo a una ruta.'">
                <x-slot:actions>
                    @if ($search !== '' || $routeFilter !== '')
                        <x-ui.button variant="secondary" wire:click="$set('search', ''); $set('routeFilter', '')">
                            Quitar los filtros
                        </x-ui.button>
                    @else
                        <x-ui.button wire:click="create">Nuevo comercio</x-ui.button>
                    @endif
                </x-slot:actions>
            </x-ui.empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-3xl text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-left text-xs tracking-wider text-slate-500 uppercase">
                            <th class="px-6 py-3 font-semibold">Comercio</th>
                            <th class="px-6 py-3 font-semibold">Código</th>
                            <th class="px-6 py-3 font-semibold">Ruta</th>
                            <th class="px-6 py-3 font-semibold">Mensajero</th>
                            <th class="px-6 py-3"><span class="sr-only">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($merchants as $merchant)
                            <tr wire:key="comercio-{{ $merchant->id }}" class="hover:bg-slate-50/75">
                                <td class="px-6 py-3">
                                    <span class="font-medium text-shell-900">{{ $merchant->name }}</span>

                                    @if ($merchant->trashed())
                                        <span class="ml-2 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500">
                                            dado de baja
                                        </span>
                                    @endif
                                </td>

                                <td class="px-6 py-3 tabular-nums">
                                    @if ($merchant->code !== null)
                                        <span class="text-slate-700">{{ $merchant->code }}</span>
                                    @else
                                        {{-- Sin código el bot cruza por nombre, y eso es fuzzy (§3). --}}
                                        <span class="text-slate-400" title="Sin código, el bot cruza por nombre">—</span>
                                    @endif
                                </td>

                                <td class="px-6 py-3">
                                    <span class="rounded-md bg-brand-50 px-2 py-0.5 text-xs font-medium text-brand-700">
                                        {{ $merchant->pickupRoute?->name ?? '—' }}
                                    </span>
                                </td>

                                <td class="px-6 py-3">
                                    @if ($merchant->pickupRoute?->courier)
                                        <span class="text-slate-700">{{ $merchant->pickupRoute->courier->name }}</span>
                                    @else
                                        <span class="text-slate-400">sin asignar</span>
                                    @endif
                                </td>

                                <td class="px-6 py-3">
                                    <div class="flex justify-end gap-1">
                                        @if ($merchant->trashed())
                                            <x-ui.icon-button label="Reactivar"
                                                              wire:click="restore({{ $merchant->id }})"
                                                              wire:loading.attr="disabled">
                                                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                          d="M16.023 9.348h4.992V4.356M2.985 19.644v-4.992h4.992M19.5 9a7.5 7.5 0 00-13.02-3.02L2.985 9m0 6a7.5 7.5 0 0013.02 3.02l3.495-3.02" />
                                                </svg>
                                            </x-ui.icon-button>
                                        @else
                                            <x-ui.icon-button label="Editar"
                                                              wire:click="edit({{ $merchant->id }})"
                                                              wire:loading.attr="disabled">
                                                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                          d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" />
                                                </svg>
                                            </x-ui.icon-button>

                                            <x-ui.icon-button label="Dar de baja" variant="danger"
                                                              wire:click="confirmDelete({{ $merchant->id }})"
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
                    {{ $merchants->total() }} {{ $merchants->total() === 1 ? 'comercio' : 'comercios' }}
                </p>

                {{ $merchants->links() }}
            </div>
        @endif
    </x-ui.card>

    @if ($showingForm)
        <x-ui.modal :title="$editing ? 'Editar comercio' : 'Nuevo comercio'"
                    description="El nombre se guarda tal cual: el bot lo normaliza por su cuenta.">
            <form wire:submit="save" id="form-comercio" class="space-y-4">
                <x-ui.field label="Nombre" for="name" :error="$errors->first('name')"
                            hint="Tal y como aparece en el portal, sin retocar.">
                    <x-ui.input wire:model="name" id="name" :invalid="$errors->has('name')" autofocus />
                </x-ui.field>

                <x-ui.field label="Código" for="code" :error="$errors->first('code')"
                            hint="El número entre paréntesis del portal. Déjalo vacío si no lo tiene: entonces el bot cruza por nombre.">
                    <x-ui.input wire:model="code" id="code" type="number" min="1"
                                :invalid="$errors->has('code')" placeholder="Sin código" />
                </x-ui.field>

                <x-ui.field label="Ruta" for="pickup_route_id" :error="$errors->first('pickup_route_id')"
                            hint="De aquí sale el mensajero que lo recoge.">
                    <x-ui.select wire:model="pickup_route_id" id="pickup_route_id"
                                 :invalid="$errors->has('pickup_route_id')">
                        <option value="">Elige una ruta</option>

                        @foreach ($pickupRoutes as $pickupRoute)
                            <option value="{{ $pickupRoute->id }}">{{ $pickupRoute->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
            </form>

            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="cancel" wire:loading.attr="disabled">
                    Cancelar
                </x-ui.button>

                <x-ui.button type="submit" form="form-comercio"
                             wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">{{ $editing ? 'Guardar' : 'Crear' }}</span>
                    <span wire:loading wire:target="save">Guardando…</span>
                </x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($confirmingDeletion && $objetivo = $this->deletionTarget())
        <x-ui.confirm-modal title="Dar de baja el comercio" :name="$objetivo->name"
                            confirm="delete({{ $confirmingDeletion }})">
            Dejará de servirse al bot, así que sus envíos quedarán sin evaluar hasta que lo
            reactives.
        </x-ui.confirm-modal>
    @endif

</div>
