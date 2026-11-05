<?php

namespace Tests\Feature;

use App\Services\EsquemaBaseDatos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * El `formal` de la red «postgres» (esquema de la base incluido) tiene que ser identico, byte a byte, en los dos service.json y en
 * config/nutrition.php, y coincidir con las tablas que crean las migraciones.
 */
class EsquemaServicioTest extends TestCase
{
    use RefreshDatabase;

    private const CLUSTER = ['db.cluster' => '${NUTRITION_DB_CLUSTER}'];

    /** @return array<string, array{0: string}> */
    public static function serviceJson(): array
    {
        return [
            'servicio' => ['../.service/service.json'],
            'postgres' => ['../dependencies/postgres/.service/service.json'],
        ];
    }

    #[DataProvider('serviceJson')]
    public function test_service_json_declara_la_red_con_el_formal_completo(string $relativa): void
    {
        $ruta = base_path($relativa);
        if (! is_file($ruta)) {
            $this->markTestSkipped("{$relativa} no existe (el servicio se prueba fuera del repositorio completo).");
        }

        $spec = json_decode((string) file_get_contents($ruta), true, flags: JSON_THROW_ON_ERROR);
        $redes = array_values(array_filter($spec['network'] ?? [], fn (array $red) => in_array(config('nutrition.red.tag'), $red['tags'] ?? [], true)));

        $this->assertCount(1, $redes, 'Tiene que haber una sola entrada de red con el tag de la base.');
        $this->assertSame(['postgres'], $redes[0]['tags']);
        $this->assertSame([['tags' => ['postgresql']]], $redes[0]['protocol_stack']);

        $esperado = [...config('nutrition.red.formal'), ...self::CLUSTER];
        $real = $redes[0]['formal'];
        ksort($esperado, SORT_STRING);
        ksort($real, SORT_STRING);
        $this->assertSame($esperado, $real, 'Reescribe los service.json con: python3 database/sincronizar-esquema.py');
    }

    public function test_el_tag_de_la_red_no_lleva_sufijo(): void
    {
        $this->assertSame('postgres', config('nutrition.red.tag'));
    }

    public function test_formal_son_strings_de_una_linea_y_solo_db_cluster_es_variable(): void
    {
        $formal = [...config('nutrition.red.formal'), ...self::CLUSTER];

        $this->assertSame('postgresql', $formal['db.engine']);
        $this->assertSame('personal-nutrition', $formal['db.schema']);
        foreach ($formal as $clave => $valor) {
            $this->assertIsString($clave);
            $this->assertIsString($valor, $clave);
            $this->assertDoesNotMatchRegularExpression('/[\r\n=]/', $clave, 'La clave no puede llevar saltos de linea ni «=».');
            $this->assertDoesNotMatchRegularExpression('/[\r\n]/', $valor, "{$clave} lleva un salto de linea");
            $this->assertSame(trim($valor), $valor, "{$clave} lleva espacios en los extremos");
            if ($clave === 'db.cluster') {
                $this->assertSame('${NUTRITION_DB_CLUSTER}', $valor);
            } else {
                $this->assertStringNotContainsString('${', $valor, "{$clave} lleva una variable");
            }
        }
        $this->assertArrayNotHasKey('db.cluster', config('nutrition.red.formal'), 'db.cluster lo pone quien instancia.');
    }

    public function test_toda_tabla_migrada_esta_en_el_esquema_y_con_sus_columnas(): void
    {
        $esquema = array_filter(config('nutrition.red.formal'), fn (string $clave) => str_starts_with($clave, EsquemaBaseDatos::PREFIJO), ARRAY_FILTER_USE_KEY);

        $tablas = array_values(array_diff(array_column(Schema::getTables(), 'name'), EsquemaBaseDatos::EXCLUIDAS));
        $claves = array_map(fn (string $tabla) => EsquemaBaseDatos::PREFIJO.$tabla, $tablas);
        sort($claves, SORT_STRING);

        $this->assertSame($claves, array_keys($esquema), 'Tablas migradas y claves de esquema.json no coinciden: php artisan nutrition:esquema --escribir');

        foreach ($tablas as $tabla) {
            $reales = array_column(Schema::getColumns($tabla), 'name');
            $escritas = EsquemaBaseDatos::nombresDeColumnas($esquema[EsquemaBaseDatos::PREFIJO.$tabla]);
            sort($reales);
            sort($escritas);
            $this->assertSame($reales, $escritas, "Las columnas de {$tabla} no coinciden con esquema.json");
        }
    }

