<?php

namespace App\Services\Claude;

use App\Services\Agente\AlmacenAgente;
use App\Services\Menu\TokenClaude;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Unico sitio donde se lanza `claude -p`. Con AGENTE_URL se le pide al servicio «agente» de docker (que solo ve
 * agente/, ver docker/agente/runner.py); sin ella se ejecuta en el propio proceso (desarrollo y pruebas).
 */
class EjecutorClaude
{
    public static function remoto(): bool
    {
        return filled(config('agente.remoto.url'));
    }

    /**
     * @param  ?string  $herramientas  lista de --allowedTools, o null para no pasar ninguna
     * @param  ?callable(string, string): void  $salida  recibe ('out'|'err', texto) segun va saliendo
     * @param  ?callable(?int): void  $alIniciar  recibe el pid si se ejecuta en este proceso
     * @param  ?callable(string): void  $alAlta  recibe el id de la ejecucion cuando el agente remoto la acepta (para guardarlo y poder cancelarla)
     * @param  ?Process  $proceso  solo en local: el proceso a usar en vez de construir uno (las pruebas lo sustituyen)
     * @param  bool  $stream  pide `--output-format stream-json`: $salida recibe un registro legible de cada paso (herramientas, textos) y
     *                        ResultadoClaude::$salida sigue siendo el texto final de la respuesta
     * @param  ?callable(string): void  $crudo  solo con $stream: recibe la salida estandar tal como llega (JSON por lineas, sin traducir), para quien necesite los eventos (el chat)
     * @param  ?callable(): void  $latido  solo con agente remoto: se llama en cada consulta del avance, haya salida nueva o no (el chat comprueba asi si la persona cancelo mientras el agente lee o piensa en silencio)
     *
     * @throws RuntimeException si no se puede ejecutar o se pasa del tiempo (un codigo de salida distinto de 0 no lanza: va en el resultado)
     */
    public function ejecutar(string $prompt, ?string $herramientas = null, float $timeout = 180, ?callable $salida = null,
        ?callable $alIniciar = null, ?Process $proceso = null, bool $stream = false, ?callable $alAlta = null, ?callable $crudo = null, ?callable $latido = null): ResultadoClaude
    {
        $registro = $stream ? new RegistroStream : null;
        $destino = $salida;
        if ($registro) {
            $salida = function (string $tipo, string $texto) use ($registro, $destino, $crudo) {
                $tipo === 'out' && $crudo && $crudo($texto);
                $texto = $tipo === 'out' ? $registro->alimentar($texto) : $texto;
                $texto !== '' && $destino && $destino($tipo, $texto);
            };
        }

        $r = static::remoto()
            ? $this->remota($prompt, $herramientas, $timeout, $salida, $stream, $alAlta, $latido)
            : $this->local($prompt, $herramientas, $timeout, $salida, $alIniciar, $proceso, $stream);

        if ($registro) {
            ($resto = $registro->terminar()) !== '' && $destino && $destino('out', $resto);
            $r = new ResultadoClaude($registro->resultado() ?? $r->salida, $r->errores, $r->codigo);
        }

        return $r;
    }

    private static function http(): \Closure
    {
        return fn () => Http::baseUrl(rtrim(config('agente.remoto.url'), '/'))->withToken((string) config('agente.remoto.secreto'))
            ->acceptJson()->timeout(30);
    }

    /** Pide al agente que mate una ejecucion suya (no-op si no hay agente remoto). Devuelve si lo consiguio. */
    public static function cancelar(string $id): bool
    {
        if (! static::remoto() || $id === '') {
            return false;
        }
        try {
            $r = (static::http())()->delete('/ejecuciones/'.rawurlencode($id));

            return $r->successful() || $r->status() === 404;
        } catch (\Throwable) {
            return false;
        }
    }

