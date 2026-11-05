# personal-nutrition


# Personal Nutrition: descripción del proyecto

**Idea general**
Un bot que planifica las comidas de cada día. Recibe información personal (gustos, historial, stock, etc.) y genera un menú semanal con recetas y tiempos de preparación.

**Entradas (IN)**
- **Feedback:** lo que me gusta y lo que no.
- **Historial:** lo que he comido, para no repetir y mantener variedad.
- **Stock:** lo que tengo disponible en casa.
- **Modelo:** qué es lo mejor, es decir, el criterio nutricional o de optimización que guía las decisiones.
- **Calendario:** lo que ya se ha sugerido anteriormente.
- **Tiempos:** cuánto tiempo hay para cocinar en el momento, para cada día de la semana.

**Salidas (OUT)**
Una lista de días (`Dia[]`). Cada día contiene:
- **Fecha.**
- **Comida / cena.**
- **Categorías de comida** (por ejemplo: carne roja, huevo, etc.).
- **Ejemplo de recetas**, cada una con:
  - Ingredientes.
  - Duración previa (preparación anticipada).
  - Duración al momento (cocinado en el momento).

**Flujo**
`IN → BOT → OUT`

---

# Cómo funciona

Es una aplicación Laravel 13 + Filament 5 (`admin/`) que orquesta un agente Claude Code aislado en otro usuario del mismo microVM (`agente/`). La arquitectura, las decisiones y las plantillas vienen de la guía `agentic-laravel/`.

```
navegador ──8000──▶ web ──┐                      ┌──────────────┐
                    queue ┼─ HTTP interno ──────▶│ agente       │ claude -p (skill planificar-menu)
                          │  (Bearer)            └──────┬───────┘
                          └── archivos por HTTP ◀────────┘   encargo ▶  /  ◀ resultado (guardados en PostgreSQL)
```

- **Laravel nunca ejecuta `claude`.** Le pide la ejecución al runner del agente por HTTP interno y lee su salida por *polling*.
- **Los ficheros viajan en las peticiones HTTP** al runner (encargo y base del agente al lanzar, resultado al terminar) y **Laravel los guarda en PostgreSQL** (`agente_archivos`, `AlmacenAgente`), igual que el registro de cada tanda. El runner no tiene credenciales de la base; así cualquier Laravel que comparta la base ve el resultado y el registro, aunque la ejecución la haya hecho el agente de otro dispositivo.
- **El agente no ve** la base de datos, el `.env` ni `APP_KEY`. Solo recibe el token de Claude, y solo durante una ejecución.
- **Cada respuesta del modelo se cuenta** (hook `Stop` + `consumos.py`) y Laravel guarda su coste con la tarifa de ese momento. Cambiar precios no reescribe el histórico.

## Qué hace el sistema

1. **Hogar:** tiempos de cocinado por día y por toma (comida, cena, y también desayuno, almuerzo y merienda; en estas tres, vacío = sin dato y no se limita), perfiles (cada uno con su modelo nutricional, restricciones, gustos y notas libres como el gimnasio), despensa (existencias, sin registro de compras).
2. **Seguimiento:** se registra lo que se comió *de verdad*. Lo planificado no cuenta como comido.
3. **Menús:** se elige la semana y se pulsa «Generar menú». El agente fija primero las categorías de alimento de cada comida y cena y luego propone 2-3 recetas dentro de ellas (todas comparten categorías). Además da una toma individual de desayuno, almuerzo y merienda por perfil y día, y una explicación por perfil. La administración valida la entrega (contrato versión 3, ver abajo) y la importa. El título del menú lleva el rango de fechas: «Nombre · del 2 al 8 de noviembre».
4. **PDF:** en la ficha del menú se elige la opción preferida de cada toma (por defecto, la primera) y «Descargar PDF» genera un documento. Orden: título con fechas, lista de la compra (comida y cena elegidas más todas las tomas individuales), «Cada persona» con su explicación y, por último, cada día con la comida y la cena elegidas y debajo las tomas individuales de cada persona. Se regenera cada vez, así que siempre refleja las elecciones actuales.

