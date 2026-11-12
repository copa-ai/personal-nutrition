<?php

namespace App\Services\Celaut;

use JsonException;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * La conversacion de este servicio con su nodo Celaut, a traves de `celaut` (celaut-project/service-library, el binario estatico que
 * va en la imagen). Cada orden imprime un JSON; un error es JSON en stderr y un codigo de salida (1 rechazado, 3 el nodo no responde).
 *
 * Las instancias que se lanzan las guarda `celaut` en un fichero de estado (con sus tokens, que no llegan a este proceso): una orden
 * posterior las reutiliza y `apagar()` las para todas.
 */
class Nodo
{
    public function __construct(
        private readonly string $binario = 'celaut',
        private readonly string $raiz = '/opt/nutrition/celaut',
        private readonly ?string $estado = null,
    ) {}

    public static function desdeConfig(): self
    {
        return new self(config('nutrition.celaut.binario'), config('nutrition.celaut.raiz'), config('nutrition.celaut.estado'));
    }

    /**
     * @return array{gateway: string, mem_limit: int, networks: list<array{tags: list<string>, peers: list<string>, members: list<array{slots: list<array<string, mixed>>}>}>}
     */
    public function configuracion(): array
    {
        return $this->ejecutar(['config']);
    }

    /**
     * Direcciones «ip:puerto» del puerto con ese protocolo de los miembros que el nodo resolvio para la red al lanzar este servicio
     * (`celaut config`; `networks[].members` trae todos los puertos de cada miembro). El tag se compara exacto («postgres») entre las
     * redes que este servicio declara; solo declara una con ese tag, y lo que la distingue de otras bases es su `formal`.
     *
     * @return list<string>
     */
    public function miembrosDeRed(string $tag, string $protocolo): array
    {
        $miembros = [];
        foreach ($this->configuracion()['networks'] ?? [] as $red) {
            in_array($tag, $red['tags'] ?? [], true) && $miembros = [...$miembros, ...($red['members'] ?? [])];
        }

        return static::direccionesDe($miembros, $protocolo);
    }

    /**
     * Pregunta al nodo por los miembros de una red (`celaut resolve` = Gateway.ResolveNetwork). El nodo pregunta ademas a sus peers y
     * abre en el firewall de este servicio las direcciones que devuelve. La peticion tiene que caber en lo que el servicio declaro
     * (mismos tags, claves fijas iguales; `db.cluster` se rellena), si no el nodo la rechaza.
     *
     * @param  array<string, string>  $formal  clave => valor, ya sin variables ${...}. Lleva el esquema completo (`db.table.*`): cada
     *                                         clave sale como un argumento `--formal clave=valor` (el valor puede contener «=»)
     * @return list<string> direcciones «ip:puerto» del puerto con $protocolo
     */
    public function resolverRed(string $tag, array $formal, string $protocolo): array
    {
        $args = ['resolve', '--tag', $tag, '--protocol', $protocolo];
        foreach ($formal as $clave => $valor) {
            array_push($args, '--formal', "{$clave}={$valor}");
        }

        // El nodo puede tener que preguntar a varios peers: tarda mas que una orden local.
        return static::direccionesDe($this->ejecutar($args, 120)['peers'] ?? [], $protocolo);
    }

    /**
     * De una lista de miembros (`{slots: [{internal_port, transport, protocol_stack: [[tag...]], uris: [«ip:puerto»]}]}`), las
     * direcciones de los puertos cuyo protocolo es $protocolo. Un miembro que viene de otro nodo trae un solo puerto con la pila de
     * protocolos que pedimos; uno local, el puerto real con la que declara: no se adivina el protocolo por el numero de puerto.
     *
     * @param  list<array<string, mixed>>  $miembros
     * @return list<string>
     */
    public static function direccionesDe(array $miembros, string $protocolo): array
    {
        $direcciones = [];
        foreach ($miembros as $miembro) {
            foreach ($miembro['slots'] ?? [] as $slot) {
                $etiquetas = array_merge([], ...($slot['protocol_stack'] ?? []));
                in_array($protocolo, $etiquetas, true) && $direcciones = [...$direcciones, ...($slot['uris'] ?? [])];
            }
        }

        return array_values(array_unique($direcciones));
    }

