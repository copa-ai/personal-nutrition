<?php

namespace App\Services\Coder;

/**
 * Read the shared directories that a service declares as a guest.
 *
 * The celaut CLI v0.6.3 has no command to read a spec (docs/coder/decisions.md). This class returns null for that reason:
 * the spec is unknown. A reader that can parse a .bee file or call Gateway.GetService replaces it by extending this class.
 */
class LectorEspecificacion
{
    /**
     * @param  string  $tipo  "hash" or "paquete"
     * @param  string  $referencia  the service id, or the path of the .celaut.bee file
     * @return list<string>|null the tags of the guest shares of the service, or null if the spec cannot be read
     */
    public function tagsInvitado(string $tipo, string $referencia): ?array
    {
        return null;
    }
}
