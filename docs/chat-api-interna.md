# API interna del chat (pieza gRPC ↔ Laravel)

Contrato entre `docker/chat/` (traductor gRPC, Python) y Laravel (`App\Http\Controllers\Interno\*`). Es la **única** frontera entre los dos:
el traductor no guarda datos ni tiene credenciales de la base; Laravel es la fuente de verdad. La spec pública es
`celaut-project/chatui` → `docs/celaut/chat.md` (revisión 6) y `chat.proto`.

## Transporte

- Base: `http://127.0.0.1:8001/interno/chat`. El puerto 8001 escucha **solo en 127.0.0.1** (Caddyfile) y **no** se declara en `service.json`: no se puede tunelar.
- Cabecera `Authorization: Bearer $CHAT_SECRETO` (secreto generado en `start.sh`; lo reciben solo el traductor y la app). Sin ella, 401.
- JSON UTF-8 (`Content-Type: application/json`), salvo los archivos (cuerpo binario). Tiempos: el traductor espera 25 s por llamada.
- **Interruptor.** Si el ajuste `chat_habilitado` está apagado, todas las rutas excepto `GET /salud` responden `503` con `{"codigo":"deshabilitado"}`. El traductor lo
  convierte en `UNAVAILABLE` sin `chat-reason` (el cliente lo trata como «instancia no disponible» y reintenta).

## Errores

Cuerpo `{"codigo": "<razón>", "mensaje": "texto para el usuario"}`. `codigo` es exactamente el `chat-reason` de chat.md §9 y el traductor lo traduce a su estado gRPC:

| `codigo` | HTTP | Estado gRPC |
|---|---|---|
| `bad_request` | 422 | `INVALID_ARGUMENT` |
| `too_large` | 413 | `INVALID_ARGUMENT` |
| `unsupported_media` | 415 | `INVALID_ARGUMENT` |
| `hash_mismatch` | 422 | `INVALID_ARGUMENT` |
| `not_found` | 404 | `NOT_FOUND` |
| `file_missing` | 409 | `FAILED_PRECONDITION` |
| `bad_state` | 409 | `FAILED_PRECONDITION` |
| `history_full` | 507 | `RESOURCE_EXHAUSTED` |
| `storage_full` | 507 | `RESOURCE_EXHAUSTED` |
| `busy` | 503 | `UNAVAILABLE` + `chat-reason: busy` |
| `service_error` | 500 | `INTERNAL` |
| `unimplemented` | 501 | `UNIMPLEMENTED` |
| `deshabilitado` | 503 | `UNAVAILABLE` (sin `chat-reason`) |

El traductor decide solo (sin llamar a Laravel) lo que es del cable: `Hash.type` vacío → `bad_request`; hash de tipo desconocido se descarta, y si no queda ninguno conocido → `bad_request`;
`value` de SHA3-256 que no mida 32 bytes → `bad_request`; partes sin `kind`; tamaño de mensaje gRPC. Todo lo demás lo valida Laravel.

## Formas JSON

**Parte** (`content[]`). `hash` es el SHA3-256 en minúsculas, 64 caracteres hexadecimales.

```json
{"tipo": "texto", "texto": "..."}
{"tipo": "imagen", "hash": "…64 hex…", "mime": "image/jpeg", "tamano": 12345, "nombre": null, "alt": null}
{"tipo": "audio", "hash": "…", "mime": "audio/wav", "tamano": 1, "nombre": null, "transcripcion": null, "duracion_ms": null}
{"tipo": "archivo", "hash": "…", "mime": "…", "tamano": 1, "nombre": null}
{"tipo": "peticion_secreto", "request_id": "…", "nombre": "CLAUDE_CODE_OAUTH_TOKEN", "prosa": "…"}
{"tipo": "slot_link", "port": 50052, "label": "Lista de la compra"}
```

`slot_link` (chat.md §5.8: un botón que abre otro slot de la instancia; `label` de 1 a 32 caracteres o `null`) solo lo publica el servicio, en un mensaje `COMPLETED`. Un usuario solo envía `texto`, `imagen` y `audio`. El texto de un mensaje es la unión de sus partes `texto`, sin separador.

