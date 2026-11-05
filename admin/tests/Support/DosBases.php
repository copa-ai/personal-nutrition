<?php

namespace Tests\Support;

use App\Services\Fusion\Exportador;
use App\Services\Fusion\Fusionador;
use Illuminate\Support\Facades\Artisan;
use PDO;

/**
 * Dos bases PostgreSQL (A y B) migradas en el servidor de pruebas, para probar la fusion: lo de B se fusiona en A. No usa
 * RefreshDatabase (la fusion abre su propia transaccion): cada prueba empieza con las dos vacias.
 */
trait DosBases
{
    private const BASES_FUSION = ['a' => 'pn_fusion_a', 'b' => 'pn_fusion_b'];

    private static bool $basesListas = false;

    /** @var array<string, PDO> */
    private array $pdos = [];

    protected function prepararDosBases(): void
    {
        $c = config('database.connections.pgsql');
        if (! self::$basesListas) {
            $servidor = $this->abrir($c['database']);
            foreach (self::BASES_FUSION as $clave => $base) {
                $servidor->exec("DROP DATABASE IF EXISTS {$base} WITH (FORCE)");
                $servidor->exec("CREATE DATABASE {$base}");
                config(["database.connections.fusion_{$clave}" => [...$c, 'database' => $base]]);
                Artisan::call('migrate', ['--database' => "fusion_{$clave}", '--force' => true]);
            }
            register_shutdown_function(function () use ($c): void {
                $servidor = new PDO("pgsql:host={$c['host']};port={$c['port']};dbname={$c['database']}", $c['username'], $c['password']);
                foreach (self::BASES_FUSION as $base) {
                    $servidor->exec("DROP DATABASE IF EXISTS {$base} WITH (FORCE)");
                }
            });
            self::$basesListas = true;
        }

        foreach (self::BASES_FUSION as $clave => $base) {
            $this->pdos[$clave] = $this->abrir($base);
            $this->pdos[$clave]->exec('TRUNCATE users, alimentos, hogares, perfiles, menus, disponibilidad_dias, existencias, feedback, registros_comida, '
                .'consumos_menu, notifications, agente_archivos, ajustes, chat_conversacion, chat_archivos, chat_mensajes, chat_peticiones_secreto, '
                .'borrados, auditorias CASCADE');
        }
    }

    private function abrir(string $base): PDO
    {
        $c = config('database.connections.pgsql');

        return new PDO("pgsql:host={$c['host']};port={$c['port']};dbname={$base}", $c['username'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    protected function base(string $clave): PDO
    {
        return $this->pdos[$clave];
    }

    /** Ejecuta SQL en una base (A o B). @param  list<mixed>  $parametros */
    protected function sql(string $clave, string $sql, array $parametros = []): void
    {
        $this->base($clave)->prepare($sql)->execute($parametros);
    }

    /** @param  list<mixed>  $parametros @return list<array<string, mixed>> */
    protected function filas(string $clave, string $sql, array $parametros = []): array
    {
        $consulta = $this->base($clave)->prepare($sql);
        $consulta->execute($parametros);

        return $consulta->fetchAll(PDO::FETCH_ASSOC);
    }

    protected function valor(string $clave, string $sql, array $parametros = []): mixed
    {
        $consulta = $this->base($clave)->prepare($sql);
        $consulta->execute($parametros);

        return $consulta->fetchColumn();
    }

    /** Fusiona lo de $origen en $destino (por el fichero, como en produccion). @return array<string, int> */
    protected function fusionar(string $origen = 'b', string $destino = 'a'): array
    {
        $flujo = fopen('php://temp', 'w+');
        (new Exportador)->exportar($this->base($origen), $flujo, "instancia-{$origen}");
        rewind($flujo);

        return (new Fusionador)->fusionar($this->base($destino), $flujo, "instancia-{$destino}");
    }

    protected function hogar(string $clave, string $id, string $nombre, string $actualizado, ?string $menu = null): void
    {
        $this->sql($clave, 'INSERT INTO hogares (id, nombre, menu_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?)', [$id, $nombre, $menu, '2026-11-01 00:00:00', $actualizado]);
    }

    protected function descartes(string $clave): array
    {
        return $this->filas($clave, "SELECT * FROM auditorias WHERE evento = 'fusion_descartada' ORDER BY id");
    }
}
