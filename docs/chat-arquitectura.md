# Arquitectura del chat (interfaz «chat» de chatui)

Documento de decisiones: qué se decidió, por qué, qué se descartó y qué cuesta. No es un manual de uso. El contrato exacto entre las dos piezas del chat está en
[`chat-api-interna.md`](chat-api-interna.md); la spec pública es `celaut-project/chatui` → `docs/celaut/chat.md` (revisión 6) y `chat.proto`. Las referencias «§N» son de chat.md.

Estado: las decisiones son las acordadas con el responsable del proyecto. El mapa de ficheros (§6) refleja el repo tal y como quedó tras implementarlo.

## 1. Resumen

Personal Nutrition (un solo microVM Celaut) declara un **segundo slot de API** que sigue la interfaz «chat» (§3: `tcp` + `http2` + `grpc` + `chat`), además del slot HTTP :8000 del panel (y un tercero, `cards`, para la lista de la compra: ver §2k).
La app chatui se conecta a ese slot y conversa con la instancia. La instancia **es** la conversación (§2, «Session»): hay una sola **por instancia**: cada instancia de Laravel tiene la suya, aunque compartan la base PostgreSQL.

```
 chatui (cliente)
    │  gRPC sobre HTTP/2 en claro (h2c)
    ▼
 túnel del nodo  ── frontera de acceso: solo llega quien conoce el token de la instancia
    │
    ▼  slot :50051 (declarado en service.json; protocol_stack http2 / grpc / chat)
 traductor gRPC (Python, docker/chat/, usuario propio, entorno vacío; SIN estado, sin credenciales de la base)
    │  HTTP + Bearer CHAT_SECRETO, solo 127.0.0.1:8001 (no declarado en service.json: no tunelable)
    ▼
 Laravel (usuario app, FrankenPHP)  ── fuente de verdad: mensajes, archivos, secretos pendientes, ajustes
    │                         │
    │ SQL                     │ encola «responder»
    ▼                         ▼
 SQLite del disco de la     cola (dos workers: queue:work --queue=default para menús y --queue=chat para el chat)
 instancia (conexión `chat`:   │
  chat_mensajes, chat_archivos) ▼
                          motor de respuesta
                           ├─ bot básico (sin IA)                      [si no hay token de Claude]
                           └─ modo Claude: runner HTTP 127.0.0.1:9000 → claude -p (usuario agente)
                                 · instantánea de SOLO LECTURA de la base, exportada a archivos
                                 · acciones propuestas → las valida y ejecuta Laravel
```

Piezas y quién habla con quién:

- El cliente solo habla gRPC con el traductor. El traductor solo habla HTTP con Laravel. Laravel es el único que toca Postgres. El agente solo habla con Laravel (por el runner, como ya hacía con los menús).
- Las respuestas **no se calculan en la petición**: `SendMessage` guarda el mensaje del usuario y encola el trabajo (§2 «Sends without a wait»). La respuesta crece en la base y el traductor la reenvía como deltas (§5.2).
- Los archivos (fotos de comidas) viajan por `FileService` (§6.4), se guardan en `chat_archivos` y se enlazan a `registros_comida`.

## 2. Decisiones y alternativas descartadas

### (a) Traductor gRPC aparte, en Python

**Decisión.** Una pieza fina en Python (`grpcio`) que implementa `ChatService` y `FileService` y traduce a la API interna.

**Descartado.**
- *gRPC dentro de PHP.* FrankenPHP/Caddy sirven HTTP/1.1, 2 y 3, pero no hablan gRPC hacia la aplicación (no hay el manejo de trailers y streaming bidireccional que exige `Connect`, §6.5). RoadRunner o Swoole sí podrían, pero añaden un segundo runtime de PHP al microVM, con su propio modelo de memoria, solo para esto.
- *Binario Rust/Go.* Más ligero en memoria y de arranque rápido, pero el proyecto no tiene esa cadena de compilación en la imagen, y la pieza es tan fina que el coste de CPU de Python no es el cuello de botella (el cuello es el agente).

**Por qué Python.** Ya está en la imagen (runner del agente, página de estado), `grpcio` y `grpc_tools` están disponibles, y el mismo estilo de pieza (usuario propio, `env -i`) ya existe en `start.sh`.

