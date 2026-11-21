<?php

namespace Tests\Feature;

use App\Jobs\LanzarCoder;
use App\Models\Ajuste;
use App\Models\CoderEstado;
use App\Services\Coder\Coder;
use App\Services\Coder\Saldo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\LectorEspecificacionFalso;
use Tests\Support\NodoFalsoCoder;
use Tests\Support\SondaSshFalsa;
use Tests\TestCase;

/** `nutrition:vigilar-coder` and the job that starts the Coder, with a fake node. */
class VigilarCoderTest extends TestCase
{
    use RefreshDatabase;

    private const HASH = 'a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4';

    private string $carpeta;

    private NodoFalsoCoder $nodo;

    private LectorEspecificacionFalso $lector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->carpeta = sys_get_temp_dir().'/vigilar-coder-'.uniqid();
        mkdir($this->carpeta.'/coder', 0777, true);
        mkdir($this->carpeta.'/proyecto', 0777, true);
        config([
            'coder.directorio' => $this->carpeta.'/coder',
            'coder.secretos' => $this->carpeta.'/secretos',
            'coder.proyecto' => $this->carpeta.'/proyecto',
            'coder.lanzamiento.espera_ssh_s' => 0,
            'coder.lanzamiento.pausa_ssh_s' => 0,
        ]);
        $this->nodo = new NodoFalsoCoder;
        $this->lector = new LectorEspecificacionFalso;
        $coder = new Coder($this->nodo, $this->lector, new SondaSshFalsa);
        $this->app->instance(Coder::class, $coder);
        $this->app->instance(Saldo::class, new Saldo($this->nodo, $coder));
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->carpeta));
        parent::tearDown();
    }

    public function test_sin_coder_el_comando_termina_bien_y_quita_un_destino_viejo(): void
    {
        file_put_contents($this->carpeta.'/coder/destino.json', '{}');

        $this->artisan('nutrition:vigilar-coder')->expectsOutput('Coder: ninguno')->assertSuccessful();

        $this->assertFileDoesNotExist($this->carpeta.'/coder/destino.json');
    }

    public function test_con_el_coder_muerto_quita_destino_y_marca_error(): void
    {
        app(Coder::class)->lanzar(self::HASH);
        $this->nodo->pool = [];

        $this->artisan('nutrition:vigilar-coder')->expectsOutput('Coder: muerto')->assertSuccessful();

        $this->assertFileDoesNotExist($this->carpeta.'/coder/destino.json');
        $this->assertSame(Coder::ERROR, app(Coder::class)->estado()['estado']);
    }

    public function test_con_el_coder_vivo_no_cambia_nada(): void
    {
        app(Coder::class)->lanzar(self::HASH);

        $this->artisan('nutrition:vigilar-coder')->expectsOutput('Coder: vivo')->assertSuccessful();

        $this->assertFileExists($this->carpeta.'/coder/destino.json');
    }

    public function test_el_trabajo_lanza_el_coder(): void
    {
        (new LanzarCoder(self::HASH))->handle(app(Coder::class));

        $this->assertSame(Coder::EN_MARCHA, app(Coder::class)->estado()['estado']);
    }

    public function test_el_trabajo_no_falla_si_el_lanzamiento_se_rechaza(): void
    {
        $this->lector->tags = [];

        (new LanzarCoder(self::HASH))->handle(app(Coder::class));

        $this->assertSame(Coder::ERROR, app(Coder::class)->estado()['estado']);
        $this->assertStringContainsString('«project»', (string) Ajuste::obtener('coder.error'));
    }

    public function test_sin_la_opcion_saldo_no_se_lee_el_saldo(): void
    {
        app(Coder::class)->lanzar(self::HASH);

        $this->artisan('nutrition:vigilar-coder')->assertSuccessful();

        $this->assertSame([], $this->nodo->de('cuenta'));
    }

    public function test_con_la_opcion_saldo_toma_una_muestra_y_dice_que_hizo(): void
    {
        app(Coder::class)->lanzar(self::HASH);

        $this->artisan('nutrition:vigilar-coder --saldo')
            ->expectsOutput('Coder: vivo')
            ->expectsOutputToContain('Saldo: rechazado')   // no samples yet: the life of the service is unknown
            ->assertSuccessful();

        // One read for the sample and one inside the lock of the transfer (the first reading can be old).
        $this->assertCount(2, $this->nodo->de('cuenta'));
        $this->assertSame([], $this->nodo->de('depositar'));
    }

    public function test_con_la_opcion_saldo_y_todo_en_orden_recarga_al_coder(): void
    {
        app(Coder::class)->lanzar(self::HASH);
        $ahora = now()->getTimestamp();
        $m = [];
        for ($i = 5; $i >= 1; $i--) {
            $m[] = ['t' => $ahora - $i * 300, 'saldo' => 1_000_000 + $i * 300, 'dep' => 0];
        }
        CoderEstado::fijar('coder.muestras_saldo', $m);

        $this->artisan('nutrition:vigilar-coder --saldo')->expectsOutputToContain('Saldo: enviado 128600 MU')->assertSuccessful();

        $this->assertSame('129600', $this->nodo->saldoCoder);
    }

    public function test_con_la_opcion_saldo_y_el_nodo_caido_termina_bien(): void
    {
        app(Coder::class)->lanzar(self::HASH);
        $this->nodo->saldoNutrition = null;

        $this->artisan('nutrition:vigilar-coder --saldo')->expectsOutputToContain('Saldo: sin_nodo')->assertSuccessful();
    }

    public function test_un_fallo_inesperado_del_saldo_se_escribe_y_no_rompe_el_comando(): void
    {
        $coder = app(Coder::class);
        $this->app->instance(Saldo::class, new class($this->nodo, $coder) extends Saldo
        {
            public function vigilar(): array
            {
                throw new \RuntimeException('fallo raro');
            }
        });

        $this->artisan('nutrition:vigilar-coder --saldo')
            ->expectsOutputToContain('No se pudo vigilar el saldo del Coder: fallo raro')
            ->assertSuccessful();
    }

    public function test_con_el_coder_muerto_la_opcion_saldo_quita_el_destino_y_no_recarga(): void
    {
        app(Coder::class)->lanzar(self::HASH);
        $this->nodo->pool = [];

        $this->artisan('nutrition:vigilar-coder --saldo')->expectsOutput('Coder: muerto')->assertSuccessful();

        $this->assertFileDoesNotExist($this->carpeta.'/coder/destino.json');
        $this->assertSame([], $this->nodo->de('depositar'));
    }
}
