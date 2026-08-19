<?php

use App\Models\RunPackage;
use App\Models\IncidentRun;
use App\Support\IncidentPresenter;
use App\Support\PermissionCatalog;
use App\Support\SendsToasts;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
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
 *
 * Desde el 14/08/2026 es además una **lista de trabajo**: cada incidencia se
 * puede comentar y marcar como atendida, y el listado lo dice sin abrirla. Ver
 * la migración de `run_packages.handled_at` para por qué esas columnas viven en
 * la tabla que escribe el bot.
 */
new #[Layout('components.layouts.app')] class extends Component
{
    use SendsToasts;

    /**
     * En la fila «Sin UT asignada» del calendario de capacidades no hay nombre
     * que poner en la URL, y un `?ut=` vacío es «sin filtro»: hace falta un
     * valor. Si alguna vez existiera una UT llamada así, el centinela ganaría;
     * es el único caso, y a cambio el enlace se lee.
     */
    public const SIN_UT = 'sin-ut';

    public IncidentRun $run;

    /**
     * La UT con la que se entró desde el calendario de capacidades: sus rutas
     * llegan abiertas y resaltadas. Va en la query y no en el path porque es un
     * resalte sobre esta misma jornada, no otra pantalla.
     */
    #[Url(as: 'ut', except: '')]
    public string $courier = '';

    /** El paquete abierto en el diálogo de detalle, si hay alguno. */
    public ?int $selected = null;

    /** El paquete abierto en el diálogo de gestión, si hay alguno. */
    public ?int $managing = null;

    public string $note = '';

    public bool $handled = false;

    /**
     * Las incidencias marcadas con la casilla, para gestionarlas de una vez.
     *
     * Un lote de paquetes del mismo comercio pasa por la cinta en segundos y
     * arrastra exactamente la misma incidencia: cerrarlas de una en una es
     * escribir el mismo comentario quince veces.
     *
     * Los ids llegan del navegador, así que aquí no son más que una intención:
     * lo que se gestiona sale siempre de consultar la jornada (`seleccionadas`).
     *
     * @var list<int>
     */
    public array $selection = [];

    /** El diálogo de gestión abierto sobre la selección, no sobre un paquete. */
    public bool $bulk = false;

    public function mount(string $date): void
    {
        $this->run = IncidentRun::where('run_date', $date)->firstOrFail();
    }

    public function clearCourier(): void
    {
        $this->courier = '';
    }

    /** Si esta ruta la llevaba aquel día la UT con la que se entró. */
    private function highlighted(?string $courier): bool
    {
        if ($this->courier === '') {
            return false;
        }

        return $this->courier === self::SIN_UT
            ? $courier === null
            : $courier === $this->courier;
    }

    public function show(int $id): void
    {
        $this->selected = $id;
    }

    public function cancel(): void
    {
        $this->selected = null;
    }

    // --- Gestión de la incidencia -------------------------------------------

    /**
     * Abre el diálogo de gestión con lo que ya hubiera anotado.
     *
     * `firstOrFail` contra los paquetes de **esta** jornada y no `find` a secas:
     * el id llega del cliente, y sin acotarlo se podría anotar el paquete de
     * otro día pasando otro número.
     */
    public function manage(int $id): void
    {
        $this->authorizeManagement();

        $paquete = $this->run->packages()->findOrFail($id);

        $this->managing = $paquete->id;
        $this->note = $paquete->handling_note ?? '';
        $this->handled = $paquete->isHandled();
    }

    /**
     * Gestionar una incidencia —comentarla, darla por atendida— es escribir, y
     * el `can:` de la ruta sólo deja entrar a leer la jornada (§7, fase 12).
     */
    private function authorizeManagement(): void
    {
        $this->authorize(PermissionCatalog::name('incidents', PermissionCatalog::MANAGE));
    }

    /** Si esta cuenta puede gestionar, para no ofrecer un botón que va a negarse. */
    public function canManage(): bool
    {
        return (bool) auth()->user()?->can(
            PermissionCatalog::name('incidents', PermissionCatalog::MANAGE)
        );
    }

    public function cancelManagement(): void
    {
        $this->reset('managing', 'note', 'handled', 'bulk');
    }

    // --- Gestión en lote -----------------------------------------------------

    /**
     * Las incidencias de la selección, **consultadas**, no las que diga el
     * cliente que son.
     *
     * Filtra por tres cosas a la vez, y las tres importan: que sean de esta
     * jornada —el id llega del navegador, igual que en `manage()`—, que sean
     * incidencias —un paquete correcto no se «atiende»— y que no estén
     * retiradas, que es lo que la pantalla no enseña (§7).
     *
     * @return Collection<int, RunPackage>
     */
    private function seleccionadas(): Collection
    {
        if ($this->selection === []) {
            return collect();
        }

        return $this->run->currentPackages()
            ->whereIn('id', $this->selection)
            ->get()
            ->filter->isIncident()
            ->values();
    }

    /** Cuántas hay marcadas, para la barra de selección. */
    public function selectedCount(): int
    {
        return $this->seleccionadas()->count();
    }

    public function clearSelection(): void
    {
        $this->reset('selection');
    }

    /**
     * Vuelve a pintar la isla del listado.
     *
     * Se llama a mano después de escribir porque una isla **no se repinta
     * sola**: ése es justo el motivo de que exista. Lo que cambia tras guardar
     * son los distintivos de cada fila y los contadores de cada ruta, y los dos
     * viven ahí dentro.
     */
    private function refreshListing(): void
    {
        $this->renderIsland('rutas');
    }

    /**
     * Abre el diálogo de gestión sobre la selección.
     *
     * Empieza en blanco a propósito: son varias incidencias con comentarios
     * que pueden diferir, y precargar el de una cualquiera daría a entender que
     * ese texto es el de todas.
     */
    public function manageSelection(?array $ids = null): void
    {
        $this->authorizeManagement();

        // Los ids llegan de la selección del navegador (ver la raíz de la
        // vista). Se guardan como intención y nada más: lo que se gestiona sale
        // de `seleccionadas()`, que los vuelve a consultar contra la jornada.
        if ($ids !== null) {
            $this->selection = array_map('intval', $ids);
        }

        if ($this->seleccionadas()->isEmpty()) {
            $this->toast('No hay ninguna incidencia marcada.', 'warning');

            return;
        }

        $this->bulk = true;
        $this->managing = null;
        $this->note = '';
        $this->handled = false;
    }

    /**
     * Escribe el mismo comentario —y, si se pide, el cierre— en todas.
     *
     * Dos reglas que **no** son las del diálogo de una sola incidencia, y que
     * la pantalla dice en palabras encima del formulario:
     *
     * 1. **Un comentario en blanco no borra nada.** En el diálogo de una sola,
     *    vaciar el campo es querer quitar el comentario. Aquí sería un
     *    accidente: se marcan quince, se cierra en lote sin escribir, y se
     *    perderían los comentarios que ya tuvieran.
     * 2. **El interruptor sólo cierra; nunca reabre.** Desmarcado significa «no
     *    toques el estado», no «reábrelas». Reabrir en lote borraría fecha,
     *    autor y nombre de incidencias atendidas hace semanas, y eso se pide de
     *    una en una, mirándolas.
     */
    public function saveSelection(): void
    {
        $this->authorizeManagement();

        $this->validate([
            'note' => ['nullable', 'string', 'max:2000'],
        ], attributes: ['note' => 'comentario']);

        // Ni comentario ni cierre: no se ha pedido nada. Con esto el diálogo se
        // cerraba diciendo «N incidencias actualizadas» sin haber tocado una.
        if ($this->note === '' && ! $this->handled) {
            $this->addError('handled', 'Escribe un comentario o marca «Darlas todas por atendidas».');

            return;
        }

        $incidencias = $this->seleccionadas();

        if ($incidencias->isEmpty()) {
            $this->toast('No hay ninguna incidencia marcada.', 'warning');
            $this->cancelManagement();

            return;
        }

        // La misma regla que en el diálogo de una sola: el comentario no vive
        // sin el cierre. Aquí sólo estorba si alguna de las marcadas se
        // quedaría abierta; comentar en lote las que ya están atendidas —un
        // repaso, por ejemplo— sigue valiendo.
        $abiertas = $incidencias->reject->isHandled()->count();

        if ($this->note !== '' && ! $this->handled && $abiertas > 0) {
            $this->addError('handled', sprintf(
                'De las marcadas, %d %s sin atender: marca «Darlas todas por atendidas» para guardar el comentario.',
                $abiertas,
                $abiertas === 1 ? 'sigue' : 'siguen',
            ));

            return;
        }

        // En una transacción: la mitad de un lote cerrado y la otra mitad no es
        // peor que no haber cerrado nada, porque nadie sabría dónde se quedó.
        DB::transaction(function () use ($incidencias) {
            foreach ($incidencias as $paquete) {
                if ($this->note !== '') {
                    $paquete->handling_note = $this->note;
                }

                // Ya atendida se queda como estaba: mover `handled_at` falsearía
                // cuánto se tardó en ocuparse de ella (§7, fase 9).
                if ($this->handled && ! $paquete->isHandled()) {
                    $paquete->handled_at = now();
                    $paquete->handled_by = auth()->id();
                    $paquete->handled_by_name = auth()->user()->fullName();
                }

                $paquete->save();
            }
        });

        $total = $incidencias->count();

        $this->clearSelection();
        $this->cancelManagement();
        $this->refreshListing();

        // La selección del navegador se vacía con esto: acaba de aplicarse.
        $this->dispatch('seleccion-limpia');

        $this->toast($total === 1
            ? 'Incidencia actualizada.'
            : "{$total} incidencias actualizadas.");
    }

    /**
     * Guarda el comentario y el estado.
     *
     * **El comentario y la atención van juntos** (18/08/2026): no se puede
     * dejar un comentario en una incidencia que se queda sin atender, y
     * desmarcarla borra el que tuviera. El comentario dice *qué se hizo*, así
     * que una incidencia comentada y abierta era un estado que no significaba
     * nada y que nadie sabía leer en el listado. Cerrar **sin** comentario
     * sigue valiendo: forzar a escribir algo sólo produce «ok».
     *
     * `handled_at` sólo se toca cuando el interruptor cambia de verdad: volver a
     * guardar un comentario no debe mover la fecha de cuando se atendió, que es
     * el dato que responde «¿cuánto tardamos en ocuparnos de esto?».
     *
     * El nombre de quien atiende se copia junto al id, como `audit_logs` (§4):
     * la fila tiene que seguir leyéndose con el usuario dado de baja.
     */
    public function saveHandling(): void
    {
        // Otra vez aquí y no sólo al abrir el diálogo: entre lo uno y lo otro
        // hay una ida al servidor, y a este método se llega desde el navegador.
        $this->authorizeManagement();

        $this->validate([
            // Sin `required`: cerrar una incidencia sin comentario es legítimo,
            // y forzar a escribir algo sólo produce comentarios que dicen «ok».
            'note' => ['nullable', 'string', 'max:2000'],
        ], attributes: ['note' => 'comentario']);

        $paquete = $this->run->packages()->findOrFail($this->managing);

        // Guardar sin haber tocado nada cerraba el diálogo con un «Incidencia
        // actualizada» que no era verdad. Ahora lo dice.
        if ($this->note === ($paquete->handling_note ?? '') && $this->handled === $paquete->isHandled()) {
            $this->addError('handled', 'No has cambiado nada: escribe un comentario o marca la incidencia como atendida.');

            return;
        }

        // Comentar sin cerrar, no. El error va en el interruptor y no en el
        // texto porque lo que falta es marcarlo, no reescribir el comentario.
        if ($this->note !== '' && ! $this->handled && ! $paquete->isHandled()) {
            $this->addError('handled', 'Marca la incidencia como atendida para poder guardar el comentario.');

            return;
        }

        $paquete->handling_note = $this->note === '' ? null : $this->note;

        if ($this->handled && ! $paquete->isHandled()) {
            $paquete->handled_at = now();
            $paquete->handled_by = auth()->id();
            $paquete->handled_by_name = auth()->user()->fullName();
        } elseif (! $this->handled && $paquete->isHandled()) {
            // Reabrir: se borra también quién y cuándo. Dejar el rastro de una
            // atención que ya no vale sería peor que no tenerlo.
            $paquete->handled_at = null;
            $paquete->handled_by = null;
            $paquete->handled_by_name = null;

            // Y el comentario se va con ella: decía qué se hizo, y ya no hay
            // nada hecho. Se borra sin preguntar y aunque el campo siga lleno
            // —el diálogo lo avisa—, porque si no, reabrir obligaría a vaciar
            // el texto a mano y chocaría con la regla de arriba.
            $paquete->handling_note = null;
        }

        $paquete->save();

        $this->cancelManagement();
        $this->refreshListing();
        $this->toast('Incidencia actualizada.');
    }

    /**
     * Lo que necesita **cada** petición, y sólo eso.
     *
     * Aquí no se cargan los paquetes de la jornada (~650 el 03/08): abrir o
     * cerrar un diálogo obligaba a hidratarlos, agruparlos y volver a pintar la
     * tabla entera, y eso eran 640 ms y 2 MB de HTML por clic. Lo caro vive en
     * las propiedades calculadas de abajo, que sólo se tocan desde la isla del
     * listado; cuando la isla no se vuelve a pintar, no llegan a consultarse.
     *
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return [
            // De quién son las rutas resaltadas, ya en palabras, y cuántas ha
            // resaltado: con cero hay que decirlo, o el resalte que no aparece
            // se lee como una pantalla rota.
            'utDestacada' => match ($this->courier) {
                '' => null,
                self::SIN_UT => 'Sin UT asignada',
                default => $this->courier,
            },
            'destacadas' => $this->highlightedRoutes(),

            // Las tres cifras del balance, en una consulta de agregados en vez
            // de contando una colección que aquí ya no se carga.
            'balance' => $this->balance(),

            // El paquete de cada diálogo, buscado por su id **dentro de esta
            // jornada**: el id llega del cliente, y sin acotarlo se abriría el
            // de otro día pasando otro número.
            'detalle' => $this->selected ? $this->run->currentPackages()->find($this->selected) : null,
            'gestion' => $this->managing ? $this->run->currentPackages()->find($this->managing) : null,

            // Cuántas hay marcadas de verdad —de esta jornada, incidencias y no
            // retiradas—, que es lo que dice el diálogo del lote.
            'marcadas' => $this->selectedCount(),
        ];
    }

    /**
     * Incidencias, firmes y sin atender, en una sola consulta.
     *
     * `count(*) filter (where …)` es de Postgres y hace las tres pasadas en un
     * recorrido. La alternativa era contar sobre la colección entera, que es
     * justo lo que esta pantalla dejó de cargar en cada clic.
     *
     * Desde el 19/08/2026 arrastra también la ganancia de la jornada, y por el mismo motivo
     * de siempre: se pinta **fuera** de la isla del listado, así que no puede salir de
     * `$this->paquetes` sin cargar las ~650 filas del día en cada clic — que es justo lo que
     * esta pantalla dejó de hacer. Aquí no cuesta ni una consulta más.
     *
     * `sum(net_revenue)` junto a `count(net_revenue)`, como en el calendario de capacidades:
     * `count` de una columna no cuenta los nulos, y es el denominador honesto de la suma.
     * Postgres devuelve `null` —no cero— si no hay ni un valor, que es lo que hace falta.
     *
     * @return array{incidencias: int, firmes: int, pendientes: int, ganancia: ?float, con_ganancia: int, envios: int}
     */
    private function balance(): array
    {
        $fila = $this->run->currentPackages()
            ->selectRaw('count(*) filter (where type is not null) as incidencias')
            ->selectRaw('count(*) filter (where type is not null and confidence = ?) as firmes', [RunPackage::CONFIDENCE_HIGH])
            ->selectRaw('count(*) filter (where type is not null and handled_at is null) as pendientes')
            ->selectRaw('sum(net_revenue) as ganancia')
            ->selectRaw('count(net_revenue) as con_ganancia')
            ->selectRaw('count(*) as envios')
            ->first();

        return [
            'incidencias' => (int) $fila->incidencias,
            'firmes' => (int) $fila->firmes,
            'pendientes' => (int) $fila->pendientes,

            // Nula si ni un envío de la jornada trae valoración: «no se sabe», no cero.
            'ganancia' => $fila->ganancia === null ? null : (float) $fila->ganancia,
            'con_ganancia' => (int) $fila->con_ganancia,
            'envios' => (int) $fila->envios,
        ];
    }

    /** Cuántas rutas se resaltan, sin cargar ninguna: sólo hace falta el número. */
    private function highlightedRoutes(): int
    {
        if ($this->courier === '') {
            return 0;
        }

        return (int) $this->run->currentPackages()
            ->when(
                $this->courier === self::SIN_UT,
                fn ($q) => $q->whereNull('assigned_courier_name'),
                fn ($q) => $q->where('assigned_courier_name', $this->courier),
            )
            // `coalesce` para contar igual que agrupa la tabla: los paquetes sin
            // ruta en el maestro van todos a una sección «Sin ruta».
            ->selectRaw("count(distinct coalesce(assigned_route_name, 'Sin ruta')) as total")
            ->value('total');
    }

    /**
     * Todos los paquetes evaluados, no sólo las incidencias: la pantalla tiene
     * que poder decir «94 paquetes, 11 con incidencia».
     *
     * Calculada y no en `with()`: así se consulta una vez por petición **y sólo
     * si la isla del listado se pinta**. Ver `with()`.
     */
    #[Computed]
    public function paquetes(): Collection
    {
        return $this->run->currentPackages()
            ->orderBy('belt_time')
            ->orderBy('id')
            ->get();
    }

    #[Computed]
    public function incidencias(): Collection
    {
        return $this->paquetes->filter->isIncident();
    }

    #[Computed]
    public function rutas(): Collection
    {
        return $this->porRuta($this->paquetes);
    }

    /**
     * Las horas van en UTC, que es lo que muestran el informe del bot y GLS
     * Atlas. Pintarlas en Europe/Madrid las correría dos horas y el cliente no
     * podría contrastar ni un solo paquete contra el portal.
     *
     * Es un método del componente y no una función de la vista porque la isla
     * del listado se pinta en su propio ámbito y ahí no llegan las de fuera.
     */
    public function hora(?\Illuminate\Support\Carbon $momento): string
    {
        return $momento?->utc()->format('H:i:s') ?? '—';
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

                    // Se entró desde el calendario de capacidades a mirar
                    // justo esta ruta: llega abierta y marcada.
                    'destacada' => $this->highlighted($grupo->first()->assigned_courier_name),
                    'paquetes' => $grupo->count(),

                    // La ganancia de la ruta y **sobre cuántos envíos** se sumó.
                    // Las dos juntas o ninguna: ver `ganancia()`.
                    ...$this->ganancia($grupo),

                    'total' => $incidencias->count(),
                    'firmes' => $incidencias->where('confidence', RunPackage::CONFIDENCE_HIGH)->count(),
                    'pendientes' => $incidencias->reject->isHandled()->count(),
                    'acusaciones' => $this->firmesDelante($grupo->where('type', RunPackage::TYPE_OTHER_ROUTE)),
                    'descolgados' => $this->firmesDelante($grupo->where('type', RunPackage::TYPE_OUT_OF_BATCH)),
                    'correctos' => $grupo->reject->isIncident()->values(),
                ];
            })
            ->sortBy('nombre');
    }

    /**
     * Lo facturado sin IVA por un grupo de paquetes, con su cobertura al lado.
     *
     * **`importe` es nulo, no cero, cuando ningún envío del grupo trae el dato**, igual que
     * con el volumen: un cero diría «esta ruta no dejó nada» cuando lo cierto es que no se
     * sabe. Y `con` viaja siempre pegado al importe porque sin el denominador una ruta a la
     * que le faltan valoraciones parece menos rentable de lo que fue (§7, fase 13.C, regla 1).
     *
     * Se suma en PHP y no en SQL porque la colección de la jornada ya está cargada —es la
     * misma que pinta las tablas—, y una consulta más por ruta no compraría nada.
     *
     * @param  Collection<int, RunPackage>  $grupo
     * @return array{ganancia: ?float, con_ganancia: int}
     */
    private function ganancia(Collection $grupo): array
    {
        $con = $grupo->whereNotNull('net_revenue');

        return [
            'ganancia' => $con->isEmpty() ? null : round($con->sum('net_revenue'), 2),
            'con_ganancia' => $con->count(),
        ];
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
    #[Computed]
    public function traspasos(): Collection
    {
        return $this->incidencias
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

{{-- La selección vive en el navegador (`marcadas`), no en el servidor: marcar
     una casilla no puede costar una ida y vuelta con la jornada entera detrás.
     Sólo viaja al pulsar «Gestionar juntas», y se vacía cuando el lote se
     guarda. Va en la raíz porque la barra de abajo y las casillas de las tablas
     están en sitios distintos del árbol. --}}
<div x-data="{ marcadas: [] }" x-on:seleccion-limpia.window="marcadas = []">
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
        {{-- Cinco columnas en pantalla ancha desde que hay «Sin atender»: con
             `sm:grid-cols-4` la quinta caía sola a una segunda fila. --}}
        <dl class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
            @foreach ([
                ['Envíos del día', $run->shipments, null],
                ['Evaluados', $run->evaluated, 'Los que tienen ruta en el maestro y hora de cinta'],
                ['Incidencias', $balance['incidencias'], 'Sobre los evaluados'],
                ['Hallazgos firmes', $balance['firmes'], 'Los que el bot sostiene sin reservas'],
                ['Sin atender', $balance['pendientes'], 'Incidencias que nadie ha marcado todavía como atendidas'],
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
        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
            <div>
                <h2 class="text-sm font-semibold text-shell-900">Por ruta</h2>

                {{-- «De las rutas», nunca «del día»: aquí sólo están los envíos que tienen
                     ruta en el maestro. El 07/08/2026 eran 2.615,16 € de los 4.871,10 €
                     facturados; llamar «del día» a esta suma diría la mitad. El total de
                     jornada no viaja en el contrato todavía (§8). --}}
                @if ($balance['ganancia'] !== null)
                    <p class="text-xs text-slate-500">
                        Ganancia de las rutas:
                        <strong class="font-semibold text-shell-900 tabular-nums">{{ number_format($balance['ganancia'], 2, ',', '.') }} €</strong>
                        sobre {{ $balance['con_ganancia'] }} de {{ $balance['envios'] }} envíos
                        · <span title="Los envíos de comercios que no están en el maestro no llegan en el payload, así que tampoco están en esta suma.">no incluye los envíos sin ruta</span>
                    </p>
                @endif
            </div>

            {{-- De dónde se viene, cuando se entra desde una celda del
                 calendario de capacidades. Con el aviso puesto, una jornada en
                 la que esa UT no llevó nada se explica sola. --}}
            @if ($utDestacada)
                <p class="flex flex-wrap items-center gap-2 text-xs text-slate-500">
                    @if ($destacadas > 0)
                        Resaltando las rutas de
                        <strong class="font-semibold text-shell-900">{{ $utDestacada }}</strong>
                    @else
                        <strong class="font-semibold text-shell-900">{{ $utDestacada }}</strong>
                        no llevaba ninguna ruta en esta jornada
                    @endif

                    <button type="button" wire:click="clearCourier"
                            class="font-medium text-brand-600 hover:text-brand-700">
                        Ver todas
                    </button>
                </p>
            @endif
        </div>

        {{-- El listado entero es una **isla**: sin ella, abrir un diálogo
             volvía a pintar las ~650 filas de la jornada —640 ms y 2 MB de
             HTML— para cambiar un modal. Livewire no repinta una isla salvo que
             se le pida, y aquí se le pide justo cuando cambia lo que enseña:
             al guardar una gestión o un lote (`renderIsland('rutas')`). --}}
        @island('rutas')
        @if ($this->rutas->isEmpty())
            <x-ui.card padding="p-0">
                <x-ui.empty-state title="Ninguna incidencia en esta jornada"
                                  description="Todos los paquetes evaluados pasaron por la cinta con el grueso de su ruta." />
            </x-ui.card>
        @else
            <div class="space-y-3">
                @php $yaDesplazada = false; @endphp

                @foreach ($this->rutas as $ruta)
                    @php
                        // Sólo la primera se lleva el desplazamiento: con dos
                        // rutas de la misma UT, la página saltaría dos veces.
                        $desplazar = $ruta['destacada'] && ! $yaDesplazada;
                        $yaDesplazada = $yaDesplazada || $ruta['destacada'];
                    @endphp

                    {{-- El `@if` no cabe entre los atributos de un componente
                         —Blade los compila antes—, así que la condición del
                         desplazamiento va dentro del propio `x-init`. --}}
                    <x-ui.card padding="p-0"
                               class="{{ $ruta['destacada'] ? 'ring-2 ring-brand-400' : '' }}"
                               x-data="{ abierta: {{ $ruta['destacada'] ? 'true' : 'false' }} }"
                               x-init="{{ $desplazar ? 'true' : 'false' }} && $nextTick(() => $el.scrollIntoView({ behavior: 'smooth', block: 'center' }))">
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

                                    {{-- Lo que dejó la ruta, **siempre con el número de
                                         envíos sobre los que se sumó**: un envío sin
                                         valoración en Envexpress es «no se sabe», no cero,
                                         y sin el denominador la ruta parecería menos
                                         rentable de lo que fue. --}}
                                    @if ($ruta['ganancia'] !== null)
                                        <span class="whitespace-nowrap"
                                              title="Facturado sin IVA. {{ $ruta['paquetes'] - $ruta['con_ganancia'] }} de los {{ $ruta['paquetes'] }} envíos no tienen valoración en Envexpress y no suman.">
                                            · <span class="font-semibold text-shell-900 tabular-nums">{{ number_format($ruta['ganancia'], 2, ',', '.') }} €</span>
                                            ({{ $ruta['con_ganancia'] }} de {{ $ruta['paquetes'] }} envíos)
                                        </span>
                                    @else
                                        <span class="whitespace-nowrap" title="Ninguno de sus envíos tiene valoración en Envexpress: es «no se sabe», no cero.">
                                            · sin dato de ganancia
                                        </span>
                                    @endif
                                </p>
                            </div>

                            <div class="flex shrink-0 items-center gap-2">
                                {{-- Lo que queda por mirar de esta ruta, sin
                                     tener que desplegarla. --}}
                                @if ($ruta['total'] > 0 && $ruta['pendientes'] === 0)
                                    <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700">
                                        Todas atendidas
                                    </span>
                                @elseif ($ruta['pendientes'] > 0 && $ruta['pendientes'] < $ruta['total'])
                                    <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600">
                                        {{ $ruta['pendientes'] }} sin atender
                                    </span>
                                @endif

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

                                    {{-- Sólo las que quedan por atender: gestionar en
                                         lote es cerrar, y una ya cerrada no tiene nada
                                         que hacer ahí. En texto porque es lo que
                                         devuelve el `value` de una casilla, y comparar
                                         contra enteros dejaría la cabecera sin marcar. --}}
                                    @php $ids = $filas->reject->isHandled()->pluck('id')->map('strval')->all(); @endphp

                                    <div class="mb-5 overflow-x-auto">
                                        <table class="w-full min-w-[34rem] text-sm">
                                            <thead>
                                                <tr class="text-left text-xs text-slate-500">
                                                    {{-- Un lote de paquetes del mismo comercio arrastra
                                                         la misma incidencia, y suele ser la sección
                                                         entera: esta casilla la marca de una vez. --}}
                                                    @if ($this->canManage())
                                                        <th class="w-8 pb-2">
                                                            {{-- Con todas atendidas no hay nada que
                                                                 marcar, y una casilla que no hace
                                                                 nada se pulsa igual. --}}
                                                            @if ($ids !== [])
                                                            <input type="checkbox"
                                                                   x-bind:checked="@js($ids).every(id => marcadas.includes(id))"
                                                                   x-on:change="marcadas = $event.target.checked
                                                                       ? [...new Set([...marcadas, ...@js($ids)])]
                                                                       : marcadas.filter(id => ! @js($ids).includes(id))"
                                                                   class="size-4 rounded border-slate-300 text-brand-500 focus:ring-brand-500"
                                                                   aria-label="Marcar todas las de esta lista">
                                                            @endif
                                                        </th>
                                                    @endif

                                                    <th class="pb-2 font-medium">Comercio</th>
                                                    <th class="pb-2 font-medium">Hora cinta</th>
                                                    @if ($acusa)
                                                        {{-- «Apunta a» y no «Pasó con»: el 95 % de las filas son
                                                             de confianza baja, y una columna que afirma choca con
                                                             el «No concluyente» de la de al lado. El bot señala la
                                                             ruta más compatible, no un hecho comprobado. --}}
                                                        <th class="pb-2 font-medium">Apunta a</th>
                                                    @endif
                                                    <th class="pb-2 font-medium">Fiabilidad</th>
                                                    <th class="pb-2 font-medium">Gestión</th>
                                                    <th class="pb-2"><span class="sr-only">Acciones</span></th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-slate-100">
                                                @foreach ($filas as $fila)
                                                    <tr wire:key="fila-{{ $fila->id }}"
                                                        @class(['text-slate-500' => ! $fila->isConclusive()])
                                                        x-bind:class="marcadas.includes('{{ $fila->id }}') && 'bg-brand-50/60'">
                                                        @if ($this->canManage())
                                                            {{-- La celda se queda aunque no lleve casilla:
                                                                 sin ella, la fila de una atendida se
                                                                 correría una columna entera. --}}
                                                            <td class="py-2">
                                                                @unless ($fila->isHandled())
                                                                    <input type="checkbox" x-model="marcadas"
                                                                           value="{{ $fila->id }}"
                                                                           class="size-4 rounded border-slate-300 text-brand-500 focus:ring-brand-500"
                                                                           aria-label="Marcar la incidencia de {{ $fila->merchant_name }} de las {{ $this->hora($fila->belt_time) }}">
                                                                @endunless
                                                            </td>
                                                        @endif

                                                        <td class="py-2 pr-3">{{ $fila->merchant_name }}</td>
                                                        <td class="py-2 pr-3 tabular-nums">{{ $this->hora($fila->belt_time) }}</td>

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

                                                        {{-- Atendida o no, en el propio listado: sin
                                                             esto hay que abrir una por una para saber
                                                             cuáles quedan. El comentario va en el
                                                             tooltip, que es donde cabe. --}}
                                                        <td class="py-2 pr-3">
                                                            @if ($fila->isHandled())
                                                                <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5
                                                                             text-xs font-semibold text-emerald-700"
                                                                      title="Atendida por {{ $fila->handled_by_name ?? 'alguien que ya no está' }} el {{ $fila->handled_at->translatedFormat('j \d\e F \a \l\a\s H:i') }}{{ $fila->handling_note ? ' · '.$fila->handling_note : '' }}">
                                                                    <svg class="size-3" fill="none" viewBox="0 0 24 24"
                                                                         stroke-width="3" stroke="currentColor">
                                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                                                    </svg>
                                                                    Atendida
                                                                </span>
                                                            @elseif ($fila->handling_note)
                                                                {{-- Comentada pero sin cerrar. Desde el
                                                                     18/08/2026 no se puede llegar a este
                                                                     estado —el comentario pide cerrar—,
                                                                     pero las filas escritas antes lo
                                                                     tienen y hay que saber leerlas. --}}
                                                                <span class="text-xs text-slate-500" title="{{ $fila->handling_note }}">
                                                                    Con comentario
                                                                </span>
                                                            @else
                                                                <span class="text-xs text-slate-400">Pendiente</span>
                                                            @endif
                                                        </td>

                                                        {{-- Los dos botones llaman por `$wire` y no con
                                                             `wire:click`: un `wire:click` dentro de una
                                                             isla le dice a Livewire que repinte **sólo esa
                                                             isla**, y estos diálogos viven fuera de ella,
                                                             así que no llegarían a aparecer. Por `$wire`
                                                             la petición no lleva isla: se repinta todo
                                                             menos las islas, que es justo lo barato. --}}
                                                        <td class="py-2 text-right">
                                                            <div class="flex justify-end gap-1">
                                                                @if ($this->canManage())
                                                                <x-ui.icon-button label="Comentar o marcar como atendida"
                                                                                  x-on:click="$wire.manage({{ $fila->id }})">
                                                                    <svg class="size-4" fill="none" viewBox="0 0 24 24"
                                                                         stroke-width="1.7" stroke="currentColor">
                                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                                              d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 01.865-.501 48.172 48.172 0 003.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z" />
                                                                    </svg>
                                                                </x-ui.icon-button>
                                                                @endif

                                                                <x-ui.icon-button label="Ver el detalle del paquete"
                                                                                  x-on:click="$wire.show({{ $fila->id }})">
                                                                    <svg class="size-4" fill="none" viewBox="0 0 24 24"
                                                                         stroke-width="1.7" stroke="currentColor">
                                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                                              d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                                              d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                                    </svg>
                                                                </x-ui.icon-button>
                                                            </div>
                                                        </td>
                                                    </tr>

                                                    @unless ($fila->isConclusive())
                                                        @if (filled($motivos = \App\Support\IncidentPresenter::reasons($fila)))
                                                            <tr>
                                                                <td colspan="{{ $acusa ? 6 : 5 }}" class="pb-2 text-xs text-slate-400">
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
                                                            {{ $fila->belt_time ? $this->hora($fila->belt_time) : 'sin paso por la cinta' }}
                                                        </td>
                                                        <td class="py-2 text-right">
                                                            <x-ui.icon-button label="Ver el detalle del paquete"
                                                                              x-on:click="$wire.show({{ $fila->id }})">
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
        @endisland
    </section>

    {{-- El agregado que responde la pregunta del negocio de un vistazo. --}}
    {{-- También isla: sale de las mismas incidencias y no cambia mientras se
         gestionan, así que no hay por qué volver a calcularla en cada clic. --}}
    @island('traspasos')
    @if ($this->traspasos->isNotEmpty())
        <section class="mb-6">
            {{-- Frase y no «Ruta 6 → Ruta 5»: con la flecha, «quién recogió de quién» se lee
                 natural como «el primero recogió del segundo», que es justo lo contrario —
                 el primero era el dueño del paquete y el segundo quien se lo llevó. Y no hay
                 forma de deducirlo de los datos: el 10/08 aparecían «Ruta 1 → Ruta 4: 5» y
                 «Ruta 4 → Ruta 1: 5», simétricas y con el mismo número. --}}
            <h2 class="mb-2 text-sm font-semibold text-shell-900">Paquetes que se llevó otra ruta</h2>

            <x-ui.card>
                <ul class="space-y-2 text-sm">
                    @foreach ($this->traspasos as $traspaso)
                        <li class="flex flex-wrap items-baseline gap-x-2">
                            <span class="font-medium text-shell-900">{{ $traspaso['a'] }}</span>
                            <span class="text-slate-500">se llevó {{ $traspaso['total'] }} de</span>
                            <span class="font-medium text-shell-900">{{ $traspaso['de'] }}</span>
                            {{-- Sólo el distintivo: el total ya va dentro de la frase, y
                                 repetirlo a la derecha se leía como si fueran dos cifras
                                 distintas («se llevó 22 de Ruta 6   22»). --}}
                            @if ($traspaso['firmes'] > 0)
                                <span class="ml-auto rounded-full bg-brand-50 px-2 py-0.5 text-xs font-semibold text-brand-700">
                                    {{ $traspaso['firmes'] }} firme{{ $traspaso['firmes'] === 1 ? '' : 's' }}
                                </span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        </section>
    @endif
    @endisland

    {{-- El detalle de un paquete: lo que hace falta para ir a contrastarlo a
         GLS Atlas. --}}
    @if ($detalle)
        <x-ui.modal title="{{ $detalle->merchant_name }}"
                    description="Expedición {{ $detalle->shipment_id }}">
            <div class="px-6 py-4">
                <dl class="grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2">
                    @foreach (array_filter([
                        ['Código de barras', $detalle->barcode ?? '—'],
                        ['Hora de cinta (UTC)', $detalle->belt_time ? $this->hora($detalle->belt_time) : 'Sin paso por la cinta'],
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

                        {{-- «Otras rutas compatibles» y no «compartían esa tanda»: desde la v2
                             del payload la lista son las rutas cuya VENTANA contiene esa hora,
                             no las que compartían el bloque de descarga. El texto tiene que ser
                             cierto también para las jornadas v1 ya guardadas, porque conviven en
                             la misma tabla y ninguna pantalla mira el `payload_version`. --}}
                        @if (filled($detalle->compatible_routes))
                            <p class="mt-2 text-xs text-slate-500">
                                Otras rutas compatibles a esa hora:
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

    {{-- Gestión: el comentario y el interruptor de atendida. Diálogo aparte del
         de detalle a propósito — el de detalle se abre para mirar y este para
         escribir, y mezclarlos convertiría una consulta en un formulario. --}}
    {{-- La barra de la selección, fija abajo: se marcan filas de dos tablas y
         de secciones distintas, así que el sitio donde se cierra el lote no
         puede ser una de ellas. Sólo aparece con algo marcado. --}}
    <div x-show="marcadas.length > 0" x-cloak class="fixed inset-x-0 bottom-4 z-30 flex justify-center px-4">
            <div class="flex flex-wrap items-center gap-3 rounded-xl bg-shell-900 px-4 py-2.5 text-sm text-white shadow-lg">
                {{-- Sin el recuento, a petición (18/08/2026): las filas marcadas
                     ya se ven resaltadas, y la cifra la vuelve a decir el
                     diálogo justo antes de escribir en todas. --}}
                {{-- Aquí es donde la selección viaja al servidor, y sólo aquí. --}}
                <button type="button" x-on:click="$wire.manageSelection(marcadas)"
                        wire:loading.attr="disabled"
                        class="rounded-lg bg-brand-500 px-3 py-1.5 font-medium transition hover:bg-brand-600">
                    Gestionar juntas
                </button>

                <button type="button" x-on:click="marcadas = []"
                        class="font-medium text-slate-300 transition hover:text-white">
                    Quitar la selección
                </button>
            </div>
        </div>

    {{-- El mismo formulario que el de una sola, con dos reglas distintas
         escritas encima: en lote, un comentario en blanco no borra y el
         interruptor sólo cierra. Ver `saveSelection()`. --}}
    @if ($bulk)
        <x-ui.modal title="Gestión en lote" close="cancelManagement"
                    :description="$marcadas.' '.($marcadas === 1 ? 'incidencia marcada' : 'incidencias marcadas').' de esta jornada'">
            <form wire:submit="saveSelection" id="form-lote" class="space-y-4">
                <x-ui.field label="Comentario" for="note-lote" :error="$errors->first('note')"
                            hint="Se escribe igual en todas las marcadas. En blanco no toca los que ya tuvieran.">
                    <x-ui.textarea wire:model="note" id="note-lote" :invalid="$errors->has('note')"
                                   placeholder="Mismo lote del comercio: pasaron seguidos por la cinta." />
                </x-ui.field>

                <div>
                    <label @class([
                        'flex items-start gap-3 rounded-lg border px-3 py-2.5',
                        'border-slate-200' => ! $errors->has('handled'),
                        'border-red-300 bg-red-50' => $errors->has('handled'),
                    ])>
                        <input type="checkbox" wire:model="handled"
                               class="mt-0.5 size-4 rounded border-slate-300 text-brand-500 focus:ring-brand-500">
                        <span class="text-sm">
                            <span class="font-medium text-shell-900">Darlas todas por atendidas</span>
                            <span class="block text-xs text-slate-500">
                                Las que ya lo estén se quedan con su fecha y su autor. Dejarlo sin marcar no reabre
                                ninguna: eso se hace de una en una. Un comentario sobre incidencias que se quedan
                                abiertas no se guarda.
                            </span>
                        </span>
                    </label>

                    @error('handled')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            </form>

            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="cancelManagement" wire:loading.attr="disabled">
                    Cancelar
                </x-ui.button>

                <x-ui.button type="submit" form="form-lote"
                             wire:loading.attr="disabled" wire:target="saveSelection">
                    <span wire:loading.remove wire:target="saveSelection">Guardar en las {{ $marcadas }}</span>
                    <span wire:loading wire:target="saveSelection">Guardando…</span>
                </x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($gestion)
        <x-ui.modal title="Gestión de la incidencia" close="cancelManagement"
                    description="{{ $gestion->merchant_name }} · expedición {{ $gestion->shipment_id }}">
            <form wire:submit="saveHandling" id="form-gestion" class="space-y-4">
                <x-ui.field label="Comentario" for="note" :error="$errors->first('note')"
                            hint="Qué se ha hecho o qué se ha averiguado. Lo lee quien abra la jornada después.">
                    <x-ui.textarea wire:model="note" id="note" :invalid="$errors->has('note')"
                                   placeholder="Hablado con la UT: se confundió de jaula al cargar." />
                </x-ui.field>

                {{-- El comentario y la atención van juntos: el texto de debajo
                     lo dice antes de guardar, y el error sale aquí y no en el
                     comentario porque lo que falta es marcar la casilla. --}}
                <div>
                    <label @class([
                        'flex items-start gap-3 rounded-lg border px-3 py-2.5',
                        'border-slate-200' => ! $errors->has('handled'),
                        'border-red-300 bg-red-50' => $errors->has('handled'),
                    ])>
                        <input type="checkbox" wire:model="handled"
                               class="mt-0.5 size-4 rounded border-slate-300 text-brand-500 focus:ring-brand-500">
                        <span class="text-sm">
                            <span class="font-medium text-shell-900">Incidencia atendida</span>
                            <span class="block text-xs text-slate-500">
                                Queda marcada en el listado con tu nombre y la fecha. Guardar el comentario
                                pide marcarla, y
                                @if ($gestion->isHandled())
                                    <strong>al desmarcarla se borra también el comentario</strong>.
                                @else
                                    al desmarcarla después se borrará también el comentario.
                                @endif
                            </span>
                        </span>
                    </label>

                    @error('handled')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Quién y cuándo, si ya estaba atendida: es la mitad del dato,
                     y sin ella «atendida» no le sirve a quien llega después. --}}
                @if ($gestion->isHandled())
                    <p class="text-xs text-slate-500">
                        Atendida por
                        <strong>{{ $gestion->handled_by_name ?? 'alguien que ya no está en el maestro' }}</strong>
                        el {{ $gestion->handled_at->translatedFormat('j \d\e F \d\e Y \a \l\a\s H:i') }}.
                    </p>
                @endif
            </form>

            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="cancelManagement" wire:loading.attr="disabled">
                    Cancelar
                </x-ui.button>

                <x-ui.button type="submit" form="form-gestion"
                             wire:loading.attr="disabled" wire:target="saveHandling">
                    <span wire:loading.remove wire:target="saveHandling">Guardar</span>
                    <span wire:loading wire:target="saveHandling">Guardando…</span>
                </x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
