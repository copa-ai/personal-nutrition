<?php

namespace App\Services\Chat\Marcado;

/** Una tecla de un bloque keyboard: etiqueta y data-say (el mensaje que pone o envía). */
final class Tecla
{
    private bool $enviar = false;

    private function __construct(public readonly string $etiqueta, public readonly string $di) {}

    /**
     * @param  string  $di  lo que dice la tecla; hasta 500 caracteres; un {nombre} se sustituye por el campo con ese data-name
     *
     * @throws MarcadoInvalido si el data-say está vacío o pasa de 500 caracteres
     */
    public static function de(string $etiqueta, string $di): self
    {
        $di = Reglas::linea($di);
        if ($di === '') {
            throw new MarcadoInvalido('Una tecla necesita un data-say que no esté vacío.');
        }
        if (mb_strlen($di) > Reglas::MAX_DI) {
            throw new MarcadoInvalido('El data-say de una tecla pasa de '.Reglas::MAX_DI.' caracteres.');
        }

        return new self(Reglas::linea($etiqueta), $di);
    }

    /** La tecla envía el mensaje al pulsarla (data-send). Solo para acciones que la persona pidió con claridad. */
    public function enviando(bool $enviar = true): self
    {
        $this->enviar = $enviar;

        return $this;
    }

    public function nodo(): Nodo
    {
        return Nodo::elemento('button', ['data-say' => $this->di] + ($this->enviar ? ['data-send' => true] : []), [Reglas::cortar($this->etiqueta, Reglas::MAX_DI)]);
    }
}