5. **Menú activo:** cada hogar tiene un menú activo (`hogares.menu_id`). El primer menú que se importa lo queda solo; después se cambia con «Activar» en la ficha. El listado de menús marca cuál es. Al activar otro se descartan los cambios del hogar.
6. **Editar con comentario:** en la ficha, «Editar con comentario» pide al agente que rehaga el menú entero siguiendo el texto («sin pescado el jueves»). Usa la skill `editar-menu` y la misma validación. No se guarda copia del menú anterior. Las elecciones se conservan solo si su opción sigue existiendo; los cambios del hogar se descartan. Si la edición falla, el menú sigue terminado con el motivo en `error`.
7. **Intercambio:** lo comido y el menú activo con sus cambios van al agente (skill `intercambiar-menu`), que puede mover platos entre tomas o sustituirlos dentro de las categorías de la toma. Devuelve `{"cambios": {"AAAA-MM-DD/comida": {"categorias": [...], "opciones": [...]}}}`, que se valida con las reglas de las opciones y se guarda en `hogares.cambios`; `menus.contenido` no se toca. Un intercambio nuevo se suma a los anteriores. Solo vale para el menú activo.
8. **Menú vigente y por defecto:** `Menu::contenidoVigente()` fusiona `contenido` con `hogares.cambios` (solo si el menú es el activo). La ficha, las elecciones y el PDF leen el vigente; el interruptor «Ver menú por defecto» muestra el original y «Descartar cambios» borra los cambios. Las tomas cambiadas llevan la marca «Cambiada».
9. **Lo comido:** el formulario pide hogar (si solo hay uno, se rellena y se oculta), uno o varios perfiles, fecha y momento. El campo de descripción sale deshabilitado con la receta programada (la opción elegida del menú activo, con los cambios). «Guardar» registra esa receta (`origen=menu`, `menu_id`, `opcion_ref`) y no llama al agente. «Otra cosa» vacía el campo para escribir lo comido (casa o fuera) y, al guardar, lanza el intercambio. Se crea un registro por perfil.
10. **Calendario:** página «Calendario» (grupo Seguimiento) con selector de hogar (solo si hay varios), de perfil y de vista (mes o semana). Cada día enseña la comida y la cena de ese perfil: lo comido de verdad (registro de «Lo comido»), lo programado en el menú activo con los cambios vigentes (de hoy en adelante) o lo programado que no se anotó (días pasados, con borde discontinuo). Si hay registro, manda sobre lo programado. Al pulsar un día se ve el detalle: opciones de la toma, desayuno, almuerzo y merienda propuestos y otras tomas anotadas. Los datos salen de `App\Filament\Support\DatosCalendario`.

Las tres tareas (generar, editar, intercambiar) comparten una sola infraestructura: el modelo `Menu` con la columna `tarea`, el mismo job, estado, registro y consumos.

### Contrato de entrega (versión 3)

Encargo y entrega llevan `version: 3`. El encargo añade `minutos_desayuno`, `minutos_almuerzo` y `minutos_merienda` a `hogar.tiempos`. La entrega lleva `dias[]` con `comida` y `cena` (`categorias` + `opciones`), `individuales[]` (una entrada por perfil con `desayuno`, `almuerzo` y `merienda`, una sola versión cada una) y, arriba, `explicaciones[]` (una por perfil). Cada opción de comida o cena puede llevar `ajustes[]` (`perfil_id` + `texto`, sin perfil repetido) para que cada persona adapte el mismo plato; las entregas v2 ya guardadas se siguen leyendo. La entrega de un intercambio es distinta: `entrega.version` 3 y `cambios` (ver arriba). Las reglas están en `admin/app/Models/Menu/ValidaEntrega.php` y en `agente/scripts/menu.py`: son las mismas y hay que cambiarlas a la vez. Detalle en `agente/.claude/skills/planificar-menu/SKILL.md`.

## Conectar Claude

El token **no va en el `.env`**: se pega en el panel y se guarda cifrado con `APP_KEY`.

1. En una máquina con Claude Code, ejecuta `claude setup-token` y copia el token.
2. En el panel: **Ajustes → Conexión con Claude → Pegar clave**.
3. Pulsa **Probar conexión**: debe responder «Claude responde».

Si cambias `APP_KEY`, el token guardado deja de poder descifrarse y hay que volver a pegarlo.

## Arranque (servicio Celaut)

