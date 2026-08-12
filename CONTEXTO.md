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
  ┌──────────────────┐        GET /api/rutas          ┌──────────────────┐
  │  panel-bot-gls   │ ◀───── (1 vez al día, al  ───── │     bot-gls      │
  │  Laravel + PG    │        arrancar la corrida)     │  Python + cron   │
  └──────────────────┘                                 └──────────────────┘
```

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

### ⚠️ Cambio de contrato sin cerrar: `ruta` pasa de número a texto

**Decidido el 12/08/2026 en este repo. Todavía NO acordado con el repo del bot.**

Las rutas dejaron de ser un número estático para ser entidades con nombre libre, renombrables
desde el panel (§4). `1`…`6` pasan a ser etiquetas, no identidad. En consecuencia el endpoint
servirá `"ruta": "1"` (texto) donde el contrato dice `"ruta": 1` (número).

Que la fase 2 no esté construida es lo que hace barato este cambio: **hoy no hay una sola
línea sirviendo ese JSON**, así que el coste está entero en el lado del bot.

Lo que hay que comprobar en `bot-gls` antes de dar la fase 2 por cerrada:

- Que la clave de agrupación admita texto. Si agrupa por `int`, `"1"` puede colar por
  coerción y `"Vallecas"` no — y eso es un fallo silencioso, de los que §3 llama peores que
  romperse.
- Que los informes no formateen la ruta como número.
- Que la validación de entrada del bot (regla 2, más abajo) acepte `mensajero: null`, que
  ahora puede pasar cuando una ruta se queda sin conductor.

Mientras no se cierre, el `rutas.xlsx` sigue siendo el recambio y el bot no se rompe.

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

## 4. Modelo de datos

```
rutas:       id, nombre (unique†), deleted_at, timestamps
mensajeros:  id, nombre (unique†), ruta_id (nullable, unique†), deleted_at, timestamps
comercios:   id, nombre, nombre_normalizado (generada, unique†),
             codigo (int, nullable, unique†), ruta_id, deleted_at, timestamps

† único sólo entre los vivos — ver "Borrados pasivos" más abajo.
```

```
rutas 1──1 mensajeros        quién la conduce hoy
  │
  └──* comercios             de qué se compone la ruta  ← el maestro
```

**La ruta es la entidad duradera; el mensajero es lo que rota.** Un mensajero deja la
empresa y entra otro, pero la ruta y sus comercios siguen siendo los mismos. Por eso `rutas`
es una tabla propia con nombre libre, `mensajeros.ruta_id` dice quién la lleva ahora, y el
comercio pertenece a la **ruta**, no a la persona. Dar de baja al mensajero no toca el
maestro: hay un test que lo fija.

**Por qué el comercio no apunta al mensajero.** Si lo hiciera, borrar a esa persona dejaría
al comercio huérfano de ruta, que es justo el dato que el bot necesita. Con `comercios.ruta_id`
hay **una sola fuente de verdad**: es imposible que un comercio esté en una ruta distinta a
la que dice su ruta. El `mensajero` que pide el contrato sale derivado
(`comercio → ruta → mensajero`, un `hasOneThrough`), no de una FK propia.

**`mensajeros.ruta_id` es único** porque el contrato sirve un solo `mensajero` por comercio
(§3): dos mensajeros en la misma ruta lo dejarían ambiguo. Es nullable —un mensajero recién
dado de alta puede no tener ruta— y en Postgres el índice único deja pasar varios NULL, así
que puede haber varios sin asignar.

**Las FK, y por qué cada una borra como borra.** `comercios.ruta_id` es `restrictOnDelete`:
cargarse una ruta con comercios dentro tiene que ser una decisión explícita, no un efecto
colateral. `mensajeros.ruta_id` es `nullOnDelete`: borrar una ruta no puede borrar a una
persona. Con borrado pasivo ninguna de las dos llega a dispararse casi nunca; quedan como
red para el `forceDelete`.

### Borrados pasivos

Las tres tablas del maestro usan `SoftDeletes`, y también `users`. Dar de baja no destruye:
el maestro es el histórico del negocio y un borrado en falso es indistinguible de un error de
captura. En `users` sirve además para quitarle el acceso a alguien sin perder de quién era la
cuenta — un usuario dado de baja no pasa `Auth::attempt`, porque el proveedor de Eloquent
consulta el modelo y arrastra el scope de `SoftDeletes`.

**Los índices únicos son parciales, con `WHERE deleted_at IS NULL`.** No es un adorno: un
índice único normal cuenta también las filas dadas de baja, así que el sustituto de un
mensajero no podría heredar su ruta —la fila del saliente seguiría ocupando `ruta_id`— ni
podría volverse a dar de alta un comercio con el nombre de uno retirado. El Blueprint de
Laravel no expresa índices parciales, de ahí el `DB::statement` en las migraciones.

**Las reglas de validación llevan `whereNull('deleted_at')`** para decir exactamente lo mismo
que el índice. Si no, el panel avisaría de un choque con un registro invisible que la base sí
deja crear.

**`Ruta` se niega a darse de baja si le quedan comercios vivos**, y eso vive en el modelo, no
en la FK: sin `DELETE` real no hay nada que restringir. Sin esa comprobación, dar de baja una
ruta dejaría a sus comercios apuntando a una ruta invisible — seguirían en la base, fuera del
maestro que consume el bot, y sin que nadie lo note. Que es justo la clase de fallo silencioso
que §3 llama peor que romperse.

**El seeder resucita**: si algo que estaba dado de baja vuelve a aparecer en el maestro de
origen, es que está vigente. Se revive en vez de crear una fila nueva.

**La columna generada.** Postgres es *case-sensitive*, así que la unicidad del nombre hay
que hacerla explícita:

```php
$table->string('nombre');                       // tal cual — es lo que sirve el JSON
$table->string('nombre_normalizado')
      ->storedAs("upper(regexp_replace(trim(nombre), '\\s+', ' ', 'g'))");
