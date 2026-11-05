<?php

namespace App\Filament\Pages;

use App\Models\Ajuste;
use App\Models\ChatMensaje;
use App\Models\User;
use App\Services\Chat\RespondedorMixto;
use App\Services\Menu\TokenClaude;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

/** Interruptores de la instancia: chat habilitado y login automatico, mas el estado del chat. Cada cambio se audita (Ajuste::fijar). */
class AjustesServicio extends Page
{
    protected string $view = 'filament.pages.ajustes-servicio';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|\UnitEnum|null $navigationGroup = 'Ajustes';

    protected static ?string $navigationLabel = 'Ajustes del servicio';

    protected static ?string $title = 'Ajustes del servicio';

    protected static ?string $slug = 'ajustes-servicio';

    protected static ?int $navigationSort = 8;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'chat_habilitado' => Ajuste::chatHabilitado(),
            'chat_claude_habilitado' => Ajuste::claudeChatHabilitado(),
            'autologin_activo' => (bool) Ajuste::obtener(Ajuste::AUTOLOGIN_ACTIVO),
            'autologin_usuario_id' => self::usuarioElegidoId(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Chat')->schema([
                Toggle::make('chat_habilitado')->label('Chat habilitado')->live()
                    ->helperText('El túnel del nodo es la frontera de seguridad: quien tenga acceso a esta instancia ya está autenticado y puede conversar y, con Claude, consultar los datos de todos los perfiles. Apágalo para que el servicio rechace el chat.')
                    ->afterStateUpdated(fn (bool $state) => Ajuste::fijar(Ajuste::CHAT_HABILITADO, $state)),
                Toggle::make('chat_claude_habilitado')->label('Chat con Claude')->live()
                    ->helperText('Con esto apagado el chat responde siempre con el bot básico (sin IA) y no pide el token de Claude, aunque haya uno guardado. No afecta a la generación de menús.')
                    ->afterStateUpdated(fn (bool $state) => Ajuste::fijar(Ajuste::CHAT_CLAUDE_HABILITADO, $state)),
            ]),
            Section::make('Login automático')->schema([
                Toggle::make('autologin_activo')->label('Entrar sin contraseña')->live()
                    ->helperText('AVISO DE SEGURIDAD: con esto activo, cualquiera que llegue a la web de esta instancia entra como el usuario elegido, sin pedir contraseña. Úsalo solo si el acceso ya está protegido (el túnel del nodo: quien no conozca el token de la instancia no llega). Para entrar a mano, cierra sesión o usa /login?manual=1.')
                    ->afterStateUpdated(function (bool $state, Set $set): void {
                        if ($state && Ajuste::obtener(Ajuste::AUTOLOGIN_USUARIO_ID) === null) {
                            $id = self::usuarioElegidoId();
                            if ($id !== null) {
                                Ajuste::fijar(Ajuste::AUTOLOGIN_USUARIO_ID, $id);
                                $set('autologin_usuario_id', $id);
                            }
                        }
                        Ajuste::fijar(Ajuste::AUTOLOGIN_ACTIVO, $state && Ajuste::obtener(Ajuste::AUTOLOGIN_USUARIO_ID) !== null);
                    }),
                Select::make('autologin_usuario_id')->label('Usuario con el que se entra')->live()
                    ->options(fn (): array => User::query()->orderBy('email')->get()->mapWithKeys(fn (User $u): array => [$u->id => $u->name.' <'.$u->email.'>'])->all())
                    ->afterStateUpdated(function ($state): void {
                        // El estado viene del navegador: solo vale el id de un usuario que existe.
                        if (is_string($state) && Str::isUuid($state) && User::query()->whereKey($state)->exists()) {
                            Ajuste::fijar(Ajuste::AUTOLOGIN_USUARIO_ID, $state);
                        }
                    }),
            ]),
        ]);
    }

    /** El usuario guardado si existe; si no, admin@demo.com o el primero. Null si no hay usuarios. */
    public static function usuarioElegidoId(): ?string
    {
        $guardado = Ajuste::obtener(Ajuste::AUTOLOGIN_USUARIO_ID);
        if (is_string($guardado) && Str::isUuid($guardado) && User::query()->whereKey($guardado)->exists()) {
            return (string) $guardado;
        }
        $id = User::query()->where('email', 'admin@demo.com')->value('id') ?? User::query()->orderBy('id')->value('id');

        return $id === null ? null : (string) $id;
    }

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $claude = TokenClaude::existe();
        $bot = (string) config('chat.respondedor_bot');

        return [
            'tokenClaude' => $claude,
            'motor' => RespondedorMixto::claudeActivo() ? 'Claude' : (class_exists($bot) ? 'Bot básico (sin IA)' : 'Eco (provisional)'),
            'claudeChatHabilitado' => Ajuste::claudeChatHabilitado(),
            'mensajes' => ChatMensaje::query()->count(),
            'chatHabilitado' => Ajuste::chatHabilitado(),
        ];
    }
}
