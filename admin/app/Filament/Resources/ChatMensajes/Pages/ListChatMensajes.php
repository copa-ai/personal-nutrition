<?php

namespace App\Filament\Resources\ChatMensajes\Pages;

use App\Filament\Resources\ChatMensajes\ChatMensajeResource;
use Filament\Resources\Pages\ListRecords;

class ListChatMensajes extends ListRecords
{
    protected static string $resource = ChatMensajeResource::class;
}
