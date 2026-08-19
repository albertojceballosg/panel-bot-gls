# CONTEXTO.md — Panel de rutas de recogida GLS

> Documento de contexto de `panel-bot-gls`. **Es autosuficiente**: contiene todo lo
> necesario para entender y continuar el proyecto sin abrir ningún otro repositorio.
> Cuando algo dependa del repo del bot, se dice explícitamente.
>
> Si cambias una decisión de arquitectura o cierras una fase, **actualiza este documento
> en el mismo commit**. Es la memoria del proyecto; si envejece, deja de servir.

---

## 0. El negocio en cuatro párrafos

GLS es una empresa de paquetería. Una **agencia** (identificada por el código de pago
`326`) tiene furgonetas con mensajeros que hacen **rutas de recogida**: pasan por comercios
recogiendo paquetes y los llevan a la **cinta clasificadora** de la central de GLS.

A la agencia no le importa el destino del paquete — eso es de otra parte de GLS. Le importa
**su** parte: verificar que lo que recogió cada ruta llegó efectivamente a la cinta, y que
llegó **junto**, en la ventana horaria que le toca a esa ruta.

Existe un bot (repositorio `bot-gls`, Python + Playwright + cron) que cada día entra al
portal **GLS Atlas**, saca los envíos del pago `326`, obtiene la hora en que cada paquete
pasó por la cinta, los agrupa por ruta y marca como **incidencia** todo paquete que se
desvía más de una tolerancia (±20 min por defecto) de la mediana de su grupo. Si un paquete
de un comercio de la ruta 1 pasa por la cinta a la hora de la ruta 5, algo salió mal en la
recogida.

Para agrupar por ruta, el bot necesita saber **a qué ruta pertenece cada comercio**. Ese
dato **el portal de GLS no lo expone**: devuelve el nombre del remitente, no su ruta.
**Ese maestro es lo que sirve este panel.**

## 1. Por qué existe este proyecto

Hoy el maestro es un `rutas.xlsx` que el cliente entrega a mano. Dos problemas medidos
sobre los datos del 03/08/2026:

- **Envejece.** 490 de 983 envíos (**el 50 %**) eran de 61 comercios que no estaban en el
  maestro, y quedaron sin evaluar.
- **Se cruza por nombre, que es frágil.** El bot compara el nombre del remitente que
  devuelve el portal contra el nombre del Excel, normalizando y con *fuzzy match* cuando no
  hay coincidencia exacta. 22 de los cruces de ese día fueron *fuzzy*.

**Este panel existe para que el cliente mantenga el maestro él mismo**, y para que el cruce
pase a ser exacto cuando el comercio tenga código (ver `codigo` en §3).

## 2. Cómo encaja con el bot

```
  ┌──────────────────┐        GET /api/rutas           ┌──────────────────┐
  │  panel-bot-gls   │ ◀───── (1 vez al día, al  ────  │     bot-gls      │
  │  Laravel + PG    │        arrancar la corrida)     │  Python + cron   │
  │                  │                                 │                  │
  │                  │ ◀───── POST /api/incidencias ─  │                  │
  └──────────────────┘        (al terminarla — §3.1)   └──────────────────┘
```

**En las dos direcciones el bot es el cliente**, y no es casualidad: el panel es el que tiene
dirección estable y el bot el que corre a ratos bajo cron. Al arrancar la corrida tira del
maestro; al terminarla empuja el resultado.

**El bot tira, el panel no empuja.** Decidido así porque el bot corre en WSL2 con cron, sin
dirección estable: un `POST` del panel hacia el bot exigiría un proceso escuchando siempre,
un puerto abierto y una cola con reintentos para no perder cambios hechos mientras el bot
está apagado. Con el `GET` no hay actualizaciones perdidas — el bot coge el estado bueno del
momento exacto en que lo necesita.

**El panel puede caerse sin romper al bot.** Si la descarga falla, el bot sigue con el
último maestro válido que tiene en disco y lo registra en el log.

**La obligación simétrica de este repo:** `GET /api/rutas` **no debe depender de nada de la
interfaz** — ni de sesión, ni de layout, ni de Livewire. Es el producto; el panel es la
forma de alimentarlo.

## 3. El contrato

Acordado el 11/08/2026 entre ambos repos. **Cambiarlo obliga a tocar el bot**, así que no
se cambia sin coordinar.

```json
{
  "generado": "2026-08-11T19:40:00+02:00",
  "comercios": [
    { "nombre": "3COR CREATIONS SLU", "ruta": 1, "mensajero": "Benjamin GLS", "codigo": 287 },
    { "nombre": "COBO FAMILY, S.L.",  "ruta": 3, "mensajero": "Freddy GLS",   "codigo": null }
  ]
}
```

| Campo | Regla |
|---|---|
| `generado` | ISO-8601 con zona (`Europe/Madrid`). Informativo. |
| `nombre` | El nombre del comercio, **sin normalizar**. Lo normaliza el bot. |
| `ruta` | Número, o `null` si ese mensajero aún no tiene número asignado. **⚠️ Pendiente de renegociar: pasa a texto, ver abajo.** |
| `mensajero` | Nombre del mensajero que hace esa ruta. **Puede ser `null`** si la ruta no tiene mensajero asignado ahora mismo. |
| `codigo` | **Opcional** (`null` si falta). Es el `SourceDepartment` del portal — el número entre paréntesis de nombres como `(287) Good Id S.L`. |

Sobre `codigo`: es 1:1 con el comercio (comprobado sobre el 03/08 — 0 comercios con dos
códigos, 0 códigos con dos comercios). **Si viene, el cruce del bot pasa a ser exacto y
desaparece el *fuzzy*.** 11 de los 93 comercios del maestro actual no lo tienen.

### ✅ Cambio de contrato cerrado: `ruta` pasa de número a texto

**Decidido el 12/08/2026 en este repo, acordado con el repo del bot el 13/08/2026** (queda
anotado en su `CONTEXT.md` §11.4) **e implementado en los dos lados.**

Las rutas dejaron de ser un número estático para ser entidades con nombre libre, renombrables
desde el panel (§4). `1`…`6` pasan a ser etiquetas, no identidad. En consecuencia el endpoint
sirve `"ruta": "1"` (texto) donde el contrato original decía `"ruta": 1` (número).

**El endpoint lo sirve así** desde el 12/08/2026 (fase 2), y el test de contrato lo fija:
`"ruta": "1"`, texto.

Los tres puntos que quedaban por comprobar en `bot-gls` están los tres en su código
—verificado el 17/08/2026 leyendo `../bot-gls`, commits `c2ecb00` y `d34d32c`—:

- **La clave de agrupación admite texto.** `src/rutas.py` declara `ruta: str | None` y agrupa
  por una `etiqueta_ruta()` común, que se extrajo justo al pasar de número a texto porque el
  informe y el análisis la calculaban cada uno con su `float(ruta)`. Era el riesgo real: con
  `int`, `"1"` colaba por coerción y `"Vallecas"` no, y eso es un fallo silencioso de los que
  §3 llama peores que romperse.
- **Los informes no la formatean como número**: una ruta llamada `"1"` sigue rindiendo
  «Ruta 1», así que los listados de siempre se leen igual.
- **`mensajero: null` se acepta**, que es lo que puede pasar cuando una ruta se queda sin
  conductor.

El `rutas.xlsx` queda de recambio y ya no se usa en la corrida normal (fase 6, bot B).

### Segundo cambio: el endpoint añade el `id` de cada ruta y cada comercio

**Acordado el 13/08/2026 entre ambos repos, y hecho en los dos el mismo día** — aquí es la
fase 6.A y allí bot C. El orden de claves que sirve el endpoint es `id, nombre, ruta_id, ruta,
mensajero, codigo`.

```json
{ "id": 42, "nombre": "3COR CREATIONS SLU", "ruta": "1", "ruta_id": 3,
  "mensajero": "Benjamin GLS", "codigo": 287 }
```

Nace de §3.1: sin identificador, una incidencia sólo puede señalar una ruta por su etiqueta,
y como las rutas son renombrables (el cambio de arriba), renombrar una descoloca todas las
incidencias ya guardadas. El bot conserva los ids junto al maestro y los devuelve al subir
las incidencias, de modo que cada una enlaza con su entidad real en vez de casar cadenas de
texto — que es justo el problema que ya sufre el cruce por nombre contra el portal.

**El `nombre` sigue siendo obligatorio y no cambia de papel**: el portal sólo da nombres, así
que el cruce del bot se sigue haciendo por ahí. El `id` es para el camino de vuelta.

Los ids son los de `pickup_routes.id` y `merchants.id` (§4). Al ser identidad, **no se
reciclan**: si un comercio se da de baja y vuelve, es el mismo id o es otro comercio, nunca
un id reutilizado por un tercero.

### Reglas acordadas que este repo debe respetar

1. **Siempre la lista completa**, nunca altas/bajas incrementales. Es idempotente y no se
   puede desincronizar. Con deltas, un mensaje perdido deja el maestro mal para siempre y
   sin rastro.
2. **El bot valida antes de aceptar**: rechaza lista vacía, nombres que colapsan al mismo
   normalizado con rutas distintas, `ruta`/`mensajero` ausentes; y avisa si el número de
   comercios cae de golpe (93 → 4 es un envío truncado, no una decisión). El panel no puede
   romper al bot, pero **sí puede hacerle producir un informe equivocado en silencio**, que
   es peor — de ahí las validaciones de §4.
3. **El `rutas.xlsx` sigue siendo el recambio en el bot**: si hay JSON usa el JSON, si no,
   el Excel. La migración no rompe nada.

### 3.2 `parametros`: lo que el cliente ajusta del análisis (17/08/2026)

`GET /api/rutas` sirve, junto al maestro, los ajustes que el cliente controla desde
`/settings/bot`:

```json
{ "generado": "…", "parametros": { "semiancho_min": 14 }, "comercios": [ … ] }
```

Hay dos:

| Clave | Qué es | Defecto del bot | Rango |
|---|---|---|---|
| `semiancho_min` | La ventana de atribución, en minutos a cada lado del pico de descarga de una ruta. | ±10 | entero **> 0**, sin tope |
| `dias_atras_ganancia` | Cuántos días **hacia atrás** pide el bot en Envexpress, además del día que analiza, para encontrar la ganancia de cada envío. | 3 | entero **de 0 a 30** |

`dias_atras_ganancia` es de la fase 14 y **este panel ya lo sirve desde el 19/08/2026**
—módulo «Rentabilidad» en Configuraciones—, aunque **el bot todavía no lo lee**: hasta que lo
haga, corre con su 3. No comparte todas las reglas del primero, aunque viaje por el mismo
sitio:

- **El cero es válido** y significa «sólo el día que se analiza». En `semiancho_min` un cero
  sería una ventana que no contiene nada; aquí es una opción legítima.
- **Sí lleva tope, al contrario que el otro.** Cada día de ventana es otro día de listado que
  Envexpress tiene que devolver —uno solo ronda los 4 MB—, así que un número disparatado no es
  un ajuste agresivo: es una petición que muere en el timeout y una jornada sin ganancia. El
  máximo de 30 cubre cualquier puente.
- **Por qué existe:** en Envexpress el envío lleva la fecha de su **creación** y en GLS la de la
  **recogida**. El lunes 10/08/2026, 103 de los 997 paquetes del día se habían creado el
  domingo; sin mirar hacia atrás se quedan sin ganancia. Mirar hacia adelante no sirve de nada:
  no se recoge un envío antes de crearlo.

**Va aquí y no en un endpoint propio** porque el bot guarda esta respuesta en disco y la
reutiliza cuando el panel no contesta: el parámetro hereda esa tolerancia a caídas sin
necesitar su propia caché.

**La clave se omite si nadie la ha configurado.** Es la regla de `settings` llevada al
contrato: el panel no inventa defectos, y mandar `""` o `0` le daría al bot un valor que
nadie eligió — con `0`, `semiancho_min` correría con una ventana que no contiene nada.
`ApiContractTest` lo fija en los dos sentidos: ausente cuando no hay valor, y entero (no
cadena) cuando lo hay.

⚠️ **Ojo al omitir: `dias_atras_ganancia` sí puede valer `0`, y ese cero tiene que viajar.**
Las dos cosas conviven en el mismo `array_filter` del controlador, que descarta **nulos** y no
falsy — `array_filter($x)` a secas se llevaría el cero por delante y el ajuste «sólo el día»
no llegaría nunca. Hay un test dedicado, porque ese callback parece eliminable y no lo es.

**Sin tope por arriba** (acordado el 17/08/2026): sólo entero positivo. Quien lo configura
conoce su operación mejor que nosotros. Lo que sí hay es rastro — el cambio queda en
`audit_logs` como cualquier otro ajuste, y el bot devuelve en cada jornada
(`corrida.semiancho_min`) con qué valor la calculó.

**Conviene saber qué hace, porque no es un dial de sensibilidad.** Medido sobre el 10/08/2026:
con ±25 en vez de ±10, las Rutas 3 y 4 **desaparecen** del reparto de acusaciones — sus
ventanas se solapan tanto que dejan de ser la única compatible y el bot deja de nombrarlas.
El número de incidencias no cambia; cambia a quién se señala.

---

## 3.1 El contrato de subida: incidencias

> ⚠️ **En fase de ajuste: este contrato puede cambiar.** Se documenta el 13/08/2026 para
> poder construir los dos lados contra la misma forma, no porque esté cerrado. De ahí el
> `version` en la raíz del payload: cuando cambie, se sube el número.

`POST /api/incidencias`, mismo `VerifyBotToken` que el maestro, una vez al día al terminar la
corrida.

**Estado (13/08/2026): implementado y guardando.** `IncidentIntakeController` valida, guarda
en `incident_runs` + `run_packages` y responde `200` con el balance
(`recibidas`/`nuevas`/`actualizadas`/`retiradas`). Probado de punta a punta con el 03/08 real
del bot: 168 incidencias, 8 alertas, los 168 `merchant_id` resueltos. `401` sin token y `422`
con el detalle de qué campo falla.

**Qué manda el bot y qué no.** En esta primera versión, **sólo las incidencias** más el
resumen que las enmarca. Se da por hecho que crecerá. Una "incidencia" aquí es una cosa muy
concreta: *un paquete pasó por la cinta en la tanda de otra ruta* — o sea, lo recogió quien no
le tocaba. No es el desvío horario contra la mediana, que el bot calcula aparte y manda como
campo de apoyo.

> **`version` va por 4 desde el 19/08/2026, y los dos lados la hablan.** La v4 **añade un solo
> campo**: `ganancia` en cada elemento de `paquetes[]` —y por herencia en `incidencias[]`, que
> se construye encima—. Es aditiva: una v3 sigue entrando y queda con la ganancia a nulo.
> **Este panel la guarda en `run_packages.net_revenue` y la enseña por ruta desde el
> 19/08/2026** (fase 13); fijado en `IncidentIntakeV4Test`. El bot la saca de **Envexpress
> (Mensaglobal)**, el otro portal de la agencia, cruzando por el código de barras que ya venía
> en el contrato como `codigo`. Este panel no habla con Envexpress ni tiene por qué: le llega
> ya cruzado. La fase 13 de §7 lo desarrolla.
>
> **Lo que la v4 NO trae, por decisión del 19/08/2026:** ni el `coste` —se pidió la ganancia,
> el margen ya se verá— ni totales de jornada. Los dos son aplazamientos conscientes; el
> segundo tiene consecuencias para la pantalla, ver §8.
>
> **`version` va por 3 desde el 17/08/2026.** La v3 **añade** `rutas_misma_tanda`: las rutas
> que descargaban en el mismo bloque que el paquete. Es aditiva —una v2 sigue entrando— y
> nace de la pantalla: un hallazgo no concluyente por `tanda_compartida` se explicaba con
> *«dos furgonetas descargaron juntas»* sin decir cuáles, y había que ir a buscarlo a las
> alertas. Se guarda en `run_packages.batch_shared_routes`. **Las dos listas no se mezclan:**
> `rutas_compatibles` es el instante del paquete y `rutas_misma_tanda` el bloque de media
> hora; `IncidentPresenter::reasons()` da a cada motivo la suya. Fijado en
> `IncidentIntakeV3Test`.
>
> **La v2, del mismo día.** La forma del JSON **no cambió**; cambió lo que
> significan `ruta_observada`, `rutas_compatibles` y `motivo_confianza`, porque el bot dejó de
> atribuir una tanda a la ruta con más paquetes dentro y pasó a usar la **ventana** de cada
> ruta (su pico de densidad ±10 min). Lo que hubo que tocar aquí: la frase de
> `ventana_compartida` en `IncidentPresenter` y la etiqueta de `rutas_compatibles` en el
> detalle de la jornada. `IncidentIntakeController` **no** necesitó cambios —`version` sólo se
> valida como entero y `motivo_confianza` no tiene lista cerrada—, y el calendario de
> capacidad tampoco, porque sólo mira `volume_m3`. Fijado en `IncidentIntakeV2Test`.
>
> **Conviven las dos.** Ninguna pantalla mira el `payload_version` de su corrida, así que las
> jornadas guardadas en v1 se siguen viendo con los textos nuevos: por eso la etiqueta dice
> «Otras rutas compatibles a esa hora», que es cierta bajo las dos.

```json
{
  "version": 3,
  "corrida": {
    "fecha": "2026-08-10",
    "generado": "2026-08-11T09:14:03+02:00",
    "fiable": true,
    "maestro": "2026-08-11T07:00:00+02:00",
    "tolerancia_min": 20,
    "umbral_tanda_min": 5,
    "envios": 646, "evaluados": 520, "incidencias": 66,
    "sin_hora_cinta": 59, "sin_ruta": 67
  },
  "incidencias": [
    {
      "expedicion": "1334043165",
      "codigo": "61326305203862",
      "comercio": { "id": null, "nombre": "COBO FAMILY, S.L." },
      "ruta_asignada":  { "id": null, "nombre": "Ruta 3", "mensajero": "Freddy GLS" },
      "ruta_observada": { "id": null, "nombre": "Ruta 1" },
      "tipo": "tanda_de_otra_ruta",
      "hora_cinta": "2026-08-03T19:52:52+00:00",
      "desvio_min": 22.3,
      "volumen_m3": 0.129,
      "ganancia": 8.60,
      "rutas_compatibles": [],
      "confianza": "baja",
      "motivo_confianza": ["ruta_dispersa"]
    }
  ],
  "alertas": [
    { "tipo": "ruta_dispersa", "texto": "…", "rutas": [ { "id": null, "nombre": "Ruta 6" } ] }
  ]
}
```

| Campo | Regla |
|---|---|
| `version` | Entero. Sube cuando cambie la forma del payload. |
| `corrida.fecha` | El día analizado, `aaaa-mm-dd`. Es media clave natural. |
| `corrida.fiable` | Si `false`, esa corrida no pudo consultar bastantes envíos. **Hay que enseñarlo en la interfaz**, ver abajo. |
| `corrida.maestro` | El `generado` del maestro que sirvió este endpoint y con el que se evaluó. Permite saber si una incidencia discutida salió de un maestro viejo. |
| `expedicion` | El identificador del envío en GLS. La otra media clave natural. |
| `codigo` | El código de barras. Informativo, para buscar el paquete en el portal. |
| `ruta_asignada` | La ruta que el maestro **de este panel** dice que le toca a ese comercio. |
| `tipo` | `tanda_de_otra_ruta` (pasó en la tanda principal de otra ruta: hay a quién señalar) \| `fuera_de_tanda` (no pasó con el grueso de su ruta, pero esa tanda no era de nadie en particular). |
| `ruta_observada` | Desde la v2: la ruta **en cuya ventana** pasó el paquete, y sólo si es la única que encaja (antes, la dueña de la tanda por mayoría). Es la acusación, y **es `null` cuando `tipo` es `fuera_de_tanda`** — 115 de las 193 del 10/08. Son dos hallazgos distintos: uno señala a alguien y el otro no. No mezclarlos en la misma lista. |
| `mensajero` | **Foto del día, no relación.** Texto, dentro de la ruta, a propósito: si el panel reasigna el conductor después, la incidencia debe seguir diciendo quién conducía aquel día. No enlazar contra `couriers` para pintarlo. |
| `volumen_m3` | Volumen del envío en m³ (columna `volume_m3`). Añadido el 13/08/2026. **Nulo, no cero, cuando el portal no lo trae**: GLS devuelve `0` en parte de los envíos —29 de 493 el 03/08— y ahí un cero significa «no lo sé». Al sumarlo, la interfaz **tiene que decir sobre cuántos envíos** se hizo la suma, o dará a entender que una ruta ocupa menos de lo que ocupa. Opcional: un bot anterior a esa fecha no lo manda. |
| `ganancia` | Desde la v4: **lo que se facturó por ese envío, sin IVA**, en euros. Sale de Envexpress: la suma de la columna `Precio` de sus valoraciones (servicio + suplementos). **No es el margen** — el margen es `ganancia − coste`. **Nula, no cero, cuando el envío no aparece en Envexpress**: 30 de 543 el 07/08/2026. Mismo criterio que `volumen_m3` y por el mismo motivo: un cero diría «no se ganó nada» y falsearía a la baja el total de una ruta. Quien la sume **tiene que decir sobre cuántos envíos**. Opcional: un bot anterior a la v4 no la manda. |
| `rutas_misma_tanda` | Desde la v3: las rutas cuya tanda principal era aquella en la que pasó el paquete — quiénes descargaban en ese bloque. **Incluye la propia ruta del paquete** si también descargaba ahí. Es lo que permite escribir *«Ruta 3 y Ruta 4 descargaron juntas»* en vez de *«dos furgonetas»*. Opcional: un bot anterior no la manda y queda `[]`. |
| `rutas_compatibles` | Desde la v2: las otras rutas **cuya ventana también contiene esa hora**, y **sólo cuando hay dos o más** — o sea, las que ese día no se distinguen entre sí. Vacío si el encaje fue inequívoco (la ruta va en `ruta_observada` y **no** se repite aquí; en la v1 sí venía duplicada, y sumar las dos listas la contaba dos veces) o si no encajó ninguna. |
| `confianza` | `alta` \| `baja`. |
| `motivo_confianza` | Lista, posiblemente con los tres: `ruta_dispersa` \| `tanda_compartida` \| `ventana_compartida` (nuevo en la v2). Los dos últimos no son lo mismo: `tanda_compartida` habla de la jornada —dos rutas descargaron en el mismo bloque— y `ventana_compartida` de la hora concreta de *ese* paquete, que cae en más de una ventana. Pueden darse por separado. Vacía si `confianza` es `alta`. |
| `id` | Desde el 13/08/2026 **llega poblado**: es el `merchants.id` o el `pickup_routes.id` de este panel. Sigue siendo opcional en el contrato —un maestro sin identificadores lo manda nulo— así que la persistencia debe saber casar por `nombre` cuando falte. |

