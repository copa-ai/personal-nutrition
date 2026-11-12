<?php

namespace App\Services\Chat;

use App\Enums\EstadoChat;
use App\Jobs\ResponderChat;
use App\Models\Auditoria;
use App\Models\ChatConversacion;
use App\Models\ChatMensaje;
use App\Models\ChatPeticionSecreto;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * La conversacion principal de la instancia (chat.md §5, §6): envio con sus 8 validaciones, lectura, fijar, cancelar,
 * sincronizacion para el traductor gRPC y la API de escritura de los motores de respuesta (ver ChatMensaje).
 * Laravel es la fuente de verdad: la `sequence` se reparte con la fila de ChatConversacion bloqueada.
 */
class Conversacion
{
    private const PATRON_CLAVE = '/^[A-Za-z0-9_-]{1,64}$/';

    private const PATRON_NOMBRE_SECRETO = '/^[A-Za-z_][A-Za-z0-9_]{0,127}$/';

    private const MIME_ARCHIVO = ['imagen' => 'image/', 'audio' => 'audio/'];

    public function __construct(private readonly Archivos $archivos) {}

    public static function ahoraMs(): int
    {
        return (int) (microtime(true) * 1000);
    }

    // ---------------------------------------------------------------- envio (§6.1)

    /**
     * POST /mensajes: valida en el orden de chat.md §6.1 (pasos 1 a 8), crea el mensaje de la persona y la respuesta PENDING
     * y encola la respuesta. No espera a la respuesta. Con una clave de idempotencia ya usada devuelve el mensaje existente.
     *
     * @param  array<string, mixed>  $cuerpo  JSON ya decodificado
     * @param  int  $bytesPeticion  tamano del cuerpo de la peticion (paso 2)
     *
     * @throws ErrorChat
     */
    public function enviar(array $cuerpo, int $bytesPeticion): ChatMensaje
    {
        // Pasos 1 a 3: estructura, tamanos y tipos.
        [$partes, $clave, $replyTo] = $this->validarEnvio($cuerpo, $bytesPeticion);

        // Paso 4.
        if ($replyTo !== null && ! ChatMensaje::query()->where('message_id', $replyTo)->exists()) {
            throw ErrorChat::notFound('El mensaje al que respondes no existe.');
        }

        // Paso 5.
        $hashes = array_values(array_unique(array_column(array_filter($partes, fn (array $p): bool => $p['tipo'] !== 'texto'), 'hash')));
        foreach ($this->archivos->disponibles($hashes) as $hash => $existe) {
            if (! $existe) {
                throw ErrorChat::fileMissing('Falta el archivo '.substr($hash, 0, 12).'…; hay que subirlo antes de enviar.');
            }
        }

        $corrector = self::normalizarCorrector($cuerpo['corrector'] ?? null);

        $this->cerrarCaducadas();

        [$usuario, $respuesta, $nuevo] = DB::connection('chat')->transaction(function () use ($partes, $clave, $replyTo, $corrector): array {
            $conversacion = ChatConversacion::bloqueada();

            // Paso 6.
            if ($clave !== null) {
                $existente = ChatMensaje::query()->where('idempotency_key', $clave)->where('from_service', false)->first();
                if ($existente) {
                    return [$existente, null, false];
                }
            }
            // Paso 7: sitio para el mensaje de la persona y su respuesta.
            if ($conversacion->ultima_secuencia + 2 > (int) config('chat.max_historial')) {
                throw ErrorChat::historyFull();
            }
            // Paso 8.
            $abiertas = ChatMensaje::query()->where('from_service', true)
                ->whereIn('estado', [EstadoChat::Pendiente->value, EstadoChat::EnCurso->value])->count();
            if ($abiertas >= (int) config('chat.max_en_espera')) {
                throw ErrorChat::busy();
            }

            $usuario = $this->crear($conversacion, [
                'contenido' => $partes, 'estado' => EstadoChat::Completado, 'from_service' => false, 'reply_to' => $replyTo,
                'idempotency_key' => $clave, 'corrector' => $corrector,
            ]);
            $respuesta = $this->crear($conversacion, [
                'contenido' => [], 'estado' => EstadoChat::Pendiente, 'from_service' => true, 'reply_to' => $usuario->message_id,
            ]);
            $this->fijarTituloSiProcede($conversacion, $usuario->texto());

            return [$usuario, $respuesta, true];
        });

        if ($nuevo) {
            Auditoria::registrar('chat.mensaje_recibido', 'Mensaje recibido en el chat', [
                'message_id' => $usuario->message_id, 'sequence' => $usuario->sequence, 'partes' => count($partes),
                'bytes_texto' => strlen($usuario->texto()),
            ]);
            try {
                ResponderChat::dispatch($usuario->id, $respuesta->id);
            } catch (Throwable $e) {
                // La cola no acepto el trabajo: el mensaje ya existe, asi que la respuesta termina en FAILED (§5.2) en vez de
                // quedarse PENDING para siempre. El envio sigue siendo correcto.
                report($e);
                $this->fallar($respuesta, 'No se pudo preparar la respuesta. Inténtalo de nuevo.');
            }
        }

        return $usuario;
    }

