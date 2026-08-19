<?php

namespace App\Http\Controllers\Api;

use App\Models\IncidentRun;
use App\Models\Merchant;
use App\Models\PickupRoute;
use App\Models\RunPackage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Recibe la jornada del bot: CONTEXTO.md §3.1.
 *
 * **La jornada entera en cada envío, nunca filas sueltas** (§3.1, regla 1). De
 * ahí que esto sea idempotente: reenviar el 03/08 deja el mismo estado que la
 * primera vez, porque el bot puede repetir una corrida y lo hace.
 *
 * Lo que eso obliga a hacer aquí, y es el corazón del método:
 *
 * - *Upsert* por `(jornada, expedicion)`, no `insert`. Con `insert` un reenvío
 *   duplicaría el día entero.
 * - Las que dejan de venir se marcan **retiradas, no se borran**. Si el bot
 *   corrige una jornada y una fila desaparece, hay que poder ver que existió y
 *   dejó de estar — coherente con los borrados pasivos de §4.
 * - Todo dentro de una transacción: media jornada guardada sería peor que
 *   ninguna, porque nadie sabría que está a medias.
 *
 * **Dos listas, y `paquetes` es opcional a propósito.** `incidencias` es lo que
 * el bot manda desde el primer día. `paquetes` —todos los evaluados, con
 * incidencia o sin ella— se añadió el 13/08/2026 para que la pantalla pueda
 * enseñar la ruta completa («94 paquetes, 11 con incidencia») y no sólo lo que
 * salió mal. Mientras el bot no la mande, el panel guarda como siempre: un bot
 * viejo no puede romperse por un campo que no conoce.
 *
 * El 4xx es definitivo: el bot no reintenta un 422 (§3.1, regla 4), así que un
 * payload mal formado tiene que decir qué campo falla o el fallo se queda mudo
 * en el otro lado.
 */
class IncidentIntakeController
{
    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->json()->all();

        $validator = Validator::make($payload, [
            'version' => ['required', 'integer'],
            'corrida' => ['required', 'array'],
            'corrida.fecha' => ['required', 'date_format:Y-m-d'],
            'corrida.generado' => ['required', 'date'],
            'corrida.fiable' => ['required', 'boolean'],
            'corrida.maestro' => ['nullable', 'date'],
            'corrida.tolerancia_min' => ['required', 'integer', 'min:0'],
            'corrida.umbral_tanda_min' => ['required', 'integer', 'min:0'],
            'corrida.envios' => ['required', 'integer', 'min:0'],
            'corrida.evaluados' => ['required', 'integer', 'min:0'],
            'corrida.incidencias' => ['required', 'integer', 'min:0'],
            'corrida.sin_hora_cinta' => ['required', 'integer', 'min:0'],
            'corrida.sin_ruta' => ['required', 'integer', 'min:0'],

            'incidencias' => ['present', 'array'],
            'incidencias.*.expedicion' => ['required', 'string', 'max:255'],
            'incidencias.*.comercio.nombre' => ['required', 'string', 'max:255'],
            'incidencias.*.tipo' => ['required', 'in:'.RunPackage::TYPE_OTHER_ROUTE.','.RunPackage::TYPE_OUT_OF_BATCH],
            'incidencias.*.confianza' => ['required', 'in:'.RunPackage::CONFIDENCE_HIGH.','.RunPackage::CONFIDENCE_LOW],
            'incidencias.*.motivo_confianza' => ['present', 'array'],
            'incidencias.*.rutas_compatibles' => ['present', 'array'],
            // Opcional: un bot anterior a la v3 del payload no la manda.
            'incidencias.*.rutas_misma_tanda' => ['sometimes', 'array'],

            // Opcional: un bot que no la mande sigue funcionando igual.
            'paquetes' => ['sometimes', 'array'],
            'paquetes.*.expedicion' => ['required', 'string', 'max:255'],
            'paquetes.*.comercio.nombre' => ['required', 'string', 'max:255'],
            'paquetes.*.tipo' => ['nullable', 'in:'.RunPackage::TYPE_OTHER_ROUTE.','.RunPackage::TYPE_OUT_OF_BATCH],
            'paquetes.*.confianza' => ['nullable', 'in:'.RunPackage::CONFIDENCE_HIGH.','.RunPackage::CONFIDENCE_LOW],

            // Metros cúbicos. Nulo cuando el portal no lo trae, nunca negativo. Opcional
            // porque un bot anterior al 13/08/2026 no lo manda.
            'paquetes.*.volumen_m3' => ['nullable', 'numeric', 'min:0'],
            'incidencias.*.volumen_m3' => ['nullable', 'numeric', 'min:0'],

            // Euros facturados sin IVA, de Envexpress. Nulo cuando el envío no aparece
            // allí, nunca negativo. Opcional porque un bot anterior a la v4 no lo manda.
            'paquetes.*.ganancia' => ['nullable', 'numeric', 'min:0'],
            'incidencias.*.ganancia' => ['nullable', 'numeric', 'min:0'],

            'alertas' => ['present', 'array'],
            'alertas.*.tipo' => ['required', 'string'],
            'alertas.*.texto' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            Log::warning('POST /api/incidencias rechazado por contrato.', [
                'errores' => $validator->errors()->toArray(),
            ]);

            return response()->json([
                'error' => 'El payload no cumple el contrato de §3.1',
                'detalle' => $validator->errors()->toArray(),
            ], 422);
        }

        $incidents = collect($payload['incidencias']);
        $packages = collect($payload['paquetes'] ?? []);

        // Dos expediciones iguales en la misma lista harían que el upsert se
        // pisara a sí mismo y el recuento mintiera. Es un error del emisor, así
        // que se rechaza en vez de quedarse con la última en silencio.
        foreach (['incidencias' => $incidents, 'paquetes' => $packages] as $nombre => $lista) {
            $duplicated = $lista->duplicates('expedicion');
            if ($duplicated->isNotEmpty()) {
                return response()->json([
                    'error' => 'El payload trae expediciones repetidas',
                    'detalle' => [$nombre => $duplicated->values()->all()],
                ], 422);
            }
        }

        $rows = $this->merge($packages, $incidents);

        $result = DB::transaction(fn () => $this->store($payload, $rows));

        Log::info('POST /api/incidencias guardado.', $result + [
            'por_confianza' => $incidents->countBy('confianza')->all(),
            'por_tipo' => $incidents->countBy('tipo')->all(),
        ]);

        return response()->json($result);
    }