### `confianza` es obligación de la interfaz, no un campo más

**Medido sobre el envío de prueba del 03/08/2026: de 168 incidencias, 160 llegan con
`confianza: baja`** — 133 por ruta dispersa, 10 por tanda compartida, 17 por ambas. Sólo 8 se
sostienen sin reservas. Una pantalla que las liste todas igual presentaría 160 sospechas que
el bot marca como no concluyentes con la misma autoridad que las 8 que sí lo son.

Es la parte que evita que esta herramienta señale a una persona sin fundamento. El bot ya
distingue dos situaciones en las que no puede afirmar quién recogió qué: cuando una ruta pasó
dispersa por la cinta, y cuando dos furgonetas descargaron seguidas y sus paquetes son
indistinguibles por hora. **El listado en texto que el cliente lee hoy lo anota explícitamente**
("(!) ruta dispersa: poco fiable", "(!) esa tanda la compartían varias rutas").

Si el panel pinta *"Vallecas — 48 paquetes de otra ruta"* sin ese matiz, convierte una
sospecha declarada en un hecho firme contra un mensajero. Un panel que pierde el matiz es
peor que no tener panel. Lo mismo con `corrida.fiable`: una corrida dudosa no cubre todos los
envíos del día y no se puede leer como si fuera completa.

### Reglas que este repo debe respetar

1. **El bot manda la jornada completa**, nunca incidencias sueltas — la regla 1 de §3 en el
   otro sentido. Una corrida se puede repetir a mano, y el reenvío debe dejar el mismo estado
   sin duplicar nada.
2. ***Upsert* por `(fecha, expedicion)`.** Las incidencias que dejen de venir en un reenvío de
   esa fecha se marcan retiradas, **no se borran** — coherente con los borrados pasivos de §4.
   Así el contrato aguantó cuando el panel pasó a guardar estado de gestión, que era la
   decisión abierta cuando se escribió esto: la fase 9 lo cerró el 14/08/2026 en «atendida» y
   comentario, y **el *upsert* no los pisa** porque escribe una lista explícita de columnas, las
   del contrato. Hay un test que lo fija.
3. **No llegan datos personales y no hay que pedirlos.** El bot recorta por lista blanca: los
   CSV de su lado llevan nombre, dirección, teléfono y email del destinatario, y nada de eso
   hace falta para gestionar una incidencia de ruta. No ampliar el contrato con esos campos
   sin una razón de negocio y una política de retención (§10).
4. **El endpoint no depende de la interfaz**, igual que `GET /api/rutas` (regla 3 de
   `CLAUDE.md`). Y el 4xx tiene que ser honesto: el bot no reintenta un 422, así que un
   payload rechazado por contrato debe decir qué campo falla.
5. **`VerifyBotToken` sirve tal cual**, y su `reject()` ya no miente. ✅ Registraba a mano
   `"GET /api/rutas rechazado"`, que dejó de ser cierto en cuanto el middleware pasó a
   proteger también el `POST`; desde el 13/08/2026 compone el método y la ruta de la petición,
   así que el log dice a qué endpoint iba. Del token sigue sin escribirse nada, ni un prefijo
   (§10).

### Lo que faltaba en este repo — ✅ nada, desde el 13/08/2026

Aquí había tres puntos y los tres están hechos:

1. **La persistencia** → fase 6.B: `incident_runs` + `run_packages`, *upsert* por
   `(jornada, expedicion)` en una transacción y marcado de retiradas.
2. **La pantalla**, con la obligación de `confianza` → fase 6.C, y la gestión encima en la
   fase 9.
3. **El test de contrato** → `IncidentIntakeTest`, 23 casos: el `422` campo a campo, el `401`
   y el balance de un reenvío.

**Una corrección sobre lo que se pedía:** el `202` de aquel punto 3 **no existe**. El endpoint
responde `200` con el balance porque guarda dentro de la propia petición y no encola nada; un
`202` prometería un trabajo diferido que no hay.

Del otro lado tampoco queda nada por escribir: el bot emite `paquetes` y engancha el envío en su
corrida diaria (fase 6, bot D). Lo que falta es **volver a correrlo junto** — ver el «Orden de
ataque» de la fase 6.

Nada de esto llega a producción hasta que el bot alcance al panel por red, que es el pendiente
de §8 sobre dónde se despliega cada uno. En desarrollo el bot apunta a `http://localhost:8000`.

## 4. Modelo de datos

```
pickup_routes:  id, name (unique†), deleted_at, timestamps
couriers:       id, name (unique†), pickup_route_id (nullable, unique†),
                maximum_volume (decimal 8,3, nullable), deleted_at, timestamps
merchants:      id, name, normalized_name (generada, unique†),
                code (int, nullable, unique†), pickup_route_id, deleted_at, timestamps

† único sólo entre los vivos — ver "Borrados pasivos" más abajo.
```

```
pickup_routes 1──1 couriers      quién la conduce hoy
  │
  └──* merchants                  de qué se compone la ruta  ← el maestro
```

**Y el resto del esquema**, que no es el maestro pero completa el mapa. Cada tabla se explica
donde se decidió —el historial aquí abajo, las dos del bot en la fase 6.B, `settings` en la
fase 11—; esto es sólo para no tener que abrir las migraciones para saber qué hay:

```
users:          id, name, last_name (nullable), email (unique†), password,
                remember_token, deleted_at, timestamps
audit_logs:     id, user_id (nullable), user_email, action,
                auditable_type + auditable_id, before (jsonb), after (jsonb), created_at
incident_runs:  id, run_date (unique), payload_version, generated_at,
                master_generated_at, reliable, tolerance_minutes, batch_gap_minutes,
                shipments, evaluated, incidents_reported, without_belt_time,
                without_route, alerts (jsonb), timestamps
run_packages:   id, incident_run_id, shipment_id, barcode,
                merchant_id + merchant_name, assigned_route_id + assigned_route_name,
                assigned_courier_name, observed_route_id + observed_route_name,
                type, belt_time, deviation_minutes, volume_m3,
                net_revenue,
                compatible_routes (jsonb), confidence, confidence_reasons (jsonb),
                withdrawn_at, handled_at, handled_by, handled_by_name,
                handling_note, timestamps          único: (incident_run_id, shipment_id)
settings:       id, module, key, value (nullable), timestamps   único: (module, key)
```

**De estas cuatro últimas, ninguna lleva borrado pasivo**, y cada una por su motivo: el
historial no se borra nunca, las dos del bot las reescribe él cada mañana —y lo que desaparece
de un reenvío se marca con `withdrawn_at`, que hace el mismo papel— y un parámetro se cambia,
no se da de baja (fase 11). `users` sí lo lleva, con el mismo índice único parcial que el
maestro.

> **Idioma y nombres.** El código va en inglés —clases, tablas, columnas, métodos— y los
> comentarios y esta documentación en castellano (§5). El comercio es `Merchant` y no `Store`
> porque varios del maestro son importadores o mayoristas, no tiendas. La ruta es
> `PickupRoute` y no `Route` para no colisionar con `Illuminate\Support\Facades\Route`. **Las claves del JSON de §3 siguen en
> castellano**: son el contrato con el bot y renombrarlas obligaría a tocar `bot-gls` por un
> motivo puramente cosmético.

**La ruta es la entidad duradera; el mensajero es lo que rota.** Un mensajero deja la
empresa y entra otro, pero la ruta y sus comercios siguen siendo los mismos. Por eso
`pickup_routes` es una tabla propia con nombre libre, `couriers.pickup_route_id` dice quién
la lleva ahora, y el
comercio pertenece a la **ruta**, no a la persona. Dar de baja al mensajero no toca el
maestro: hay un test que lo fija.

**Por qué el comercio no apunta al mensajero.** Si lo hiciera, borrar a esa persona dejaría
al comercio huérfano de ruta, que es justo el dato que el bot necesita. Con
`merchants.pickup_route_id`
hay **una sola fuente de verdad**: es imposible que un comercio esté en una ruta distinta a
la que dice su ruta. El `mensajero` que pide el contrato sale derivado
(`Merchant → PickupRoute → Courier`, un `hasOneThrough`), no de una FK propia.

**`couriers.pickup_route_id` es único** porque el contrato sirve un solo `mensajero` por comercio
(§3): dos mensajeros en la misma ruta lo dejarían ambiguo. Es nullable —un mensajero recién
dado de alta puede no tener ruta— y en Postgres el índice único deja pasar varios NULL, así
que puede haber varios sin asignar.

**`run_packages.net_revenue` es la ganancia sin IVA del envío** (fase 13), `decimal(10,2)`
y **nullable**, donde nulo significa «no se encontró el envío en Envexpress», no «cero euros»
— exactamente el mismo trato que `volume_m3` y por el mismo motivo. Es la única columna que
la v4 añade: el coste no viaja (§3.1). **Ojo al sumarla:** esta tabla sólo guarda los envíos
**con** ruta, así que su suma es la ganancia de las rutas, no la del día. La del día no está
en este panel todavía (§8).

**`couriers.maximum_volume` es lo que cabe en la furgoneta, en m³.** Añadido el
13/08/2026, y lo usa el calendario de capacidades (§7, fase 6.E). Misma unidad y misma precisión —`decimal(8,3)`— que `volume_m3` de
`run_packages` (§3) a propósito: contrastar lo que una ruta arrastró contra lo que su
furgoneta admite tiene que ser una resta, no una conversión. Es nullable, y **nulo significa
«no se sabe», no «no cabe nada»**, igual que el volumen del envío; por eso la validación
exige `> 0` cuando se declara y deja el hueco vacío cuando no. El contrato de §3 no lo sirve: es
un dato del panel, no del bot.

**Las FK, y por qué cada una borra como borra.** `merchants.pickup_route_id` es
`restrictOnDelete`:
cargarse una ruta con comercios dentro tiene que ser una decisión explícita, no un efecto
colateral. `couriers.pickup_route_id` es `nullOnDelete`: borrar una ruta no puede borrar a una
persona. Con borrado pasivo ninguna de las dos llega a dispararse casi nunca; quedan como
red para el `forceDelete`.

### Borrados pasivos

Las tres tablas del maestro usan `SoftDeletes`, y también `users`. Dar de baja no destruye:
el maestro es el histórico del negocio y un borrado en falso es indistinguible de un error de
captura. En `users` sirve además para quitarle el acceso a alguien sin perder de quién era la
cuenta — un usuario dado de baja no pasa `Auth::attempt`, porque el proveedor de Eloquent
consulta el modelo y arrastra el scope de `SoftDeletes`. Las cuentas se mantienen desde el
panel (§7, fase 8); `users.last_name` es nullable porque las que ya existían no lo tienen.

**Los índices únicos son parciales, con `WHERE deleted_at IS NULL`.** No es un adorno: un
índice único normal cuenta también las filas dadas de baja, así que el sustituto de un
mensajero no podría heredar su ruta —la fila del saliente seguiría ocupando
`pickup_route_id`— ni
podría volverse a dar de alta un comercio con el nombre de uno retirado. El Blueprint de
Laravel no expresa índices parciales, de ahí el `DB::statement` en las migraciones.

**Las reglas de validación llevan `whereNull('deleted_at')`** para decir exactamente lo mismo
que el índice. Si no, el panel avisaría de un choque con un registro invisible que la base sí
deja crear.

**`PickupRoute` se niega a darse de baja si le quedan comercios vivos**, y eso vive en el modelo, no
en la FK: sin `DELETE` real no hay nada que restringir. Sin esa comprobación, dar de baja una
ruta dejaría a sus comercios apuntando a una ruta invisible — seguirían en la base, fuera del
maestro que consume el bot, y sin que nadie lo note. Que es justo la clase de fallo silencioso
que §3 llama peor que romperse.

**El seeder resucita**: si algo que estaba dado de baja vuelve a aparecer en el maestro de
origen, es que está vigente. Se revive en vez de crear una fila nueva.

### Historial de cambios

`audit_logs`: una fila por cambio, y no se actualiza ni se borra nunca — el modelo lo impide
en `booted()`. Cubre `PickupRoute`, `Courier`, `Merchant` y `User`.

**Una sola tabla para todas las entidades**, no una por modelo: cuatro tablas de historial
serían cuatro consultas para responder una sola pregunta.

**Polimórfica (`auditable_type` / `auditable_id`), no un `module` de texto libre.** Guarda la
clase del modelo, así que no se puede escribir mal; un typo en un string parte el historial
en dos sin que salte nada.

**En `UPDATE` sólo se guardan los campos que cambiaron**, y si el diff queda vacío no se
escribe fila. Un formulario que se guarda sin tocar nada es lo más normal del mundo, y ese
ruido enterraría los cambios de verdad. En alta y baja va el registro completo.

**`user_email` desnormalizado** además del `user_id`: el historial tiene que poder leerse
dentro de dos años, con el usuario dado de baja o con el correo cambiado. Sin sesión —seeder,
consola, cron— el autor queda como "Sistema", que es preferible a inventárselo.

**Los `$hidden` del modelo quedan fuera del historial** (§10): lo que no se expone en un JSON
tampoco debe acabar copiado en una tabla que no se borra nunca.

**El log se escribe sin `try`/`catch`.** Si no se puede registrar el cambio, el cambio no se
da por hecho: un maestro que se mueve sin dejar rastro es el fallo silencioso que §3 llama
peor que romperse.

Lo que **no** se construyó, habiendo un diseño de referencia que lo traía: resolvers de FK
—hay una sola FK que resolver, `pickup_route_id`—, `skipAudit` como concepto general —no hay
ni una escritura derivada—, log manual para documentos con líneas —no hay documentos— y panel
global de auditoría con filtros y paginación, que §5 ya descarta por tamaño.

**La columna generada.** Postgres es *case-sensitive*, así que la unicidad del nombre hay
que hacerla explícita:

```php
$table->string('name');                         // tal cual — es lo que sirve el JSON
$table->string('normalized_name')
      ->storedAs("upper(regexp_replace(trim(name), '\\s+', ' ', 'g'))");
// El índice va aparte y es parcial: ver "Borrados pasivos".
```

Se mantiene sola y no se puede desincronizar. **Alcance deliberadamente limitado:** es un
guardarraíl contra duplicados evidentes en el panel, **no** un sustituto del cruce del bot,
que además quita sufijos (`S.L` / `S.L.` / `SLU`) y hace *fuzzy*. Meter esa lógica también
aquí sería duplicarla en dos repos y que se separen con el tiempo.

### Datos de referencia (maestro de agosto 2026)

La columna "Ruta" son los **nombres** que el seeder les puso, tomados del Excel. Se pueden
renombrar desde el panel sin tocar nada más.

| Ruta | Mensajero | # comercios |
|---|---|---|
| 1 | Benjamin GLS | 21 |
| 2 | BORJA GONZALEZ | 7 |
| 3 | Freddy GLS | 21 |
| 4 | JOSE GLS | 9 |
| 5 | Pepe Rodriguez | 21 |
| 6 | Vallecas | 14 |

> Ojo si consultas la documentación del repo del bot: allí Vallecas figura como "sin nº de
> ruta, a confirmar con el cliente". **Está desactualizado** — en el `rutas.xlsx` actual
> Vallecas ya tiene la ruta 6. Verificado el 12/08/2026 leyendo el Excel.

## 5. Stack, y qué se descartó

| Capa | Elección |
|---|---|
| Entorno | Docker (todo: PHP, Node, Postgres). El host solo necesita Docker. |
| Framework | **Laravel 13.25.0** sobre **PHP 8.4** |
| UI | **Livewire 4.4.0** + **Tailwind 4** vía **Vite 8**, Blade escrito a mano |
| BD | **Postgres 17** |
| Auth del panel | Login propio, ~1 componente Livewire |
| Auth de la API | Bearer estático contra `config('panel.bot_token')` |
| Terceros | Solo `livewire/livewire` (más `laravel/tinker`, que trae el esqueleto) |

> **Livewire 4 usa componentes de fichero único**: la clase PHP y el Blade van juntos en
> `resources/views/components/⚡nombre.blade.php`, y el ⚡ se ignora al resolver el nombre
> (`⚡login.blade.php` → `login`). No hay `app/Livewire/`. Se enrutan con
> `Route::livewire($uri, $componente)`. Es lo que genera `artisan livewire:make`.
>
> Esta nota estaba **dentro** de la tabla hasta el 17/08/2026, y partía en dos su renderizado:
> las cuatro últimas filas salían como texto suelto. Un blockquote cierra la tabla.

**Lo descartado, y por qué** — para que nadie lo reabra sin motivo nuevo:

- **TailAdmin** (plantilla de admin, MIT, con variante Laravel oficial). Descartada por
  coste: traía ~10 dashboards y 500 componentes de demo para un panel de 2 tablas, sus
  widgets con JS propio se pelean con el diffing de Livewire (habría que ir marcando
  `wire:ignore`), y está pinneada a Laravel 12.
- **Filament.** Mismo argumento de peso: da el CRUD casi gratis, pero es maquinaria grande
  para dos tablas.
- **El starter kit oficial de Livewire.** Trae Flux UI más pantallas de registro, perfil y
  recuperación de contraseña que acabaríamos borrando. Para ~5 usuarios internos, el login
  son unas 60 líneas.
- **Laravel Sanctum.** Hay un único consumidor (el bot). Tokens en BD, revocables y con
  scopes, es infraestructura sin caso de uso. Un token estático en `.env` basta.
- **MySQL.** Preferencia por Postgres. Nota: el argumento de la *collation*
  case-insensitive era específico de MySQL; con Postgres se resuelve con la columna
  generada de §4.
- **PHP nativo en el host.** No hay PHP ni Composer en esta máquina, así que "nativo" era
  instalar y mantener un toolchain entero para un solo proyecto.

**`spatie/laravel-permission`, añadida el 18/08/2026.** Es la única dependencia que ha entrado
después del arranque, y entró **a petición del cliente y por nombre**. Lo que aporta y no
íbamos a escribir mejor: la tabla pivote de roles y permisos, la caché del registrar y el
enganche con el `Gate` de Laravel, que es lo que permite seguir usando el `can:` del framework
en vez de un middleware nuestro. Ver §7, fase 12.

**Lo que NO se va a construir** (dicho aquí para que no se cuele después): multi-tenant, API de
escritura, dashboard con gráficas. Son ~5 usuarios internos y dos tablas.

> Aquí ponía también **«roles y permisos»**, desde el 12/08/2026 y con el mismo argumento. Lo
> pidió el cliente el **18/08/2026** y se construyó: la fase 12. Queda escrito porque la razón
> de entonces no era mala —con ~5 personas que hacen lo mismo, los roles son ceremonia—; lo que
> cambió es que la pantalla de copias se lleva la base entera del cliente en un fichero (§10) y
> eso no es trabajo de todo el mundo.

## 6. Entorno de desarrollo

Tres servicios, en `docker-compose.yml`:

| Servicio | Imagen | Puerto | Para qué |
|---|---|---|---|
| `app` | `Dockerfile` (PHP 8.4 CLI + Composer) | 8000 | Laravel (`artisan serve`) y todos los comandos |
| `vite` | `node:22-bookworm-slim` | 5173 | Tailwind + hot reload. Node **no** está en la imagen de PHP a propósito: son ciclos de vida distintos. |
| `postgres` | `postgres:17-alpine` | 5432 | Base de datos |

