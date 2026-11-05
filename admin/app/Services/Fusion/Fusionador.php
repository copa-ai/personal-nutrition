<?php

namespace App\Services\Fusion;

use App\Services\BaseCompartida\Conexion;
use App\Services\Instancia;
use JsonException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Fusiona unos datos (el fichero de App\Services\Fusion\Exportador) en una base. La fusion en si es de PostgreSQL
 * (migracion create_fusion_functions): esto lee el fichero y llama a sus funciones, tanda a tanda, dentro de una transaccion.
 * Todo o nada: si algo falla no cambia nada.
 */
class Fusionador
{
    /**
     * @param  resource  $entrada  fichero NDJSON abierto para leer
     * @return array<string, int> resumen: insertadas, actualizadas, descartadas, iguales, borradas, auditorias...
     */
    public function fusionar(PDO $destino, $entrada, ?string $ejecutor = null): array
    {
        $cabecera = $this->leer($entrada) ?? throw new RuntimeException('El fichero de datos esta vacio.');
        if (($cabecera['formato'] ?? null) !== Exportador::FORMATO || ($cabecera['version'] ?? null) !== Exportador::VERSION) {
            throw new RuntimeException('El fichero no es un volcado de datos de Personal Nutrition (o es de otra version).');
        }

        $destino->exec('BEGIN');
        try {
            $this->llamar($destino, 'SELECT fusion_iniciar(?, ?, ?)', [(string) ($cabecera['origen'] ?? ''), (string) ($cabecera['esquema'] ?? ''), $ejecutor ?? (string) Instancia::id()]);
            while (($linea = $this->leer($entrada)) !== null) {
                if (isset($linea['borrados'])) {
                    $this->llamar($destino, 'SELECT fusion_borrados(?::jsonb)', [json_encode($linea['borrados'], JSON_UNESCAPED_UNICODE)]);
                } elseif (isset($linea['tabla'], $linea['filas'])) {
                    $this->llamar($destino, 'SELECT fusion_tabla(?, ?::jsonb)', [$linea['tabla'], json_encode($linea['filas'], JSON_UNESCAPED_UNICODE)]);
                } elseif (isset($linea['auditorias'])) {
                    $this->llamar($destino, 'SELECT fusion_auditorias(?::jsonb)', [json_encode($linea['auditorias'], JSON_UNESCAPED_UNICODE)]);
                } else {
                    throw new RuntimeException('Linea desconocida en el fichero de datos.');
                }
            }
            $resumen = json_decode((string) $this->llamar($destino, 'SELECT fusion_terminar()'), true);
            $destino->exec('COMMIT');
        } catch (Throwable $e) {
            try {
                $destino->exec('ROLLBACK');
            } catch (Throwable) {
            }

            throw $e;
        }

        return is_array($resumen) ? $resumen : [];
    }

    /**
     * Fusiona una base de la red en otra, por sus direcciones: vuelca la de origen a un fichero temporal y lo carga en el destino.
     * Es lo que usa `nutrition:vigilar-base`; se sustituye en las pruebas.
     *
     * @return array<string, int>
     */
    public function fusionarBase(string $origen, string $destino): array
    {
        [$hostOrigen, $puertoOrigen] = $this->separar($origen);
        [$hostDestino, $puertoDestino] = $this->separar($destino);
        $ruta = tempnam(sys_get_temp_dir(), 'fusion');
        try {
            $flujo = fopen($ruta, 'w+');
            (new Exportador)->exportar(Conexion::pdo($hostOrigen, $puertoOrigen), $flujo, $origen);
            rewind($flujo);
            try {
                return $this->fusionar(Conexion::pdo($hostDestino, $puertoDestino), $flujo);
            } finally {
                fclose($flujo);
            }
        } finally {
            @unlink($ruta);
        }
    }

    /**
     * @param  resource  $entrada
     * @return array<string, mixed>|null null al terminar el fichero
     */
    private function leer($entrada): ?array
    {
        while (($linea = fgets($entrada)) !== false) {
            if (trim($linea) === '') {
                continue;
            }
            try {
                return json_decode($linea, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new RuntimeException('El fichero de datos esta danado: '.$e->getMessage(), previous: $e);
            }
        }

        return null;
    }

    /** @param  list<string>  $parametros */
    private function llamar(PDO $pdo, string $sql, array $parametros = []): mixed
    {
        $consulta = $pdo->prepare($sql);
        $consulta->execute($parametros);

        return $consulta->fetchColumn();
    }

    /** @return array{0: string, 1: int} */
    private function separar(string $direccion): array
    {
        return [str($direccion)->beforeLast(':')->toString(), (int) str($direccion)->afterLast(':')->toString()];
    }
}
