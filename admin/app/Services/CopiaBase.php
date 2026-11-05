<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

/**
 * Copia de seguridad de la base de datos: un volcado SQL de datos en PHP puro (sin pg_dump), TRUNCATE + INSERT por tabla dentro de
 * una transaccion, restaurable con psql sobre una base que ya tenga el esquema (migraciones). La aplicacion no restaura copias.
 */
class CopiaBase
{
    /** Tablas que no forman parte de la copia: estado de ejecucion, no datos de la persona. */
    private const OMITIR = ['migrations', 'auditoria_tablas', 'sessions', 'jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks', 'password_reset_tokens'];

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

    /** Nombre del fichero de descarga. */
    public static function nombreDescarga(): string
    {
        return 'nutrition-'.now()->format('Y-m-d-His').'.sql.gz';
    }

    /**
     * Vuelca los datos de PostgreSQL como SQL, enviando cada trozo a $salida.
     *
     * @param  callable(string): void  $salida
     * @param  PDO|null  $pdo  la base que se vuelca; sin ella, la de esta aplicacion
     * @return int numero de tablas volcadas
     */
    public function volcarSql(callable $salida, ?PDO $pdo = null): int
    {
        $pdo ??= DB::connection()->getPdo();
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
            // Sin triggers ni claves foraneas durante la carga (hace falta ser superusuario): restaurar no es un movimiento que auditar,
            // la auditoria viaja en la propia copia.
            $salida("BEGIN;\nSET client_encoding = 'UTF8';\nSET LOCAL session_replication_role = replica;\n");
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
                        $salida('SELECT setval(pg_get_serial_sequence('.self::texto($t).', '.self::texto($col['nombre'])."), COALESCE(MAX($c), 1), MAX($c) IS NOT NULL) FROM $t;\n");
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
     * @param  PDO|null  $pdo  la base que se vuelca; sin ella, la de esta aplicacion
     */
    public function volcarGzip(callable $salida, ?PDO $pdo = null): int
    {
        $z = deflate_init(ZLIB_ENCODING_GZIP);
        $buffer = '';
        $n = $this->volcarSql(function (string $trozo) use (&$buffer, $z, $salida): void {
            $buffer .= $trozo;
            if (strlen($buffer) >= 65536) {
                $salida(deflate_add($z, $buffer, ZLIB_NO_FLUSH));
                $buffer = '';
            }
        }, $pdo);
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
}