Arranque desde cero: `./bootstrap.sh` (detalles en `README.md`). Es idempotente.

Un solo `.env` sirve a Compose y a Laravel: Compose interpola `DB_DATABASE`, `DB_USERNAME`
y `DB_PASSWORD` desde ahí, así que las credenciales tienen una única fuente de verdad. El
`UID`/`GID` del `.env` deben ser los tuyos (`id -u`, `id -g`) o todo lo que escriba el
contenedor queda como root.

Comandos del día a día:

```bash
docker compose exec app php artisan <lo que sea>
docker compose exec app composer <lo que sea>
docker compose exec app php artisan test
docker compose logs -f vite
```

## 7. Fases

| # | Qué | Estado |
|---|---|---|
| 0 | Docker, scaffold de Laravel, Livewire, Tailwind | **Hecha** (12/08/2026) |
| 1 | Migraciones, modelos y seeder | **Hecha** (12/08/2026) |
| 2 | `GET /api/rutas` + token + test de contrato | **Hecha** (12/08/2026). El cambio de §3 está también en el bot; falta repetir una corrida real de punta a punta |
| 3 | CRUD Livewire de comercios y mensajeros | **Hecha** (12/08/2026) |
| 4 | Historial de cambios | **Hecha** (12/08/2026), pantalla incluida |
| 5 | Imagen de producción y despliegue | **Pendiente** — es lo último a propósito |
| 6 | Cerrar el circuito con el bot: `id` en el maestro, guardar incidencias, pantalla, calendario de capacidades | **Hecha** (13/08/2026) salvo **6.D**, el backfill del `codigo` |
| 7 | Copias de seguridad | **Hecha** (13/08/2026) |
| 8 | Usuarios del panel | **Hecha** (14/08/2026) |
| 9 | Gestionar la incidencia: comentario y «atendida» | **Hecha** (14/08/2026) |
| 10 | Mi perfil, y los avisos flotantes | **Hecha** (14/08/2026) |
| 11 | Configuraciones por módulo, y el calendario leyéndolas | **Hecha** (14/08/2026; el calendario, el 17/08/2026) |
| 12 | Roles y permisos, con su maestro | **Hecha** (18/08/2026) |
| 13 | La ganancia por ruta: guardarla y enseñarla | **Hecha** (19/08/2026) salvo la decisión de si merece pantalla propia |
| 14 | Configuraciones → Rentabilidad: los días hacia atrás de la ganancia | **Hecha** (19/08/2026) en este repo; el bot todavía no lee la clave |

> La tabla se quedó en la fase 5 hasta el **17/08/2026**, con seis fases descritas más abajo y
> ninguna listada aquí: quien abría el documento por §7 leía que el proyecto iba por la mitad.
> Las fases de la 6 en adelante se numeraron según se pedían, así que **el número no es orden
> de ejecución**: producción es la 5 y sigue siendo lo último.
>
> Lo único que queda abierto de la 6 a la 11 es **6.D**, el backfill del `codigo`, que es mejora
> y no requisito (§8).

**La suite son 455 tests** (1.343 aserciones) el 19/08/2026, sobre Postgres. Es la cifra
que hay que ver pasar antes de dar cualquier cosa por terminada, no la inspección del código.

**El orden importa.** La fase 2 va antes que cualquier pantalla: es el producto real, y en
cuanto esté, el bot puede apuntar su `RUTAS_URL` a `http://localhost:8000/api/rutas` y
probarse de punta a punta con el panel todavía sin una sola vista.

### Fase 0 — Hecha el 12/08/2026

Verificado, no solo ejecutado: `app` responde 200 con `<title>Panel Bot GLS</title>`,
`vite` sirve `resources/css/app.css` con `createHotContext` (hot reload vivo), `postgres`
*healthy* con las 3 migraciones del esqueleto aplicadas, `APP_KEY` generada y los ficheros
con el dueño correcto (no root).

El esqueleto de Laravel 13 ya trae Tailwind 4 cableado (`@tailwindcss/vite` +
`@import 'tailwindcss'`), así que no hubo que tocarlo.

> **Corregido el 12/08/2026.** Aquí ponía que no hacía falta configurar `server.hmr.host`
> porque «con el 5173 publicado el navegador llega a Vite por localhost sin más». **Era
> falso**, y se vio en cuanto hubo una pantalla de verdad que abrir: el contenedor arranca
> con `--host 0.0.0.0`, el plugin de Laravel escribe eso mismo en `public/hot`, y Chrome
> rechaza `0.0.0.0` con `ERR_ADDRESS_INVALID`. La página cargaba sin CSS y sin hot reload.
>
> La verificación de la fase 0 tenía un hueco: se comprobó con `curl` que Vite servía el CSS
> **pidiéndoselo a `localhost:5173` directamente**, que funciona, pero no se miró qué
> dirección se le estaba diciendo al navegador que pidiese. Comprobar el servidor no es lo
> mismo que comprobar al cliente.
>
> Arreglado en `vite.config.js` con `hmr.host: 'localhost'` —lo que el navegador debe pedir,
> distinto de lo que escucha el contenedor— más `strictPort: true`, para que un 5173 ocupado
> falle en vez de saltar al 5174 y dejar la página muda.

### Fase 1 — Hecha el 12/08/2026

- Migraciones de `pickup_routes`, `couriers` y `merchants` según §4, con la columna generada, los
  índices únicos parciales, el borrado pasivo y las FK con el borrado que le toca a cada una.
- Modelos con `PickupRoute hasMany Merchant`, `PickupRoute hasOne Courier` y el `mensajero` de un
  comercio derivado por `hasOneThrough`, no por FK propia.
- `RouteMasterSeeder` carga los 93 comercios desde el CSV. **Ver §9: el fichero de origen
  no está en el repo.** Es idempotente y no pisa `code` al re-sembrar, para no borrar un
  backfill hecho a mano. Aborta si un mensajero aparece en dos rutas o una ruta con dos
  mensajeros: son los dos choques que dejarían el contrato ambiguo.
- Validación en `PickupRoute::rules()`, `Courier::rules()` y `Merchant::rules()` — en el modelo
  para que el CRUD de la fase 3 las reutilice en vez de reescribirlas: `name` obligatorio,
  `code` único cuando no es nulo, `pickup_route_id` existente y no ocupada por otro mensajero. El
  duplicado por mayúsculas se comprueba contra `normalized_name`, no contra `name`, o
  se colaría.

**El modelo se rehízo el mismo día**, antes de que hubiera nada montado encima: en el primer
diseño la ruta era un entero colgado del mensajero, y dar de baja a esa persona se llevaba
por delante la definición de la ruta. Las migraciones se reescribieron en su sitio en vez de
apilar `ALTER TABLE`, porque no hay nada desplegado y el histórico de una tabla que nunca
existió fuera de este repo no le sirve a nadie.

Verificado, no sólo ejecutado: `migrate:fresh --seed` deja **93 comercios en 6 rutas con 6
mensajeros** y el reparto exacto de la tabla de referencia de §4 (21/7/21/9/21/14), dar de
baja un comercio y volver a sembrar lo revive sin duplicar fila (92 → 93, y 93 contando los
borrados), y **21 tests** cubren los invariantes — incluidos los dos que motivaron los
cambios de diseño del día: dar de baja al mensajero deja la ruta y sus comercios intactos y
el sustituto hereda la ruta, y una ruta con comercios vivos no se deja dar de baja.

**Los tests pasaron a correr sobre Postgres.** El `phpunit.xml` del esqueleto usaba sqlite en
memoria, que no sabe ejecutar el `regexp_replace` de la columna generada: sobre sqlite no se
puede ni migrar. Se comprobó con un test de sonda antes de cambiarlo. Ahora usan la base
`panel_testing`, que `bootstrap.sh` crea si no existe; host y credenciales salen del `.env`,
así que en `phpunit.xml` no hay ninguna clave.

### Fase 2 — El endpoint. Código hecho el 12/08/2026

- `GET /api/rutas` → `RouteMasterController`, invocable y sin estado. La URL se queda en
  castellano y las claves del JSON también: son el contrato, no nombres nuestros.
- `VerifyBotToken` compara el `Authorization: Bearer …` contra `config('panel.bot_token')`
  (que lee `RUTAS_TOKEN` del `.env`) con `hash_equals`, en tiempo constante. `401` si no
  cuadra, y **también si el token no está configurado**: `hash_equals('', '')` es `true`, así
  que sin esa comprobación un despliegue que se olvide del `RUTAS_TOKEN` dejaría el maestro
  del cliente abierto a cualquiera. Cerrado por defecto.
- Registrado en el grupo `api` de `bootstrap/app.php`, que no arrastra sesión ni CSRF.
- **Test de contrato en `ApiContractTest`** (14 casos): forma del JSON, que las claves sigan
  en castellano, tipos (`codigo` entero de verdad, no `"287"`), `mensajero`/`codigo` nulables,
  nombre sin normalizar, lista siempre completa, bajas fuera, `401` en los tres escenarios de
  token, y que no haya N+1.

**Va en PHPUnit y no en Pest**, que §7 pedía: Pest no está instalado y añadirlo choca con la
regla 2 de `CLAUDE.md`. Los 42 tests del repo usan PHPUnit; lo que da valor aquí es la
cobertura del contrato, no el framework. Si se quiere Pest, es una decisión aparte.

**Dos cosas que encontró el propio test y que estaban mal desde el esqueleto:**

1. `config/app.php` traía `'timezone' => 'UTC'` a fuego y **no leía `APP_TIMEZONE`**, así que
   el `Europe/Madrid` del `.env` no hacía nada y `generado` habría salido en UTC, incumpliendo
   §3. Ahora es `env('APP_TIMEZONE', 'UTC')`.
2. El `phpunit.xml` no fijaba zona, así que los tests corrían en UTC — el mismo error que el
   de sqlite: probar en algo distinto a lo que se despliega.

Verificado con peticiones reales, no sólo con tests: `401` sin token y con token incorrecto,
`200` con el bueno, 93 comercios, `"generado": "2026-08-12T15:31:27+02:00"` y las seis rutas.

### Fase 3 — CRUD

Partida en módulos, y se entregan de uno en uno. El orden no es arbitrario: **rutas va antes
que mensajeros y comercios** porque los dos la referencian, y el historial va al final porque
necesita pantallas donde enchufarse.

| # | Módulo | Estado |
|---|---|---|
| 0 | Layout, navegación y estilos | **Hecho** (12/08/2026) |
| 1 | Login | **Hecho** (12/08/2026) |
| 2 | CRUD de rutas | **Hecho** (12/08/2026) |
| 3 | CRUD de mensajeros: nombre y ruta que conduce, que puede quedar sin asignar | **Hecho** (12/08/2026) |
| 4 | Comercios: listado con búsqueda y filtro por ruta, paginación, alta/edición de nombre, código y **ruta** (no mensajero: el comercio pertenece a la ruta, §4) | **Hecho** (12/08/2026) |
| 5 | ~~Historial en la ficha de cada registro~~ | **Retirado**: lo cubre el módulo 6 |
| 6 | Listado de auditoría | **Hecho** (12/08/2026) |

Blade y Tailwind escritos a mano; nada de librerías de componentes.

**El módulo 5** es la cara visible de la fase 4, que ya está construida por debajo: un parcial
reutilizable que reciba `$model->auditLogs()` y pinte la línea de tiempo con "Campo / Antes /
Después". Necesita un mapa de etiquetas por entidad (`pickup_route_id` → «Ruta») para no
enseñar IDs crudos; con una sola FK que resolver, es un array pequeño, no un sistema.

#### Módulos 0 y 1 — Hechos el 12/08/2026

- `components/layouts/app.blade.php` (con sesión) y `guest.blade.php` (sin ella).
- `⚡login.blade.php`: `Auth::attempt`, "mantener la sesión abierta", y **limitación de cinco
  intentos por minuto y correo**, sin la cual el formulario es una puerta abierta a probar
  contraseñas a ritmo de máquina. La clave del contador lleva también la IP para que nadie
  pueda bloquear la cuenta de otro.
- **Un solo mensaje de error** para "ese correo no existe" y "contraseña incorrecta":
  distinguirlos confirma qué correos tienen cuenta a quien pruebe uno por uno.
- `session()->regenerate()` al entrar y `invalidate()` al salir, contra la fijación de sesión.
  Salir es `POST`: con `GET` lo dispara cualquier enlace de fuera, o lo precarga el navegador.
- Portada escueta con los totales del maestro. §5 descarta el dashboard con gráficas.
- Se borraron dos restos del esqueleto que ya mentían: `welcome.blade.php`, al que no llegaba
  nadie, y el `ExampleTest` que afirmaba que `/` devuelve 200 cuando ahora exige sesión.

Verificado con una sesión HTTP real, no sólo con tests: `GET /` sin sesión redirige a
`/login`, el POST al endpoint de Livewire entra y redirige, y `GET /` ya autenticado pinta
93 comercios, 6 rutas y 6 mensajeros. La build de producción de Tailwind genera las clases de
los ficheros nuevos, emoji en el nombre incluido.

#### Módulo 2 — CRUD de rutas. Hecho el 12/08/2026

Alta, edición, baja y reactivación. Las dadas de baja se ocultan tras una casilla: son la
excepción. La baja de una ruta con comercios la corta el modelo (§4) y la pantalla la
convierte en un aviso legible en vez de en un error de servidor.

**Lenguaje visual.** Referencia de TailAdmin —barra lateral oscura y ancha, acento
azul-índigo, tarjetas de borde suave— **como estilo, no como dependencia**: §5 lo descartó
por su peso, y copiar el aire no instala nada. El acento y los grises de la carcasa viven en
`@theme` de `app.css`, en un solo sitio.

**Primitivas en `components/ui/`**: `button`, `field`, `input`, `card`, `alert`,
`page-header`, `empty-state`, `nav-link`. Cada una hace una cosa y son las que evitan repetir
Tailwind pantalla a pantalla. Los enlaces de la barra lateral se declaran en un array del
layout: **añadir un módulo es una línea**, no tocar el marcado.

> **Ampliado el 13/08/2026 con «Operaciones».** La barra pasa a tener dos listas porque son
> dos cosas distintas: `$modulos` es el maestro que el cliente mantiene —siempre a la vista,
> es el trabajo del día— y `$grupos` son bloques plegables para lo que se consulta a ratos,
> hoy sólo Incidencias. Sigue siendo una línea por pantalla.
>
> `ui/nav-group` recibe las rutas de sus hijos y **nace abierto si estás dentro de una de
> ellas**. No es un detalle: `wire:navigate` reconstruye la página entera y el estado de
> Alpine no sobrevive al salto, así que sin eso el grupo se cerraría justo al llegar a la
> pantalla que acabas de abrir desde él.
>
> De paso, **`x-cloak` se usaba en el layout desde el módulo 0 sin estar definido en CSS**, así
> que no hacía nada: el velo oscuro de la barra en móvil se pintaba un instante en cada carga
> antes de esconderse. La regla está ahora en `app.css`.

> **Corregido el 13/08/2026: el arreglo de móvil del módulo 2 estaba a medias.** Aquí ponía
> que la tabla que se salía de la pantalla quedó resuelta con `overflow-x-auto`. Resuelve la
> mitad —la tabla scrollea dentro de su caja— pero **la página entera seguía arrastrándose**:
> medido a 390 px, `/merchants` se movía 356 px en horizontal dejando media pantalla en
> blanco, y lo mismo `/pickup-routes` y `/audit-logs`. Se vio al medirlo, no al mirarlo: la
> captura de móvil sale idéntica hasta que arrastras.
>
> El navegador propaga el ancho de la tabla al documento aunque su envoltorio scrollee. Lo
> corta `contain: paint`, en `app.css` sobre `.overflow-x-auto` para que valga en las cuatro
> pantallas y lo herede la siguiente. **No sirvieron** `overflow-x: hidden` en `main`, en
> `body` ni en la raíz, ni `max-width: 100%` ni `min-width: 0` en el envoltorio; están
> probados uno a uno.
>
> Y la cabecera superior pasa a nombrar la sección en la que estás. Decía «Maestro de rutas de
> recogida» en todas las pantallas, que dejó de ser verdad al entrar Operaciones. Sale de las
> mismas listas del layout, así que una pantalla nueva no tiene que registrarse en dos sitios.

**No se extrajo todavía la abstracción común de los CRUD.** Con un solo ejemplo escrito se
adivinaría mal; sale cuando el de mensajeros enseñe qué se repite de verdad.

**Verificado mirándolo**, no sólo con tests: se condujo un Chromium headless contra el panel
—reutilizando el Playwright que ya tiene el repo del bot, sin instalar nada— para entrar,
recorrer las pantallas y capturarlas. Eso destapó tres cosas que ni `curl` ni los tests veían:
la concordancia de número del mensaje de baja («1 comercios»), que la tabla se salía de la
pantalla en móvil, y restos de depuración en la base de desarrollo. Las tres, corregidas.

> **Lección, que ya van dos.** La fase 0 dio por bueno el hot reload sin abrir un navegador, y
> era falso. Aquí lo mismo: el `curl` dice que el servidor responde, no que la pantalla se
> vea. Para cualquier cosa con interfaz, hay que mirarla.

**Ampliado el 12/08/2026** con lo que se le pidió al módulo, y que marca el patrón para los
tres CRUD siguientes:

- **Alta y edición en un modal** (`ui/modal`), no en un panel sobre la tabla.
- **Editar y dar de baja como iconos** (`ui/icon-button`). El `label` es obligatorio en la
  primitiva: al quitar el texto visible, sin él la acción queda muda para un lector de
  pantalla y sin pista al pasar el ratón.
- **Filtro por nombre de ruta y de mensajero** a la vez, con `ilike` y **escapando `%` y `_`**
  del usuario — si no, buscar «100%» traería cualquier cosa que empiece por 100.
- **Paginación de 10**, y al filtrar se vuelve a la primera página o te quedas mirando una que
  ya no existe.
- **Doble envío, en los dos lados.** El botón se desactiva mientras la acción está en vuelo,
  pero eso es cosmético: no cubre el doble clic que llega antes de que reaccione el JS, ni dos
  pestañas, ni una petición reenviada. El que de verdad lo impide es `PreventsDoubleSubmit`,
  un cerrojo atómico (`Cache::lock` sobre `cache_locks`) por usuario y acción, que se suelta en
  `finally`. **Aplicado también al login**, donde además evita que un doble clic gaste dos de
  los cinco intentos.
- **Transacciones**: el guardado va dentro de `DB::transaction`, que envuelve también la
  escritura del historial —se dispara en un evento del modelo—, así que un fallo no deja la
  fila sin auditoría ni al revés. Hay un test que lo comprueba desde dentro del evento.
- **Validación en el servidor**, con las reglas del modelo (§7, fase 1).

**`lang/es/validation.php`.** El panel está entero en castellano pero Laravel sólo trae los
mensajes en inglés y no había `lang/`, así que respondía «The name has already been taken».
Se vio en una captura, no en los 91 tests que pasaban. Está en un solo fichero para que el
mensaje de una regla sea el mismo en todas las pantallas, con `attributes` para que diga
«ruta» y no «pickup_route_id».

#### Módulo 3 — CRUD de mensajeros. Hecho el 12/08/2026

Mismo patrón que rutas. Lo propio de esta pantalla:

- **El desplegable sólo ofrece rutas libres**, más la del mensajero que se está editando —si no,
  abrir el formulario le borraría su propia ruta al guardar. `couriers.pickup_route_id` es único
  entre los vivos (§4), así que ofrecer una ocupada sería ofrecer algo que la base va a rechazar.
- **La validación lo corta igual**, aunque el desplegable no la ofrezca: el navegador no es una
  frontera de confianza. Hay un test que manda una ruta ocupada a mano.
- Dar de baja a un mensajero **no toca la ruta ni sus comercios**, y el sustituto puede heredar
  su ruta en cuanto el saliente se retira. Es lo que motivó el rediseño del modelo, ahora
  comprobado desde la pantalla.

#### `CrudScreen`: lo común, extraído con dos ejemplos delante

Se pospuso a propósito hasta tener dos CRUD escritos; con uno solo se habría adivinado mal qué
sube y qué se queda. En el trait está lo mecánico —estado del formulario, paginación, baja y
reactivación, cerrojo de doble envío y transacción— y en cada pantalla queda lo que de verdad
cambia: las reglas de validación, los campos y la consulta del listado. Rutas se reescribió
encima y sus 19 tests siguieron pasando sin tocarlos, que es la señal de que la abstracción no
se inventó nada.

Un detalle que sólo aparece al generalizar en castellano: **el género**. «Ruta dado de baja» no
lo dice nadie, así que el trait pide un `feminine()` y compone el participio. Hay un test por
cada género.

#### Confirmación antes de dar de baja

Añadida el 12/08/2026 a rutas y mensajeros. Vive en `CrudScreen` y en `ui/confirm-modal`, así
que el módulo de comercios la hereda sin escribir nada: es el primer dividendo de haber
extraído el trait.