**Mensaje**:

```json
{"message_id": "…", "sequence": 7, "conversation_id": null, "content": [Parte], "status": "COMPLETED",
 "created_ms": 0, "updated_ms": 0, "reply_to": null, "pinned": false, "metadata": {"clave": "valor"},
 "from_service": true, "reasoning": "", "idempotency_key": null, "error": null, "suggestions": [],
 "corrector": {"enabled": true, "corrections": [{"original": "x", "replacement": "y"}]}}
```

`status` ∈ `PENDING | IN_PROGRESS | COMPLETED | FAILED | CANCELLED`. `sequence` empieza en 1 y crece de uno en uno. `message_id`: de 1 a 64 caracteres de `A-Za-z0-9-_`.

## Rutas

| Método y ruta | Cuerpo / respuesta |
|---|---|
| `GET /salud` | `200 {"habilitado": bool}`. Responde siempre (también con el chat apagado) |
| `GET /capacidades` | `{"tipos_mime": ["image/jpeg","image/png"], "max_archivo_bytes": 4194304, "max_archivos": 4, "max_texto_bytes": 16384, "soporta_ramas": false, "soporta_cancelar": true}`. El traductor la lee al arrancar y la guarda: no cambia en la vida de la instancia |
| `GET /conversacion` | `{"titulo": null\|str, "creada_ms": int, "ultima_secuencia": int}` |
| `POST /mensajes` | Cuerpo `{"content": [Parte], "reply_to": null\|str, "idempotency_key": null\|str, "corrector": null\|{...}}`. Valida en el orden de chat.md §6.1 (pasos 1–8), crea el mensaje del usuario y encola la respuesta; **no espera a la respuesta**. Responde `200` con el Mensaje (el existente si la clave ya se usó) |
| `GET /mensajes?despues=0&antes=0&limite=0&ultimos=0` | `{"mensajes": [Mensaje]}` en orden de `sequence`. Semántica de `GetMessagesRequest`. `limite` 0 → 500 |
| `GET /mensajes/{id}` | Mensaje, o `404 not_found` |
| `POST /mensajes/{id}/fijar` | `{"fijado": bool}` → Mensaje |
| `POST /mensajes/{id}/cancelar` | Mensaje con `CANCELLED`; `409 bad_state` si ya es final; `404` si no existe |
| `POST /sincronizar` | Ver abajo |
| `POST /archivos/consultar` | `{"hashes": ["hex"]}` → `{"resultados": [{"hash": "hex", "disponible": bool}]}` en el mismo orden |
| `PUT /archivos/{hex}` | Cuerpo binario. Cabeceras `X-Mime` (obligatoria), `X-Tamano` (bytes declarados, obligatoria), `X-Nombre` (opcional, url-encoded). Comprueba tamaño, tipo, y que SHA3-256 del cuerpo es `{hex}`. `200 {"hash","mime","tamano","nombre"}`; si ya existía, también `200`. Un fallo no deja nada guardado |
| `GET /archivos/{hex}` | `200` con los bytes (`Content-Type` = mime), o `404 not_found` |
| `POST /secretos` | `{"message_id": "…", "request_id": "…", "valor": str\|null, "omitido": bool}` (exactamente uno de los dos) → `200 {}`. Respuesta única e idempotente (chat.md §6.9). **El valor no se guarda en ningún mensaje, evento, log, metadato ni fila de auditoría** |

### `POST /sincronizar` (para `Connect` y `GetMessages`)

El traductor sondea cada ~300 ms mientras haya un `Connect` abierto y le pasa lo que ya ha enviado al cliente. Cuerpo:

```json
{"despues": 12,
 "abiertos": [{"id": "…", "texto": 480, "razonamiento": 0, "estado": "IN_PROGRESS", "fijado": false}],
 "titulo": null}
```

- `despues`: última `sequence` que el traductor ya envió. `abiertos`: los mensajes que envió en estado `PENDING` o `IN_PROGRESS`, con la longitud **en bytes UTF-8** del texto y del razonamiento que ya envió, su estado y si estaban fijados. `titulo`: el título que el cliente ya tiene.

