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

    /**
     * Operación inversa de codificar(). La contraseña es lo que queda tras el segundo «|», así que puede contenerlo.
     *
     * @return array{app_key: string, cluster: string, clave_bd: string}|null null si la cadena no es válida
     */
    public static function decodificar(string $red): ?array
    {
        $red = trim($red);

        if ($red === '' || preg_match('/^[A-Za-z0-9_-]+$/', $red) !== 1) {
            return null;
        }

        $base64 = strtr($red, '-_', '+/');
        $base64 .= str_repeat('=', (4 - strlen($base64) % 4) % 4);
        $bruto = base64_decode($base64, true);

        if ($bruto === false) {
            return null;
        }

        $partes = explode('|', $bruto, 3);

        if (count($partes) !== 3 || $partes[0] === '' || $partes[1] === '' || $partes[2] === '') {
            return null;
        }

        return ['app_key' => $partes[0], 'cluster' => $partes[1], 'clave_bd' => $partes[2]];
    }
}
