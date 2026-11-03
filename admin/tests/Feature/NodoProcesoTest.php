<?php

namespace Tests\Feature;

use App\Services\Celaut\Nodo;
use App\Services\Celaut\NodoException;
use Tests\TestCase;

/** `Nodo` contra un `celaut` falso: un script que imprime lo que se le configura. */
class NodoProcesoTest extends TestCase
{
    private string $carpeta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->carpeta = sys_get_temp_dir().'/nodo-'.uniqid();
        mkdir($this->carpeta);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->carpeta.'/*') ?: []);
        @rmdir($this->carpeta);
        parent::tearDown();
    }

    /** Un `celaut` que registra sus argumentos en `args` y responde con $stdout (codigo 0) o con $stderr y $codigo. */
    private function falso(string $stdout = '{}', string $stderr = '', int $codigo = 0): Nodo
    {
        $binario = $this->carpeta.'/celaut';
        file_put_contents($binario, "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> ".$this->carpeta."/args\n"
            .($codigo === 0 ? 'cat <<\'EOF\'' : 'cat >&2 <<\'EOF\'')."\n".($codigo === 0 ? $stdout : $stderr)."\nEOF\nexit {$codigo}\n");
        chmod($binario, 0755);

        return new Nodo($binario, '/opt/nutrition/celaut', $this->carpeta.'/pool.json');
    }

    private function argumentos(): string
    {
        return trim((string) file_get_contents($this->carpeta.'/args'));
    }

    public function test_lista_las_instancias_del_pool(): void
    {
        $nodo = $this->falso('{"instances":[{"lease":3,"service":"ab","uri":"10.0.0.9:5432","leased":true,"idle_secs":0,"timeouts":0,"failures":0}]}');

        $this->assertSame('10.0.0.9:5432', $nodo->instancias()[0]['uri']);
        $this->assertSame('--state '.$this->carpeta.'/pool.json list', $this->argumentos());
    }

    public function test_un_pool_vacio_no_da_instancias(): void
    {
        $this->assertSame([], $this->falso('{"instances":[]}')->instancias());
    }

    public function test_para_una_instancia_por_su_lease(): void
    {
        $this->falso('{"refund_mu":"5"}')->parar(7);

        $this->assertStringEndsWith('stop 7', $this->argumentos());
    }

    public function test_adquirir_pasa_la_dependencia_el_entorno_y_la_raiz(): void
    {
        $r = $this->falso('{"lease":1,"uri":"10.0.0.9:5432","new":true}')->adquirir('NUTRITION_POSTGRES', ['DB_USERNAME' => 'u', 'NUTRITION_DB_CLUSTER' => 'abc']);

        $this->assertSame('10.0.0.9:5432', $r['uri']);
        $this->assertStringEndsWith('acquire --name NUTRITION_POSTGRES --root /opt/nutrition/celaut --env DB_USERNAME=u --env NUTRITION_DB_CLUSTER=abc', $this->argumentos());
    }

    public function test_un_rechazo_del_nodo_lanza_con_el_motivo(): void
    {
        $nodo = $this->falso(stderr: '{"error":"Insufficient balance","kind":"refused","unreachable":false}', codigo: 1);

        try {
            $nodo->adquirir('NUTRITION_POSTGRES');
            $this->fail('Debia lanzar.');
        } catch (NodoException $e) {
            $this->assertStringContainsString('Insufficient balance', $e->getMessage());
            $this->assertFalse($e->inalcanzable);
        }
    }

    public function test_el_codigo_3_es_nodo_inalcanzable(): void
    {
        $nodo = $this->falso(stderr: '{"error":"no answer","kind":"unreachable","unreachable":true}', codigo: 3);

        try {
            $nodo->instancias();
            $this->fail('Debia lanzar.');
        } catch (NodoException $e) {
            $this->assertTrue($e->inalcanzable);
        }
    }
}