El icono de la papelera ya no borra — abre la confirmación. Un icono es fácil de pulsar sin
querer, y va pegado al de editar. **El aviso nombra el registro** («Vas a dar de baja «1»»),
porque un «¿Seguro?» a secas no te dice si acertaste de fila, y **explica la consecuencia real
de cada módulo**: en rutas avisa de que primero hay que mover sus comercios; en mensajeros, de
que la ruta y sus comercios no se tocan.

#### Módulo 4 — CRUD de comercios. Hecho el 12/08/2026

El más grande, y el que menos código propio tiene: `CrudScreen` le dio modal, iconos,
confirmación, paginación, cerrojo y transacción sin escribir nada. Lo suyo:

- **Dos controles, no uno**: caja de búsqueda por nombre **y por código** —es lo que el cliente
  tiene delante cuando mira el portal— y un desplegable aparte para filtrar por ruta.
- **El código es opcional** (11 de los 93 no lo tienen, §3). Un `<input>` vacío llega como `''`
  y la regla es `nullable|integer`, así que se normaliza a `null` antes de validar; si no,
  «sin código» se leería como un entero mal formado. En la tabla se muestra como «—» con la
  explicación al pasar el ratón: sin código, el bot cruza por nombre y eso es *fuzzy*.
- **El mensajero de la tabla es derivado**, no una columna: sale de `pickupRoute.courier` (§4),
  con eager loading para no hacer dos consultas por fila.
- Mover un comercio de ruta —lo que motivó todo el historial— queda registrado con autor.

**`lang/es/pagination.php` y `lang/es.json`.** El paginador decía «Showing 1 to 10 of 93
results». Son dos mecanismos distintos: las flechas piden `pagination.previous`, con espacio de
nombres, y los textos sueltos van por claves de JSON. Otra vez lo encontró una captura, no los
134 tests que pasaban.

#### El paginador del panel

Cambiado el 12/08/2026 en los tres listados a la vez, desde `CrudScreen`. Con 93 comercios el
numerado sacaba diez botones que se comían el pie de la tabla entero, y en azul oscuro pesaba
más que la propia tabla. Ahora es **anterior/siguiente** con un `2 / 10` en medio, en blanco y
gris: es navegación secundaria y no debe competir con el contenido. El pie queda con el
recuento a la izquierda («93 comercios») y los botones a la derecha.

**Livewire ignora `Paginator::defaultView()`.** Su `WithPagination` resuelve la vista buscando
un método `paginationView()` en el propio componente y, si no lo encuentra, se queda con
`livewire::tailwind`. Registrarlo en el `AppServiceProvider` no hace nada — se probó, y la
pantalla seguía saliendo numerada aunque `Paginator::$defaultView` fuese la correcta. El sitio
bueno es `CrudScreen::paginationView()`.

#### Módulo 5 — Pantalla del historial. Hecho el 12/08/2026. **Cierra la fase 3**

Un icono de reloj por fila abre un modal con la línea de tiempo del registro. Vive en
`CrudScreen` y en `ui/audit-trail`, así que las tres pantallas lo tienen con una línea cada una.

- **`CrudScreen::auditEntries()` prepara, el Blade sólo pinta.** Ahí se resuelven las etiquetas
  («Ruta», no `pickup_route_id`) y los valores (el nombre de la ruta, no su id), y se cargan
  las rutas **de una sola consulta** en vez de una por fila.
- El mapa de rutas va con `withTrashed`: el historial de algo movido a una ruta que luego se
  dio de baja tiene que seguir leyéndose. Lo mismo `historyRecord()`, o dar de baja un registro
  escondería justo el rastro de por qué se dio de baja.
- **`id` se deja fuera** del diff: en un alta viene el volcado entero y ese dato no le dice
  nada a nadie.
- Un registro sin historial lo dice, en vez de enseñar un hueco: lo que cargó el seeder no
  aparece porque no es el cambio de nadie.

Verificado en el navegador con el escenario literal de §8: mover COBO FAMILY de la ruta 3 a la
5 desde el panel y leer «Modificación · Panel · 12/08/2026 23:35 · Ruta: 3 → 5».

#### Módulo 6 — Listado de auditoría. Hecho el 12/08/2026

Añadido a petición, y **corrigiendo un descarte mío**: había dicho que §5 lo excluía, pero §5
excluye «dashboard con gráficas», que no es lo mismo que un listado de auditoría. Lo apliqué
de más.

La razón de que haga falta: el modal de la ficha responde «¿qué le pasó a *este* comercio?»,
pero sólo sirve si ya sospechas de él. La pregunta real de §0 es al revés y cronológica — «el
informe de ayer cambió, ¿qué tocó alguien?» — y eso sólo lo responde un listado entre módulos.

Columnas: cuándo, quién, módulo, registro, acción, y un botón al detalle con «Campo / Antes /
Después». Filtros por módulo y búsqueda por autor o por nombre del registro —que vive **dentro
del JSON** del volcado, así que se busca con `after->>'name'`—. Sólo lectura: `audit_logs` no
se modifica ni se borra nunca (§4).

**`AuditPresenter` salió de `CrudScreen` al construirlo.** La traducción de etiquetas y valores
estaba en el trait de los CRUD, fuera del alcance del listado; tenerla en una clase propia
evita duplicarla y le da una responsabilidad sola. Los 10 tests del historial de ficha pasaron
sin tocarse tras el cambio.

**El nombre del registro se lee del propio volcado antes que de la relación.** Así la fila
sigue siendo legible aunque el registro se haya borrado del todo — la misma razón por la que
`user_email` está desnormalizado.

**El historial por ficha se retiró** el mismo día, al entrar este listado: tener dos sitios
para ver lo mismo es duplicación, y el modal de la ficha sólo servía si ya sospechabas del
registro. Con él se fueron el icono del reloj de las tres tablas, el parcial `ui/audit-trail`
—que quedaba sin usar— y su fichero de tests, previa mudanza a `AuditLogsScreenTest` de las
tres comprobaciones que el listado no cubría: que `id` no entra al diff, que un nulo se pinta
«—» y que las cuatro acciones tienen nombre en castellano.

### Fase 4 — Historial de cambios. Hecha el 12/08/2026

Se adelantó a la fase 3 a propósito: si el CRUD se construye primero, meter el historial
después obliga a volver a pasar por cada `save()`, cada componente Livewire y sus tests.

- `audit_logs` polimórfica, `AuditLog` inmutable y el trait `Auditable` sobre los eventos de
  Eloquent. Aplicado a `PickupRoute`, `Courier`, `Merchant` y **también `User`**.
- **Sin `updated_by` en ninguna tabla.** "Cargó" y "última modificación" se derivan del propio
  historial (primer `CREATE`, último `UPDATE`). Dos columnas que dicen lo mismo que la tabla
  de historial acaban discrepando de ella.
- Los seeders escriben con `AuditLog::withoutRecording()`: cargar el maestro son 105
  registros que taparían los cambios de verdad, y además ahí no hay sesión de la que sacar
  un autor.

Verificado sobre la base real reproduciendo el escenario que motivó la tabla: mover COBO
FAMILY de la ruta 3 a la 5 deja una entrada con el campo, el antes, el después, el autor y
la hora. 16 tests cubren el resto, incluido que la contraseña nunca llega al historial.

**La pantalla ya está**: es el módulo 6 de la fase 3, hecho el mismo 12/08/2026. El historial
por ficha que se planeó aquí se retiró al entrar el listado —dos sitios para ver lo mismo—, y
`Setting` entró en la auditoría con la fase 11.

### Fase 5 — Producción

`Dockerfile` multi-stage **distinto del de desarrollo** (sin Composer, sin volumen montado,
con `opcache` y los assets ya compilados), decidir dónde se despliega y cómo lo alcanza el
bot.

### Fase 6 — Cerrar el circuito con el bot (plan acordado el 13/08/2026)

Va después de la 5 por número, no por orden: **producción es lo último**. Esta fase es la que
convierte las dos mitades sueltas —el maestro que se sirve y las incidencias que se reciben—
en un ciclo diario que funciona solo.

#### El reparto de trabajo, y una alternativa que se descartó

El bot analiza, este panel administra el maestro, guarda el resultado y se lo enseña al
cliente. El 13/08/2026 **se planteó y se descartó** que fuera este repo quien calculase las
incidencias, dejando al bot como mero recolector de datos de GLS.

Lo que lo empujaba era bueno: el maestro vivía en un `rutas.xlsx` que alguien tenía que
entregar a mano, y el cliente sólo tiene acceso al panel. Pero **ese problema lo resuelve la
descarga del maestro** (§3), sin mover el análisis. Lo que frenó la mudanza es el coste de
portar la lógica del bot a PHP: no implementa una regla dictada, sino lo que midieron tres
días reales —el umbral de 5 minutos para partir tandas de la cinta, el 0,80 de concentración,
y la regla de sólo acusar a otra ruta si esa tanda es de verdad suya, que evita 56
acusaciones falsas al día—. El riesgo no es que una versión en PHP no funcione: es que
funcione *parecido* y nadie lo note, porque el resultado son acusaciones contra mensajeros
reales.

Si algún día se retoma, hay dos ventajas que siguen en pie: recalcular una jornada tras
corregir el maestro sin volver a consultar GLS (hoy obliga a repetir 14 minutos de corrida),
y que los envíos de comercios desconocidos —490 de 983 el 03/08— aparezcan donde el cliente
puede darlos de alta. La forma de hacerlo sin salto de fe sería que el bot mandase también
las observaciones crudas, que este panel calculase en paralelo, y apagar el cálculo en Python
sólo cuando ambos coincidieran sobre los mismos días.

#### Lo que hace el bot (contexto, no es trabajo de este repo)

| | Qué hace |
|---|---|
| **bot A** | ✅ **Hecha el 13/08/2026.** Lee el maestro de un `rutas.json` en su propio disco en vez del Excel, con el cambio de `ruta` a texto y admitiendo `mensajero: null`. Verificado convirtiendo el Excel al formato exacto de `GET /api/rutas`: mismo cruce, mismos grupos y listado byte a byte idéntico por las dos vías. |
| **bot B** | ✅ **Hecha el 13/08/2026.** Descarga el maestro de `GET /api/rutas` al arrancar cada corrida. **El Excel ya no se usa**, queda de recambio. Si el panel no responde, sigue con la última copia buena que tiene en disco. Probado contra este panel: 93 comercios, y el listado del 03/08 sale idéntico. |
| **bot C** | ✅ **Hecha el 13/08/2026.** Guarda los `id` del maestro y los devuelve en cada incidencia. Verificado con el 03/08: las 168 llegan con `id` de comercio y de ruta asignada, y las 112 que acusan a otra ruta traen también el suyo. |
| **bot D** | ✅ **En su código, verificado el 17/08/2026 leyendo `../bot-gls`.** `src/main.py` llama a `panel.publicar()` al final de la corrida, con **código de salida propio** cuando el reporte está en disco pero no llegó al panel —«hay algo que hacer: reenviarlo»— y un reenviador (`python -m src.panel --reenviar`) que encuentra los pendientes por su cuenta. El envío **nunca levanta**: un panel caído no puede invalidar 14 minutos de corrida ya escritos en disco. Y sin `PANEL_URL`/`PANEL_TOKEN` configurados no falla, sólo deja el JSON. |

El `rutas.json` del bot **no es un fichero compartido**: es su copia local, en su disco, que
él mismo escribe con lo que descargó. No hay ningún fichero, disco ni base de datos en común
entre los dos proyectos — las dos direcciones son HTTP, y por eso pueden vivir en servidores
distintos.

#### Lo que hay que hacer en este repo

**A — Añadir los `id` a `GET /api/rutas`.** ✅ **Hecha el 13/08/2026.** El endpoint sirve
`id` (comercio) y `ruta_id`, en ese orden de claves: `id, nombre, ruta_id, ruta, mensajero,
codigo`. El test de contrato los fija, incluido que **renombrar una ruta no cambia su
`ruta_id`**, que es el motivo entero de que existan. Comprobado de punta a punta: el bot los
recibe, los arrastra por el cruce y los devuelve en cada incidencia.

**B — Guardar las incidencias.** ✅ **Hecha el 13/08/2026.** Dos tablas: `incident_runs` (la
jornada y su marco: `reliable`, contadores y alertas) y `run_packages` (una fila por paquete evaluado; con `type` no nulo es incidencia).
*Upsert* por `(jornada, expedicion)` dentro de una transacción, y las que dejan de venir se
marcan retiradas en vez de borrarse.

*Verificado con el 03/08 real del bot*, enviándolo cuatro veces seguidas:

| Envío | Resultado |
|---|---|
| 1º, las 168 | `nuevas: 168` |
| 2º, idéntico | `nuevas: 0, actualizadas: 168` — no duplica |
| 3º, recortado a 100 | `actualizadas: 100, retiradas: 68` |
| 4º, las 168 otra vez | `retiradas: 0` — las 68 vuelven a estar vigentes |

Tres decisiones que la fase C hereda y no debe deshacer:

- **Los nombres son la foto del día.** `merchant_name`, `assigned_route_name` y
  `assigned_courier_name` se copian aunque haya FK. Para *enseñar* una incidencia hay que
  usarlos a ellos, no `$incident->merchant->name`: si mañana renombran la ruta o reasignan al
  conductor, la incidencia debe seguir diciendo lo que pasó aquel día. Las FK sirven para
  agrupar y enlazar. Hay un test que lo fija.
- **Los ids que ya no existen no rompen la ingesta.** Si alguien forzó el borrado de un
  comercio entre que el bot descargó el maestro y subió las incidencias, la fila entra con
  `merchant_id` nulo y su nombre copiado. Insertar una FK muerta daría un 500, y el bot
  reintenta los 5xx: se quedaría reintentando para siempre.
- **Ni `RunPackage` ni `IncidentRun` son `Auditable`.** El historial de §4 registra lo que hacen
  las personas; esto lo escribe el bot cada mañana y llenaría la auditoría de ruido.

**C — La pantalla del cliente.** ✅ **Hecha el 13/08/2026.** Listado con filtro por fechas y
detalle por jornada agrupado por ruta. Ver «La pantalla de incidencias», abajo.

**D — Backfill del `codigo`.** Rellenar el `SourceDepartment` de cada comercio para que el
cruce del bot sea exacto y desaparezca el *fuzzy*. Va al final porque es mejora, no requisito.
**Es lo único de la fase 6 que sigue pendiente.**

**E — Calendario de capacidades.** ✅ **Hecho el 13/08/2026.** Nació de tener el volumen de cada
paquete guardado (§3.1) y `couriers.maximum_volume` al lado. Ver «Calendario de capacidades»,
abajo. **Se numeraba 6.D hasta el 17/08/2026**, igual que el backfill: dos cosas distintas con
la misma letra, y una de ellas hecha y la otra no.

#### La pantalla de incidencias (fase 6.C) — especificación

> ✅ **Hecha el 13/08/2026.** Dos pantallas, y las cinco obligaciones de abajo cubiertas con
> un test cada una. 31 tests entre `IncidentsScreenTest` e `IncidentRunScreenTest`.
>
> - **`/incidents`** — el listado: **tabla paginada** de 15 en 15, una fila por jornada, con
>   **buscador por fechas** (`desde` / `hasta`, en la URL) y el recuento en el pie, como los
>   tres CRUD. Columnas: jornada, evaluados, incidencias y firmes.
>
>   **Firmes va en columna propia y destacada**, separada del total: poner sólo «168» anuncia
>   un incendio que el propio bot no sostiene — ese día sólo sostiene 8. Y **«evaluados» se
>   pinta como `459 / 983` con una barra**, no como una cifra suelta: es el denominador
>   honesto, y sin él «168 incidencias» se lee como si el día estuviera revisado entero.
>
>   Empezó siendo una lista de tarjetas y pasó a tabla el 13/08/2026 a petición. En el camino
>   se quedaron por el suelo dos cosas que sólo se ven mirándolo: la fecha larga («lun. 3 de
>   agosto de 2026») se partía en tres líneas y descuadraba la fila —de ahí el
>   `lun. 03/08/2026`—, y con siete columnas la tabla no cabía en una pantalla normal, así que
>   «envíos» y «evaluados» se fundieron en una, que además dice lo mismo mejor.
> - **`/incidents/{fecha}`** — el detalle, con la forma del informe que el cliente ya lee:
>   jornada → ruta → paquete, agrupado por la ruta **dueña** del paquete. Secciones de ruta
>   plegables (Alpine, sin ida al servidor: las incidencias vienen todas en una consulta) y
>   diálogo por paquete con lo que hace falta para contrastarlo en GLS Atlas.
>
> **Las horas se pintan en UTC, no en `Europe/Madrid`.** El informe del bot y GLS Atlas las
> dan así, y convertirlas correría cada fila dos horas: el cliente vería 21:15 donde el portal
> dice 19:15 y no podría contrastar ni un paquete. Hay un test que lo fija.
>
> **Las alertas pierden el `2026-08-03 ·` que el bot les antepone**: su informe cubre varios
> días y esta página ya se titula con la fecha.
>
> **Un desplegable explica la columna «Fiabilidad»**, añadido el 13/08/2026 a petición. Sin
> él, la diferencia entre «Firme» y «No concluyente» queda a interpretación de quien lea la
> pantalla, y de esa interpretación sale una conversación con un mensajero. Dice qué es una
> tanda, qué es la tanda principal, y las dos situaciones que tumban un hallazgo. **Los
> umbrales los saca de la propia corrida** (`batch_gap_minutes`, `tolerance_minutes`), no
> escritos a mano: si el bot cambia los suyos, el texto cambia con ellos. Hay un test que lo
> fija cambiándolos a 9 y 35.
>
> ⚠️ **La única cifra escrita a mano es el 80 % de concentración** que decide si una ruta pasó
> dispersa. Vive en `CONCENTRACION_MINIMA` de `src/incidencias.py` en el bot y **no viaja en
> el payload**, así que si allí se cambia, este texto miente sin que salte nada. Candidata a
> subir con el próximo cambio de contrato, junto con lo de aquí abajo.
>
> **Retirada la sección «Lo que el bot sostiene»** el 13/08/2026, también a petición: agrupaba
> por comercio los hallazgos firmes del día. La cifra sigue en el balance y como distintivo de
> cada ruta.
>
> Lo que **no** se hizo: un selector de jornada dentro del detalle, porque el listado con
> filtro ya es ese selector.

##### La jornada completa, no sólo las incidencias

Pedido el 13/08/2026: ver dentro de cada ruta **los paquetes que no son incidencia**, como
hace el informe en texto del bot («Ruta 1 — 94 paquetes, 11 con incidencia»). Sin ellos no hay
proporción: once sobre 94 no es lo mismo que once sobre doce.

**El panel está hecho, y el bot ya los manda.** ✅ `construir()` de `src/panel.py` mete
`"paquetes": [...]` con todos los envíos con ruta de la jornada, y `publicar()` —la que llama la
corrida diaria— usa ese mismo constructor. Verificado el 17/08/2026 leyendo `../bot-gls`; aquí
ponía «falta que el bot los mande» desde el 13/08/2026, cuando se probó construyendo el payload
a mano desde un CSV.

**Una sola tabla, `run_packages`, una fila por paquete evaluado.** La tabla nació como
`incidents` esa misma mañana y se reescribió en su sitio el mismo día —no hay nada desplegado,
igual que en la fase 1—. Una segunda tabla para los paquetes correctos habría dejado el mismo
envío en dos sitios, que es como empiezan a discrepar. El grano natural del dato es el
paquete; **«incidencia» es un filtro sobre él** (`type` no nulo), y de ahí salen
`scopeIncidents()` y `IncidentRun::currentIncidents()`.

**El contrato de §3.1 crece con una lista opcional `paquetes`**, todos los evaluados del día,
con incidencia o sin ella:

```json
{ "expedicion": "1334043165", "codigo": "61326305203862",
  "comercio": { "id": 42, "nombre": "BOHOCHIQUE" },
  "ruta_asignada": { "id": 3, "nombre": "Ruta 1", "mensajero": "Benjamin GLS" },
  "hora_cinta": "2026-08-03T19:55:02+00:00" }
```

Los campos de incidencia (`tipo`, `ruta_observada`, `desvio_min`, `rutas_compatibles`,
`confianza`, `motivo_confianza`) son opcionales aquí: el panel los superpone desde la lista
`incidencias` emparejando por expedición, así que el bot puede mandarlos en una lista o en la
otra sin repetirlos.

**`paquetes` es opcional a propósito y `incidencias` no cambia.** Un bot que no la mande sigue
funcionando exactamente igual y la sección «Pasaron con su ruta» no aparece: un bot viejo no
puede romperse por un campo que no conoce. Hay un test de cada caso.

