<?php

namespace App\Console\Commands;

use App\Services\Coder\Coder;
use App\Services\Coder\Saldo;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Watch the Coder child. start.sh runs this command in two loops.
 * Every 60 s, without options: check that the lease is still in the pool. If the Coder died, remove destino.json and set the state to "error".
 * Every 300 s, with --saldo: do the same check, then sample the balance of nutrition and fund the Coder (App\Services\Coder\Saldo).
 * An error is written on the output. It never ends the loop: the command always exits with success.
 */
#[Signature('nutrition:vigilar-coder {--saldo : También toma una muestra del saldo y recarga al Coder si los tres controles lo permiten}')]
#[Description('Comprueba que el Coder sigue en el nodo, quita su destino SSH si murió y, con --saldo, vigila su saldo')]
class VigilarCoder extends Command
{
    public function handle(Coder $coder, Saldo $saldo): int
    {
        try {
            $resultado = $coder->vigilar();
        } catch (Throwable $e) {
            $this->warn('No se pudo vigilar el Coder: '.$e->getMessage());

            return self::SUCCESS;
        }

        $this->line("Coder: {$resultado}");
        if (! $this->option('saldo') || in_array($resultado, ['ajeno', 'sin_nodo'], true)) {
            return self::SUCCESS;
        }

        try {
            $r = $saldo->vigilar();
        } catch (Throwable $e) {
            $this->warn('No se pudo vigilar el saldo del Coder: '.$e->getMessage());

            return self::SUCCESS;
        }
        $this->line("Saldo: {$r['accion']}".($r['mu'] > 0 ? " {$r['mu']} MU" : '').($r['motivo'] === null ? '' : " ({$r['motivo']})"));

        return self::SUCCESS;
    }
}
