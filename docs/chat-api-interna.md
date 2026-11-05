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
```

Un usuario solo envía `texto`, `imagen` y `audio`. El texto de un mensaje es la unión de sus partes `texto`, sin separador.

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

Se guardan en Postgres (tabla `chat_archivos`: hash SHA3-256 hex único, mime, tamaño, nombre, contenido `bytea`), no en disco: cualquier dispositivo que comparta la base los ve. Tamaño máximo 4 MiB. Tipos aceptados: `image/jpeg`, `image/png`.