    /**
     * Pasos 1 a 3 de §6.1. @param  array<string, mixed>  $cuerpo  @return array{0: array<int, array<string, mixed>>, 1: ?string, 2: ?string}
     */
    private function validarEnvio(array $cuerpo, int $bytesPeticion): array
    {
        $contenido = $cuerpo['content'] ?? null;
        if (! is_array($contenido) || $contenido === [] || ! array_is_list($contenido)) {
            throw ErrorChat::badRequest('El mensaje no tiene contenido.');
        }

        $clave = $cuerpo['idempotency_key'] ?? null;
        if ($clave === '') {
            $clave = null;
        }
        if ($clave !== null && (! is_string($clave) || preg_match(self::PATRON_CLAVE, $clave) !== 1)) {
            throw ErrorChat::badRequest('La clave de idempotencia no es válida.');
        }
        $replyTo = $cuerpo['reply_to'] ?? null;
        if ($replyTo === '') {
            $replyTo = null;
        }
        if ($replyTo !== null && ! is_string($replyTo)) {
            throw ErrorChat::badRequest('reply_to no es válido.');
        }

        $partes = [];
        $adjuntos = 0;
        $bytesTexto = 0;
        foreach ($contenido as $parte) {
            $partes[] = $this->validarParte($parte);
            $ultima = end($partes);
            if ($ultima['tipo'] === 'texto') {
                $bytesTexto += strlen($ultima['texto']);
            } else {
                $adjuntos++;
            }
        }
        if ($bytesTexto === 0 && $adjuntos === 0) {
            throw ErrorChat::badRequest('El texto no puede estar vacío si no hay adjuntos.');
        }
        if ($adjuntos > (int) config('chat.max_archivos')) {
            throw ErrorChat::badRequest('Demasiados adjuntos (máximo '.config('chat.max_archivos').').');
        }

        // Paso 2: tamanos.
        if ($bytesTexto > (int) config('chat.max_texto_bytes') || $bytesPeticion > (int) config('chat.max_peticion_bytes')) {
            throw ErrorChat::tooLarge('El mensaje es demasiado largo.');
        }
        foreach ($partes as $parte) {
            if ($parte['tipo'] !== 'texto' && $parte['tamano'] > (int) config('chat.max_archivo_bytes')) {
                throw ErrorChat::tooLarge('Un archivo supera el tamaño máximo.');
            }
        }

        // Paso 3: tipos.
        foreach ($partes as $parte) {
            if ($parte['tipo'] !== 'texto' && ! $this->archivos->tipoAceptado($parte['mime'])) {
                throw ErrorChat::unsupportedMedia('Tipo de archivo no admitido: '.mb_substr($parte['mime'], 0, 100).'.');
            }
        }

        return [$partes, $clave, $replyTo];
    }

    /**
     * §5.7: el estado del corrector, tal y como lo mando el cliente pero dentro de los limites (hasta 32 correcciones, cada
     * palabra de 1 a 64 caracteres). Lo que no cabe se descarta: un envio nunca falla por el corrector.
     *
     * @return array{enabled: bool, corrections: array<int, array{original: string, replacement: string}>}|null
     */
    public static function normalizarCorrector(mixed $corrector): ?array
    {
        if (! is_array($corrector) || array_is_list($corrector)) {
            return null;
        }
        $correcciones = [];
        foreach (is_array($corrector['corrections'] ?? null) ? $corrector['corrections'] : [] as $c) {
            $original = is_array($c) ? ($c['original'] ?? null) : null;
            $reemplazo = is_array($c) ? ($c['replacement'] ?? null) : null;
            if (! is_string($original) || ! is_string($reemplazo) || ! mb_check_encoding($original.$reemplazo, 'UTF-8')) {
                continue;
            }
            $largo = fn (string $t): bool => mb_strlen($t) >= 1 && mb_strlen($t) <= 64;
            if ($largo($original) && $largo($reemplazo) && count($correcciones) < 32) {
                $correcciones[] = ['original' => $original, 'replacement' => $reemplazo];
            }
        }

        return ['enabled' => ($corrector['enabled'] ?? false) === true, 'corrections' => $correcciones];
    }

