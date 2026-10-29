<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\AsCommand;
use Illuminate\Console\Command;

/**
 * Crea el usuario de demostracion del panel, solo si todavia no hay ninguno (el servicio de Celaut lo ejecuta en cada
 * arranque: una vez cambiados el correo o la contraseña, no se vuelve a crear).
 */
#[AsCommand(name: 'usuario:inicial', description: 'Crea admin@demo.com si no hay ningun usuario')]
class UsuarioInicial extends Command
{
    public function handle(): int
    {
        if (User::query()->exists()) {
            $this->info('Ya hay usuarios: no se crea ninguno.');

            return self::SUCCESS;
        }

        User::create(['name' => 'Admin', 'email' => 'admin@demo.com', 'password' => 'password']);
        $this->info('Creado admin@demo.com (contraseña «password»): cámbiala en el perfil del panel.');

        return self::SUCCESS;
    }
}
