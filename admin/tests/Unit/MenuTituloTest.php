<?php

namespace Tests\Unit;

use App\Models\Menu;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MenuTituloTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function casos(): array
    {
        return [
            'mismo mes' => ['2026-11-02', 'Semana 45 · del 2 al 8 de noviembre'],
            'cruza de mes' => ['2026-10-28', 'Semana 45 · del 28 de octubre al 3 de noviembre'],
            'cruza de año' => ['2026-12-29', 'Semana 45 · del 29 de diciembre al 4 de enero'],
        ];
    }

    #[DataProvider('casos')]
    public function test_el_titulo_lleva_el_rango_de_fechas(string $inicio, string $esperado): void
    {
        config(['agente.menu.dias' => 7]);

        $menu = new Menu(['nombre' => 'Semana 45', 'fecha_inicio' => $inicio]);

        $this->assertSame($esperado, $menu->titulo());
    }

    public function test_el_fin_depende_de_los_dias_configurados(): void
    {
        config(['agente.menu.dias' => 3]);

        $this->assertSame('M · del 2 al 4 de noviembre', (new Menu(['nombre' => 'M', 'fecha_inicio' => '2026-11-02']))->titulo());
    }
}
