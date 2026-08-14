<?php

use App\Models\RunPackage;
use App\Models\IncidentRun;
use App\Support\IncidentPresenter;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Detalle de una jornada, agrupado por ruta (CONTEXTO.md §7, fase 6.C).
 *
 * **Sigue la forma del informe que el cliente ya lee**
 * (`incidencias_<fecha>.txt` del bot): jornada → ruta → paquete, y se agrupa por
 * la ruta **dueña** del paquete, no por la que lo recogió. Es la pregunta del
 * negocio: «de lo mío, ¿qué se fue en otra furgoneta?».
 *
 * Las cinco obligaciones de §6.C, y dónde se cumplen:
 *
 * 1. `confidence` manda: lo firme abre la pantalla y va marcado dentro de cada
 *    ruta; lo no concluyente lleva su motivo escrito en palabras, no en clave.
 * 2. `reliable = false` avisa arriba del todo.
 * 3. Los dos `type` van en tablas distintas. Juntarlos convertiría 56 hechos
 *    neutros en 56 acusaciones.
 * 4. Se pintan los nombres copiados (`assigned_route_name`…), nunca las
 *    relaciones: son la foto del día y renombrar una ruta no puede reescribir
 *    el pasado.
 * 5. Las retiradas quedan fuera: se lee por `currentIncidents()`.
 *
 * **Una sola consulta para las incidencias**, y el agrupado en memoria: son ~170
 * filas de un día y agrupar en SQL obligaría a una consulta por sección.
 */
new #[Layout('components.layouts.app')] class extends Component
{
    public IncidentRun $run;

    /** El paquete abierto en el diálogo de detalle, si hay alguno. */
    public ?int $selected = null;

    public function mount(string $date): void
    {
        $this->run = IncidentRun::where('run_date', $date)->firstOrFail();
    }

    public function show(int $id): void
    {
        $this->selected = $id;
    }

    public function cancel(): void
    {
        $this->selected = null;
    }

    /** @return array<string, mixed> */
    public function with(): array
    {
        // Todos los paquetes evaluados, no sólo las incidencias: la pantalla
        // tiene que poder decir «94 paquetes, 11 con incidencia».
        $paquetes = $this->run->currentPackages()
            ->orderBy('belt_time')
            ->orderBy('id')
            ->get();

        $incidencias = $paquetes->filter->isIncident();

        return [
            'paquetes' => $paquetes,
            'incidencias' => $incidencias,
            'rutas' => $this->porRuta($paquetes),

            // Lo único accionable del día. Va en el balance de arriba y como
            // distintivo de cada ruta; el desglose por comercio se retiró el
            // 13/08/2026 a petición, ver CONTEXTO §6.C.
            'firmes' => $incidencias->where('confidence', RunPackage::CONFIDENCE_HIGH)->count(),

            'traspasos' => $this->traspasos($incidencias),
            'detalle' => $this->selected ? $paquetes->firstWhere('id', $this->selected) : null,
        ];
    }

    /**
     * Una sección por ruta dueña: sus dos clases de hallazgo separadas, lo firme
     * delante dentro de cada una, y **los paquetes que fueron donde debían**.
     *
     * Esos últimos son la mitad de la pregunta del cliente: saber que 83 de los
     * 94 de la Ruta 1 pasaron bien es lo que pone las 11 restantes en
     * proporción. Sólo aparecen si el bot manda la lista completa (§3.1); con un
     * bot que sólo mande incidencias, la sección se queda como estaba.
     */
    private function porRuta(Collection $paquetes): Collection
    {
        return $paquetes
            ->groupBy(fn (RunPackage $p) => $p->assigned_route_name ?? 'Sin ruta')
            ->map(function (Collection $grupo, string $nombre) {
                $incidencias = $grupo->filter->isIncident();

                return [
                    'nombre' => $nombre,
                    'mensajero' => $grupo->first()->assigned_courier_name,
                    'paquetes' => $grupo->count(),
                    'total' => $incidencias->count(),
                    'firmes' => $incidencias->where('confidence', RunPackage::CONFIDENCE_HIGH)->count(),
                    'acusaciones' => $this->firmesDelante($grupo->where('type', RunPackage::TYPE_OTHER_ROUTE)),
                    'descolgados' => $this->firmesDelante($grupo->where('type', RunPackage::TYPE_OUT_OF_BATCH)),
                    'correctos' => $grupo->reject->isIncident()->values(),
                ];
            })
            ->sortBy('nombre');
    }

    /** Lo que el bot sostiene, arriba; lo dudoso, después. */
    private function firmesDelante(Collection $grupo): Collection
    {
        return $grupo->sortByDesc(fn (RunPackage $p) => $p->isConclusive() ? 1 : 0)->values();
    }

    /**
     * «Quién recogió de quién»: el agregado que responde la pregunta del
     * negocio de un vistazo. Sólo acusaciones — un descolgado no señala a nadie.
     */
    private function traspasos(Collection $incidencias): Collection
    {
        return $incidencias
            ->where('type', RunPackage::TYPE_OTHER_ROUTE)
            ->groupBy(fn (RunPackage $p) => $p->assigned_route_name.'|'.$p->observed_route_name)
            ->map(fn (Collection $grupo) => [
                'de' => $grupo->first()->assigned_route_name,
                'a' => $grupo->first()->observed_route_name,
                'total' => $grupo->count(),
                'firmes' => $grupo->where('confidence', RunPackage::CONFIDENCE_HIGH)->count(),
            ])
            ->sortByDesc('total')
            ->values();
    }
}; ?>

