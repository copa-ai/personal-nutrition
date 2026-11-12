<?php

namespace App\Services\Chat;

use App\Models\Auditoria;
use App\Models\ChatArchivo;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Archivos del chat en el SQLite del disco de la instancia (conexion `chat`, tabla chat_archivos, contenido binario). Identificados por su SHA3-256 en hexadecimal.
 */
class Archivos
{
    public const PATRON_HASH = '/^[0-9a-f]{64}$/';

    /** Hash en minusculas, o null si no es un SHA3-256 hexadecimal de 64 caracteres. */
    public static function normalizarHash(mixed $hash): ?string
    {
        if (! is_string($hash)) {
            return null;
        }
        $hash = strtolower($hash);

        return preg_match(self::PATRON_HASH, $hash) === 1 ? $hash : null;
    }

    /** Tipo en minusculas y sin parametros («Image/JPEG; q=1» -> «image/jpeg»). */
    public static function normalizarMime(string $mime): string
    {
        return strtolower(trim(explode(';', $mime, 2)[0]));
    }

    public function tipoAceptado(string $mime): bool
    {
        return in_array(self::normalizarMime($mime), config('chat.tipos_mime'), true);
    }

    /**
     * Cuales de los hashes existen. @param  array<int, string>  $hashes  @return array<string, bool> hash => disponible
     */
    public function disponibles(array $hashes): array
    {
        $existentes = $hashes === [] ? [] : ChatArchivo::query()->whereIn('hash', array_values(array_unique($hashes)))->pluck('hash')->all();
        $resultado = [];
        foreach ($hashes as $hash) {
            $resultado[$hash] = in_array($hash, $existentes, true);
        }

        return $resultado;
    }

    /** True si el fallo de la base es de falta de sitio: SQLITE_FULL (disco lleno, codigo 13) o, por compatibilidad, la clase SQLSTATE 53. */
    public static function faltaSitio(QueryException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === 13 || str_contains(strtolower($e->getMessage()), 'disk is full')
            || str_starts_with((string) $e->getCode(), '53') || str_starts_with((string) ($e->errorInfo[0] ?? ''), '53');
    }

    /** El archivo con sus bytes, o null. */
    public function buscar(string $hash): ?ChatArchivo
    {
        return ChatArchivo::query()->where('hash', $hash)->first();
    }

    /** Solo los metadatos (sin cargar los bytes), o null. */
    public function metadatos(string $hash): ?ChatArchivo
    {
        return ChatArchivo::query()->select(ChatArchivo::SIN_CONTENIDO)->where('hash', $hash)->first();
    }

    /**
     * Guarda un archivo subido tras comprobar, en este orden: estructura, tamano, tipo y que el SHA3-256 y el tamano declarado
     * coinciden con los bytes. Un fallo no deja nada. Si ya existia (mismo hash) no cambia nada y devuelve el existente.
     *
     * @throws ErrorChat
     */
    public function guardar(string $hashUrl, string $bytes, ?string $mime, ?string $tamanoDeclarado, ?string $nombre): ChatArchivo
    {
        $hash = self::normalizarHash($hashUrl);
        if ($hash === null) {
            throw ErrorChat::badRequest('El hash debe ser un SHA3-256 en hexadecimal (64 caracteres).');
        }
        if ($mime === null || trim($mime) === '') {
            throw ErrorChat::badRequest('Falta el tipo del archivo.');
        }
        if ($tamanoDeclarado === null || preg_match('/^[0-9]+$/', $tamanoDeclarado) !== 1 || (int) $tamanoDeclarado < 1) {
            throw ErrorChat::badRequest('Falta el tamaño del archivo o no es válido.');
        }
        $declarado = (int) $tamanoDeclarado;
        $real = strlen($bytes);
        if ($real < 1) {
            throw ErrorChat::badRequest('El archivo está vacío.');
        }
        $maximo = (int) config('chat.max_archivo_bytes');
        if ($declarado > $maximo || $real > $maximo) {
            throw ErrorChat::tooLarge('El archivo supera el máximo de '.intdiv($maximo, 1048576).' MiB.');
        }
        $mime = self::normalizarMime($mime);
        if (! $this->tipoAceptado($mime)) {
            throw ErrorChat::unsupportedMedia('Tipo de archivo no admitido: '.mb_substr($mime, 0, 100).'.');
        }
        if ($declarado !== $real || ! hash_equals($hash, hash('sha3-256', $bytes))) {
            throw ErrorChat::hashMismatch('Los bytes no tienen el hash o el tamaño indicados.');
        }

        if (($existente = $this->metadatos($hash)) !== null) {
            return $existente;
        }

        $total = (int) ChatArchivo::query()->sum('tamano');
        if ($total + $real > (int) config('chat.max_total_archivos_bytes')) {
            throw ErrorChat::storageFull('No queda espacio para más archivos en el chat.');
        }

        // El nombre es texto libre del cliente: UTF-8 valido y sin caracteres de control (un NUL no cabe en PostgreSQL).
        $nombre = $nombre === null ? null : trim((string) preg_replace('/[\x00-\x1F\x7F]/', '', mb_convert_encoding($nombre, 'UTF-8', 'UTF-8')));
        $nombre = $nombre === null || $nombre === '' ? null : mb_strcut($nombre, 0, 255);
        try {
            $nuevos = DB::connection('chat')->table('chat_archivos')->insertOrIgnore([
                'id' => ChatArchivo::idDeClave($hash), 'hash' => $hash, 'mime' => $mime, 'tamano' => $real, 'nombre' => $nombre, 'contenido' => $bytes,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (QueryException $e) {
            // §6.4, paso 4: un almacen que no puede tomar el archivo es storage_full, no un error interno.
            throw self::faltaSitio($e) ? ErrorChat::storageFull('No hay espacio para guardar el archivo.') : $e;
        }

        if ($nuevos > 0) {
            Auditoria::registrar('chat.archivo_subido', 'Archivo subido al chat', ['hash' => $hash, 'mime' => $mime, 'tamano' => $real]);
        }

        return $this->metadatos($hash) ?? throw ErrorChat::serviceError();
    }
}
