<?php

use App\Models\RunPackage;
use App\Models\IncidentRun;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Listado de jornadas de incidencias (CONTEXTO.md §7, fase 6.C).
 *
 * Es la puerta, no el contenido: el bot sube una jornada cada mañana, así que
 * esto crece una fila al día y lo que hace falta aquí es **elegir un día**. El
 * análisis está en el detalle.
 *
 * **La cifra grande es la de hallazgos firmes, no la de incidencias.** Del
 * 03/08: 168 incidencias, de las que el bot sostiene 8. Poner 168 en grande
 * anuncia un incendio que el propio bot no sostiene, y esconde que el trabajo
 * real de ese día son dos conversaciones.
 *
 * **La cobertura se enseña siempre.** Ese mismo día, 490 de 983 envíos no se
 * pudieron evaluar porque su comercio no está en el maestro. Un listado que
 * sólo diga «168 incidencias» se lee como «el día está revisado», y la mitad
 * no lo está.
 */
new #[Layout('components.layouts.app')] class extends Component
{
    use WithPagination;

    public const POR_PAGINA = 15;

    #[Url(as: 'desde', except: '')]
    public string $from = '';

    #[Url(as: 'hasta', except: '')]
    public string $to = '';

    public function updatedFrom(): void
    {
        $this->resetPage();
    }

    public function updatedTo(): void
    {
        $this->resetPage();
    }

    public function clearFilter(): void
    {
        $this->reset('from', 'to');
        $this->resetPage();
    }

    /** Ver `CrudScreen`: Livewire sólo mira aquí, no `Paginator::defaultView()`. */
    public function paginationView(): string
    {
        return 'vendor.pagination.panel';
    }

    /**
     * Una fecha imposible llega por la URL, no por el `<input type="date">`.
     * Sin esto, `?desde=lo-que-sea` revienta en Postgres con un 500.
     */
    private function date(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    public function with(): array
    {
        $desde = $this->date($this->from);
        $hasta = $this->date($this->to);

        $jornadas = IncidentRun::query()
            ->withCount([
                'currentIncidents as incidencias',
                'currentIncidents as firmes' => fn ($query) => $query->where('confidence', RunPackage::CONFIDENCE_HIGH),
            ])
            ->when($desde, fn ($query) => $query->where('run_date', '>=', $desde))
            ->when($hasta, fn ($query) => $query->where('run_date', '<=', $hasta))
            ->orderByDesc('run_date')
            ->paginate(self::POR_PAGINA);

        return [
            'jornadas' => $jornadas,
            'filtrando' => $this->from !== '' || $this->to !== '',
            'hayAlguna' => IncidentRun::exists(),
        ];
    }
}; ?>

<div>
    <x-ui.page-header title="Incidencias"
                      description="Una jornada por día. El bot la sube al terminar su corrida de la mañana." />

    <x-ui.card padding="p-0">
        {{-- El buscador se pinta sólo si ya hay jornadas: con la base recién
             montada sería un control muerto. --}}
        @if ($hayAlguna)
            <div class="flex flex-wrap items-end gap-4 border-b border-slate-200 px-6 py-4">
                <div>
                    <label for="desde" class="block text-xs font-medium text-slate-500">Desde</label>
                    {{-- `max` y `min` cruzados: el navegador no deja elegir un
                         rango imposible, que es mejor que explicarlo después. --}}
                    <input id="desde" type="date" wire:model.live="from" max="{{ $to ?: null }}"
                           class="mt-1 rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                </div>

                <div>
                    <label for="hasta" class="block text-xs font-medium text-slate-500">Hasta</label>
                    <input id="hasta" type="date" wire:model.live="to" min="{{ $from ?: null }}"
                           class="mt-1 rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                </div>

                @if ($filtrando)
                    <button type="button" wire:click="clearFilter"
                            class="pb-2 text-sm font-medium text-brand-600 hover:text-brand-700">
                        Quitar filtro
                    </button>
                @endif
            </div>
        @endif

        @if ($jornadas->isEmpty())
            @if ($filtrando)
                <x-ui.empty-state title="Ninguna jornada en esas fechas"
                                  description="Prueba con un rango más amplio o quita el filtro." />
            @else
                <x-ui.empty-state title="Todavía no ha llegado ninguna jornada"
                                  description="El bot sube las incidencias al terminar su corrida diaria. Cuando lo haga, aparecerán aquí." />
            @endif
        @else
            <div class="overflow-x-auto">
                {{-- Cinco columnas y no siete: «Envíos» y «Evaluados» por
                     separado repetían lo que ya dice la cobertura, y con siete
                     la tabla no cabía en una pantalla normal. --}}
                <table class="w-full min-w-2xl text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-left text-xs tracking-wider text-slate-500 uppercase">
                            <th class="px-6 py-3 font-semibold">Jornada</th>
                            <th class="px-6 py-3 font-semibold">Evaluados</th>
                            <th class="px-6 py-3 text-right font-semibold">Incidencias</th>
                            <th class="px-6 py-3 text-right font-semibold">Firmes</th>
                            <th class="px-6 py-3"><span class="sr-only">Acciones</span></th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-slate-100">
                        @foreach ($jornadas as $jornada)
                            @php
                                // Cuánto del día se pudo mirar de verdad. Es el
                                // denominador honesto: sin él, «168 incidencias»
                                // parece el día entero.
                                $cobertura = $jornada->shipments > 0
                                    ? (int) round($jornada->evaluated / $jornada->shipments * 100)
                                    : 0;

                                $detalle = route('incident-run', $jornada->run_date->toDateString());
                            @endphp

                            <tr wire:key="jornada-{{ $jornada->id }}" class="hover:bg-slate-50/75">
                                <td class="px-6 py-3 whitespace-nowrap">
                                    {{-- Formato corto: en una tabla, «lun. 3 de
                                         agosto de 2026» se parte en tres líneas y
                                         descuadra la fila entera. El día de la
                                         semana se queda porque una recogida no se
                                         lee igual en lunes que en sábado. --}}
                                    <a href="{{ $detalle }}" wire:navigate
                                       class="font-medium text-shell-900 hover:text-brand-600">
                                        {{ $jornada->run_date->translatedFormat('D d/m/Y') }}
                                    </a>

                                    {{-- Una corrida dudosa no cubre el día entero
                                         (§3.1): tiene que verse desde el listado,
                                         no sólo al entrar. --}}
                                    @unless ($jornada->reliable)
                                        <span class="ml-2 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">
                                            no fiable
                                        </span>
                                    @endunless
                                </td>

                                {{-- Una barra además de los números: lo que no se
                                     pudo evaluar importa tanto como lo que sí, y
                                     en una cifra suelta se pierde. El 03/08 sólo
                                     se pudo mirar el 47 % del día. --}}
                                <td class="px-6 py-3 whitespace-nowrap"
                                    @if ($jornada->without_route > 0)
                                        title="{{ number_format($jornada->without_route, 0, ',', '.') }} envíos son de comercios que no están en el maestro"
                                    @endif>
                                    <div class="flex items-center gap-2">
                                        <span class="tabular-nums text-slate-700">
                                            {{ number_format($jornada->evaluated, 0, ',', '.') }}
                                            <span class="text-slate-400">/ {{ number_format($jornada->shipments, 0, ',', '.') }}</span>
                                        </span>

                                        <span class="h-1.5 w-16 overflow-hidden rounded-full bg-slate-200">
                                            <span class="block h-full rounded-full {{ $cobertura < 70 ? 'bg-amber-400' : 'bg-emerald-400' }}"
                                                  style="width: {{ $cobertura }}%"></span>
                                        </span>

                                        <span class="text-xs tabular-nums text-slate-500">{{ $cobertura }} %</span>
                                    </div>
                                </td>

                                <td class="px-6 py-3 text-right tabular-nums text-slate-700">
                                    {{ number_format($jornada->incidencias, 0, ',', '.') }}
                                </td>

                                {{-- Lo firme destacado, porque es lo único
                                     accionable: 8 de 168 el 03/08. --}}
                                <td class="px-6 py-3 text-right">
                                    @if ($jornada->firmes > 0)
                                        <span class="rounded-md bg-brand-50 px-2 py-0.5 text-xs font-semibold tabular-nums text-brand-700">
                                            {{ $jornada->firmes }}
                                        </span>
                                    @else
                                        <span class="text-slate-300 tabular-nums">0</span>
                                    @endif
                                </td>

                                <td class="px-6 py-3">
                                    <div class="flex justify-end">
                                        <a href="{{ $detalle }}" wire:navigate
                                           title="Ver la jornada"
                                           class="inline-flex size-8 items-center justify-center rounded-lg text-slate-500
                                                  transition hover:bg-slate-100 hover:text-slate-900">
                                            <span class="sr-only">Ver la jornada</span>
                                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                                            </svg>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-6 py-3">
                <p class="text-sm text-slate-500">
                    {{ $jornadas->total() }} {{ $jornadas->total() === 1 ? 'jornada' : 'jornadas' }}
                </p>

                {{ $jornadas->links() }}
            </div>
        @endif
    </x-ui.card>
</div>
