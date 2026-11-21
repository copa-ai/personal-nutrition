<?php

namespace Tests\Feature;

use App\Jobs\LanzarCoder;
use App\Models\Ajuste;
use App\Services\Coder\Coder;
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
        $this->app->instance(Coder::class, new Coder($this->nodo, $this->lector, new SondaSshFalsa));
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
}
