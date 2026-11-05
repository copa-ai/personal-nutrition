<?php

namespace App\Services\Chat\Marcado;

/**
 * Un nodo del árbol de un bloque: texto, o elemento con atributos (valor true = atributo booleano, sin valor) e hijos. Todo el marcado
 * (el de los constructores y el que sanea el validador) pasa por aquí y se serializa de una sola forma, así que un bloque canónico
 * vuelve a ser el mismo al sanearlo.
 */
final class Nodo
{
    /**
     * @param  array<string, string|true>  $atributos
     * @param  list<Nodo>  $hijos
     */
    private function __construct(
        public readonly ?string $etiqueta,
        public readonly array $atributos = [],
        public readonly array $hijos = [],
        public readonly string $texto = '',
    ) {}

    public static function texto(string $texto): self
    {
        return new self(null, texto: $texto);
    }

    /**
     * @param  array<string, string|true>  $atributos
     * @param  list<Nodo|string>  $hijos  los strings son texto
     */
    public static function elemento(string $etiqueta, array $atributos = [], array $hijos = []): self
    {
        return new self($etiqueta, $atributos, array_map(fn (Nodo|string $h): Nodo => is_string($h) ? self::texto($h) : $h, $hijos));
    }

    public function esTexto(): bool
    {
        return $this->etiqueta === null;
    }

    /** @param  list<Nodo>  $nodos */
    public static function serializar(array $nodos): string
    {
        $salida = '';
        self::escribir($nodos, $salida, false);

        return trim($salida);
    }

    /** @param  list<Nodo>  $nodos */
    private static function escribir(array $nodos, string &$salida, bool $pre): void
    {
        $nodos = array_values($nodos);
        foreach ($nodos as $i => $nodo) {
            if ($nodo->esTexto()) {
                $texto = $pre ? Reglas::multilinea($nodo->texto) : self::colapsar($nodo->texto);
                if (! $pre) {
                    // Los blancos junto a un elemento de bloque (o en los extremos) no se ven: no se escriben.
                    if ($i === 0 || ($nodos[$i - 1]->etiqueta !== null && Reglas::esBloque($nodos[$i - 1]->etiqueta))) {
                        $texto = ltrim($texto);
                    }
                    if ($i === count($nodos) - 1 || ($nodos[$i + 1]->etiqueta !== null && Reglas::esBloque($nodos[$i + 1]->etiqueta))) {
                        $texto = rtrim($texto);
                    }
                }
                $salida .= Reglas::esc($texto);

                continue;
            }
            $etiqueta = (string) $nodo->etiqueta;
            $bloque = Reglas::esBloque($etiqueta);
            if ($bloque && $salida !== '' && ! str_ends_with($salida, "\n")) {
                $salida .= "\n";
            }
            $salida .= '<'.$etiqueta;
            foreach ($nodo->atributos as $nombre => $valor) {
                $salida .= $valor === true ? ' '.$nombre : ' '.$nombre.'="'.Reglas::esc(Reglas::linea($valor)).'"';
            }
            $salida .= '>';
            if (! in_array($etiqueta, Reglas::VACIAS, true)) {
                self::escribir($nodo->hijos, $salida, $pre || $etiqueta === 'pre');
                $salida .= '</'.$etiqueta.'>';
            }
            if ($bloque) {
                $salida .= "\n";
            }
        }
    }

    /** Blancos a un espacio (el espacio puede ser significativo entre elementos en línea). */
    private static function colapsar(string $texto): string
    {
        return (string) preg_replace('/\s+/u', ' ', Reglas::multilinea($texto));
    }
}