`.service/` empaqueta la aplicación como servicio de [celaut-project/nodo](https://github.com/celaut-project/nodo) (rama `dev`; guía: `docs/PACKING.md`, `docs/TUNNELING.md`). Es **un solo microVM** con todas las piezas: el runner del agente, FrankenPHP (Caddy con PHP embebido; solo HTTP, el TLS lo pone `nodo tunnel`) y la cola, arrancados por `docker/servicio/start.sh`. Mantiene la regla de oro: cada pieza corre con su usuario y un entorno vacío (`env -i`), de modo que el agente no ve `APP_KEY` ni la base.

| Recurso | `at_init` = `at_most` | Por qué |
|---|---|---|
| Memoria | 2 GiB | PHP (web + cola) ~0,5 GiB; cada `claude -p` ronda 0,5 GiB, así que el agente corre de uno en uno (constante en `start.sh`) |
| CPU | 1 núcleo | el modelo es remoto; la CPU solo la usan PHP, el PDF y Claude Code |
| Disco | 4 GiB | imagen (PHP + Node + Claude Code + `vendor`, ~1,5 GiB) + bucket |
| Red | `*` (salida abierta) | el agente llama a `api.anthropic.com` por nombre; el guest no resuelve nombres desde `__config__` |
| API | puerto 8000 (HTTP) | único puerto declarado: el 9000 del agente **no** se pueden tunelar |

```bash
nodo pack .                                  # genera el .celaut.bee (requiere packer configurado, ver docs/PACKING.md)
nodo estimate personal-nutrition             # veredicto de memoria y coste
nodo inspect personal-nutrition              # la única variable aceptada es NUTRITION_RED (opcional; ver «Base de datos compartida»)
nodo instances                               # id (= token) de la instancia
nodo tunnel <instancia> 8000 --listen 8000   # y abre http://127.0.0.1:8000 (Filament)
```

Primera vez: al arrancar se crea el usuario **`admin@demo.com` / `password`** (`php artisan usuario:inicial`, solo si no hay ningún usuario). Cambia correo y contraseña en el perfil del panel; al reiniciar no se vuelve a crear. Los secretos (`APP_KEY`, `AGENTE_SECRETO`, contraseña de la base) se generan en el primer arranque y se guardan en `/var/lib/nutrition/secretos`; el token de Claude se pega en el panel como siempre.

### Base de datos compartida (PostgreSQL)

Varios dispositivos pueden usar el sistema compartiendo **una sola base PostgreSQL**. La base es un servicio Celaut aparte (`dependencies/postgres`, `nutrition-postgres`) que **declara una red**; `personal-nutrition` declara la misma red y `php artisan nutrition:base-datos` (`start.sh`) la usa al arrancar:

| Campo de la red | Valor |
|---|---|
| `tags` | `postgres` (exacto, sin sufijo) |
| `prose` | PostgreSQL con el esquema de las migraciones de personal-nutrition; la comparten las instancias con el mismo cluster y el mismo esquema |
| `formal` | `db.engine=postgresql`, `db.schema=personal-nutrition`, `db.cluster=${NUTRITION_DB_CLUSTER}` (clave de selección: la pone quien instancia) y una clave `db.table.<tabla>` por tabla con todo su esquema (ver abajo) |
| `protocol_stack` | `postgresql` (el puerto 5432 de la base lo expone en su `api`) |

Cada `db.table.<tabla>` es una cadena de una sola línea (el `formal` se empaqueta como líneas `clave=valor` y se compara byte a byte) con esta gramática:

```
valor    := columnas { " | " restricción }
columnas := columna { "; " columna }          # en el orden real de la tabla
columna  := nombre ":" tipo [" null"] [" auto"] [" default=" expresión]
restricción (en este orden): pk(cols) | uq(cols)... | idx(cols)... | fk(cols->tabla.cols del=acción upd=acción)...
```

**Claves primarias UUID.** Todas las tablas que se fusionan usan UUID (UUIDv7 en las que no tienen clave natural), para que dos bifurcaciones de la base no choquen al unirse. `alimentos`, `ajustes`, `agente_archivos` y `chat_archivos` derivan el UUID (UUIDv5, `App\Models\Concerns\UuidDeClaveNatural`) de su clave natural única (`nombre`, `clave`, `ruta`, `hash`): la misma fila creada por separado en dos bifurcaciones tiene el mismo id. Solo `jobs` y `failed_jobs` (de Laravel, no se fusionan) y `chat_conversacion` (una fila fija) conservan un entero. En el contrato con el agente, `perfiles[].id` y `perfil_id` son ese UUID en texto.

**Auditoría.** La hace PostgreSQL con triggers (`admin/database/migrations/*_create_auditoria_triggers.php`, requiere PostgreSQL 18 por `uuidv7()`): cada alta, cambio y baja de las tablas de datos queda en `auditorias` aunque no pase por Eloquent (consultas directas, actualizaciones en bloque, `psql`, la fusión de datos). Cada fila lleva la fecha, el usuario (nombre y correo), la instancia (un UUID que se genera la primera vez y se guarda en `storage/app/instancia`), el modelo (`auditable_type`) y el id (`auditable_id`) siempre, y en un cambio **solo las columnas que cambian** (antes y después, sin `updated_at`); una baja guarda la fila entera. Los campos sensibles (`password`, `token`, `secret`, `clave`, `key`, también dentro de un json) salen como `***`; los textos de más de 500 caracteres, recortados; y el contenido de archivos y mensajes del chat solo deja su tamaño. Para que el usuario, la ip y la url lleguen al trigger, la aplicación los deja en la sesión de PostgreSQL (`nutrition.*`) antes de cada escritura (`App\Services\ContextoAuditoria`); una escritura sin aplicación (psql) queda con `metodo = sql` y el rol de la base en `datos`. Quedan fuera las tablas efímeras (sesiones, cola, caché) y `migrations`; los deltas de una respuesta del chat (solo `contenido` y `razonamiento`) no son un evento cada uno, pero sí su cambio de estado. Los registros son inmutables también en la base (`UPDATE` y `DELETE` sobre `auditorias` fallan) y restaurar una copia no genera movimientos (el volcado desactiva los triggers y trae la auditoría consigo). Una tabla nueva se audita con `SELECT auditar_tabla('tabla', 'App\Models\Modelo')` en su migración; `TablasAuditadasTest` falla si se olvida.

**Qué base usa cada dispositivo.** Con varias bases de la misma red (por ejemplo tras una desconexión), cada dispositivo elige en cuál escribir con una regla que no necesita hablar con los demás (`App\Services\BaseCompartida\Eleccion`): gana la que tiene **más eventos en la auditoría**; a igualdad, la de **menor `system_identifier`** de PostgreSQL (único por base y visible igual desde cualquier nodo). Quien ya escribe en una base la mantiene mientras la mejor no la supere en más de un 5 % (`nutrition.eleccion.margen`). `nutrition:vigilar-base` lo revisa cada 5 minutos (lo lanza `start.sh`), reescribe el fichero de estado (`NUTRITION_BASE_ESTADO`, interno) y la aplicación se reconecta sin reiniciar. Una base que este dispositivo lanzó y que sobra se fusiona en la elegida y, cuando ya está fusionada y en silencio, se para tras guardar una copia suya (ver «Fusión de bases»).

**Fusión de bases.** Las bases que no son la elegida se fusionan en ella (`nutrition:vigilar-base`; `nutrition.fusion.activa` en `config/nutrition.php`, activo) y también a mano: `php artisan nutrition:exportar-datos fichero.ndjson` en una base y `php artisan nutrition:fusionar-datos fichero.ndjson` en otra. La fusión la hace PostgreSQL, con funciones (`fusion_*`, migración `create_fusion_functions`) que llama `App\Services\Fusion\Fusionador`: en una sola transacción (todo o nada), idempotente y sin tocar la base de origen. El fichero es un volcado NDJSON de una instantánea coherente (`Exportador`); no se fusionan bases con distinto esquema. Reglas:
- **Misma fila en las dos (mismo UUID):** se queda la de `updated_at` mayor (a igualdad, la de mayor `md5` del contenido: sale lo mismo en las dos direcciones). La otra queda en la auditoría como `fusion_descartada`: `valores_antiguos` es lo que se queda, `valores_nuevos` lo que se pierde (solo las columnas que difieren, con lo sensible enmascarado), `datos` dice la tabla, la acción y el origen, y `instancia` quién fusionó. Un administrador puede revisarlas y volver a editar lo que proceda. Cada fusión anota además un evento `fusion` con el resumen.
- **Borrados:** cada borrado deja una tumba en `borrados` (la pone el propio trigger). Una fila borrada en un lado no reaparece desde el otro; una edición posterior al borrado se conserva. Gana siempre la acción más nueva. Las tumbas y las filas descartadas por ella también quedan en la auditoría.
- **Misma clave única con otro id** (el mismo correo de usuario, el mismo alimento, el mismo hogar y día de disponibilidad…): es la misma fila; se queda el id de esta base y las referencias del origen se reasignan. Una fila cuya referencia obligatoria ya no existe (su hogar se borró aquí) se descarta como `huerfana`.
- **Menús:** lo que describe a quien los ejecuta (proceso, tanda, espera, estado de ejecución) no se importa: un menú que corría en el origen entra como `fallido`, y uno que corre aquí conserva su ejecución aunque traiga cambios de contenido.
- **Chat:** los mensajes se unen por `message_id`; los que llegan con una `sequence` ya usada se renumeran a continuación de la última y `ultima_secuencia` se ajusta. Un cliente que ya los vio en la otra base los verá de nuevo con otro número.
- **Auditoría:** la del origen se une a la de aquí (por id), así el historial de las dos bifurcaciones queda completo. La fusión no deja un «creado» por cada fila que trae.
- **Hora:** las comparaciones usan `updated_at` (sin zona horaria, con precisión de segundos): todos los dispositivos usan UTC (constante, no se configura) y deben tener el reloj en hora.

`nutrition:vigilar-base` recuerda con qué eventos fusionó cada base (`<estado>.fusiones`): no repite la fusión si nadie ha escrito en ella, y la repite si sí. Solo con la base ya fusionada con todo lo que tiene (nadie ha escrito durante ni después de la fusión), sin eventos desde hace `nutrition.baja.silencio_min` (10) minutos y lanzada por este dispositivo, se para (`nutrition.baja.activa`, activada en `config/nutrition.php`). **Antes de pararla se guarda siempre una copia entera de ella** (`.sql.gz`, como la de *Ajustes → Copia de seguridad*, restaurable con `psql`) en `storage/app/private/bases-retiradas` (se conservan las últimas 5); si la copia no se puede guardar entera, la base no se para y se reintenta en la vuelta siguiente.

`tipo` es el de PostgreSQL tal como lo devuelve `Schema::getColumns()` (`uuid`, `bigint`, `character varying(255)`, `timestamp(0) without time zone`, `numeric(10,2)`, `jsonb`…); sin ` null` la columna es NOT NULL; ` auto` marca las auto-incrementales; `default` es la expresión que guarda PostgreSQL. Los índices, uniques y claves foráneas van sin nombre (lo deriva Laravel) y ordenados por sus columnas; las acciones son `no action`, `restrict`, `cascade`, `set null` o `set default`. Se incluyen todas las tablas de las migraciones excepto `migrations` (la de la herramienta). Por ejemplo:

```
db.table.hogares = id:uuid; nombre:character varying(255); created_at:timestamp(0) without time zone null; updated_at:timestamp(0) without time zone null; menu_id:uuid null; cambios:json null | pk(id) | fk(menu_id->menus.id del=set null upd=no action)
db.table.alimentos = id:uuid; nombre:character varying(255); categoria:character varying(20) default='otro'::character varying; unidad:character varying(10) default='g'::character varying; created_at:…null; updated_at:…null | pk(id) | uq(nombre)
```

**El `formal` incluye el esquema completo y hay que mantenerlo al día.** La fuente única es `admin/database/esquema.json` (lo lee `config/nutrition.php`). Tras cada migración nueva, con la conexión `pgsql` ya migrada: `php artisan nutrition:esquema --escribir` regenera `esquema.json` (`--comprobar` falla si difiere de la base migrada) y `python3 admin/database/sincronizar-esquema.py` lo copia al `formal` de los dos `service.json` (`.service/` y `dependencies/postgres/.service/`), que tienen que ser idénticos entre sí y a `config('nutrition.red.formal')` más `db.cluster` (lo comprueba `EsquemaServicioTest`). La consecuencia: dos dispositivos con esquemas distintos (versiones distintas de las migraciones) pertenecen a **redes distintas** y no se ven: no comparten base de datos.

El nodo indexa una instancia como miembro de la red si la declara **y** expone el protocolo (`docs/NETWORKS.md`, «Network Instance Indexing»). Al arrancar:

1. Una base que este mismo servicio lanzó en un arranque anterior (pool de `celaut`): se reutiliza. El nodo no la reconoce por la red (compara el `formal` declarado, con `${NUTRITION_DB_CLUSTER}` sin rellenar, con el pedido ya relleno) y `celaut acquire` no reutiliza una instancia aún arrendada, así que sin esto cada reinicio lanzaría otra base.
2. Si no, se pregunta al nodo por instancias de la red: lo que resolvió al lanzar el servicio (`celaut config`, con `NUTRITION_DB_CLUSTER` pasada en `nodo execute -e`) y, con la clave de red ya rellena, `celaut resolve` (`Gateway.ResolveNetwork`), donde el nodo mira sus instancias, las semillas del operador y lo que ofrecen sus peers (`service_networks.ASK_PEERS`, por defecto 4).
3. Si no hay ninguna, se lanza una instancia de `nutrition-postgres` como hijo (paga el saldo de la instancia: `nodo increase_deposit`) con esa misma clave, y a partir de ahí otros la encuentran.

El servicio **no sale con error** por falta de saldo ni porque la base tarde en arrancar (emulada, puede tardar minutos): el nodo lo reiniciaría y lanzaría otra base. Reintenta el lanzamiento cada 15 s (hasta 120 veces) y espera hasta 30 min a que la base acepte conexiones (`config/nutrition.php` → `espera`).

Variable (`nodo execute -e`): **`NUTRITION_RED`**, una sola cadena corta (cabe en un QR) con tres valores: `APP_KEY` (tiene que ser igual en todos los dispositivos: cifra el token de Claude y las sesiones guardadas en la base), la clave **pública** de la red (`db.cluster`) y la contraseña de la base (no va en `formal`, que se anuncia a otros nodos). Es el base64url de `APP_KEY|cluster|clave_bd`, y `start.sh` la decodifica con shell. Es una credencial completa: trátala como una contraseña. Sin ella, el dispositivo genera sus valores y empieza una red nueva. Como el nodo ya no recibe `NUTRITION_DB_CLUSTER` al lanzar, el `${NUTRITION_DB_CLUSTER}` de `service.json` queda sin rellenar para el nodo; la base se encuentra igualmente con `celaut resolve`, que sí lleva la clave ya decodificada.

> **Para que un dispositivo encuentre la base de otro** (nodo `dev` con celaut-project/nodo#620): los nodos tienen que ser peers (`nodo connect <ip:puerto>`), y el nodo solo ofrece una dirección de un peer si es una IP **pública** (sin nombres DNS ni rangos privados) que acepta una conexión TCP. En una red local hay que poner `service_networks.ALLOW_PRIVATE_PEER_ADDRESSES: true` en el `config.yaml` del nodo que pregunta.

Cada dispositivo conserva su propio runner del agente (Claude Code), pero **el estado vive en la base**: datos, sesiones, caché, cola, archivos y registro del agente. Cualquier dispositivo puede atender un trabajo en cola. **Ajustes → Variables compartidas** muestra lo que hay que copiar para arrancar otro dispositivo (`NUTRITION_RED`) y la orden `nodo execute` ya montada.

Laravel habla con el nodo con `celaut` ([celaut-project/service-library](https://github.com/celaut-project/service-library), binario estático en la imagen) a través de `App\Services\Celaut\Nodo`; no hay código Python de orquestación. Limitación: parar un trabajo desde otro dispositivo solo pone `detener_pedido` (la ejecución en curso la cancela el dispositivo que la lanzó).

**Copias de seguridad:** **Ajustes → Copia de seguridad** descarga un volcado SQL de los datos (`.sql.gz`: TRUNCATE + INSERT por tabla, sin sesiones, cola ni `migrations`). La aplicación no restaura copias: se restauran con `psql` sobre una base que ya tenga el esquema. No incluye el token de Claude (va cifrado con `APP_KEY`, que cambia en un servicio nuevo): hay que volver a pegarlo.

> Nodo es **alfa** y los pagos en Ergo son **definitivos e irreversibles** (`docs/KyA.md`). `nodo execute` descuenta saldo de la instancia: revisa `nodo estimate` antes.

## Trabajar en el proyecto

| Si cambias… | Haz… |
|---|---|
| Cualquier cosa de `admin/`, `agente/`, `docker/agente/`, `docker/servicio/` o `.service/` | Volver a empaquetar (`nodo pack .`) y ejecutar el servicio nuevo: el id cambia con el contenido. Haz antes una copia de seguridad desde el panel. |
| Precios de los modelos | Editar `admin/config/agente.php` (`precios`). **Verifica las tarifas vigentes antes de fiarte del coste.** Solo afectan a lo que se registre desde ese momento. |

### Pruebas

```bash
cd admin && php artisan test                                   # PHP
cd agente && python3 -I -B -m unittest discover -s tests       # Python del agente
```


## Chat (interfaz «chat» de chatui)

El servicio declara un segundo slot de API (puerto 50051: `http2` → `grpc` → `chat`) que sigue [chat.md rev. 6](https://github.com/celaut-project/chatui/blob/master/docs/celaut/chat.md), de modo que la app de chat de Celaut conversa con Personal Nutrition: preguntar por el menú, contar lo que se ha comido y enviar **fotos** de las comidas (se guardan en PostgreSQL y se enlazan al registro de comida).

- **Dos motores.** Con token de Claude (se pide en el propio chat con una petición de secreto, o en *Conexión con Claude*) responde Claude, que ve una instantánea de **solo lectura** de los datos y propone acciones que Laravel valida antes de aplicarlas. Sin token responde un bot básico sin IA. Ambos usan widgets y formularios del propio chat.
- **Piezas.** Un traductor gRPC en Python (`docker/chat/`, sin estado ni credenciales de la base) habla por HTTP interno (`127.0.0.1:8001`, Bearer `CHAT_SECRETO`, no tunelable) con Laravel, que guarda mensajes y archivos en la base compartida. Las respuestas salen por una cola y un worker propios (`chat`).
- **Interruptores** (*Ajustes → Ajustes del servicio*): **Chat habilitado** (por defecto sí), **Chat con Claude** (por defecto sí; apagado, el chat responde siempre con el bot básico y no pide el token, aunque haya uno guardado; la generación de menús no cambia) y **Login automático** (por defecto no; entra con el usuario elegido a quien llegue a la web: la frontera de seguridad es el túnel del nodo).
- **Auditoría** (*Ajustes → Auditoría*) recoge también los hechos del chat; el valor de un secreto no se guarda en ningún sitio. Ver «Auditoría» más abajo.
- Contrato interno: [`docs/chat-api-interna.md`](docs/chat-api-interna.md). Decisiones, beneficios y tradeoffs: [`docs/chat-arquitectura.md`](docs/chat-arquitectura.md).

## Desarrollo y pruebas sin instalar PHP

Las pruebas de PHP corren **solo contra PostgreSQL** (el motor de producción; no hay SQLite). `phpunit.xml` apunta por defecto a la base desechable de `docker/pruebas/postgres.sh` (`127.0.0.1:55432`, `nutrition`/`ci`); las pruebas la borran y migran de nuevo, así que no la uses para nada más.

```bash
docker/pruebas/postgres.sh up                        # PostgreSQL 18 desechable en 127.0.0.1:55432 (nutrition/ci), antes de probar
docker/pruebas/php.sh artisan test --compact        # PHP 8.4 en contenedor (variable IMAGEN_PHP)
(cd docker/chat && python3 -m unittest discover -s tests)   # traductor gRPC (grpcio y grpcio-tools)
(cd agente && python3 -m unittest discover -s tests)        # scripts del agente
docker/pruebas/postgres.sh down
```

`.github/workflows/pruebas.yml` ejecuta PHP (PostgreSQL 18) y el traductor del chat en CI.

## Estructura

```
admin/      Laravel + Filament (panel, modelos, cola, servicios del agente)
agente/     Kit del agente: CLAUDE.md, skills planificar-menu, editar-menu e intercambiar-menu, scripts (consumos.py, menu.py) y sus tests
.service/   Empaquetado como servicio Celaut (Dockerfile de construcción, service.json, pack_config.json)
docker/     agente/ (runner HTTP del agente), chat/ (traductor gRPC), servicio/ (start.sh, arranque del microVM) y pruebas/ (scripts de prueba)
docs/       Contrato interno del chat y documento de arquitectura
agentic-laravel/   Guía de arquitectura origen (no se versiona)
```

