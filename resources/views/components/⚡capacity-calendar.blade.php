<?php

use App\Models\Courier;
use App\Models\IncidentRun;
use App\Models\Setting;
use App\Models\RunPackage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Calendario de capacidades: qué volumen movió cada UT cada día de una semana
 * (CONTEXTO.md §7, fase 6.D).
 *
 * **El volumen es el que le tocaba a su ruta, no el que llevó en la furgoneta.**
 * Sale de `run_packages.volume_m3` agrupado por `assigned_courier_name`, es
 * decir, por quien conducía la ruta del comercio **aquel día** (§3.1). Un
 * paquete que acabó en otra tanda sigue contando aquí para su ruta: esto mide
 * la carga que se le planifica a una UT, y las desviaciones son justo lo que
 * mira la pantalla de incidencias.
 *
 * **Se agrupa por el nombre copiado y no por la FK de la ruta** porque la
 * pregunta es por persona: si en marzo Freddy llevaba la 3 y en abril la 5, su
 * fila tiene que seguir siendo la suya. Ir por `assigned_route_id` hasta el
 * conductor de hoy reescribiría el pasado, que es lo que §4 prohíbe.
 *
 * De ahí que haya filas que no están en el maestro: quien se fue de la empresa
 * movió volumen esa semana y esconderlo dejaría una suma que no cuadra.
 *
 * **Toda suma dice sobre cuántos envíos se hizo.** El portal no trae el volumen
 * de una parte de los envíos y ahí un cero significa «no lo sé» (§3): sin el
 * denominador delante, un día mal cubierto se lee como un día flojo.
 */