$table->unique('nombre_normalizado');
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
| Auth de la API | Bearer estático contra `config()` |
| Terceros | Solo `livewire/livewire` |

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

**Lo que NO se va a construir** (dicho aquí para que no se cuele después): roles y permisos,
multi-tenant, API de escritura, dashboard con gráficas. Son ~5 usuarios internos y dos
tablas.

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
| 2 | `GET /api/rutas` + token + test de contrato | — |
| 3 | CRUD Livewire de comercios y mensajeros | — |
| 4 | Historial de cambios | **Alcance sin confirmar** — ver §8 |
| 5 | Imagen de producción y despliegue | — |

**El orden importa.** La fase 2 va antes que cualquier pantalla: es el producto real, y en
cuanto esté, el bot puede apuntar su `RUTAS_URL` a `http://localhost:8000/api/rutas` y
probarse de punta a punta con el panel todavía sin una sola vista.

### Fase 0 — Hecha el 12/08/2026

Verificado, no solo ejecutado: `app` responde 200 con `<title>Panel Bot GLS</title>`,
`vite` sirve `resources/css/app.css` con `createHotContext` (hot reload vivo), `postgres`
*healthy* con las 3 migraciones del esqueleto aplicadas, `APP_KEY` generada y los ficheros
con el dueño correcto (no root).

No hizo falta configurar `server.hmr.host`: el esqueleto de Laravel 13 ya trae Tailwind 4
cableado (`@tailwindcss/vite` + `@import 'tailwindcss'`) y, con el 5173 publicado, el
navegador llega a Vite por `localhost` sin más.

### Fase 1 — Hecha el 12/08/2026

- Migraciones de `rutas`, `mensajeros` y `comercios` según §4, con la columna generada, los
  índices únicos parciales, el borrado pasivo y las FK con el borrado que le toca a cada una.
- Modelos con `Ruta hasMany Comercio`, `Ruta hasOne Mensajero` y el `mensajero` de un
  comercio derivado por `hasOneThrough`, no por FK propia.
- `MaestroRutasSeeder` carga los 93 comercios desde el CSV. **Ver §9: el fichero de origen
  no está en el repo.** Es idempotente y no pisa `codigo` al re-sembrar, para no borrar un
  backfill hecho a mano. Aborta si un mensajero aparece en dos rutas o una ruta con dos
  mensajeros: son los dos choques que dejarían el contrato ambiguo.
- Validación en `Ruta::reglas()`, `Mensajero::reglas()` y `Comercio::reglas()` — en el modelo
  para que el CRUD de la fase 3 las reutilice en vez de reescribirlas: `nombre` obligatorio,
  `codigo` único cuando no es nulo, `ruta_id` existente y no ocupada por otro mensajero. El
  duplicado por mayúsculas se comprueba contra `nombre_normalizado`, no contra `nombre`, o
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