### (b) Laravel como fuente de verdad; traductor sin estado

**Decisión.** Todo estado (mensajes, secuencia, archivos, secretos, ajustes) vive en Laravel: el chat en el SQLite del disco de la instancia; los datos del hogar en PostgreSQL. El traductor solo mantiene, por conexión `Connect`, lo que ya envió a ese cliente (última `sequence`, longitudes de los mensajes abiertos). Si muere, el cliente reconecta y se resincroniza con `GetMessages` (§6.3).

**Por qué.** La instancia puede compartir base con otros dispositivos; el estado en un proceso Python local no se vería desde ellos. Y un traductor sin estado se reinicia sin pérdida.

**Coste.** Cada operación es un salto HTTP más. A 127.0.0.1 es despreciable frente al resto, pero hay dos sitios que validan (ver §3).

### (c) Historial y archivos en un SQLite del disco de la instancia

**Decisión (revisada).** Mensajes, secuencia, fotos (`chat_archivos`, hash SHA3-256 único) y peticiones de secreto viven en un SQLite en `storage/app/chat/chat.sqlite`, conexión Laravel `chat` (WAL, `busy_timeout`, transacciones `IMMEDIATE`). Las migraciones están en `admin/database/migrations/chat/` y `start.sh` las ejecuta con `migrate --database=chat`. PostgreSQL ya no tiene tablas `chat_*` (las quita `2026_11_12_110000_drop_chat_tables`).

**Por qué.** La conversación debe ser independiente entre instancias que comparten base: cada una ve solo la suya. Un SQLite conserva transacciones, índices únicos e idempotencia sin reescribir el código del chat.

**Coste.** Las fotos del chat no son compartidas: un `registros_comida.foto_hash` creado en otra instancia no resuelve aquí y se ve sin foto (`/fotos/{hash}` da 404). El chat no entra en `CopiaBase` ni en la fusión de bases; su copia es la del disco de la instancia (que persiste a reinicios).

### (d) Conversación única; el bot pregunta o deduce el perfil

**Decisión.** Una conversación principal por instancia (§2 «Session», §6.8 hace opcionales las ramas y otras conversaciones: no se implementan, `soporta_ramas: false`). Todos los dispositivos que comparten base ven la misma.

**Desviación honesta de la spec.** §2 dice que «cuando la instancia para, la conversación termina». Aquí el historial persiste en el disco de la instancia y sobrevive a reinicios, pero no se comparte con otras instancias. Es una decisión deliberada, pero hay que vigilar que ningún test de conformidad (§11) asuma una conversación vacía al arrancar.

**Perfiles.** La conversación no pertenece a un perfil del hogar. Quien conversa puede preguntar por varios («qué tiene que comer mi hijo», «qué tengo que comer yo»). El bot básico pregunta con un teclado (§5.5) cuando no puede deducir el perfil; Claude lo deduce del texto y de la instantánea, o pregunta. Nunca se asume un perfil por defecto al registrar una comida.

### (e) Sondeo de ~300 ms frente a LISTEN/NOTIFY

**Decisión.** Mientras haya un `Connect` abierto, el traductor llama a `POST /sincronizar` cada ~300 ms.

**Descartado.** `LISTEN/NOTIFY` de Postgres: es lo elegante, pero exige una conexión persistente por escuchador, y PHP en FrankenPHP clásico (modo sin worker, `num_threads 2`) no mantiene conexiones largas; habría que dar al traductor credenciales de la base, que rompe (b) y la regla de oro de no darlas fuera de Laravel. Con la cola `database` de Laravel tampoco hay un punto único donde emitir el `NOTIFY` para todas las escrituras.

**Efecto con varios dispositivos.** Cada `Connect` abierto es un sondeo independiente: *N* dispositivos conectados son ~3,3·*N* peticiones por segundo contra los 2 hilos de PHP (máximo 6) que comparte el panel. Con 1–3 dispositivos domésticos es asumible; con más hay que subir el intervalo en reposo o pasar a LISTEN/NOTIFY (§5). Un `Connect` sin mensajes abiertos puede sondear más despacio sin romper la spec (§6.5 no fija latencia).

### (f) «Toda la BD» para Claude = instantánea de solo lectura + acciones validadas

