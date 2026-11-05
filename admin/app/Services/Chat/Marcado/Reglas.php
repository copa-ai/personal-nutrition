<?php

namespace App\Services\Chat\Marcado;

/**
 * Reglas de chat.md §5.4 (bloque html) y §5.5 (bloque keyboard): etiquetas y atributos permitidos, límites y el escapado.
 * Es la única fuente de esos datos: la usan los constructores (Widget, Teclado) y el validador.
 */
final class Reglas
{
    public const MAX_BLOQUE = 8000;

    public const MAX_TECLADOS_POR_MENSAJE = 2;

    public const MAX_TECLAS = 64;

    public const MAX_CAMPOS = 8;

    public const MAX_OPCIONES = 32;

    public const MAX_DI = 500;

    public const MAX_VALOR = 200;

    public const MAX_PLACEHOLDER = 80;

    public const MAX_ETIQUETA_OPCION = 80;

    public const MAX_PROFUNDIDAD = 10;

    public const PATRON_NOMBRE = '/^[A-Za-z0-9_-]{1,32}$/';

    /** Etiquetas de bloque: empiezan fila (chat.md §5.5, «Layout»). */
    public const BLOQUE = ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'table', 'tr', 'td', 'th', 'hr', 'pre'];

    public const EN_LINEA = ['b', 'strong', 'i', 'em', 'u', 's', 'a', 'span', 'code', 'br'];

    /** Solo en bloques keyboard. */
    public const FORMULARIO = ['button', 'input', 'select', 'option'];

    public const VACIAS = ['br', 'hr', 'input'];

    /** Se descartan con todo su contenido (nunca se «desenvuelven»). */
    public const SOLTAR_CON_CONTENIDO = [
        'script', 'style', 'iframe', 'object', 'embed', 'noscript', 'template', 'svg', 'math', 'head', 'title', 'textarea',
        'frame', 'frameset', 'applet', 'audio', 'video', 'canvas', 'img', 'link', 'meta', 'base', 'form',
    ];

    public const TIPOS_CAMPO = ['date', 'time', 'range', 'checkbox'];

    /** Propiedades de `style` que se conservan y sus valores seguros (sin url(), expression ni punto y coma). */
    public const ESTILOS = [
        'color', 'background-color', 'font-weight', 'font-style', 'font-size', 'text-align', 'text-decoration', 'border',
        'border-radius', 'padding', 'margin', 'width',
    ];

    public static function esBloque(string $etiqueta): bool
    {
        return in_array($etiqueta, self::BLOQUE, true);
    }

    /** @return list<string> */
    public static function permitidas(bool $teclado): array
    {
        return $teclado ? [...self::BLOQUE, ...self::EN_LINEA, ...self::FORMULARIO] : [...self::BLOQUE, ...self::EN_LINEA];
    }

    /** Texto de una sola línea: sin control, con los blancos (y saltos) reducidos a un espacio y sin espacios en los extremos. */
    public static function linea(string $texto, bool $recortar = true): string
    {
        $texto = (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', ' ', self::utf8($texto));
        $texto = (string) preg_replace('/\s+/u', ' ', $texto);

        return $recortar ? trim($texto) : $texto;
    }

    /** Texto con saltos de línea (para `pre`): sin controles salvo \n y tabulador. */
    public static function multilinea(string $texto): string
    {
        $texto = str_replace(["\r\n", "\r"], "\n", self::utf8($texto));

        return (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', ' ', $texto);
    }

    public static function utf8(string $texto): string
    {
        return mb_check_encoding($texto, 'UTF-8') ? $texto : mb_convert_encoding($texto, 'UTF-8', 'UTF-8');
    }

    /** Escapa para texto y atributos. La comilla invertida va como entidad: así ninguna línea del bloque puede cerrar la valla. */
    public static function esc(string $texto): string
    {
        return str_replace('`', '&#96;', htmlspecialchars($texto, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'));
    }

    public static function cortar(string $texto, int $max): string
    {
        return mb_strlen($texto) > $max ? rtrim(mb_substr($texto, 0, $max - 1)).'…' : $texto;
    }

    public static function fechaReal(string $valor): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $valor, $m) !== 1) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    public static function horaReal(string $valor): bool
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $valor) === 1;
    }

    public static function numero(string $valor): bool
    {
        return preg_match('/^-?\d+(\.\d+)?$/', $valor) === 1 && is_finite((float) $valor);
    }

    /** Propiedades de estilo seguras de un atributo `style`, como «a:b;c:d», o null si no queda ninguna. */
    public static function estilo(string $css): ?string
    {
        $salida = [];
        foreach (explode(';', $css) as $declaracion) {
            [$propiedad, $valor] = array_pad(explode(':', $declaracion, 2), 2, '');
            $propiedad = strtolower(trim($propiedad));
            $valor = self::linea($valor);
            if (in_array($propiedad, self::ESTILOS, true) && $valor !== '' && mb_strlen($valor) <= 60
                && preg_match('/^[#%(),.\w\s-]+$/u', $valor) === 1 && preg_match('/url|expression|javascript|@import/i', $valor) !== 1) {
                $salida[$propiedad] = $valor;
            }
        }

        return $salida === [] ? null : implode(';', array_map(fn (string $p, string $v): string => "{$p}:{$v}", array_keys($salida), $salida));
    }

    /** Un color para `style`: #rgb, #rrggbb o #rrggbbaa. */
    public static function color(string $color): bool
    {
        return preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $color) === 1;
    }

    /** Solo enlaces http(s) y mailto. */
    public static function enlaceSeguro(string $url): bool
    {
        return preg_match('~^(https?://|mailto:)[^\s<>"\']+$~i', $url) === 1;
    }
}
