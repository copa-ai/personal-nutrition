<?php

namespace App\Console\Commands;

use App\Services\Celaut\Nodo;
use App\Services\Celaut\NodoException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Localiza (o crea) la base PostgreSQL compartida y escribe su direccion para que start.sh arranque el resto con ella.
 *
 *  1. DB_HOST/DB_PORT en el entorno: se usan tal cual.
 *  2. Las instancias de la red «postgres:personal-nutrition» que el nodo resolvio al lanzar este servicio (celaut config).
 *  3. Preguntarle al nodo ahora, con la clave de red ya rellena (celaut resolve = Gateway.ResolveNetwork): mira sus instancias, las
 *     semillas del operador y lo que ofrecen sus peers (service_networks.ASK_PEERS).
 *  4. Ninguna: se lanza nutrition-postgres como hijo de este servicio (celaut acquire) con la misma clave de red.
 *
 * Salida (JSON): {"host", "port", "cluster"}. Hay que correrlo sin base de datos (no la usa).
 */
#[Signature('nutrition:base-datos {salida : Fichero donde se escribe la direccion} {--estado= : Carpeta donde se guarda la clave de red generada}')]
#[Description('Localiza o lanza la PostgreSQL compartida y escribe su direccion')]
class BaseDatosCompartida extends Command
{
    private const ESPERA_ARRANQUE_S = 300;

    public function handle(): int
    {
        $salida = (string) $this->argument('salida');
        $cluster = $this->cluster();
        $this->line("Clave de red (NUTRITION_DB_CLUSTER): {$cluster}");

        if (filled(config('nutrition.db.host'))) {
            return $this->escribir($salida, (string) config('nutrition.db.host'), (int) config('nutrition.db.port'), $cluster);
        }

        $nodo = Nodo::desdeConfig();

        $red = config('nutrition.red');
        $formal = [...$red['formal'], 'db.cluster' => $cluster];

        $candidatas = [];
        try {
            $candidatas = $nodo->miembrosDeRed($red['tag'], $red['protocolo']);
        } catch (NodoException $e) {
            $this->warn($e->getMessage());
        }
        try {
            $candidatas = [...$candidatas, ...$nodo->resolverRed($red['tag'], $formal, $red['protocolo'])];
        } catch (NodoException $e) {
            // Rechazado por la politica del nodo o nodo sin respuesta: no es motivo para no arrancar, se lanza una base propia.
            $this->warn($e->getMessage());
        }

        foreach (array_values(array_unique($candidatas)) as $direccion) {
            [$host, $puerto] = $this->separar($direccion);
            if ($this->abierto($host, $puerto)) {
                $this->info("El nodo ofrecio una base: {$direccion}");

                return $this->escribir($salida, $host, $puerto, $cluster);
            }
            $this->warn("{$direccion} esta en la red pero no acepta conexiones; se descarta.");
        }

        $this->info('Ninguna instancia de la red: se lanza nutrition-postgres.');
        try {
            $instancia = $nodo->adquirir(config('nutrition.red.dependencia'), [
                'DB_USERNAME' => (string) config('nutrition.db.usuario'),
                'DB_PASSWORD' => (string) config('nutrition.compartido.clave_bd'),
                'DB_DATABASE' => (string) config('nutrition.db.nombre'),
                'NUTRITION_DB_CLUSTER' => $cluster,
            ]);
        } catch (NodoException $e) {
            $this->error($e->getMessage().($e->inalcanzable ? '' : ' (si es por saldo insuficiente: nodo increase_deposit <id de este servicio> <cantidad>)'));

            return self::FAILURE;
        }

        [$host, $puerto] = $this->separar($instancia['uri']);
        if (! $this->esperar($host, $puerto)) {
            $this->error("nutrition-postgres no respondio en {$host}:{$puerto}");

            return self::FAILURE;
        }

        return $this->escribir($salida, $host, $puerto, $cluster);
    }

    /** La clave de red: la dada, la que ya se genero en un arranque anterior o una nueva (red de un solo dispositivo). */
    private function cluster(): string
    {
        if (filled($dada = config('nutrition.compartido.cluster'))) {
            return (string) $dada;
        }
        $fichero = rtrim((string) ($this->option('estado') ?: sys_get_temp_dir()), '/').'/cluster';
        if (is_file($fichero) && filled($guardada = trim((string) file_get_contents($fichero)))) {
            return $guardada;
        }
        @mkdir(dirname($fichero), 0700, true);
        file_put_contents($fichero, $nueva = Str::lower(Str::random(16)));

        return $nueva;
    }

    /** @return array{0: string, 1: int} */
    private function separar(string $direccion): array
    {
        return [Str::beforeLast($direccion, ':'), (int) Str::afterLast($direccion, ':')];
    }

    private function abierto(string $host, int $puerto): bool
    {
        $socket = @fsockopen($host, $puerto, $codigo, $texto, 3);
        $socket && fclose($socket);

        return (bool) $socket;
    }

    private function esperar(string $host, int $puerto): bool
    {
        $limite = time() + self::ESPERA_ARRANQUE_S;
        while (time() < $limite) {
            if ($this->abierto($host, $puerto)) {
                return true;
            }
            sleep(2);
        }

        return false;
    }

    private function escribir(string $salida, string $host, int $puerto, string $cluster): int
    {
        file_put_contents($salida.'.tmp', json_encode(['host' => $host, 'port' => $puerto, 'cluster' => $cluster]));
        rename($salida.'.tmp', $salida);
        $this->info("Base de datos en {$host}:{$puerto}");

        return self::SUCCESS;
    }
}
