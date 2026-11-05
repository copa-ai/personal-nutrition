<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Que ninguna tabla de datos se quede sin trigger de auditoria al anadir migraciones. */
class TablasAuditadasTest extends TestCase
{
    use RefreshDatabase;

    /** Tablas que no se auditan, y por que. */
    private const SIN_AUDITORIA = [
        'migrations' => 'la de la herramienta',
        'auditorias' => 'es la propia auditoria (inmutable)',
        'auditoria_tablas' => 'configuracion de la auditoria (la crea la migracion de los triggers)',
        'borrados' => 'tumbas para la fusion (las deja el propio trigger de auditoria)',
        'cache' => 'efimera', 'cache_locks' => 'efimera', 'sessions' => 'efimera',
        'jobs' => 'cola', 'job_batches' => 'cola', 'failed_jobs' => 'cola',
        'password_reset_tokens' => 'efimera',
    ];

    public function test_toda_tabla_tiene_trigger_de_auditoria_o_esta_en_la_lista_de_excepciones(): void
    {
        $tablas = array_map(fn ($f) => $f->tablename, DB::select('SELECT tablename FROM pg_tables WHERE schemaname = current_schema()'));
        $auditadas = array_map(fn ($f) => $f->tabla, DB::select(
            "SELECT c.relname AS tabla FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE t.tgname = 'auditar' AND NOT t.tgisinternal",
        ));

        $sinTrigger = array_values(array_diff($tablas, $auditadas, array_keys(self::SIN_AUDITORIA)));

        $this->assertSame([], $sinTrigger, 'Tablas sin auditar: anadelas a auditar_tabla() en su migracion o a SIN_AUDITORIA con el motivo.');
    }

    public function test_las_excepciones_son_tablas_que_existen_y_no_estan_auditadas(): void
    {
        $tablas = array_map(fn ($f) => $f->tablename, DB::select('SELECT tablename FROM pg_tables WHERE schemaname = current_schema()'));
        $auditadas = array_map(fn ($f) => $f->tabla, DB::select(
            "SELECT c.relname AS tabla FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE t.tgname = 'auditar' AND NOT t.tgisinternal",
        ));

        $this->assertSame([], array_values(array_diff(array_keys(self::SIN_AUDITORIA), $tablas)), 'una excepcion apunta a una tabla que ya no existe');
        $this->assertSame([], array_values(array_intersect(array_keys(self::SIN_AUDITORIA), $auditadas)), 'una tabla de la lista de excepciones si esta auditada');
    }

    public function test_los_triggers_cubren_alta_cambio_y_baja(): void
    {
        $tipos = DB::select(
            "SELECT t.tgtype FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE t.tgname = 'auditar' AND c.relname = 'hogares'",
        );

        $this->assertCount(1, $tipos);
        $tipo = (int) $tipos[0]->tgtype;
        $this->assertTrue(($tipo & 1) === 1, 'por fila');
        $this->assertTrue(($tipo & 4) === 4 && ($tipo & 8) === 8 && ($tipo & 16) === 16, 'INSERT, DELETE y UPDATE');
        $this->assertTrue(($tipo & 2) === 0, 'AFTER, no BEFORE');
    }
}