    private function local(string $prompt, ?string $herramientas, float $timeout, ?callable $salida, ?callable $alIniciar, ?Process $proceso, bool $stream): ResultadoClaude
    {
        set_time_limit(0);
        $orden = [config('agente.claude'), '-p', $prompt, '--model', config('agente.modelo'), ...($herramientas ? ['--allowedTools', $herramientas] : []),
            ...($stream ? ['--output-format', 'stream-json', '--verbose'] : [])];
        $proceso ??= new Process($orden, rtrim(config('agente.carpeta'), '/\\'), TokenClaude::entorno() ?: null, null, $timeout);
        $proceso->start();
        $alIniciar && $alIniciar($proceso->getPid());
        $out = $err = '';
        $proceso->wait(function ($tipo, $texto) use (&$out, &$err, $salida) {
            $tipo === Process::ERR ? $err .= $texto : $out .= $texto;
            $salida && $salida($tipo === Process::ERR ? 'err' : 'out', $texto);
        });

        return new ResultadoClaude($out, $err, $proceso->getExitCode());
    }

    private function remota(string $prompt, ?string $herramientas, float $timeout, ?callable $salida, bool $stream, ?callable $alAlta, ?callable $latido = null): ResultadoClaude
    {
        $http = static::http();

        try {
            $alta = $http()->post('/ejecuciones', array_filter([
                'prompt' => $prompt, 'modelo' => config('agente.modelo'), 'herramientas' => $herramientas, 'timeout' => $timeout, 'token' => TokenClaude::obtener(), 'stream' => $stream ?: null,
                // Encargo y base del agente, de la base de datos: el runner no tiene acceso a ella (solo en las tandas de trabajo).
                'archivos' => $stream && AlmacenAgente::activo() ? AlmacenAgente::entrada() : null,
            ], fn ($v) => $v !== null));
            if ($alta->failed()) {
                throw new RuntimeException('El agente no aceptó la ejecución ('.$alta->status().'): '.($alta->json('error') ?? mb_substr($alta->body(), 0, 200)));
            }
            $id = (string) $alta->json('id');
            $alAlta && $alAlta($id);

            $out = $err = '';
            $desde = 0;
            $limite = microtime(true) + $timeout + 60;   // el agente corta a $timeout; el margen cubre el aviso de cierre
            while (true) {
                $r = $http()->get("/ejecuciones/{$id}", ['desde' => $desde]);
                if ($r->failed()) {
                    throw new RuntimeException('El agente no respondió al consultar la ejecución ('.$r->status().').');
                }
                foreach ($r->json('chunks', []) as [$tipo, $texto]) {
                    $tipo === 'err' ? $err .= $texto : $out .= $texto;
                    $salida && $salida($tipo, $texto);
                }
                $desde = (int) $r->json('siguiente', $desde);
                $latido && $latido();
                if ($r->json('estado') === 'terminado') {
                    if ($r->json('interrumpida')) {
                        throw new RuntimeException('El agente se reinició mientras ejecutaba la tanda y la ejecución se perdió.');
                    }
                    if ($r->json('tiempo_agotado')) {
                        throw new RuntimeException('claude se pasó del tiempo ('.(int) $timeout.' s) y se cortó.');
                    }
                    if ($stream && AlmacenAgente::activo()) {
                        $a = $http()->timeout(120)->get("/ejecuciones/{$id}/archivos");
                        if ($a->failed()) {
                            throw new RuntimeException('El agente terminó pero no se pudieron recoger sus archivos ('.$a->status().').');
                        }
                        AlmacenAgente::recibir((array) $a->json('archivos', []));
                    }

                    return new ResultadoClaude($out, $err, $r->json('codigo'));
                }
                if (microtime(true) > $limite) {
                    throw new RuntimeException('El agente no terminó a tiempo.');
                }
                usleep(max(0, (int) config('agente.remoto.espera_ms')) * 1000);
            }
        } catch (\Throwable $e) {
            if (isset($id)) {
                try {
                    $http()->delete("/ejecuciones/{$id}");   // no dejar claude gastando el plan si nadie espera ya su respuesta
                } catch (\Throwable) {
                }
            }

            throw $e instanceof RuntimeException ? $e : new RuntimeException('No se pudo hablar con el agente: '.$e->getMessage(), 0, $e);
        }
    }
}
