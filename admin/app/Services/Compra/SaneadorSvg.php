<?php

namespace App\Services\Compra;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Sanea el SVG que dibuja el agente. No se «limpia» el SVG: se LEE con una lista cerrada de etiquetas (svg, g, rect, circle, ellipse,
 * line, polygon) y atributos (posicion, tamano, fill, stroke, stroke-width, opacity) y se devuelve solo un modelo de formas, que es
 * lo que dibuja el Rasterizador. Todo lo demas (script, foreignObject, image, use, style, href, on*, transform, DOCTYPE o ENTIDADES)
 * o bien se rechaza (etiqueta desconocida, DOCTYPE) o bien no llega al modelo (atributos desconocidos).
 *
 * @phpstan-type Forma array{t: string, x?: float, y?: float, w?: float, h?: float, rx?: float, cx?: float, cy?: float, rxe?: float, rye?: float, x1?: float, y1?: float, x2?: float, y2?: float, pts?: list<float>, fill: ?array{int, int, int}, stroke: ?array{int, int, int}, sw: float, op: float}
 */
final class SaneadorSvg
{
    private const ETIQUETAS = ['svg', 'g', 'rect', 'circle', 'ellipse', 'line', 'polygon'];

    private const COLORES = [
        'black' => '#000000', 'white' => '#ffffff', 'red' => '#d32f2f', 'green' => '#388e3c', 'blue' => '#1976d2', 'yellow' => '#fbc02d',
        'orange' => '#f57c00', 'purple' => '#7b1fa2', 'pink' => '#ec407a', 'brown' => '#6d4c41', 'gray' => '#9e9e9e', 'grey' => '#9e9e9e',
        'beige' => '#f5f5dc', 'gold' => '#ffd700', 'tan' => '#d2b48c', 'ivory' => '#fffff0',
    ];

    private int $formas = 0;

    /**
     * @return array{vb: array{float, float, float, float}, formas: list<array<string, mixed>>}|null null si no es un SVG aceptable
     */
    public static function sanear(string $svg, int $maxBytes, int $maxFormas): ?array
    {
        $svg = trim($svg);
        if ($svg === '' || strlen($svg) > $maxBytes || preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $svg) === 1) {
            return null;
        }
        $previo = libxml_use_internal_errors(true);
        try {
            $documento = new DOMDocument;
            if (! $documento->loadXML($svg, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR)) {
                return null;
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previo);
        }
        $raiz = $documento->documentElement;
        if (! $raiz instanceof DOMElement || $raiz->localName !== 'svg') {
            return null;
        }

        $vb = self::viewBox($raiz->getAttribute('viewBox'));
        $lector = new self;
        $formas = [];
        $estilo = ['fill' => [0, 0, 0], 'stroke' => null, 'sw' => 1.0, 'op' => 1.0];
        if (! $lector->recorrer($raiz, $estilo, $formas, $maxFormas)) {
            return null;
        }

