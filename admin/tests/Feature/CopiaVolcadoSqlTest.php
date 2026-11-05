<?php

namespace Tests\Feature;

use App\Services\CopiaBase;
use PHPUnit\Framework\TestCase;

class CopiaVolcadoSqlTest extends TestCase
{
    public function test_literal_escapa_los_tipos_basicos(): void
    {
        $this->assertSame('NULL', CopiaBase::literal(null));
        $this->assertSame('TRUE', CopiaBase::literal(true));
        $this->assertSame('FALSE', CopiaBase::literal(false));
        $this->assertSame('42', CopiaBase::literal(42));
        $this->assertSame('1.5', CopiaBase::literal(1.5));
        $this->assertSame("'NaN'", CopiaBase::literal(NAN));
        $this->assertSame("'12.50'", CopiaBase::literal('12.50'));
    }

    public function test_literal_escapa_comillas_y_barras(): void
    {
        $this->assertSame("'it''s'", CopiaBase::literal("it's"));
        $this->assertSame("E'a\\\\b'", CopiaBase::literal('a\\b'));
        $this->assertSame("'{\"a\": \"x\"}'", CopiaBase::literal('{"a": "x"}', 'jsonb'));
        $this->assertSame("'ab'", CopiaBase::literal("a\0b"));
    }

    public function test_literal_convierte_bytea_a_hex(): void
    {
        $this->assertSame("decode('0001ff','hex')", CopiaBase::literal("\x00\x01\xff", 'bytea'));

        $flujo = fopen('php://memory', 'r+');
        fwrite($flujo, 'AB');
        rewind($flujo);
        $this->assertSame("decode('4142','hex')", CopiaBase::literal($flujo));
    }

    public function test_identificador_se_entrecomilla(): void
    {
        $this->assertSame('"a""b"', CopiaBase::identificador('a"b'));
    }

    public function test_ordenar_pone_las_referenciadas_primero(): void
    {
        $orden = CopiaBase::ordenar(['menus', 'hogares', 'users'], ['menus' => ['hogares'], 'hogares' => ['users', 'hogares']]);

        $this->assertSame(['users', 'hogares', 'menus'], $orden);
    }

    public function test_ordenar_tolera_ciclos(): void
    {
        $orden = CopiaBase::ordenar(['a', 'b'], ['a' => ['b'], 'b' => ['a']]);

        $this->assertEqualsCanonicalizing(['a', 'b'], $orden);
    }
}
