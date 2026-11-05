<?php

namespace App\Services\Chat\Marcado;

/**
 * Compone el texto de un mensaje del servicio: prosa, widgets y teclados. La prosa se neutraliza para que nada de lo que lleve
 * (un nombre de perfil, lo que escribió una persona) pueda abrir un bloque: una línea que empieza por ``` pierde esa valla.
 * Un mensaje admite como mucho 2 teclados (chat.md §5.5), y solo cuenta el último.
 */
final class Mensaje
{
    /** @var list<string> */
    private array $trozos = [];

    private int $teclados = 0;

    public static function nuevo(): self
    {
        return new self;
    }

    /** Quita de un texto cualquier cosa que haría de valla de código. */
    public static function neutralizar(string $texto): string
    {
        return (string) preg_replace('/^([ \t]*)(`{3,})/m', "$1\u{200B}$2", str_replace("\r\n", "\n", $texto));
    }

    public function texto(string $texto): self
    {
        if (trim($texto) !== '') {
            $this->trozos[] = self::neutralizar(trim($texto));
        }

        return $this;
    }

    public function widget(Widget $widget): self
    {
        $this->trozos[] = $widget->render();

        return $this;
    }

    public function teclado(Teclado $teclado): self
    {
        if (++$this->teclados > Reglas::MAX_TECLADOS_POR_MENSAJE) {
            throw new MarcadoInvalido('Un mensaje tiene como mucho '.Reglas::MAX_TECLADOS_POR_MENSAJE.' bloques keyboard.');
        }
        $this->trozos[] = $teclado->render();

        return $this;
    }

    public function render(): string
    {
        return implode("\n\n", $this->trozos);
    }
}
