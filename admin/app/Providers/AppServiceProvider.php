<?php

namespace App\Providers;

use App\Services\BaseCompartida\EstadoBase;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // La base compartida puede cambiar mientras el servicio corre (nutrition:vigilar-base): cada peticion, orden y trabajo la comprueba.
        EstadoBase::aplicar();
        Event::listen(JobProcessing::class, fn () => EstadoBase::aplicar());
    }
}
