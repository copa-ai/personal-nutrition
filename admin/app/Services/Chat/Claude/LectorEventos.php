<?php

namespace App\Services\Chat\Claude;

/**
 * Lee la salida `--output-format stream-json` de claude (un evento JSON por linea) y entrega a $destino lo que debe ver la persona:
 *   ('texto', ...)        texto de una respuesta del asistente sin herramientas: es la respuesta
 *   ('razonamiento', ...) bloques `thinking` y el texto que dice mientras usa herramientas (es el hilo de trabajo, no la respuesta)
 * Las lineas que no son JSON se ignoran. Si $destino devuelve false deja de entregar (la respuesta ya es final).
 */
final class LectorEventos
{
    private string $resto = '';

    private ?string $resultado = null;

    private bool $hayTexto = false;

    private bool $activo = true;

    /** @param  \Closure(string, string): bool  $destino */
    public function __construct(private readonly \Closure $destino) {}

    public function alimentar(string $trozo): void
    {
        $this->resto .= $trozo;
        $lineas = explode("\n", $this->resto);
        $this->resto = array_pop($lineas);
        foreach ($lineas as $linea) {
            $this->linea($linea);
        }
    }

    public function terminar(): void
    {
        $resto = $this->resto;
        $this->resto = '';
        $this->linea($resto);
    }

    public function resultado(): ?string
    {
        return $this->resultado;
    }

    public function hayTexto(): bool
    {
        return $this->hayTexto;
    }

    private function linea(string $linea): void
    {
        $evento = trim($linea) === '' ? null : json_decode($linea, true);
        if (! is_array($evento) || ! is_string($evento['type'] ?? null)) {
            return;
        }
        if ($evento['type'] === 'result') {
            $this->resultado = (string) ($evento['result'] ?? '');
        }
        if ($evento['type'] !== 'assistant' || ! $this->activo) {
            return;
        }
        $bloques = array_values(array_filter((array) ($evento['message']['content'] ?? []), 'is_array'));
        $conHerramientas = array_filter($bloques, fn (array $b): bool => ($b['type'] ?? '') === 'tool_use') !== [];
        foreach ($bloques as $b) {
            $tipo = $b['type'] ?? '';
            if ($tipo === 'thinking' && trim((string) ($b['thinking'] ?? '')) !== '') {
                $this->entregar('razonamiento', trim((string) $b['thinking'])."\n\n");
            } elseif ($tipo === 'text' && trim((string) ($b['text'] ?? '')) !== '') {
                $texto = trim((string) $b['text']);
                if ($conHerramientas) {
                    $this->entregar('razonamiento', $texto."\n\n");
                } else {
                    $this->entregar('texto', ($this->hayTexto ? "\n\n" : '').$texto);
                    $this->hayTexto = true;
                }
            }
        }
    }

    private function entregar(string $tipo, string $texto): void
    {
        if ($this->activo && ! ($this->destino)($tipo, $texto)) {
            $this->activo = false;
        }
    }
}