new #[Layout('components.layouts.app')] class extends Component
{
    /** Lunes de la semana que se mira. '' es la semana en curso. */
    #[Url(as: 'semana', except: '')]
    public string $week = '';

    /**
     * El lunes de la semana elegida.
     *
     * Explícito `Carbon::MONDAY` y no el del locale: la semana del cliente
     * empieza en lunes, y que eso dependa de una configuración es una forma
     * tonta de que un día se mueva de columna.
     *
     * Una fecha imposible llega por la URL, no por el `<input type="date">`:
     * sin el try, `?semana=lo-que-sea` sería un 500.
     */
    private function monday(): Carbon
    {
        if ($this->week !== '') {
            try {
                return Carbon::createFromFormat('Y-m-d', $this->week)->startOfWeek(Carbon::MONDAY);
            } catch (\Throwable) {
                // Se cae a la semana en curso, abajo.
            }
        }

        return Carbon::today()->startOfWeek(Carbon::MONDAY);
    }

    public function shift(int $weeks): void
    {
        $this->week = $this->monday()->addWeeks($weeks)->toDateString();
    }

    public function thisWeek(): void
    {
        $this->week = '';
    }

    /** El filtro es semanal: se elija el día que se elija, manda su semana. */
    public function updatedWeek(): void
    {
        if ($this->week !== '') {
            $this->week = $this->monday()->toDateString();
        }
    }

    /**
     * Una fila de la tabla: los siete días y la cobertura.
     *
     * Cada día trae, además del volumen, qué parte de la furgoneta ocupó: el
     * volumen sumado de ese día entre la capacidad declarada de la UT. Un 1,20
     * es un día que no cabía. Sin capacidad en el maestro no hay con qué
     * comparar, así que la ocupación queda a null y la vista pone un guion.
     *
     * @param  Collection<int, object>  $celdas  Las agregadas de esta UT, una por día.
     * @param  Collection<int, Carbon>  $dias
     */
    private function row(string $label, ?float $capacity, ?string $note, Collection $celdas, Collection $dias): array
    {
        $porDia = $celdas->keyBy('dia');

        return [
            'label' => $label,
            'capacity' => $capacity,
            'note' => $note,
            'days' => $dias->mapWithKeys(function (Carbon $dia) use ($porDia, $capacity) {
                $clave = $dia->toDateString();
                $celda = $porDia[$clave] ?? null;

                if ($celda === null) {
                    return [$clave => null];
                }

                $volumen = $celda->volumen === null ? null : (float) $celda->volumen;

                return [$clave => [
                    'volume' => $volumen,
                    // El `> 0` no es paranoia de división por cero: una furgoneta
                    // declarada con capacidad cero es un dato mal metido, y
                    // dividir por él daría un infinito en pantalla.
                    'usage' => $volumen === null || $capacity === null || $capacity <= 0
                        ? null
                        : $volumen / $capacity,
                    'shipments' => (int) $celda->envios,
                    'measured' => (int) $celda->con_volumen,
                ]];
            })->all(),
            'shipments' => $celdas->sum(fn ($c) => (int) $c->envios),
            'measured' => $celdas->sum(fn ($c) => (int) $c->con_volumen),
        ];
    }

    /** @return array<string, mixed> */
    public function with(): array
    {
        $lunes = $this->monday();
        $domingo = $lunes->copy()->addDays(6);
        $dias = collect(range(0, 6))->map(fn (int $i) => $lunes->copy()->addDays($i));

        // Las corridas del tramo, para poder distinguir «ese día no movió nada»
        // de «ese día el bot no corrió», que no es lo mismo en absoluto.
        $corridas = IncidentRun::query()
            ->whereBetween('run_date', [$lunes->toDateString(), $domingo->toDateString()])
            ->get()
            ->keyBy(fn (IncidentRun $run) => $run->run_date->toDateString());

        // Toda la tabla en una consulta: agrupar en SQL en vez de traerse los
        // paquetes de la semana —miles— para sumarlos en PHP.
        //
        // `count(volume_m3)` no cuenta los nulos, que es exactamente la
        // cobertura que hay que enseñar junto a la suma.
        $celdas = RunPackage::query()
            ->join('incident_runs', 'incident_runs.id', '=', 'run_packages.incident_run_id')
            ->whereNull('run_packages.withdrawn_at')
            ->whereBetween('incident_runs.run_date', [$lunes->toDateString(), $domingo->toDateString()])
            ->groupBy('incident_runs.run_date', 'run_packages.assigned_courier_name')
            ->selectRaw('incident_runs.run_date::text as dia')
            ->selectRaw('run_packages.assigned_courier_name as ut')
            ->selectRaw('sum(run_packages.volume_m3) as volumen')
            ->selectRaw('count(*) as envios')
            ->selectRaw('count(run_packages.volume_m3) as con_volumen')
            ->get()
            ->groupBy(fn ($celda) => $celda->ut ?? '');

        $maestro = Courier::orderBy('name')->get();
        $vacias = collect();

        $filas = $maestro->map(fn (Courier $ut) => $this->row(
            $ut->name,
            $ut->maximum_volume,
            null,
            $celdas[$ut->name] ?? $vacias,
            $dias,
        ));

        // Las que movieron volumen esa semana y ya no están en el maestro: se
        // dieron de baja, o cambiaron de nombre. Van al final y marcadas, pero
        // van: si no, la suma de la semana no cuadraría con la de incidencias.
        $huerfanas = $celdas->keys()
            ->reject(fn (string $nombre) => $nombre === '' || $maestro->contains('name', $nombre))
            ->sort()
            ->map(fn (string $nombre) => $this->row(
                $nombre,
                null,
                'Ya no está en el maestro',
                $celdas[$nombre],
                $dias,
            ));

        // Y el volumen de las rutas que aquel día no llevaba nadie, por el mismo
        // motivo: es volumen que existió y tiene que verse en alguna fila.
        $sinUt = isset($celdas[''])
            ? [$this->row('Sin UT asignada', null, 'Rutas que aquel día no llevaba nadie', $celdas[''], $dias)]
            : [];

        return [
            // Los parámetros que esta pantalla necesita y nadie ha puesto
            // todavía (§7, fase 11). No hay valores por defecto a propósito:
            // inventarle un umbral al cliente cambiaría cómo se lee la tabla sin
            // que él lo haya elegido, así que se pide y punto.
            'faltanAjustes' => Setting::missing('capacity-calendar'),

            'lunes' => $lunes,
            'domingo' => $domingo,
            'dias' => $dias,
            'corridas' => $corridas,
            'rows' => $filas->concat($huerfanas)->concat($sinUt)->values(),
            'esLaSemanaEnCurso' => $lunes->isSameDay(Carbon::today()->startOfWeek(Carbon::MONDAY)),
            'hayMaestro' => $maestro->isNotEmpty(),
        ];
    }
}; ?>