    /**
     * Una instancia de una dependencia empaquetada (entrada de `.dependencies`): una libre del pool o una nueva.
     *
     * @param  array<string, string>  $entorno  variables de entorno de la instancia
     * @return array{lease: int, uri: string, new: bool}
     */
    public function adquirir(string $dependencia, array $entorno = []): array
    {
        $args = ['acquire', '--name', $dependencia, '--root', $this->raiz];
        foreach ($entorno as $clave => $valor) {
            array_push($args, '--env', "{$clave}={$valor}");
        }

        // Un lanzamiento puede tardar minutos: el nodo construye y arranca el servicio antes de contestar.
        return $this->ejecutar($args, 1800);
    }

    /**
     * Las instancias del pool de `celaut` (las que lanzo este servicio, aunque sea en un arranque anterior, si el estado sobrevive).
     *
     * @return list<array{lease: int, service: string, uri: string, leased: bool, idle_secs: int, timeouts: int, failures: int}>
     */
    public function instancias(): array
    {
        return $this->ejecutar(['list'])['instances'] ?? [];
    }

    /**
     * El saldo de una instancia del pool y si esta suspendida (`celaut metrics`). Las cantidades (MU) llegan como cadena.
     *
     * @return array{balance_mu: string, suspended: bool}
     */
    public function metricas(int $lease): array
    {
        return $this->ejecutar(['metrics', (string) $lease]);
    }

    /**
     * Lo que cuesta mantener una dependencia empaquetada: `init_maintenance_cost_mu` es UN tick de mantenimiento (10 s) con los recursos
     * del arranque, a precio de ahora (`celaut cost`).
     *
     * @return array{service: string, cost_mu: string, init_maintenance_cost_mu: string, max_maintenance_cost_mu: string}
     */
    public function coste(string $dependencia): array
    {
        return $this->ejecutar(['cost', '--name', $dependencia, '--root', $this->raiz], 120);
    }

    /**
     * Mueve MU de este servicio a una instancia del pool (`celaut deposit`; negativo = de vuelta). Con saldo insuficiente el nodo
     * contesta `success: false`.
     *
     * @return array{success: bool, message: string}
     */
    public function depositar(int $lease, string $mu): array
    {
        return $this->ejecutar(['deposit', (string) $lease, $mu]);
    }

    /** El id (hash) del servicio de una dependencia empaquetada, de `.dependencies` en la raiz de celaut, o null si no consta. */
    public function idDeDependencia(string $nombre): ?string
    {
        $ruta = rtrim($this->raiz, '/').'/.dependencies';
        if (! is_file($ruta)) {
            return null;
        }
        foreach (file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linea) {
            [$clave, $valor] = array_pad(explode('=', trim($linea), 2), 2, '');
            if ($clave === $nombre && $valor !== '') {
                return $valor;
            }
        }

        return null;
    }

    /** Para una instancia del pool y la saca de el (el saldo no gastado vuelve a este servicio). */
    public function parar(int $lease): array
    {
        return $this->ejecutar(['stop', (string) $lease], 300);
    }

    /** Para todas las instancias que este servicio lanzo (al terminar el servicio). */
    public function apagar(): array
    {
        return $this->ejecutar(['shutdown'], 300);
    }

    /**
     * @param  list<string>  $args
     * @return array<string, mixed>
     */
    private function ejecutar(array $args, float $timeout = 60): array
    {
        $global = $this->estado ? ['--state', $this->estado] : [];
        $proceso = new Process([$this->binario, ...$global, ...$args], null, null, null, $timeout);
        $proceso->run();

        if (! $proceso->isSuccessful()) {
            $error = json_decode($proceso->getErrorOutput(), true);
            $motivo = is_array($error) ? ($error['error'] ?? 'error desconocido') : trim($proceso->getErrorOutput());
            throw new NodoException("celaut {$args[0]}: {$motivo}", $proceso->getExitCode() === 3);
        }

        try {
            $salida = json_decode($proceso->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("celaut {$args[0]} no devolvio JSON: ".trim($proceso->getOutput()), previous: $e);
        }

        return (array) $salida;
    }
}
