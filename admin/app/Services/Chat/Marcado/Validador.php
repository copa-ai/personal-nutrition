<?php

namespace App\Services\Chat\Marcado;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Sanea y valida los bloques ```html y ```keyboard de un texto (chat.md §5.4 y §5.5). Sirve para lo que escribe un modelo:
 *
 *   $seguro = Validador::sanear($textoDeClaude);
 *
 * Cada bloque se lee, se limpia (se quitan etiquetas, atributos y estilos que no están permitidos, scripts incluidos) y se vuelve
 * a escribir en su forma canónica. Si incumple una regla que no se puede arreglar quitando cosas (más de 8000 caracteres, más de 64
 * teclas o 8 campos, un campo sin nombre válido o repetido, un {marcador} sin campo, un data-say de más de 500 caracteres, más de
 * 32 opciones, un bloque sin cerrar, el tercer bloque keyboard...), el bloque se DEGRADA a código (```text) con su contenido
 * original: la persona lo ve como código y nada se ejecuta ni se ofrece como tecla. El texto fuera de los bloques no se toca.
 */
final class Validador
{
    /** Texto con los bloques saneados o degradados. */
    public static function sanear(string $texto): string
    {
        $errores = [];

        return self::procesar($texto, $errores);
    }

    /**
     * Como sanear(), para un trozo de un mensaje que se emite por partes (el streaming de Claude): si el trozo deja abierta una valla
     * de código ajena (```text, ```json...) se cierra, para que la valla de un trozo no «se coma» el trozo siguiente y deje un bloque
     * ```html de este sin sanear dentro de un bloque de código que el cliente cierra con la valla del anterior.
     */
    public static function saneaTrozo(string $texto): string
    {
        $errores = [];

        return self::procesar($texto, $errores, true);
    }

    /** Motivos por los que se degradaron bloques de este texto (vacío si todo estaba bien). @return list<string> */
    public static function errores(string $texto): array
    {
        $errores = [];
        self::procesar($texto, $errores);

        return $errores;
    }

    /** El texto no necesita cambios: todos sus bloques ya están en forma canónica y son válidos. */
    public static function esValido(string $texto): bool
    {
        $errores = [];

        return self::procesar($texto, $errores) === str_replace("\r\n", "\n", $texto) && $errores === [];
    }

    /**
     * Contenido canónico de UN bloque ('html' o 'keyboard'), sin las vallas.
     *
     * @throws MarcadoInvalido si el bloque incumple una regla
     */
    public static function bloque(string $tipo, string $contenido): string
    {
        return (new self)->canonico($tipo === 'keyboard', $contenido);
    }

    /** @param  list<string>  $errores */
    private static function procesar(string $texto, array &$errores, bool $cerrarAbiertas = false): string
    {
        $lineas = explode("\n", str_replace("\r\n", "\n", Reglas::utf8($texto)));
        $salida = [];
        $teclados = 0;
        $n = count($lineas);
        for ($i = 0; $i < $n; $i++) {
            if (preg_match('/^ {0,3}(`{3,})([^`]*)$/', $lineas[$i], $m) !== 1) {
                $salida[] = $lineas[$i];

                continue;
            }
            $valla = $m[1];
            $tipo = strtolower(trim($m[2]));
            $cierre = $i + 1;
            while ($cierre < $n && preg_match('/^ {0,3}`{'.strlen($valla).',}\s*$/', $lineas[$cierre]) !== 1) {
                $cierre++;
            }
            $cerrado = $cierre < $n;
            $interior = array_slice($lineas, $i + 1, $cierre - $i - 1);

            if ($tipo !== 'html' && $tipo !== 'keyboard') {
                array_push($salida, ...array_slice($lineas, $i, ($cerrado ? $cierre : $n - 1) - $i + 1));
                if (! $cerrado && $cerrarAbiertas) {
                    $salida[] = $valla;
                }
                $i = $cerrado ? $cierre : $n;

                continue;
            }

            try {
                if (! $cerrado) {
                    throw new MarcadoInvalido('El bloque '.$tipo.' no está cerrado.');
                }
                if ($tipo === 'keyboard' && ++$teclados > Reglas::MAX_TECLADOS_POR_MENSAJE) {
                    throw new MarcadoInvalido('Hay más de '.Reglas::MAX_TECLADOS_POR_MENSAJE.' bloques keyboard en el mensaje.');
                }
                $limpio = self::bloque($tipo, implode("\n", $interior));
                $salida[] = $valla.$tipo;
                $salida[] = $limpio;
                $salida[] = $valla;
            } catch (MarcadoInvalido $e) {
                $errores[] = $e->getMessage();
                $salida[] = $valla.'text';
                array_push($salida, ...$interior);
                $salida[] = $valla;
            }
            $i = $cerrado ? $cierre : $n;
        }

        return implode("\n", $salida);
    }

    // ------------------------------------------------------------------ lectura de un bloque

    private int $teclas = 0;

    /** @var array<string, true> */
    private array $campos = [];

    /** @var list<string> */
    private array $dis = [];

    private function canonico(bool $teclado, string $html): string
    {
        $this->teclas = 0;
        $this->campos = [];
        $this->dis = [];
        $tipo = $teclado ? 'keyboard' : 'html';
        if (mb_strlen($html) > Reglas::MAX_BLOQUE * 4) {
            throw new MarcadoInvalido("El bloque {$tipo} es demasiado largo (máximo ".Reglas::MAX_BLOQUE.' caracteres).');
        }

        $doc = new DOMDocument;
        $anterior = libxml_use_internal_errors(true);
        try {
            $doc->loadHTML('<?xml encoding="utf-8"?><html><body><div id="raiz">'.Reglas::utf8($html).'</div></body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($anterior);
        }
        $raiz = $doc->getElementById('raiz');
        $nodos = $raiz ? $this->hijos($raiz, $teclado, 0) : [];

        foreach ($this->dis as $di) {
            if (preg_match_all('/\{([A-Za-z0-9_-]{1,32})\}/', $di, $m) > 0) {
                foreach ($m[1] as $nombre) {
                    if (! isset($this->campos[$nombre])) {
                        throw new MarcadoInvalido("El data-say «{$di}» usa {{$nombre}}, pero no hay un campo con ese data-name.");
                    }
                }
            }
        }
        if ($teclado && $this->teclas === 0) {
            throw new MarcadoInvalido('El bloque keyboard no tiene ninguna tecla (un botón con data-say).');
        }
        $contenido = Nodo::serializar($nodos);
        if ($contenido === '') {
            throw new MarcadoInvalido("El bloque {$tipo} está vacío o no queda nada permitido.");
        }
        if (mb_strlen($contenido) > Reglas::MAX_BLOQUE) {
            throw new MarcadoInvalido("El bloque {$tipo} tiene ".mb_strlen($contenido).' caracteres (máximo '.Reglas::MAX_BLOQUE.').');
        }

        return $contenido;
    }

    /** @return list<Nodo> */
    private function hijos(DOMNode $padre, bool $teclado, int $profundidad): array
    {
        $salida = [];
        foreach (iterator_to_array($padre->childNodes) as $hijo) {
            if ($hijo instanceof DOMText) {
                $texto = Reglas::utf8($hijo->wholeText ?? $hijo->data);
                if (trim($texto) === '' && str_contains($texto, "\n")) {
                    continue;    // sangría entre elementos de bloque
                }
                if ($texto !== '') {
                    $salida[] = Nodo::texto($texto);
                }
            } elseif ($hijo instanceof DOMElement) {
                array_push($salida, ...$this->elemento($hijo, $teclado, $profundidad));
            }
        }

        return $salida;
    }

    /** @return list<Nodo> */
    private function elemento(DOMElement $e, bool $teclado, int $profundidad): array
    {
        $etiqueta = strtolower($e->tagName);
        if (in_array($etiqueta, Reglas::SOLTAR_CON_CONTENIDO, true) || (! $teclado && in_array($etiqueta, ['select', 'option'], true))) {
            return [];
        }
        if (! in_array($etiqueta, Reglas::permitidas($teclado), true) || $profundidad >= Reglas::MAX_PROFUNDIDAD || $etiqueta === 'option') {
            return $this->hijos($e, $teclado, $profundidad);   // se desenvuelve: queda su contenido
        }

        return match ($etiqueta) {
            'button' => $this->boton($e, $teclado, $profundidad),
            'input' => $this->entrada($e),
            'select' => $this->seleccion($e),
            default => [Nodo::elemento($etiqueta, $this->atributosComunes($e, $etiqueta), in_array($etiqueta, Reglas::VACIAS, true) ? [] : $this->hijos($e, $teclado, $profundidad + 1))],
        };
    }

    /** @return array<string, string|true> */
    private function atributosComunes(DOMElement $e, string $etiqueta): array
    {
        $atributos = [];
        if ($e->hasAttribute('style') && ($estilo = Reglas::estilo($e->getAttribute('style'))) !== null) {
            $atributos['style'] = $estilo;
        }
        if (in_array($etiqueta, ['td', 'th'], true) && preg_match('/^\d{1,2}$/', trim($e->getAttribute('colspan'))) === 1 && (int) $e->getAttribute('colspan') >= 1) {
            $atributos['colspan'] = (string) (int) $e->getAttribute('colspan');
        }
        if ($etiqueta === 'a' && Reglas::enlaceSeguro(trim($e->getAttribute('href')))) {
            $atributos['href'] = trim($e->getAttribute('href'));
        }

        return $atributos;
    }

    /** @return list<Nodo> */
    private function boton(DOMElement $e, bool $teclado, int $profundidad): array
    {
        $di = Reglas::linea($e->getAttribute('data-say'));
        if ($di === '') {
            return $this->hijos($e, $teclado, $profundidad);   // sin data-say no es una tecla: se queda su texto
        }
        if (mb_strlen($di) > Reglas::MAX_DI) {
            throw new MarcadoInvalido('Un data-say tiene '.mb_strlen($di).' caracteres (máximo '.Reglas::MAX_DI.').');
        }
        if (++$this->teclas > Reglas::MAX_TECLAS) {
            throw new MarcadoInvalido('Hay más de '.Reglas::MAX_TECLAS.' teclas en el bloque.');
        }
        $this->dis[] = $di;
        $atributos = ['data-say' => $di];
        if ($e->hasAttribute('data-send')) {
            $atributos['data-send'] = true;
        }
        $etiqueta = Reglas::linea($e->textContent);

        return [Nodo::elemento('button', $atributos, $etiqueta === '' ? [] : [Nodo::texto($etiqueta)])];
    }

    private function nombreDeCampo(DOMElement $e): string
    {
        $nombre = trim($e->getAttribute('data-name'));
        if (preg_match(Reglas::PATRON_NOMBRE, $nombre) !== 1) {
            throw new MarcadoInvalido('Un campo ('.strtolower($e->tagName).') no tiene un data-name válido (1 a 32 caracteres de A-Z, a-z, 0-9, _ y -).');
        }
        if (isset($this->campos[$nombre])) {
            throw new MarcadoInvalido("El campo «{$nombre}» está repetido.");
        }
        if (count($this->campos) >= Reglas::MAX_CAMPOS) {
            throw new MarcadoInvalido('Hay más de '.Reglas::MAX_CAMPOS.' campos en el bloque.');
        }
        $this->campos[$nombre] = true;

        return $nombre;
    }

    /** @return list<Nodo> */
    private function entrada(DOMElement $e): array
    {
        $nombre = $this->nombreDeCampo($e);
        $tipo = strtolower(trim($e->getAttribute('type')));
        $a = [];
        $texto = fn (string $clave): string => Reglas::linea($e->getAttribute($clave));
        $conservar = function (string $clave, callable $valido) use ($e, &$a, $texto): void {
            if ($e->hasAttribute($clave) && $valido($texto($clave))) {
                $a[$clave] = $texto($clave);
            }
        };
        $valorLibre = fn (string $v): bool => $v !== '' && mb_strlen($v) <= Reglas::MAX_VALOR;

        if (in_array($tipo, Reglas::TIPOS_CAMPO, true)) {
            $a['type'] = $tipo;
        }
        $a['data-name'] = $nombre;
        if ($e->hasAttribute('placeholder')) {
            $a['placeholder'] = Reglas::cortar($texto('placeholder'), Reglas::MAX_PLACEHOLDER);
        }
        switch ($tipo) {
            case 'date':
                $conservar('min', Reglas::fechaReal(...));
                $conservar('max', Reglas::fechaReal(...));
                if (isset($a['min'], $a['max']) && $a['min'] > $a['max']) {
                    unset($a['min'], $a['max']);
                }
                $conservar('value', Reglas::fechaReal(...));
                break;
            case 'time':
                $conservar('value', Reglas::horaReal(...));
                break;
            case 'range':
                foreach (['min', 'max', 'step', 'value'] as $clave) {
                    $conservar($clave, Reglas::numero(...));
                }
                break;
            case 'checkbox':
                $conservar('value', $valorLibre);
                $conservar('data-off', $valorLibre);
                if ($e->hasAttribute('checked')) {
                    $a['checked'] = true;
                }
                break;
            default:
                $conservar('value', $valorLibre);
        }

        return [Nodo::elemento('input', $a)];
    }

    /** @return list<Nodo> */
    private function seleccion(DOMElement $e): array
    {
        $nombre = $this->nombreDeCampo($e);
        $multiple = $e->hasAttribute('multiple');
        $opciones = [];
        $vistos = [];
        $hayElegida = false;
        foreach ($e->getElementsByTagName('option') as $o) {
            $texto = Reglas::linea($o->textContent);
            $valor = $o->hasAttribute('value') ? Reglas::linea($o->getAttribute('value')) : $texto;
            if ($valor === '' || mb_strlen($valor) > Reglas::MAX_VALOR || isset($vistos[$valor])) {
                continue;     // «se pierde» (chat.md §5.5)
            }
            $vistos[$valor] = true;
            if (count($opciones) >= Reglas::MAX_OPCIONES) {
                throw new MarcadoInvalido("El select «{$nombre}» tiene más de ".Reglas::MAX_OPCIONES.' opciones.');
            }
            $atributos = ['value' => $valor];
            if ($o->hasAttribute('selected') && ($multiple || ! $hayElegida)) {
                $atributos['selected'] = true;
                $hayElegida = true;
            }
            $opciones[] = Nodo::elemento('option', $atributos, [Reglas::cortar($texto !== '' ? $texto : $valor, Reglas::MAX_ETIQUETA_OPCION)]);
        }
        $a = ['data-name' => $nombre];
        if ($multiple) {
            $a['multiple'] = true;
        }
        if ($e->hasAttribute('placeholder')) {
            $a['placeholder'] = Reglas::cortar(Reglas::linea($e->getAttribute('placeholder')), Reglas::MAX_PLACEHOLDER);
        }

        return [Nodo::elemento('select', $a, $opciones)];
    }
}
