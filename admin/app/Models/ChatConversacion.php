<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * La conversacion principal de la instancia: una sola fila (id 1). Guarda el titulo y la ultima `sequence`; se bloquea
 * (lockForUpdate dentro de una transaccion) para repartir secuencias sin huecos ni duplicados.
 */
class ChatConversacion extends Model
{
    public const ID = 1;

    protected $connection = 'chat';

    protected $table = 'chat_conversacion';

    public $incrementing = false;

    protected $guarded = [];

    /** La fila de la conversacion (se crea la primera vez). */
    public static function unica(): static
    {
        static::asegurar();

        return static::query()->findOrFail(self::ID);
    }

    /** Como unica(), pero bloqueada hasta el fin de la transaccion actual. Llamar dentro de DB::transaction(). */
    public static function bloqueada(): static
    {
        static::asegurar();

        return static::query()->lockForUpdate()->findOrFail(self::ID);
    }

    private static function asegurar(): void
    {
        if (! static::query()->whereKey(self::ID)->exists()) {
            DB::table('chat_conversacion')->insertOrIgnore([
                'id' => self::ID,
                'creada_ms' => (int) (microtime(true) * 1000),
                'ultima_secuencia' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