**Decisión.** Antes de cada turno en modo Claude, Laravel exporta una **instantánea de solo lectura** (perfiles, menú activo, existencias, últimos registros de comida, feedback…) a archivos que el runner escribe en la carpeta del agente (el mismo mecanismo que `AlmacenAgente::ENTRADA`). Claude lee los archivos y, si quiere cambiar algo, devuelve **acciones** estructuradas (p. ej. «registrar comida») que Laravel valida y ejecuta con sus propias reglas.

**Descartado.**
- *SQL en vivo para el agente.* Le daría control de todo el contenido de la base.
- *Credenciales de la base en el agente.* Idem, y en un microVM compartido con otros dispositivos que usan la misma base.

**Modelo de amenazas.** El agente ejecuta herramientas, incluida Bash, y procesa texto que no controlamos: lo que escribe quien conversa, el texto de una foto, nombres de alimentos. Una inyección de prompt debe poder, como mucho, **proponer** una acción; nunca ejecutarla. Por eso el agente no tiene credenciales ni acceso SQL (la misma regla de `start.sh`: «el agente nunca ve APP_KEY ni la base»), y toda acción pasa por validación de Laravel: perfil existente, fecha y momento válidos, tamaños, y solo el conjunto cerrado de acciones permitidas.

**Catálogo.** Desde el chat se puede hacer lo mismo que en el panel salvo seguridad: comidas y gustos (`registrar_comida`, `corregir_comida`, `anotar_feedback`, `editar_feedback`…), hogares, perfiles y tiempos de cocinado (`crear_perfil`, `editar_perfil`, `fijar_tiempos`…), alimentos y despensa (`crear_alimento`, `anadir_existencia`, `editar_existencia`, `restar_existencias`…) y menús (`crear_menu`, `generar_menu`, `activar_menu`, `elegir_opcion`, `editar_menu_con_comentario`, `intercambiar_menu`, `editar_opcion_menu`, `cambiar_categorias_toma`, `borrar_menu`…). La lista completa y los campos de cada una están en `App\Services\Chat\Claude\Acciones\Grupo*` y en la skill `chat-nutricion`; la lógica compartida con el panel vive en `App\Services\Acciones` (`GestionMenus`, `EditaMenu`, `Despensa`). Quedan fuera usuarios y contraseñas, ajustes de seguridad (login automático, secretos, interruptores del chat, token), copia de seguridad y auditoría. Cada acción se valida con reglas estrictas (ids uuid que existan, enums, fechas, tamaños, tope de 10 por turno) y todo lo ejecutado o rechazado queda en la auditoría.

**Confirmación de lo destructivo.** Las acciones destructivas (borrar cualquier cosa, descartar cambios del menú, activar un menú que sustituye a otro, editar con comentario —rehace el menú entero—, restar existencias, cancelar una generación) **no se ejecutan al llegar la propuesta**. Laravel las guarda como pendientes en `metadata` del mensaje del chat (SQLite, sin tabla nueva) con un token aleatorio de 12 hex que el agente no ve, enseña un resumen legible y un teclado Confirmar / Cancelar, y solo ejecuta cuando llega la confirmación: la tecla envía «Confirmar acción TOKEN» como un mensaje normal de la persona y `RespondedorMixto` lo atiende antes de llamar a Claude o al bot (así funciona aunque Claude esté apagado). Es idempotente (el paso de pendiente a confirmada/cancelada/caducada es un cambio atómico, y confirmar dos veces no ejecuta dos veces), caduca a los 30 minutos (`chat_claude.acciones.caduca_min`) y revalida cada acción con los datos del momento de confirmar. El agente nunca puede confirmar: no escribe mensajes de la persona.

**Coste.** La instantánea puede estar desfasada respecto a la base si otro dispositivo escribe durante el turno, y cuesta tokens y tiempo en cada turno (ver §3). La instantánea incluye también `catalogos.json` (valores válidos de los campos cerrados) y los ids de menús, tomas, existencias, feedback y registros, dentro de los topes de `chat_claude.instantanea`; nunca incluye secretos ni usuarios.

### (g) Bot básico sin IA; widgets y formularios como texto

