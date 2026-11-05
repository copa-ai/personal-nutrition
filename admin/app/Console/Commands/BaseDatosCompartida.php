<?php

namespace App\Console\Commands;

use App\Services\BaseCompartida\EstadoBase;
use App\Services\Celaut\Nodo;
use App\Services\Celaut\NodoException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Localiza (o crea) la base PostgreSQL compartida y escribe su direccion para que start.sh arranque el resto con ella.
 *
 *  1. Una base que este servicio lanzo en un arranque anterior (pool de `celaut`): se reutiliza. El nodo no la reconoce por la red
 *     (compara el `formal` declarado, con `${NUTRITION_DB_CLUSTER}` sin rellenar, con el pedido ya relleno) y `celaut acquire` no
 *     reutiliza una instancia aun arrendada, asi que sin esto cada reinicio lanzaria otra base.
 *  2. Las instancias de la red «postgres» (PostgreSQL con el esquema de las migraciones en su `formal`; config nutrition.red) que el nodo resolvio al lanzar este servicio (celaut config).
 *  3. Preguntarle al nodo ahora, con la clave de red ya rellena (celaut resolve = Gateway.ResolveNetwork): mira sus instancias, las
 *     semillas del operador y lo que ofrecen sus peers (service_networks.ASK_PEERS).
 *  4. Ninguna: se lanza nutrition-postgres como hijo de este servicio (celaut acquire) con la misma clave de red.
 *
 * No termina con error por falta de saldo ni porque la base tarde en arrancar: el nodo reiniciaria el servicio y lanzaria otra base
 * (cada una se paga de este saldo). Espera y reintenta (config nutrition.espera) y solo falla pasado ese tiempo.
 *
 * Salida (JSON): {"host", "port", "cluster"}. Hay que correrlo sin base de datos (no la usa).
 */
#[Signature('nutrition:base-datos {salida : Fichero donde se escribe la direccion} {--estado= : Carpeta donde se guarda la clave de red generada}')]
#[Description('Localiza o lanza la PostgreSQL compartida y escribe su direccion')]
class BaseDatosCompartida extends Command
{
    public function handle(): int
    {
        $salida = (string) $this->argument('salida');
        $cluster = $this->cluster();
        $this->line("Clave de red (NUTRITION_DB_CLUSTER): {$cluster}");

        $nodo = Nodo::desdeConfig();
        $red = config('nutrition.red');

        if ($direccion = $this->delPool($nodo)) {
            return $this->escribir($salida, $direccion[0], $direccion[1], $cluster);
        }

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
        $instancia = $this->lanzar($nodo, $cluster);
        if ($instancia === null) {
            return self::FAILURE;
        }

        [$host, $puerto] = $this->separar($instancia['uri']);
        if (! $this->esperar($host, $puerto, (int) config('nutrition.espera.arranque_s'))) {
            $this->error("nutrition-postgres no respondio en {$host}:{$puerto} tras ".config('nutrition.espera.arranque_s').' s.');

            return self::FAILURE;
        }

        return $this->escribir($salida, $host, $puerto, $cluster);
    }

    /**
     * Una base de un arranque anterior: la primera instancia del pool que acepta conexiones (esperando un poco a las que aun arrancan).
     * Las que no responden se paran y se sacan del pool: no se queda una base huerfana pagando mantenimiento.
     *
     * @return array{0: string, 1: int}|null
     */
    private function delPool(Nodo $nodo): ?array
    {
        try {
            $instancias = $nodo->instancias();
        } catch (NodoException $e) {
            $this->warn($e->getMessage());

            return null;
        }

        foreach ($instancias as $instancia) {
            [$host, $puerto] = $this->separar($instancia['uri']);
            $this->line("Base de un arranque anterior en {$instancia['uri']}: se espera a que responda.");
            if ($this->esperar($host, $puerto, (int) config('nutrition.espera.reutilizar_s'))) {
                $this->info("Se reutiliza la base {$instancia['uri']}.");

                return [$host, $puerto];
            }
            $this->warn("{$instancia['uri']} no responde: se para y se saca del pool.");
            try {
                $nodo->parar((int) $instancia['lease']);
            } catch (NodoException $e) {
                $this->warn($e->getMessage());
            }
        }

        return null;
    }

    /**
     * Lanza nutrition-postgres. Si el nodo lo rechaza (saldo insuficiente, memoria ocupada...) espera y reintenta en vez de salir.
     *
     * @return array{lease: int, uri: string, new: bool}|null null si se agotan los intentos
     */
    private function lanzar(Nodo $nodo, string $cluster): ?array
    {
        $intentos = max(1, (int) config('nutrition.espera.intentos_lanzar'));
        $pausa = max(0, (int) config('nutrition.espera.pausa_lanzar_s'));

        for ($i = 1; $i <= $intentos; $i++) {
            try {
                return $nodo->adquirir(config('nutrition.red.dependencia'), [
                    'DB_USERNAME' => (string) config('nutrition.db.usuario'),
                    'DB_PASSWORD' => (string) config('nutrition.compartido.clave_bd'),
                    'DB_DATABASE' => (string) config('nutrition.db.nombre'),
                    'NUTRITION_DB_CLUSTER' => $cluster,
                ]);
            } catch (NodoException $e) {
                $this->warn("No se pudo lanzar nutrition-postgres ({$i}/{$intentos}): ".$e->getMessage());
                $this->warn('Si es por saldo insuficiente: nodo increase_deposit <id de este servicio> <cantidad>. Se reintenta en '.$pausa.' s.');
                $i < $intentos && sleep($pausa);
            }
        }
        $this->error('No se pudo lanzar nutrition-postgres.');

        return null;
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

    private function esperar(string $host, int $puerto, int $segundos): bool
    {
        $inicio = time();
        $aviso = $inicio;
        while (time() - $inicio < $segundos) {
            if ($this->abierto($host, $puerto)) {
                return true;
            }
            if (time() - $aviso >= 30) {
                $aviso = time();
                $this->line("Esperando a {$host}:{$puerto} (".(time() - $inicio).' de '.$segundos.' s)...');
            }
            sleep(2);
        }

        return false;
    }

    private function escribir(string $salida, string $host, int $puerto, string $cluster): int
    {
        EstadoBase::escribir($salida, $host, $puerto, $cluster);
        $this->info("Base de datos en {$host}:{$puerto}");

        return self::SUCCESS;
    }
}
