<?php

namespace Tests\Support;

use App\Models\ChatMensaje;
use Carbon\Carbon;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/** Montaje comun de las pruebas del modo Claude del chat: hogar, carpeta del agente temporal, runner simulado y un turno ya creado. */
trait PreparaChatClaude
{
    use ApiChat, CreaHogar;

    protected string $carpeta;

    protected function prepararClaude(): void
    {
        Carbon::setTestNow('2026-11-05 10:00:00');
        $this->prepararChat();
        $this->carpeta = sys_get_temp_dir().'/chat-claude-'.uniqid();
        File::makeDirectory($this->carpeta.'/workspace', 0775, true);
        File::makeDirectory($this->carpeta.'/encargos', 0775, true);
        config([
            'agente.carpeta' => $this->carpeta, 'agente.remoto.url' => 'http://agente:9000', 'agente.remoto.secreto' => 'secreto-de-prueba-123',
            'agente.remoto.espera_ms' => 0, 'agente.almacen' => null,
            'chat_claude.espera_agente_cada_s' => 0, 'chat_claude.espera_agente_max_s' => 30,
        ]);
        $this->crearHogar();
    }

    protected function limpiarClaude(): void
    {
        File::deleteDirectory($this->carpeta);
        Carbon::setTestNow();
    }

    /** Envia una pregunta (sin lanzar el job) y devuelve [pregunta, respuesta PENDING]. @param  array<string, mixed>  $extra  @return array{0: ChatMensaje, 1: ChatMensaje} */
    protected function preguntar(string $texto, array $partes = []): array
    {
        Queue::fake();
        $this->api('POST', 'mensajes', ['content' => [['tipo' => 'texto', 'texto' => $texto], ...$partes]])->assertOk();
        $ultima = (int) ChatMensaje::query()->max('sequence');

        return [$this->mensajePorSecuencia($ultima - 1), $this->mensajePorSecuencia($ultima)];
    }

    /** Sube una foto y devuelve su parte de imagen. @return array<string, mixed> */
    protected function foto(string $bytes): array
    {
        $this->subir($bytes)->assertOk();

        return $this->parteImagen($bytes);
    }

    protected function lineas(array ...$eventos): string
    {
        return implode('', array_map(fn (array $e): string => json_encode($e, JSON_UNESCAPED_UNICODE)."\n", $eventos));
    }

    /** @return array<string, mixed> */
    protected function pensar(string $t): array
    {
        return ['type' => 'assistant', 'message' => ['content' => [['type' => 'thinking', 'thinking' => $t]]]];
    }

    /** @return array<string, mixed> */
    protected function decir(string $t, bool $conHerramienta = false): array
    {
        return ['type' => 'assistant', 'message' => ['content' => [['type' => 'text', 'text' => $t], ...($conHerramienta ? [['type' => 'tool_use', 'name' => 'Read', 'input' => ['file_path' => 'x']]] : [])]]];
    }

    /** @return array<string, mixed> */
    protected function resultado(string $t = 'listo'): array
    {
        return ['type' => 'result', 'result' => $t, 'num_turns' => 2];
    }

    /**
     * Runner simulado. $consultas son los trozos de salida que van llegando (uno por consulta GET; el ultimo cierra la ejecucion con $codigo).
     * $alConsultar($n) se ejecuta en cada consulta (n desde 0). $archivos son los de GET /ejecuciones/{id}/archivos. $altas: respuestas
     * sucesivas del POST (por defecto 202).
     *
     * @param  array<int, string>  $consultas
     * @param  array<string, mixed>  $final  campos que pisan la respuesta de la ultima consulta
     * @param  array<int, int>  $altas
     * @param  array<string, string>  $archivos  ruta => contenido (sin base64)
     */
    protected function runner(array $consultas, ?callable $alConsultar = null, array $final = [], array $altas = [202], array $archivos = []): void
    {
        $n = 0;
        $altaN = 0;
        app()->forgetInstance(HttpFactory::class);
        Http::clearResolvedInstances();
        Http::fake(function (Request $r) use ($consultas, $alConsultar, $final, $altas, $archivos, &$n, &$altaN) {
            if ($r->method() === 'POST') {
                $estado = $altas[min($altaN++, count($altas) - 1)];

                return $estado === 202 ? Http::response(['id' => 'run1'], 202) : Http::response(['error' => 'demasiadas ejecuciones a la vez'], $estado);
            }
            if ($r->method() === 'GET' && str_ends_with($r->url(), '/archivos')) {
                return Http::response(['archivos' => array_map('base64_encode', $archivos)]);
            }
            if ($r->method() === 'GET') {
                $i = $n++;
                $alConsultar && $alConsultar($i);
                $ultima = $i >= count($consultas) - 1;

                return Http::response(($ultima ? $final + ['estado' => 'terminado', 'codigo' => 0] : ['estado' => 'corriendo']) + ['chunks' => [['out', $consultas[min($i, count($consultas) - 1)]]], 'siguiente' => $i + 1]);
            }

            return Http::response(['ok' => true]);   // DELETE
        });
    }

    /** @return array<int, Request> */
    protected function enviados(string $metodo): array
    {
        return Http::recorded()->filter(fn ($par): bool => $par[0]->method() === $metodo)->map(fn ($par) => $par[0])->values()->all();
    }
}