**Decisión.** Hay dos motores: modo Claude (si hay token de Claude, `App\Services\Menu\TokenClaude`, y el agente responde) y un **bot básico** sin IA. Ambos responden con **widgets** (bloque ```` ```html ````, §5.4) y **formularios/teclados** (bloque ```` ```keyboard ````, §5.5), que son texto dentro de `TextContent`.

**Por qué.** Sin token, el chat debe seguir siendo útil (registrar una comida, ver qué toca hoy) en vez de quedar muerto. Usar bloques de texto evita cualquier extensión del protocolo: el cable no cambia, y un cliente sin widgets los muestra como código. Los widgets están limitados a la lista de permitidos del cliente (sin script ni recursos de red, §5.4).

**Coste.** Los bloques no son normativos (§5.4 y §5.5 «not normative»): un cliente distinto de chatui puede mostrarlos feos. Un bloque sin cerrar en un mensaje abierto se ve como código hasta que se cierra: el motor debe generar bloques completos.

### (h) Petición de secreto para el token de Claude

**Decisión.** Sin token de Claude, el asistente lo pide con una `SecretRequest` (§5.6, §6.9; parte `peticion_secreto` con nombre `CLAUDE_CODE_OAUTH_TOKEN`). El cliente responde por `AnswerSecretRequest`, que llega como `POST /secretos`.

**El valor no toca el historial, la cola ni la auditoría.** Se pasa en memoria de la petición directamente a `TokenClaude::guardar()` (cifrado con `APP_KEY`). No se guarda en mensajes, eventos, `metadata`, logs ni en la tabla `auditorias`; tampoco en el `payload` de ningún trabajo de la cola (el contrato lo obliga: «el valor no se guarda en ningún mensaje, evento, log, metadato ni fila de auditoría»). La respuesta del asistente («token guardado») es un mensaje nuevo con `reply_to` el del mensaje que lo pidió (§5.6).

**Límites a tener presentes.**
- La sección de secretos es **borrador, ronda 8** de la revisión 6: puede cambiar.
- `TokenClaude` guarda el token cifrado en el disco local de la instancia (`claude-token.enc`), **no** en Postgres. Otro dispositivo que comparta la base no lo ve y tendrá que pedirlo también.
- Si la petición se queda sin respuesta, espera indefinidamente (§5.6 «Waiting»); mientras tanto el bot básico sigue respondiendo.

### (i) Seguridad y acceso

- **El túnel del nodo es la frontera.** Quien no conoce el token de la instancia no llega a ningún puerto. El chat **no tiene autenticación propia**: acepta a quien llegue. Es coherente con la spec (el cliente no autentica, §4) pero significa que quien llega puede leer todo el historial y las fotos.
- **Interruptor `chat_habilitado`** (tabla `ajustes`): **activado por defecto**, porque quien tiene la instancia ya está autenticado. Apagado, la API interna responde `503 deshabilitado` y el slot devuelve `UNAVAILABLE` sin razón.
- **Auto-login** (otro interruptor en `ajustes`): **apagado por defecto**. Encendido, al entrar por la web se inicia sesión como un usuario existente elegido (p. ej. `admin@demo.com`) sin pedir contraseña. Es seguro solo mientras el túnel sea la frontera; si alguien expone el :8000 sin túnel, el auto-login lo convierte en acceso de administrador abierto.
- **API interna solo en 127.0.0.1:8001, no declarada en `service.json`**: el nodo no puede tunelarla. Además exige `Authorization: Bearer $CHAT_SECRETO` (generado en `start.sh`, solo lo reciben traductor y app). Un proceso local sin el secreto recibe 401. El agente (usuario `agente`, entorno vacío) no lo tiene.
- El traductor corre con usuario propio y `env -i`, con solo `CHAT_SECRETO` y su puerto.

### (j) Límites

