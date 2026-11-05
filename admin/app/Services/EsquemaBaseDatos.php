<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * El esquema de la base de datos escrito como claves del `formal` de la red Celaut (ver config/nutrition.php y README).
 *
 * Una clave por tabla, `db.table.<tabla>`, con un valor de UNA sola linea (el `formal` se empaqueta como lineas `clave=valor`
 * y se compara byte a byte) y sin `${` (un valor es entero una variable o no lleva ninguna). Gramatica:
 *
 *   valor    := columnas { " | " restriccion }
 *   columnas := columna { "; " columna }               en el orden real de la tabla
 *   columna  := nombre ":" tipo [" null"] [" auto"] [" default=" expresion]
 *   restriccion, en este orden:
 *     "pk(" cols ")"                                   clave primaria (columnas en el orden del indice)
 *     "uq(" cols ")"                                   indices unicos, ordenados
 *     "idx(" cols ")"                                  indices no unicos, ordenados
 *     "fk(" cols "->" tabla "." cols " del=" accion " upd=" accion ")"    claves foraneas, ordenadas
 *   cols     := nombre { "," nombre }
 *
 *  - `tipo` es el de PostgreSQL tal como lo devuelve Schema::getColumns()['type'] (format_type): `bigint`,
 *    `character varying(255)`, `timestamp(0) without time zone`, `numeric(10,2)`, `jsonb`...
 *  - ` null` = admite nulos (sin la marca, NOT NULL). ` auto` = auto-incremental (serial; su `nextval(...)` no se repite como default).
 *  - `expresion` es el default tal como lo guarda PostgreSQL (`'borrador'::character varying`, `true`, `0`, `CURRENT_TIMESTAMP`).
 *  - acciones de las claves foraneas: `no action`, `restrict`, `cascade`, `set null`, `set default`. La tabla referenciada es
 *    del mismo esquema.
 *  - No llevan nombre los indices ni las claves (los deriva Laravel de tabla y columnas). Los indices que respaldan pk/uq
 *    no se repiten como `idx`.
 *  - La tabla `migrations` de Laravel no se incluye (es de la herramienta, no del esquema).
 *
 * Clase pura: recibe los arrays de Schema::getColumns()/getIndexes()/getForeignKeys() y no toca la base de datos.
 */
class EsquemaBaseDatos
{
    public const PREFIJO = 'db.table.';

    /** Tablas de la herramienta de migraciones que no forman parte del esquema. */
    public const EXCLUIDAS = ['migrations'];

    /**
     * La cadena de una tabla.
     *
     * @param  list<array{name: string, type: string, nullable?: bool, default?: ?string, auto_increment?: bool}>  $columnas  en el orden de la tabla
     * @param  list<array{columns: list<string>, unique?: bool, primary?: bool}>  $indices
     * @param  list<array{columns: list<string>, foreign_table: string, foreign_columns: list<string>, on_update?: ?string, on_delete?: ?string}>  $claves
     */
    public static function tabla(array $columnas, array $indices = [], array $claves = []): string
    {
        $partes = [implode('; ', array_map(self::columna(...), $columnas))];

        $primarias = $unicos = $normales = [];
        foreach ($indices as $indice) {
            $lista = self::lista($indice['columns']);
            if (! empty($indice['primary'])) {
                $primarias[] = "pk({$lista})";
            } elseif (! empty($indice['unique'])) {
                $unicos[] = "uq({$lista})";
            } else {
                $normales[] = "idx({$lista})";
            }
        }
        $foraneas = array_map(fn (array $clave) => sprintf(
            'fk(%s->%s.%s del=%s upd=%s)',
            self::lista($clave['columns']),
            self::nombre($clave['foreign_table']),
            self::lista($clave['foreign_columns']),
            self::accion($clave['on_delete'] ?? null),
            self::accion($clave['on_update'] ?? null),
        ), $claves);

        foreach ([$primarias, $unicos, $normales, $foraneas] as $grupo) {
            sort($grupo, SORT_STRING);
            array_push($partes, ...$grupo);
        }

        $valor = implode(' | ', $partes);
        if (str_contains($valor, "\n") || str_contains($valor, "\r") || str_contains($valor, '${')) {
            throw new InvalidArgumentException('Un valor del formal no puede llevar saltos de linea ni «${».');
        }

        return $valor;
    }

    /**
     * El mapa completo del esquema: ['db.table.<tabla>' => cadena], ordenado por clave y sin las tablas excluidas.
     *
     * @param  array<string, array{columns: list<array>, indexes?: list<array>, foreign_keys?: list<array>}>  $tablas  por nombre de tabla
     * @return array<string, string>
     */
    public static function mapa(array $tablas): array
    {
        $mapa = [];
        foreach ($tablas as $nombre => $tabla) {
            if (in_array($nombre, self::EXCLUIDAS, true)) {
                continue;
            }
            $mapa[self::PREFIJO.$nombre] = self::tabla($tabla['columns'], $tabla['indexes'] ?? [], $tabla['foreign_keys'] ?? []);
        }
        ksort($mapa, SORT_STRING);

        return $mapa;
    }

    /**
     * Los nombres de las columnas de una cadena, en orden.
     *
     * @return list<string>
     */
    public static function nombresDeColumnas(string $valor): array
    {
        $columnas = explode(' | ', $valor, 2)[0];

        return array_map(fn (string $columna) => explode(':', $columna, 2)[0], explode('; ', $columnas));
    }

    /** Las restricciones (pk, uq, idx, fk) de una cadena, en orden. @return list<string> */
    public static function restricciones(string $valor): array
    {
        return array_slice(explode(' | ', $valor), 1);
    }

    private static function columna(array $columna): string
    {
        $auto = ! empty($columna['auto_increment']);
        $texto = self::nombre($columna['name']).':'.self::texto($columna['type']);
        $texto .= ($columna['nullable'] ?? false) ? ' null' : '';
        $texto .= $auto ? ' auto' : '';
        if (! $auto && ($columna['default'] ?? null) !== null) {
            $texto .= ' default='.self::texto((string) $columna['default']);
        }

        return $texto;
    }

    /** @param  list<string>  $columnas */
    private static function lista(array $columnas): string
    {
        return implode(',', array_map(self::nombre(...), $columnas));
    }

    private static function accion(?string $accion): string
    {
        return $accion === null || $accion === '' ? 'no action' : strtolower($accion);
    }

    private static function nombre(string $nombre): string
    {
        if ($nombre === '' || preg_match('/[\s,:;|()=>]/', $nombre)) {
            throw new InvalidArgumentException("Nombre no representable en el formal: «{$nombre}».");
        }

        return $nombre;
    }

    private static function texto(string $texto): string
    {
        if (str_contains($texto, '; ') || str_contains($texto, ' | ') || preg_match('/[\r\n]/', $texto)) {
            throw new InvalidArgumentException("Texto no representable en el formal: «{$texto}».");
        }

        return $texto;
    }
}
