<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Copia de seguridad de la base de datos: exportarla a un fichero y (solo con SQLite) volver a cargarla.
 *
 * Con PostgreSQL la exportacion es un volcado SQL de datos en PHP puro (sin pg_dump): TRUNCATE + INSERT por tabla dentro de
 * una transaccion, restaurable con psql sobre una base que ya tenga el esquema (migraciones).
 *
 * Importar NO reemplaza el fichero de la base (la web y la cola lo tienen abierto): adjunta la copia y vuelca sus tablas
 * sobre las actuales dentro de una transaccion, de modo que el esquema sigue siendo el del codigo en marcha. Solo se copian
 * las columnas que existen en los dos lados y no se tocan las tablas efimeras (sesiones, cola, cache) ni `migrations`.
 */
class CopiaBase
{
    /** Tablas que no forman parte de la copia: estado de ejecucion, no datos de la persona. */
    private const OMITIR = ['migrations', 'sessions', 'jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks', 'password_reset_tokens'];

    /** Una copia valida de esta aplicacion tiene al menos estas tablas. */
    private const REQUERIDAS = ['users', 'hogares'];

    public static function disponible(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['sqlite', 'pgsql'], true);
    }

    /** La restauracion desde la propia aplicacion solo existe con SQLite. */
    public static function puedeImportar(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }

    public static function esPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    /**
     * Convierte un valor de PHP en un literal SQL de PostgreSQL.
     *
     * @param  mixed  $valor  valor leido por PDO (los bytea llegan como resource)
     * @param  string  $tipo  nombre del tipo de la columna (udt_name), si se conoce
     */
    public static function literal(mixed $valor, string $tipo = ''): string
    {
        if ($valor === null) {
            return 'NULL';
        }
        if (is_resource($valor)) {
            $valor = (string) stream_get_contents($valor);
            $tipo = 'bytea';
        }
        if ($tipo === 'bytea' && is_string($valor)) {
            return "decode('".bin2hex($valor)."','hex')";
        }
        if (is_bool($valor)) {
            return $valor ? 'TRUE' : 'FALSE';
        }
        if (is_int($valor)) {
            return (string) $valor;
        }
        if (is_float($valor)) {
            if (is_nan($valor) || is_infinite($valor)) {
                return "'".(is_nan($valor) ? 'NaN' : ($valor > 0 ? 'Infinity' : '-Infinity'))."'";
            }

            return var_export($valor, true);
        }

        return self::texto((string) $valor);
    }

    /** Literal de texto con comillas simples escapadas; usa E'' si hay barras invertidas. */
    public static function texto(string $texto): string
    {
        $texto = str_replace("\0", '', $texto);
        $escapado = str_replace("'", "''", $texto);
        if (str_contains($escapado, '\\')) {
            return "E'".str_replace('\\', '\\\\', $escapado)."'";
        }

        return "'".$escapado."'";
    }

    /** Identificador entre comillas dobles. */
    public static function identificador(string $nombre): string
    {
        return '"'.str_replace('"', '""', $nombre).'"';
    }

    /**
     * Ordena las tablas para que las referenciadas vayan antes que las que las referencian.
     *
     * @param  list<string>  $tablas
     * @param  array<string, list<string>>  $dependencias  tabla => tablas de las que depende
     * @return list<string>
     */
    public static function ordenar(array $tablas, array $dependencias): array
    {
        $orden = [];
        $estado = [];
        $visitar = function (string $t) use (&$visitar, &$orden, &$estado, $tablas, $dependencias): void {
            if (isset($estado[$t])) {
                return;
            }
            $estado[$t] = true;
            foreach ($dependencias[$t] ?? [] as $d) {
                if ($d !== $t && in_array($d, $tablas, true)) {
                    $visitar($d);
                }
            }
            $orden[] = $t;
        };
        foreach ($tablas as $t) {
            $visitar($t);
        }

        return $orden;
    }

    /** Nombre del fichero de descarga segun el motor. */
    public static function nombreDescarga(): string
    {
        return 'nutrition-'.now()->format('Y-m-d-His').(self::esPostgres() ? '.sql.gz' : '.sqlite');
    }

    /**
     * Vuelca los datos de PostgreSQL como SQL, enviando cada trozo a $salida.
     *
     * @param  callable(string): void  $salida
     * @return int numero de tablas volcadas
     */
    public function volcarSql(callable $salida): int
    {
        if (! self::esPostgres()) {
            throw new RuntimeException('El volcado SQL solo esta disponible con PostgreSQL.');
        }
        $pdo = DB::connection()->getPdo();
        $pdo->exec('BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY');

        try {
            $tablas = $this->tablasPostgres($pdo);
            $columnas = $this->columnasPostgres($pdo);
            $tablas = self::ordenar($tablas, $this->dependenciasPostgres($pdo));

            $salida("-- Copia de datos de Nutrition (PostgreSQL)\n");
            $salida('-- Fecha: '.now()->toIso8601String()."\n");
            $salida('-- Version de la aplicacion: '.config('app.version', config('app.name'))."\n");
            $salida('-- Tablas ('.count($tablas).'): '.implode(', ', $tablas)."\n");
            $salida("-- Restaurar con: psql \"\$DATABASE_URL\" -v ON_ERROR_STOP=1 -f copia.sql  (sobre una base con el esquema ya migrado)\n\n");
            $salida("BEGIN;\nSET client_encoding = 'UTF8';\n");
            if ($tablas) {
                $salida('TRUNCATE '.implode(', ', array_map(self::identificador(...), $tablas))." RESTART IDENTITY CASCADE;\n\n");
            }

            foreach ($tablas as $tabla) {
                $this->volcarTabla($pdo, $tabla, $columnas[$tabla] ?? [], $salida);
            }

            foreach ($tablas as $tabla) {
                foreach ($columnas[$tabla] ?? [] as $col) {
                    if ($col['serie']) {
                        $t = self::identificador($tabla);
                        $c = self::identificador($col['nombre']);
                        $salida("SELECT setval(pg_get_serial_sequence(".self::texto($t).', '.self::texto($col['nombre'])."), COALESCE(MAX($c), 1), MAX($c) IS NOT NULL) FROM $t;\n");
                    }
                }
            }
            $salida("COMMIT;\n");

            return count($tablas);
        } finally {
            try {
                $pdo->exec('ROLLBACK');
            } catch (Throwable) {
            }
        }
    }

    /**
     * Escribe en $salida el SQL .sql.gz de la copia, comprimido por bloques (para usar dentro de un streamDownload).
     *
     * @param  callable(string): void  $salida
     */
    public function volcarGzip(callable $salida): int
    {
        $z = deflate_init(ZLIB_ENCODING_GZIP);
        $buffer = '';
        $n = $this->volcarSql(function (string $trozo) use (&$buffer, $z, $salida): void {
            $buffer .= $trozo;
            if (strlen($buffer) >= 65536) {
                $salida(deflate_add($z, $buffer, ZLIB_NO_FLUSH));
                $buffer = '';
            }
        });
        $salida(deflate_add($z, $buffer, ZLIB_FINISH));

        return $n;
    }

    /**
     * @param  list<array{nombre: string, tipo: string, serie: bool}>  $columnas
     * @param  callable(string): void  $salida
     */
    private function volcarTabla(PDO $pdo, string $tabla, array $columnas, callable $salida): void
    {
        if (! $columnas) {
            return;
        }
        $t = self::identificador($tabla);
        $lista = implode(', ', array_map(fn ($c) => self::identificador($c['nombre']), $columnas));
        $salida("-- $tabla\n");
        $cursor = 'copia_'.substr(md5($tabla), 0, 12);
        $pdo->exec("DECLARE $cursor NO SCROLL CURSOR FOR SELECT $lista FROM $t");
        try {
            do {
                $filas = $pdo->query("FETCH 500 FROM $cursor")->fetchAll(PDO::FETCH_NUM);
                if (! $filas) {
                    break;
                }
                $valores = [];
                foreach ($filas as $fila) {
                    $celdas = [];
                    foreach ($columnas as $i => $c) {
                        $celdas[] = self::literal($fila[$i], $c['tipo']);
                    }
                    $valores[] = '('.implode(', ', $celdas).')';
                }
                $salida("INSERT INTO $t ($lista) VALUES\n".implode(",\n", $valores).";\n");
            } while (count($filas) === 500);
        } finally {
            $pdo->exec("CLOSE $cursor");
        }
        $salida("\n");
    }

    /** @return list<string> */
    private function tablasPostgres(PDO $pdo): array
    {
        $filas = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() AND table_type = 'BASE TABLE' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);

        return array_values(array_diff($filas, self::OMITIR));
    }

    /** @return array<string, list<array{nombre: string, tipo: string, serie: bool}>> */
    private function columnasPostgres(PDO $pdo): array
    {
        $sql = "SELECT table_name, column_name, udt_name, pg_get_serial_sequence(quote_ident(table_name), column_name) IS NOT NULL AS serie
                FROM information_schema.columns WHERE table_schema = current_schema()
                AND table_name IN (SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() AND table_type = 'BASE TABLE') ORDER BY table_name, ordinal_position";
        $resultado = [];
        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $resultado[$f['table_name']][] = ['nombre' => $f['column_name'], 'tipo' => $f['udt_name'], 'serie' => (bool) $f['serie']];
        }

        return $resultado;
    }

    /** @return array<string, list<string>> */
    private function dependenciasPostgres(PDO $pdo): array
    {
        $sql = "SELECT c.conrelid::regclass::text AS tabla, c.confrelid::regclass::text AS referencia
                FROM pg_constraint c JOIN pg_namespace n ON n.oid = c.connamespace
                WHERE c.contype = 'f' AND n.nspname = current_schema()";
        $resultado = [];
        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $resultado[trim($f['tabla'], '"')][] = trim($f['referencia'], '"');
        }

        return $resultado;
    }

    /** Escribe una instantanea consistente en un fichero temporal y devuelve su ruta (quien la pida debe borrarla). */
    public function exportar(): string
    {
        $this->exigirSqlite();
        $ruta = tempnam(sys_get_temp_dir(), 'copia');
        $pdo = DB::connection()->getPdo();
        $pdo->exec('VACUUM INTO '.$pdo->quote($ruta));

        return $ruta;
    }

    /**
     * Sustituye los datos actuales por los de la copia.
     *
     * @return array<string, int> filas importadas por tabla
     */
    public function importar(string $ruta): array
    {
        $this->exigirSqlite();
        $this->comprobarCabecera($ruta);

        $db = DB::connection();
        $pdo = $db->getPdo();
        $pdo->exec('ATTACH DATABASE '.$pdo->quote($ruta).' AS copia');

        try {
            $this->comprobarContenido();
            $tablas = array_values(array_diff($this->tablas('main'), self::OMITIR));
            $tablas = array_values(array_intersect($tablas, $this->tablas('copia')));

            $db->statement('PRAGMA foreign_keys = OFF');
            $resumen = [];
            $db->transaction(function () use ($db, $tablas, &$resumen) {
                $db->statement('PRAGMA defer_foreign_keys = ON');
                foreach ($tablas as $tabla) {
                    $columnas = array_values(array_intersect($this->columnas('main', $tabla), $this->columnas('copia', $tabla)));
                    $t = $this->id($tabla);
                    $db->statement("DELETE FROM main.$t");
                    if ($columnas) {
                        $lista = implode(', ', array_map($this->id(...), $columnas));
                        $db->statement("INSERT INTO main.$t ($lista) SELECT $lista FROM copia.$t");
                    }
                    $resumen[$tabla] = (int) $db->selectOne("SELECT COUNT(*) AS n FROM main.$t")->n;
                }
            });

            return $resumen;
        } finally {
            $db->statement('PRAGMA foreign_keys = ON');
            $pdo->exec('DETACH DATABASE copia');
        }
    }

    private function exigirSqlite(): void
    {
        if (! self::disponible()) {
            throw new RuntimeException('Las copias de seguridad solo estan disponibles con SQLite.');
        }
    }

    private function comprobarCabecera(string $ruta): void
    {
        $f = is_file($ruta) ? fopen($ruta, 'rb') : false;
        $cabecera = $f ? fread($f, 16) : '';
        if ($f) {
            fclose($f);
        }
        if ($cabecera !== "SQLite format 3\0") {
            throw new RuntimeException('El archivo no es una copia de seguridad (no es una base SQLite).');
        }
    }

    private function comprobarContenido(): void
    {
        $estado = DB::selectOne('PRAGMA copia.integrity_check');
        if (($estado ? array_values((array) $estado)[0] : null) !== 'ok') {
            throw new RuntimeException('La copia esta danada (falla la comprobacion de integridad).');
        }
        $faltan = array_diff(self::REQUERIDAS, $this->tablas('copia'));
        if ($faltan) {
            throw new RuntimeException('El archivo no parece una copia de esta aplicacion (faltan: '.implode(', ', $faltan).').');
        }
    }

    /** @return list<string> */
    private function tablas(string $base): array
    {
        return array_map(
            fn ($f) => $f->name,
            DB::select("SELECT name FROM $base.sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\'"),
        );
    }

    /** @return list<string> */
    private function columnas(string $base, string $tabla): array
    {
        return array_map(fn ($f) => $f->name, DB::select("PRAGMA $base.table_info(".$this->id($tabla).')'));
    }

    private function id(string $nombre): string
    {
        return '"'.str_replace('"', '""', $nombre).'"';
    }
}
