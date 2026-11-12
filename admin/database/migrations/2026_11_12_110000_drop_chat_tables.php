<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El chat pasa al disco de cada instancia (SQLite, conexion `chat`, migraciones en database/migrations/chat): ya no hay tablas
 * `chat_*` en PostgreSQL. Se quitan tambien de la auditoria, de las tumbas y de la fusion de bases (que las nombraba).
 * Los mensajes y fotos que hubiera en PostgreSQL se pierden: la conversacion es de cada instancia.
 */
return new class extends Migration
{
    private const TABLAS = ['chat_peticiones_secreto', 'chat_mensajes', 'chat_archivos', 'chat_conversacion'];

    public function up(): void
    {
        DB::table('auditoria_tablas')->whereIn('tabla', self::TABLAS)->delete();
        DB::table('borrados')->whereIn('tabla', self::TABLAS)->delete();
        foreach (self::TABLAS as $tabla) {
            Schema::dropIfExists($tabla);   // los triggers de auditoria caen con la tabla
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION fusion_tablas() RETURNS text[] LANGUAGE sql IMMUTABLE AS $f$
    -- En orden de dependencias: lo referenciado antes que lo que referencia.
    SELECT ARRAY['users', 'alimentos', 'hogares', 'perfiles', 'menus', 'disponibilidad_dias', 'existencias', 'feedback', 'registros_comida',
                 'consumos_menu', 'notifications', 'agente_archivos', 'ajustes']
$f$
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION fusion_terminar() RETURNS jsonb LANGUAGE plpgsql AS $f$
DECLARE
    pendiente record;
    resumen jsonb;
BEGIN
    -- Referencias a filas que llegaron despues (la de un hogar a su menu): ahora que existen, se enlazan.
    FOR pendiente IN SELECT * FROM _fusion_pendientes LOOP
        EXECUTE format('UPDATE %I SET %I = $1::uuid WHERE id::text = $2 AND %I IS NULL AND EXISTS (SELECT 1 FROM %I WHERE id::text = $1)',
                       pendiente.tabla, pendiente.columna, pendiente.columna, pendiente.referencia) USING pendiente.valor, pendiente.id;
    END LOOP;

    SELECT COALESCE(jsonb_object_agg(concepto, n), '{}'::jsonb) INTO resumen FROM _fusion_resumen;
    INSERT INTO auditorias (id, evento, descripcion, datos, metodo, instancia, created_at)
    VALUES (uuidv7(), 'fusion', 'Datos fusionados desde ' || COALESCE(NULLIF(current_setting('nutrition.fusion_origen', true), ''), 'otra base'),
            resumen || jsonb_build_object('origen', current_setting('nutrition.fusion_origen', true)), 'fusion',
            NULLIF(current_setting('nutrition.fusion_ejecutor', true), ''), clock_timestamp()::timestamp(0));
    RETURN resumen;
END
$f$
SQL);
    }

    /** No se recrean las tablas: el chat ya vive en el disco. */
    public function down(): void {}
};
