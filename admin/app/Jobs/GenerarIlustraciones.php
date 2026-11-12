<?php

namespace App\Jobs;

use App\Services\Compra\GeneradorIlustraciones;
use App\Services\Compra\Mazo;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Un lote de ilustraciones de la lista de la compra (GeneradorIlustraciones) y, al terminar, las pone en las tarjetas del mazo. Va en la
 * cola del chat: no lleva datos, solo se vuelve a leer el mazo.
 */
class GenerarIlustraciones implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 0;

    public function __construct()
    {
        $this->onQueue(ResponderChat::COLA);
    }

    /** @param  list<array<string, mixed>>  $filas */
    public static function hayPendientes(array $filas): bool
    {
        return GeneradorIlustraciones::pendientes($filas, 1) !== [];
    }

    public function handle(GeneradorIlustraciones $generador, Mazo $mazo): void
    {
        try {
            $generador->generar();
        } finally {
            Cache::forget('cards-ilustrar');
        }
        $mazo->refrescarImagenes();
    }

    public function failed(\Throwable $e): void
    {
        Cache::forget('cards-ilustrar');
    }
}