- **2 GiB de memoria** para todo el microVM (`service.json`): FrankenPHP, la cola, el runner con `claude -p`, la página de estado, la base si la lanza como hijo, y ahora el traductor. Una instantánea grande o varios `claude -p` a la vez pueden agotarla.
- **Un solo agente simultáneo** (`AGENTE_MAX_SIMULTANEAS=1`, constante en `start.sh`), **compartido con la generación de menús**. Un runner ocupado devuelve 429. Además la cola es un único `queue:work` y la generación de un menú puede durar horas (`DB_QUEUE_RETRY_AFTER=14400`, `--timeout=0`): por eso el chat usa su propia cola (`chat`) con su propio worker en `start.sh`; aun así, la respuesta de **Claude** sigue compartiendo el único agente con la generación de menús. Es el coste más visible de la arquitectura de un solo microVM.
- **Fotos de 4 MiB** como máximo, hasta 4 por mensaje, `image/jpeg` y `image/png` (§7; el audio no se acepta). Texto hasta 16 384 bytes.
- Carga de petición: Caddy limita el cuerpo a 20 MB; el runner, a 64 MB y 8 MB por archivo.

### (k) Lista de la compra con la interfaz «cards» en otro slot

**Decisión.** La lista de la compra del menú activo es un **mazo de tarjetas** (`cards.md` rev. 1) en un slot propio (`http2` / `grpc` / `cards`, puerto 50052), atendido por el mismo traductor Python (otro servidor gRPC en el mismo proceso, mismo usuario y entorno vacío) contra `/interno/cards` de Laravel, con el mismo Bearer e interruptor. Laravel es la fuente de verdad: calcula la lista (la misma suma que el PDF, menos la despensa), guarda en el SQLite del chat el mazo, las decisiones y los eventos con versión, y suma o resta existencias al decidir. El chat enlaza el mazo con un `slot_link` (chat.md §5.8).

**Por qué.** Un deslizar no es un mensaje: no debe empezar un turno del modelo, y el mazo es un estado vivo que se sustituye, mientras que el historial del chat solo crece (`cards.md` §2). Un slot propio mantiene el contrato del chat intacto y un cliente que no conoce `cards` no ve nada. El mazo se **genera cuando la persona lo pide** (abrir o reconectar la pantalla), con el menú y la despensa de ese momento, así que puede usarse muchas veces: lo ya comprado está en la despensa y no vuelve a salir. Durante una sesión es una foto (una tarjeta comprada sigue ahí con su decisión y «comprado» suma exactamente lo que ponía); si la pantalla se reconecta mientras la sesión sigue (menos de 5 minutos de inactividad y tarjetas sin resolver) se reanuda el mismo mazo. Las decisiones llevan clave de idempotencia y, con efecto en `existencias`, un registro de lo sumado para que deshacer sea exacto.

**Imágenes.** `cards.md` solo admite `image/png` e `image/jpeg`, así que el SVG que dibuja el agente nunca viaja: Laravel lo lee con una lista blanca de etiquetas y atributos y lo guarda en `alimentos.ilustracion` (catálogo compartido: se genera una vez para todas las instancias; columna fuera del núcleo del esquema declarado, así que no cambia la red), y cada instancia lo dibuja con GD a PNG y lo guarda por hash en `chat_archivos` como caché local. Como otra instancia puede haber escrito el SVG, se vuelve a comprobar al leerlo. Hasta entonces, o sin token de Claude, cada alimento usa una ilustración fija de su categoría. El agente solo recibe nombres de alimentos y no toca la base (§2f).

**Coste.** Es un tercer puerto y otro estado que mantener (tablas `cards_*` en SQLite, sin migrar a mano). Dentro de una sesión la foto se queda desfasada si cambian las existencias (se corrige al pedir el mazo otra vez). «Ya lo tengo» no toca la despensa, así que esa tarjeta vuelve a salir en el mazo siguiente hasta que la despensa lo refleje. Generar ilustraciones usa el único agente, compartido con los menús y el chat (§2j), por eso van en lotes pequeños y con tope diario. No hay tests de esta pieza por ahora.

## 3. Beneficios y costes

### Beneficios

