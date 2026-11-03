<?php

namespace App\Console\Commands;

use App\Services\Celaut\Nodo;
use App\Services\Celaut\NodoException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Para las instancias Celaut que lanzo este servicio (nutrition-postgres). start.sh lo llama al terminar. */
#[Signature('nutrition:apagar-nodo')]
#[Description('Para las instancias Celaut que lanzo este servicio')]
class ApagarNodo extends Command
{
    public function handle(): int
    {
        try {
            Nodo::desdeConfig()->apagar();
        } catch (NodoException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
