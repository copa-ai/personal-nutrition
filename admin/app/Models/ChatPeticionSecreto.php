<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Una peticion de secreto hecha al usuario (chat.md §5.6). Nunca guarda el valor, solo el estado. */
class ChatPeticionSecreto extends Model
{
    use HasUuids;

    public const PENDIENTE = 'pendiente';

    public const RESPONDIDA = 'respondida';

    public const OMITIDA = 'omitida';

    protected $connection = 'chat';

    protected $table = 'chat_peticiones_secreto';

    protected $guarded = [];
}
