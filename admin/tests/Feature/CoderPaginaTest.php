<?php

namespace Tests\Feature;

use App\Filament\Pages\CoderPagina;
use App\Jobs\LanzarCoder;
use App\Models\Ajuste;
use App\Models\Auditoria;
use App\Models\User;
use App\Services\Coder\Coder;
use App\Services\Coder\Saldo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Livewire\Exceptions\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\LectorEspecificacionFalso;
use Tests\Support\NodoFalsoCoder;
use Tests\Support\SondaSshFalsa;
use Tests\TestCase;

/** The Coder panel page with a fake node, a fake spec reader and a fake SSH test. No process and no socket. */
class CoderPaginaTest extends TestCase
{
    use RefreshDatabase;

    private const HASH = 'a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4';

    private string $carpeta;

    private NodoFalsoCoder $nodo;

    private LectorEspecificacionFalso $lector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->carpeta = sys_get_temp_dir().'/coderpag-'.uniqid();
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
        $this->actingAs(User::create(['name' => 'Admin', 'email' => 'a@b.c', 'password' => 'password']));
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->carpeta));
        parent::tearDown();
    }

    private function pagina(): Testable
    {
        return Livewire::test(CoderPagina::class);
    }

    private function archivo(string $nombre): string
    {
        return $this->carpeta.'/coder/'.$nombre;
    }

    /** A key line with a valid blob (same method as ClavesSshTest). */
    private function clave(string $tipo = 'ssh-ed25519'): string
    {
        return $tipo.' '.base64_encode(pack('N', strlen($tipo)).$tipo.pack('N', 32).str_repeat('a', 32)).' yo@pc';
    }

    private function lanzado(): void
    {
        app(Coder::class)->lanzar(self::HASH);
    }

    /** Five samples, 300 s apart, with a burn of 1 MU/s (the method of SaldoTest). */
    private function muestras(): void
    {
        $ahora = now()->getTimestamp();
        $m = [];
        for ($i = 5; $i >= 1; $i--) {
            $m[] = ['t' => $ahora - $i * 300, 'saldo' => 1_000_000 + $i * 300, 'dep' => 0];
        }
        Ajuste::fijar('coder.muestras_saldo', $m);
    }

    // --- View ---

    public function test_se_muestra_sin_coder_con_el_bloque_de_conexion(): void
    {
        $this->pagina()->assertOk()
            ->assertSee('Sin Coder')
            ->assertSee('Lanzar un Coder')
            ->assertSee('nodo tunnel <instancia-de-esta-app> 2222 --listen 2222', false)
            ->assertSee('HostKeyAlias nutrition-coder')
            ->assertSee('Remote-SSH')
            ->assertSee('desconocidas');
    }

    public function test_el_estado_de_recarga_ausente_o_roto_no_rompe_la_pagina(): void
    {
        $this->pagina()->assertOk()->assertSee('sin datos');

        file_put_contents($this->archivo('recarga.json'), '{"estado": ');
        $this->pagina()->assertOk()->assertSee('sin datos');

        file_put_contents($this->archivo('recarga.json'), '[1,2,3]');
        $this->pagina()->assertOk()->assertSee('sin datos');

        file_put_contents($this->archivo('recarga.json'), json_encode(['estado' => ['x'], 'resultado' => 'otro', 'mensaje' => 7, 'cambios' => 'muchos', 'arbol_vivo' => '<script>']));
        $this->pagina()->assertOk()->assertDontSee('<script>', false);
    }

    public function test_muestra_el_resultado_de_la_ultima_recarga(): void
    {
        file_put_contents($this->archivo('recarga.json'), json_encode([
            'estado' => 'vigilando', 'resultado' => 'error_sintaxis', 'mensaje' => 'PHP Parse error en app/X.php', 'fecha' => '2026-11-21T10:00:00Z',
            'pendiente' => false, 'cambios' => 3, 'igual_original' => false, 'arbol_vivo' => str_repeat('a', 64), 'ultima_buena' => null, 'recargas' => 0,
        ]));

        $this->pagina()->assertSee('No se recargó: error de sintaxis PHP')->assertSee('PHP Parse error en app/X.php')->assertSee('3 fichero(s) distintos');
    }

    // --- Launch ---

    public function test_rechaza_un_servicio_sin_la_etiqueta_de_invitado(): void
    {
        Queue::fake();
        $this->lector->tags = ['otra'];

        $p = $this->pagina()->set('referencia', self::HASH)->call('preparar');
        $this->assertStringContainsString('no declara una carpeta compartida', $p->get('preparacion')['bloqueo']);
        $p->assertSee('NO declarada');

        $p->call('lanzar')->assertNotified('No se pudo completar la acción');
        Queue::assertNotPushed(LanzarCoder::class);
    }

    public function test_rechaza_si_no_se_puede_leer_la_especificacion(): void
    {
        Queue::fake();
        $this->lector->tags = null;

        $p = $this->pagina()->set('referencia', self::HASH)->call('preparar');
        $this->assertStringContainsString('No se puede leer la especificación', $p->get('preparacion')['bloqueo']);
        $p->call('lanzar');
        Queue::assertNotPushed(LanzarCoder::class);
    }

    public function test_con_la_opcion_confirmar_hace_falta_marcar_la_casilla(): void
    {
        Queue::fake();
        config(['coder.spec_desconocida' => 'confirmar']);
        $this->lector->tags = null;

        $p = $this->pagina()->set('referencia', self::HASH)->call('preparar');
        $this->assertNull($p->get('preparacion')['bloqueo']);
        $p->call('lanzar')->assertNotified('No se pudo completar la acción');
        Queue::assertNotPushed(LanzarCoder::class);

        $p->set('confirmaSinSpec', true)->call('lanzar');
        Queue::assertPushed(LanzarCoder::class, fn (LanzarCoder $j): bool => $j->confirmaSinSpec === true && $j->referencia === self::HASH);
    }

    public function test_un_hash_mal_escrito_da_un_aviso_y_no_una_excepcion(): void
    {
        $this->pagina()->set('referencia', 'abc')->call('preparar')->assertNotified('No se pudo completar la acción')->assertSet('preparacion', null);
    }

    public function test_el_paso_de_confirmacion_muestra_coste_y_recursos_y_no_lanza_nada(): void
    {
        Queue::fake();

        $this->pagina()->set('referencia', self::HASH)->call('preparar')
            ->assertSee('Revisa antes de lanzar')
            ->assertSee('1.000 MU')   // cost_mu of the fake node
            ->assertSee('3 GiB de memoria');
        Queue::assertNothingPushed();
        $this->assertSame([], $this->nodo->de('adquirirPorHash'));
    }

    public function test_confirmar_pone_el_trabajo_en_la_cola_con_el_maximo_del_arranque(): void
    {
        Queue::fake();

        $this->pagina()->set('referencia', strtoupper(self::HASH))->call('preparar')
            ->set('inicialMu', '50000')->callAction('confirmarLanzamiento')->assertNotified('Lanzamiento en marcha');
        Queue::assertPushed(LanzarCoder::class, fn (LanzarCoder $j): bool => $j->referencia === self::HASH && $j->inicialMu === '50000');
    }

    public function test_un_maximo_de_arranque_malo_no_lanza(): void
    {
        Queue::fake();

        $this->pagina()->set('referencia', self::HASH)->call('preparar')->set('inicialMu', '-5')->call('lanzar')
            ->assertNotified('No se pudo completar la acción');
        Queue::assertNothingPushed();
    }

    public function test_el_flujo_completo_con_la_cola_sincrona_deja_el_coder_en_marcha(): void
    {
        $this->pagina()->set('referencia', self::HASH)->call('preparar')->callAction('confirmarLanzamiento');

        $this->assertSame('running', Ajuste::obtener('coder.estado'));
        $this->assertCount(1, $this->nodo->de('adquirirPorHash'));
        $this->assertFileExists($this->archivo('destino.json'));
        $this->pagina()->assertSee('En marcha')->assertSee(self::HASH);
    }

    public function test_el_estado_de_preparacion_no_se_puede_cambiar_desde_el_navegador(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        $this->pagina()->set('preparacion', ['bloqueo' => null, 'id' => 'x']);
    }

    public function test_sube_un_paquete_y_lo_guarda_con_un_nombre_propio(): void
    {
        $p = $this->pagina()->set('paquete', UploadedFile::fake()->create('mi-editor.celaut.bee', 20));

        $ruta = $p->get('rutaPaquete');
        $this->assertNotNull($ruta);
        $this->assertStringStartsWith($this->carpeta.'/coder/paquetes/', $ruta);
        $this->assertStringNotContainsString('mi-editor', $ruta);
        $this->assertFileExists($ruta);
        $p->assertSet('paquete', null);

        $p->call('preparar')->assertSet('preparacion.tipo', 'paquete');
    }

    public function test_rechaza_una_extension_mala_y_un_fichero_demasiado_grande(): void
    {
        $this->pagina()->set('paquete', UploadedFile::fake()->create('malo.zip', 10))
            ->assertSet('rutaPaquete', null)->assertNotified('No se pudo completar la acción');

        config(['coder.paquete.tamano_maximo_mb' => 1]);
        $this->pagina()->set('paquete', UploadedFile::fake()->create('grande.celaut.bee', 2048))
            ->assertSet('rutaPaquete', null)->assertNotified('No se pudo completar la acción');
        $this->assertSame([], glob($this->carpeta.'/coder/paquetes/*') ?: []);
    }

    // --- Stop and balance ---

    public function test_parar_pide_confirmacion_y_limpia_el_estado(): void
    {
        $this->lanzado();

        $this->pagina()->callAction('parar')->assertNotified('Coder parado');
        $this->assertSame('none', app(Coder::class)->estado()['estado']);
        $this->assertSame([[5]], $this->nodo->de('parar'));
        $this->assertFileDoesNotExist($this->archivo('destino.json'));
    }

    public function test_si_el_nodo_no_deja_parar_se_ve_el_aviso_y_la_pagina_sigue(): void
    {
        $this->lanzado();
        $this->nodo->rechazoParada = 'busy';

        $this->pagina()->callAction('parar')->assertNotified('No se pudo completar la acción')->assertSee('Error');
        $this->assertSame('error', app(Coder::class)->estado()['estado']);
    }

    public function test_anadir_saldo_sin_historial_se_rechaza_con_el_motivo(): void
    {
        $this->lanzado();

        $this->pagina()->callAction('anadirSaldo', ['monto' => '1000'])->assertNotified('No se ha enviado el saldo');
        $this->assertSame([], $this->nodo->de('depositar'));
        $rechazo = Ajuste::obtener('coder.ultimo_rechazo');
        $this->assertNotEmpty($rechazo['control']);
        $this->assertSame(1, Auditoria::query()->where('evento', 'coder.saldo_rechazado')->count());
        $this->pagina()->assertSee($rechazo['motivo']);
    }

    public function test_anadir_saldo_que_pasa_los_controles_lo_envia(): void
    {
        $this->lanzado();
        $this->muestras();

        $this->pagina()->callAction('anadirSaldo', ['monto' => '5000'])->assertNotified('Saldo enviado');
        $this->assertSame([[5, '5000']], $this->nodo->de('depositar'));
    }

    public function test_anadir_saldo_con_una_cantidad_mala_se_rechaza(): void
    {
        $this->lanzado();
        $this->muestras();

        $this->pagina()->callAction('anadirSaldo', ['monto' => '1e9'])->assertNotified('No se ha enviado el saldo');
        $this->assertSame([], $this->nodo->de('depositar'));
    }

    public function test_los_limites_se_guardan_y_se_auditan(): void
    {
        $this->pagina()->set('tope', '999000')->set('porcentaje', '30')->set('horasMinimas', '72')->call('guardarLimites')
            ->assertNotified('Límites guardados');

        $this->assertSame('999000', Ajuste::obtener('coder.tope_mu'));
        $this->assertSame(30, Ajuste::obtener('coder.porcentaje_max'));
        $this->assertSame(72, Ajuste::obtener('coder.horas_minimas'));
        $this->assertGreaterThan(0, Auditoria::query()->where('evento', 'ajuste.cambiado')->count());
    }

    public function test_un_limite_malo_no_cambia_nada(): void
    {
        $this->pagina()->set('tope', 'mucho')->call('guardarLimites')->assertNotified('No se pudo completar la acción');
        $this->pagina()->set('porcentaje', '150')->call('guardarLimites')->assertNotified('No se pudo completar la acción');

        $this->assertNull(Ajuste::obtener('coder.porcentaje_max'));
        $this->assertNull(Ajuste::obtener('coder.tope_mu'));
    }

    // --- Keys ---

    public function test_guarda_las_claves_validas_en_el_fichero_del_coder(): void
    {
        $this->pagina()->set('claves', $this->clave()."\n".$this->clave('ecdsa-sha2-nistp256')."\n")->call('guardarClaves')
            ->assertNotified('Claves guardadas')->assertSet('erroresClaves', []);

        $destino = $this->carpeta.'/proyecto/.coder/authorized_keys';
        $this->assertSame($this->clave()."\n".$this->clave('ecdsa-sha2-nistp256')."\n", file_get_contents($destino));
        $this->assertSame([], glob($this->carpeta.'/proyecto/.coder/*.tmp*') ?: []);
    }

    public function test_las_claves_con_opciones_se_rechazan_con_el_numero_de_linea(): void
    {
        $p = $this->pagina()->set('claves', $this->clave()."\ncommand=\"rm -rf /\" ".$this->clave())->call('guardarClaves')
            ->assertNotified('Las claves no se han guardado');

        $errores = $p->get('erroresClaves');
        $this->assertCount(1, $errores);
        $this->assertStringStartsWith('Línea 2:', $errores[0]);
        $this->assertFileDoesNotExist($this->carpeta.'/proyecto/.coder/authorized_keys');
        $p->assertSee('Línea 2:');
    }

    public function test_mas_de_veinte_claves_se_rechazan(): void
    {
        $texto = '';
        for ($i = 0; $i < 21; $i++) {
            $texto .= 'ssh-ed25519 '.base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).str_repeat(chr(65 + $i), 32))."\n";
        }

        $p = $this->pagina()->set('claves', $texto)->call('guardarClaves')->assertNotified('Las claves no se han guardado');
        $this->assertNotEmpty($p->get('erroresClaves'));
    }

    // --- Reload flags ---

    public function test_recargar_ahora_escribe_la_marca(): void
    {
        $this->pagina()->callAction('recargarAhora')->assertNotified('Recarga pedida');

        $this->assertFileExists($this->archivo('recargar-ahora'));
        $this->assertFileDoesNotExist($this->archivo('restaurar-original'));
        $this->assertSame([], glob($this->archivo('*.tmp*')) ?: []);
        $this->pagina()->assertSee('recarga pedida');
    }

    public function test_restaurar_el_original_escribe_la_marca(): void
    {
        $this->pagina()->callAction('restaurarOriginal')->assertNotified('Restauración pedida');

        $this->assertFileExists($this->archivo('restaurar-original'));
        $this->assertFileDoesNotExist($this->archivo('recargar-ahora'));
    }

    public function test_el_interruptor_de_recarga_automatica_escribe_el_fichero_y_audita(): void
    {
        $p = $this->pagina()->assertSet('autoRecarga', true);

        $p->set('autoRecarga', false);
        $this->assertSame("0\n", file_get_contents($this->archivo('auto-recarga')));
        $p->set('autoRecarga', true);
        $this->assertSame("1\n", file_get_contents($this->archivo('auto-recarga')));
        $this->assertSame(2, Auditoria::query()->where('evento', 'coder.recarga_auto')->count());
        $this->pagina()->assertSet('autoRecarga', true);

        file_put_contents($this->archivo('auto-recarga'), "OFF\n");
        $this->pagina()->assertSet('autoRecarga', false);
    }

    public function test_sin_la_carpeta_de_control_las_acciones_avisan_y_no_rompen(): void
    {
        config(['coder.directorio' => $this->carpeta.'/no-existe']);

        $this->pagina()->callAction('recargarAhora')->assertNotified('No se pudo completar la acción')->assertOk();
    }
}
