<?php

namespace App\Services\Compra;

/**
 * Dibuja con GD el modelo de formas del SaneadorSvg y devuelve un PNG cuadrado (cards.md §5.2 solo admite image/jpeg e image/png: un
 * SVG no se puede enviar). Se dibuja al doble de tamano y se reduce, para suavizar los bordes. Sin la extension gd devuelve null.
 */
final class Rasterizador
{
    private const SEGMENTOS = 48;

    /**
     * @param  array{vb: array{float, float, float, float}, formas: list<array<string, mixed>>}  $modelo
     * @param  array{int, int, int}  $fondo
     */
    public static function png(array $modelo, int $lado, array $fondo = [245, 241, 232]): ?string
    {
        if (! function_exists('imagecreatetruecolor') || $lado < 16 || $lado > 1024) {
            return null;
        }
        $grande = $lado * 2;
        [$vx, $vy, $vw, $vh] = $modelo['vb'];
        $escala = $grande / max($vw, $vh);

        $lienzo = imagecreatetruecolor($grande, $grande);
        if ($lienzo === false) {
            return null;
        }
        imagealphablending($lienzo, true);
        imagefilledrectangle($lienzo, 0, 0, $grande, $grande, (int) imagecolorallocate($lienzo, ...$fondo));

        $px = fn (float $x): float => ($x - $vx) * $escala;
        $py = fn (float $y): float => ($y - $vy) * $escala;
        foreach ($modelo['formas'] as $f) {
            $alfa = 127 - (int) round(127 * max(0.0, min(1.0, (float) $f['op'])));
            $relleno = $f['fill'] === null ? null : (int) imagecolorallocatealpha($lienzo, $f['fill'][0], $f['fill'][1], $f['fill'][2], $alfa);
            $trazo = $f['stroke'] === null || $f['sw'] <= 0 ? null : (int) imagecolorallocatealpha($lienzo, $f['stroke'][0], $f['stroke'][1], $f['stroke'][2], $alfa);
            imagesetthickness($lienzo, max(1, (int) round($f['sw'] * $escala)));

            if ($f['t'] === 'line') {
                if ($trazo !== null) {
                    imageline($lienzo, (int) $px($f['x1']), (int) $py($f['y1']), (int) $px($f['x2']), (int) $py($f['y2']), $trazo);
                }

                continue;
            }
            $puntos = [];
            foreach (self::contorno($f) as [$x, $y]) {
                $puntos[] = (int) round($px($x));
                $puntos[] = (int) round($py($y));
            }
            if (count($puntos) < 6) {
                continue;
            }
            if ($relleno !== null) {
                imagefilledpolygon($lienzo, $puntos, $relleno);
            }
            if ($trazo !== null) {
                imagepolygon($lienzo, $puntos, $trazo);
            }
        }

        $final = imagecreatetruecolor($lado, $lado);
        if ($final === false) {
            return null;
        }
        imagecopyresampled($final, $lienzo, 0, 0, 0, 0, $lado, $lado, $grande, $grande);
        ob_start();
        $ok = imagepng($final, null, 6);
        $bytes = (string) ob_get_clean();

        return $ok && $bytes !== '' ? $bytes : null;
    }

    /**
     * Contorno de una forma como lista de puntos.
     *
     * @param  array<string, mixed>  $f
     * @return list<array{float, float}>
     */
    private static function contorno(array $f): array
    {
        switch ($f['t']) {
            case 'rect':
                [$x, $y, $w, $h, $r] = [$f['x'], $f['y'], $f['w'], $f['h'], $f['rx']];
                if ($r <= 0) {
                    return [[$x, $y], [$x + $w, $y], [$x + $w, $y + $h], [$x, $y + $h]];
                }
                $puntos = [];
                foreach ([[$x + $w - $r, $y + $r, -90], [$x + $w - $r, $y + $h - $r, 0], [$x + $r, $y + $h - $r, 90], [$x + $r, $y + $r, 180]] as [$cx, $cy, $inicio]) {
                    for ($i = 0; $i <= 6; $i++) {
                        $a = deg2rad($inicio + 90 * $i / 6);
                        $puntos[] = [$cx + $r * cos($a), $cy + $r * sin($a)];
                    }
                }

                return $puntos;
            case 'circle':
            case 'ellipse':
                $puntos = [];
                for ($i = 0; $i < self::SEGMENTOS; $i++) {
                    $a = 2 * M_PI * $i / self::SEGMENTOS;
                    $puntos[] = [$f['cx'] + $f['rxe'] * cos($a), $f['cy'] + $f['rye'] * sin($a)];
                }

                return $puntos;
            default:
                $puntos = [];
                for ($i = 0; $i + 1 < count($f['pts']); $i += 2) {
                    $puntos[] = [$f['pts'][$i], $f['pts'][$i + 1]];
                }

                return $puntos;
        }
    }
}
