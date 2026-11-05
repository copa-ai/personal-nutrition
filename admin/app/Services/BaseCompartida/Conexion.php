<?php

namespace App\Services\BaseCompartida;

use PDO;

/** Una conexion PDO a una base PostgreSQL de la red, con las credenciales de esta aplicacion (las mismas en todos los dispositivos). */
final class Conexion
{
    public static function pdo(string $host, int $puerto, int $esperaSegundos = 5): PDO
    {
        return new PDO(
            "pgsql:host={$host};port={$puerto};dbname=".config('database.connections.pgsql.database').";connect_timeout={$esperaSegundos}",
            (string) config('database.connections.pgsql.username'),
            (string) config('database.connections.pgsql.password'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10, PDO::ATTR_EMULATE_PREPARES => false],
        );
    }
}