| Beneficio | Consecuencia concreta |
|---|---|
| Cumple el protocolo real (gRPC h2c) | chatui funciona sin adaptadores; un `Connect` y subidas de fotos comparten una sola conexión por túnel (§4) |
| Laravel como única fuente de verdad | Reglas de negocio, validación y auditoría en un solo sitio; el traductor se puede reiniciar sin pérdida |
| El agente no tiene credenciales ni SQL | Una inyección de prompt solo puede *proponer* acciones del conjunto cerrado; no lee ni cambia la base por su cuenta |
| Chat en SQLite local | Conversación independiente por instancia, sin cambiar el esquema de PostgreSQL; no se comparte ni entra en `CopiaBase` |
| Bot básico sin IA | El chat funciona sin token de Claude y no depende de la cuota del plan |
| Widgets y teclados como texto | Sin cambios de protocolo; degradan a código en clientes sin soporte |
| La API interna no es tunelable | No hay ruta desde fuera a la API de Laravel del chat, ni con el token del nodo |
| Sin dependencias nuevas de PHP | No se toca `composer.json`; las fotos y la cola usan lo que ya había |
| Reanudación sencilla | Reinicio de traductor o de microVM: el cliente sigue con `GetMessages` y la secuencia (§6.3) |

### Costes y tradeoffs

| Coste | Consecuencia concreta |
|---|---|
| Latencia de la instantánea por turno | Exportar y subir los archivos de la base antes de cada turno de Claude suma segundos; crece con el historial de comidas. Mitigación posible: instantánea incremental o por demanda (no decidida) |
| Coste en tokens | Claude lee la instantánea (o parte) en cada turno; más perfiles y más historial, más tokens y más cerca del límite de uso del plan (`LimiteUso`) |
| Concurrencia con la generación de menús | Un agente y un worker de cola compartidos: el chat espera si hay un menú en marcha; ver §2(j) |
| Tamaño de la base por las fotos | Cada foto (hasta 4 MiB) engorda el disco de la instancia; no hay purga ni miniaturas decididas |
| El formal de la red cambia con cada tabla nueva | `service.json` lleva el esquema versionado en `formal` (`db.table.*`); cada migración de chat obliga a regenerarlo y a que los dispositivos de la red tengan versión compatible (hay un commit reciente de sincronización esquema↔migraciones) |
| Pieza extra a mantener | Un proceso Python más (arranque, vigilancia, usuario, secreto, memoria) y su imagen |
| Doble validación | El traductor valida lo del cable (hash sin tipo, 32 bytes, partes sin `kind`) y Laravel el resto; si divergen, el mismo error se ve distinto según quién lo detecte. El contrato fija el reparto |
| Conformidad con una spec en revisión | Revisión 6; los secretos son borrador ronda 8; título y sugerencias son propuestas «to review». Pueden cambiar y obligar a retocar traductor y contrato |
| Sin autenticación propia | Cualquiera que llegue por el túnel lee todo el historial y las fotos y puede registrar comidas |
| Auditoría y privacidad de las fotos | Las fotos de comida y lo que se conversa sobre salud son datos personales; viven en claro (sin cifrar) en la base y en sus copias. Qué acciones del chat se auditan, y con qué usuario (no hay sesión), está por decidir; los secretos, nunca |
| Sondeo | Carga constante mientras haya `Connect`; ver §2(e) |
| Desviación de §2 «Session» | El historial persiste tras un reinicio, la spec dice que la conversación termina con la instancia |

## 4. Flujos

**Mensaje con foto.**
1. El cliente calcula SHA3-256 y llama a `QueryFiles` → traductor → `POST /archivos/consultar` (¿ya está disponible?).
2. Si no, `UploadFile` en trozos → el traductor reensambla (≤ 4 MiB) → `PUT /archivos/{hex}`. Laravel comprueba tamaño, tipo y hash; guarda en `chat_archivos`. Fallo: nada guardado.
3. `SendMessage` con `ImageContent` y el hash → `POST /mensajes`. Laravel valida en el orden de §6.1, crea el mensaje del usuario (`COMPLETED`) y encola «responder».
4. El worker procesa: el bot (o Claude) puede proponer registrar la comida; al confirmarse, se crea el `registros_comida` enlazado a la foto.
5. El traductor, sondeando, recibe el mensaje nuevo y los deltas de la respuesta.

**Pregunta sobre el menú en modo Claude.**
1. `POST /mensajes` → mensaje de usuario + trabajo en cola; la respuesta se crea `PENDING`.
2. El worker exporta la instantánea de solo lectura y llama al runner (`POST /ejecuciones` con los archivos, token en la petición como ya hace `EjecutorClaude`). Si el runner devuelve 429 (ocupado), la respuesta espera o falla con un texto claro.
3. Se lee el flujo del runner; el texto crece en el mensaje (`IN_PROGRESS`) y llega al cliente como deltas (§5.2). Si hay que decidir perfil, Claude lo deduce o lo pregunta con un teclado.
4. Al terminar: `COMPLETED` con sugerencias opcionales; si falla, `FAILED` con `error` corto para el usuario.

