<?php

namespace App\Console\Commands;

use App\Services\Coder\Coder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Watch the Coder child. start.sh runs this command in a loop.
 * It checks that the lease is still in the pool. If the Coder died, it removes destino.json and sets the state to "error".
 * The funding step adds the balance rules to this command.
 */
#[Signature('nutrition:vigilar-coder')]
#[Description('Comprueba que el Coder sigue en el nodo y quita su destino SSH si murió')]
class VigilarCoder extends Command
{
    public function handle(Coder $coder): int
    {
        try {
            $resultado = $coder->vigilar();
        } catch (Throwable $e) {
            $this->warn('No se pudo vigilar el Coder: '.$e->getMessage());

            return self::SUCCESS;
        }

        $this->line("Coder: {$resultado}");

        return self::SUCCESS;
    }
}