### Fase 2 — El endpoint

- `GET /api/rutas` devolviendo exactamente el JSON de §3.
- Middleware que compara el header `Authorization: Bearer …` contra `config('rutas.token')`
  (que lee `RUTAS_TOKEN` del `.env`). `401` si no cuadra.
- **Test Pest contra el contrato**: forma del JSON, tipos, `ruta`/`codigo` nulables, y que
  sin token o con token malo responde `401`. Este test es lo que impide que un refactor
  futuro rompa al bot en silencio.
- Sin sesión, sin CSRF, sin dependencias de la UI.

### Fase 3 — CRUD

- Listado de comercios con búsqueda por nombre y filtro por ruta (componente Livewire).
- Alta/edición de comercio: nombre, código, **ruta** (no mensajero: el comercio pertenece a
  la ruta, §4).
- CRUD de rutas: nombre, renombrable.
- CRUD de mensajeros: nombre y ruta que conduce, que puede quedar sin asignar.
- Login propio (~1 componente Livewire + `Auth::attempt`), sin registro público. El usuario
  ya existe: lo siembra `UsuarioInicialSeeder` desde el `.env` (§10). Falta sólo la pantalla.
- Blade y Tailwind escritos a mano; nada de librerías de componentes.

### Fase 4 — Historial de cambios

Pendiente de confirmar alcance (§8). Si entra: `updated_by` en ambas tablas y una tabla de
historial con qué comercio cambió de ruta, cuándo y quién.

### Fase 5 — Producción

`Dockerfile` multi-stage **distinto del de desarrollo** (sin Composer, sin volumen montado,
con `opcache` y los assets ya compilados), decidir dónde se despliega y cómo lo alcanza el
bot.

## 8. Pendientes y decisiones abiertas

- [ ] **Cerrar con el repo del bot el cambio de `ruta` a texto** (§3). Es lo único que
      bloquea dar la fase 2 por terminada, y cuanto antes se hable, más barato: hoy no hay
      nada sirviendo ese JSON.
- [ ] **Historial de cambios: ¿dentro o fuera del alcance?** Argumento a favor: este maestro
      determina si un envío se marca como incidencia. Si alguien mueve COBO FAMILY de la
      ruta 3 a la 5 y el informe del día siguiente cambia, hay que poder saber quién y
      cuándo — el propósito del sistema entero es control de calidad, y el dato que lo
      gobierna sin historial es un punto ciego. Coste bajo. **Preguntado al usuario el
      12/08/2026, sin responder todavía.**
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

Se convirtió una sola vez a `database/seeders/data/comercios.csv` y se siembra desde ahí,
sin añadir ninguna librería de Excel a PHP: son 93 filas y una conversión única. Ese CSV
**también está en `.gitignore`**, por el mismo motivo que el Excel.

La conversión se hizo con un script de usar y tirar dentro del contenedor, leyendo el xlsx
como lo que es —un zip con XML— con `ext-zip` y `SimpleXML`, que ya están en la imagen. Dos
detalles por si hay que repetirla: el `Target` de la hoja viene absoluto
(`/xl/worksheets/sheet1.xml`) y este fichero **no tiene `sharedStrings.xml`**, las cadenas
van embebidas en las celdas.

El CSV tiene cabecera `nombre,mensajero,ruta` y el seeder la verifica antes de nada.

## 10. Seguridad

- `RUTAS_TOKEN`, `DB_PASSWORD` y `SEED_USER_PASSWORD` viven solo en `.env`, que está en
  `.gitignore`. **Ninguna clave debe escribirse en este documento ni en ningún otro del
  repo**, ni como ejemplo.
- El usuario con el que se entra al panel lo crea `UsuarioInicialSeeder` leyendo
  `SEED_USER_EMAIL` y `SEED_USER_PASSWORD` del `.env` vía `config('panel.usuario_inicial')`.
  **No tiene valores por defecto a propósito**: revienta si faltan, en vez de inventarse una
  contraseña de desarrollo que acabaría en producción el día que alguien despliegue sin
  mirar. Es idempotente, así que volver a pasarlo con otra clave en el `.env` es la forma de
  recuperar el acceso.
- Los nombres y códigos de los comercios son **datos comerciales del cliente**. Ni el
  `rutas.xlsx` ni el CSV derivado se versionan (§9).
- `GET /api/rutas` devuelve el maestro completo. El token es lo único que lo protege:
  tratarlo como una contraseña.