    /**
     * Una sola lista de paquetes a guardar.
     *
     * `paquetes` manda cuando viene: es la jornada completa. Encima se superpone
     * lo que traiga `incidencias`, emparejando por expedición, para que el bot
     * pueda mandar los paquetes escuetos sin repetir en ellos los campos de la
     * incidencia. Si no viene `paquetes`, la jornada son las incidencias y ya
     * está — que es como funcionó hasta el 13/08/2026.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function merge(Collection $packages, Collection $incidents): Collection
    {
        if ($packages->isEmpty()) {
            return $incidents;
        }

        $porExpedicion = $incidents->keyBy('expedicion');

        return $packages->map(function (array $paquete) use ($porExpedicion) {
            $incidencia = $porExpedicion->get($paquete['expedicion']);

            return $incidencia ? array_replace($paquete, $incidencia) : $paquete;
        });
    }

    /** @return array<string, mixed> */
    private function store(array $payload, Collection $rows): array
    {
        $run = $this->storeRun($payload);

        // Qué ids existen de verdad aquí y ahora. El bot los tomó del maestro
        // que descargó esta mañana, así que casi siempre estarán todos; pero si
        // alguien forzó el borrado de un comercio entre medias, insertar una FK
        // muerta reventaría con un 500 y el bot reintentaría eso para siempre.
        // Se filtran y la fila entra igual, con su nombre copiado.
        $merchantIds = Merchant::withTrashed()
            ->whereIn('id', $rows->pluck('comercio.id')->filter()->unique())
            ->pluck('id')->flip();
        $routeIds = PickupRoute::withTrashed()
            ->whereIn('id', $this->routeIdsIn($rows))
            ->pluck('id')->flip();

        $created = 0;
        foreach ($rows as $row) {
            // updateOrCreate y no un upsert masivo: son ~500 filas una vez al
            // día contra una base local, y así los `casts` del modelo se aplican
            // solos en lugar de codificar el JSON a mano en el sitio equivocado.
            $model = RunPackage::updateOrCreate(
                ['incident_run_id' => $run->id, 'shipment_id' => (string) $row['expedicion']],
                $this->attributes($row, $merchantIds, $routeIds),
            );
            $created += (int) $model->wasRecentlyCreated;
        }

        // Las que ya no vienen en esta versión de la jornada. Se marcan, no se
        // borran. Y las que vuelven quedan des-retiradas por el upsert de
        // arriba, que escribe `withdrawn_at` a null.
        $withdrawn = $run->packages()
            ->whereNotIn('shipment_id', $rows->pluck('expedicion')->map(strval(...)))
            ->whereNull('withdrawn_at')
            ->update(['withdrawn_at' => now()]);

        return [
            'fecha' => $run->run_date->toDateString(),
            'recibidas' => $rows->count(),
            'incidencias' => $rows->filter(fn (array $r) => filled($r['tipo'] ?? null))->count(),
            'nuevas' => $created,
            'actualizadas' => $rows->count() - $created,
            'retiradas' => $withdrawn,
        ];
    }

