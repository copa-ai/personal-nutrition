<?php

namespace Tests\Feature;

use App\Services\Claude\LimiteUso;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class LimiteUsoTest extends TestCase
{
    private function ahora(string $fecha = '2026-11-01 10:00:00'): CarbonImmutable
    {
        return CarbonImmutable::parse($fecha, 'UTC');
    }

    public function test_un_texto_normal_no_es_un_limite(): void
    {
        $this->assertNull(LimiteUso::renovacion('Menú entregado', $this->ahora()));
        $this->assertNull(LimiteUso::renovacion('', $this->ahora()));
    }

    public function test_hora_de_renovacion_posterior_el_mismo_dia(): void
    {
        $r = LimiteUso::renovacion("You've hit your session limit · resets 3:20pm (UTC)", $this->ahora());

        $this->assertSame('2026-11-01 15:20:00', $r->toDateTimeString());
    }

    public function test_hora_de_renovacion_ya_pasada_se_pasa_a_manana(): void
    {
        $r = LimiteUso::renovacion("You've hit your session limit · resets 3:20pm (UTC)", $this->ahora('2026-11-01 16:00:00'));

        $this->assertSame('2026-11-02 15:20:00', $r->toDateTimeString());
    }

    public function test_medianoche_y_mediodia(): void
    {
        $this->assertSame('2026-11-02 00:00:00', LimiteUso::renovacion('You hit your usage limit. resets 12am (UTC)', $this->ahora())->toDateTimeString());
        $this->assertSame('2026-11-01 12:00:00', LimiteUso::renovacion('You hit your usage limit. resets 12pm (UTC)', $this->ahora())->toDateTimeString());
    }

    public function test_con_mes_y_dia(): void
    {
        $r = LimiteUso::renovacion('You hit your weekly limit · resets Nov 5, 9am (UTC)', $this->ahora());

        $this->assertSame('2026-11-05 09:00:00', $r->toDateTimeString());
    }

    public function test_respeta_la_zona_horaria_del_aviso(): void
    {
        // 15:20 en Madrid (UTC+1 en noviembre) son las 14:20 UTC.
        $r = LimiteUso::renovacion("You've hit your session limit · resets 3:20pm (Europe/Madrid)", $this->ahora());

        $this->assertSame('2026-11-01 14:20:00', $r->toDateTimeString());
    }

    public function test_si_no_se_entiende_la_hora_se_espera_el_tiempo_por_defecto(): void
    {
        $r = LimiteUso::renovacion('Usage limit reached. Try again later.', $this->ahora(), 45);

        $this->assertSame('2026-11-01 10:45:00', $r->toDateTimeString());
    }

    public function test_una_zona_desconocida_tambien_cae_al_tiempo_por_defecto(): void
    {
        $r = LimiteUso::renovacion("You've hit your session limit · resets 3pm (Marte/Olimpo)", $this->ahora(), 30);

        $this->assertSame('2026-11-01 10:30:00', $r->toDateTimeString());
    }
}
