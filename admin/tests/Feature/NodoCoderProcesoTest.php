<?php

namespace Tests\Feature;

use App\Services\Celaut\Nodo;
use App\Services\Celaut\NodoException;
use Tests\TestCase;

/** The Nodo methods for the Coder, against a fake `celaut`: a script that prints what the test sets and records its arguments. */
class NodoCoderProcesoTest extends TestCase
{
    private const HASH = 'a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4a1b2c3d4';

    private string $carpeta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->carpeta = sys_get_temp_dir().'/nodo-coder-'.uniqid();
        mkdir($this->carpeta);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->carpeta.'/*') ?: []);
        @rmdir($this->carpeta);
        parent::tearDown();
    }

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

    public function test_adquirir_por_hash_usa_service_y_el_entorno(): void
    {
        $r = $this->falso('{"lease":4,"uri":"10.0.0.7:22","new":true}')->adquirirPorHash(self::HASH, ['CODER_SSH_HOST_KEY' => 'YWJj', 'A' => 'b=c']);

        $this->assertSame(4, $r['lease']);
        $this->assertSame('--state '.$this->carpeta.'/pool.json acquire --service '.self::HASH.' --env CODER_SSH_HOST_KEY=YWJj --env A=b=c', $this->argumentos());
    }

    public function test_adquirir_por_hash_con_limite_inicial_y_nuevo(): void
    {
        $this->falso('{"lease":1,"uri":"x:1","new":true}')->adquirirPorHash(self::HASH, [], '5000', true);

        $this->assertStringEndsWith('acquire --service '.self::HASH.' --initial-mu 5000 --new', $this->argumentos());
    }

    public function test_adquirir_por_paquete_usa_package(): void
    {
        $this->falso('{"lease":1,"uri":"x:1","new":true}')->adquirirPorPaquete('/ruta/x.celaut.bee', ['K' => 'v'], '9');

        $this->assertStringEndsWith('acquire --package /ruta/x.celaut.bee --env K=v --initial-mu 9', $this->argumentos());
    }

    public function test_un_valor_con_espacios_o_comillas_llega_como_un_solo_argumento(): void
    {
        // Symfony Process passes an array: no shell is involved, so no value can add an option.
        $binario = $this->carpeta.'/celaut';
        file_put_contents($binario, "#!/bin/sh\nfor a in \"\$@\"; do printf '[%s]' \"\$a\"; done > ".$this->carpeta."/args\necho '{\"lease\":1,\"uri\":\"x:1\",\"new\":true}'\n");
        chmod($binario, 0755);

        (new Nodo($binario))->adquirirPorHash(self::HASH, ['K' => 'a b "c" --new']);

        $this->assertStringContainsString('[K=a b "c" --new]', $this->argumentos());
    }

    public function test_coste_por_hash_y_por_paquete(): void
    {
        $nodo = $this->falso('{"service":"x","cost_mu":"1","init_maintenance_cost_mu":"2","max_maintenance_cost_mu":"3"}');

        $this->assertSame('3', $nodo->costePorHash(self::HASH)['max_maintenance_cost_mu']);
        $this->assertStringEndsWith('cost --service '.self::HASH, $this->argumentos());

        $nodo->costePorPaquete('/ruta/x.celaut.bee', [], '77');
        $this->assertStringEndsWith('cost --package /ruta/x.celaut.bee --initial-mu 77', $this->argumentos());
    }

    public function test_id_de_paquete_lee_el_servicio_de_inspect(): void
    {
        $this->assertSame(self::HASH, $this->falso('{"service":"'.self::HASH.'"}')->idDePaquete('/ruta/x.celaut.bee'));
        $this->assertStringEndsWith('inspect /ruta/x.celaut.bee', $this->argumentos());
    }

    public function test_un_rechazo_al_costear_lanza_con_el_motivo(): void
    {
        $nodo = $this->falso(stderr: '{"error":"unknown service","kind":"refused","unreachable":false}', codigo: 1);

        $this->expectException(NodoException::class);
        $this->expectExceptionMessage('unknown service');
        $nodo->costePorHash(self::HASH);
    }
}
