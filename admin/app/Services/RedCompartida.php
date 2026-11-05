<?php

namespace App\Services;

/**
 * La variable corta NUTRITION_RED: APP_KEY, clave de red y contraseña de la base en una sola cadena (base64url de
 * «APP_KEY|cluster|clave_bd», sin relleno). Aquí solo se codifica; la decodifica docker/servicio/start.sh con herramientas
 * de shell, antes de que arranque PHP, así que el formato debe seguir siendo ese.
 */
class RedCompartida
{
    public static function codificar(string $appKey, string $cluster, string $claveBd): string
    {
        return rtrim(strtr(base64_encode($appKey.'|'.$cluster.'|'.$claveBd), '+/', '-_'), '=');
    }
}