    /**
     * §5.7: sugerencias validas para el cliente: hasta 3, de 1 a 64 caracteres, en una sola linea (sin U+0000 a U+001F ni U+007F).
     * Las que no cumplen se arreglan (espacios y controles pasan a un espacio, se corta a 64 caracteres) o se descartan si quedan vacias.
     *
     * @param  array<int, mixed>  $sugerencias
     * @return array<int, string>
     */
    public static function limpiarSugerencias(array $sugerencias): array
    {
        $limpias = [];
        foreach ($sugerencias as $sugerencia) {
            if (! is_string($sugerencia)) {
                continue;
            }
            $texto = trim((string) preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', mb_scrub($sugerencia, 'UTF-8')));
            $texto = rtrim(mb_substr($texto, 0, 64));
            if ($texto !== '' && count($limpias) < (int) config('chat.max_sugerencias')) {
                $limpias[] = $texto;
            }
        }

        return $limpias;
    }

    /**
     * §5.2: toda respuesta debe terminar. Si un proceso murio a medias (reinicio del servicio, cola parada), su respuesta se
     * quedaria abierta para siempre y, con ocho asi, el chat respondería `busy` sin remedio. Las abiertas desde hace mas de
     * chat.caducidad_respuesta_min minutos pasan a FAILED (el motor que siguiera vivo ya no puede escribir en ellas).
     */
    public function cerrarCaducadas(): int
    {
        $limite = self::ahoraMs() - (int) config('chat.caducidad_respuesta_min') * 60_000;
        $cerradas = 0;
        $caducadas = ChatMensaje::query()->where('from_service', true)->where('created_ms', '<', $limite)
            ->whereIn('estado', [EstadoChat::Pendiente->value, EstadoChat::EnCurso->value])->get();
        foreach ($caducadas as $mensaje) {
            $cerradas += $this->fallar($mensaje, 'La respuesta se interrumpió. Inténtalo de nuevo.') ? 1 : 0;
        }

        return $cerradas;
    }

    /** Valida y normaliza una parte enviada por una persona (solo texto, imagen y audio). @return array<string, mixed> */
    private function validarParte(mixed $parte): array
    {
        if (! is_array($parte) || ! is_string($parte['tipo'] ?? null)) {
            throw ErrorChat::badRequest('Una parte del mensaje no tiene tipo.');
        }
        $tipo = $parte['tipo'];

        if ($tipo === 'texto') {
            $texto = $parte['texto'] ?? null;
            if (! is_string($texto) || ! mb_check_encoding($texto, 'UTF-8')) {
                throw ErrorChat::badRequest('El texto no es UTF-8 válido.');
            }

            return ['tipo' => 'texto', 'texto' => $texto];
        }
        if (! isset(self::MIME_ARCHIVO[$tipo])) {
            throw ErrorChat::badRequest('Tipo de parte no permitido: '.mb_substr($tipo, 0, 40).'.');
        }

        $hash = Archivos::normalizarHash($parte['hash'] ?? null);
        $mime = $parte['mime'] ?? null;
        $tamano = $parte['tamano'] ?? null;
        $nombre = $parte['nombre'] ?? null;
        if ($hash === null || ! is_string($mime) || trim($mime) === '' || ! is_int($tamano) || $tamano < 1
            || ($nombre !== null && ! is_string($nombre))) {
            throw ErrorChat::badRequest('Un adjunto no tiene hash SHA3-256, tipo o tamaño válidos.');
        }
        $mime = Archivos::normalizarMime($mime);
        foreach (self::MIME_ARCHIVO as $otro => $prefijo) {
            if ($otro !== $tipo && str_starts_with($mime, $prefijo)) {
                throw ErrorChat::badRequest('El tipo del adjunto no corresponde a su clase.');
            }
        }

        $normal = ['tipo' => $tipo, 'hash' => $hash, 'mime' => $mime, 'tamano' => $tamano, 'nombre' => $nombre];
        if ($tipo === 'imagen') {
            $normal['alt'] = is_string($parte['alt'] ?? null) ? $parte['alt'] : null;
        } else {
            $normal['transcripcion'] = is_string($parte['transcripcion'] ?? null) ? $parte['transcripcion'] : null;
            $normal['duracion_ms'] = is_int($parte['duracion_ms'] ?? null) ? $parte['duracion_ms'] : null;
        }

        return $normal;
    }

    /**
     * Crea un mensaje con la siguiente `sequence`. Llamar dentro de la transaccion y con la conversacion bloqueada.
     *
     * @param  array<string, mixed>  $atributos
     */
    private function crear(ChatConversacion $conversacion, array $atributos): ChatMensaje
    {
        $conversacion->ultima_secuencia = $conversacion->ultima_secuencia + 1;
        $conversacion->save();

        $ahora = self::ahoraMs();
        $mensaje = new ChatMensaje;
        $mensaje->forceFill($atributos + [
            'message_id' => (string) Str::uuid(),
            'sequence' => $conversacion->ultima_secuencia,
            'razonamiento' => '',
            'pinned' => false,
            'metadata' => [],
            'sugerencias' => [],
            'created_ms' => $ahora,
            'updated_ms' => $ahora,
        ])->save();

        return $mensaje;
    }

    /** §6.10: el titulo se fija una vez, con las primeras palabras del primer mensaje con texto de la persona. */
    private function fijarTituloSiProcede(ChatConversacion $conversacion, string $texto): void
    {
        if ($conversacion->titulo !== null) {
            return;
        }
        $titulo = self::tituloDe($texto);
        if ($titulo !== null) {
            $conversacion->titulo = $titulo;
            $conversacion->titulo_ms = self::ahoraMs();
            $conversacion->save();
        }
    }

    /** Primeras palabras del texto en una sola linea (1 a 256 bytes), o null si no hay texto. */
    public static function tituloDe(string $texto): ?string
    {
        $limpio = trim((string) preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', $texto));
        if ($limpio === '') {
            return null;
        }
        $palabras = explode(' ', $limpio);
        $maximo = (int) config('chat.titulo_palabras');
        $titulo = implode(' ', array_slice($palabras, 0, $maximo));
        if (count($palabras) > $maximo) {
            $titulo .= '…';
        }

        return mb_strcut($titulo, 0, 256);
    }

    // ---------------------------------------------------------------- lectura (§6.2)

    /** @throws ErrorChat */
    public function obtener(string $messageId): ChatMensaje
    {
        return ChatMensaje::query()->where('message_id', $messageId)->first()
            ?? throw ErrorChat::notFound('El mensaje no existe.');
    }

    /**
     * GetMessages: mensajes con `sequence` > $despues y < $antes (0 = sin tope), los primeros $limite o los ultimos $ultimos.
     *
     * @return Collection<int, ChatMensaje>
     *
     * @throws ErrorChat
     */
    public function leer(int $despues, int $antes, int $limite, int $ultimos): Collection
    {
        if ($despues < 0 || $antes < 0 || $limite < 0 || $ultimos < 0 || ($limite > 0 && $ultimos > 0)) {
            throw ErrorChat::badRequest('Parámetros de lectura no válidos.');
        }
        $ultima = (int) ChatConversacion::unica()->ultima_secuencia;
        if ($despues > $ultima) {
            throw ErrorChat::badRequest('despues es mayor que la última secuencia.');
        }
        if ($antes > 0 && $antes <= $despues + 1) {
            return collect();
        }

        $tope = (int) config('chat.limite_lectura');
        $consulta = ChatMensaje::query()->where('sequence', '>', $despues);
        if ($antes > 0) {
            $consulta->where('sequence', '<', $antes);
        }

        if ($ultimos > 0) {
            return $this->tomar($consulta, true, min($ultimos, $tope))->reverse()->values();
        }

        return $this->tomar($consulta, false, $limite > 0 ? min($limite, $tope) : $tope);
    }

    /**
     * Hasta $maximo mensajes por orden de `sequence` (o inverso), leidos por lotes y parando al llegar a max_bytes_lectura de texto:
     * un mensaje del servicio puede pesar 1 MiB y 500 de ellos no deben cargarse de golpe. Siempre devuelve al menos uno si lo hay;
     * quien lee por paginas (`despues`) recibe menos de los pedidos y sigue por donde acabo.
     *
     * @param  Builder<ChatMensaje>  $consulta
     * @return Collection<int, ChatMensaje>
     */
    private function tomar(Builder $consulta, bool $descendente, int $maximo): Collection
    {
        $presupuesto = (int) config('chat.max_bytes_lectura');
        $resultado = collect();
        $usado = 0;
        $ultima = null;
        while ($resultado->count() < $maximo) {
            $lote = (clone $consulta)->when($ultima !== null, fn (Builder $q) => $q->where('sequence', $descendente ? '<' : '>', $ultima))
                ->orderBy('sequence', $descendente ? 'desc' : 'asc')->limit(min(25, $maximo - $resultado->count()))->get();
            if ($lote->isEmpty()) {
                break;
            }
            foreach ($lote as $mensaje) {
                $peso = strlen($mensaje->texto()) + strlen((string) $mensaje->razonamiento);
                if ($resultado->isNotEmpty() && $usado + $peso > $presupuesto) {
                    return $resultado;
                }
                $usado += $peso;
                $resultado->push($mensaje);
                $ultima = $mensaje->sequence;
            }
        }

        return $resultado;
    }

    // ---------------------------------------------------------------- fijar y cancelar (§6.6, §6.5)

    /** @throws ErrorChat */
    public function fijar(string $messageId, bool $fijado): ChatMensaje
    {
        return DB::connection('chat')->transaction(function () use ($messageId, $fijado): ChatMensaje {
            $mensaje = ChatMensaje::query()->where('message_id', $messageId)->lockForUpdate()->first()
                ?? throw ErrorChat::notFound('El mensaje no existe.');
            if ($mensaje->pinned !== $fijado) {
                $mensaje->pinned = $fijado;
                $mensaje->updated_ms = max(self::ahoraMs(), (int) $mensaje->updated_ms);
                $mensaje->save();
            }

            return $mensaje;
        });
    }

    /** POST /mensajes/{id}/cancelar. @throws ErrorChat 404 si no existe, 409 bad_state si ya es final */
    public function cancelarPorId(string $messageId): ChatMensaje
    {
        $mensaje = $this->obtener($messageId);
        if (! $this->cancelar($mensaje)) {
            throw ErrorChat::badState('El mensaje ya es final.');
        }

        return $mensaje;
    }

    // ---------------------------------------------------------------- sincronizacion (§6.5)

    /**
     * POST /sincronizar: lo nuevo, los deltas de los mensajes abiertos (en bytes UTF-8, sin partir caracteres, maximo
     * max_delta_bytes cada uno y antes del estado final), los cambios de estado y de fijado y el titulo.
     *
     * Un mensaje que el traductor tiene como PENDING y que ya avanzo solo recibe esta vez el paso a IN_PROGRESS: los deltas y el
     * estado final llegan despues (chat.md §5.2: no se envia delta de un mensaje PENDING).
     *
     * @param  array<string, mixed>  $cuerpo
     * @return array<string, mixed>
     *
     * @throws ErrorChat
     */
    public function sincronizar(array $cuerpo): array
    {
        $despues = $cuerpo['despues'] ?? 0;
        $abiertos = $cuerpo['abiertos'] ?? [];
        $titulo = $cuerpo['titulo'] ?? null;
        if (! is_int($despues) || $despues < 0 || ! is_array($abiertos) || ! array_is_list($abiertos) || count($abiertos) > (int) config('chat.max_abiertos_sincronizar')
            || ($titulo !== null && ! is_string($titulo))) {
            throw ErrorChat::badRequest('Cuerpo de sincronización no válido.');
        }

        $conversacion = ChatConversacion::unica();
        $ultima = (int) $conversacion->ultima_secuencia;
        // Con muchos mensajes por delante solo se entrega un trozo (los primeros): el traductor repite hasta alcanzar `ultima_secuencia`.
        $nuevos = $this->tomar(ChatMensaje::query()->where('sequence', '>', $despues)->where('sequence', '<=', $ultima), false, (int) config('chat.limite_lectura'));

        $deltas = [];
        $estados = [];
        $fijados = [];
        foreach ($abiertos as $abierto) {
            if (! is_array($abierto) || ! is_string($abierto['id'] ?? null)) {
                throw ErrorChat::badRequest('Un mensaje abierto no es válido.');
            }
            $mensaje = ChatMensaje::query()->where('message_id', $abierto['id'])->first();
            if (! $mensaje || $mensaje->sequence > $despues) {
                continue;   // los desconocidos se ignoran; los posteriores a `despues` ya viajan completos en `nuevos`
            }
            $bytesTexto = max(0, (int) ($abierto['texto'] ?? 0));
            $bytesRazon = max(0, (int) ($abierto['razonamiento'] ?? 0));
            $estadoVisto = (string) ($abierto['estado'] ?? '');
            $estado = $mensaje->estado;

            if ($estadoVisto === EstadoChat::Pendiente->value && $estado !== EstadoChat::Pendiente) {
                $estados[] = $this->entradaEstado($mensaje, EstadoChat::EnCurso);
            } else {
                $texto = $mensaje->texto();
                $anadidoTexto = self::trozo($texto, $bytesTexto);
                $anadidoRazon = self::trozo((string) $mensaje->razonamiento, $bytesRazon);
                if ($anadidoTexto !== '' || $anadidoRazon !== '') {
                    $deltas[] = ['id' => $mensaje->message_id, 'texto_anadido' => $anadidoTexto, 'razonamiento_anadido' => $anadidoRazon];
                }
                $pendiente = $bytesTexto + strlen($anadidoTexto) < strlen($texto)
                    || $bytesRazon + strlen($anadidoRazon) < strlen((string) $mensaje->razonamiento);
                if ($estado->value !== $estadoVisto && ! ($estado->esFinal() && $pendiente)) {
                    $estados[] = $this->entradaEstado($mensaje, $estado);
                }
            }

            if ($mensaje->pinned !== (bool) ($abierto['fijado'] ?? false)) {
                $fijados[] = ['id' => $mensaje->message_id, 'fijado' => $mensaje->pinned, 'actualizado_ms' => (int) $mensaje->updated_ms];
            }
        }

        $actual = $conversacion->titulo === '' ? null : $conversacion->titulo;

        return [
            'ultima_secuencia' => $ultima,
            'nuevos' => $nuevos->map(fn (ChatMensaje $m): array => $m->paraApi())->all(),
            'deltas' => $deltas,
            'estados' => $estados,
            'fijados' => $fijados,
            'titulo' => [
                'cambio' => $actual !== ($titulo === '' ? null : $titulo), 'valor' => $actual,
                // Hora del cambio (§6.10): la misma para todos los streams. Campo opcional que anade el contrato.
                'actualizado_ms' => (int) $conversacion->titulo_ms,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function entradaEstado(ChatMensaje $mensaje, EstadoChat $estado): array
    {
        return [
            'id' => $mensaje->message_id,
            'estado' => $estado->value,
            'actualizado_ms' => (int) $mensaje->updated_ms,
            'error' => $estado === EstadoChat::Fallido ? $mensaje->error : null,
            'sugerencias' => $estado === EstadoChat::Completado ? array_values($mensaje->sugerencias ?? []) : [],
        ];
    }

    /** Lo que hay en $texto a partir del byte $desde, hasta max_delta_bytes y sin partir un caracter UTF-8. */
    public static function trozo(string $texto, int $desde): string
    {
        $total = strlen($texto);
        while ($desde < $total && (ord($texto[$desde]) & 0xC0) === 0x80) {
            $desde++;   // un desplazamiento en mitad de un caracter: sigue en el siguiente
        }
        if ($desde >= $total) {
            return '';
        }
        $fin = min($total, $desde + max(4, (int) config('chat.max_delta_bytes')));
        while ($fin < $total && $fin > $desde && (ord($texto[$fin]) & 0xC0) === 0x80) {
            $fin--;
        }

        return substr($texto, $desde, $fin - $desde);
    }

    // ---------------------------------------------------------------- API de escritura para los motores

    /**
     * Crea la respuesta del servicio a un mensaje de la persona: PENDING (o IN_PROGRESS si $enCurso), vacia, con reply_to.
     * Normalmente ya la crea el envio; esto sirve para reintentos (por defecto reply_to es la pregunta).
     */
    public function crearRespuesta(ChatMensaje $pregunta, ?string $replyTo = null, bool $enCurso = false): ChatMensaje
    {
        return DB::connection('chat')->transaction(fn (): ChatMensaje => $this->crear(ChatConversacion::bloqueada(), [
            'contenido' => [], 'from_service' => true, 'reply_to' => $replyTo ?? $pregunta->message_id,
            'estado' => $enCurso ? EstadoChat::EnCurso : EstadoChat::Pendiente,
        ]));
    }

    /**
     * Modifica un mensaje abierto bajo bloqueo. Devuelve false (y refresca el modelo) si ya es final.
     *
     * @param  Closure(ChatMensaje): void  $cambio
     */
    private function modificarAbierto(ChatMensaje $mensaje, Closure $cambio): bool
    {
        return DB::connection('chat')->transaction(function () use ($mensaje, $cambio): bool {
            $fresco = ChatMensaje::query()->whereKey($mensaje->getKey())->lockForUpdate()->first();
            if (! $fresco) {
                return false;
            }
            $abierto = $fresco->estado->esAbierto();
            if ($abierto) {
                $cambio($fresco);
                $fresco->save();
            }
            $mensaje->setRawAttributes($fresco->getAttributes(), true);

            return $abierto;
        });
    }

    private function enCurso(ChatMensaje $mensaje): void
    {
        if ($mensaje->estado === EstadoChat::Pendiente) {
            $mensaje->estado = EstadoChat::EnCurso;
            $mensaje->updated_ms = max(self::ahoraMs(), (int) $mensaje->updated_ms);
        }
    }

    private function terminar(ChatMensaje $mensaje, EstadoChat $estado): void
    {
        $mensaje->estado = $estado;
        $mensaje->updated_ms = max(self::ahoraMs(), (int) $mensaje->updated_ms);
    }

    public function iniciar(ChatMensaje $mensaje): bool
    {
        return $this->modificarAbierto($mensaje, fn (ChatMensaje $m) => $this->enCurso($m));
    }

    /**
     * Anade texto al final de la ultima parte de texto (o crea una). Si el mensaje llegaria al limite de 1 MiB, corta el texto,
     * lo anade (devuelve true) y cierra el mensaje como COMPLETED (chat.md §7); las llamadas siguientes devuelven false.
     */
    public function anadirTexto(ChatMensaje $mensaje, string $texto): bool
    {
        if ($texto === '') {
            return $mensaje->estado->esAbierto();
        }

        return $this->modificarAbierto($mensaje, function (ChatMensaje $m) use ($texto): void {
            $this->enCurso($m);
            $partes = $m->contenido ?? [];
            $usado = strlen((string) $m->razonamiento) + strlen($m->texto());
            $libre = max(0, (int) config('chat.max_mensaje_bytes') - $usado);
            $lleno = strlen($texto) > $libre;
            if ($lleno) {
                $texto = mb_strcut($texto, 0, $libre, 'UTF-8');
            }
            if ($texto !== '') {
                $indice = array_key_last($partes);
                if ($indice !== null && ($partes[$indice]['tipo'] ?? null) === 'texto') {
                    $partes[$indice]['texto'] .= $texto;
                } else {
                    $partes[] = ['tipo' => 'texto', 'texto' => $texto];
                }
                $m->contenido = $partes;
            }
            if ($lleno) {
                $this->terminar($m, EstadoChat::Completado);
            }
        });
    }

    public function anadirRazonamiento(ChatMensaje $mensaje, string $texto): bool
    {
        if ($texto === '') {
            return $mensaje->estado->esAbierto();
        }

        return $this->modificarAbierto($mensaje, function (ChatMensaje $m) use ($texto): void {
            $this->enCurso($m);
            $libre = max(0, (int) config('chat.max_mensaje_bytes') - strlen((string) $m->razonamiento) - strlen($m->texto()));
            $m->razonamiento = (string) $m->razonamiento.mb_strcut($texto, 0, $libre, 'UTF-8');
        });
    }

    /** @param  array<int, mixed>  $sugerencias  hasta 3 respuestas cortas; se descartan las vacias */
    public function finalizar(ChatMensaje $mensaje, array $sugerencias = []): bool
    {
        $limpias = self::limpiarSugerencias($sugerencias);

        return $this->modificarAbierto($mensaje, function (ChatMensaje $m) use ($limpias): void {
            $m->sugerencias = $limpias;
            $this->terminar($m, EstadoChat::Completado);
        });
    }

    /** FAILED con `error` (texto legible de 1 a 1024 bytes, sin datos secretos). */
    public function fallar(ChatMensaje $mensaje, string $error): bool
    {
        $error = mb_strcut(trim($error) !== '' ? trim($error) : 'No se pudo generar la respuesta.', 0, 1024, 'UTF-8');

        return $this->modificarAbierto($mensaje, function (ChatMensaje $m) use ($error): void {
            $m->error = $error;
            $this->terminar($m, EstadoChat::Fallido);
        });
    }

    public function cancelar(ChatMensaje $mensaje): bool
    {
        return $this->modificarAbierto($mensaje, fn (ChatMensaje $m) => $this->terminar($m, EstadoChat::Cancelado));
    }

    /**
     * Parte de imagen o archivo para un mensaje del servicio, a partir de un archivo ya guardado en chat_archivos.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException si el archivo no existe
     */
    public function parteArchivo(string $tipo, string $hash, ?string $alt = null): array
    {
        $archivo = Archivos::normalizarHash($hash) !== null ? $this->archivos->metadatos(strtolower($hash)) : null;
        if (! $archivo) {
            throw new InvalidArgumentException('El archivo no existe en chat_archivos.');
        }
        $parte = ['tipo' => $tipo, 'hash' => $archivo->hash, 'mime' => $archivo->mime, 'tamano' => (int) $archivo->tamano, 'nombre' => $archivo->nombre];
        if ($tipo === 'imagen') {
            $parte['alt'] = $alt;
        }

        return $parte;
    }

    /**
     * Publica un mensaje nuevo del servicio, ya COMPLETED, con estas partes (texto, imagen, archivo, peticion_secreto) y el
     * mismo reply_to que $respuesta. Es la unica forma de dar partes que no son de texto: un mensaje abierto solo crece en texto.
     *
     * @param  array<int, array<string, mixed>>  $partes
     * @param  array<int, string>  $sugerencias
     */
    public function publicarPartes(ChatMensaje $respuesta, array $partes, array $sugerencias = []): ChatMensaje
    {
        return DB::connection('chat')->transaction(function () use ($respuesta, $partes, $sugerencias): ChatMensaje {
            $mensaje = $this->crear(ChatConversacion::bloqueada(), [
                'contenido' => $partes, 'from_service' => true, 'reply_to' => $respuesta->reply_to, 'estado' => EstadoChat::Completado,
                'sugerencias' => self::limpiarSugerencias($sugerencias),
            ]);

            return $mensaje;
        });
    }

    /**
     * Pide un secreto a la persona (chat.md §5.6): publica un mensaje COMPLETED con la parte `peticion_secreto` y apunta la
     * peticion como pendiente. Cierra $respuesta (COMPLETED si ya tenia contenido; si estaba vacia, CANCELLED, para no dejar una
     * burbuja en marcha que nunca termina). Cuando la persona responda (Secretos), se lanza otra respuesta a la misma pregunta
     * con reply_to = el mensaje de la peticion.
     *
     * @throws InvalidArgumentException si request_id, nombre o prosa no son validos
     */
    public function pedirSecreto(ChatMensaje $respuesta, string $requestId, string $nombre, string $prosa): ChatMensaje
    {
        if (preg_match(self::PATRON_CLAVE, $requestId) !== 1 || preg_match(self::PATRON_NOMBRE_SECRETO, $nombre) !== 1) {
            throw new InvalidArgumentException('request_id o nombre del secreto no válidos.');
        }
        $prosa = mb_strcut(trim($prosa), 0, 1024, 'UTF-8');

        return DB::connection('chat')->transaction(function () use ($respuesta, $requestId, $nombre, $prosa): ChatMensaje {
            $this->modificarAbierto($respuesta, function (ChatMensaje $m): void {
                if ($m->texto() === '' && (string) $m->razonamiento === '') {
                    $m->metadata = ['motivo' => 'peticion_secreto'];
                    $this->terminar($m, EstadoChat::Cancelado);
                } else {
                    $this->terminar($m, EstadoChat::Completado);
                }
            });

            $mensaje = $this->publicarPartes($respuesta, [
                ['tipo' => 'peticion_secreto', 'request_id' => $requestId, 'nombre' => $nombre, 'prosa' => $prosa === '' ? null : $prosa],
            ]);
            ChatPeticionSecreto::query()->create([
                'request_id' => $requestId, 'message_id' => $mensaje->message_id, 'nombre' => $nombre, 'estado' => ChatPeticionSecreto::PENDIENTE,
            ]);

            return $mensaje;
        });
    }

    /**
     * Publica un mensaje del servicio con un texto y un enlace a otro slot de la instancia (parte `slot_link`, chat.md §5.8: un mazo de
     * cards, por ejemplo) y cierra $respuesta como en pedirSecreto(): COMPLETED si ya tenia contenido, CANCELLED si estaba vacia. El puerto
     * es el de un slot de service.json; la etiqueta, de 1 a 32 caracteres.
     *
     * @throws InvalidArgumentException si el puerto no es valido
     */
    public function enlazarSlot(ChatMensaje $respuesta, string $texto, int $puerto, ?string $etiqueta = null): ChatMensaje
    {
        if ($puerto < 1 || $puerto > 65535) {
            throw new InvalidArgumentException('El puerto del slot no es válido.');
        }
        $etiqueta = $etiqueta === null ? null : mb_substr(trim((string) preg_replace('/\s+/', ' ', $etiqueta)), 0, 32);

        return DB::connection('chat')->transaction(function () use ($respuesta, $texto, $puerto, $etiqueta): ChatMensaje {
            $this->modificarAbierto($respuesta, function (ChatMensaje $m): void {
                if ($m->texto() === '' && (string) $m->razonamiento === '') {
                    $m->metadata = ['motivo' => 'slot_link'];
                    $this->terminar($m, EstadoChat::Cancelado);
                } else {
                    $this->terminar($m, EstadoChat::Completado);
                }
            });

            return $this->publicarPartes($respuesta, [
                ['tipo' => 'texto', 'texto' => $texto],
                ['tipo' => 'slot_link', 'port' => $puerto, 'label' => $etiqueta === '' ? null : $etiqueta],
            ]);
        });
    }

    /** El mensaje de la persona al que contesta (siguiendo reply_to) un mensaje del servicio, o null. */
    public function preguntaDe(ChatMensaje $mensaje): ?ChatMensaje
    {
        for ($saltos = 0; $saltos < 10 && $mensaje->reply_to !== null; $saltos++) {
            $mensaje = ChatMensaje::query()->where('message_id', $mensaje->reply_to)->first();
            if (! $mensaje) {
                return null;
            }
            if (! $mensaje->from_service) {
                return $mensaje;
            }
        }

        return null;
    }

    /**
     * Vuelve a responder la pregunta que quedo esperando un secreto: nueva respuesta con reply_to = el mensaje de la peticion
     * (chat.md §5.6) y job encolado. El job solo lleva ids.
     */
    public function reintentarTrasSecreto(ChatMensaje $peticion): ?ChatMensaje
    {
        $pregunta = $this->preguntaDe($peticion);
        if (! $pregunta) {
            return null;
        }
        $respuesta = $this->crearRespuesta($pregunta, $peticion->message_id);
        ResponderChat::dispatch($pregunta->id, $respuesta->id);

        return $respuesta;
    }
}
