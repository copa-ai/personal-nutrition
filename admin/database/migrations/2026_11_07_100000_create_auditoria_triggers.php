<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tablas auditadas por la propia base de datos: tabla => [modelo, columnas omitidas, columnas que no se enmascaran, columnas cuyo
     * cambio, solo, no es un evento]. Un trigger por tabla anota cada alta, cambio y baja en `auditorias` aunque no pasen por Eloquent
     * (consultas directas, actualizaciones en bloque, psql, la fusion de datos). Quedan fuera las tablas efimeras (sesiones, cola, cache,
     * tokens de reinicio), `migrations` y la propia `auditorias`.
     *
     * Para una tabla nueva: `SELECT auditar_tabla('tabla', 'App\Models\Modelo')` en su migracion (y anotarla en TablasAuditadasTest).
     */
    private const TABLAS = [
        'users' => ['App\Models\User'],
        'hogares' => ['App\Models\Hogar'],
        'disponibilidad_dias' => ['App\Models\DisponibilidadDia'],
        'perfiles' => ['App\Models\Perfil'],
        'alimentos' => ['App\Models\Alimento'],
        'existencias' => ['App\Models\Existencia'],
        'feedback' => ['App\Models\Feedback'],
        'menus' => ['App\Models\Menu'],
        'consumos_menu' => ['App\Models\ConsumoMenu'],
        'registros_comida' => ['App\Models\RegistroComida'],
        'notifications' => ['Illuminate\Notifications\DatabaseNotification'],
        'agente_archivos' => ['App\Models\AgenteArchivo', 'contenido'],
        'ajustes' => ['App\Models\Ajuste', '', 'clave'],
        'chat_conversacion' => ['App\Models\ChatConversacion'],
        // Los deltas de una respuesta que se escribe solo tocan contenido y razonamiento: no son un evento cada uno.
        'chat_mensajes' => ['App\Models\ChatMensaje', 'contenido,razonamiento,sugerencias,corrector,error', '', 'contenido,razonamiento,updated_ms'],
        'chat_archivos' => ['App\Models\ChatArchivo', 'contenido'],
        'chat_peticiones_secreto' => ['App\Models\ChatPeticionSecreto'],
    ];

    public function up(): void
    {
        // Como se audita cada tabla (lo lee el trigger y la fusion de datos).
        Schema::create('auditoria_tablas', function (Blueprint $table): void {
            $table->string('tabla')->primary();
            $table->string('modelo');
            $table->text('omitir')->default('');          // columnas que solo dejan su tamano, separadas por comas
            $table->text('sin_mascara')->default('');     // columnas cuyo nombre parece sensible y no lo es
            $table->text('ignorar')->default('');         // columnas cuyo cambio, solo, no es un evento
        });

        // Tumbas de las filas borradas: al fusionar dos bases, una fila borrada en una no reaparece por estar en la otra.
        Schema::create('borrados', function (Blueprint $table): void {
            $table->string('tabla');
            $table->string('id');
            $table->timestamp('borrado_en');
            $table->string('instancia', 64)->nullable();
            $table->primary(['tabla', 'id']);
        });

        // Oculta los campos sensibles (mismos nombres que App\Models\Auditoria::esSensible), tambien dentro de json, y recorta los textos
        // de mas de 500 caracteres.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION auditoria_enmascarar(valor jsonb, sin_mascara text[] DEFAULT '{}') RETURNS jsonb
LANGUAGE plpgsql IMMUTABLE AS $f$
DECLARE
    resultado jsonb;
    campo text;
    contenido jsonb;
BEGIN
    CASE jsonb_typeof(valor)
        WHEN 'object' THEN
            resultado := '{}'::jsonb;
            FOR campo, contenido IN SELECT key, value FROM jsonb_each(valor) LOOP
                IF campo ~* '(password|token|secret|clave|key)' AND NOT (campo = ANY (sin_mascara)) THEN
                    resultado := resultado || jsonb_build_object(campo, '***');
                ELSE
                    resultado := resultado || jsonb_build_object(campo, auditoria_enmascarar(contenido, sin_mascara));
                END IF;
            END LOOP;
            RETURN resultado;
        WHEN 'array' THEN
            RETURN COALESCE((SELECT jsonb_agg(auditoria_enmascarar(e.value, sin_mascara) ORDER BY e.posicion)
                             FROM jsonb_array_elements(valor) WITH ORDINALITY AS e(value, posicion)), '[]'::jsonb);
        WHEN 'string' THEN
            IF char_length(valor #>> '{}') > 500 THEN
                RETURN to_jsonb(left(valor #>> '{}', 500) || '… [truncado]');
            END IF;
            RETURN valor;
        ELSE
            RETURN valor;
    END CASE;
END
$f$
SQL);

        // Una fila (o parte de ella) lista para guardar: las columnas omitidas solo dejan su tamano, las sensibles se enmascaran y lo
        // que sigue siendo enorme (un json de varios KB) se recorta.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION auditoria_fila(fila jsonb, omitir text[], sin_mascara text[]) RETURNS jsonb
LANGUAGE plpgsql IMMUTABLE AS $f$
DECLARE
    resultado jsonb := '{}'::jsonb;
    campo text;
    contenido jsonb;
    texto text;
BEGIN
    FOR campo, contenido IN SELECT key, value FROM jsonb_each(fila) LOOP
        IF campo = ANY (omitir) THEN
            texto := CASE jsonb_typeof(contenido) WHEN 'null' THEN '' WHEN 'string' THEN contenido #>> '{}' ELSE contenido::text END;
            -- un bytea llega como «\xAABB»: dos caracteres hexadecimales por byte
            contenido := to_jsonb('[omitido: ' || CASE WHEN left(texto, 2) = '\x' THEN (octet_length(texto) - 2) / 2 ELSE octet_length(texto) END || ' bytes]');
        ELSIF campo ~* '(password|token|secret|clave|key)' AND NOT (campo = ANY (sin_mascara)) THEN
            contenido := to_jsonb('***'::text);
        ELSE
            contenido := auditoria_enmascarar(contenido, sin_mascara);
            IF jsonb_typeof(contenido) IN ('object', 'array') AND char_length(contenido::text) > 500 THEN
                contenido := to_jsonb(left(contenido::text, 500) || '… [truncado]');
            END IF;
        END IF;
        resultado := resultado || jsonb_build_object(campo, contenido);
    END LOOP;
    RETURN resultado;
END
$f$
SQL);

        // El trigger. Argumentos: modelo, columnas omitidas, columnas que no se enmascaran, columnas cuyo cambio solo no cuenta.
        // El usuario, la ip, la url y la instancia los deja la aplicacion en la sesion (nutrition.*) antes de cada escritura; sin ellos
        // (psql, la fusion de datos) el movimiento queda con metodo «sql» y el rol de la base en `datos`.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION auditar_cambio() RETURNS trigger
LANGUAGE plpgsql AS $f$
DECLARE
    modelo text;
    omitir text[];
    sin_mascara text[];
    ignorar text[];
    antiguo jsonb;
    nuevo jsonb;
    evento text;
    identificador text;
    valores_antiguos jsonb;
    valores_nuevos jsonb;
    cambiadas text[];
BEGIN
    -- La fusion de datos anota sus propios movimientos (fusion_descartada, fusion) y no deja un «creado» por cada fila que trae.
    IF current_setting('nutrition.fusionando', true) = 'on' THEN
        RETURN NULL;
    END IF;

    SELECT t.modelo, string_to_array(t.omitir, ','), string_to_array(t.sin_mascara, ','), string_to_array(t.ignorar, ',')
      INTO modelo, omitir, sin_mascara, ignorar
      FROM auditoria_tablas t WHERE t.tabla = TG_TABLE_NAME;

    IF TG_OP = 'INSERT' THEN
        nuevo := to_jsonb(NEW);
        evento := 'creado';
        identificador := nuevo ->> 'id';
        valores_nuevos := auditoria_fila(nuevo, omitir, sin_mascara);
    ELSIF TG_OP = 'DELETE' THEN
        antiguo := to_jsonb(OLD);
        evento := 'eliminado';
        identificador := antiguo ->> 'id';
        valores_antiguos := auditoria_fila(antiguo, omitir, sin_mascara);
        INSERT INTO borrados (tabla, id, borrado_en, instancia)
        VALUES (TG_TABLE_NAME, identificador, clock_timestamp()::timestamp(0), NULLIF(current_setting('nutrition.instancia', true), ''))
        ON CONFLICT (tabla, id) DO UPDATE SET borrado_en = EXCLUDED.borrado_en, instancia = EXCLUDED.instancia;
    ELSE
        antiguo := to_jsonb(OLD);
        nuevo := to_jsonb(NEW);
        identificador := nuevo ->> 'id';
        IF antiguo ? 'deleted_at' AND jsonb_typeof(antiguo -> 'deleted_at') = 'null' AND jsonb_typeof(nuevo -> 'deleted_at') <> 'null' THEN
            evento := 'eliminado';
            valores_antiguos := auditoria_fila(antiguo, omitir, sin_mascara);
        ELSIF antiguo ? 'deleted_at' AND jsonb_typeof(antiguo -> 'deleted_at') <> 'null' AND jsonb_typeof(nuevo -> 'deleted_at') = 'null' THEN
            evento := 'restaurado';
            valores_nuevos := auditoria_fila(nuevo, omitir, sin_mascara);
        ELSE
            cambiadas := ARRAY(SELECT key FROM jsonb_each(nuevo) WHERE antiguo -> key IS DISTINCT FROM nuevo -> key AND key <> 'updated_at' ORDER BY key);
            IF NOT EXISTS (SELECT 1 FROM unnest(cambiadas) AS c WHERE NOT (c = ANY (ignorar))) THEN
                RETURN NULL;
            END IF;
            evento := 'actualizado';
            valores_antiguos := auditoria_fila((SELECT jsonb_object_agg(key, value) FROM jsonb_each(antiguo) WHERE key = ANY (cambiadas)), omitir, sin_mascara);
            valores_nuevos := auditoria_fila((SELECT jsonb_object_agg(key, value) FROM jsonb_each(nuevo) WHERE key = ANY (cambiadas)), omitir, sin_mascara);
        END IF;
    END IF;

    INSERT INTO auditorias (id, user_id, user_nombre, user_email, evento, auditable_type, auditable_id, valores_antiguos, valores_nuevos,
                            datos, ip, user_agent, url, metodo, instancia, created_at)
    VALUES (uuidv7(),
            NULLIF(current_setting('nutrition.user_id', true), '')::uuid,
            NULLIF(current_setting('nutrition.user_nombre', true), ''),
            NULLIF(current_setting('nutrition.user_email', true), ''),
            evento, modelo, identificador, valores_antiguos, valores_nuevos,
            jsonb_build_object('tabla', TG_TABLE_NAME, 'rol', current_user),
            NULLIF(current_setting('nutrition.ip', true), ''),
            NULLIF(current_setting('nutrition.user_agent', true), ''),
            NULLIF(current_setting('nutrition.url', true), ''),
            COALESCE(NULLIF(current_setting('nutrition.metodo', true), ''), 'sql'),
            NULLIF(current_setting('nutrition.instancia', true), ''),
            clock_timestamp()::timestamp(0));
    RETURN NULL;
END
$f$
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION auditar_tabla(tabla regclass, modelo text, omitir text DEFAULT '', sin_mascara text DEFAULT '', ignorar text DEFAULT '') RETURNS void
LANGUAGE plpgsql AS $f$
BEGIN
    INSERT INTO auditoria_tablas (tabla, modelo, omitir, sin_mascara, ignorar)
    VALUES (tabla::text, modelo, omitir, sin_mascara, ignorar)
    ON CONFLICT ON CONSTRAINT auditoria_tablas_pkey DO UPDATE SET modelo = EXCLUDED.modelo, omitir = EXCLUDED.omitir, sin_mascara = EXCLUDED.sin_mascara, ignorar = EXCLUDED.ignorar;
    EXECUTE format('DROP TRIGGER IF EXISTS auditar ON %s', tabla);
    EXECUTE format('CREATE TRIGGER auditar AFTER INSERT OR UPDATE OR DELETE ON %s FOR EACH ROW EXECUTE FUNCTION auditar_cambio()', tabla);
END
$f$
SQL);

        // Los registros de auditoria no se tocan: ni la aplicacion ni nadie con acceso a la base (salvo quien desactive los triggers,
        // como la restauracion de una copia).
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION auditoria_inmutable() RETURNS trigger
LANGUAGE plpgsql AS $f$
BEGIN
    RAISE EXCEPTION 'Los registros de auditoria son inmutables.' USING ERRCODE = 'integrity_constraint_violation';
END
$f$
SQL);
        DB::unprepared('CREATE TRIGGER auditorias_inmutable BEFORE UPDATE OR DELETE ON auditorias FOR EACH ROW EXECUTE FUNCTION auditoria_inmutable()');

        foreach (self::TABLAS as $tabla => $argumentos) {
            DB::select('SELECT auditar_tabla(?, ?, ?, ?, ?)', [$tabla, $argumentos[0], $argumentos[1] ?? '', $argumentos[2] ?? '', $argumentos[3] ?? '']);
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TABLAS) as $tabla) {
            DB::unprepared("DROP TRIGGER IF EXISTS auditar ON \"{$tabla}\"");
        }
        DB::unprepared('DROP TRIGGER IF EXISTS auditorias_inmutable ON auditorias');
        DB::unprepared('DROP FUNCTION IF EXISTS auditoria_inmutable()');
        DB::unprepared('DROP FUNCTION IF EXISTS auditar_tabla(regclass, text, text, text, text)');
        DB::unprepared('DROP FUNCTION IF EXISTS auditar_cambio()');
        DB::unprepared('DROP FUNCTION IF EXISTS auditoria_fila(jsonb, text[], text[])');
        DB::unprepared('DROP FUNCTION IF EXISTS auditoria_enmascarar(jsonb, text[])');
        Schema::dropIfExists('borrados');
        Schema::dropIfExists('auditoria_tablas');
    }
};
