<?php

namespace App\Filament\Resources\ChatMensajes\Schemas;

use App\Enums\EstadoChat;
use App\Filament\Resources\ChatMensajes\Tables\ChatMensajesTable;
use App\Models\ChatMensaje;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

/**
 * Detalle de un mensaje. El texto se muestra como texto plano (los widgets html del asistente NO se interpretan aquí) y las
 * fotos por la ruta autenticada /fotos/{hash}.
 */
class ChatMensajeInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Mensaje')->columns(3)->schema([
                TextEntry::make('sequence')->label('Nº'),
                TextEntry::make('from_service')->label('Quién')->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Asistente' : 'Persona'),
                TextEntry::make('estado')->label('Estado')->badge()
                    ->formatStateUsing(fn (EstadoChat $state): string => ChatMensajesTable::etiquetaEstado($state))
                    ->color(fn (EstadoChat $state): string => ChatMensajesTable::colorEstado($state)),
                TextEntry::make('created_ms')->label('Fecha')
                    ->formatStateUsing(fn (int $state): string => date('d/m/Y H:i:s', intdiv($state, 1000))),
                TextEntry::make('reply_to')->label('Responde a')->placeholder('—'),
                TextEntry::make('message_id')->label('Identificador'),
            ]),
            Section::make('Texto')->schema([
                TextEntry::make('texto')->hiddenLabel()->state(fn (ChatMensaje $r): string => $r->texto())
                    ->placeholder('Sin texto')->extraAttributes(['style' => 'white-space: pre-wrap; word-break: break-word']),
            ]),
            Section::make('Razonamiento')->collapsed()->schema([
                TextEntry::make('razonamiento')->hiddenLabel()->placeholder('Sin razonamiento')
                    ->extraAttributes(['style' => 'white-space: pre-wrap; word-break: break-word']),
            ]),
            Section::make('Error')->visible(fn (ChatMensaje $r): bool => filled($r->error))->schema([
                TextEntry::make('error')->hiddenLabel()->color('danger'),
            ]),
            Section::make('Fotos')->visible(fn (ChatMensaje $r): bool => $r->imagenes() !== [])->schema([
                View::make('filament.chat.fotos'),
            ]),
        ]);
    }
}