Respuesta:

```json
{"ultima_secuencia": 14,
 "nuevos": [Mensaje],
 "deltas": [{"id": "…", "texto_anadido": "…", "razonamiento_anadido": ""}],
 "estados": [{"id": "…", "estado": "COMPLETED", "actualizado_ms": 0, "error": null, "sugerencias": ["…"]}],
 "fijados": [{"id": "…", "fijado": true, "actualizado_ms": 0}],
 "titulo": {"cambio": false, "valor": null}}
```

- `nuevos`: mensajes con `sequence` > `despues`, completos tal y como están ahora, en orden. Si uno está abierto, el traductor lo añade a su lista de abiertos con las longitudes de ese Mensaje.
- `deltas`: para cada mensaje de `abiertos`, el texto y el razonamiento que se han añadido después de los bytes indicados. Un delta no pasa de 64 KiB y nunca parte un carácter UTF-8 (si hay más, llegan en la siguiente sincronización).
- `estados`: mensajes de `abiertos` cuyo estado cambió. Para un mensaje que pasa a final, Laravel devuelve **antes** todos sus deltas pendientes (no devuelve el estado final mientras queden bytes por enviar). `sugerencias` solo con `COMPLETED`; `error` solo con `FAILED`.
- `fijados`: cambios de `pinned` respecto a lo indicado. `titulo.cambio` es `true` si el título difiere del recibido.
- Un mensaje nunca se repite entre `nuevos` y `deltas` en la misma respuesta.

## Archivos

Se guardan en el SQLite del disco de la instancia (tabla `chat_archivos`: hash SHA3-256 hex único, mime, tamaño, nombre, contenido binario): no se comparten con otras instancias. Tamaño máximo 4 MiB. Tipos aceptados: `image/jpeg`, `image/png`.

## Cards (lista de la compra)

Segunda API interna, para el slot `cards` (`http2` → `grpc` → `cards`, puerto 50052; spec: `celaut-project/chatui` → `docs/celaut/cards.md` rev. 1 y `cards.proto`). Mismo transporte, mismo Bearer `CHAT_SECRETO`, mismo interruptor `chat_habilitado` (503 `deshabilitado`, salvo `GET /salud`) y mismos errores (`codigo` = razón; el traductor la pone en el trailer `cards-reason`) que el chat, pero con la base **`http://127.0.0.1:8001/interno/cards`**. El traductor (`docker/chat/cartas.py`, mismo proceso que el del chat) no guarda nada. Los nombres de los campos son los de `cards.proto`; los enums, los nombres del proto (`TONE_POSITIVE`, `ICON_CART`…).

| Método y ruta | Cuerpo / respuesta |
|---|---|
| `GET /salud` | `200 {"ok": true}` |
| `GET /mazo` | `{"version": int, "deck": Deck}`. Es «la persona pide el mazo» (abrir o reconectar la pantalla): genera un mazo nuevo con el menú activo y la despensa de ese momento, salvo que el actual siga en uso (actividad hace menos de `cards.reanudar_s`, 300 s, y quedan tarjetas por resolver), en cuyo caso lo reanuda. `version` es la del último evento (≥ 1). Sin menú activo, `deck.deck_id` es `""` |
| `GET /eventos?despues=N` | `{"version": int, "reiniciar": bool, "eventos": [{"version", "deck_id", "tipo", "carga"}]}`: los eventos con versión mayor que `N` (hasta 200). `tipo` ∈ `deck`, `cards_added`, `cards_removed`, `card_moved`, `card_changed`; `carga` es `{"deck": Deck}`, `{"cards", "before_item_id"}`, `{"item_ids"}`, `{"item_id", "before_item_id"}` o `{"card": Card}`. `reiniciar: true` si `N` ya no está en el historial (o es mayor que la versión actual): el traductor vuelve a pedir `/mazo` y envía un `deck` |
| `POST /acciones` | `{"deck_id","item_id","action_id","idempotency_key","undo","acted_at"}` → `200 {}`. Campos con formato no válido: `422 bad_request`. Mazo o tarjeta que ya no están, acción desconocida o clave ya vista: `200 {}` sin cambios (cards.md §6.2) |
| `GET /archivos/{hex}` | Los bytes de una imagen de tarjeta (`image/png`), o `404 not_found` |

