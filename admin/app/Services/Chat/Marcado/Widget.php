<?php

namespace App\Services\Chat\Marcado;

/**
 * Construye un bloque ```html (un widget de solo lectura, chat.md §5.4). Todo el texto que se le da se escapa; solo se pueden
 * pedir cosas que el cliente muestra, así que no puede salir marcado inválido. render() lanza MarcadoInvalido si el bloque pasa
 * de 8000 caracteres (partir el contenido en varios widgets es cosa de quien llama).
 *
 *   Widget::nuevo()->titulo('Menú de hoy')->tabla(['Toma', 'Plato'], [['Comida', 'Lentejas'], ['Cena', Widget::resaltar('Tortilla', '#b45309')]])->render()
 *
 * Los textos de celdas, párrafos y listas son string o el resultado de resaltar().
 */
final class Widget
{
    /** @var list<Nodo> */
    private array $nodos = [];

    public static function nuevo(): self
    {
        return new self;
    }

    /**
     * Texto con énfasis para usar donde se admite texto.
     *
     * @return array{texto: string, negrita: bool, color: ?string}
     *
     * @throws MarcadoInvalido si el color no es #rgb, #rrggbb o #rrggbbaa
     */
    public static function resaltar(string $texto, ?string $color = null, bool $negrita = true): array
    {
        if ($color !== null && ! Reglas::color($color)) {
            throw new MarcadoInvalido("Color no válido: «{$color}» (usa #rgb o #rrggbb).");
        }

        return ['texto' => $texto, 'negrita' => $negrita, 'color' => $color];
    }

    /** @param  int  $nivel  1 a 4 */
    public function titulo(string $texto, int $nivel = 3): self
    {
        if ($nivel < 1 || $nivel > 4) {
            throw new MarcadoInvalido('El nivel de un título va de 1 a 4.');
        }
        $this->nodos[] = Nodo::elemento('h'.$nivel, [], $this->enLinea($texto));

        return $this;
    }

    /** @param  string|array{texto: string, negrita: bool, color: ?string}  ...$trozos */
    public function parrafo(string|array ...$trozos): self
    {
        $this->nodos[] = Nodo::elemento('p', [], $this->enLinea(...$trozos));

        return $this;
    }

    /** @param  list<string|array{texto: string, negrita: bool, color: ?string}>  $elementos */
    public function lista(array $elementos, bool $ordenada = false): self
    {
        $this->nodos[] = Nodo::elemento($ordenada ? 'ol' : 'ul', [], array_map(
            fn (string|array $e): Nodo => Nodo::elemento('li', [], $this->enLinea($e)), array_values($elementos)));

        return $this;
    }

    /**
     * @param  list<string>  $cabeceras  puede ir vacía
     * @param  list<list<string|array{texto: string, negrita: bool, color: ?string}>>  $filas
     */
    public function tabla(array $cabeceras, array $filas): self
    {
        $trs = [];
        if ($cabeceras !== []) {
            $trs[] = Nodo::elemento('tr', [], array_map(fn (string $c): Nodo => Nodo::elemento('th', [], $this->enLinea($c)), array_values($cabeceras)));
        }
        foreach ($filas as $fila) {
            $trs[] = Nodo::elemento('tr', [], array_map(fn (string|array $c): Nodo => Nodo::elemento('td', [], $this->enLinea($c)), array_values($fila)));
        }
        if ($trs === []) {
            throw new MarcadoInvalido('Una tabla necesita al menos una fila.');
        }
        $this->nodos[] = Nodo::elemento('table', [], $trs);

        return $this;
    }

    public function separador(): self
    {
        $this->nodos[] = Nodo::elemento('hr');

        return $this;
    }

    /** Longitud del bloque tal como saldría (sin las vallas). */
    public function longitud(): int
    {
        return mb_strlen(Nodo::serializar($this->nodos));
    }

    /** El bloque con sus vallas, listo para poner en un texto. */
    public function render(): string
    {
        if ($this->nodos === []) {
            throw new MarcadoInvalido('Un widget vacío no se puede generar.');
        }

        return "```html\n".Validador::bloque('html', Nodo::serializar($this->nodos))."\n```";
    }

    /** @return list<Nodo> */
    private function enLinea(string|array ...$trozos): array
    {
        $salida = [];
        foreach ($trozos as $trozo) {
            $datos = is_string($trozo) ? ['texto' => $trozo, 'negrita' => false, 'color' => null] : $trozo;
            $texto = Reglas::linea((string) $datos['texto'], false);
            if (trim($texto) === '') {
                continue;
            }
            $nodo = Nodo::texto($texto);
            if (! empty($datos['negrita'])) {
                $nodo = Nodo::elemento('b', [], [$nodo]);
            }
            if (! empty($datos['color'])) {
                $nodo = Nodo::elemento('span', ['style' => 'color:'.$datos['color']], [$nodo]);
            }
            $salida[] = $nodo;
        }

        return $salida;
    }
}
