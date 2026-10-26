<?php

namespace App\Services\Claude;

/**
 * Traduce la salida de `claude -p --output-format stream-json --verbose` (una linea JSON por evento) a un registro
 * legible, segun va llegando. Lo que no es JSON se deja tal cual (asi sirve tambien con un proceso que imprime texto).
 * Al terminar, resultado() devuelve el texto final de la respuesta (el evento «result»), igual que con `claude -p` a secas.
 */
final class RegistroStream
{
    private string $resto = '';

    private ?string $resultado = null;

    /** Recibe un fragmento (no tiene por que acabar en salto de linea) y devuelve el texto legible de las lineas completas. */
    public function alimentar(string $fragmento): string
    {
        $this->resto .= $fragmento;
        $lineas = explode("\n", $this->resto);
        $this->resto = array_pop($lineas);

        return $this->traducir($lineas);
    }

    /** Lo que quede sin procesar (la ultima linea, si no acabo en salto de linea). */
    public function terminar(): string
    {
        $lineas = trim($this->resto) === '' ? [] : [$this->resto];
        $this->resto = '';

        return $this->traducir($lineas);
    }

    /** Texto final de la respuesta, o null si no llego el evento «result». */
    public function resultado(): ?string
    {
        return $this->resultado;
    }

    private function traducir(array $lineas): string
    {
        $texto = '';
        foreach ($lineas as $linea) {
            if (trim($linea) === '') {
                continue;
            }
            $evento = json_decode($linea, true);
            $texto .= is_array($evento) && isset($evento['type']) ? $this->evento($evento) : $linea."\n";
        }

        return $texto;
    }

    private function evento(array $e): string
    {
        $hora = '['.now()->format('H:i:s').'] ';

        switch ($e['type']) {
            case 'system':
                return ($e['subtype'] ?? '') === 'init'
                    ? $hora.'▶ Sesión iniciada'.(isset($e['model']) ? " ({$e['model']})" : '')."\n"
                    : '';

            case 'assistant':
                $salida = '';
                foreach ($e['message']['content'] ?? [] as $bloque) {
                    if (($bloque['type'] ?? '') === 'text' && trim($bloque['text'] ?? '') !== '') {
                        $salida .= $hora.'💬 '.trim($bloque['text'])."\n";
                    } elseif (($bloque['type'] ?? '') === 'tool_use') {
                        $salida .= $hora.'🔧 '.($bloque['name'] ?? '?').': '.$this->resumenEntrada($bloque['input'] ?? [])."\n";
                    }
                }

                return $salida;

            case 'user':
                $salida = '';
                foreach ((array) ($e['message']['content'] ?? []) as $bloque) {
                    if (is_array($bloque) && ($bloque['type'] ?? '') === 'tool_result') {
                        $contenido = $bloque['content'] ?? '';
                        $contenido = is_array($contenido) ? implode(' ', array_column($contenido, 'text')) : (string) $contenido;
                        $salida .= empty($bloque['is_error'])
                            ? '           ↳ '.mb_strlen($contenido)." caracteres\n"
                            : '           ↳ ⚠ '.$this->corto($contenido, 200)."\n";
                    }
                }

                return $salida;

            case 'result':
                $this->resultado = (string) ($e['result'] ?? '');
                $segundos = isset($e['duration_ms']) ? round($e['duration_ms'] / 1000).' s' : null;
                $coste = isset($e['total_cost_usd']) ? '$'.round($e['total_cost_usd'], 2) : null;
                $detalle = implode(' · ', array_filter([$segundos, isset($e['num_turns']) ? "{$e['num_turns']} turnos" : null, $coste]));

                return $hora.(empty($e['is_error']) ? '✔ Terminado' : '✘ Terminó con error').($detalle !== '' ? " ({$detalle})" : '')."\n";

            default:
                return '';   // eventos internos (limites, partes de mensaje...) que no aportan al registro
        }
    }

    private function resumenEntrada(array $entrada): string
    {
        foreach (['command', 'query', 'url', 'file_path', 'path', 'pattern', 'skill', 'description'] as $clave) {
            if (isset($entrada[$clave]) && is_string($entrada[$clave])) {
                return $this->corto($entrada[$clave], 200);
            }
        }

        return $this->corto((string) json_encode($entrada, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 200);
    }

    private function corto(string $texto, int $max): string
    {
        $texto = trim(preg_replace('/\s+/', ' ', $texto));

        return mb_strlen($texto) > $max ? mb_substr($texto, 0, $max).'…' : $texto;
    }
}
