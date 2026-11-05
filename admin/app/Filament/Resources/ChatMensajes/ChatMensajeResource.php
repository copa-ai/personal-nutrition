<?php

namespace App\Filament\Resources\ChatMensajes;

use App\Filament\Resources\ChatMensajes\Pages\ListChatMensajes;
use App\Filament\Resources\ChatMensajes\Pages\ViewChatMensaje;
use App\Filament\Resources\ChatMensajes\Schemas\ChatMensajeInfolist;
use App\Filament\Resources\ChatMensajes\Tables\ChatMensajesTable;
use App\Models\ChatMensaje;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Historial del chat de la instancia: solo lectura (listar y ver). */
class ChatMensajeResource extends Resource
{
    protected static ?string $model = ChatMensaje::class;

    protected static ?string $slug = 'chat';

    protected static ?string $modelLabel = 'mensaje del chat';

    protected static ?string $pluralModelLabel = 'chat';

    protected static ?string $navigationLabel = 'Chat';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'Seguimiento';

    protected static ?int $navigationSort = 5;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /** Un id que no es numerico (p. ej. /chat/create) es un 404, no un error de la base (PostgreSQL rechaza el tipo). */
    public static function resolveRecordRouteBinding(int|string $key, ?\Closure $modifyQuery = null): ?Model
    {
        return ctype_digit((string) $key) ? parent::resolveRecordRouteBinding($key, $modifyQuery) : null;
    }

    public static function infolist(Schema $schema): Schema
    {
        return ChatMensajeInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ChatMensajesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListChatMensajes::route('/'),
            'view' => ViewChatMensaje::route('/{record}'),
        ];
    }
}