**Acción de registrar comida propuesta por Claude y validada.**
1. Claude no escribe en la base: deja, en el resultado, una acción estructurada (p. ej. `{"accion":"registrar_comida","perfil":…,"fecha":…,"momento":…,"descripcion":…}`).
2. Laravel la valida (perfil existente en el hogar, fecha, momento permitido, longitudes, foto existente) y, si pasa, la ejecuta con sus modelos y reglas. Si no, lo cuenta en la respuesta y no cambia nada.
3. El mensaje final confirma lo hecho o explica por qué se rechazó. Nunca llega al agente más de lo que ya tenía en la instantánea.

**Petición del token de Claude.**
1. Sin token, el bot responde con un mensaje `COMPLETED` que incluye la parte `peticion_secreto` (no puede ir en un mensaje abierto, §5.6).
2. El usuario responde en la app: `AnswerSecretRequest` → `POST /secretos` con el valor o `omitido`.
3. Laravel valida (§6.9: existe, uno de los dos, no vacío, ≤ 8 192 bytes), guarda con `TokenClaude::guardar()` y registra solo «respondida», nunca el valor. Crea un mensaje nuevo de respuesta (`reply_to` el de la petición). Una segunda respuesta devuelve `OK` sin cambios. Omitir deja el bot básico.

**Reanudación tras reinicio.**
- *Traductor muerto:* el cliente pierde `Connect`, reintenta (`UNAVAILABLE` sin razón); al volver, `GetMessages` con `after_sequence` y las longitudes de los abiertos se resincronizan contra Laravel (§6.3, §6.5).
- *MicroVM reiniciado:* el historial está en el SQLite del disco. Un trabajo que estaba a medias muere con la cola: los mensajes que quedaron `PENDING` o `IN_PROGRESS` se marcan `FAILED` («se interrumpió») al arrancar y el usuario puede reenviar (la clave de idempotencia evita duplicados). El runner ya trata sus ejecuciones interrumpidas así (`interrumpida: true`).
- *Petición de secreto abierta:* sigue en el historial y se puede responder después.

## 5. Riesgos abiertos y qué cambiar si crece

| Riesgo | Cómo vigilarlo |
|---|---|
| Chat bloqueado por un menú largo en la cola | Medir el tiempo entre mensaje y primera respuesta; si pasa de unos segundos con menú en marcha, separar cola y worker del chat |
| Memoria (2 GiB) | Observar OOM del microVM; limitar tamaño de la instantánea; mantener `AGENTE_MAX_SIMULTANEAS=1` (constante en `start.sh`) |
| Crecimiento de la base por fotos | Tamaño de `chat_archivos` en la página de copia de seguridad; si pasa de unos cientos de MiB, plantear purga, miniaturas o almacenamiento de objetos |
| Inyección de prompt desde fotos/texto | Revisar que el conjunto de acciones sea cerrado y que Laravel valide cada campo; mantener las pruebas con acciones maliciosas |
| Spec que cambia (secretos ronda 8, título, sugerencias) | Seguir el historial de revisiones (§15) y mantener el traductor y el contrato versionados juntos |
| Auto-login con el :8000 expuesto sin túnel | Dejarlo apagado por defecto; avisar en el interruptor |
| Fuga del valor del secreto | Prueba que, tras responder, ni mensajes, ni `jobs`, ni `failed_jobs`, ni `auditorias`, ni logs contienen el valor |
| Varios dispositivos sondeando | Contar peticiones a `/sincronizar` por segundo |

**Si crece:**
- *LISTEN/NOTIFY:* cuando el sondeo pese. Exige un oyente que no sea el traductor sin credenciales (p. ej. un proceso de Laravel con worker persistente) o una decisión explícita de darle una credencial de solo `LISTEN`.
- *Almacenamiento de objetos:* si las fotos dominan el tamaño; mantener el hash SHA3-256 como identidad y `chat_archivos` como índice.
- *Varias conversaciones:* implicaría §6.8 (conversaciones y ramas), `conversation_id` real en cada mensaje y una pertenencia por usuario/hogar; hoy el contrato lo reserva (`conversation_id: null`).
- *Autenticación propia del chat:* si la instancia se expone sin túnel, o si se quieren perfiles con historial privado.

