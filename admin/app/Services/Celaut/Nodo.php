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
     * @return array{gateway: string, mem_limit: int, networks: list<array{tags: list<string>, peers: list<string>}>}
     */
    public function configuracion(): array
    {
        return $this->ejecutar(['config']);
    }

    /**
     * Direcciones «ip:puerto» que el nodo resolvio para la red con ese tag al lanzar este servicio.
     *
     * @return list<string>
     */
    public function miembrosDeRed(string $tag): array
    {
        $peers = [];
        foreach ($this->configuracion()['networks'] ?? [] as $red) {
            in_array($tag, $red['tags'] ?? [], true) && $peers = [...$peers, ...($red['peers'] ?? [])];
        }

        return array_values(array_unique($peers));
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
