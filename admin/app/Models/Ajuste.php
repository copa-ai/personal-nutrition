<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Interruptor o ajuste de la instancia: clave unica y valor json. Valores por defecto en DEFECTOS.
 */
class Ajuste extends Model
{
    public const CHAT_HABILITADO = 'chat_habilitado';

    /** Si el chat puede responder con Claude (aunque haya token). Apagado, responde siempre el bot basico y no se pide el token. */
    public const CHAT_CLAUDE_HABILITADO = 'chat_claude_habilitado';

    public const AUTOLOGIN_ACTIVO = 'autologin_activo';

    public const AUTOLOGIN_USUARIO_ID = 'autologin_usuario_id';

    /** Valor de cada ajuste mientras no se haya fijado. */
    public const DEFECTOS = [
        self::CHAT_HABILITADO => true,
        self::CHAT_CLAUDE_HABILITADO => true,
        self::AUTOLOGIN_ACTIVO => false,
        self::AUTOLOGIN_USUARIO_ID => null,
    ];

    protected $table = 'ajustes';

    protected $fillable = ['clave', 'valor'];

    protected function casts(): array
    {
        return ['valor' => 'json'];
    }

    /** Valor del ajuste: el fijado, si no el de DEFECTOS, si no $defecto. */
    public static function obtener(string $clave, mixed $defecto = null): mixed
    {
        $fila = static::query()->where('clave', $clave)->first();
        if ($fila) {
            return $fila->valor;
        }

        return array_key_exists($clave, self::DEFECTOS) ? self::DEFECTOS[$clave] : $defecto;
    }

    /** Fija el valor y, si cambia, lo audita (sin pasar por la auditoria generica, que enmascara el campo «clave»). */
    public static function fijar(string $clave, mixed $valor): void
    {
        $antes = static::obtener($clave);
        static::query()->updateOrCreate(['clave' => $clave], ['valor' => $valor]);

        if ($antes !== $valor) {
            Auditoria::registrar(
                str_starts_with($clave, 'chat_') ? 'chat.interruptor_cambiado' : 'ajuste.cambiado',
                'Ajuste '.$clave.' cambiado',
                ['ajuste' => $clave, 'antes' => $antes, 'despues' => $valor],
            );
        }
    }

    public static function chatHabilitado(): bool
    {
        return (bool) static::obtener(self::CHAT_HABILITADO);
    }

    public static function claudeChatHabilitado(): bool
    {
        return (bool) static::obtener(self::CHAT_CLAUDE_HABILITADO);
    }
}
