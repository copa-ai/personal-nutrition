<?php

namespace App\Services\BaseCompartida;

use Carbon\CarbonImmutable;
use PDO;
use Throwable;

/**
 * Pregunta a una base PostgreSQL quien es y cuanto se ha usado: `system_identifier` (de `pg_control_system()`, lo puede leer
 * cualquier rol) y el numero y la fecha del ultimo evento de la tabla `auditorias`. Una base recien lanzada, sin migrar, tiene 0 eventos.
 */
class Sonda
{
    /** @return Candidata|null null si la base no responde o rechaza las credenciales */
    public function sondear(string $host, int $puerto): ?Candidata
    {
        try {
            $pdo = Conexion::pdo($host, $puerto);

            $base = $pdo->query("SELECT (SELECT system_identifier::text FROM pg_control_system()) AS id, to_regclass('auditorias') IS NOT NULL AS auditada")->fetch(PDO::FETCH_ASSOC);
            $eventos = 0;
            $ultimo = null;
            if ($base['auditada']) {
                $a = $pdo->query('SELECT count(*) AS n, max(created_at) AS ultimo FROM auditorias')->fetch(PDO::FETCH_ASSOC);
                $eventos = (int) $a['n'];
                $ultimo = $a['ultimo'] === null ? null : CarbonImmutable::parse($a['ultimo']);
            }

            return new Candidata("{$host}:{$puerto}", (string) $base['id'], $eventos, $ultimo);
        } catch (Throwable) {
            return null;
        }
    }
}
