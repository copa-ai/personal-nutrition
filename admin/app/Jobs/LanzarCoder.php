<?php

namespace App\Jobs;

use App\Models\Ajuste;
use App\Services\Coder\Coder;
use App\Services\Coder\CoderException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Start the Coder (App\Services\Coder\Coder::lanzar). The node can need minutes, so this runs in the queue and not in the web request.
 * The result is in the state of the Coder (settings "coder.*"), which the panel reads.
 */
class LanzarCoder implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 0;

    public function __construct(
        public readonly string $referencia,
        public readonly bool $confirmaSinSpec = false,
        public readonly ?string $inicialMu = null,
    ) {}

    public function handle(Coder $coder): void
    {
        try {
            $coder->lanzar($this->referencia, $this->confirmaSinSpec, $this->inicialMu);
        } catch (CoderException $e) {
            // A failure after the start already set the state to "error". A refusal before any change did not: store it for the panel.
            if ($coder->estado()['estado'] === Coder::NINGUNO) {
                Ajuste::fijar('coder.estado', Coder::ERROR);
                Ajuste::fijar('coder.error', $e->getMessage());
            }
        }
    }
}
