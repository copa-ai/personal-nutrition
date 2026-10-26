<?php

namespace App\Models\Menu;

use App\Services\Menu\RegistroConsumo;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

trait ImportaEntrega
{
    /**
     * Guarda la entrega del agente. Si no cumple el contrato (ver ValidaEntrega) lanza una excepción con los errores y no guarda nada.
     * El consumo por respuesta (`consumo.filas`) va al libro `consumos_menu` en la misma transacción; en `contenido` solo quedan los totales.
     *
     * @param  array<string, mixed>  $datos
     *
     * @throws InvalidArgumentException
     */
    public function importarEntrega(array $datos): void
    {
        $errores = $this->validarEntrega($datos);
        if ($errores !== []) {
            $resto = count($errores) - 8;
            throw new InvalidArgumentException('La entrega del agente no es válida: '.implode(' ', array_slice($errores, 0, 8)).($resto > 0 ? " (y {$resto} errores más)" : ''));
        }

        $filas = $datos['consumo']['filas'] ?? [];
        unset($datos['consumo']['filas']);

        DB::transaction(function () use ($datos, $filas): void {
            $this->update(['contenido' => $datos, 'selecciones' => null, 'huella' => hash('sha256', json_encode($datos))]);
            RegistroConsumo::registrar($this, is_array($filas) ? $filas : []);
        });
    }
}
