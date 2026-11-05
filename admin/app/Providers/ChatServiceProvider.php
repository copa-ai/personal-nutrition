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
        $this->app->bind(Respondedor::class, RespondedorMixto::class);
    }
}
