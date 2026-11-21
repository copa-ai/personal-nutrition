<?php

namespace App\Services\Coder;

/**
 * Read the shared directories that a service declares as a guest.
 *
 * Two cases:
 *  - A package (`paquete`, a .celaut.bee file that the user uploaded): the class scans the file for the `share` policy of a directory.
 *    A packed service keeps the policy as plain text in the file: the formal of the setting ends with `setting=share`, then comes
 *    the value (`access=rw`, `role=guest`, `tag=project`, one `key=value` line each, sorted by key). See docs/SHARED_FILESYSTEMS.md of nodo.
 *    The scan is not a full parse of the protobuf stream, so a file of the image that holds the same text can give a false guest share.
 *    This is safe: the node alone decides what a Coder mounts, and it refuses a launch if the parent does not export the share.
 *  - A service id (`hash`): the node holds the spec, and the celaut CLI v0.6.3 has no command to read it. The result is null (unknown).
 *
 * @see docs/coder/decisions.md entries 28 and 88
 */
class LectorEspecificacion
{
    /** Bytes that one read takes from the file. */
    private const TROZO = 1048576;

    /** Bytes that the scan keeps between two reads. It is larger than the longest policy match. */
    private const SOLAPE = 1024;

    /**
     * `setting=share`, then the bytes of the protobuf field (its tag and its length), then the value of the policy.
     * The keys of the value are sorted: access, env, network, role, tag. Only `role` is required.
     */
    private const POLITICA = '/setting=share.{1,3}?(?:access=(?:ro|rw)\n)?(?:env=[A-Za-z_][A-Za-z0-9_]{0,63}\n)?(?:network=[A-Za-z0-9._@:+-]{1,64}\n)?role=(guest|shared|network)(?:\ntag=([A-Za-z0-9][A-Za-z0-9._@:+-]{0,127}))?/s';

    /**
     * @param  string  $tipo  "hash" or "paquete"
     * @param  string  $referencia  the service id, or the path of the .celaut.bee file
     * @return list<string>|null the tags of the guest shares of the service, or null if the spec cannot be read
     */
    public function tagsInvitado(string $tipo, string $referencia): ?array
    {
        if ($tipo !== 'paquete') {
            return null;
        }

        return $this->tagsDePaquete($referencia);
    }

    /**
     * Scan a package file. A guest share with no tag is named by its path: it has no tag to report, so it is not in the list.
     *
     * @param  int  $trozo  bytes for each read (a test sets a small value to cross the limit of two reads)
     * @return list<string>|null the tags of the guest shares (an empty list if there are none), or null if the file cannot be read
     */
    public function tagsDePaquete(string $ruta, int $trozo = self::TROZO): ?array
    {
        $archivo = @fopen($ruta, 'rb');
        if ($archivo === false) {
            return null;
        }

        $tags = [];
        $resto = '';
        try {
            while (true) {
                $datos = fread($archivo, max(1, $trozo));
                if ($datos === false) {
                    return null;
                }
                $fin = $datos === '' || feof($archivo);
                $buffer = $resto.$datos;
                $limite = $fin ? strlen($buffer) : strlen($buffer) - self::SOLAPE;

                // A match that starts in the last SOLAPE bytes can be cut by the end of the buffer. The next read finds it again.
                if (preg_match_all(self::POLITICA, $buffer, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
                    foreach ($m as $hallazgo) {
                        if ($hallazgo[0][1] < $limite && $hallazgo[1][0] === 'guest' && isset($hallazgo[2]) && $hallazgo[2][0] !== '') {
                            $tags[$hallazgo[2][0]] = true;
                        }
                    }
                }
                if ($fin) {
                    break;
                }
                $resto = substr($buffer, -self::SOLAPE);
            }
        } finally {
            fclose($archivo);
        }

        return array_map('strval', array_keys($tags));
    }
}
