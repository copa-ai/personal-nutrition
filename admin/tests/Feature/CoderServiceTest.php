<?php

namespace Tests\Feature;

use App\Models\Ajuste;
use App\Models\Auditoria;
use App\Services\Coder\Coder;
use App\Services\Coder\CoderException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\LectorEspecificacionFalso;
use Tests\Support\NodoFalsoCoder;
use Tests\Support\SondaSshFalsa;
use Tests\TestCase;

/** App\Services\Coder\Coder with a fake node, a fake SSH test and a fake spec reader. No process and no socket. */
class CoderServiceTest extends TestCase
{
    use RefreshDatabase;

    private const HASH = 'a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4';

    private string $carpeta;

    private NodoFalsoCoder $nodo;

    private SondaSshFalsa $sonda;

    private LectorEspecificacionFalso $lector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->carpeta = sys_get_temp_dir().'/coder-'.uniqid();
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
        $this->sonda = new SondaSshFalsa;
        $this->lector = new LectorEspecificacionFalso;
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->carpeta));
        parent::tearDown();
    }

    private function coder(): Coder
    {
        return new Coder($this->nodo, $this->lector, $this->sonda);
    }

    private function destino(): string
    {
        return $this->carpeta.'/coder/destino.json';
    }

    private function eventos(): array
    {
        return Auditoria::query()->orderBy('created_at')->pluck('evento')->all();
    }

    private function claveSsh(string $relleno = 'a'): string
    {
        return 'ssh-ed25519 '.base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).str_repeat($relleno, 32)).' yo@pc';
    }

    // --- reference ---

    public function test_acepta_un_hash_de_64_hex_en_mayusculas_o_minusculas(): void
    {
        $this->assertSame(['hash', self::HASH], $this->coder()->referencia(self::HASH));
        $this->assertSame(['hash', self::HASH], $this->coder()->referencia('  '.strtoupper(self::HASH)."\n"));
    }

    public function test_rechaza_hashes_cortos_largos_o_con_otros_caracteres(): void
    {
        foreach ([substr(self::HASH, 1), self::HASH.'a', str_replace('a', 'g', self::HASH), '', '../../etc/passwd', '/tmp/x.celaut.bee'] as $texto) {
            try {
                $this->coder()->referencia($texto);
                $this->fail("Debia rechazar: {$texto}");
            } catch (CoderException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_un_paquete_solo_se_acepta_si_esta_en_la_carpeta_de_paquetes(): void
    {
        $origen = $this->carpeta.'/x.celaut.bee';
        file_put_contents($origen, 'datos');
        $guardado = $this->coder()->guardarPaquete($origen, 'mi-coder.celaut.bee');

        $this->assertSame(['paquete', realpath($guardado['ruta'])], $this->coder()->referencia($guardado['ruta']));
        $this->assertSame($this->nodo->idPaquete, $guardado['id']);
        $this->assertSame(0600, fileperms($guardado['ruta']) & 0777);

        $this->expectException(CoderException::class);
        $this->coder()->referencia($origen);   // a real .celaut.bee file, but outside the packages directory
    }

    public function test_un_enlace_simbolico_fuera_de_la_carpeta_no_es_un_paquete(): void
    {
        mkdir($this->carpeta.'/coder/paquetes');
        file_put_contents($this->carpeta.'/fuera.celaut.bee', 'x');
        symlink($this->carpeta.'/fuera.celaut.bee', $this->carpeta.'/coder/paquetes/enlace.celaut.bee');

        $this->expectException(CoderException::class);
        $this->coder()->referencia($this->carpeta.'/coder/paquetes/enlace.celaut.bee');
    }

    public function test_guardar_paquete_rechaza_extension_vacio_y_exceso_de_tamano(): void
    {
        $origen = $this->carpeta.'/x.bin';
        file_put_contents($origen, 'datos');
        $casos = [
            fn () => $this->coder()->guardarPaquete($origen, 'x.zip'),
            fn () => $this->coder()->guardarPaquete($this->carpeta.'/no-existe', 'x.celaut.bee'),
            function () use ($origen) {
                config(['coder.paquete.tamano_maximo_mb' => 0]);
                $this->coder()->guardarPaquete($origen, 'x.celaut.bee');
            },
        ];
        foreach ($casos as $caso) {
            try {
                $caso();
                $this->fail('Debia rechazar.');
            } catch (CoderException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame([], glob($this->carpeta.'/coder/paquetes/*') ?: []);
    }

    public function test_guardar_paquete_borra_el_fichero_si_el_id_no_es_valido(): void
    {
        $origen = $this->carpeta.'/x.bin';
        file_put_contents($origen, 'datos');
        $this->nodo->idPaquete = 'no-es-un-hash';

        try {
            $this->coder()->guardarPaquete($origen, 'x.celaut.bee');
            $this->fail('Debia rechazar.');
        } catch (CoderException) {
            $this->assertSame([], glob($this->carpeta.'/coder/paquetes/*') ?: []);
        }
    }

    // --- confirm step ---

    public function test_preparar_da_el_coste_y_el_limite_de_recursos(): void
    {
        $r = $this->coder()->preparar(self::HASH);

        $this->assertSame('hash', $r['tipo']);
        $this->assertSame(self::HASH, $r['id']);
        $this->assertSame('1000', $r['coste']['cost_mu']);
        $this->assertNull($r['aviso_coste']);
        $this->assertNull($r['recursos']['conocidos']);
        $this->assertSame(3, $r['recursos']['limite']['memoria_gib']);
        $this->assertTrue($r['spec_leida']);
        $this->assertTrue($r['spec_declara_share']);
    }

    public function test_preparar_sin_coste_da_un_aviso_y_no_falla(): void
    {
        $this->nodo->rechazoCoste = 'el nodo no tiene el servicio';
        $this->lector->tags = null;

        $r = $this->coder()->preparar(self::HASH);

        $this->assertNull($r['coste']);
        $this->assertSame('el nodo no tiene el servicio', $r['aviso_coste']);
        $this->assertFalse($r['spec_leida']);
        $this->assertNull($r['spec_declara_share']);
    }

    // --- spec gate ---

    public function test_no_lanza_si_no_se_puede_leer_la_especificacion(): void
    {
        $this->lector->tags = null;

        try {
            $this->coder()->lanzar(self::HASH);
            $this->fail('Debia rechazar.');
        } catch (CoderException $e) {
            $this->assertStringContainsString('No se puede leer la especificación', $e->getMessage());
        }
        $this->assertSame([], $this->nodo->de('adquirirPorHash'));
        $this->assertSame(Coder::NINGUNO, $this->coder()->estado()['estado']);
        $this->assertFileDoesNotExist($this->carpeta.'/secretos/ssh_host_ed25519');
    }

    public function test_la_confirmacion_solo_vale_si_la_configuracion_lo_permite(): void
    {
        $this->lector->tags = null;
        $this->expectException(CoderException::class);
        $this->coder()->lanzar(self::HASH, confirmaSinSpec: true);   // coder.spec_desconocida is "rechazar"
    }

    public function test_con_confirmar_y_confirmacion_lanza_sin_leer_la_especificacion(): void
    {
        config(['coder.spec_desconocida' => 'confirmar']);
        $this->lector->tags = null;

        $this->assertSame(Coder::EN_MARCHA, $this->coder()->lanzar(self::HASH, confirmaSinSpec: true)['estado']);
    }

    public function test_no_lanza_si_la_especificacion_no_declara_el_tag_project(): void
    {
        foreach ([[], ['otro'], ['Project'], ['project-x']] as $tags) {
            $this->lector->tags = $tags;
            try {
                $this->coder()->lanzar(self::HASH);
                $this->fail('Debia rechazar.');
            } catch (CoderException $e) {
                $this->assertStringContainsString('«project»', $e->getMessage());
            }
        }
        $this->assertSame([], $this->nodo->de('adquirirPorHash'));
    }

    // --- launch ---

    public function test_lanza_por_hash_y_deja_estado_destino_y_claves(): void
    {
        $r = $this->coder()->lanzar(self::HASH);

        $this->assertSame(Coder::EN_MARCHA, $r['estado']);
        $this->assertSame(5, $r['lease']);
        $this->assertSame('10.0.0.7:22', $r['uri']);
        $this->assertSame(self::HASH, $r['servicio']);
        $this->assertTrue($r['destino']);
        $this->assertSame([['10.0.0.7', 22]], $this->sonda->pruebas);

        $destino = json_decode((string) file_get_contents($this->destino()), true);
        $this->assertSame('10.0.0.7', $destino['host']);
        $this->assertSame(22, $destino['port']);
        $this->assertSame(0644, fileperms($this->destino()) & 0777);
        $this->assertSame([], glob($this->carpeta.'/coder/*.tmp*') ?: []);

        $this->assertFileExists($this->carpeta.'/proyecto/.coder/authorized_keys');
        $this->assertEqualsCanonicalizing(['coder.lanzamiento', 'coder.clave_host_creada', 'coder.lanzado'], array_values(array_filter($this->eventos(), fn ($e) => str_starts_with($e, 'coder.'))));
    }

    public function test_pasa_la_clave_del_servidor_en_base64_y_el_limite_inicial(): void
    {
        $this->coder()->lanzar(self::HASH, inicialMu: '123456');

        [$hash, $entorno, $inicial, $nuevo] = $this->nodo->de('adquirirPorHash')[0];
        $this->assertSame(self::HASH, $hash);
        $this->assertSame(['CODER_SSH_HOST_KEY'], array_keys($entorno));
        $this->assertStringStartsWith('-----BEGIN OPENSSH PRIVATE KEY-----', (string) base64_decode($entorno['CODER_SSH_HOST_KEY'], true));
        $this->assertSame('123456', $inicial);
        $this->assertFalse($nuevo);
    }

    public function test_la_clave_del_servidor_se_crea_una_vez_y_no_cambia_entre_lanzamientos(): void
    {
        $this->coder()->lanzar(self::HASH);
        $primera = $this->nodo->de('adquirirPorHash')[0][1]['CODER_SSH_HOST_KEY'];
        $this->assertSame(0600, fileperms($this->carpeta.'/secretos/ssh_host_ed25519') & 0777);
        $this->assertSame(0700, fileperms($this->carpeta.'/secretos') & 0777);

        $this->coder()->parar();
        $this->coder()->lanzar(self::HASH);

        $this->assertSame($primera, $this->nodo->de('adquirirPorHash')[1][1]['CODER_SSH_HOST_KEY']);
        $this->assertCount(1, array_filter($this->eventos(), fn ($e) => $e === 'coder.clave_host_creada'));
    }

    public function test_la_clave_privada_nunca_llega_a_la_auditoria_ni_a_los_ajustes(): void
    {
        $this->coder()->lanzar(self::HASH);
        $clave = $this->nodo->de('adquirirPorHash')[0][1]['CODER_SSH_HOST_KEY'];

        $todo = json_encode([Auditoria::all()->toArray(), Ajuste::all()->toArray()]);
        $this->assertStringNotContainsString($clave, $todo);
        $this->assertStringNotContainsString('PRIVATE KEY', $todo);
    }

    public function test_lanza_por_paquete(): void
    {
        $origen = $this->carpeta.'/x.bin';
        file_put_contents($origen, 'datos');
        $paquete = $this->coder()->guardarPaquete($origen, 'x.celaut.bee');

        $r = $this->coder()->lanzar($paquete['ruta']);

        $this->assertSame(Coder::EN_MARCHA, $r['estado']);
        $this->assertSame($this->nodo->idPaquete, $r['servicio']);
        $this->assertSame(realpath($paquete['ruta']), $this->nodo->de('adquirirPorPaquete')[0][0]);
        $this->assertSame([], $this->nodo->de('adquirirPorHash'));
    }

    public function test_un_segundo_lanzamiento_con_el_coder_en_marcha_se_rechaza(): void
    {
        $this->coder()->lanzar(self::HASH);

        $this->expectException(CoderException::class);
        $this->coder()->lanzar(self::HASH);
    }

    public function test_si_el_nodo_rechaza_el_lanzamiento_queda_en_error_sin_destino(): void
    {
        $this->nodo->rechazo = 'Insufficient balance';

        try {
            $this->coder()->lanzar(self::HASH);
            $this->fail('Debia fallar.');
        } catch (CoderException $e) {
            $this->assertStringContainsString('Insufficient balance', $e->getMessage());
        }

        $e = $this->coder()->estado();
        $this->assertSame(Coder::ERROR, $e['estado']);
        $this->assertNull($e['lease']);
        $this->assertStringContainsString('Insufficient balance', $e['error']);
        $this->assertFileDoesNotExist($this->destino());
        $this->assertContains('coder.lanzamiento_fallido', $this->eventos());

        // An error with no lease allows a new launch.
        $this->nodo->rechazo = null;
        $this->assertSame(Coder::EN_MARCHA, $this->coder()->lanzar(self::HASH)['estado']);
    }

    public function test_si_no_responde_ssh_para_la_instancia_y_no_escribe_destino(): void
    {
        $this->sonda->respuesta = 'HTTP/1.1 400';

        try {
            $this->coder()->lanzar(self::HASH);
            $this->fail('Debia fallar.');
        } catch (CoderException $e) {
            $this->assertStringContainsString('SSH', $e->getMessage());
        }

        $this->assertSame([[5]], $this->nodo->de('parar'));
        $this->assertSame(Coder::ERROR, $this->coder()->estado()['estado']);
        $this->assertNull($this->coder()->estado()['lease']);
        $this->assertFileDoesNotExist($this->destino());
    }

    public function test_si_no_hay_banner_tampoco_se_escribe_destino(): void
    {
        $this->sonda->respuesta = null;
        $this->expectException(CoderException::class);
        try {
            $this->coder()->lanzar(self::HASH);
        } finally {
            $this->assertFileDoesNotExist($this->destino());
        }
    }

    public function test_una_uri_sin_puerto_valido_cuenta_como_fallo(): void
    {
        $this->nodo->uri = 'sin-puerto';

        try {
            $this->coder()->lanzar(self::HASH);
            $this->fail('Debia fallar.');
        } catch (CoderException) {
            $this->assertSame([[5]], $this->nodo->de('parar'));
            $this->assertSame([], $this->sonda->pruebas);
        }
    }

    public function test_si_no_se_puede_escribir_en_la_carpeta_compartida_no_se_lanza(): void
    {
        config(['coder.proyecto' => '/proc/no-se-puede-escribir']);

        try {
            $this->coder()->lanzar(self::HASH);
            $this->fail('Debia fallar.');
        } catch (CoderException) {
            $this->assertSame([], $this->nodo->de('adquirirPorHash'));
        }
    }

    // --- stop ---

    public function test_parar_quita_destino_para_la_instancia_y_limpia_el_estado(): void
    {
        $this->coder()->lanzar(self::HASH);

        $r = $this->coder()->parar();

        $this->assertSame('77', $r['refund_mu']);
        $this->assertSame([[5]], $this->nodo->de('parar'));
        $this->assertFileDoesNotExist($this->destino());
        $e = $this->coder()->estado();
        $this->assertSame(Coder::NINGUNO, $e['estado']);
        $this->assertNull($e['lease']);
        $this->assertNull($e['servicio']);
        $this->assertContains('coder.parado', $this->eventos());
    }

    public function test_parar_sin_coder_no_hace_nada_y_no_falla(): void
    {
        $this->assertSame(['refund_mu' => null], $this->coder()->parar());
        $this->assertSame([], $this->nodo->de('parar'));
    }

    public function test_parar_conserva_las_claves_ssh_de_los_usuarios(): void
    {
        $this->coder()->guardarClaves($this->claveSsh());
        $this->coder()->lanzar(self::HASH);
        $this->coder()->parar();

        $this->assertCount(1, $this->coder()->claves());
    }

    public function test_si_el_nodo_rechaza_la_parada_queda_en_error_con_la_instancia(): void
    {
        $this->coder()->lanzar(self::HASH);
        $this->nodo->rechazoParada = 'busy';

        try {
            $this->coder()->parar();
            $this->fail('Debia fallar.');
        } catch (CoderException $e) {
            $this->assertStringContainsString('busy', $e->getMessage());
        }

        $e = $this->coder()->estado();
        $this->assertSame(Coder::ERROR, $e['estado']);
        $this->assertSame(5, $e['lease']);
        $this->assertFileDoesNotExist($this->destino());   // the SSH access closes even if the node refuses
        $this->assertContains('coder.parada_fallida', $this->eventos());

        // With the lease still there, a new launch is refused.
        try {
            $this->coder()->lanzar(self::HASH);
            $this->fail('Debia rechazar.');
        } catch (CoderException) {
            $this->addToAssertionCount(1);
        }

        $this->coder()->parar(forzar: true);
        $this->assertSame(Coder::NINGUNO, $this->coder()->estado()['estado']);
    }

    public function test_no_se_para_un_lanzamiento_en_curso_sin_forzar(): void
    {
        Ajuste::fijar('coder.estado', Coder::LANZANDO);
        Ajuste::fijar('coder.instancia', 'prueba');

        try {
            $this->coder()->parar();
            $this->fail('Debia rechazar.');
        } catch (CoderException) {
            $this->assertSame(Coder::LANZANDO, $this->coder()->estado()['estado']);
        }
        $this->coder()->parar(forzar: true);
        $this->assertSame(Coder::NINGUNO, $this->coder()->estado()['estado']);
    }

    // --- watcher ---

    public function test_vigilar_sin_coder_quita_un_destino_viejo(): void
    {
        file_put_contents($this->destino(), '{"host":"10.0.0.7","port":22}');

        $this->assertSame('ninguno', $this->coder()->vigilar());
        $this->assertFileDoesNotExist($this->destino());
        $this->assertSame([], $this->nodo->de('instancias'));
    }

    public function test_vigilar_deja_un_coder_vivo_como_esta(): void
    {
        $this->coder()->lanzar(self::HASH);

        $this->assertSame('vivo', $this->coder()->vigilar());
        $this->assertFileExists($this->destino());
        $this->assertSame(Coder::EN_MARCHA, $this->coder()->estado()['estado']);
    }

    public function test_vigilar_detecta_un_coder_que_murio(): void
    {
        $this->coder()->lanzar(self::HASH);
        $this->nodo->pool = [];

        $this->assertSame('muerto', $this->coder()->vigilar());

        $e = $this->coder()->estado();
        $this->assertSame(Coder::ERROR, $e['estado']);
        $this->assertNull($e['lease']);
        $this->assertNotNull($e['error']);
        $this->assertFileDoesNotExist($this->destino());
        $this->assertContains('coder.caido', $this->eventos());
        // The user can launch again.
        $this->assertSame(Coder::EN_MARCHA, $this->coder()->lanzar(self::HASH)['estado']);
    }

    public function test_vigilar_no_cambia_nada_si_el_nodo_no_responde(): void
    {
        $this->coder()->lanzar(self::HASH);
        $this->nodo->inalcanzable = true;

        $this->assertSame('sin_nodo', $this->coder()->vigilar());
        $this->assertSame(Coder::EN_MARCHA, $this->coder()->estado()['estado']);
        $this->assertFileExists($this->destino());
    }

    public function test_vigilar_da_por_colgado_un_lanzamiento_viejo(): void
    {
        Ajuste::fijar('coder.estado', Coder::LANZANDO);
        Ajuste::fijar('coder.instancia', 'prueba');
        Ajuste::fijar('coder.lanzado_en', now()->subHours(2)->toIso8601String());

        $this->assertSame('colgado', $this->coder()->vigilar());
        $this->assertSame(Coder::ERROR, $this->coder()->estado()['estado']);
    }

    public function test_vigilar_respeta_un_lanzamiento_reciente(): void
    {
        Ajuste::fijar('coder.estado', Coder::LANZANDO);
        Ajuste::fijar('coder.instancia', 'prueba');
        Ajuste::fijar('coder.lanzado_en', now()->toIso8601String());

        $this->assertSame('vivo', $this->coder()->vigilar());
        $this->assertSame(Coder::LANZANDO, $this->coder()->estado()['estado']);
    }

    public function test_un_coder_de_otro_dispositivo_no_se_toca(): void
    {
        $this->coder()->lanzar(self::HASH);
        Ajuste::fijar('coder.instancia', 'otro-dispositivo');
        $this->nodo->pool = [];

        $this->assertTrue($this->coder()->estado()['ajeno']);
        $this->assertSame('ajeno', $this->coder()->vigilar());
        $this->assertSame(Coder::EN_MARCHA, $this->coder()->estado()['estado']);
        foreach ([fn () => $this->coder()->parar(), fn () => $this->coder()->lanzar(self::HASH)] as $accion) {
            try {
                $accion();
                $this->fail('Debia rechazar.');
            } catch (CoderException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // --- state ---

    public function test_el_estado_inicial_es_ninguno(): void
    {
        $e = $this->coder()->estado();

        $this->assertSame(Coder::NINGUNO, $e['estado']);
        $this->assertNull($e['lease']);
        $this->assertNull($e['horas_vida']);
        $this->assertFalse($e['ajeno']);
        $this->assertFalse($e['destino']);
        $this->assertSame(0, $e['claves']);
    }

    public function test_un_valor_de_estado_desconocido_cuenta_como_ninguno(): void
    {
        Ajuste::fijar('coder.estado', 'raro');

        $this->assertSame(Coder::NINGUNO, $this->coder()->estado()['estado']);
    }

    // --- authorized keys ---

    public function test_guardar_claves_escribe_el_fichero_de_la_comparticion_y_los_ajustes(): void
    {
        $r = $this->coder()->guardarClaves($this->claveSsh('a')."\r\n".$this->claveSsh('b'));

        $this->assertSame([], $r['errores']);
        $ruta = $this->carpeta.'/proyecto/.coder/authorized_keys';
        $this->assertSame($this->claveSsh('a')."\n".$this->claveSsh('b')."\n", file_get_contents($ruta));
        $this->assertSame(0644, fileperms($ruta) & 0777);
        $this->assertSame([], glob($this->carpeta.'/proyecto/.coder/*.tmp*') ?: []);
        $this->assertCount(2, $this->coder()->claves());
        $this->assertContains('coder.claves_cambiadas', $this->eventos());
    }

    public function test_una_linea_mala_no_cambia_nada(): void
    {
        $this->coder()->guardarClaves($this->claveSsh('a'));
        $antes = file_get_contents($this->carpeta.'/proyecto/.coder/authorized_keys');

        $r = $this->coder()->guardarClaves($this->claveSsh('b')."\ncommand=\"id\" ".$this->claveSsh('c'));

        $this->assertNotSame([], $r['errores']);
        $this->assertSame($antes, file_get_contents($this->carpeta.'/proyecto/.coder/authorized_keys'));
        $this->assertSame([$this->claveSsh('a')], $this->coder()->claves());
    }

    public function test_texto_vacio_quita_todas_las_claves(): void
    {
        $this->coder()->guardarClaves($this->claveSsh('a'));
        $this->coder()->guardarClaves("  \n");

        $this->assertSame('', file_get_contents($this->carpeta.'/proyecto/.coder/authorized_keys'));
        $this->assertSame([], $this->coder()->claves());
    }

    public function test_guardar_las_mismas_claves_no_audita_otra_vez(): void
    {
        $this->coder()->guardarClaves($this->claveSsh('a'));
        $this->coder()->guardarClaves($this->claveSsh('a'));

        $this->assertCount(1, array_filter($this->eventos(), fn ($e) => $e === 'coder.claves_cambiadas'));
    }

    public function test_si_la_comparticion_no_se_puede_escribir_se_avisa_y_no_se_guardan(): void
    {
        config(['coder.proyecto' => '/proc/no-se-puede-escribir']);

        try {
            $this->coder()->guardarClaves($this->claveSsh('a'));
            $this->fail('Debia fallar.');
        } catch (CoderException $e) {
            $this->assertStringContainsString('carpeta compartida', $e->getMessage());
        }
        $this->assertSame([], $this->coder()->claves());
    }

    public function test_lanzar_vuelve_a_escribir_las_claves_en_una_comparticion_nueva(): void
    {
        $this->coder()->guardarClaves($this->claveSsh('a'));
        exec('rm -rf '.escapeshellarg($this->carpeta.'/proyecto/.coder'));   // the share was lost

        $this->coder()->lanzar(self::HASH);

        $this->assertSame($this->claveSsh('a')."\n", file_get_contents($this->carpeta.'/proyecto/.coder/authorized_keys'));
    }
}