*Verificado el 13/08/2026 con el 03/08 real*, construyendo el payload desde el
`validado_20260803_20260803.csv` del bot: 493 paquetes con ruta, 168 de ellos incidencia,
`nuevas 325 / actualizadas 168`. Los seis recuentos por ruta salen **idénticos** a los del
informe en texto: 94/11, 51/30, 159/53, 48/7, 37/7, 104/60. El payload pasa de 100 KB a
**184 KB**, no del orden de 600 KB como se estimó antes de medirlo.

**Lo que falta, y es del otro repo:** que el bot emita `paquetes` en su corrida. Y decidir
retención — ~500 filas al día son ~180.000 al año, que no es problema para Postgres pero sí
una decisión que conviene tomar antes de producción (§8).

**Para quién es.** Para la agencia. Responde una pregunta muy concreta —*¿algún paquete se fue
en la furgoneta equivocada ayer, y de quién?*— y de ella sale una conversación con un
mensajero. Eso obliga a que la pantalla sea prudente: cada fila es una acusación sobre una
persona real.

##### Los datos que ya están guardados

Todo viene de `incident_runs` (una fila por jornada) y `run_packages` (un paquete evaluado); las
migraciones explican campo a campo. Lo que importa para pintar:

| Campo | Para qué |
|---|---|
| `run.reliable` | Si es `false`, esa corrida no pudo consultar bastantes envíos del día. |
| `run.shipments` / `evaluated` / `without_route` / `without_belt_time` | El denominador: cuántos envíos hubo y cuántos se pudieron mirar de verdad. |
| `run.alerts` | Alertas del día, ya redactadas, con su `tipo` y las rutas implicadas. |
| `confidence` + `confidence_reasons` | Si el hallazgo se sostiene, y por qué no. |
| `type` | `tanda_de_otra_ruta` (hay a quién señalar) \| `fuera_de_tanda` (no lo hay). |
| `assigned_route_name` / `assigned_courier_name` | De quién era el paquete, **aquel día**. |
| `observed_route_name` | Quién lo recogió. Nulo si `type` es `fuera_de_tanda`. |
| `compatible_routes` | Si no está vacío, la tanda la compartían varias rutas: la acusación no señala a una sola. |
| `belt_time`, `deviation_minutes`, `barcode` | El detalle para contrastar contra GLS Atlas. |
| `withdrawn_at` | Dejó de venir en un reenvío. Fuera del listado por defecto. |

##### Cinco obligaciones que no son negociables

1. **`confidence` manda sobre el diseño.** No es una columna más ni un icono discreto. Del
   03/08: **160 de 168 son de confianza baja**. Una pantalla que las liste todas igual presenta
   160 sospechas que el propio bot marca como no concluyentes con la misma autoridad que las 8
   que sí lo son. Lo natural es que **lo firme se vea primero y lo dudoso vaya aparte**, con su
   motivo escrito en palabras, no con un código: `ruta_dispersa` → *«esa ruta pasó desperdigada
   por la cinta ese día»*; `tanda_compartida` → *«dos furgonetas descargaron juntas: por la
   hora no se puede saber cuál lo llevó»*; `ventana_compartida` → *«a esa hora estaban
   descargando varias rutas: encaja en más de una»*. Los textos viven en `IncidentPresenter`
   y `IncidentIntakeV2Test` comprueba que un motivo nuevo no se cuele en clave.
2. **`reliable = false` se avisa arriba y en grande.** Una jornada dudosa no cubre todos los
   envíos, y leerla como completa lleva a concluir «no hubo más incidencias» cuando lo cierto
   es «no se pudo mirar».
3. **Los dos `type` no se mezclan en la misma lista.** `tanda_de_otra_ruta` señala a alguien;
   `fuera_de_tanda` sólo dice que el paquete pasó descolgado, sin culpable. Fueron 112 y 56 el
   03/08. Juntarlos convierte 56 hechos neutros en 56 acusaciones.
4. **Para mostrar, usar los nombres copiados, nunca las relaciones.** `assigned_route_name` y
   `assigned_courier_name`, no `$incident->assignedRoute->name`. Son la foto del día: si
   renombran la ruta o reasignan al conductor, la incidencia debe seguir diciendo lo que pasó.
   Las FK son para agrupar y enlazar. Hay un test que lo fija.
5. **`withdrawn_at` fuera del listado por defecto**, pero localizable. Existe para poder ver
   que algo estuvo y dejó de estar; usar `RunPackage::current()` o `$run->currentIncidents()`.

##### Los números reales del 03/08, para diseñar contra ellos

No son un ejemplo inventado: salieron de una corrida real, guardada entonces en la base de
desarrollo. **Esa base se vació el 17/08/2026** (ver «Con qué datos desarrollar»), así que estas
cifras son ya sólo el retrato contra el que se diseñó la pantalla, no algo que se pueda consultar
abriéndola.

```
983 envíos | 459 evaluados | 490 sin ruta | 34 sin hora de cinta
168 incidencias → 160 de confianza baja, 8 alta
                  112 acusan a otra ruta, 56 pasaron descolgados

quién recogió de quién (vigentes, agrupado):
   Ruta 3 → Ruta 1 ... 51  (baja)      Ruta 4 → Ruta 1 ...  3  (ALTA)
   Ruta 6 → Ruta 5 ... 21  (baja)      Ruta 5 → Ruta 3 ...  3  (baja)
   Ruta 6 → Ruta 3 ... 11  (baja)      Ruta 2 → Ruta 3 ...  2  (baja)
   Ruta 1 → Ruta 3 ...  9  (baja)
```

**El dato que debería decidir la pantalla:** las 8 incidencias concluyentes de ese día son de
**dos comercios** — Imperio Corinto (6) y CAI YUN LAI (2). O sea que el contenido accionable
de una jornada de 983 envíos son dos conversaciones. Si la pantalla abre con una tabla de 168
filas paginada de 20 en 20, esas dos se pierden en la página cuatro.

##### Estructura sugerida

Es una sugerencia, no un mandato; lo de arriba sí es obligatorio.

- **Selector de jornada** (por defecto la última) con su cabecera: fecha, el aviso de
  `reliable` si toca, y el balance «168 incidencias sobre 459 envíos evaluados de 983».
- **Lo concluyente primero**: las de confianza alta, agrupadas por comercio, que es como se
  actúa —se habla con un mensajero de un comercio, no de un paquete suelto.
- **Quién recogió de quién**: el agregado `assigned_route_name` × `observed_route_name` con su
  recuento. Es la respuesta directa a la pregunta del negocio.
- **Lo dudoso, aparte y plegado**, con el motivo en palabras.
- **Los descolgados** (`fuera_de_tanda`), en su propia sección y sin lenguaje de culpa.
- **Las alertas del día** de `run.alerts`, que ya vienen redactadas.
- **Detalle de un paquete** bajo demanda: `barcode`, `belt_time`, `deviation_minutes` y
  `compatible_routes`, que es lo que permite contrastarlo en GLS Atlas.

##### Qué reutilizar y qué no

Vale `CrudScreen`... **hasta cierto punto: esto no es un CRUD.** Nadie crea ni edita una
incidencia; las escribe el bot. De ahí sirven la paginación —incluido `paginationView()`, que
es el único sitio donde Livewire lo respeta (§7)— y los componentes de `ui/`. No sirven el
modal de alta, la confirmación de baja ni el historial de auditoría: estas tablas no son
`Auditable` a propósito.

##### Con qué datos desarrollar

⚠️ **La base de desarrollo está vacía de incidencias desde el 17/08/2026**, a petición:
`TRUNCATE run_packages, incident_runs RESTART IDENTITY`. Lo que se borró eran cinco jornadas del
10 al 14/08 —el 03/08 con el que se diseñó hacía tiempo que se había reemplazado— y `run_packages`
ya estaba a cero antes: las jornadas declaraban sus contadores de incidencias sin tener las filas
debajo, que es lo que se ve hoy si se abre `/incidents` sin repoblar. **Las dos pantallas de la
fase 6.C se quedan sin nada que enseñar hasta que se repueble**, y no es un fallo de la pantalla.

Para repoblarla, dos caminos, los dos desde el repo del bot: `python -m src.panel
salidas/validado_<fecha>.csv --enviar`, o un `POST /api/incidencias` con uno de los JSON de
`salidas/`. Nada de esto vive en este repo (§9).

**Para los tests no hace falta la base de desarrollo**: lo que se pedía aquí existe y es el
trait `MakesIncidents` —`storedRun()`, `incident()`, `package()`—, del que tiran
`IncidentsScreenTest` e `IncidentRunScreenTest`. **Trait y no factory a propósito**: un factory
en `database/factories` sugeriría que estas dos tablas se dan de alta desde el panel, y las
escribe el bot. Sus valores por defecto son los del 03/08 real, así que los tests se leen
contra las cifras de este documento. Cubre la jornada con `reliable = false`, los dos `type`,
las dos `confidence` y `compatible_routes`; la retirada se hace encima
(`->update(['withdrawn_at' => now()])`), que es como la escribe el propio *upsert*.
`CapacityCalendarTest` no lo usa: monta sus jornadas con sus propios ayudantes, porque lo que
necesita es volumen y mensajero, no incidencias. Por eso los 327 tests pasan con la base de
desarrollo vacía.

##### Cómo se verifica

Tests de pantalla como los del CRUD, y que fijen las obligaciones, no el maquetado:

- una jornada con `reliable = false` muestra el aviso;
- una incidencia de confianza baja **no** se presenta igual que una de confianza alta;
- una `fuera_de_tanda` no aparece en la lista de acusaciones a otra ruta;
- una retirada no sale en el listado por defecto;
- renombrar la ruta después de guardar no cambia lo que muestra la incidencia;
- la pantalla no dispara una consulta por fila.

#### Calendario de capacidades (fase 6.E) — hecho el 13/08/2026

`GET /capacity-calendar`, colgando de **Operaciones**. Una tabla por semana: una fila por UT y
una columna por día. La semana por defecto es la en curso, y el filtro
va en la query (`?semana=`, el lunes) porque es un filtro con valor por defecto, no otra
pantalla; se mueve con flechas o eligiendo cualquier día, que salta al lunes de su semana.

Cuatro decisiones que no son de estilo:

1. **Se agrupa por `assigned_courier_name`, no por la ruta.** La pregunta es por persona, y el
   nombre copiado es quién conducía **aquel día** (§3.1). Ir por `assigned_route_id` hasta el
   conductor de hoy reescribiría el pasado en cuanto alguien cambie de ruta.

2. **Lo que se mide es la carga que le tocaba a su ruta**, no lo que acabó en su furgoneta. Un
   paquete que pasó en la tanda de otra ruta sigue contando aquí para la suya: esto sirve para
   planificar, y las desviaciones son el asunto de la pantalla de incidencias.

3. **Una suma incompleta ya no se marca en la celda.** Los nulos siguen sin sumar como cero
   —un nulo del portal es «no lo sé», §3— y la cobertura del día (`shipments` y `measured`)
   se sigue calculando y llega a la vista, pero **desde el 17/08/2026 la pantalla no la
   enseña**. El recorrido fue: el denominador «1 de 3 envíos» debajo de cada celda hasta el
   14/08/2026 —con tres cifras por celda la tabla no se leía—, luego sólo en el tooltip, y el
   17/08/2026 fuera también el tooltip, a petición.

   **Es una renuncia consciente y va contra lo que pedía §3**: hoy un día con la mitad de los
   volúmenes nulos se lee como un día flojo y nada dice que ocupó más. El dato está en la fila
   —volver a enseñarlo es una línea de Blade— y hay un test que fija que hoy no se ve, para que
   quitarlo haya sido una decisión y no una pérdida silenciosa.

4. **Nada se esconde por no estar en el maestro.** Quien se dio de baja después de mover
   volumen esa semana conserva su fila, marcada, y las rutas que aquel día no llevaba nadie van
   a una fila «Sin UT asignada». Si no, la suma de la semana no cuadraría con la de incidencias
   y nadie sabría por qué.

La cifra de cada día es **qué parte de la furgoneta ocupó**: la suma del día entre
`couriers.maximum_volume`. Es la lectura que se busca —4 m³ es mucho o poco según quién los
lleve— y por encima del 100 % es un día que no cabía. Sin capacidad declarada no hay entre qué
dividir, así que sale un guion; una capacidad de cero se trata igual, porque es un dato mal
metido y no un divisor. **Sustituyó a la media semanal el 14/08/2026**, que respondía a otra
pregunta: la media decía cuánto carga una UT y esto dice si el día le cabe.

**Al lado del porcentaje, entre paréntesis, de dónde sale** (18/08/2026): qué parte de ese
volumen pasó con su propia ruta y qué parte acabó fuera de ella —suman el 100 %—, con el segundo
en ámbar sólo cuando existe, para que un día limpio no lleve un color de aviso. Y un **triángulo
que lleva a las incidencias de esa ruta ese día** (`?ut=`, ver el diálogo más abajo), ámbar
cuando hubo paquetes fuera de la ruta y gris cuando no: está siempre, pero sólo llama la
atención si hay algo que mirar.

**El neto en m³ salió de la celda ese mismo día, a petición.** Estuvo en la misma línea que el
porcentaje hasta el 17/08/2026 —competían—, debajo y en pequeño hasta el 18/08, y ahora vive
sólo en el diálogo, que es donde se va a cuadrar con incidencias. La fila lo sigue calculando y
llega a la vista; volver a pintarlo es una línea de Blade, y hay un test que fija que hoy no se
ve para que quitarlo haya sido una decisión y no una pérdida silenciosa. **Con esto, una UT sin
capacidad declarada se quedaba con una celda muda**, así que su guion también abre el diálogo.

El reparto **sale del mismo agregado que la tabla**: la consulta agrupa además por
`type is null`, así que son dos filas por UT y día que se pliegan en PHP en una. Sigue siendo
una sola consulta, y el test de las cuatro lo fija.

Un día sin corrida se marca como tal —no es un día sin
trabajo— y uno con la corrida no fiable, también. La tabla entera se arma con **cuatro
consultas** —corridas, agregado, maestro y la configuración de la pantalla— y hay un test que
lo fija: agrupar en SQL es lo que la mantiene en pie con el maestro entero delante.

`couriers.maximum_volume` (§4) se enseña como columna y es el divisor de la ocupación. Es el
único uso que hoy tiene ese campo.

**El color del porcentaje lo decide la configuración** desde el 17/08/2026, no la pantalla: ver
la fase 11 y §8, donde se cerró qué pasaba con el rojo del «se pasa de la capacidad».

**Pulsar el porcentaje abre de qué paquetes sale** (18/08/2026). El diálogo enseña la ocupación
del día y la reparte en dos: lo que pasó **en la tanda de su propia ruta** (`type` nulo) y lo
que le tocaba a esa ruta y **se recogió fuera de ella** (`tanda_de_otra_ruta` y
`fuera_de_tanda`, juntos: son dos hallazgos distintos para la pantalla de incidencias, pero
para «cuánto de este 11 % es suyo» son lo mismo). Tres decisiones:

1. **Reparte el volumen de la celda, no otro.** Los filtros son los del agregado de la tabla
   —jornada, UT y no retirados—, así que las dos partes suman exactamente el porcentaje del que
   se abrió. Si aquí se colara otra condición, el diálogo contaría una historia que la tabla no
   cuenta, y hay un test que fija que las dos ocupaciones suman la del día.

2. **Manda el reparto en porcentaje del volumen del día** —los dos suman 100 %, que es la
   pregunta que se hace al pulsar la cifra— y debajo, en pequeño, lo que cada parte ocupa de la
   furgoneta, que es lo que suma el porcentaje de la tabla. Con las dos partes en porcentaje de
   capacidad, un 6 % y un 6 % debajo de un 11 % parecerían un error de la pantalla: son dos
   redondeos.

3. **Aquí sí se dice la cobertura**, aunque la celda no la enseñe desde el 17/08/2026: el
   reparto sólo puede salir de los envíos con volumen, y sin decirlo se leería como si cubriera
   la jornada entera. Es un diálogo que se abre a propósito, no una tercera cifra en una tabla
   que se lee en diagonal, que es lo que se quitó entonces.

4. **Del diálogo se sale a la jornada de ese día**, con las rutas de esa UT abiertas y
   resaltadas (`/incidents/<fecha>?ut=<nombre>`). La pregunta que sigue a «el 19 % acabó fuera
   de su ruta» es siempre cuál se lo llevó, y eso ya lo contesta la pantalla de incidencias:
   duplicar ahí el detalle por paquete habría sido una segunda versión de la misma tabla.
   El resalte se explica solo en destino —«Resaltando las rutas de X · Ver todas»—, y dice
   también cuando esa UT no llevó ninguna ruta ese día, que si no se lee como una pantalla
   rota. La fila «Sin UT asignada» viaja como `?ut=sin-ut`: un `?ut=` vacío es «sin filtro», y
   hace falta un valor. **El parámetro está escrito en las dos pantallas**, así que el test
   recorre el enlace entero —abre el diálogo, sigue la URL y comprueba el resalte— en vez de
   comparar cadenas: es lo único que impide que se separen.

La consulta del reparto **sólo se hace con el diálogo abierto**: la tabla se sigue armando con
sus cuatro, y hay un test para cada cosa.

#### Orden de ataque

```
bot A ──▶ bot B ──▶ bot D
                          ┌── panel A ──▶ bot C
panel B ──▶ panel C ──────┘
```

**Hechas el 13/08/2026: bot A, bot B, panel A, bot C, panel B, panel C y panel E.** El ciclo
funciona de punta a punta: el bot baja el maestro del panel, analiza, y sube las incidencias, que
este panel guarda y ya enseña.

**Y bot D también está**, verificado el 17/08/2026 leyendo `../bot-gls`: la corrida diaria
publica, hay código de salida propio para «hecho pero no entregado» y un `--reenviar` para lo que
quedó pendiente. Así que de todo el cuadro **queda sólo `panel D`**, el backfill del `codigo`,
que es mejora y no requisito.

⚠️ **Lo que no se ha vuelto a comprobar es el ciclo entero corriendo solo.** Lo de arriba es
lectura de código de los dos repos, no una corrida real de punta a punta; y la base de
desarrollo de este panel se vació el 17/08/2026, así que ahora mismo no hay ninguna jornada
guardada con la que contrastar. Repetirlo —una corrida del bot contra este panel, y mirar la
pantalla— es lo que cierra la fase 6 de verdad, y es de las cosas que este documento insiste en
verificar ejecutando (§7, fase 0 y módulo 2).

Antes de producción hay que decidir además dónde se despliega cada uno y cómo se alcanzan por
red (§8), y revisar la hora del servidor del bot: hoy su reloj no está en hora de Madrid y
calcula qué día analizar con la zona local.

> **Corregido el 13/08/2026: el panel perdía el offset de las horas que manda el bot.**
> `Carbon::parse()` lee bien el `-04:00`, pero Laravel escribe la fecha con formato
> `Y-m-d H:i:s`, **sin zona**, y Postgres la interpreta en la suya. Resultado: un `generado`
> de `09:38:58-04:00` se guardaba como `09:38:58 UTC`, cuatro horas antes de lo real. No era
> hipotético — el reloj del servidor del bot va en `-04:00` ahora mismo. Arreglado con `->utc()`
> antes de escribir, en `IncidentIntakeController`. `hora_cinta` se salvaba de milagro porque
> el bot la manda ya en `+00:00`, y es justo el dato que sostiene el «pasó en la tanda de otra
> ruta».

### Fase 7 — Copias de seguridad. Hecha el 13/08/2026

`GET /backups`, en el bloque **Sistema** de la barra lateral. Dos cosas: descargar un volcado
de la base entera y restaurar la base desde uno descargado antes.

**El panel no guarda copias.** El volcado se genera en un temporal del sistema, se manda al
navegador y el temporal se borra con `deleteFileAfterSend()`. No hay carpeta de copias que
vigilar, y sobre todo no hay volcados con los nombres y códigos de los comercios (§9)
acumulándose en el servidor. La contrapartida, que la pantalla dice en voz alta: **la única
copia es la que se lleve quien la descarga**, y para deshacer una restauración hay que haberse
bajado antes el estado actual.

`pg_dump`/`pg_restore` y no un volcado escrito en PHP: un fichero de `INSERT`s propio no
reproduce la columna generada de `merchants`, los índices únicos parciales de §4 ni las
secuencias, y una copia que no restaura no es una copia. Van en la imagen de desarrollo
—`Dockerfile`, del repositorio de PostgreSQL y no del de Debian, porque el cliente 15 de
bookworm se niega a volcar el servidor 17—. No son una dependencia de las que veta la regla 2
de `CLAUDE.md`: no hay paquete de Composer ni de npm por medio, son las herramientas del motor
que ya usamos. La pantalla comprueba que están y lo dice si faltan, en vez de dejar que los
botones fallen uno a uno.

Formato `custom` (`-Fc`), y la restauración con `--clean --if-exists --single-transaction
--exit-on-error`: limpia antes de escribir —si no, los registros nuevos quedarían mezclados
con los del volcado— y es todo o nada, de modo que un error a mitad deja la base como estaba.
Antes de lanzarlo se hace `DB::disconnect()`: la conexión de la propia petición tiene tomadas
las tablas que `--clean` necesita tirar, y sin eso la restauración se espera a sí misma.