    private function storeRun(array $payload): IncidentRun
    {
        $run = $payload['corrida'];

        return IncidentRun::updateOrCreate(['run_date' => $run['fecha']], [
            'payload_version' => $payload['version'],

            // `->utc()` no es cosmético: Laravel escribe la fecha con formato
            // `Y-m-d H:i:s`, sin zona, y Postgres la interpreta en la suya. Sin
            // convertir antes, un bot cuyo reloj no esté en UTC —el de hoy va en
            // -04:00— guardaría la hora corrida y nadie lo notaría.
            'generated_at' => Carbon::parse($run['generado'])->utc(),
            'master_generated_at' => $run['maestro'] ? Carbon::parse($run['maestro'])->utc() : null,

            'reliable' => $run['fiable'],
            'tolerance_minutes' => $run['tolerancia_min'],
            'batch_gap_minutes' => $run['umbral_tanda_min'],
            'shipments' => $run['envios'],
            'evaluated' => $run['evaluados'],
            'incidents_reported' => $run['incidencias'],
            'without_belt_time' => $run['sin_hora_cinta'],
            'without_route' => $run['sin_ruta'],
            'alerts' => $payload['alertas'],
        ]);
    }

    /** @return array<string, mixed> */
    private function attributes(array $row, Collection $merchantIds, Collection $routeIds): array
    {
        $assigned = $row['ruta_asignada'] ?? [];
        $observed = $row['ruta_observada'] ?? null;

        return [
            'barcode' => $row['codigo'] ?? null,
            'merchant_id' => $this->keep($row['comercio']['id'] ?? null, $merchantIds),
            'merchant_name' => $row['comercio']['nombre'],
            'assigned_route_id' => $this->keep($assigned['id'] ?? null, $routeIds),
            'assigned_route_name' => $assigned['nombre'] ?? null,
            'assigned_courier_name' => $assigned['mensajero'] ?? null,
            'observed_route_id' => $this->keep($observed['id'] ?? null, $routeIds),
            'observed_route_name' => $observed['nombre'] ?? null,

            // Nulo en un paquete que pasó donde debía: es lo que lo distingue de
            // una incidencia.
            'type' => $row['tipo'] ?? null,

            'belt_time' => isset($row['hora_cinta']) ? Carbon::parse($row['hora_cinta'])->utc() : null,
            'deviation_minutes' => $row['desvio_min'] ?? null,

            // Nulo si el portal no lo trajo. El bot ya convierte a nulo el cero que devuelve
            // GLS, porque ahí un cero es "no lo sé" y no "no ocupa nada" (ver la migración).
            'volume_m3' => $row['volumen_m3'] ?? null,

            // v4: lo facturado por el envío sin IVA. Nulo si no aparece en Envexpress —el
            // bot no inventa un cero—, y nulo también en toda jornada anterior a la v4, que
            // no traía el campo. Las dos cosas se leen igual: «no se sabe» (ver la migración).
            'net_revenue' => $row['ganancia'] ?? null,
            'compatible_routes' => $row['rutas_compatibles'] ?? [],

            // v3: quiénes descargaban en el mismo bloque. Ausente en los payloads
            // anteriores, y entonces queda `[]`: la pantalla lo pinta igual que si no
            // hubiera bloque compartido, que para una jornada v1/v2 es lo correcto —
            // el dato no se perdió, es que nunca vino.
            'batch_shared_routes' => $row['rutas_misma_tanda'] ?? [],
            'confidence' => $row['confianza'] ?? null,
            'confidence_reasons' => $row['motivo_confianza'] ?? [],

            // Si vuelve a venir, deja de estar retirada. Sin esto, corregir una
            // jornada dos veces dejaría enterrada una fila que sí está.
            'withdrawn_at' => null,
        ];
    }

    /** El id sólo si existe aquí; si no, null y nos quedamos con el nombre. */
    private function keep(mixed $id, Collection $existing): ?int
    {
        return $id !== null && $existing->has($id) ? (int) $id : null;
    }

    /** Todos los ids de ruta que menciona el payload, vengan de donde vengan. */
    private function routeIdsIn(Collection $rows): Collection
    {
        return $rows
            ->flatMap(fn (array $r) => [
                $r['ruta_asignada']['id'] ?? null,
                $r['ruta_observada']['id'] ?? null,
                ...collect($r['rutas_compatibles'] ?? [])->pluck('id'),
                ...collect($r['rutas_misma_tanda'] ?? [])->pluck('id'),
            ])
            ->filter()->unique()->values();
    }
}
