<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable;

    /** Sin esto, Filament devuelve 403 tras el login fuera del entorno local. */
    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    /**
     * Impide borrar al usuario en sesión y al último usuario que queda; protege también el borrado en bloque.
     */
    protected static function booted(): void
    {
        static::deleting(function (User $usuario): bool {
            return $usuario->puedeBorrarse();
        });
    }

    /** Un usuario no puede borrarse a sí mismo ni ser el último que queda. */
    public function puedeBorrarse(): bool
    {
        if ($this->getKey() === auth()->id()) {
            return false;
        }

        return static::query()->count() > 1;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
