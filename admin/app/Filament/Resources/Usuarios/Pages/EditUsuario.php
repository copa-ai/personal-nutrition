<?php

namespace App\Filament\Resources\Usuarios\Pages;

use App\Filament\Resources\Usuarios\UsuarioResource;
use App\Models\Auditoria;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EditUsuario extends EditRecord
{
    protected static string $resource = UsuarioResource::class;

    /** Indica si el guardado en curso incluye una contraseña nueva. */
    protected bool $contrasenaCambiada = false;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->hidden(fn (User $record): bool => ! $record->puedeBorrarse()),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->contrasenaCambiada = filled($data['password'] ?? null);

        return $data;
    }

    /**
     * Al cambiar la contraseña se cierran las sesiones de ese usuario (si se guardan en base de datos) y se audita.
     */
    protected function afterSave(): void
    {
        if (! $this->contrasenaCambiada) {
            return;
        }

        /** @var User $usuario */
        $usuario = $this->getRecord();

        if (config('session.driver') === 'database' && Schema::hasTable('sessions') && $usuario->getKey() !== auth()->id()) {
            DB::table('sessions')->where('user_id', $usuario->getKey())->delete();
        }

        Auditoria::registrar(
            'usuario.contrasena_cambiada',
            'Contraseña cambiada del usuario '.$usuario->email,
            ['usuario_id' => $usuario->getKey()],
        );

        $this->contrasenaCambiada = false;
    }
}
