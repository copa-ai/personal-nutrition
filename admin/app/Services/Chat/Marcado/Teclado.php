<?php

namespace App\Services\Chat\Marcado;

/**
 * Construye un bloque ```keyboard (chat.md §5.5): teclas y campos. Rechaza al construir lo que el cliente no mostraría
 * (más de 64 teclas o 8 campos, nombres repetidos) y al renderizar los {marcadores} sin campo y los bloques de más de 8000
 * caracteres.
 *
 *   Teclado::nuevo()->parrafo('¿Seguimos?')->fila(Tecla::de('Sí', 'Sí, sigue')->enviando(), Tecla::de('No', 'No, déjalo'))->render()
 *
 * Un data-say debe ser un mensaje completo y seguro: quien lo recibe es un mensaje de la persona, con los valores que ella escribió.
 */
final class Teclado
{
    /** @var list<Nodo> */
    private array $nodos = [];

    private int $teclas = 0;

    /** @var array<string, true> */
    private array $campos = [];

    /** @var list<string> */
    private array $dis = [];

    public static function nuevo(): self
    {
        return new self;
    }

    /** Texto entre las filas (una pregunta, una explicación). */
    public function parrafo(string $texto): self
    {
        $this->nodos[] = Nodo::elemento('p', [], [Reglas::linea($texto)]);

        return $this;
    }

    /** Una fila: teclas y campos que van juntos. */
    public function fila(Tecla|Campo ...$elementos): self
    {
        if ($elementos === []) {
            throw new MarcadoInvalido('Una fila necesita al menos una tecla o un campo.');
        }
        $hijos = [];
        foreach ($elementos as $elemento) {
            if ($elemento instanceof Tecla) {
                if (++$this->teclas > Reglas::MAX_TECLAS) {
                    throw new MarcadoInvalido('Un bloque keyboard tiene como mucho '.Reglas::MAX_TECLAS.' teclas.');
                }
                $this->dis[] = $elemento->di;
            } else {
                if (isset($this->campos[$elemento->nombre])) {
                    throw new MarcadoInvalido("El campo «{$elemento->nombre}» está repetido.");
                }
                if (count($this->campos) >= Reglas::MAX_CAMPOS) {
                    throw new MarcadoInvalido('Un bloque keyboard tiene como mucho '.Reglas::MAX_CAMPOS.' campos.');
                }
                $this->campos[$elemento->nombre] = true;
            }
            $hijos[] = $elemento->nodo();
        }
        $this->nodos[] = Nodo::elemento('div', [], $hijos);

        return $this;
    }

    /** El bloque con sus vallas, listo para poner en un texto. @throws MarcadoInvalido */
    public function render(): string
    {
        if ($this->teclas === 0) {
            throw new MarcadoInvalido('Un bloque keyboard necesita al menos una tecla.');
        }

        return "```keyboard\n".Validador::bloque('keyboard', Nodo::serializar($this->nodos))."\n```";
    }
}
