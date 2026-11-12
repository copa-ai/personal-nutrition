<?php

namespace App\Providers;

use App\Services\Chat\Respondedor;
use App\Services\Chat\RespondedorMixto;
use Illuminate\Support\ServiceProvider;

/** Enlaza el motor de respuesta del chat (Claude si hay token, si no el bot basico). */
class ChatServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->asegurarBaseDelChat();

        $this->app->bind(Respondedor::class, RespondedorMixto::class);
    }

    /**
     * El chat vive en un SQLite del disco de la instancia (conexion `chat`). El conector de SQLite no crea el fichero, asi que se
     * crea aqui (vacio; las tablas las crea `migrate --database=chat --path=database/migrations/chat`).
     */
    private function asegurarBaseDelChat(): void
    {
        $ruta = (string) config('database.connections.chat.database');
        if ($ruta === '' || $ruta === ':memory:' || file_exists($ruta)) {
            return;
        }
        if (! is_dir(dirname($ruta))) {
            @mkdir(dirname($ruta), 0700, true);
        }
        @touch($ruta);
    }
}
