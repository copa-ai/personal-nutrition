<?php

namespace Tests\Feature;

use App\Services\Fusion\Exportador;
use App\Services\Fusion\Fusionador;
use RuntimeException;
use Tests\Support\DosBases;
use Tests\TestCase;

/**
 * Fusion de dos bases (lo de B entra en A) contra PostgreSQL de verdad. Reglas: gana la version mas nueva de cada fila (la otra queda en
 * la auditoria como fusion_descartada), lo borrado no reaparece, la misma clave unica es la misma fila, y todo es atomico e idempotente.
 */
class FusionDatosTest extends TestCase
{
    use DosBases;

    private const H1 = '01000000-0000-7000-8000-000000000001';

    private const H2 = '01000000-0000-7000-8000-000000000002';

    private const P1 = '02000000-0000-7000-8000-000000000001';

    private const P2 = '02000000-0000-7000-8000-000000000002';

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararDosBases();
    }

    private function perfil(string $clave, string $id, string $hogar, string $nombre, string $actualizado): void
    {
        $this->sql($clave, 'INSERT INTO perfiles (id, hogar_id, nombre, activo, created_at, updated_at) VALUES (?, ?, ?, true, ?, ?)', [$id, $hogar, $nombre, '2026-11-01 00:00:00', $actualizado]);
    }

    public function test_las_filas_que_solo_estan_en_el_origen_se_insertan_con_sus_hijas(): void
    {
        $this->hogar('b', self::H1, 'Casa', '2026-11-02 10:00:00');
        $this->perfil('b', self::P1, self::H1, 'Marta', '2026-11-02 10:00:00');

        $resumen = $this->fusionar();

        $this->assertSame(2, $resumen['insertadas']);
        $this->assertSame('Casa', $this->valor('a', 'SELECT nombre FROM hogares WHERE id = ?', [self::H1]));
        $this->assertSame('Marta', $this->valor('a', 'SELECT nombre FROM perfiles WHERE id = ?', [self::P1]));
        $this->assertSame(self::H1, $this->valor('a', 'SELECT hogar_id FROM perfiles WHERE id = ?', [self::P1]));
    }

    public function test_las_filas_que_solo_estan_en_el_destino_no_se_tocan(): void
    {
        $this->hogar('a', self::H1, 'Solo en A', '2026-11-02 10:00:00');
        $this->hogar('b', self::H2, 'Solo en B', '2026-11-02 10:00:00');

        $this->fusionar();

        $this->assertSame(['Solo en A', 'Solo en B'], array_column($this->filas('a', 'SELECT nombre FROM hogares ORDER BY nombre'), 'nombre'));
    }

    public function test_de_dos_versiones_gana_la_mas_nueva_y_la_otra_queda_en_la_auditoria(): void
    {
        $this->hogar('a', self::H1, 'Casa de A', '2026-11-02 10:00:00');
        $this->hogar('b', self::H1, 'Casa de B', '2026-11-02 11:00:00');

        $resumen = $this->fusionar();

        $this->assertSame('Casa de B', $this->valor('a', 'SELECT nombre FROM hogares WHERE id = ?', [self::H1]));
        $this->assertSame(1, $resumen['actualizadas']);
        $this->assertSame('2026-11-02 11:00:00', $this->valor('a', 'SELECT updated_at::text FROM hogares WHERE id = ?', [self::H1]));

        $descarte = $this->descartes('a')[0];
        $this->assertSame('App\Models\Hogar', $descarte['auditable_type']);
        $this->assertSame(self::H1, $descarte['auditable_id']);
        $this->assertSame(['nombre' => 'Casa de B'], json_decode($descarte['valores_antiguos'], true), 'lo que se queda');
        $this->assertSame(['nombre' => 'Casa de A'], json_decode($descarte['valores_nuevos'], true), 'lo que se pierde');
        $datos = json_decode($descarte['datos'], true);
        $this->assertSame(['hogares', 'sustituida', 'instancia-b'], [$datos['tabla'], $datos['accion'], $datos['origen']]);
        $this->assertSame('instancia-a', $descarte['instancia']);
        $this->assertSame('fusion', $descarte['metodo']);
    }

    public function test_si_la_del_destino_es_mas_nueva_se_queda_y_la_del_origen_se_anota_como_descartada(): void
    {
        $this->hogar('a', self::H1, 'Casa de A', '2026-11-02 12:00:00');
        $this->hogar('b', self::H1, 'Casa de B', '2026-11-02 11:00:00');

        $resumen = $this->fusionar();

        $this->assertSame('Casa de A', $this->valor('a', 'SELECT nombre FROM hogares WHERE id = ?', [self::H1]));
        $this->assertArrayNotHasKey('actualizadas', $resumen);
        $descarte = $this->descartes('a')[0];
        $this->assertSame(['nombre' => 'Casa de A'], json_decode($descarte['valores_antiguos'], true));
        $this->assertSame(['nombre' => 'Casa de B'], json_decode($descarte['valores_nuevos'], true));
        $this->assertSame('descartada', json_decode($descarte['datos'], true)['accion']);
    }

    public function test_una_fila_igual_en_los_dos_lados_no_cambia_nada_ni_deja_descartes(): void
    {
        $this->hogar('a', self::H1, 'Casa', '2026-11-02 10:00:00');
        $this->hogar('b', self::H1, 'Casa', '2026-11-02 10:00:00');

        $resumen = $this->fusionar();

        $this->assertSame(1, $resumen['iguales']);
        $this->assertSame([], $this->descartes('a'));
    }

    public function test_a_igualdad_de_fecha_el_resultado_es_el_mismo_en_las_dos_direcciones(): void
    {
        foreach (['a' => 'Nombre uno', 'b' => 'Nombre dos'] as $clave => $nombre) {
            $this->hogar($clave, self::H1, $nombre, '2026-11-02 10:00:00');
        }

        $this->fusionar('b', 'a');
        $this->fusionar('a', 'b');

        $this->assertSame($this->valor('a', 'SELECT nombre FROM hogares'), $this->valor('b', 'SELECT nombre FROM hogares'), 'la misma ganadora venga de donde venga');
    }

    public function test_fusionar_dos_veces_es_lo_mismo_que_una(): void
    {
        $this->hogar('a', self::H1, 'Casa de A', '2026-11-02 10:00:00');
        $this->hogar('b', self::H1, 'Casa de B', '2026-11-02 11:00:00');
        $this->hogar('b', self::H2, 'Otro', '2026-11-02 11:00:00');
        $this->fusionar();
        $antes = [$this->filas('a', 'SELECT * FROM hogares ORDER BY id'), count($this->descartes('a'))];

        $segunda = $this->fusionar();

        $this->assertSame($antes, [$this->filas('a', 'SELECT * FROM hogares ORDER BY id'), count($this->descartes('a'))]);
        $this->assertSame(2, $segunda['iguales']);
        $this->assertArrayNotHasKey('insertadas', $segunda);
        $this->assertArrayNotHasKey('actualizadas', $segunda);
    }

    public function test_lo_que_se_fusiona_no_deja_un_creado_por_fila_pero_si_un_resumen(): void
    {
        $this->hogar('b', self::H1, 'Casa', '2026-11-02 10:00:00');

        $this->fusionar();

        // Solo el «creado» que ya tenia B (viaja con su auditoria): la fusion no anota otro por cada fila que trae.
        $this->assertSame(1, (int) $this->valor('a', "SELECT count(*) FROM auditorias WHERE evento = 'creado'"));
        $resumen = $this->filas('a', "SELECT * FROM auditorias WHERE evento = 'fusion'");
        $this->assertCount(1, $resumen);
        $this->assertSame('instancia-b', json_decode($resumen[0]['datos'], true)['origen']);
        $this->assertSame(1, json_decode($resumen[0]['datos'], true)['insertadas']);
    }

    public function test_la_auditoria_del_origen_se_une_a_la_del_destino(): void
    {
        $this->sql('b', "INSERT INTO auditorias (id, evento, descripcion, created_at) VALUES ('03000000-0000-7000-8000-000000000001', 'login', 'Entro en B', '2026-11-02 09:00:00')");
        $this->sql('a', "INSERT INTO auditorias (id, evento, descripcion, created_at) VALUES ('03000000-0000-7000-8000-000000000002', 'login', 'Entro en A', '2026-11-02 09:30:00')");

        $primera = $this->fusionar();
        $this->fusionar();

        $this->assertSame(1, $primera['auditorias']);
        $this->assertSame(['Entro en A', 'Entro en B'], array_column($this->filas('a', "SELECT descripcion FROM auditorias WHERE evento = 'login' ORDER BY descripcion"), 'descripcion'));
    }

    public function test_los_valores_sensibles_de_una_version_descartada_salen_enmascarados(): void
    {
        $hash = '$2y$12$abcdefghijklmnopqrstuv';
        $this->sql('a', "INSERT INTO users (id, name, email, password, created_at, updated_at) VALUES ('04000000-0000-7000-8000-000000000001', 'Ana', 'a@b.c', '{$hash}1', '2026-11-01', '2026-11-02 10:00:00')");
        $this->sql('b', "INSERT INTO users (id, name, email, password, created_at, updated_at) VALUES ('04000000-0000-7000-8000-000000000001', 'Ana', 'a@b.c', '{$hash}2', '2026-11-01', '2026-11-02 11:00:00')");

        $this->fusionar();

        $descarte = $this->descartes('a')[0];
        $this->assertStringNotContainsString('abcdefghijklmnopqrstuv', json_encode($descarte));
        $this->assertSame(['password' => '***'], json_decode($descarte['valores_nuevos'], true));
    }

    public function test_un_borrado_posterior_a_la_ultima_edicion_se_aplica_aqui_con_sus_hijas(): void
    {
        $this->hogar('a', self::H1, 'Casa', '2026-11-02 10:00:00');
        $this->perfil('a', self::P1, self::H1, 'Marta', '2026-11-02 10:00:00');
        $this->sql('b', "INSERT INTO borrados (tabla, id, borrado_en, instancia) VALUES ('hogares', ?, '2026-11-02 12:00:00', 'instancia-b')", [self::H1]);

        $resumen = $this->fusionar();

        $this->assertSame(0, (int) $this->valor('a', 'SELECT count(*) FROM hogares'));
        $this->assertSame(0, (int) $this->valor('a', 'SELECT count(*) FROM perfiles'), 'se borra en cascada');
        $this->assertSame(1, $resumen['borradas']);
        $descarte = $this->descartes('a')[0];
        $this->assertSame([self::H1, 'borrada_en_el_origen'], [$descarte['auditable_id'], json_decode($descarte['datos'], true)['accion']]);
        $this->assertSame('Casa', json_decode($descarte['datos'], true)['fila_descartada']['nombre']);
        $this->assertSame('2026-11-02 12:00:00', $this->valor('a', "SELECT borrado_en::text FROM borrados WHERE tabla = 'hogares'"));
    }

    public function test_una_edicion_posterior_al_borrado_del_origen_se_queda(): void
    {
        $this->hogar('a', self::H1, 'Casa editada despues', '2026-11-02 13:00:00');
        $this->sql('b', "INSERT INTO borrados (tabla, id, borrado_en) VALUES ('hogares', ?, '2026-11-02 12:00:00')", [self::H1]);

        $this->fusionar();

        $this->assertSame('Casa editada despues', $this->valor('a', 'SELECT nombre FROM hogares WHERE id = ?', [self::H1]));
        $this->assertSame('borrado_descartado', json_decode($this->descartes('a')[0]['datos'], true)['accion']);
        $this->assertSame(0, (int) $this->valor('a', 'SELECT count(*) FROM borrados'), 'la tumba descartada no se importa');
    }

    public function test_una_fila_borrada_aqui_no_reaparece_desde_el_origen(): void
    {
        $this->sql('a', "INSERT INTO borrados (tabla, id, borrado_en) VALUES ('hogares', ?, '2026-11-02 12:00:00')", [self::H1]);
        $this->hogar('b', self::H1, 'Casa', '2026-11-02 10:00:00');

        $this->fusionar();

        $this->assertSame(0, (int) $this->valor('a', 'SELECT count(*) FROM hogares'));
        $this->assertSame('borrada_aqui', json_decode($this->descartes('a')[0]['datos'], true)['accion']);
    }

    public function test_una_fila_recreada_despues_del_borrado_si_entra(): void
    {
        $this->sql('a', "INSERT INTO borrados (tabla, id, borrado_en) VALUES ('hogares', ?, '2026-11-02 12:00:00')", [self::H1]);
        $this->hogar('b', self::H1, 'Casa recreada', '2026-11-02 13:00:00');

        $this->fusionar();

        $this->assertSame('Casa recreada', $this->valor('a', 'SELECT nombre FROM hogares WHERE id = ?', [self::H1]));
    }

    public function test_los_borrados_hechos_por_la_aplicacion_dejan_tumba_y_se_propagan(): void
    {
        $this->hogar('a', self::H1, 'Casa', '2026-11-02 10:00:00');
        $this->hogar('b', self::H1, 'Casa', '2026-11-02 10:00:00');
        $this->sql('b', 'DELETE FROM hogares WHERE id = ?', [self::H1]);   // el trigger deja la tumba con la hora de ahora

        $this->assertSame(1, (int) $this->valor('b', "SELECT count(*) FROM borrados WHERE tabla = 'hogares'"));
        $this->fusionar();

        $this->assertSame(0, (int) $this->valor('a', 'SELECT count(*) FROM hogares'));
    }

    public function test_una_fila_cuya_referencia_obligatoria_no_existe_se_descarta(): void
    {
        $this->sql('a', "INSERT INTO borrados (tabla, id, borrado_en) VALUES ('hogares', ?, '2026-11-02 12:00:00')", [self::H1]);
        $this->hogar('b', self::H1, 'Casa', '2026-11-02 10:00:00');
        $this->perfil('b', self::P1, self::H1, 'Marta', '2026-11-02 10:00:00');

        $this->fusionar();

        $this->assertSame(0, (int) $this->valor('a', 'SELECT count(*) FROM perfiles'));
        $acciones = array_map(fn (array $d): string => json_decode($d['datos'], true)['accion'], $this->descartes('a'));
        sort($acciones);
        $this->assertSame(['borrada_aqui', 'huerfana'], $acciones);
    }

    public function test_la_misma_clave_unica_es_la_misma_fila_y_sus_referencias_se_reasignan(): void
    {
        $this->sql('a', "INSERT INTO alimentos (id, nombre, categoria, unidad, created_at, updated_at) VALUES ('05000000-0000-7000-8000-00000000000a', 'Leche', 'lacteo', 'l', '2026-11-01', '2026-11-02 10:00:00')");
        $this->sql('b', "INSERT INTO alimentos (id, nombre, categoria, unidad, created_at, updated_at) VALUES ('05000000-0000-7000-8000-00000000000b', 'Leche', 'lacteo', 'ml', '2026-11-01', '2026-11-02 11:00:00')");
        $this->hogar('b', self::H1, 'Casa', '2026-11-02 10:00:00');
        $this->sql('b', "INSERT INTO existencias (id, hogar_id, alimento_id, cantidad, unidad, created_at, updated_at) VALUES ('06000000-0000-7000-8000-000000000001', ?, '05000000-0000-7000-8000-00000000000b', 3, 'ml', '2026-11-01', '2026-11-02 11:00:00')", [self::H1]);

        $this->fusionar();

        $this->assertSame(1, (int) $this->valor('a', 'SELECT count(*) FROM alimentos'));
        $this->assertSame('05000000-0000-7000-8000-00000000000a', $this->valor('a', 'SELECT id FROM alimentos'), 'se queda el id de aqui');
        $this->assertSame('ml', $this->valor('a', 'SELECT unidad FROM alimentos'), 'con el contenido mas nuevo');
        $this->assertSame('05000000-0000-7000-8000-00000000000a', $this->valor('a', 'SELECT alimento_id FROM existencias'), 'la existencia apunta al alimento de aqui');
    }

    public function test_disponibilidad_con_el_mismo_hogar_y_dia_es_la_misma_fila(): void
    {
        $this->hogar('a', self::H1, 'Casa', '2026-11-02 10:00:00');
        $this->hogar('b', self::H1, 'Casa', '2026-11-02 10:00:00');
        $this->sql('a', "INSERT INTO disponibilidad_dias (id, hogar_id, dia_semana, minutos_comida, created_at, updated_at) VALUES ('07000000-0000-7000-8000-00000000000a', ?, 1, 20, '2026-11-01', '2026-11-02 10:00:00')", [self::H1]);
        $this->sql('b', "INSERT INTO disponibilidad_dias (id, hogar_id, dia_semana, minutos_comida, created_at, updated_at) VALUES ('07000000-0000-7000-8000-00000000000b', ?, 1, 45, '2026-11-01', '2026-11-02 11:00:00')", [self::H1]);

        $this->fusionar();

        $this->assertSame([['id' => '07000000-0000-7000-8000-00000000000a', 'minutos_comida' => 45]], $this->filas('a', 'SELECT id, minutos_comida FROM disponibilidad_dias'));
    }

    public function test_el_menu_activo_de_un_hogar_se_enlaza_aunque_llegue_despues(): void
    {
        $menu = '08000000-0000-7000-8000-000000000001';
        $this->hogar('b', self::H1, 'Casa', '2026-11-02 10:00:00');
        $this->sql('b', "INSERT INTO menus (id, nombre, hogar_id, fecha_inicio, estado, created_at, updated_at) VALUES (?, 'Semana', ?, '2026-11-02', 'terminado', '2026-11-01', '2026-11-02 10:00:00')", [$menu, self::H1]);
        $this->sql('b', 'UPDATE hogares SET menu_id = ? WHERE id = ?', [$menu, self::H1]);

        $this->fusionar();

        $this->assertSame($menu, $this->valor('a', 'SELECT menu_id FROM hogares WHERE id = ?', [self::H1]));
    }

    public function test_un_menu_que_corria_en_el_origen_entra_como_fallido(): void
    {
        $this->hogar('b', self::H1, 'Casa', '2026-11-02 10:00:00');
        $this->sql('b', "INSERT INTO menus (id, nombre, hogar_id, fecha_inicio, estado, proceso_pid, ejecucion_id, detener_pedido, ciclo, created_at, updated_at)
            VALUES ('08000000-0000-7000-8000-000000000002', 'En marcha', ?, '2026-11-02', 'en_marcha', 4321, 'ej-1', true, 2, '2026-11-01', '2026-11-02 10:00:00')", [self::H1]);

        $this->fusionar();

        $menu = $this->filas('a', 'SELECT estado, proceso_pid, ejecucion_id, detener_pedido, error FROM menus')[0];
        $this->assertSame('fallido', $menu['estado']);
        $this->assertNull($menu['proceso_pid']);
        $this->assertNull($menu['ejecucion_id']);
        $this->assertFalse($menu['detener_pedido']);
        $this->assertStringContainsString('interrumpió', $menu['error']);
    }

    public function test_un_menu_que_corre_aqui_conserva_su_ejecucion_aunque_el_origen_traiga_cambios(): void
    {
        $id = '08000000-0000-7000-8000-000000000003';
        $this->hogar('a', self::H1, 'Casa', '2026-11-02 10:00:00');
        $this->hogar('b', self::H1, 'Casa', '2026-11-02 10:00:00');
        $this->sql('a', "INSERT INTO menus (id, nombre, hogar_id, fecha_inicio, estado, proceso_pid, ejecucion_id, ciclo, created_at, updated_at) VALUES (?, 'Menu', ?, '2026-11-02', 'en_marcha', 99, 'ej-a', 1, '2026-11-01', '2026-11-02 10:00:00')", [$id, self::H1]);
        $this->sql('b', "INSERT INTO menus (id, nombre, hogar_id, fecha_inicio, estado, ciclo, created_at, updated_at) VALUES (?, 'Menu renombrado', ?, '2026-11-02', 'terminado', 5, '2026-11-01', '2026-11-02 12:00:00')", [$id, self::H1]);

        $this->fusionar();

        $menu = $this->filas('a', 'SELECT nombre, estado, proceso_pid, ejecucion_id, ciclo FROM menus')[0];
        $this->assertSame(['Menu renombrado', 'en_marcha', 99, 'ej-a', 1], array_values($menu), 'cambia el contenido, no la ejecucion');
    }

    public function test_los_mensajes_del_chat_se_unen_y_los_nuevos_se_renumeran_a_continuacion(): void
    {
        $this->sql('a', "INSERT INTO chat_conversacion (id, creada_ms, ultima_secuencia, created_at, updated_at) VALUES (1, 1000, 2, '2026-11-01', '2026-11-02 10:00:00')");
        $this->sql('b', "INSERT INTO chat_conversacion (id, creada_ms, ultima_secuencia, created_at, updated_at) VALUES (1, 1000, 3, '2026-11-01', '2026-11-02 10:00:00')");
        $mensaje = fn (string $mid, int $seq, string $texto) => ['INSERT INTO chat_mensajes (id, message_id, sequence, contenido, estado, created_ms, updated_ms, created_at, updated_at) VALUES (?, ?, ?, ?::jsonb, ?, ?, ?, ?, ?)',
            [self::uuidMensaje($mid), $mid, $seq, json_encode([['tipo' => 'texto', 'texto' => $texto]]), 'COMPLETED', $seq * 10, $seq * 10, '2026-11-02 10:00:00', '2026-11-02 10:00:00']];
        foreach ([['a', 'm-comun', 1, 'hola'], ['a', 'm-solo-a', 2, 'en A'], ['b', 'm-comun', 1, 'hola'], ['b', 'm-solo-b', 2, 'en B'], ['b', 'm-solo-b-2', 3, 'en B otra vez']] as [$clave, $mid, $seq, $texto]) {
            $this->sql($clave, ...$mensaje($mid, $seq, $texto));
        }

        $resumen = $this->fusionar();

        $this->assertSame(2, $resumen['mensajes_renumerados']);
        $this->assertSame(
            ['m-comun' => 1, 'm-solo-a' => 2, 'm-solo-b' => 3, 'm-solo-b-2' => 4],
            array_column($this->filas('a', 'SELECT message_id, sequence FROM chat_mensajes ORDER BY sequence'), 'sequence', 'message_id'),
        );
        $this->assertSame(4, (int) $this->valor('a', 'SELECT ultima_secuencia FROM chat_conversacion'));
    }

    private static function uuidMensaje(string $mid): string
    {
        return '09000000-0000-7000-8000-'.substr(md5($mid), 0, 12);
    }

    public function test_si_el_esquema_no_coincide_no_se_fusiona_nada(): void
    {
        $this->hogar('b', self::H1, 'Casa', '2026-11-02 10:00:00');
        $flujo = fopen('php://temp', 'w+');
        (new Exportador)->exportar($this->base('b'), $flujo);
        rewind($flujo);
        $contenido = str_replace((string) $this->valor('b', 'SELECT fusion_esquema()'), 'esquema-de-otra-version', stream_get_contents($flujo));
        $flujo = fopen('php://temp', 'w+');
        fwrite($flujo, $contenido);
        rewind($flujo);

        try {
            (new Fusionador)->fusionar($this->base('a'), $flujo);
            $this->fail('deberia rechazarse');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('esquema', $e->getMessage());
        }
        $this->assertSame(0, (int) $this->valor('a', 'SELECT count(*) FROM hogares'));
    }

    public function test_si_algo_falla_a_mitad_no_cambia_nada(): void
    {
        $this->hogar('b', self::H1, 'Casa', '2026-11-02 10:00:00');
        $flujo = fopen('php://temp', 'w+');
        (new Exportador)->exportar($this->base('b'), $flujo);
        fwrite($flujo, '{"tabla":"sessions","filas":[{"id":"x"}]}'."\n");   // una tabla que no se fusiona
        rewind($flujo);

        try {
            (new Fusionador)->fusionar($this->base('a'), $flujo);
            $this->fail('deberia fallar');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('no se fusiona', $e->getMessage());
        }
        $this->assertSame(0, (int) $this->valor('a', 'SELECT count(*) FROM hogares'), 'el hogar que ya se habia aplicado se deshace');
        $this->assertSame(0, (int) $this->valor('a', "SELECT count(*) FROM auditorias WHERE evento = 'fusion'"));
    }

    public function test_un_fichero_que_no_es_un_volcado_se_rechaza(): void
    {
        foreach (['', "esto no es json\n", '{"formato":"otra-cosa","version":1}'."\n", '{"formato":"nutrition-datos","version":99}'."\n"] as $contenido) {
            $flujo = fopen('php://temp', 'w+');
            fwrite($flujo, $contenido);
            rewind($flujo);
            try {
                (new Fusionador)->fusionar($this->base('a'), $flujo);
                $this->fail('deberia rechazarse: '.$contenido);
            } catch (RuntimeException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_un_volumen_mayor_que_una_tanda_se_fusiona_entero(): void
    {
        $this->sql('b', "INSERT INTO alimentos (id, nombre, categoria, unidad, created_at, updated_at) SELECT uuidv7(), 'Alimento ' || g, 'otro', 'g', '2026-11-01', '2026-11-02 10:00:00' FROM generate_series(1, 450) g");

        $resumen = $this->fusionar();

        $this->assertSame(450, $resumen['insertadas']);
        $this->assertSame(450, (int) $this->valor('a', 'SELECT count(*) FROM alimentos'));
    }

    public function test_el_fichero_lleva_cabecera_y_tandas_en_orden(): void
    {
        $this->hogar('b', self::H1, 'Casa', '2026-11-02 10:00:00');
        $this->sql('b', "INSERT INTO borrados (tabla, id, borrado_en) VALUES ('hogares', ?, '2026-11-02 12:00:00')", [self::H2]);
        $flujo = fopen('php://temp', 'w+');

        $cuenta = (new Exportador)->exportar($this->base('b'), $flujo, 'portatil');
        rewind($flujo);
        $lineas = array_map(fn (string $l): array => json_decode($l, true), array_filter(explode("\n", stream_get_contents($flujo))));

        $this->assertSame(['nutrition-datos', 1, 'portatil'], [$lineas[0]['formato'], $lineas[0]['version'], $lineas[0]['origen']]);
        $this->assertSame((string) $this->valor('b', 'SELECT fusion_esquema()'), $lineas[0]['esquema']);
        $this->assertSame(1, $cuenta['hogares']);
        $tablas = array_values(array_filter(array_column($lineas, 'tabla')));
        $this->assertSame(['hogares'], array_values(array_unique(array_intersect($tablas, ['hogares']))));
        $this->assertArrayHasKey('borrados', $lineas[1]);
        $this->assertArrayHasKey('auditorias', end($lineas));
    }

    public function test_fusionar_por_direcciones_vuelca_a_un_fichero_temporal_y_lo_carga(): void
    {
        // Las dos direcciones son el mismo servidor: se fusiona la base A consigo misma, que tiene que dejarla igual.
        $this->hogar('a', self::H1, 'Casa', '2026-11-02 10:00:00');
        $this->perfil('a', self::P1, self::H1, 'Marta', '2026-11-02 10:00:00');
        config(['database.connections.pgsql.database' => 'pn_fusion_a']);
        $direccion = config('database.connections.pgsql.host').':'.config('database.connections.pgsql.port');
        $antes = $this->filas('a', 'SELECT * FROM hogares ORDER BY id');

        $resumen = (new Fusionador)->fusionarBase($direccion, $direccion);

        $this->assertSame(2, $resumen['iguales']);
        $this->assertSame($antes, $this->filas('a', 'SELECT * FROM hogares ORDER BY id'));
        $this->assertSame([], glob(sys_get_temp_dir().'/fusion*') ?: [], 'no deja ficheros temporales');
    }
}