Tres cautelas, porque restaurar no se deshace:

1. **Hay que escribir `RESTAURAR`**, y se comprueba en el servidor. Elegir un fichero y pulsar
   un botón es demasiado fácil un martes por la mañana.
2. **El fichero se valida antes de preguntar**: si no empieza por `PGDMP` no es un volcado de
   este motor y se dice ahí mismo, sin hacer escribir la palabra para nada.
3. **Al terminar se cierra la sesión.** Las sesiones viven en la base (`SESSION_DRIVER`), así
   que la de quien restaura deja de existir en cuanto entra el volcado; se sale al login con el
   aviso, que es lo honesto frente a quedarse en una pantalla que ya no responde.

La contraseña de la base va por entorno (`PGPASSWORD`) y nunca como argumento: la línea de
mandato la ve cualquiera que liste los procesos de la máquina (§10). Hay un test que lo fija.

Los tests no restauran de verdad —`pg_restore` sobre la base de test la dejaría sin las tablas
que el propio test necesita—, así que fijan el mandato con `Process::fake()`. El volcado sí se
hace de verdad, y se comprueba que `pg_restore --list` lo sabe leer. El ciclo completo
—volcar, escribir después, restaurar y ver que lo escrito después desapareció— se verificó a
mano contra una base desechable el 13/08/2026.

### Fase 8 — Usuarios del panel. Hecha el 14/08/2026

`GET /users`, en el bloque **Sistema** de la barra lateral y no en la lista de arriba: son las
cuentas que entran a la aplicación, no maestro que el cliente mantenga a diario. Alta, edición,
baja y reactivación sobre el mismo `CrudScreen` que rutas, UT y comercios, con el mismo
buscador —nombre, apellido o correo— y la misma casilla de dados de baja.

**`users.last_name`, nullable en la base y obligatorio en el formulario** (migración del
14/08/2026). Nullable y no `default('')` porque las cuentas que ya existían —la del
`InitialUserSeeder`, entre ellas— no tienen apellido y nadie puede inventárselo: un NULL dice
«no consta», que es la verdad. La validación sí lo exige, porque lo que se dé de alta de ahora
en adelante debe tenerlo; la base guarda lo que hay y el formulario pide lo que debería haber.
**Consecuencia aceptada**: editar una cuenta anterior obliga a rellenarle el apellido, y hay un
test que lo fija para que no se lea como un fallo. `name` se queda como el
nombre de pila y **no se parte en dos**: es con lo que `AuditLog::author()` firma cada entrada
del historial y lo que `AuditPresenter::record()` usa para nombrar la fila, así que trocearlo
habría reescrito el historial. `User::fullName()` los junta para la pantalla, nada más.

> **Corregido el 17/08/2026: aquí ponía `audit_logs.user_name`, y esa columna no existe.**
> `audit_logs` guarda `user_email` desnormalizado (§4) y el nombre del autor sale vivo de la
> relación (`$this->user?->name ?? $this->user_email ?? 'Sistema'`). El argumento de no partir
> `name` se sostiene igual —o más—: al leerse por la relación, trocearlo cambiaría cómo se
> firman también las entradas de hace meses.

Tres decisiones que no son de estilo:

1. **La contraseña nunca sale de la base.** El formulario la pide en blanco siempre, y al
   editar vacío significa «déjala como está». Enseñar la actual es imposible —es un hash— y
   exigirla en cada cambio de correo invita a poner una floja para salir del paso. Se guarda
   asignándola al modelo, cuyo cast `hashed` la cifra: llamar además a `Hash::make` la
   hashearía dos veces y nadie podría entrar. Hay un test de cada cosa.

2. **Nadie se da de baja a sí mismo**, y eso vive en la pantalla, no en el modelo — al revés
   que la regla de `PickupRoute`. «A ti mismo» sólo significa algo habiendo sesión: en el
   modelo rompía a los seeders y a los tests que borran su propio usuario, que es exactamente
   la señal de que estaba en el sitio equivocado. La fila propia va marcada con «tú» y sin
   botón de baja; la comprobación en `delete()` es la red por si la llamada llega igual.

3. **De ahí sale gratis que el panel nunca se quede sin usuarios**: si sólo hay una cuenta, esa
   cuenta es la de quien está mirando, y la suya no se puede borrar. No hace falta un guardia
   aparte contando filas, que fue lo primero que se escribió y lo que rompió media suite.

**El ancho del modal es una prop, no una clase suelta** (`ui/modal`, `width`, por defecto
`max-w-lg`). Antes se colaba por `class` y convivían dos `max-w-*` en el mismo elemento, con lo
que quién ganaba lo decidía el orden del CSS de Tailwind y no quien llamaba —`confirm-modal`
pedía `max-w-md` y se quedaba en `lg`—. La ficha de usuario usa `max-w-2xl` porque **pasa de
tres campos**: con el ancho de siempre salía en una tira larga. Nombre y apellido comparten
fila, y las dos contraseñas también; en móvil se apilan (`sm:grid-cols-2`).

**Los mensajes de validación, todos en castellano.** `lang/es/validation.php` gana `confirmed`
y `file`, y `last_name` en la lista de atributos; sin ellos el formulario respondía «The
password field confirmation does not match». Se repasaron **todas** las reglas que usa el panel
—rutas, UT, comercios, usuarios, login y la subida de una copia— comprobando el mensaje que
sale de verdad, no la lista de claves. Hay un test que fija tres de ellos en la pantalla de
usuarios.

El correo es único **entre los vivos**, como el resto del esquema (§4): dar de baja libera el
correo por si esa persona vuelve o alguien hereda la cuenta. La contraseña no llega al
historial —`#[Hidden]` la excluye vía `Auditable`— y hay un test que lo comprueba sobre el
volcado del alta, no sobre la teoría.

### Fase 9 — Gestionar la incidencia. Hecha el 14/08/2026

Cierra el pendiente de §8. Cada incidencia del detalle de una jornada se puede **comentar** y
marcar como **atendida**, y el listado lo dice sin abrirla: distintivo por fila («Atendida», «Con
comentario», «Pendiente»), contador «Sin atender» en el balance del día y «Todas atendidas» /
«N sin atender» en la cabecera de cada ruta. La pantalla deja de ser un informe que se lee y
pasa a ser una lista de trabajo.

Cuatro columnas nuevas en `run_packages`: `handled_at`, `handled_by`, `handled_by_name` y
`handling_note`.

1. **Viven en la tabla que escribe el bot**, y no en una `run_package_handlings` aparte. Es uno
   a uno con el paquete y la pantalla lo lee siempre: la tabla aparte obligaba a un join —o a
   una consulta por fila— para pintar un distintivo. Lo que lo hace seguro es que el *upsert*
   de la fase 6.B escribe **una lista explícita de columnas**, las del contrato, y no `$row`
   entero; y `RunPackage::$fillable` **no incluye** las de gestión, para que ningún campo del
   payload pueda escribirlas. Reenviar la jornada corrige los datos del bot y no borra lo que
   una persona anotó: hay un test en `IncidentIntakeTest` que lo fija, porque es la clase de
   cosa que se rompe callando.

2. **`handled_at` nulo es «pendiente», y es la única fuente de verdad.** Un booleano aparte
   podría contradecir a la fecha, y entonces no valdría ninguno de los dos.

3. **Editar el comentario no mueve la fecha de atención.** Es el dato que responde «cuánto
   tardamos en ocuparnos de esto», y se movería sola cada vez que alguien añade una línea.
   Reabrir sí borra fecha, id y nombre: dejar el rastro de una atención que ya no vale es peor
   que no tenerlo.

4. **`handled_by_name` va desnormalizado**, por lo mismo que `audit_logs.user_email` (§4):
   quien atendió una incidencia en agosto tiene que seguir leyéndose dentro de dos años, dado
   de baja o renombrado. Aquí se copia el nombre y no el correo porque esto se pinta en la
   pantalla del cliente, no en un historial técnico. (Decía `audit_logs.user_name`, columna que
   no existe; corregido el 17/08/2026.) `RunPackage` **sigue sin ser `Auditable`** —lo escribe
   el bot cientos de veces al día y ahogaría el historial—; el rastro de quién atendió qué son
   estas cuatro columnas.

**El comentario y la atención van juntos** (18/08/2026, a petición). No se puede guardar un
comentario dejando la incidencia abierta, y **desmarcarla borra el que tuviera**. El comentario
dice *qué se hizo*: comentada y sin atender era un estado que no significaba nada y que nadie
sabía leer en el listado —hasta ese día se pintaba como «Con comentario»—. El borrado al reabrir
es sin preguntar y aunque el campo del diálogo siga lleno; si no, reabrir obligaría a vaciar el
texto a mano para no chocar con la primera mitad de la regla, y el diálogo lo avisa antes de
guardar. El distintivo «Con comentario» **se queda en el listado**: las filas escritas antes de
la regla lo tienen y hay que saber leerlas.

La misma regla vale **en lote**, pero sólo cuando alguna de las marcadas se quedaría abierta:
comentar de una vez sobre incidencias ya atendidas —un repaso— sigue valiendo.

Y **guardar sin haber tocado nada se rechaza**, en los dos diálogos: hasta el 18/08/2026 el de
una sola cerraba con un «Incidencia actualizada» que no era verdad, y el del lote decía «N
incidencias actualizadas» sin haber tocado ninguna. Ahora dicen qué falta. Los mensajes de
validación nombran el campo en castellano (`attributes: ['note' => 'comentario']`); «El campo
note» no lo entiende nadie, y esto se lee en pantalla.

Lo que **no** cambió: el comentario **no es obligatorio** para cerrar una incidencia. Exigirlo
sólo produce comentarios que dicen «ok». El diálogo de gestión es distinto del de detalle a propósito: uno se
abre para mirar y el otro para escribir, y mezclarlos convierte una consulta en un formulario.
El id del paquete llega del cliente, así que se busca **dentro de la jornada** (`$run->packages()
->findOrFail()`) y no con un `find` suelto; hay un test que lo comprueba con el paquete de otro
día. Primitiva nueva: `ui/textarea`, gemela de `ui/input`.

#### Abrir un diálogo dejó de costar la jornada entera (18/08/2026)

Con un día real —168 incidencias y ~490 paquetes correctos— **abrir o cerrar un modal tardaba
~640 ms y devolvía 2 MB de HTML**. No era la consulta: era que Livewire vuelve a pintar el
componente entero en cada petición, así que cambiar `managing` de `null` a un id rehidrataba los
650 paquetes, los reagrupaba y repintaba las ~650 filas con sus SVG. Medido antes de tocar nada,
y otra vez después: **27 ms y 12 KB**.

Tres cambios, y el orden importa porque cada uno depende del anterior:

1. **El listado por ruta es una isla** (`@island('rutas')`, de Livewire 4), y los traspasos,
   otra. Una isla no se vuelve a pintar salvo que se le pida, así que un diálogo que se abre no
   la toca. Como no se pinta sola, **hay que pedírselo cuando cambia lo que enseña**:
   `refreshListing()` tras guardar una gestión o un lote, porque los distintivos de cada fila y
   los contadores de cada ruta viven ahí dentro. Hay un test que lo fija por los dos lados: al
   abrir un diálogo no viaja ningún trozo de isla, y al guardar sí y trae «Atendida».

2. **Lo caro pasó a propiedades calculadas** (`#[Computed] paquetes/incidencias/rutas/
   traspasos`) y salió de `with()`. Es lo que hace que la isla saltada no cueste **nada**: si
   nadie las toca, la consulta no se hace. `with()` se quedó con lo que sí necesita cada
   petición, y el balance de arriba —incidencias, firmes, sin atender— se calcula ahora con un
   `count(*) filter (where …)` en vez de contando una colección que ya no se carga. Hay un test
   que comprueba que ninguna consulta de abrir el diálogo trae la lista de paquetes.

3. **La selección del lote vive en el navegador.** Estaba con `wire:model.live`, así que
   **marcar una casilla costaba lo mismo que abrir un diálogo**: una ida al servidor con toda la
   jornada detrás. Ahora es un `x-data` de Alpine en la raíz —el resalte de la fila, la casilla
   de cabecera y la barra de abajo se resuelven ahí— y los ids sólo viajan al pulsar «Gestionar
   juntas», que los pasa como argumento. El servidor sigue sin creérselos: `seleccionadas()` los
   vuelve a consultar contra la jornada.

**La trampa de las islas, y costó un rato encontrarla:** un `wire:click` **dentro** de una isla
le dice a Livewire que repinte *sólo esa isla*, y los dos diálogos de la pantalla viven fuera de
ella. Con las islas puestas, el icono de comentar dejó de abrir el modal: la petición salía, se
guardaba el estado y volvía sólo el trozo del listado. Por eso los botones de cada fila llaman
por **`$wire.manage(...)` / `$wire.show(...)`** desde Alpine: una llamada por `$wire` no lleva
elemento de origen, así que no lleva isla, y se repinta todo **menos** las islas — que es justo
lo barato. Hay un test que lo fija por si alguien los devuelve a `wire:click`.

**Lo que no se ha tocado**: la primera carga de la página sigue costando lo que cuesta pintar el
día entero (~780 ms con 650 paquetes). Ahí el HTML hay que generarlo una vez sí o sí; si algún
día molesta, lo siguiente es paginar dentro de cada ruta, no otra isla.

#### Gestión en lote (18/08/2026)

Un lote de paquetes del mismo comercio pasa por la cinta en segundos y arrastra **exactamente la
misma incidencia**: cerrarlas de una en una es escribir el mismo comentario quince veces. Cada
fila lleva su casilla, la cabecera de cada lista marca la sección entera —el lote suele ser
justo eso—, y con algo marcado aparece abajo una barra fija con «Gestionar juntas». Va fija y no
dentro de una tabla porque se marcan filas de dos listas y de secciones distintas.

**Una incidencia atendida no lleva casilla**, y si en una lista no queda ninguna pendiente
tampoco la lleva su cabecera: gestionar en lote es cerrar, y ofrecer la casilla de algo ya
cerrado invita a marcarlo para nada. La celda sí se queda, vacía, o la fila se correría una
columna. **Sin
recuento**, a petición del mismo día: las filas marcadas ya se ven resaltadas, y la cifra la
vuelve a decir el diálogo justo antes de escribir en todas. Marcar y desmarcar **no toca el
servidor**: ver el apartado anterior.

Tres reglas, y las tres **se apartan a propósito** de las del diálogo de una sola:

1. **La selección se consulta, no se cree.** Los ids llegan del navegador, así que
   `seleccionadas()` filtra por jornada, por que sean incidencias —un paquete correcto no se
   «atiende»— y por no retiradas. Hay un test que manda el id de otra jornada y el de un paquete
   correcto y comprueba que ninguno se toca.

2. **Un comentario en blanco no borra nada.** En el diálogo de una sola, vaciar el campo es
   querer quitar el comentario; en lote sería un accidente —se marcan quince, se cierran sin
   escribir— y se perderían los comentarios que ya tuvieran.

3. **El interruptor sólo cierra; nunca reabre.** Sin marcar significa «no toques el estado». En
   lote, reabrir borraría fecha, autor y nombre de incidencias atendidas hace semanas, y eso se
   pide de una en una, mirándolas. Lo que ya estaba atendido conserva su fecha y su autor.

La escritura va en una transacción: medio lote cerrado y medio no es peor que no haber cerrado
nada, porque nadie sabría dónde se quedó. Y todo esto pide `incidents.manage` (§7, fase 12): sin
él no se pintan ni las casillas, y llamar a los métodos a mano responde 403.

### Fase 10 — Mi perfil. Hecha el 14/08/2026

`GET /profile`, **fuera de la barra lateral**: se llega desde el menú de la cabecera, que es
donde se busca «mi cuenta». No es un módulo del maestro y ponerlo en la lista de la izquierda lo
habría convertido en uno.

Siempre opera sobre `auth()->user()`, **nunca sobre un id que llegue del cliente**. Sin eso
sería `/users` con otro nombre y con menos comprobaciones.

1. **El correo no se cambia aquí**, por decisión del 14/08/2026. Es la credencial de acceso:
   quien lo toca se está cambiando el usuario con el que entra, y eso pasa por el maestro de
   cuentas para que quede como lo que es, un cambio administrativo. Se enseña deshabilitado
   —saber con qué correo has entrado sí es asunto de tu perfil— y el componente **no tiene
   propiedad `email`**, así que no es que el formulario lo esconda: es que no hay dónde
   recibirlo. Hay un test que lo comprueba con `property_exists`.
   **La letra pequeña, resuelta el 18/08/2026**: hasta entonces todos los usuarios veían
   `/users`, así que cualquiera podía cambiarse el correo por ahí y la restricción del perfil
   era de diseño de la pantalla, no un permiso. Con la fase 12 lo es: `/users` pide
   `users.view` y cambiar un correo, `users.manage`.

2. **Dos formularios y no uno.** Cambiar el nombre y cambiar la contraseña son gestos distintos
   con distinto riesgo: juntarlos obligaría a escribir la contraseña para corregir una tilde.

3. **La contraseña actual se pide aunque la sesión esté abierta.** Un portátil desatendido
   basta para quedarse con la cuenta, y ese es justo el descuido contra el que sirve. Se usa la
   regla `current_password` de Laravel, que comprueba contra el hash del usuario autenticado:
   nada de `Hash::check` a mano. La nueva no puede ser la de antes (`Rule::notIn`).

Los cambios quedan en el historial porque `User` es `Auditable` (§4), y la contraseña no llega a
él —`#[Hidden]`—; hay un test que lo recorre entero buscándola. `lang/es/validation.php` gana
`current_password`. De paso, **el menú de la cabecera pasa a saludar con `fullName()`**, que
desde la fase 8 incluye el apellido.

**Avisos flotantes (`ui/toasts`), estrenados aquí y extendidos a todo el panel el mismo día.**
Al estilo de los *toasts* de TailAdmin y, como todo el lenguaje visual, copiando el aire y sin
instalar nada (§5). El contenedor vive una sola vez en cada layout —el de sesión y el de
invitado— y escucha en `window`; cualquier pantalla lanza uno con `$this->toast('…')` del trait
`SendsToasts`, que envuelve `$this->dispatch('toast', …)`: Livewire v3 publica sus `dispatch`
como eventos del navegador, así que basta Alpine para recogerlos y ninguna plantilla necesita un
hueco donde pintarlos.

**Todos los `session()->flash('ok'|'error')` del panel pasaron a toast** el 14/08/2026, y con
ellos desaparecieron los bloques `@if (session('ok'))` de las seis pantallas que los repetían.
Tres cosas que la conversión decide:

1. **El éxito se va solo a los cinco segundos; el error se queda hasta que lo cierras.** Un
   error dice por qué **no** se hizo lo que pediste —«la ruta todavía tiene 3 comercios»— y eso
   no puede evaporarse mientras miras a otro lado.

2. **El aviso va teñido del color de lo que dice**, no blanco con un filo de color: en la
   esquina de una pantalla llena de tarjetas blancas, un borde de un píxel no se ve. Se cambió
   el 14/08/2026 después de verlo en marcha.

3. **Lo que cruza una redirección sigue viajando en la sesión** —la restauración de una copia,
   que se lleva por delante la sesión y aterriza en el login—, porque ahí no hay evento de
   Livewire que valga. El contenedor lee `session('ok'|'error')` en su `x-init` y los enseña
   igual que a los demás; por eso está también en el layout de invitado.

**Los `x-ui.alert` que quedan son estado, no aviso**: «esta corrida no es fiable», «faltan
`pg_dump` y `pg_restore` en el contenedor», la advertencia de que restaurar no se deshace. Eso
es parte de lo que la pantalla dice mientras está abierta y no puede desaparecer solo.

### Fase 11 — Configuraciones. Hecha el 14/08/2026

`GET /settings/{module}`, en un bloque **Configuraciones** de la barra lateral con un hijo por
módulo configurable. Son los parámetros con los que trabaja otra pantalla: umbrales, colores y
lo que venga. Hoy sólo está el **calendario de capacidades**, que **la lee desde el
17/08/2026**: la fase entregó la configuración y el efecto llegó después (ver más abajo).

La pantalla de configuración lo avisaba con un `x-ui.alert`, retirado el mismo día a petición;
con él se fue el soporte de `pending` en el catálogo. **El aviso que sí hay vive en el módulo
configurado**, no en la configuración: el calendario dice «esta pantalla está sin configurar»,
enumera lo que falta por su nombre visible y enlaza a su ajuste.

**Una tabla `settings` con una fila por parámetro** (`module`, `key`, `value`), y no una tabla
por módulo ni un `jsonb` por módulo. Las tres se pensaron:

- Columnas tipadas por módulo es lo que hace el resto del esquema (§4), y sería lo coherente si
  esto fuera negocio. No lo es: son ajustes de pantalla, y cada umbral nuevo costaría una
  migración.
