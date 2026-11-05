<?php

namespace Tests\Feature;

use App\Services\BaseCompartida\Candidata;
use App\Services\BaseCompartida\Sonda;
use PDO;
use Tests\TestCase;

/** La sonda contra PostgreSQL de verdad: dos bases extra en el servidor de pruebas. */
class SondaBaseTest extends TestCase
{
    private PDO $servidor;

    private const BASES = ['pn_sonda_a', 'pn_sonda_b'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->servidor = $this->conectar((string) config('database.connections.pgsql.database'));
        foreach (self::BASES as $base) {
            $this->servidor->exec("DROP DATABASE IF EXISTS {$base}");
            $this->servidor->exec("CREATE DATABASE {$base}");
        }
    }

    protected function tearDown(): void
    {
        foreach (self::BASES as $base) {
            $this->servidor->exec("DROP DATABASE IF EXISTS {$base}");
        }
        parent::tearDown();
    }

    private function conectar(string $base): PDO
    {
        $c = config('database.connections.pgsql');

        return new PDO("pgsql:host={$c['host']};port={$c['port']};dbname={$base}", $c['username'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    private function sondear(string $base, ?int $puerto = null): ?Candidata
    {
        config(['database.connections.pgsql.database' => $base]);

        return (new Sonda)->sondear((string) config('database.connections.pgsql.host'), $puerto ?? (int) config('database.connections.pgsql.port'));
    }

    public function test_cuenta_los_eventos_y_da_el_ultimo(): void
    {
        $pdo = $this->conectar('pn_sonda_a');
        $pdo->exec('CREATE TABLE auditorias (created_at timestamp)');
        $pdo->exec("INSERT INTO auditorias VALUES ('2026-11-05 10:00:00'), ('2026-11-05 11:30:00'), ('2026-11-04 09:00:00')");

        $candidata = $this->sondear('pn_sonda_a');

        $this->assertSame(3, $candidata->eventos);
        $this->assertSame('2026-11-05 11:30:00', $candidata->ultimoEvento->format('Y-m-d H:i:s'));
    }

    public function test_el_identificador_es_el_system_identifier_de_postgres(): void
    {
        $esperado = (string) $this->servidor->query('SELECT system_identifier::text FROM pg_control_system()')->fetchColumn();

        $candidata = $this->sondear('pn_sonda_a');

        $this->assertSame($esperado, $candidata->identificador);
        $this->assertMatchesRegularExpression('/^\d+$/', $candidata->identificador);
        $this->assertSame(config('database.connections.pgsql.host').':'.config('database.connections.pgsql.port'), $candidata->direccion);
    }

    public function test_una_base_sin_migrar_tiene_cero_eventos(): void
    {
        $candidata = $this->sondear('pn_sonda_b');

        $this->assertSame(0, $candidata->eventos);
        $this->assertNull($candidata->ultimoEvento);
    }

    public function test_una_tabla_de_auditoria_vacia_tampoco_tiene_ultimo_evento(): void
    {
        $this->conectar('pn_sonda_a')->exec('CREATE TABLE auditorias (created_at timestamp)');

        $candidata = $this->sondear('pn_sonda_a');

        $this->assertSame(0, $candidata->eventos);
        $this->assertNull($candidata->ultimoEvento);
    }

    public function test_una_base_que_no_responde_no_es_candidata(): void
    {
        $this->assertNull($this->sondear('pn_sonda_a', 1));
    }

    public function test_credenciales_rechazadas_dejan_la_base_fuera(): void
    {
        config(['database.connections.pgsql.password' => 'otra-clave']);

        $this->assertNull($this->sondear('pn_sonda_a'));
    }

    public function test_dos_bases_del_mismo_servidor_comparten_identificador_pero_no_datos(): void
    {
        $this->conectar('pn_sonda_a')->exec('CREATE TABLE auditorias (created_at timestamp); INSERT INTO auditorias VALUES (now())');

        $a = $this->sondear('pn_sonda_a');
        $b = $this->sondear('pn_sonda_b');

        $this->assertSame($a->identificador, $b->identificador, 'el identificador es del cluster; en produccion cada nutrition-postgres es un cluster');
        $this->assertSame([1, 0], [$a->eventos, $b->eventos]);
    }
}