@php
    // Un volumen para leer de un vistazo. Dos decimales y no tres: la precisión
    // del portal es por envío, y en una suma del día el milímetro cúbico es
    // ruido en una columna que se lee en diagonal.
    $vol = fn (?float $v) => $v === null ? '—' : number_format($v, 2, ',', '.');

    // La ocupación en porcentaje y sin decimales: lo que se busca de un vistazo
    // es si el día pasó del 100 %, no si fue un 31,4 % o un 31,7 %.
    $ocupacion = fn (float $u) => number_format($u * 100, 0, ',', '.').' %';
@endphp

<div>
    <x-ui.page-header title="Calendario de capacidades"
                      description="Volumen en m³ que movió cada UT, día a día. Sale de las corridas del bot, no del maestro.">
        <x-slot:actions>
            @unless ($esLaSemanaEnCurso)
                <x-ui.button variant="secondary" wire:click="thisWeek">Esta semana</x-ui.button>
            @endunless
        </x-slot:actions>
    </x-ui.page-header>

    @if ($faltanAjustes)
        <x-ui.alert type="warning" class="mb-4">
            <span>
                <strong>Esta pantalla está sin configurar.</strong>
                Falta{{ count($faltanAjustes) === 1 ? '' : 'n' }} por definir
                {{ collect($faltanAjustes)->map(fn ($n) => mb_strtolower($n))->join(', ', ' y ') }}.
                <a href="{{ route('settings', ['module' => 'capacity-calendar']) }}" wire:navigate
                   class="font-semibold underline underline-offset-2">Configurarla ahora</a>.
            </span>
        </x-ui.alert>
    @endif

    <x-ui.card padding="p-0">
        {{-- El selector de semana. Las flechas son lo que se usa a diario
             —«la semana pasada»— y el calendario, para saltar lejos. --}}
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-6 py-3">
            <div class="flex items-center gap-2">
                <x-ui.icon-button label="Semana anterior" wire:click="shift(-1)" wire:loading.attr="disabled">
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />
                    </svg>
                </x-ui.icon-button>

                <p class="text-sm font-medium text-shell-900">
                    {{ $lunes->translatedFormat('j') }}
                    @unless ($lunes->isSameMonth($domingo))
                        {{ $lunes->translatedFormat('\d\e F') }}
                    @endunless
                    – {{ $domingo->translatedFormat('j \d\e F \d\e Y') }}
                </p>

                <x-ui.icon-button label="Semana siguiente" wire:click="shift(1)" wire:loading.attr="disabled">
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                    </svg>
                </x-ui.icon-button>
            </div>

            <div class="flex items-center gap-2">
                <label for="semana" class="text-sm whitespace-nowrap text-slate-500">Ir a la semana del</label>
                {{-- Vale cualquier día: al elegirlo se salta al lunes de su
                     semana, porque lo que se mira aquí son semanas enteras. --}}
                <input id="semana" type="date" wire:model.live="week"
                       class="rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
            </div>
        </div>

        @if (! $hayMaestro)
            <x-ui.empty-state title="Todavía no hay UT"
                              description="Da de alta la primera en el maestro y aquí saldrá su semana." />
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-4xl text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-left text-xs tracking-wider text-slate-500 uppercase">
                            <th class="px-6 py-3 font-semibold">UT</th>
                            <th class="px-4 py-3 text-right font-semibold">Capacidad</th>

                            @foreach ($dias as $dia)
                                @php $corrida = $corridas[$dia->toDateString()] ?? null; @endphp

                                <th @class(['px-4 py-3 text-right font-semibold', 'pr-6' => $loop->last])>
                                    {{ ucfirst($dia->translatedFormat('l')) }}
                                    <span class="block font-normal normal-case">{{ $dia->format('d/m') }}</span>

                                    {{-- Un día sin corrida no es un día sin trabajo, y una
                                         corrida no fiable no cubre la jornada entera (§3.1):
                                         las dos cosas cambian cómo se lee la columna. --}}
                                    @if ($corrida === null)
                                        <span class="block font-normal text-slate-400 normal-case">sin corrida</span>
                                    @elseif (! $corrida->reliable)
                                        <span class="block font-normal text-amber-600 normal-case">no fiable</span>
                                    @endif
                                </th>
                            @endforeach
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-slate-100">
                        @foreach ($rows as $row)
                            <tr wire:key="ut-{{ $loop->index }}" class="hover:bg-slate-50/75">
                                <td class="px-6 py-3">
                                    <span class="font-medium text-shell-900">{{ $row['label'] }}</span>

                                    @if ($row['note'])
                                        <span class="ml-2 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500">
                                            {{ $row['note'] }}
                                        </span>
                                    @endif
                                </td>

                                <td class="px-4 py-3 text-right tabular-nums">
                                    @if ($row['capacity'] === null)
                                        <span class="text-slate-300">—</span>
                                    @else
                                        {{ $vol($row['capacity']) }}
                                    @endif
                                </td>

                                @foreach ($dias as $dia)
                                    @php $celda = $row['days'][$dia->toDateString()]; @endphp

                                    <td @class(['px-4 py-3 text-right tabular-nums', 'pr-6' => $loop->last])>
                                        @if ($celda === null)
                                            <span class="text-slate-300">—</span>
                                        @else
                                            {{-- Manda lo que ocupó de la furgoneta, que es la
                                                 lectura útil: 4 m³ es mucho o poco según quién
                                                 los lleve. En rojo lo que no le cabe, que es para
                                                 lo que se mira esta pantalla. Sin capacidad
                                                 declarada no hay entre qué dividir. --}}
                                            @php
                                                $pasada = $celda['usage'] !== null && $celda['usage'] > 1;

                                                // El día del que el portal no dio todos los
                                                // volúmenes ocupó más de lo que dice la cifra. No
                                                // se marca en la celda —la tabla se lee en
                                                // diagonal y el aviso repetido la ensuciaba—, pero
                                                // el recuento queda en el tooltip: §3 no permite
                                                // dar la suma por completa sin más.
                                                $incompleta = $celda['measured'] < $celda['shipments'];

                                                $aviso = match (true) {
                                                    $pasada && $incompleta => 'Se pasa de la capacidad declarada, y aún así el portal sólo dio el volumen de '.$celda['measured'].' de '.$celda['shipments'].' envíos',
                                                    $pasada => 'Se pasa de la capacidad declarada',
                                                    $incompleta => 'El portal sólo dio el volumen de '.$celda['measured'].' de '.$celda['shipments'].' envíos: ocupó más de lo que dice la cifra',
                                                    default => null,
                                                };
                                            @endphp

                                            @if ($celda['usage'] === null)
                                                <span class="font-semibold text-slate-300"
                                                      title="Esta UT no tiene capacidad declarada">—</span>
                                            @else
                                                <span @class([
                                                    'font-semibold',
                                                    'text-rose-600' => $pasada,
                                                    'text-shell-900' => ! $pasada,
                                                ]) @if ($aviso) title="{{ $aviso }}" @endif>
                                                    {{ $ocupacion($celda['usage']) }}
                                                </span>
                                            @endif

                                            {{-- El volumen del que sale, en pequeño: el dato bruto
                                                 sigue haciendo falta para cuadrar con incidencias. --}}
                                            <span class="ml-1 text-xs text-slate-400"
                                                  @if ($aviso) title="{{ $aviso }}" @endif>{{ $vol($celda['volume']) }}</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="border-t border-slate-200 px-6 py-3 text-xs text-slate-500">
                El volumen de un día es el de los paquetes que el maestro asignaba a la ruta de esa UT,
                estuviera donde estuviera al final el paquete. <strong>El porcentaje es ese volumen entre
                la capacidad declarada de su furgoneta</strong>, así que sale un guion en las UT que no la
                tienen puesta en el maestro.
                Las cifras en rojo son los días que se pasan de la capacidad declarada de la furgoneta.
                Cuando el portal no da el volumen de todos los envíos ese hueco no se suma como cero
                —significaría «no lo sé»—, así que la ocupación real puede ser mayor que la que se ve:
                pasa el ratón por encima de la cifra para saber cuántos envíos la respaldan.
            </div>
        @endif
    </x-ui.card>
</div>