@php
    // Las horas van en UTC, que es lo que muestran el informe del bot y GLS
    // Atlas. Pintarlas en Europe/Madrid las correría dos horas y el cliente no
    // podría contrastar ni un solo paquete contra el portal.
    $hora = fn (?\Illuminate\Support\Carbon $t) => $t?->utc()->format('H:i:s') ?? '—';
@endphp

<div>
    <a href="{{ route('incidents') }}" wire:navigate
       class="mb-4 inline-flex items-center gap-1.5 text-sm font-medium text-slate-500 hover:text-slate-900">
        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
        </svg>
        Todas las jornadas
    </a>

    <x-ui.page-header :title="$run->run_date->translatedFormat('j \d\e F \d\e Y')"
                      description="Paquetes que no pasaron por la cinta con el grueso de su ruta. Horas en UTC, las mismas que muestra GLS Atlas." />

    {{-- Obligación 2: una jornada dudosa no cubre todos los envíos del día, y
         leerla como completa lleva a concluir «no hubo más incidencias» cuando
         lo cierto es «no se pudo mirar». --}}
    @unless ($run->reliable)
        <x-ui.alert type="error" class="mb-4">
            <strong>Esta corrida no es fiable.</strong> El bot no pudo consultar bastantes envíos del día,
            así que lo de abajo no cubre la jornada entera: que no aparezca una incidencia no significa
            que no la hubiera.
        </x-ui.alert>
    @endunless

    {{-- El balance, con la cobertura delante: sin el denominador, «168
         incidencias» se lee como si el día estuviera revisado entero. --}}
    <x-ui.card class="mb-4">
        <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            @foreach ([
                ['Envíos del día', $run->shipments, null],
                ['Evaluados', $run->evaluated, 'Los que tienen ruta en el maestro y hora de cinta'],
                ['Incidencias', $incidencias->count(), 'Sobre los evaluados'],
                ['Hallazgos firmes', $firmes, 'Los que el bot sostiene sin reservas'],
            ] as [$label, $total, $ayuda])
                <div @if ($ayuda) title="{{ $ayuda }}" @endif>
                    <dt class="text-xs font-medium text-slate-500">{{ $label }}</dt>
                    <dd class="mt-0.5 text-2xl font-semibold tabular-nums text-shell-900">
                        {{ number_format($total, 0, ',', '.') }}
                    </dd>
                </div>
            @endforeach
        </dl>

        @if ($run->without_route > 0 || $run->without_belt_time > 0)
            <p class="mt-4 border-t border-slate-100 pt-3 text-xs text-slate-500">
                Fuera del análisis:
                @if ($run->without_route > 0)
                    <strong>{{ number_format($run->without_route, 0, ',', '.') }}</strong> envíos de comercios
                    que no están en el maestro
                @endif
                @if ($run->without_route > 0 && $run->without_belt_time > 0) · @endif
                @if ($run->without_belt_time > 0)
                    <strong>{{ number_format($run->without_belt_time, 0, ',', '.') }}</strong> sin paso por la cinta
                @endif
            </p>
        @endif
    </x-ui.card>

    {{-- Las alertas van antes que las incidencias a propósito: son las que
         explican por qué casi todo lo de abajo llega sin concluir. Leerlas
         después es leer 160 sospechas sin saber que el propio bot las descarta. --}}
    @if (filled($run->alerts))
        <x-ui.card class="mb-4">
            <h2 class="text-sm font-semibold text-shell-900">Cómo pasó el día por la cinta</h2>
            <ul class="mt-3 space-y-2">
                @foreach ($run->alerts as $alerta)
                    <li class="flex gap-2 text-sm text-slate-600">
                        <span class="mt-1.5 size-1.5 shrink-0 rounded-full bg-amber-400"></span>
                        {{-- El bot antepone la fecha a cada alerta porque su
                             informe cubre varios días. Aquí la página ya se
                             titula con ella: repetirla ocho veces es ruido. --}}
                        <span>{{ preg_replace('/^\d{4}-\d{2}-\d{2}\s*·\s*/u', '', $alerta['texto'] ?? '') }}</span>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif

    {{-- Qué hay detrás de la columna «Fiabilidad».

         Va plegado y justo encima de las tablas que la usan: quien lea la
         pantalla a diario no necesita releerlo, y quien la abre por primera vez
         no debería tener que preguntar qué separa un «Firme» de un «No
         concluyente» antes de llamar a una UT.

         Los números salen de la propia corrida (`tolerance_minutes`,
         `batch_gap_minutes`), no escritos a mano: si el bot cambia sus umbrales,
         el texto cambia con ellos. La única cifra escrita aquí es el 80 % de
         concentración, que el bot no manda en el payload — ver CONTEXTO §6.C. --}}
    <x-ui.card padding="p-0" class="mb-6" x-data="{ abierto: false }">
        <button type="button" @click="abierto = ! abierto"
                x-bind:aria-expanded="abierto ? 'true' : 'false'"
                class="flex w-full items-center gap-2 px-5 py-3 text-left text-sm font-medium text-slate-600
                       hover:text-shell-900">
            <svg class="size-4 shrink-0 text-slate-400 transition-transform duration-200"
                 x-bind:class="abierto && 'rotate-90'"
                 fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
            </svg>
            ¿Cuándo un hallazgo es <span class="font-semibold">firme</span> y cuándo
            <span class="font-semibold">no concluyente</span>?
        </button>

        <div x-show="abierto" x-cloak class="border-t border-slate-100 px-5 py-4 text-sm text-slate-600">
            <p>
                El bot parte el día en <strong>tandas</strong>: grupos de paquetes que pasaron seguidos por
                la cinta, separados entre sí por huecos de más de
                <strong>{{ $run->batch_gap_minutes }} minutos</strong>. La tanda con más paquetes de una
                ruta es su <strong>tanda principal</strong>, y ahí es donde debería haber pasado todo lo que
                esa ruta recogió. Se marca como incidencia el paquete que se desvía más de
                <strong>{{ $run->tolerance_minutes }} minutos</strong> de la mediana de su grupo.
            </p>

            <p class="mt-3">
                Un hallazgo es <strong>firme</strong> cuando <strong>no</strong> se da ninguna de estas dos
                situaciones. Basta una para que pase a <strong>no concluyente</strong>:
            </p>

            <ul class="mt-2 space-y-2">
                <li class="flex gap-2">
                    <span class="mt-1.5 size-1.5 shrink-0 rounded-full bg-slate-300"></span>
                    <span>
                        <strong>La ruta pasó dispersa.</strong> Menos del 80 % de sus paquetes fueron en su
                        tanda principal, así que «su tanda» significa poco y estar fuera de ella no prueba
                        nada. Las rutas a las que les pasó están arriba, en «Cómo pasó el día por la cinta».
                    </span>
                </li>
                <li class="flex gap-2">
                    <span class="mt-1.5 size-1.5 shrink-0 rounded-full bg-slate-300"></span>
                    <span>
                        <strong>La tanda estaba compartida.</strong> Dos furgonetas descargaron a la vez, así
                        que por la hora no se puede saber cuál llevó el paquete.
                    </span>
                </li>
            </ul>

            <p class="mt-3 rounded-lg bg-slate-50 px-3 py-2 text-xs">
                <strong>No concluyente no significa que no pasara nada</strong>, significa que el bot no puede
                decir quién fue. No sirve para hablar con una UT; sirve para mirar el día.
            </p>
        </div>
    </x-ui.card>

    {{-- El grueso: una sección por ruta dueña, plegable. Alpine y no Livewire
         porque las incidencias ya están todas cargadas: abrir una ruta no tiene
         por qué costar una ida al servidor. --}}
    <section class="mb-6">
        <h2 class="mb-2 text-sm font-semibold text-shell-900">Por ruta</h2>

        @if ($rutas->isEmpty())
            <x-ui.card padding="p-0">
                <x-ui.empty-state title="Ninguna incidencia en esta jornada"
                                  description="Todos los paquetes evaluados pasaron por la cinta con el grueso de su ruta." />
            </x-ui.card>
        @else
            <div class="space-y-3">
                @foreach ($rutas as $ruta)
                    <x-ui.card padding="p-0" x-data="{ abierta: false }">
                        <button type="button" @click="abierta = ! abierta"
                                x-bind:aria-expanded="abierta ? 'true' : 'false'"
                                class="flex w-full items-center gap-3 px-5 py-4 text-left">
                            <svg class="size-4 shrink-0 text-slate-400 transition-transform duration-200"
                                 x-bind:class="abierta && 'rotate-90'"
                                 fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                            </svg>

                            <div class="min-w-0 flex-1">
                                <p class="font-semibold text-shell-900">{{ $ruta['nombre'] }}</p>
                                <p class="text-sm text-slate-500">
                                    {{ $ruta['mensajero'] ?? 'Sin UT asignada' }}
                                    @if ($ruta['correctos']->isNotEmpty())
                                        {{-- La proporción es el dato: 11 sobre 94 no
                                             es lo mismo que 11 sobre 12. --}}
                                        · {{ $ruta['paquetes'] }} paquetes, {{ $ruta['total'] }} con incidencia
                                    @endif
                                </p>
                            </div>

                            <div class="flex shrink-0 items-center gap-2">
                                @if ($ruta['firmes'] > 0)
                                    <span class="rounded-full bg-brand-50 px-2.5 py-1 text-xs font-semibold text-brand-700">
                                        {{ $ruta['firmes'] }} firme{{ $ruta['firmes'] === 1 ? '' : 's' }}
                                    </span>
                                @endif
                                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600">
                                    {{ $ruta['total'] }}
                                </span>
                            </div>
                        </button>

                        <div x-show="abierta" x-cloak class="border-t border-slate-100 px-5 py-4">
                            {{-- Obligación 3: las dos clases, en tablas
                                 distintas. Una señala a alguien y la otra no. --}}
                            @foreach ([
                                ['Se fueron con otra ruta', $ruta['acusaciones'], true],
                                ['Pasaron descolgados de su tanda', $ruta['descolgados'], false],
                            ] as [$titulo, $filas, $acusa])
                                @if ($filas->isNotEmpty())
                                    <h3 class="mb-2 text-xs font-semibold tracking-wide text-slate-500 uppercase">
                                        {{ $titulo }} ({{ $filas->count() }})
                                    </h3>

                                    @unless ($acusa)
                                        <p class="mb-2 text-xs text-slate-500">
                                            Pasaron fuera de la tanda de su ruta, pero esa tanda no era de nadie
                                            en particular: no hay a quién señalar.
                                        </p>
                                    @endunless

                                    <div class="mb-5 overflow-x-auto">
                                        <table class="w-full min-w-[34rem] text-sm">
                                            <thead>
                                                <tr class="text-left text-xs text-slate-500">
                                                    <th class="pb-2 font-medium">Comercio</th>
                                                    <th class="pb-2 font-medium">Hora cinta</th>
                                                    @if ($acusa)
                                                        <th class="pb-2 font-medium">Pasó con</th>
                                                    @endif
                                                    <th class="pb-2 font-medium">Fiabilidad</th>
                                                    <th class="pb-2"><span class="sr-only">Detalle</span></th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-slate-100">
                                                @foreach ($filas as $fila)
                                                    <tr class="{{ $fila->isConclusive() ? '' : 'text-slate-500' }}">
                                                        <td class="py-2 pr-3">{{ $fila->merchant_name }}</td>
                                                        <td class="py-2 pr-3 tabular-nums">{{ $hora($fila->belt_time) }}</td>

                                                        @if ($acusa)
                                                            <td class="py-2 pr-3">{{ $fila->observed_route_name ?? '—' }}</td>
                                                        @endif

                                                        <td class="py-2 pr-3">
                                                            @if ($fila->isConclusive())
                                                                <span class="rounded-full bg-brand-50 px-2 py-0.5 text-xs font-semibold text-brand-700">
                                                                    Firme
                                                                </span>
                                                            @else
                                                                {{-- El motivo en palabras, no el código: un
                                                                     «ruta_dispersa» no le dice nada a nadie. --}}
                                                                <span class="text-xs"
                                                                      title="{{ implode('. ', \App\Support\IncidentPresenter::reasons($fila)) }}">
                                                                    No concluyente
                                                                </span>
                                                            @endif
                                                        </td>

                                                        <td class="py-2 text-right">
                                                            <x-ui.icon-button label="Ver el detalle del paquete"
                                                                              wire:click="show({{ $fila->id }})">
                                                                <svg class="size-4" fill="none" viewBox="0 0 24 24"
                                                                     stroke-width="1.7" stroke="currentColor">
                                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                                          d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                                          d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                                </svg>
                                                            </x-ui.icon-button>
                                                        </td>
                                                    </tr>

                                                    @unless ($fila->isConclusive())
                                                        @if (filled($motivos = \App\Support\IncidentPresenter::reasons($fila)))
                                                            <tr>
                                                                <td colspan="{{ $acusa ? 5 : 4 }}" class="pb-2 text-xs text-slate-400">
                                                                    {{ implode('. ', $motivos) }}.
                                                                </td>
                                                            </tr>
                                                        @endif
                                                    @endunless
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endif
                            @endforeach

                            {{-- Los que fueron donde debían. Van los últimos y
                                 plegados: son la mayoría y no requieren acción,
                                 pero sin ellos no se ve la proporción — y el
                                 informe en texto que el cliente lee sí los
                                 lista. Sólo aparecen si el bot manda la jornada
                                 completa (§3.1). --}}
                            @if ($ruta['correctos']->isNotEmpty())
                                <div x-data="{ verCorrectos: false }">
                                    <button type="button" @click="verCorrectos = ! verCorrectos"
                                            x-bind:aria-expanded="verCorrectos ? 'true' : 'false'"
                                            class="flex items-center gap-2 text-xs font-semibold tracking-wide
                                                   text-slate-500 uppercase hover:text-shell-900">
                                        <svg class="size-3.5 transition-transform duration-200"
                                             x-bind:class="verCorrectos && 'rotate-90'"
                                             fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                                        </svg>
                                        Pasaron con su ruta ({{ $ruta['correctos']->count() }})
                                    </button>

                                    <div x-show="verCorrectos" x-cloak class="mt-2 overflow-x-auto">
                                        <table class="w-full min-w-[28rem] text-sm">
                                            <thead>
                                                <tr class="text-left text-xs text-slate-500">
                                                    <th class="pb-2 font-medium">Comercio</th>
                                                    <th class="pb-2 font-medium">Hora cinta</th>
                                                    <th class="pb-2"><span class="sr-only">Detalle</span></th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-slate-100">
                                                @foreach ($ruta['correctos'] as $fila)
                                                    <tr>
                                                        <td class="py-2 pr-3">{{ $fila->merchant_name }}</td>
                                                        <td class="py-2 pr-3 tabular-nums">
                                                            {{-- Un paquete con ruta pero sin escanear: 34 el
                                                                 03/08. No es una incidencia, pero tampoco se
                                                                 pudo comprobar. --}}
                                                            {{ $fila->belt_time ? $hora($fila->belt_time) : 'sin paso por la cinta' }}
                                                        </td>
                                                        <td class="py-2 text-right">
                                                            <x-ui.icon-button label="Ver el detalle del paquete"
                                                                              wire:click="show({{ $fila->id }})">
                                                                <svg class="size-4" fill="none" viewBox="0 0 24 24"
                                                                     stroke-width="1.7" stroke="currentColor">
                                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                                          d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                                          d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                                </svg>
                                                            </x-ui.icon-button>
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </x-ui.card>
                @endforeach
            </div>
        @endif
    </section>

    {{-- El agregado que responde la pregunta del negocio de un vistazo. --}}
    @if ($traspasos->isNotEmpty())
        <section class="mb-6">
            <h2 class="mb-2 text-sm font-semibold text-shell-900">Quién recogió de quién</h2>

            <x-ui.card>
                <ul class="space-y-2 text-sm">
                    @foreach ($traspasos as $traspaso)
                        <li class="flex flex-wrap items-baseline gap-x-2">
                            <span class="font-medium text-shell-900">{{ $traspaso['de'] }}</span>
                            <span class="text-slate-400">→</span>
                            <span class="font-medium text-shell-900">{{ $traspaso['a'] }}</span>
                            <span class="ml-auto tabular-nums text-slate-600">
                                {{ $traspaso['total'] }}
                                @if ($traspaso['firmes'] > 0)
                                    <span class="ml-1 rounded-full bg-brand-50 px-2 py-0.5 text-xs font-semibold text-brand-700">
                                        {{ $traspaso['firmes'] }} firme{{ $traspaso['firmes'] === 1 ? '' : 's' }}
                                    </span>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        </section>
    @endif

    {{-- El detalle de un paquete: lo que hace falta para ir a contrastarlo a
         GLS Atlas. --}}
    @if ($detalle)
        <x-ui.modal title="{{ $detalle->merchant_name }}"
                    description="Expedición {{ $detalle->shipment_id }}">
            <div class="px-6 py-4">
                <dl class="grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2">
                    @foreach (array_filter([
                        ['Código de barras', $detalle->barcode ?? '—'],
                        ['Hora de cinta (UTC)', $detalle->belt_time ? $hora($detalle->belt_time) : 'Sin paso por la cinta'],
                        ['Ruta del comercio', $detalle->assigned_route_name ?? '—'],
                        ['UT aquel día', $detalle->assigned_courier_name ?? '—'],

                        // Los dos últimos sólo tienen sentido en una incidencia:
                        // en un paquete correcto serían dos huecos que invitan a
                        // preguntarse qué falta.
                        $detalle->isIncident()
                            ? ['Pasó en la tanda de', $detalle->observed_route_name ?? 'Ninguna en concreto']
                            : null,
                        $detalle->isIncident()
                            ? ['Desvío sobre su ruta', \App\Support\IncidentPresenter::deviation($detalle->deviation_minutes)]
                            : null,
                    ]) as [$label, $valor])
                        <div>
                            <dt class="text-xs font-medium text-slate-500">{{ $label }}</dt>
                            <dd class="mt-0.5 text-sm text-shell-900">{{ $valor }}</dd>
                        </div>
                    @endforeach
                </dl>

                <div class="mt-4 border-t border-slate-100 pt-3">
                    {{-- Un paquete sin incidencia no es «no concluyente»: no hay
                         nada que concluir. Decirlo de otra forma sería sembrar
                         una duda que el bot no tiene. --}}
                    @if (! $detalle->isIncident())
                        <p class="text-sm text-shell-900">
                            Pasó por la cinta con el grueso de su ruta. Sin incidencia.
                        </p>
                    @else
                        <p class="text-xs font-medium text-slate-500">
                            {{ \App\Support\IncidentPresenter::type($detalle->type) }}
                        </p>

                        @if ($detalle->isConclusive())
                            <p class="mt-1 text-sm text-shell-900">
                                El bot sostiene este hallazgo sin reservas.
                            </p>
                        @else
                            <p class="mt-1 text-sm text-slate-600">
                                El bot <strong>no</strong> puede afirmar quién recogió este paquete:
                                {{ implode('; ', \App\Support\IncidentPresenter::reasons($detalle)) }}.
                            </p>
                        @endif

                        @if (filled($detalle->compatible_routes))
                            <p class="mt-2 text-xs text-slate-500">
                                Compartían esa tanda:
                                {{ collect($detalle->compatible_routes)->pluck('nombre')->filter()->implode(', ') }}.
                            </p>
                        @endif
                    @endif
                </div>
            </div>

            <div class="flex justify-end border-t border-slate-200 px-6 py-4">
                <x-ui.button type="button" wire:click="cancel">Cerrar</x-ui.button>
            </div>
        </x-ui.modal>
    @endif
</div>