**Deck / Card.** `Deck`: `deck_id` (`compra-` + 20 hex; único por mazo generado; cada vez que la persona pide el mazo y no hay sesión en curso se genera otro), `title`, `actions` (`no_encontrado`: izquierda, `keep`, `ICON_LATER`; `comprado`: derecha, `ICON_CART`, positivo), `cards`, `show_progress`, `end_text`, `done_text`. `Card`: `item_id` (`c` + 20 hex, estable por alimento y familia de unidad), `title` (alimento), `text` («Faltan 1,5 kg»), `detail` (total necesario, despensa, lo que falta y los usos: toma, día, plato y cantidad; texto plano, se acorta si el mazo no cabría en 1 MiB), `buttons` (`ya_lo_tengo`), `group` (categoría del alimento, aproxima el pasillo), `image` (`{"hash","mime","tamano","alt"}`, siempre `image/png`), `decision`.

**Reglas.**

- **Lista.** Ingredientes de las opciones elegidas de comida y cena y de todas las tomas individuales del menú activo (la misma suma que la lista del PDF, `Menu::necesidadesCompra()`), menos las existencias del hogar; g con kg y ml con l se suman. Un alimento sin cantidad numérica sale con «Cantidad sin fijar».
- **Estado en el SQLite del chat** (`database/migrations/chat/…create_cards_tables.php`): `cards_mazos` (el mazo actual: una foto de la lista tomada al generarlo, que no cambia durante la sesión), `cards_decisiones` (historial que solo crece; deshacer marca la anterior), `cards_eventos` (la versión es su id autoincremental), `cards_ilustraciones` (caché local del PNG de cada alimento y los intentos del encargo al agente) y `cards_lotes`. Un cerrojo de fichero serializa todo.
- **Decisiones.** `comprado` suma a `existencias` exactamente el `faltante` de la tarjeta (unidad del alimento si es de su familia; crea la fila si no existe; crea el alimento si el ingrediente no lo tenía) y guarda el efecto; deshacer lo resta sin bajar de lo que había antes ni subir de lo que hay ahora, y borra la fila si la creó la compra y queda a 0. `no_encontrado` no cambia datos: la tarjeta pasa al final (`card_changed` + `card_moved`). `ya_lo_tengo` resuelve sin sumar. Una tarjeta resuelta no acepta otra decisión hasta deshacerla. Cada cambio queda en la auditoría (`cards.compra`, `cards.compra_deshecha`, `cards.decision`, además de los triggers de la tabla `existencias`).
- **Imágenes.** cards.md solo admite PNG/JPEG. Cada tarjeta lleva la ilustración del alimento o, mientras no haya, una fija por categoría (SVG en `Ilustraciones`). Las del agente: por demanda y en lote (`GenerarIlustraciones`, cola `chat`), el agente recibe solo nombres y devuelve SVG de un subconjunto cerrado; `SaneadorSvg` lo lee con lista blanca (sin script, `foreignObject`, `href` ni `DOCTYPE`) y, si vale, el SVG se guarda en `alimentos.ilustracion` (PostgreSQL, compartido por todas las instancias; crea el alimento si falta; la auditoría solo anota su tamaño). Cada instancia lo vuelve a comprobar al leerlo, `Rasterizador` lo dibuja con GD a PNG de 256 px y lo guarda en `chat_archivos` por hash (caché local). Topes en `config/cards.php` (alimentos por lote, lotes al día, intentos por alimento, bytes). No se abre la subida de SVG a los clientes: `chat.tipos_mime` no cambia. Al terminar, un `card_changed` por cada tarjeta cuya imagen cambia.
- **Enlace desde el chat.** El bot responde a «lista de la compra» con un mensaje `COMPLETED` que lleva un `slot_link` al puerto 50052 (`ChatMensaje::enlazarSlot()`).