        return $formas === [] ? null : ['vb' => $vb, 'formas' => $formas];
    }

    /** @return array{float, float, float, float} */
    private static function viewBox(string $texto): array
    {
        $n = preg_split('/[\s,]+/', trim($texto)) ?: [];
        if (count($n) === 4 && count(array_filter($n, fn (string $v): bool => is_numeric($v))) === 4) {
            [$x, $y, $w, $h] = array_map('floatval', $n);
            if ($w > 0 && $h > 0 && $w <= 10000 && $h <= 10000) {
                return [$x, $y, $w, $h];
            }
        }

        return [0.0, 0.0, 100.0, 100.0];
    }

    /**
     * @param  array{fill: ?array{int, int, int}, stroke: ?array{int, int, int}, sw: float, op: float}  $heredado
     * @param  list<array<string, mixed>>  $formas
     */
    private function recorrer(DOMElement $nodo, array $heredado, array &$formas, int $maxFormas): bool
    {
        $estilo = $this->estilo($nodo, $heredado);
        foreach ($nodo->childNodes as $hijo) {
            if (! $hijo instanceof DOMNode || $hijo->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }
            /** @var DOMElement $hijo */
            $nombre = (string) $hijo->localName;
            if (! in_array($nombre, self::ETIQUETAS, true) || $nombre === 'svg') {
                return false;
            }
            if ($nombre === 'g') {
                if (! $this->recorrer($hijo, $estilo, $formas, $maxFormas)) {
                    return false;
                }

                continue;
            }
            if (++$this->formas > $maxFormas) {
                return false;
            }
            $forma = $this->forma($hijo, $nombre, $this->estilo($hijo, $estilo));
            if ($forma === null) {
                return false;
            }
            $formas[] = $forma;
        }

        return true;
    }

    /**
     * @param  array{fill: ?array{int, int, int}, stroke: ?array{int, int, int}, sw: float, op: float}  $heredado
     * @return array{fill: ?array{int, int, int}, stroke: ?array{int, int, int}, sw: float, op: float}
     */
    private function estilo(DOMElement $nodo, array $heredado): array
    {
        $estilo = $heredado;
        if ($nodo->hasAttribute('fill')) {
            $estilo['fill'] = self::color($nodo->getAttribute('fill'));
        }
        if ($nodo->hasAttribute('stroke')) {
            $estilo['stroke'] = self::color($nodo->getAttribute('stroke'));
        }
        if ($nodo->hasAttribute('stroke-width')) {
            $estilo['sw'] = max(0.0, min(50.0, self::numero($nodo->getAttribute('stroke-width')) ?? $estilo['sw']));
        }
        foreach (['opacity', 'fill-opacity'] as $atributo) {
            if ($nodo->hasAttribute($atributo)) {
                $estilo['op'] *= max(0.0, min(1.0, self::numero($nodo->getAttribute($atributo)) ?? 1.0));
            }
        }

        return $estilo;
    }

    /**
     * @param  array{fill: ?array{int, int, int}, stroke: ?array{int, int, int}, sw: float, op: float}  $estilo
     * @return array<string, mixed>|null
     */
    private function forma(DOMElement $nodo, string $nombre, array $estilo): ?array
    {
        $n = fn (string $atributo, float $defecto = 0.0): float => self::numero($nodo->getAttribute($atributo)) ?? $defecto;
        $base = ['t' => $nombre, 'fill' => $estilo['fill'], 'stroke' => $estilo['stroke'], 'sw' => $estilo['sw'], 'op' => $estilo['op']];

        switch ($nombre) {
            case 'rect':
                $w = $n('width');
                $h = $n('height');
                if ($w <= 0 || $h <= 0) {
                    return null;
                }
                $rx = $nodo->hasAttribute('rx') ? $n('rx') : $n('ry');

                return $base + ['x' => $n('x'), 'y' => $n('y'), 'w' => $w, 'h' => $h, 'rx' => max(0.0, min($rx, $w / 2, $h / 2))];
            case 'circle':
                $r = $n('r');

                return $r <= 0 ? null : $base + ['cx' => $n('cx'), 'cy' => $n('cy'), 'rxe' => $r, 'rye' => $r];
            case 'ellipse':
                $rx = $n('rx');
                $ry = $n('ry');

                return $rx <= 0 || $ry <= 0 ? null : $base + ['cx' => $n('cx'), 'cy' => $n('cy'), 'rxe' => $rx, 'rye' => $ry];
            case 'line':
                return $base + ['x1' => $n('x1'), 'y1' => $n('y1'), 'x2' => $n('x2'), 'y2' => $n('y2')];
            default:
                $numeros = preg_split('/[\s,]+/', trim($nodo->getAttribute('points'))) ?: [];
                if (count($numeros) < 6 || count($numeros) % 2 !== 0 || count($numeros) > 200
                    || count(array_filter($numeros, fn (string $v): bool => self::numero($v) !== null)) !== count($numeros)) {
                    return null;
                }

                return $base + ['pts' => array_map(fn (string $v): float => (float) self::numero($v), $numeros)];
        }
    }

    private static function numero(string $valor): ?float
    {
        $valor = trim($valor);
        if (preg_match('/^-?(\d+(\.\d*)?|\.\d+)(px)?$/', $valor) !== 1) {
            return null;
        }
        $f = (float) $valor;

        return abs($f) > 100000 ? null : $f;
    }

    /** @return ?array{int, int, int} null = sin color (none, o un valor que no se admite) */
    private static function color(string $valor): ?array
    {
        $valor = strtolower(trim($valor));
        $valor = self::COLORES[$valor] ?? $valor;
        if (preg_match('/^#([0-9a-f]{3})$/', $valor, $m) === 1) {
            $valor = '#'.$m[1][0].$m[1][0].$m[1][1].$m[1][1].$m[1][2].$m[1][2];
        }
        if (preg_match('/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/', $valor, $m) !== 1) {
            return null;
        }

        return [(int) hexdec($m[1]), (int) hexdec($m[2]), (int) hexdec($m[3])];
    }
}