    public function test_gramatica_de_una_tabla(): void
    {
        $cadena = EsquemaBaseDatos::tabla(
            [
                ['name' => 'id', 'type' => 'bigint', 'nullable' => false, 'default' => "nextval('menus_id_seq'::regclass)", 'auto_increment' => true],
                ['name' => 'hogar_id', 'type' => 'bigint', 'nullable' => false, 'default' => null, 'auto_increment' => false],
                ['name' => 'nombre', 'type' => 'character varying(255)', 'nullable' => true, 'default' => null, 'auto_increment' => false],
                ['name' => 'estado', 'type' => 'character varying(20)', 'nullable' => false, 'default' => "'borrador'::character varying", 'auto_increment' => false],
                ['name' => 'creado', 'type' => 'timestamp(0) without time zone', 'nullable' => true, 'default' => 'CURRENT_TIMESTAMP', 'auto_increment' => false],
            ],
            [
                ['name' => 'menus_estado_creado_index', 'columns' => ['estado', 'creado'], 'unique' => false, 'primary' => false],
                ['name' => 'menus_nombre_unique', 'columns' => ['nombre'], 'unique' => true, 'primary' => false],
                ['name' => 'menus_pkey', 'columns' => ['id'], 'unique' => true, 'primary' => true],
                ['name' => 'menus_hogar_id_index', 'columns' => ['hogar_id'], 'unique' => false, 'primary' => false],
            ],
            [
                ['name' => 'x', 'columns' => ['hogar_id'], 'foreign_schema' => 'public', 'foreign_table' => 'hogares', 'foreign_columns' => ['id'], 'on_update' => 'no action', 'on_delete' => 'cascade'],
            ],
        );

        $this->assertSame(
            "id:bigint auto; hogar_id:bigint; nombre:character varying(255) null; estado:character varying(20) default='borrador'::character varying; creado:timestamp(0) without time zone null default=CURRENT_TIMESTAMP"
            .' | pk(id) | uq(nombre) | idx(estado,creado) | idx(hogar_id) | fk(hogar_id->hogares.id del=cascade upd=no action)',
            $cadena,
        );
        $this->assertSame(['id', 'hogar_id', 'nombre', 'estado', 'creado'], EsquemaBaseDatos::nombresDeColumnas($cadena));
        $this->assertSame(['pk(id)', 'uq(nombre)', 'idx(estado,creado)', 'idx(hogar_id)', 'fk(hogar_id->hogares.id del=cascade upd=no action)'], EsquemaBaseDatos::restricciones($cadena));
    }

    public function test_gramatica_es_determinista_aunque_cambie_el_orden_de_indices_y_claves(): void
    {
        $columnas = [['name' => 'a', 'type' => 'integer'], ['name' => 'b', 'type' => 'integer', 'nullable' => true]];
        $indices = [
            ['columns' => ['b'], 'unique' => false, 'primary' => false],
            ['columns' => ['a', 'b'], 'unique' => true, 'primary' => false],
            ['columns' => ['a'], 'unique' => false, 'primary' => false],
            ['columns' => ['a'], 'unique' => true, 'primary' => true],
        ];
        $claves = [
            ['columns' => ['b'], 'foreign_table' => 'z', 'foreign_columns' => ['id'], 'on_update' => 'restrict', 'on_delete' => 'set null'],
            ['columns' => ['a'], 'foreign_table' => 'y', 'foreign_columns' => ['id'], 'on_update' => null, 'on_delete' => null],
        ];

        $esperada = 'a:integer; b:integer null | pk(a) | uq(a,b) | idx(a) | idx(b) | fk(a->y.id del=no action upd=no action) | fk(b->z.id del=set null upd=restrict)';
        $this->assertSame($esperada, EsquemaBaseDatos::tabla($columnas, $indices, $claves));
        $this->assertSame($esperada, EsquemaBaseDatos::tabla($columnas, array_reverse($indices), array_reverse($claves)));
    }

    public function test_gramatica_claves_compuestas(): void
    {
        $cadena = EsquemaBaseDatos::tabla(
            [['name' => 'a', 'type' => 'bigint'], ['name' => 'b', 'type' => 'numeric(10,2)', 'default' => '0.00']],
            [['columns' => ['a', 'b'], 'unique' => true, 'primary' => true]],
            [['columns' => ['a', 'b'], 'foreign_table' => 't', 'foreign_columns' => ['x', 'y'], 'on_update' => 'cascade', 'on_delete' => 'restrict']],
        );

        $this->assertSame('a:bigint; b:numeric(10,2) default=0.00 | pk(a,b) | fk(a,b->t.x,y del=restrict upd=cascade)', $cadena);
    }

    public function test_mapa_excluye_migrations_y_ordena_por_clave(): void
    {
        $tabla = ['columns' => [['name' => 'id', 'type' => 'bigint']]];
        $mapa = EsquemaBaseDatos::mapa(['users' => $tabla, 'migrations' => $tabla, 'cache' => $tabla]);

        $this->assertSame(['db.table.cache', 'db.table.users'], array_keys($mapa));
        $this->assertSame('id:bigint', $mapa['db.table.users']);
    }

    public function test_gramatica_rechaza_lo_que_romperia_el_formal(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EsquemaBaseDatos::tabla([['name' => 'a', 'type' => 'text', 'default' => "'\${X}'\n"]]);
    }

    public function test_gramatica_rechaza_variables_en_el_valor(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EsquemaBaseDatos::tabla([['name' => 'a', 'type' => 'text', 'default' => "'\${X}'"]]);
    }
}