- Un `jsonb` por módulo deja el historial ilegible: `audit_logs` enseñaría un volcado entero
  donde cambió un número, y la pantalla de auditoría vive de decir «Campo / Antes / Después».
- Fila por parámetro: el historial sale bien —una entrada por parámetro que cambió, y sólo por
  los que cambiaron— y añadir uno es una línea de catálogo.

**El catálogo (`SettingsCatalog`) es la única fuente**: la pantalla pinta lo que declara y la
validación sale de ahí. **Una sola pantalla sirve a todos los módulos** —lo que cambia entre
ellos son los parámetros, y eso ya está declarado—, así que añadir la configuración de otro
módulo no lleva ruta, ni componente, ni migración.

**No hay valores por defecto**, decidido el 14/08/2026 después de probarlos. Los hubo unas
horas, en el catálogo, y se quitaron por lo que implicaban: un umbral inventado cambia cómo se
lee una pantalla entera sin que el cliente lo haya elegido y sin que nada lo avise, que es
peor que el hueco. Ahora un parámetro sin poner **es** un hueco, `Setting::missing()` los
enumera y **el módulo que los necesita avisa en su propia pantalla** con enlace a su ajuste.
Con los defectos se fue también el botón «Valores por defecto», que ya no tenía qué restaurar.

Del calendario se configuran hoy dos umbrales y tres colores. **Tres y no dos**: dos umbrales
parten el día en tres tramos —por debajo del mínimo, entre mínimo y óptimo, del óptimo para
arriba—, y con dos colores el tramo de en medio se queda sin definir. La pantalla enseña una
muestra de cómo se verá cada uno, porque un verde y un ámbar que sobre blanco no se distinguen
sólo se ven al lado.

Dos cosas que no son de estilo:

1. **El hexadecimal se valida con `regex`** aunque el `<input type="color">` siempre mande
   `#rrggbb`: al lado hay un campo de texto para pegar el color corporativo, y ese valor acaba
   en un atributo `style`. Hay un test que lo intenta con `javascript:alert(1)`.
2. **`Setting` es `Auditable` pero no usa `SoftDeletes`** —un parámetro no se da de baja: se
   cambia, y borrar su fila lo devuelve a su valor por defecto—. Eso destapó que `Auditable`
   **daba por hecho el borrado pasivo**: registraba `restored`, que aporta `SoftDeletes`, y en
   un modelo sin él reventaba durante el arranque con un error que no mencionaba ni la
   auditoría ni el evento. Arreglado en el trait, que es donde estaba el supuesto.

`ui/alert` estrena el tipo `warning` —ni éxito ni fallo: algo que atender, donde el rojo diría
que se ha roto algo y no es verdad—. `AuditPresenter` aprende el módulo «Configuraciones»; el nombre de cada fila lo da un accesor
`Setting::name` —«Calendario de capacidades · Porcentaje mínimo»— porque, sin él, el historial
diría «#7». `ui/nav-sublink` aprende a llevar parámetros de ruta y a marcarse activo sólo con
los suyos: si no, todas las configuraciones se encenderían a la vez.

#### El calendario conectado a su configuración — hecho el 17/08/2026

`⚡capacity-calendar` pinta ya cada porcentaje con el color del tramo en que cae. Cuatro
decisiones:

1. **El tramo se decide sobre el porcentaje redondeado**, el mismo que se pinta. Con el crudo,
   un 79,6 % que la tabla enseña como «80 %» se quedaría fuera del tramo bueno por una décima
   que no se ve, y el color parecería un fallo de la pantalla.

2. **El día que no llega al mínimo lo dice sólo el color.** El 17/08/2026 llevó además un icono
   de alerta delante de la cifra, y **el 18/08/2026 se quitó a petición**: en su sitio, pulsar
   el porcentaje abre el desglose de la celda (ver «Calendario de capacidades»). El parámetro
   sigue igual y es el que decide el tramo malo: es el `minimum_percent` que la fase 11 ya
   guardaba, renombrado a **«Porcentaje mínimo de carga»** para que se lea como lo que es. Se
   reutilizó en vez de añadir un segundo umbral porque un «mínimo de carga» aparte del «mínimo»
   del tramo malo serían dos números que significan lo mismo y que alguien acabaría poniendo
   distintos.

3. **El rojo del «se pasa de la capacidad» no lo sustituye el color del tramo: conviven**, que
   era la duda anotada en §8. No podía sustituirlo porque son dos preguntas distintas —el tramo
   dice si el día fue flojo o bueno; pasarse dice que no cabía— y con el óptimo en 80 un 125 %
   cae en el tramo *bueno*. Tampoco podía seguir siendo un color, porque los colores ahora los
   elige el cliente. Así que el exceso pasó a ser una **marca ▲** junto a la cifra, con su
   tooltip, y el color queda entero para el tramo.

4. **Sin umbrales configurados no hay tramo y la cifra sale sin color**, coherente con no tener
   valores por defecto. Y el hexadecimal se vuelve a filtrar en la vista aunque el formulario ya
   lo valide: de ahí sale un atributo `style`, y una fila escrita a mano en la base no pasa por
   el formulario. Hay un test que lo intenta con `javascript:alert(1)`.

Sobre las consultas: `Setting::missingIn()` se separó de `missing()` para que la pantalla, que
necesita **los valores para trabajar y lo que falta para avisar**, no pague dos consultas por la
misma fila. La tabla sigue armándose con cuatro.

### Fase 12 — Roles y permisos. Hecha el 18/08/2026

Con `spatie/laravel-permission` (§5), a petición del cliente. **Dos roles y dos acciones por
módulo**, ni uno más:

| Rol | Qué lleva |
|---|---|
| **Administrador** | Todo el catálogo, que es como se define: «todos los permisos que existan». |
| **Operaciones** | El maestro (rutas, UT, comercios), las incidencias y el calendario, más la auditoría y las configuraciones **en modo lectura**. Ni usuarios, ni roles, ni copias. |

Son **los dos con los que nace** el panel; desde `GET /roles` el cliente crea los que quiera.

`App\Support\PermissionCatalog` es la única fuente, como `SettingsCatalog` con los ajustes: de
ahí salen los permisos que siembra `RolesAndPermissionsSeeder`, el desplegable del maestro de
usuarios, el `can:` de cada ruta y lo que esconde la barra lateral. Añadir un módulo es una
línea en el catálogo y volver a pasar el seeder.

Cinco decisiones:

1. **`view` y `manage`, y no un CRUD entero.** Hoy ninguna pantalla separa crear de editar, así
   que `create` y `update` serían dos permisos que nadie pondría distintos. Lo único que se
   distingue de verdad es mirar frente a escribir. Dos excepciones: `audit-logs` sólo tiene
   `view` —el historial no se edita ni se borra nunca (§4)— y `backups` sólo tiene `manage`,
   porque entrar en esa pantalla **ya es** poder descargarse la base entera (§10) y un permiso
   de «sólo mirar» ahí no protegería de nada.

2. **La ruta es la puerta, no la única cerradura.** Cada pantalla declara su `can:` en
   `routes/web.php` —el del framework, porque el paquete registra los permisos en el `Gate`—,
   pero **toda acción que escribe vuelve a comprobar su `.manage` dentro del componente**: a un
   método de Livewire se llega desde el navegador aunque el Blade no pinte el botón. Está en
   `CrudScreen::authorizeManage()` para los cuatro CRUD, y a mano en la jornada y en las
   configuraciones. Hay un test que llama a `create`, `edit`, `delete` y `save` con una cuenta
   de sólo lectura y espera cuatro 403.

3. **Lo que no se puede ver se esconde, no se enseña apagado.** El menú filtra por permiso
   (`layouts/app.blade.php`) y los botones de acción salen sólo con `manage`. Un enlace que
   existe se pulsa igual, y la respuesta sería un 403 que parece un fallo del panel.

4. **Una cuenta, un rol.** El paquete admite varios; con dos, «Administrador + Operaciones» no
   dice nada que no diga ya «Administrador», y una lista de casillas invita a combinaciones que
   nadie ha pensado. El modelo de datos aguanta varios el día que hagan falta. Y **no puedes
   quitarte a ti mismo el Administrador**, por lo mismo que no puedes darte de baja (fase 8):
   es quedarte fuera de tu propio panel sin poder volver a entrar a arreglarlo.

5. **El cambio de rol se audita a mano.** Vive en la tabla pivote del paquete y los eventos de
   Eloquent no lo ven, así que lo escribe `User::recordRoleChange()` (§4). Sin eso, «quién le
   dio el Administrador a quién» no quedaría en ninguna parte, que es justo el cambio que más
   importa poder mirar después. La consecuencia visible: un alta deja **dos** entradas en el
   historial, la de la cuenta y la del rol.

**Al desplegar hay que pasar el seeder** (`php artisan db:seed --class=RolesAndPermissionsSeeder`).
Es idempotente y hace tres cosas: crea los permisos que falten, sincroniza los de cada rol
—quitar uno del catálogo lo quita también de la base— y **da Administrador a toda cuenta que no
tenga ninguna**. Esto último es deliberado: antes de la fase 12 no había roles y cualquiera
entraba a todo, así que dejarlas sin rol sería echar del panel al equipo entero en el
despliegue. Sólo alcanza a quien no tiene rol, para que repetirlo no le devuelva el
Administrador a quien alguien acaba de bajar a Operaciones.

En los tests, `Tests\TestCase` siembra el catálogo (`$seed`/`$seeder`) y **las cuentas de
`UserFactory` nacen administradoras**: es lo que era todo el mundo antes de esto, así que los
tests que no van de permisos siguen probando lo que probaban. Para lo demás están
`->role(...)` y `->withoutRole()`.

#### El maestro de roles y permisos (`GET /roles`)

En **Sistema**, detrás de `roles.view` / `roles.manage`, que sólo lleva el Administrador: quien
puede tocar los roles puede dárselo todo a sí mismo, así que va con las cuentas y las copias.

**Dos maestros en una pantalla**, porque son la misma pregunta desde los dos lados: arriba los
roles —qué lleva cada uno, en casillas agrupadas por módulo— y abajo los permisos que hay para
repartir. La mitad de abajo nació el mismo día como una **leyenda de sólo lectura** —el catálogo
con su descripción— y **se convirtió en un CRUD a petición** unas horas después.

**Los permisos del código no se tocan.** `PermissionCatalog` siembra los que `routes/web.php` y
las pantallas comprueban por su nombre: renombrar `merchants.manage` desde el panel dejaría esa
comprobación mirando a un permiso que ya no existe y cerraría la pantalla para todos. Salen
marcados «del código» y sin botones, y `savePermission` y `deletePermission` lo vuelven a
comprobar. Los demás son del cliente enteros.

**Un permiso que ningún código comprueba no abre ninguna puerta**, y eso hay que decirlo donde
se crea: sirve para preparar el terreno de una pantalla que viene, no para inventar capacidades.
Está escrito en el diálogo y en el pie de la tabla.

Tres detalles del alta de un permiso:

- **El nombre tiene forma**: `modulo.accion`, en minúsculas y con un punto (`regex`). La
  pantalla agrupa por lo que va antes del punto y `can:` lo lee tal cual desde la ruta; un
  nombre con espacios sería un permiso imposible de escribir donde hay que escribirlo.
- **La descripción bajó a la base** (migración `add_description_to_permissions_table`). Vivía en
  el catálogo, pero desde que hay permisos creados a mano el catálogo sólo explica la mitad. El
  seeder la refresca en cada pasada para los del código: ahí el catálogo sigue mandando.
- **El nuevo entra al Administrador en el acto.** Ese rol es «todos los permisos»; si esperase
  al próximo despliegue habría un rato en el que ese «todos» sería mentira. Y por lo mismo, el
  seeder resincroniza el Administrador contra **los permisos de la base**, no contra los del
  catálogo: con la lista del catálogo, cada despliegue le quitaría los creados desde la
  pantalla.

Cuatro reglas, todas del mismo tipo que las que ya había en el maestro (§4):

1. **El Administrador ni se edita ni se borra.** Es el rol que se define como «todos los
   permisos» —lo resincroniza el seeder en cada despliegue— y es la única vuelta atrás si
   alguien se equivoca configurando los demás. Se comprueba también en `save` y en `delete`, no
   sólo escondiendo los botones.

2. **Un rol con cuentas no se borra.** Es la misma regla que la de una ruta con comercios vivos:
   borrarlo dejaría a esas cuentas sin rol —o sea, fuera del panel— sin decírselo a nadie.
   Primero se les cambia el rol en Usuarios. Y **se borra de verdad**, no en pasivo: «tiene un
   rol que ya no existe» no es un estado que ninguna pantalla sepa contar.

3. **No puedes quitarle a tu propio rol el permiso de gestionar roles**, por lo mismo que no
   puedes darte de baja ni quitarte el Administrador: es cerrar la puerta desde dentro.

4. **Un rol sin permisos es legítimo**: sirve para aparcar una cuenta sin borrarla. La fila lo
   dice con todas las letras —«no puede entrar a ninguna pantalla»— para que no parezca un rol a
   medio configurar.

**El cambio se audita.** `App\Models\Role` y `App\Models\Permission` extienden a los del
paquete y sólo añaden `Auditable` (§4) —y la descripción, en el segundo—;
`config/permission.php` apunta ahí, así que el paquete instancia los nuestros en todas partes.
Los permisos de un rol son una pivote y los eventos de Eloquent no los ven, de modo que la lista
la anota `Role::recordPermissionChange()`, igual que el rol de una cuenta. Crear o borrar un
permiso cambia lo que la aplicación es capaz de comprobar, así que también deja rastro.

**El seeder ya no pisa lo que se toque aquí.** Crea los permisos que falten, siembra un rol la
primera vez y después **sólo resincroniza el Administrador**; si volviera a sincronizar
Operaciones, cada despliegue desharía lo que el cliente hubiera cambiado. Hay un test que edita
Operaciones, pasa el seeder y comprueba que el cambio sigue ahí.

La pantalla **no usa `CrudScreen`**: ese trait está construido sobre `SoftDeletes` —baja pasiva,
reactivación, «ver dados de baja»— y aquí no hay nada de eso. Se reutiliza lo que no depende de
ello: `SendsToasts` y el cerrojo de doble envío.

### Fase 13 — La ganancia por ruta. Hecha el 19/08/2026

**Qué pide el cliente:** saber **cuánto deja cada ruta**, no sólo si sus paquetes pasaron a la
hora que tocaba. El dato existe en **Envexpress (Mensaglobal)**, el otro portal de la agencia,
y el bot ya sabe extraerlo y cruzarlo. Aquí sólo hay que **recibirlo, guardarlo y enseñarlo**:
este panel no habla con Envexpress.

**Qué es la ganancia, exactamente.** Lo que se facturó por el envío **sin IVA** — la suma de la
columna `Precio` de sus valoraciones en Envexpress. **No es el margen**, que es
`ganancia − coste`. El cliente lo fijó así el 19/08/2026 señalando la pantalla del portal: en
un envío de 3,06 € de precio y 2,46 € de coste, donde el portal rotula «Beneficio: 0,60 €», la
ganancia son **3,06 €**.

**Alcance, recortado por el cliente el 19/08/2026:** sólo `ganancia`. Ni coste, ni margen, ni
totales de jornada. Lo que sigue es exactamente eso y nada más.

**El lado del bot está terminado (19/08/2026).** Baja la ganancia de Envexpress, la cruza por
código de barras, la publica en sus dos informes y **la manda en el payload v4**. Este lado
—13.A, 13.B y 13.C— se hizo del tirón el mismo día y **se probó contra ese payload real**: los
números del criterio de aceptación de 13.D salieron todos, ver el final de la fase.

**Hay un payload v4 real para desarrollar contra él**, sin esperar a una corrida: en el repo del
bot, `salidas/panel_20260807.json` (jornada del 07/08/2026, 139 KB). Sus números, que es lo que
debería salir al otro lado:

| | |
|---|---|
| `version` | 4 |
| `paquetes[]` | 271 |
| con `ganancia` | 250 · **2.615,16 €** |
| con `null` | 21 |

⚠️ Esos 2.615,16 € **no son la ganancia del día**, que fue de 4.871,10 €: los otros 2.255,94 €
son de envíos sin ruta, que no viajan en `paquetes[]`. Es exactamente la trampa que 13.C
manda evitar al rotular.

**Cómo lo presenta el bot, por si conviene que la pantalla se parezca:** en su listado, cada
ruta lleva su importe en el encabezado —*«59 paquetes, 1 con incidencia, 7 sin ganancia —
ganancia 1.011,86 €»*— y al pie hay una tabla `Ruta | Paquetes | Con dato | Ganancia` con una
fila `(sin ruta)` y un `TOTAL`. Las dos ideas que ese formato ya resuelve son las de 13.C: **el
número de envíos con dato siempre al lado del importe**, y **los envíos sin ruta contados
aparte**.

**El precedente era `volume_m3`, y se calcó.** Entró igual, por los mismos motivos y con las
mismas trampas: opcional, nullable, nulo≠cero, y toda suma acompañada de su cuenta. La
ganancia va **pegada a él en los cuatro sitios**, así que quien toque uno encontrará al otro
al lado y no hará falta redescubrir el porqué:

| Dónde | Qué comparten |
|---|---|
| Sus dos migraciones (`…_add_volume_to_run_packages_table`, `…_add_net_revenue_to_run_packages_table`) | Cómo se documenta el nulo y por qué ese tipo. |
| `IncidentIntakeController` | Las reglas de validación, contiguas, y las dos filas del *upsert*. |
| `RunPackage` | `$fillable` y el *cast* a `float`, con el nulo advertido en el comentario. |
| `⚡capacity-calendar.blade.php` y `⚡incident-run.blade.php` | `sum(...)` con `count(...)` al lado: `count` de la columna no cuenta los nulos y es el denominador honesto. |

**13.A — Migración. Hecha.** `run_packages` ganó **una** columna: `net_revenue`
(`decimal(10,2)`, nullable, `after('volume_m3')`) en
`2026_08_19_100000_add_net_revenue_to_run_packages_table.php`. Nullable por dos motivos
distintos que conviene no confundir: una corrida de un bot v3 no trae el campo, y un envío
puede no aparecer en Envexpress (~30 de 543 al día). **Sin backfill**: el cliente vacía las
tablas antes de la primera corrida v4. `decimal(10,2)` porque son euros con dos decimales y
una jornada entera ronda los 5.000 €.

**13.B — Intake. Hecho.** `IncidentIntakeController` acepta la v4 con las tres líneas
previstas y ni una más: `'paquetes.*.ganancia'` y `'incidencias.*.ganancia'` como
`nullable|numeric|min:0` junto a las de `volumen_m3`, y `'net_revenue' => $row['ganancia'] ?? null`
en la fila del *upsert*. No hizo falta tocar nada más: `version` sólo se valida como entero y
el *upsert* por `(fecha, expedicion)` ya escribía una lista explícita de columnas (regla 2 de
§3.1). `IncidentIntakeV4Test` —6 casos— fija que la v4 entra y guarda el importe, que **los dos
decimales sobreviven** (el cliente contrasta 3,06 € contra la pantalla de Envexpress), que una
**v3 sigue entrando** con `net_revenue` a nulo, que un `null` **no** acaba en `0`, que un
importe negativo se rechaza con un 422 que nombra la fila, y que el campo se guarda también
desde `paquetes[]` —que es de donde sale casi toda la ganancia de una ruta, porque la mayoría
de los envíos no son incidencia—.

**13.C — La pantalla. Hecha**, en el detalle de la jornada. Las dos reglas que la fase fijó
como no opcionales, y que **siguen sin serlo para cualquier pantalla que sume esta columna**:

1. **Decir siempre sobre cuántos envíos se sumó.** Un nulo es «no se sabe», no «cero», igual
   que con el volumen. Sin el denominador, una ruta a la que le faltan valoraciones parece
   menos rentable de lo que es.
2. **No llamar «total del día» a la suma de las rutas.** Aquí sólo están los envíos con ruta.

Dónde quedó cada una:

- **En el encabezado de cada ruta**, junto a «59 paquetes, 1 con incidencia»:
  *«· 1.011,86 € (52 de 59 envíos)»*. Si ninguna de sus filas trae dato, no se pinta un
  `0,00 €` sino **«sin dato de ganancia»** (regla 1).
- **Bajo el título «Por ruta»**, la suma de la jornada rotulada **«Ganancia de las rutas»**,
  con su cobertura y con la advertencia de que **no incluye los envíos sin ruta** (regla 2).
  Nunca «del día»: el 07/08/2026 esta cifra son 2.615,16 € de los 4.871,10 € facturados.
- **En cada fila de paquete**, su propio importe, y delante **el código de barras**
  (19/08/2026, a petición del cliente). Las dos columnas van juntas por la misma razón: se
  mira un paquete y lo siguiente que se hace es buscarlo en el portal para ver qué se facturó.
  El código abría el diálogo de una en una, que es impracticable para contrastar una jornada
  entera contra el listado en texto del bot; ahora la tabla se lee en el mismo orden que ese
  listado —`Código · Comercio · Hora cinta · Apunta a · Ganancia`—. Un envío sin valoración es
  **«—», no «0,00 €»**: en una fila suelta el cero se lee como un envío que no se cobró, que
  es peor mentira que en un total.
