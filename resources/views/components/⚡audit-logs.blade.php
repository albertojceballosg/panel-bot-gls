<?php

use App\Models\AuditLog;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\PickupRoute;
use App\Models\User;
use App\Support\AuditPresenter;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Listado de auditoría (CONTEXTO.md §7, módulo 6).
 *
 * El historial de la ficha responde «¿qué le pasó a *este* comercio?», pero
 * sólo si ya sospechas de él. Esto responde la pregunta de verdad, que es al
 * revés y cronológica: «el informe de ayer cambió, ¿qué tocó alguien?».
 *
 * Es de sólo lectura: `audit_logs` no se modifica ni se borra nunca (§4).
 */
new #[Layout('components.layouts.app')] class extends Component
{
    use WithPagination;

    public const POR_PAGINA = 15;

    /** Busca por autor y por nombre del registro tocado. */
    public string $search = '';

    /** Nombre de clase del modelo, o '' para todos. */
    public string $moduleFilter = '';

    public ?int $viewing = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedModuleFilter(): void
    {
        $this->resetPage();
    }

    public function show(int $id): void
    {
        $this->viewing = $id;
    }

    public function close(): void
    {
        $this->viewing = null;
    }

    public function paginationView(): string
    {
        return 'vendor.pagination.panel';
    }

    public function with(): array
    {
        $presenter = AuditPresenter::make();

        $logs = AuditLog::query()
            ->when($this->moduleFilter !== '', fn ($q) => $q->where('auditable_type', $this->moduleFilter))
            ->when($this->search !== '', function ($q) {
                $termino = '%'.addcslashes(trim($this->search), '%_\\').'%';

                // El nombre del registro vive dentro del JSON del volcado, así
                // que se busca ahí además de en el autor.
                $q->where(fn ($q) => $q
                    ->where('user_email', 'ilike', $termino)
                    ->orWhereRaw("coalesce(after->>'name', before->>'name') ilike ?", [$termino]));
            })
            // `user` para el nombre del autor; `auditable` sólo como respaldo
            // cuando el volcado no trae nombre.
            ->with(['user', 'auditable'])
            ->latest('created_at')
            ->latest('id')
            ->paginate(self::POR_PAGINA);

        return [
            'logs' => $logs,
            'entries' => $logs->getCollection()->mapWithKeys(
                fn ($log) => [$log->id => $presenter->entry($log)],
            ),
            'modules' => [
                PickupRoute::class => 'Rutas',
                Courier::class => 'UT',
                Merchant::class => 'Comercios',
                User::class => 'Usuarios',
            ],
            'detail' => $this->viewing === null
                ? null
                : $presenter->entry(AuditLog::with('user', 'auditable')->findOrFail($this->viewing)),
        ];
    }
}; ?>

