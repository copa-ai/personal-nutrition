<?php

namespace App\Services\Menu;

use App\Services\Claude\EjecutorClaude;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Token de larga duracion de Claude Code (`claude setup-token`, usa la suscripcion) que se pega en la administracion.
 * Se guarda cifrado con la clave de la aplicacion y se le pasa al agente en CLAUDE_CODE_OAUTH_TOKEN.
 */
class TokenClaude
{
    private const ARCHIVO = 'claude-token.enc';

    /** Hay una clave guardada Y se puede descifrar. Con otra APP_KEY cuenta como «sin configurar» (hay que pegarla otra vez). */
    public static function existe(): bool
    {
        return static::obtener() !== null;
    }

    public static function obtener(): ?string
    {
        if (! Storage::disk('local')->exists(self::ARCHIVO)) {
            return null;
        }
        try {
            return Crypt::decryptString(Storage::disk('local')->get(self::ARCHIVO));
        } catch (Throwable) {
            return null; // cambio de APP_KEY: hay que pegarlo otra vez
        }
    }

    public static function guardar(string $token): void
    {
        Storage::disk('local')->put(self::ARCHIVO, Crypt::encryptString(trim($token)));
    }

    public static function quitar(): void
    {
        Storage::disk('local')->delete(self::ARCHIVO);
    }

    /** Variables de entorno para el proceso de claude. */
    public static function entorno(): array
    {
        $token = static::obtener();

        return $token ? ['CLAUDE_CODE_OAUTH_TOKEN' => $token] : [];
    }

    /** Pregunta algo trivial a claude para ver si responde. @return array{ok: bool, mensaje: string} */
    public static function probar(): array
    {
        try {
            $r = (new EjecutorClaude)->ejecutar('Responde solo: ok', null, 120);
        } catch (Throwable $e) {
            return ['ok' => false, 'mensaje' => $e->getMessage()];
        }

        return ['ok' => $r->exitoso(), 'mensaje' => $r->texto() !== '' ? $r->texto() : 'Sin respuesta (código '.$r->codigo.').'];
    }
}
