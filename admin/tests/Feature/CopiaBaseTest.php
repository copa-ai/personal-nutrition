<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CopiaBase;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

// DatabaseMigrations y no RefreshDatabase: VACUUM INTO no puede ejecutarse dentro de una transaccion.
class CopiaBaseTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        // Exportar e importar el fichero .sqlite solo existe con SQLite (con PostgreSQL se descarga un volcado SQL: CopiaVolcadoSqlTest).
        $this->afectaASqlite = DB::connection()->getDriverName() === 'sqlite';
    }

    private bool $afectaASqlite = true;

    private function exigirSqlite(): void
    {
        if (! $this->afectaASqlite) {
            $this->markTestSkipped('Solo con SQLite.');
        }
    }

    public function test_exportar_e_importar_devuelve_los_datos_de_la_copia(): void
    {
        $this->exigirSqlite();
        User::factory()->create(['email' => 'antes@demo.com']);
        $ruta = app(CopiaBase::class)->exportar();

        User::query()->delete();
        User::factory()->create(['email' => 'despues@demo.com']);

        $resumen = app(CopiaBase::class)->importar($ruta);
        @unlink($ruta);

        $this->assertSame(1, $resumen['users']);
        $this->assertSame(['antes@demo.com'], User::query()->pluck('email')->all());
    }

    public function test_importar_rechaza_un_archivo_que_no_es_sqlite(): void
    {
        $this->exigirSqlite();
        $ruta = tempnam(sys_get_temp_dir(), 'falso');
        file_put_contents($ruta, 'esto no es una base de datos');

        $this->expectException(RuntimeException::class);
        try {
            app(CopiaBase::class)->importar($ruta);
        } finally {
            @unlink($ruta);
        }
    }

    public function test_usuario_inicial_solo_se_crea_si_no_hay_usuarios(): void
    {
        $this->artisan('usuario:inicial')->assertSuccessful();
        $this->assertSame(['admin@demo.com'], User::query()->pluck('email')->all());

        User::query()->update(['email' => 'otro@demo.com']);
        $this->artisan('usuario:inicial')->assertSuccessful();
        $this->assertSame(['otro@demo.com'], User::query()->pluck('email')->all());
    }
}