<div>
    <x-ui.page-header title="Auditoría"
                      description="Todos los cambios del maestro, del más reciente al más antiguo. Lo que cargó el seeder no aparece: no es el cambio de nadie." />

    <x-ui.card padding="p-0">
        <div class="flex flex-col gap-3 border-b border-slate-200 px-6 py-3 sm:flex-row sm:items-center">
            <div class="relative min-w-0 flex-1">
                <svg class="pointer-events-none absolute top-2.5 left-3 size-4 text-slate-400"
                     fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z" />
                </svg>
                <x-ui.input wire:model.live.debounce.300ms="search" class="pl-9"
                            placeholder="Buscar por autor o por registro…" aria-label="Buscar" />
            </div>

            <x-ui.searchable-select wire:model.live="moduleFilter" class="sm:w-48"
                                    aria-label="Filtrar por módulo"
                                    :options="$modules"
                                    :value="$moduleFilter"
                                    placeholder="Todos los módulos"
                                    search-placeholder="Buscar un módulo…" />
        </div>

        @if ($logs->isEmpty())
            <x-ui.empty-state :title="$search !== '' || $moduleFilter !== '' ? 'Ningún cambio coincide' : 'Todavía no hay cambios'"
                              :description="$search !== '' || $moduleFilter !== ''
                                  ? 'Prueba con otro autor, otro registro u otro módulo.'
                                  : 'En cuanto alguien edite algo desde el panel, aparecerá aquí.'">
                <x-slot:actions>
                    @if ($search !== '' || $moduleFilter !== '')
                        <x-ui.button variant="secondary" wire:click="$set('search', ''); $set('moduleFilter', '')">
                            Quitar los filtros
                        </x-ui.button>
                    @endif
                </x-slot:actions>
            </x-ui.empty-state>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-3xl text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-left text-xs tracking-wider text-slate-500 uppercase">
                            <th class="px-6 py-3 font-semibold">Cuándo</th>
                            <th class="px-6 py-3 font-semibold">Quién</th>
                            <th class="px-6 py-3 font-semibold">Módulo</th>
                            <th class="px-6 py-3 font-semibold">Registro</th>
                            <th class="px-6 py-3 font-semibold">Acción</th>
                            <th class="px-6 py-3"><span class="sr-only">Detalle</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($logs as $log)
                            @php
                                $entry = $entries[$log->id];
                            @endphp

                            <tr wire:key="log-{{ $log->id }}" class="hover:bg-slate-50/75">
                                <td class="px-6 py-3 whitespace-nowrap text-slate-500 tabular-nums">
                                    {{ $entry['at']->format('d/m/Y H:i') }}
                                </td>

                                <td class="px-6 py-3 text-slate-700">{{ $entry['author'] }}</td>

                                <td class="px-6 py-3">
                                    <span class="rounded-md bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">
                                        {{ $entry['module'] }}
                                    </span>
                                </td>

                                <td class="px-6 py-3 font-medium text-shell-900">{{ $entry['record'] }}</td>

                                <td class="px-6 py-3">
                                    @php
                                        $badge = match ($entry['action']->value) {
                                            'CREATE' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
                                            'DELETE' => 'bg-red-50 text-red-700 ring-red-200',
                                            'RESTORE' => 'bg-amber-50 text-amber-700 ring-amber-200',
                                            default => 'bg-brand-50 text-brand-700 ring-brand-200',
                                        };
                                    @endphp

                                    <span class="rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $badge }}">
                                        {{ $entry['action']->label() }}
                                    </span>
                                </td>

                                <td class="px-6 py-3">
                                    <div class="flex justify-end">
                                        <x-ui.button variant="secondary" wire:click="show({{ $log->id }})"
                                                     class="px-2.5 py-1 text-xs">
                                            Ver detalle
                                            @if ($entry['changes'])
                                                <span class="text-slate-400">({{ count($entry['changes']) }})</span>
                                            @endif
                                        </x-ui.button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-6 py-3">
                <p class="text-sm text-slate-500">
                    {{ $logs->total() }} {{ $logs->total() === 1 ? 'cambio' : 'cambios' }}
                </p>

                {{ $logs->links() }}
            </div>
        @endif
    </x-ui.card>

    @if ($detail)
        <x-ui.modal title="Detalle del cambio"
                    :description="$detail['module'].' · '.$detail['record']"
                    close="close" class="max-w-2xl">
            <div class="mb-4 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">
                    {{ $detail['action']->label() }}
                </span>
                <span class="text-slate-700">{{ $detail['author'] }}</span>
                <span class="text-slate-400">{{ $detail['at']->format('d/m/Y H:i:s') }}</span>
            </div>

            @if ($detail['changes'])
                <div class="overflow-hidden rounded-lg border border-slate-200">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-slate-50 text-left text-xs tracking-wider text-slate-500 uppercase">
                                <th class="px-3 py-1.5 font-semibold">Campo</th>
                                <th class="px-3 py-1.5 font-semibold">Antes</th>
                                <th class="px-3 py-1.5 font-semibold">Después</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($detail['changes'] as $change)
                                <tr>
                                    <td class="px-3 py-1.5 font-medium text-slate-600">{{ $change['label'] }}</td>
                                    <td class="px-3 py-1.5 text-slate-400 line-through">{{ $change['before'] }}</td>
                                    <td class="px-3 py-1.5 text-shell-900">{{ $change['after'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="text-sm text-slate-500">Este cambio no dejó campos modificados.</p>
            @endif

            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="close">Cerrar</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
