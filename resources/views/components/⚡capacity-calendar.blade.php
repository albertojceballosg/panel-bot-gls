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
     * La celda cuyo desglose está abierto: la UT y el día.
     *
     * Se guarda el nombre copiado —el mismo con el que se agrupa la tabla— y no
     * un id: la fila puede ser de alguien que ya no está en el maestro, y '' es
     * la de «Sin UT asignada». Las dos a null es el diálogo cerrado.
     */
    public ?string $detailCourier = null;

    public ?string $detailDay = null;

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

    /** Abre el desglose de una celda. `''` es la fila «Sin UT asignada». */
    public function openDetail(?string $courier, string $day): void
    {
        $this->detailCourier = $courier ?? '';
        $this->detailDay = $day;
    }

    public function closeDetail(): void
    {
        $this->detailCourier = null;
        $this->detailDay = null;
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
     * @param  array{minimum: ?int, optimal: ?int}  $umbrales  Los de §7, fase 11.
     */
    private function row(string $key, string $label, ?float $capacity, ?string $note, Collection $celdas, Collection $dias, array $umbrales): array
    {
        $porDia = $celdas->keyBy('dia');

        return [
            // Con qué se pide el desglose de una celda: el nombre copiado con el
            // que se agrupa, que no siempre es la etiqueta (la fila sin UT se
            // enseña como «Sin UT asignada» y por dentro es '').
            'key' => $key,
            'label' => $label,
            'capacity' => $capacity,
            'note' => $note,
            'days' => $dias->mapWithKeys(function (Carbon $dia) use ($porDia, $capacity, $umbrales) {
                $clave = $dia->toDateString();
                $celda = $porDia[$clave] ?? null;

                if ($celda === null) {
                    return [$clave => null];
                }

                $volumen = $celda->volumen === null ? null : (float) $celda->volumen;

                // El `> 0` no es paranoia de división por cero: una furgoneta
                // declarada con capacidad cero es un dato mal metido, y
                // dividir por él daría un infinito en pantalla.
                $ocupacion = $volumen === null || $capacity === null || $capacity <= 0
                    ? null
                    : $volumen / $capacity;

                // Qué parte de ese volumen es de paquetes que pasaron con su
                // propia ruta y qué parte acabó fuera de ella. Van en tanto por
                // uno del día —suman 1— porque es lo que se lee al lado del
                // porcentaje: «de este 11 %, cuánto es suyo».
                // Un lado sin volumen medido cuenta como cero y no como «no lo
                // sé»: aquí se reparte lo que *sí* se sabe, y el hueco de los
                // envíos sin volumen lo cuenta el diálogo. Sin total no hay
                // reparto posible y los dos quedan a null.
                $parte = fn (?float $v) => $volumen === null || $volumen <= 0
                    ? null
                    : ($v ?? 0) / $volumen;

                return [$clave => [
                    'volume' => $volumen,
                    'usage' => $ocupacion,
                    'band' => $this->band($ocupacion, $umbrales),
                    'own' => $parte($celda->propio === null ? null : (float) $celda->propio),
                    'foreign' => $parte($celda->ajeno === null ? null : (float) $celda->ajeno),

                    // Cuántos paquetes acabaron fuera de su ruta: es lo que
                    // decide si la celda enseña el enlace a las incidencias
                    // como un aviso o como un atajo más.
                    'incidents' => (int) $celda->incidencias,

                    'shipments' => (int) $celda->envios,
                    'measured' => (int) $celda->con_volumen,
                ]];
            })->all(),
            'shipments' => $celdas->sum(fn ($c) => (int) $c->envios),
            'measured' => $celdas->sum(fn ($c) => (int) $c->con_volumen),
        ];
    }

    /**
     * En cuál de los tres tramos configurados cae un día: malo, justo o bueno
     * (§7, fase 11).
     *
     * **Se compara sobre el porcentaje redondeado**, el mismo que se pinta: con
     * el crudo, un 79,6 % que la tabla enseña como «80 %» se quedaría fuera del
     * tramo bueno por una décima que no se ve, y el color parecería un error.
     *
     * Sin umbrales configurados no hay tramo —`null`— y la cifra sale sin color.
     * Inventarlos cambiaría cómo se lee la tabla sin que nadie lo haya elegido,
     * que es justo lo que §7 fase 11 decidió no hacer; para eso está el aviso de
     * arriba, que enlaza a su configuración.
     *
     * @param  array{minimum: ?int, optimal: ?int}  $umbrales
     */
    private function band(?float $usage, array $umbrales): ?string
    {
        if ($usage === null || $umbrales['minimum'] === null || $umbrales['optimal'] === null) {
            return null;
        }

        $porcentaje = round($usage * 100);

        return match (true) {
            $porcentaje < $umbrales['minimum'] => 'bad',
            $porcentaje >= $umbrales['optimal'] => 'good',
            default => 'warning',
        };
    }

    /**
     * De dónde sale la ocupación de una celda: cuánto de ese volumen es de
     * paquetes que pasaron con su propia ruta y cuánto de paquetes que
     * acabaron fuera de ella.
     *
     * **Reparte el mismo volumen que enseña la celda, no otro.** Los filtros
     * son los del agregado de arriba —jornada, UT y no retirados—, así que las
     * dos partes suman exactamente el porcentaje del que se abrió el diálogo;
     * si aquí se colara otra condición, el desglose contaría una historia que
     * la tabla no cuenta.
     *
     * `type` nulo es «pasó en la tanda de su ruta» (§3.1). Lo demás
     * —`tanda_de_otra_ruta` y `fuera_de_tanda`— es volumen que le tocaba a esta
     * ruta y no se recogió en ella, que es justo lo que se viene a mirar.
     *
     * La consulta sólo se hace con el diálogo abierto: la tabla se sigue
     * armando con las cuatro de siempre.
     *
     * @param  Collection<int, Courier>  $maestro
     * @return array<string, mixed>|null
     */
    private function detail(Collection $maestro): ?array
    {
        if ($this->detailCourier === null || $this->detailDay === null) {
            return null;
        }

        // Las dos llegan por la red: un día imposible no puede ser un 500.
        try {
            $dia = Carbon::createFromFormat('Y-m-d', $this->detailDay)->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        $ut = $this->detailCourier;

        // Un `case` y no `type is null` a secas: así la clave llega ya como la
        // etiqueta con la que se agrupa en PHP, sin depender de cómo devuelva
        // el driver un booleano.
        $reparto = "case when run_packages.type is null then 'own' else 'foreign' end";

        $partes = RunPackage::query()
            ->join('incident_runs', 'incident_runs.id', '=', 'run_packages.incident_run_id')
            ->whereNull('run_packages.withdrawn_at')
            ->where('incident_runs.run_date', $dia->toDateString())
            ->when(
                $ut === '',
                fn ($q) => $q->whereNull('run_packages.assigned_courier_name'),
                fn ($q) => $q->where('run_packages.assigned_courier_name', $ut),
            )
            ->groupByRaw($reparto)
            ->selectRaw($reparto.' as parte')
            ->selectRaw('sum(run_packages.volume_m3) as volumen')
            ->selectRaw('count(*) as envios')
            ->selectRaw('count(run_packages.volume_m3) as con_volumen')

            // Lo facturado sin IVA por esos mismos paquetes, con su cobertura al lado
            // (§7, fase 13.C): `count` de la columna no cuenta los nulos, y un envío que
            // no está en Envexpress es «no se sabe», no cero.
            ->selectRaw('sum(run_packages.net_revenue) as ganancia')
            ->selectRaw('count(run_packages.net_revenue) as con_ganancia')
            ->get()
            ->keyBy('parte');

        // Ese día esa UT no movió nada: no hay celda de la que hablar.
        if ($partes->isEmpty()) {
            return null;
        }

        $capacidad = $maestro->firstWhere('name', $ut)?->maximum_volume;
        $capacidad = $capacidad === null ? null : (float) $capacidad;

        // Igual que en la celda: si el portal no dio ni un volumen, el total es
        // «no lo sé» y no un cero (§3).
        $medidas = $partes->filter(fn ($p) => $p->volumen !== null);
        $total = $medidas->isEmpty() ? null : (float) $medidas->sum(fn ($p) => (float) $p->volumen);

        // Mismo trato que el volumen, por el mismo motivo: si ni un envío trae valoración
        // de Envexpress, el importe es «no se sabe» y no 0,00 € (§3.1).
        $facturado = $partes->filter(fn ($p) => $p->ganancia !== null);
        $ganancia = $facturado->isEmpty() ? null : (float) $facturado->sum(fn ($p) => (float) $p->ganancia);

        $parte = function (string $clave, string $label, string $help) use ($partes, $total, $capacidad) {
            $fila = $partes[$clave] ?? null;
            $volumen = $fila === null || $fila->volumen === null ? null : (float) $fila->volumen;

            return [
                'label' => $label,
                'help' => $help,

                // Cuánto dinero hay en esta parte del reparto. En «Fuera de su ruta» es la
                // pregunta de negocio entera: cuánto se fue con otra furgoneta.
                'revenue' => $fila === null || $fila->ganancia === null ? null : (float) $fila->ganancia,
                'priced' => $fila === null ? 0 : (int) $fila->con_ganancia,


                // Qué parte del volumen del día es. Es el reparto que se viene a
                // ver, y por eso manda en el diálogo: los dos suman 100 %.
                'share' => $volumen === null || $total === null || $total <= 0 ? null : $volumen / $total,

                // Y con cuánto de la furgoneta carga cada parte, que es lo que
                // suma el porcentaje de la tabla.
                'usage' => $volumen === null || $capacidad === null || $capacidad <= 0 ? null : $volumen / $capacidad,

                'volume' => $volumen,
                'shipments' => $fila === null ? 0 : (int) $fila->envios,
                'measured' => $fila === null ? 0 : (int) $fila->con_volumen,
            ];
        };

        return [
            'label' => $ut === '' ? 'Sin UT asignada' : $ut,
            'day' => $dia,

            // De aquí se sale a la jornada de ese día con las rutas de esta UT
            // abiertas y resaltadas: el desglose dice cuánto volumen se recogió
            // fuera de su ruta, y la pregunta siguiente es siempre cuál.
            // `sin-ut` es el centinela de `⚡incident-run` para la fila de las
            // rutas que aquel día no llevaba nadie; hay un test que recorre el
            // enlace entero para que las dos pantallas no se separen.
            'incidents' => route('incident-run', [
                'date' => $dia->toDateString(),
                'ut' => $ut === '' ? 'sin-ut' : $ut,
            ]),

            'capacity' => $capacidad,
            'volume' => $total,
            'usage' => $total === null || $capacidad === null || $capacidad <= 0 ? null : $total / $capacidad,
            'shipments' => $partes->sum(fn ($p) => (int) $p->envios),
            'measured' => $partes->sum(fn ($p) => (int) $p->con_volumen),

            // Lo que dejaron las rutas de esta UT ese día, y sobre cuántos envíos se sumó.
            // **No es la ganancia del día de la agencia**: aquí sólo están los envíos de
            // esta UT, y ni siquiera todos los que tienen ruta (§7, fase 13.C, regla 2).
            'revenue' => $ganancia,
            'priced' => $partes->sum(fn ($p) => (int) $p->con_ganancia),
            'parts' => [
                $parte('own', 'De su ruta', 'Pasaron en la tanda de su propia ruta'),
                $parte('foreign', 'Fuera de su ruta', 'Acabaron en la tanda de otra ruta o descolgados'),
            ],
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
        //
        // Se agrupa además por el reparto —lo que pasó con su ruta y lo que
        // acabó fuera de ella— porque desde el 18/08/2026 eso va en la celda y
        // no sólo en el diálogo. Son dos filas por UT y día en vez de una, y la
        // tabla se sigue armando con la misma consulta.
        $reparto = "case when run_packages.type is null then 'own' else 'foreign' end";

        $celdas = RunPackage::query()
            ->join('incident_runs', 'incident_runs.id', '=', 'run_packages.incident_run_id')
            ->whereNull('run_packages.withdrawn_at')
            ->whereBetween('incident_runs.run_date', [$lunes->toDateString(), $domingo->toDateString()])
            ->groupBy('incident_runs.run_date', 'run_packages.assigned_courier_name')
            ->groupByRaw($reparto)
            ->selectRaw('incident_runs.run_date::text as dia')
            ->selectRaw('run_packages.assigned_courier_name as ut')
            ->selectRaw($reparto.' as parte')
            ->selectRaw('sum(run_packages.volume_m3) as volumen')
            ->selectRaw('count(*) as envios')
            ->selectRaw('count(run_packages.volume_m3) as con_volumen')
            ->get()
            // Las dos mitades de una celda se pliegan aquí en una sola fila: el
            // SQL las trae separadas para poder repartir, pero la tabla enseña
            // un día por columna.
            ->groupBy(fn ($fila) => ($fila->ut ?? '').'|'.$fila->dia)
            ->map(function (Collection $mitades) {
                $suma = fn (Collection $filas) => $filas->whereNotNull('volumen')->isEmpty()
                    ? null
                    : (float) $filas->sum(fn ($fila) => (float) $fila->volumen);

                return (object) [
                    'dia' => $mitades->first()->dia,
                    'ut' => $mitades->first()->ut,
                    'volumen' => $suma($mitades),
                    'propio' => $suma($mitades->where('parte', 'own')),
                    'ajeno' => $suma($mitades->where('parte', 'foreign')),
                    'envios' => $mitades->sum(fn ($fila) => (int) $fila->envios),
                    'con_volumen' => $mitades->sum(fn ($fila) => (int) $fila->con_volumen),
                    'incidencias' => $mitades->where('parte', 'foreign')->sum(fn ($fila) => (int) $fila->envios),
                ];
            })
            ->values()
            ->groupBy(fn ($celda) => $celda->ut ?? '');

        $maestro = Courier::orderBy('name')->get();
        $vacias = collect();

        // Los parámetros de esta pantalla (§7, fase 11), en una sola consulta:
        // de aquí salen tanto los tramos como el aviso de lo que falta por
        // poner. `Setting::missing()` haría la misma consulta otra vez.
        $ajustes = Setting::for('capacity-calendar');

        $umbrales = [
            'minimum' => $ajustes['minimum_percent'] === '' ? null : (int) $ajustes['minimum_percent'],
            'optimal' => $ajustes['optimal_percent'] === '' ? null : (int) $ajustes['optimal_percent'],
        ];

        $filas = $maestro->map(fn (Courier $ut) => $this->row(
            $ut->name,
            $ut->name,
            $ut->maximum_volume,
            null,
            $celdas[$ut->name] ?? $vacias,
            $dias,
            $umbrales,
        ));

        // Las que movieron volumen esa semana y ya no están en el maestro: se
        // dieron de baja, o cambiaron de nombre. Van al final y marcadas, pero
        // van: si no, la suma de la semana no cuadraría con la de incidencias.
        $huerfanas = $celdas->keys()
            ->reject(fn (string $nombre) => $nombre === '' || $maestro->contains('name', $nombre))
            ->sort()
            ->map(fn (string $nombre) => $this->row(
                $nombre,
                $nombre,
                null,
                'Ya no está en el maestro',
                $celdas[$nombre],
                $dias,
                $umbrales,
            ));

        // Y el volumen de las rutas que aquel día no llevaba nadie, por el mismo
        // motivo: es volumen que existió y tiene que verse en alguna fila.
        $sinUt = isset($celdas[''])
            ? [$this->row('', 'Sin UT asignada', null, 'Rutas que aquel día no llevaba nadie', $celdas[''], $dias, $umbrales)]
            : [];

        return [
            // Los parámetros que esta pantalla necesita y nadie ha puesto
            // todavía (§7, fase 11). No hay valores por defecto a propósito:
            // inventarle un umbral al cliente cambiaría cómo se lee la tabla sin
            // que él lo haya elegido, así que se pide y punto.
            'faltanAjustes' => Setting::missingIn('capacity-calendar', $ajustes),

            // El color de cada tramo, ya filtrado: lo que no sea un `#rrggbb`
            // no llega a la vista, porque de ahí sale un atributo `style`. El
            // formulario lo valida igual, pero una fila escrita a mano en la
            // base no pasa por él.
            'colores' => collect(['bad' => 'bad_color', 'warning' => 'warning_color', 'good' => 'good_color'])
                ->map(fn (string $key) => $ajustes[$key] ?? '')
                ->filter(fn (string $color) => (bool) preg_match('/^#[0-9a-fA-F]{6}$/', $color))
                ->all(),

            'lunes' => $lunes,
            'domingo' => $domingo,
            'dias' => $dias,
            'corridas' => $corridas,
            'rows' => $filas->concat($huerfanas)->concat($sinUt)->values(),

            // El desglose de la celda abierta, o null si no hay ninguna: sólo
            // entonces se hace su consulta.
            'detalle' => $this->detail($maestro),
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

    // Euros con sus dos decimales, y «—» cuando no se sabe: el envío no está en
    // Envexpress y un 0,00 € diría que no dejó nada (§3.1). Mismo signo que usa el
    // detalle de la jornada, para que las dos pantallas se lean igual.
    $euros = fn (?float $i) => $i === null ? '—' : number_format($i, 2, ',', '.').' €';
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
                <table class="w-full min-w-6xl text-sm">
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
                                                 lectura útil: 4 m³ es mucho o poco según quién los
                                                 lleve. Al lado, entre paréntesis, de dónde sale ese
                                                 porcentaje: cuánto pasó con su propia ruta y cuánto
                                                 acabó fuera. El color del principal es el de su
                                                 tramo, de Configuraciones. --}}
                                            @php
                                                $pasada = $celda['usage'] !== null && $celda['usage'] > 1;

                                                // Null mientras la pantalla esté sin configurar, y
                                                // entonces la cifra sale en el color de siempre.
                                                $color = $colores[$celda['band']] ?? null;

                                                // El neto en m³ dejó de pintarse el 18/08/2026 a
                                                // petición; sigue en el diálogo, que es donde se
                                                // va a cuadrar con incidencias.
                                                $enlace = route('incident-run', [
                                                    'date' => $dia->toDateString(),
                                                    'ut' => $row['key'] === '' ? 'sin-ut' : $row['key'],
                                                ]);
                                            @endphp

                                            {{-- Dos líneas y no una: el reparto entre paréntesis
                                                 es casi tan ancho como la cifra, y en fila con ella
                                                 obligaba a la columna a partirlo por donde cayera.
                                                 Debajo, cada cosa se lee entera. --}}
                                            <div class="flex flex-col items-end gap-0.5">
                                                <div class="flex items-baseline justify-end gap-1">
                                                    {{-- La cifra es un botón: al pulsarla se abre de
                                                         qué paquetes sale, con los volúmenes y la
                                                         cobertura del día. El guion también, porque sin
                                                         capacidad declarada esa celda no tiene otra
                                                         forma de contar lo que movió. --}}
                                                    <button type="button"
                                                            wire:click="openDetail(@js($row['key']), '{{ $dia->toDateString() }}')"
                                                            title="{{ $celda['usage'] === null
                                                                ? 'Esta UT no tiene capacidad declarada: ver de qué paquetes sale el día'
                                                                : 'Ver de qué paquetes sale este '.$ocupacion($celda['usage']) }}"
                                                            @class([
                                                                'cursor-pointer font-semibold whitespace-nowrap underline-offset-4 hover:underline',
                                                                'text-slate-300' => $celda['usage'] === null,
                                                                'text-shell-900' => $celda['usage'] !== null && $color === null,
                                                            ])
                                                            @style(['color: '.$color => $celda['usage'] !== null && $color !== null])>
                                                        {{ $celda['usage'] === null ? '—' : $ocupacion($celda['usage']) }}

                                                        {{-- Que no cupiera en la furgoneta ya no puede
                                                             decirlo el color —lo elige el cliente, y un
                                                             125 % cae en el tramo bueno—, así que lo
                                                             dice esta marca. --}}
                                                        @if ($pasada)
                                                            <span class="align-middle text-amber-500"
                                                                  title="Se pasa de la capacidad declarada">▲</span>
                                                        @endif
                                                    </button>

                                                    {{-- A las incidencias de esa ruta ese día, con sus
                                                         rutas abiertas y resaltadas. En ámbar cuando
                                                         hubo paquetes fuera de su ruta y en gris cuando
                                                         no: el icono está siempre, pero sólo llama la
                                                         atención si hay algo que mirar. --}}
                                                    <a href="{{ $enlace }}" wire:navigate
                                                       @class([
                                                           'shrink-0 transition',
                                                           'text-amber-500 hover:text-amber-600' => $celda['incidents'] > 0,
                                                           'text-slate-300 hover:text-slate-500' => $celda['incidents'] === 0,
                                                       ])
                                                       title="{{ $celda['incidents'] > 0
                                                           ? $celda['incidents'].' '.($celda['incidents'] === 1 ? 'paquete acabó' : 'paquetes acabaron').' fuera de esta ruta: ver las incidencias del día'
                                                           : 'Ver las incidencias de esta ruta ese día' }}">
                                                        <span class="sr-only">Ver las incidencias de esta ruta ese día</span>
                                                        <svg class="size-3.5" fill="none" viewBox="0 0 24 24"
                                                             stroke-width="2.2" stroke="currentColor" aria-hidden="true">
                                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                                  d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                                                        </svg>
                                                    </a>
                                                </div>

                                                {{-- El reparto, en pequeño: lo que pasó con su ruta
                                                     y lo que acabó fuera. Suman 100 %, así que el
                                                     segundo es el que se mira; va en ámbar sólo
                                                     cuando existe, para que un día limpio no lleve
                                                     un color de aviso. --}}
                                                @if ($celda['own'] !== null)
                                                    <span class="text-xs whitespace-nowrap text-slate-400"
                                                          title="{{ $ocupacion($celda['own']) }} del volumen pasó con su propia ruta y {{ $ocupacion($celda['foreign']) }} acabó fuera de ella">
                                                        ({{ $ocupacion($celda['own']) }}<span class="text-slate-300"> · </span><span @class(['text-amber-600' => $celda['foreign'] > 0])>{{ $ocupacion($celda['foreign']) }}</span>)
                                                    </span>
                                                @endif
                                            </div>
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
                la capacidad declarada de su furgoneta</strong>, así que sale un guion en las UT que no
                tienen la capacidad puesta en el maestro. Entre paréntesis, de dónde sale: <strong>qué
                parte de ese volumen pasó con su propia ruta y qué parte acabó fuera de ella</strong>
                —suman el 100 %—, y el triángulo lleva a las incidencias de esa ruta ese día.
                El color de cada porcentaje es el del tramo en que cae —malo, justo o bueno—, según los
                umbrales y los colores de
                <a href="{{ route('settings', ['module' => 'capacity-calendar']) }}" wire:navigate
                   class="font-medium underline underline-offset-2">Configuraciones · Calendario de capacidades</a>.
                <strong>Pulsa un porcentaje</strong> para ver el detalle del día: los volúmenes en m³, los
                envíos, cuántos de ellos traían volumen y <strong>lo que dejaron sus rutas</strong> —lo
                facturado sin IVA, partido igual que el volumen, para ver cuánto dinero acabó en otra
                furgoneta—.
                El ▲ marca los días que se pasan de la capacidad declarada de la furgoneta.
                Cuando el portal no da el volumen de todos los envíos ese hueco no se suma como cero
                —significaría «no lo sé»—, así que la ocupación real puede ser mayor que la que se ve.
            </div>
        @endif
    </x-ui.card>

    {{-- De qué paquetes sale la ocupación de una celda. El reparto manda en
         porcentaje del volumen del día —los dos suman 100 %, que es la pregunta
         que se hace al pulsar la cifra— y debajo va, en pequeño, lo que cada
         parte ocupa de la furgoneta, que es lo que suma el porcentaje de la
         tabla. --}}
    @if ($detalle)
        <x-ui.modal :title="$detalle['label']"
                    :description="ucfirst($detalle['day']->translatedFormat('l j \d\e F \d\e Y'))"
                    close="closeDetail">
            <div class="rounded-lg bg-slate-50 px-4 py-3">
                <div class="flex items-baseline justify-between gap-4">
                    <div>
                        <p class="text-sm font-medium text-shell-900">Ocupación del día</p>
                        <p class="text-xs text-slate-500">
                            {{ $vol($detalle['volume']) }} m³
                            @if ($detalle['capacity'] !== null)
                                de {{ $vol($detalle['capacity']) }} m³ declarados
                            @endif
                            · {{ $detalle['shipments'] }} {{ $detalle['shipments'] === 1 ? 'envío' : 'envíos' }}
                        </p>
                    </div>

                    <span class="text-2xl font-semibold text-shell-900 tabular-nums">
                        {{ $detalle['usage'] === null ? '—' : $ocupacion($detalle['usage']) }}
                    </span>
                </div>

                {{-- Lo que dejaron sus rutas ese día, con el número de envíos sobre los que
                     se sumó pegado al importe (§7, fase 13.C, regla 1). **«De sus rutas» y
                     no «del día»**: aquí sólo están los envíos de esta UT, así que llamarlo
                     la ganancia del día sería aún más falso que en la pantalla de la
                     jornada. Ganancia y no rentabilidad: es lo facturado sin IVA, y el
                     margen necesitaría el coste, que no viaja en el contrato (§3.1). --}}
                <div class="mt-3 flex items-baseline justify-between gap-4 border-t border-slate-200 pt-3">
                    <div>
                        <p class="text-sm font-medium text-shell-900">Ganancia de sus rutas</p>
                        <p class="text-xs text-slate-500">
                            Lo facturado sin IVA
                            · {{ $detalle['priced'] }} de {{ $detalle['shipments'] }}
                            {{ $detalle['shipments'] === 1 ? 'envío' : 'envíos' }} con dato
                        </p>
                    </div>

                    <span class="text-2xl font-semibold text-shell-900 tabular-nums">
                        {{ $euros($detalle['revenue']) }}
                    </span>
                </div>
            </div>

            <dl class="mt-4 divide-y divide-slate-100">
                @foreach ($detalle['parts'] as $parte)
                    <div class="flex items-baseline justify-between gap-4 py-3 first:pt-0 last:pb-0">
                        <dt class="min-w-0">
                            <span class="text-sm font-medium text-shell-900">{{ $parte['label'] }}</span>
                            <span class="block text-xs text-slate-500">{{ $parte['help'] }}</span>
                        </dt>

                        {{-- Dos repartos, no uno con una coletilla: el del volumen y el del
                             dinero. Se parecen casi siempre, y por eso hay que poder verlos
                             separados —un envío voluminoso puede facturar poco y al revés—.
                             Cada uno con su cuenta, que tampoco es la misma: los envíos con
                             volumen y los que tienen valoración de Envexpress no coinciden. --}}
                        <dd class="grid grid-cols-2 gap-x-4 text-right whitespace-nowrap tabular-nums">
                            <div>
                                <span class="block text-lg font-semibold text-shell-900">
                                    {{ $parte['share'] === null ? '—' : $ocupacion($parte['share']) }}
                                </span>
                                <span class="block text-[11px] font-medium tracking-wide text-slate-400 uppercase">
                                    del volumen
                                </span>
                                <span class="mt-0.5 block text-xs text-slate-400">
                                    {{ $vol($parte['volume']) }} m³ ·
                                    {{ $parte['shipments'] }} {{ $parte['shipments'] === 1 ? 'envío' : 'envíos' }}
                                </span>
                                @if ($parte['usage'] !== null)
                                    <span class="block text-xs text-slate-400">
                                        {{ $ocupacion($parte['usage']) }} de la furgoneta
                                    </span>
                                @endif
                            </div>

                            {{-- En euros y no en porcentaje (19/08/2026, decisión del
                                 cliente): en «Fuera de su ruta» lo que se quiere ver es el
                                 dinero que acabó en otra furgoneta, no qué fracción del día
                                 era. El importe es la cifra, y su cuenta va debajo.

                                 **«Neta» aquí es «sin IVA», no «después de costes»**: el
                                 coste no viaja en el contrato (§3.1) y esto no es el margen.
                                 De ahí el título, que lo dice al pasar por encima. --}}
                            <div title="Lo facturado por esos envíos sin IVA. No es el margen: el coste no llega a este panel.">
                                <span class="block text-lg font-semibold text-shell-900">
                                    {{ $euros($parte['revenue']) }}
                                </span>
                                <span class="block text-[11px] font-medium tracking-wide text-slate-400 uppercase">
                                    ganancia neta
                                </span>
                                <span class="mt-0.5 block text-xs text-slate-400">
                                    {{ $parte['priced'] }} con dato
                                </span>
                            </div>
                        </dd>
                    </div>
                @endforeach
            </dl>

            {{-- Sin esto el reparto se leería como si cubriera la jornada
                 entera: un nulo del portal es «no lo sé» y no suma (§3), así
                 que los envíos sin volumen no están en ninguna de las dos
                 partes. --}}
            @if ($detalle['measured'] < $detalle['shipments'])
                <p class="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
                    El portal dio el volumen de {{ $detalle['measured'] }} de los
                    {{ $detalle['shipments'] }} envíos del día, así que el reparto es el de esos.
                </p>
            @endif

            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="closeDetail">Cerrar</x-ui.button>

                {{-- La pregunta que sigue al «19 % acabó fuera de su ruta» es
                     cuál se lo llevó, y eso está en la jornada de ese día. --}}
                <x-ui.button as="a" href="{{ $detalle['incidents'] }}" wire:navigate>
                    Ver las incidencias del día
                </x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