## 6. Mapa de ficheros

Existe hoy (comprobado con `ls` al terminar de escribir):

| Qué | Dónde |
|---|---|
| Contrato traductor ↔ Laravel | `docs/chat-api-interna.md` |
| Este documento | `docs/chat-arquitectura.md` |
| Spec normativa (otro repo) | `/home/josemi/Work/chatui/docs/celaut/chat.md` y `chat.proto` |
| Arranque del microVM, secretos y piezas | `docker/servicio/start.sh` |
| Web (FrankenPHP/Caddy) | `docker/servicio/Caddyfile` |
| Declaración del servicio y esquema versionado (`formal`) | `.service/service.json` |
| Runner del agente (`claude -p`) | `docker/agente/runner.py` |
| Almacén de archivos del agente en Postgres | `admin/app/Services/Agente/AlmacenAgente.php`, `admin/app/Models/AgenteArchivo.php` |
| Ejecución de tandas del agente | `admin/app/Services/Menu/EjecutorAgente.php` |
| Token de Claude | `admin/app/Services/Menu/TokenClaude.php` |
| Cola de generación de menús | `admin/app/Jobs/GenerarMenu.php` |
| Modelos de comidas y perfiles | `admin/app/Models/Perfil.php`, `admin/app/Models/RegistroComida.php` |
| Copia de seguridad y esquema | `admin/app/Services/CopiaBase.php`, `admin/app/Services/EsquemaBaseDatos.php` |
| Scripts de prueba | `docker/pruebas/php.sh`, `docker/pruebas/postgres.sh` |

Del chat (a medida que se implementen):

| Qué | Dónde |
|---|---|
| Traductor gRPC (Python) | `docker/chat/` |
| Controladores de la API interna | `admin/app/Http/Controllers/Interno/` |
| Puerto 8001 solo en 127.0.0.1 | `docker/servicio/Caddyfile` (ya existe el bloque `http://127.0.0.1:8001`) |
| Rutas internas (`/interno/chat`) | `admin/routes/interno.php` (registrada en `admin/bootstrap/app.php`; fotos autenticadas en `admin/routes/web.php`) |
| Tablas del chat (mensajes, `chat_archivos`, secretos) | `admin/database/migrations/chat/` (SQLite, conexión `chat`) |
| Motores: bot básico y modo Claude, instantánea y acciones | `admin/app/Services/Chat/` |
| Trabajo de cola «responder» | `admin/app/Jobs/ResponderChat.php` (cola propia `chat`) |
| Interruptores `chat_habilitado` y auto-login | tabla `ajustes` y página «Ajustes del servicio» (`admin/app/Filament/Pages/AjustesServicio.php`); auto-login en `admin/app/Http/Middleware/AutoLogin.php` |
| Slot gRPC :50051 y `CHAT_SECRETO` | `.service/service.json` y `docker/servicio/start.sh` |
| Lista de la compra: slot `cards` :50052 | `.service/service.json` (api), `docker/servicio/start.sh` (`CARDS_PUERTO`), traductor `docker/chat/cartas.py` y `cards.proto` |
| Lista de la compra: rutas, mazo y decisiones | `admin/routes/interno-cards.php`, `admin/app/Http/Controllers/Interno/CardsController.php`, `admin/app/Services/Compra/` (`ListaCompra`, `Mazo`, `Despensa`, `Ilustraciones`, `SaneadorSvg`, `Rasterizador`, `GeneradorIlustraciones`), `admin/app/Jobs/GenerarIlustraciones.php`, `admin/config/cards.php` |
| Estado del mazo (`cards_*`) | `admin/database/migrations/chat/…create_cards_tables.php`, modelos `admin/app/Models/Cards*.php` |
| Página «Lista de la compra» (solo lectura) y skill del agente | `admin/app/Filament/Pages/ListaCompraPagina.php`, `agente/.claude/skills/ilustrar-alimentos/` |
