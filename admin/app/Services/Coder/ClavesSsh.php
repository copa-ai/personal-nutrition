<?php

namespace App\Services\Coder;

/**
 * Strict check of a list of SSH public keys (the text of the panel, one key per line).
 * Only four key types pass. A line with key options (command=, from=, ...) fails. The check is done on the whole line.
 * This class never writes a file. The Coder service writes the result.
 */
class ClavesSsh
{
    private const TIPOS = ['ssh-ed25519', 'ecdsa-sha2-nistp256', 'ecdsa-sha2-nistp384', 'ecdsa-sha2-nistp521', 'ssh-rsa'];

    /**
     * @return array{claves: list<string>, errores: list<string>} the clean lines, and one Spanish message for each bad line
     */
    public static function validar(string $texto, ?int $maximo = null): array
    {
        $maximo ??= (int) config('coder.claves.maximo');
        $errores = [];

        // A browser textarea sends CRLF. A single CR in a line is not accepted, and neither is any other control character.
        $lineas = explode("\n", str_replace("\r\n", "\n", $texto));
        $claves = [];
        foreach ($lineas as $n => $linea) {
            $linea = trim($linea, " \t");
            if ($linea === '') {
                continue;
            }
            $limpia = static::limpiar($linea);
            if (is_string($limpia)) {
                $claves[$limpia] = $limpia;   // the same key twice counts once

                continue;
            }
            $errores[] = 'Línea '.($n + 1).': '.$limpia[0];
        }

        $claves = array_values($claves);
        if (count($claves) > $maximo) {
            $errores[] = "Hay {$maximo} claves como máximo (se han puesto ".count($claves).').';
        }

        return ['claves' => $errores === [] ? $claves : [], 'errores' => $errores];
    }

    /**
     * The normal form of one line ("type base64 comment"), or an array with one error message.
     *
     * @return string|array{0: string}
     */
    private static function limpiar(string $linea): string|array
    {
        if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $linea) === 1 || preg_match('/^[\x09\x20-\x7E]+$/D', $linea) !== 1) {
            return ['solo se admiten caracteres ASCII visibles, sin saltos de línea ni caracteres de control.'];
        }
        $comentarioMax = (int) config('coder.claves.comentario_maximo');
        $patron = '/^(?<tipo>[a-z0-9-]+) (?<datos>[A-Za-z0-9+\/]{16,}={0,2})(?: (?<comentario>[A-Za-z0-9@._:+= -]{1,'.$comentarioMax.'}))?$/D';
        if (preg_match($patron, $linea, $m) !== 1) {
            return ['formato no válido. Usa «tipo clave comentario», sin opciones delante (command=, from=...).'];
        }
        if (! in_array($m['tipo'], self::TIPOS, true)) {
            return ['tipo de clave no admitido ('.$m['tipo'].'). Se admiten ssh-ed25519, ecdsa-sha2-nistp256/384/521 y ssh-rsa.'];
        }
        $blob = base64_decode($m['datos'], true);
        if ($blob === false || ! static::blobCoincide($blob, $m['tipo'])) {
            return ['la clave está dañada o su tipo no coincide con el de la línea.'];
        }

        return $m['tipo'].' '.$m['datos'].(isset($m['comentario']) && $m['comentario'] !== '' ? ' '.rtrim($m['comentario']) : '');
    }

    /** The blob starts with the key type as an SSH "string", and it holds at least one more field. */
    private static function blobCoincide(string $blob, string $tipo): bool
    {
        if (strlen($blob) < 4 + strlen($tipo) + 4) {
            return false;
        }
        $largo = unpack('N', substr($blob, 0, 4))[1];

        return $largo === strlen($tipo) && substr($blob, 4, $largo) === $tipo;
    }
}