- **En el desglose de una celda del calendario de capacidades** (19/08/2026, a petición del
  cliente): «Ganancia de sus rutas» bajo la ocupación, y el importe de cada parte —«De su
  ruta» y «Fuera de su ruta»— en una columna junto al porcentaje del volumen: *«98 % del
  volumen · 134,18 € de ganancia neta»*. **En euros y no en porcentaje**, decidido por el
  cliente el 19/08/2026 al verlo: de «Fuera de su ruta» lo que se quiere saber es el dinero
  que acabó en otra furgoneta, no qué fracción del día era. El 07/08/2026,
  Benjamin GLS: 1.011,86 € sobre 52 de 59 envíos, de los que 19,32 € se fueron fuera de su
  ruta. Se rotula **«de sus rutas»**, que aquí es todavía más importante: sólo están los
  envíos de esa UT.

  **El dinero no sigue al volumen**, que es la razón de que la columna exista: un envío
  voluminoso puede facturar poco y uno pequeño mucho. Ese mismo día, Benjamin GLS reparte
  97/3 el volumen y 98/2 el dinero, y BORJA GONZALEZ 98/2 y 97/3; el día que un solo bulto
  caro se vaya con otra furgoneta se separarán de verdad. Y el importe lleva **su propia
  cuenta**, que tampoco coincide con la del volumen: los envíos que traen m³ y los que tienen
  valoración en Envexpress no son los mismos.

  **«Neta» es «sin IVA», no «después de costes».** El rótulo lo eligió el cliente; el coste
  no viaja en el contrato (§3.1) y esto no es el margen, así que la columna lleva un título
  emergente que lo dice, y la caja de arriba sigue rotulando «Lo facturado sin IVA».
- **También en «Pasaron con su ruta»**, la tabla plegada de los que fueron donde debían. No es
  simetría por gusto: ahí está la mayoría de la jornada y con ella **casi toda la ganancia de
  la ruta**, así que sin esas dos columnas el importe del encabezado no se puede cuadrar
  mirando la pantalla, sólo creérselo. El 07/08/2026, de los 271 envíos con ruta sólo 50 eran
  incidencia.

Dos detalles de implementación que no son cosméticos. El importe de la jornada sale del
agregado de `balance()` —tres `selectRaw` más en la consulta que ya se hacía— y **no** de la
colección de paquetes: esa cifra se pinta **fuera** de la isla del listado, y leerla de la
colección habría vuelto a cargar las ~650 filas del día en cada clic, que es exactamente lo
que esta pantalla dejó de hacer. Y el par suma/cuenta viaja siempre junto, con la forma que ya
usaba el calendario de capacidades: `sum(net_revenue)` con `count(net_revenue)` al lado, que
no cuenta los nulos y es el denominador honesto. Nueve tests en `IncidentRunScreenTest` fijan
las dos reglas, el «sin dato», el «—» de una fila sin valoración —en las dos tablas—, el
código en la fila y que una jornada anterior a la v4 no pinta ni un euro; otros seis en
`CapacityCalendarTest` fijan el reparto del diálogo, que el importe se cuenta **sobre sus
propios envíos** —no sobre los que traen volumen, que son otros—, que **el dinero de una parte
no sigue a su volumen** (un caso a 90/10 en m³ y al revés en euros) y el rótulo.

**Ganancia, no «rentabilidad».** El cliente la pide con ese nombre y en la conversación vale,
pero **ninguna pantalla la rotula así**: la rentabilidad es el margen y el margen necesita el
coste, que quedó fuera del contrato (§3.1). Poner «Rentabilidad» sobre una cifra que es
facturación bruta invitaría a leer 1.011,86 € como beneficio.

**13.D — Permisos.** Ninguno nuevo, como estaba previsto: es más información dentro de la
pantalla de incidencias, que ya tiene su `incidents.view` (fase 12).

**Cómo se probó, y qué salió.** Se mandó el payload v4 real del bot —`salidas/panel_20260807.json`,
la jornada del 07/08/2026— tal cual al endpoint:

```bash
curl -X POST http://localhost:8000/api/incidencias \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  --data-binary @../bot-gls/salidas/panel_20260807.json
```

El criterio de aceptación, comprobado contra la base el 19/08/2026:

| | Esperado | Salió |
|---|---|---|
| respuesta | `200` con el balance | `200`, `recibidas: 271`, `incidencias: 50`, `nuevas: 271` |
| `incident_runs.payload_version` | `4` | `4` |
| `run_packages` con `net_revenue` | 250 de 271 | **250 de 271** |
| suma de `net_revenue` | 2.615,16 € | **2.615,16 €** |
| filas con `net_revenue` a `0` | ninguna | **ninguna**; las 21 que faltan, a `NULL` |

Y el reparto por ruta que pinta la pantalla coincide envío a envío con el que publica el bot
en su propio listado, que era la comprobación que de verdad importaba:

| Ruta | Ganancia | Con dato |
|---|---|---|
| Ruta 1 | 1.011,86 € | 52 de 59 |
| Ruta 2 | 137,90 € | 11 de 14 |
| Ruta 3 | 605,95 € | 64 de 69 |
| Ruta 4 | 115,10 € | 22 de 25 |
| Ruta 5 | 589,48 € | 68 de 71 |
| Ruta 6 | 154,87 € | 33 de 33 |
| **Las rutas** | **2.615,16 €** | **250 de 271** |

⚠️ **Esos 2.615,16 € no son la ganancia del día**, que fueron 4.871,10 €. La diferencia son
envíos sin ruta, que no viajan en `paquetes[]`. Si una pantalla acaba rotulando esa suma como
«ganancia del día», está diciendo la mitad.

**Una corrección a lo que decía esta fase antes de hacerse:** hablaba de poner la ganancia
«junto a los paquetes y el volumen que ya se pintan» en el detalle de la jornada. **El volumen
no se pinta ahí**: vive en el calendario de capacidades (fase 6.E) y esta pantalla nunca lo ha
enseñado. La ganancia va junto al recuento de paquetes, y sola.

**Decisión abierta:** si la rentabilidad merece pantalla propia —por ruta y por comercio, con
rango de fechas— o se queda como una columna más de la jornada. Se empieza por lo segundo, que
es lo que se pidió, y se decide con el cliente cuando lo vea funcionando.

### Fase 14 — Configuraciones → Rentabilidad. Hecha el 19/08/2026

**Qué pide el cliente:** decidir él **cuántos días hacia atrás** pide el bot en Envexpress al
buscar la ganancia. Hoy son 3, fijos en el código del bot. Pasa a ser el segundo parámetro
configurable, en un módulo propio llamado **Rentabilidad** (§3.2).

**Lo que NO es esta fase:** una pantalla nueva. La fase 11 dejó **una sola pantalla que sirve a
todos los módulos**, así que esto no lleva ruta, ni componente, ni migración: es una entrada en
`SettingsCatalog::modules()`, un tipo nuevo y una línea en el controlador del maestro.

**14.A — El catálogo. Hecho.** `profitability` en `SettingsCatalog::modules()`, junto a `bot` y
`capacity-calendar`, con un solo campo `lookback_days`. Apunta a la pantalla de incidencias
(`'route' => 'incidents'`), que es donde se ven los importes que este ajuste hace aparecer o
faltar. **Ni ruta, ni componente, ni migración**: el módulo salió en la barra lateral solo,
porque el menú se arma recorriendo el catálogo, y hereda el permiso `settings.view` de la fase
12 sin declarar nada.

**14.B — `TYPE_DAYS`. Hecho**, y es un tipo aparte y no `TYPE_MINUTES` con otra etiqueta,
porque las dos reglas cambian: `['required', 'integer', 'min:0', 'max:30']` contra el
`min:1` sin tope del otro. Declarado en el catálogo y en el Blade, que son los dos únicos
sitios que el propio catálogo avisa; el `<input>` sale con `min="0" max="30"` para que el
navegador acompañe a la validación en vez de contradecirla.

**14.C — Servirlo. Hecho**, con la línea prevista en `RouteMasterController`. **Dos avisos
sobre lo que decía esta fase antes de hacerse:**

1. **El `array_filter` ya tenía callback.** La fase avisaba de «la trampa del `array_filter`
   sin callback», pero desde la fase 11 la llamada era `array_filter([...], fn ($v) => $v !== null)`,
   que descarta nulos y **no** ceros. O sea que el cero llegaba bien sin tocar nada. Lo que sí
   hacía falta era **impedir que alguien lo quite**: ese callback parece ruido eliminable
   —`array_filter` a secas hace «lo mismo» con el otro parámetro—, y quitarlo rompería este
   ajuste en silencio. Ahora lo dicen un comentario y, sobre todo, un test.
2. **Hubo que tocar una cosa más que la línea.** Con `Setting::for()` una vez por módulo, el
   endpoint pasaba a hacer una consulta más, y su test de consultas —está ahí porque el bot lo
   llama por red y el maestro crece— dejaba de pasar. Se añadió `Setting::forModules()`, que
   lee varios módulos de una vez, así que el coste ya no crece con el catálogo. Si mañana entra
   un tercer parámetro de otro módulo, no hay que volver a pensarlo.

**14.D — El test. Hecho.** En `ApiContractTest`, las tres direcciones: entero cuando hay valor,
**presente y a `0` cuando el valor es cero** —el caso que se rompe solo— y ausente cuando nadie
lo ha configurado, sin que un módulo sin configurar arrastre al que sí lo está. En
`SettingsTest`, otros cinco: que el módulo se pinta sin ruta propia, que el 0 se guarda, que el
31 se rechaza y el 30 no, que un negativo se rechaza y que el campo del navegador ofrece el
mismo rango que las reglas.

**Comprobado con peticiones de verdad**, no leyendo el código — `GET /api/rutas` con el token
real, en los tres estados:

| Estado del ajuste | Qué llega en `parametros` |
|---|---|
| sin configurar | `{"semiancho_min": 10}` — la clave **no aparece** |
| `0` | `{"semiancho_min": 10, "dias_atras_ganancia": 0}`, entero |
| `3` | `{"semiancho_min": 10, "dias_atras_ganancia": 3}`, entero |

**Lo que falta, y está en el otro repo.** El bot todavía **no lee** esta clave: su `CONTEXT.md`
§13.11 lista los cuatro cambios que le tocan. Los dos repos pueden ir por separado — una clave
que el bot ignora no rompe nada, y un bot que la busque y no la encuentre corre con su 3—, pero
**hasta que se haga allí, mover este ajuste no cambia nada**. Conviene decírselo al cliente si
lo toca antes de tiempo.

## 8. Pendientes y decisiones abiertas

- [x] **Cerrar con el repo del bot el cambio de `ruta` a texto** (§3). Acordado el 13/08/2026,
      anotado en el `CONTEXT.md` §11.4 del bot **e implementado allí**: `src/rutas.py` la trata
      como texto y agrupa por una etiqueta común (verificado el 17/08/2026 leyendo el repo).
      Lo que sigue sin hacerse es la comprobación de punta a punta —apuntar el `RUTAS_URL` del
      bot a este panel y hacer una corrida real—, que es cómo se cierra la fase 2 del todo.
- [ ] **Del trabajo con el bot (fase 6 de §7) queda un solo punto: el backfill del `codigo`**
      —`panel D`—, y es mejora, no requisito. Los `id` en el maestro, guardar las incidencias,
      la pantalla, el calendario y el enganche en la corrida diaria del bot están hechos; la
      pareja de repos no se ha vuelto a correr junta desde el 13/08/2026.
- [x] **Estado de gestión sobre las incidencias.** Decidido y hecho el 14/08/2026: comentario y
      «atendida», ver §7 fase 9. Se quedó en esas dos y no en «revisada / comentada / asignada»:
      asignar no tiene a quién —son ~5 usuarios que miran la misma pantalla— y tres estados que
      nadie sabe distinguir se rellenan al azar.
- [x] **Conectar el calendario de capacidades con su configuración** (§7, fase 11). Hecho el
      17/08/2026: la ocupación se pinta con el color de su tramo. La duda que quedaba anotada
      aquí —si el color del tramo sustituía al rojo del «se pasa de la capacidad»— se cerró en
      que **conviven**: el exceso pasó a ser una marca ▲ y el color queda para el tramo. El
      porqué, en §7, fase 11.
- [x] **La ganancia (fase 13).** Acordada y hecha el 19/08/2026, en los dos lados: el bot la
      baja de Envexpress y la cruza por código de barras —513 de 543 envíos del 07/08 real,
      4.871,10 €— y la manda en el payload v4; aquí se guarda en `run_packages.net_revenue` y
      se enseña por ruta en el detalle de la jornada. Probado contra ese payload real, ver §7
      fase 13. **Lo único que queda abierto es si la rentabilidad acaba teniendo pantalla
      propia** —por ruta y por comercio, con rango de fechas—: se empezó por la columna en la
      jornada, que es lo que se pidió, y se decide con el cliente cuando lo vea funcionando.
- [ ] **La ganancia del día entero, aplazada el 19/08/2026.** El bot manda un importe por
      paquete, y `paquetes[]` sólo lleva los envíos **con** ruta: el 07/08/2026 eran 2.615,16 €
      de los 4.871,10 € que se facturaron ese día. Los otros 2.255,94 € son de remitentes que
      no están en el maestro y **este panel no los ve ni los verá** hasta que el contrato lleve
      totales de jornada. Mientras tanto, ninguna pantalla puede rotular la suma de las rutas
      como «ganancia del día»: sería la mitad. **La pantalla de la fase 13 ya lo respeta** —dice
      «Ganancia de las rutas» y avisa de que deja fuera los envíos sin ruta—, y hay un test que
      lo fija, así que esto es un pendiente del contrato y no de la interfaz. El bot sí lo tiene resuelto en su listado en
      texto, con una fila `(sin ruta)` aparte.
- [ ] **La ventana de días de la ganancia (fase 14).** **Hecha en este repo** el 19/08/2026:
      módulo «Rentabilidad» en Configuraciones y `dias_atras_ganancia` en `GET /api/rutas`,
      probado con peticiones reales a 0, a 3 y sin configurar. **Queda el bot**, que todavía no
      lee la clave y corre con su 3 — los cuatro cambios están en su `CONTEXT.md` §13.11.
      Mientras tanto el ajuste se puede mover y no hace nada, que es lo único que conviene
      avisarle al cliente. De las dos diferencias con `semiancho_min`, la del tope se resolvió
      con un tipo propio (`TYPE_DAYS`) y la del cero resultó no ser un problema: el
      `array_filter` del controlador ya llevaba callback y sólo descartaba nulos. Lo que hay
      ahora es un test que impide que alguien lo quite.
- [ ] **El `coste`, y con él el margen.** Fuera de la v4 por decisión del cliente. El bot lo
      recibe de Envexpress en la misma respuesta que la ganancia, así que reincorporarlo es una
      columna aquí y una línea allí, sin peticiones nuevas.
- [ ] **¿Pest?** §7 pedía el test de contrato en Pest y está en PHPUnit, porque Pest no está
      instalado y añadirlo choca con la regla 2 de `CLAUDE.md`. Decisión pendiente.
- [ ] **Backfill de `codigo`** para los comercios que lo tengan. Se puede extraer del portal
      en una corrida del bot y precargar en el seeder, y así el cruce nace exacto.
      **Confirmado el 12/08/2026 que el `rutas.xlsx` no sirve para esto**: de los 93 nombres
      sólo dos traen el código incrustado (`(287) Good Id S.L` y `(237) RAYO E ILUMINACIÓN
      SL`). La cifra de §3 —11 de 93 sin código— sale del cruce con el portal, no del Excel.
      Los 93 se sembraron con `codigo` nulo. Pendiente decidir si esos dos se despiezan en
      `nombre` + `codigo`, que cambiaría el `nombre` que el bot cruza hoy contra el portal.
- [ ] **Un nombre del maestro parece traer dos comercios en una celda:**
      `"LIANCHUN HONG, S.L.\t- LOOKAT"` (fila 43 del Excel, con un tabulador dentro). Se
      sembró tal cual, sin inventar nada, pero es casi seguro que el portal devuelve uno de
      los dos y no los dos juntos, así que ese comercio no va a cruzar. **A confirmar con el
      cliente**: si son dos, son dos filas.
- [ ] **Retención de `run_packages`**, anotada en la fase 6 y sin decidir: el bot manda ~500
      filas al día, que son ~180.000 al año. No es un problema para Postgres, pero sí una
      decisión que conviene tomar **antes** de producción, porque implica qué se puede seguir
      consultando de hace un año y qué no. Ojo: borrar filas viejas se lleva por delante la
      gestión de la fase 9 —`handled_at`, quién y su comentario—, que es trabajo de personas y
      no dato del bot.
- [ ] **Dónde se despliega el panel** y cómo lo alcanza el bot. Ligado a la decisión, aún
      abierta en el repo del bot, de dónde corre el bot en producción (hoy WSL2 + cron).
- [ ] **Reflejar en la documentación del bot** que Vallecas ya tiene ruta 6 (§4).

## 9. El fichero de datos del seeder (⚠️ no está en el repo)

El maestro de origen es **`rutas.xlsx`**, y **no está versionado en ningún repositorio**
porque contiene nombres y direcciones reales de los comercios del cliente. Vive a mano en
el repo del bot; en esta máquina, en `../bot-gls/data/rutas.xlsx`.

**Sin ese fichero la fase 1 no se puede sembrar.** Si no lo tienes, hay que pedirlo por el
canal por el que lo entrega el cliente.

Estructura: una hoja llamada `Envios`, 93 filas de datos, encabezados en la fila 1. De sus
9 columnas este proyecto solo usa tres:

| Columna del Excel | Destino |
|---|---|
| `Origen Nombre` | `comercios.nombre` |
| `Mensajero Recogida` | `mensajeros.nombre` |
| `Ruta` | `mensajeros.ruta` |

Las otras seis (`Origen Dirección`, `Horario`, `Origen Localidad`, `Origen CP`,
`Origen Provincia`, `Origen País`) son informativas y **se descartan**.

Se convirtió una sola vez a `database/seeders/data/merchants.csv` y se siembra desde ahí,
sin añadir ninguna librería de Excel a PHP: son 93 filas y una conversión única. Ese CSV
**también está en `.gitignore`**, por el mismo motivo que el Excel.

La conversión se hizo con un script de usar y tirar dentro del contenedor, leyendo el xlsx
como lo que es —un zip con XML— con `ext-zip` y `SimpleXML`, que ya están en la imagen. Dos
detalles por si hay que repetirla: el `Target` de la hoja viene absoluto
(`/xl/worksheets/sheet1.xml`) y este fichero **no tiene `sharedStrings.xml`**, las cadenas
van embebidas en las celdas.

El CSV tiene cabecera `name,courier,pickup_route` y el seeder la verifica antes de nada.

## 10. Seguridad

- `RUTAS_TOKEN`, `DB_PASSWORD` y `SEED_USER_PASSWORD` viven solo en `.env`, que está en
  `.gitignore`. **Ninguna clave debe escribirse en este documento ni en ningún otro del
  repo**, ni como ejemplo.
- **El historial nunca copia campos sensibles.** `Auditable` excluye los `$hidden` del
  modelo, que es lo que deja el hash de la contraseña y el `remember_token` fuera de una
  tabla que no se borra nunca. Atado al `#[Hidden]` del modelo y no a una segunda lista, para
  que no se desincronicen; hay un test que lo fija.
- El usuario con el que se entra al panel lo crea `InitialUserSeeder` leyendo
  `SEED_USER_EMAIL` y `SEED_USER_PASSWORD` del `.env` vía `config('panel.initial_user')`.
  **No tiene valores por defecto a propósito**: revienta si faltan, en vez de inventarse una
  contraseña de desarrollo que acabaría en producción el día que alguien despliegue sin
  mirar. Es idempotente, así que volver a pasarlo con otra clave en el `.env` es la forma de
  recuperar el acceso.
- Los nombres y códigos de los comercios son **datos comerciales del cliente**. Ni el
  `rutas.xlsx` ni el CSV derivado se versionan (§9).
- `GET /api/rutas` devuelve el maestro completo. El token es lo único que lo protege:
  tratarlo como una contraseña.
- **La pantalla de copias (§7, fase 7) es el permiso más fuerte del panel**, y desde el
  18/08/2026 está detrás de uno: `backups.manage`, que sólo lleva el Administrador (§7, fase
  12). Quien descarga una copia se lleva la base entera del cliente en un fichero, y quien
  restaura una la sustituye; por eso ese módulo no tiene un permiso de «sólo ver», que ahí no
  protegería de nada. La descarga (`/backups/download`) lo comprueba por su cuenta: es una ruta
  normal y no pasa por el componente. La contraseña de la base nunca viaja en la línea de mandato: va por
  `PGPASSWORD`, porque los argumentos de un proceso los ve cualquiera con acceso a la máquina.
