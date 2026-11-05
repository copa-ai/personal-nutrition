<?php

namespace Tests\Support;

use App\Models\ChatMensaje;
use App\Services\Chat\Bot\RespondedorBot;
use App\Services\Chat\Marcado\Validador;
use Carbon\Carbon;

/** Ayudas de las pruebas del bot: hogar con menu activo, y preguntar por la API interna leyendo lo que contesta el servicio. */
trait UsaBot
{
    use ApiChat, CreaHogar;

    /** Hogar con Marta y Javier, y el menu de la semana del lunes 2026-11-02 activo; «hoy» es el miercoles 2026-11-04. */
    protected function prepararBot(): void
    {
        $this->prepararChat();
        config(['chat.respondedor_bot' => RespondedorBot::class]);
        Carbon::setTestNow('2026-11-04 10:00:00');
        $this->crearHogar();
        $this->menu->update(['encargo' => $this->menu->construirEncargo()]);
        $this->menu->importarEntrega($this->entregaValida());
    }

    /** Envia el texto (y las partes extra) y devuelve la ultima respuesta del servicio, ya completa. @param  list<array<string, mixed>>  $extra */
    protected function preguntar(string $texto, array $extra = []): ChatMensaje
    {
        $partes = ($texto === '' ? [] : [['tipo' => 'texto', 'texto' => $texto]]) + [];
        $this->api('POST', 'mensajes', ['content' => [...$partes, ...$extra]])->assertOk();

        return $this->ultimo();
    }

    protected function ultimo(): ChatMensaje
    {
        return ChatMensaje::query()->orderByDesc('sequence')->firstOrFail();
    }

    /** Sube una imagen y devuelve su parte de envio y su hash. @return array{0: array<string, mixed>, 1: string} */
    protected function imagen(string $semilla = ''): array
    {
        $bytes = $this->bytesPng().$semilla;
        $this->subir($bytes)->assertOk();

        return [$this->parteImagen($bytes), hash('sha3-256', $bytes)];
    }

    /** El texto de la respuesta cumple chat.md: es COMPLETED, no pasa de 3 sugerencias de 64 caracteres y sus bloques son validos. */
    protected function comprobarRespuesta(ChatMensaje $respuesta): string
    {
        $this->assertTrue($respuesta->from_service);
        $this->assertSame('COMPLETED', $respuesta->estado->value);
        $texto = $respuesta->texto();
        $this->assertSame([], Validador::errores($texto), 'bloques degradados en: '.$texto);
        $this->assertTrue(Validador::esValido($texto), 'los bloques no estan en forma canonica: '.$texto);
        $this->assertLessThanOrEqual(2, substr_count($texto, '```keyboard'));
        $this->assertLessThanOrEqual(3, count($respuesta->sugerencias ?? []));
        foreach ($respuesta->sugerencias ?? [] as $sugerencia) {
            $this->assertLessThanOrEqual(64, mb_strlen($sugerencia));
        }

        return $texto;
    }
}
