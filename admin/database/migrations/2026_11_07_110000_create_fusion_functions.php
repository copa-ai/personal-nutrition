<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Fusion de dos bases con el mismo esquema (la de otra bifurcacion entra en esta). Funciones de PostgreSQL, asi que la fusion la hace
     * el propio servicio de la base, de forma atomica, y se prueba contra el motor real. Las llama App\Services\Fusion\Fusionador:
     *
     *     BEGIN;
     *     SELECT fusion_iniciar(origen, esquema, ejecutor);
     *     SELECT fusion_borrados(filas);                -- tumbas del origen
     *     SELECT fusion_tabla('hogares', filas);        -- por tabla, en el orden de fusion_tablas(), por tandas
     *     SELECT fusion_auditorias(filas);              -- la auditoria del origen se une a la de aqui
     *     SELECT fusion_terminar();                     -- resumen
     *     COMMIT;
     *
     * Reglas (docs en el README, «Fusion de bases»): la clave primaria es un UUID; de dos versiones de una fila se queda la de
     * `updated_at` mayor (a igualdad, la de mayor md5 del contenido: el mismo resultado en las dos direcciones) y la otra queda en la
     * auditoria como `fusion_descartada` con lo que difiere; una fila borrada en un lado no reaparece (tumbas en `borrados`: gana la
     * accion mas nueva); dos filas distintas con la misma clave unica son la misma (se queda el id de aqui y las referencias del origen se
     * reasignan); una fila cuya referencia obligatoria no existe se descarta.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION fusion_tablas() RETURNS text[] LANGUAGE sql IMMUTABLE AS $f$
    -- En orden de dependencias: lo referenciado antes que lo que referencia.
    SELECT ARRAY['users', 'alimentos', 'hogares', 'perfiles', 'menus', 'disponibilidad_dias', 'existencias', 'feedback', 'registros_comida',
                 'consumos_menu', 'notifications', 'agente_archivos', 'ajustes', 'chat_conversacion', 'chat_archivos', 'chat_mensajes',
                 'chat_peticiones_secreto']
$f$
SQL);

        // Huella del esquema: columnas y tipos de lo que se fusiona. Dos bases con distinto esquema no se fusionan.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION fusion_esquema() RETURNS text LANGUAGE sql STABLE AS $f$
    SELECT md5(string_agg(table_name || '.' || column_name || ':' || data_type, ',' ORDER BY table_name, ordinal_position))
      FROM information_schema.columns
     WHERE table_schema = current_schema() AND table_name = ANY (fusion_tablas() || ARRAY['borrados', 'auditorias'])
$f$
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION fusion_iniciar(origen text, esquema text, ejecutor text DEFAULT '') RETURNS void LANGUAGE plpgsql AS $f$
BEGIN
    PERFORM pg_advisory_xact_lock(hashtext('nutrition.fusion'));
    IF esquema IS DISTINCT FROM fusion_esquema() THEN
        RAISE EXCEPTION 'El esquema de los datos a fusionar no es el de esta base.' USING ERRCODE = 'invalid_parameter_value';
    END IF;
    PERFORM set_config('nutrition.fusionando', 'on', true);
    PERFORM set_config('nutrition.fusion_origen', COALESCE(origen, ''), true);
    PERFORM set_config('nutrition.fusion_ejecutor', COALESCE(ejecutor, ''), true);
    CREATE TEMP TABLE IF NOT EXISTS _fusion_mapa (tabla text, id_entrante text, id_local text, PRIMARY KEY (tabla, id_entrante)) ON COMMIT DROP;
    CREATE TEMP TABLE IF NOT EXISTS _fusion_resumen (concepto text PRIMARY KEY, n integer NOT NULL DEFAULT 0) ON COMMIT DROP;
    CREATE TEMP TABLE IF NOT EXISTS _fusion_pendientes (tabla text, id text, columna text, valor text, referencia text) ON COMMIT DROP;
    DELETE FROM _fusion_mapa;
    DELETE FROM _fusion_resumen;
    DELETE FROM _fusion_pendientes;
END
$f$
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION fusion_contar(p_concepto text, p_cuantas integer DEFAULT 1) RETURNS void LANGUAGE plpgsql AS $f$
BEGIN
    INSERT INTO _fusion_resumen (concepto, n) VALUES (p_concepto, p_cuantas) ON CONFLICT (concepto) DO UPDATE SET n = _fusion_resumen.n + p_cuantas;
END
$f$
SQL);

        // Anota en la auditoria la version que se descarta: valores_antiguos = lo que se queda, valores_nuevos = lo que se pierde (solo
        // las columnas que difieren). Con ganadora NULL la fila perdida es la que se borra.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION fusion_descartar(tabla text, identificador text, ganadora jsonb, perdedora jsonb, accion text, motivo text) RETURNS void LANGUAGE plpgsql AS $f$
DECLARE
    cfg auditoria_tablas%ROWTYPE;
    distintas text[];
    vale jsonb;
    vperdida jsonb;
BEGIN
    SELECT * INTO cfg FROM auditoria_tablas t WHERE t.tabla = fusion_descartar.tabla;
    distintas := ARRAY(SELECT key FROM jsonb_each(COALESCE(perdedora, '{}'::jsonb))
                        WHERE key <> 'updated_at' AND (ganadora IS NULL OR ganadora -> key IS DISTINCT FROM perdedora -> key) ORDER BY key);
    IF ganadora IS NOT NULL THEN
        vale := auditoria_fila((SELECT COALESCE(jsonb_object_agg(key, value), '{}'::jsonb) FROM jsonb_each(ganadora) WHERE key = ANY (distintas)),
                               string_to_array(cfg.omitir, ','), string_to_array(cfg.sin_mascara, ','));
    END IF;
    IF perdedora IS NOT NULL THEN
        vperdida := auditoria_fila((SELECT COALESCE(jsonb_object_agg(key, value), '{}'::jsonb) FROM jsonb_each(perdedora) WHERE key = ANY (distintas)),
                                   string_to_array(cfg.omitir, ','), string_to_array(cfg.sin_mascara, ','));
    END IF;

    INSERT INTO auditorias (id, evento, auditable_type, auditable_id, valores_antiguos, valores_nuevos, descripcion, datos, metodo, instancia, created_at)
    VALUES (uuidv7(), 'fusion_descartada', cfg.modelo, identificador, vale, CASE WHEN ganadora IS NULL THEN NULL ELSE vperdida END, motivo,
            jsonb_build_object('tabla', tabla, 'accion', accion, 'origen', current_setting('nutrition.fusion_origen', true),
                               'se_queda_actualizado_en', ganadora ->> 'updated_at', 'descartada_actualizada_en', perdedora ->> 'updated_at')
              || CASE WHEN ganadora IS NULL THEN jsonb_build_object('fila_descartada', auditoria_fila(perdedora, string_to_array(cfg.omitir, ','), string_to_array(cfg.sin_mascara, ','))) ELSE '{}'::jsonb END,
            'fusion', NULLIF(current_setting('nutrition.fusion_ejecutor', true), ''), clock_timestamp()::timestamp(0));
    PERFORM fusion_contar('descartadas');
END
$f$
SQL);

        // Los campos de ejecucion de un menu (proceso, tandas, espera) son de quien lo ejecuta: no se importan. Un menu que corria en el
        // origen se importa como fallido; si aqui corre, se deja como esta.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION fusion_ajustar_menu(fila jsonb, local jsonb) RETURNS jsonb LANGUAGE plpgsql IMMUTABLE AS $f$
DECLARE
    activos text[] := ARRAY['en_cola', 'en_marcha', 'en_espera'];
    ejecucion text[] := ARRAY['proceso_pid', 'ejecucion_id', 'iniciado_en', 'proximo_ciclo_en', 'detener_pedido', 'ciclo', 'estado', 'error'];
    columna text;
BEGIN
    IF local IS NOT NULL AND (local ->> 'estado') = ANY (activos) THEN
        FOREACH columna IN ARRAY ejecucion LOOP
            fila := jsonb_set(fila, ARRAY[columna], local -> columna);
        END LOOP;
        RETURN fila;
    END IF;
    fila := fila || jsonb_build_object('proceso_pid', NULL, 'ejecucion_id', NULL, 'proximo_ciclo_en', NULL, 'detener_pedido', false);
    IF (fila ->> 'estado') = ANY (activos) THEN
        fila := fila || jsonb_build_object('estado', 'fallido', 'error', 'La generación se interrumpió al fusionar las bases: corría en otro dispositivo.');
    END IF;
    RETURN fila;
END
$f$
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION fusion_fila(tabla text, fila jsonb) RETURNS void LANGUAGE plpgsql AS $f$
DECLARE
    identificador text := fila ->> 'id';
    local jsonb;
    conflicto jsonb;
    existe boolean;
    referencia record;
    clave text[];
    condicion text;
    columna text;
    valor text;
    actualizacion timestamp;
    columnas text;
    distintas text[];
    gana_entrante boolean;
    maximo bigint;
    borrado timestamp;
BEGIN
    -- 1. Referencias: se reasignan a los ids de aqui y las que no existen se anulan (si pueden) o descartan la fila.
    FOR referencia IN
        SELECT a.attname AS columna, r.relname AS tabla_ref, a.attnotnull AS obligatoria
          FROM pg_constraint c
          JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = c.conkey[1]
          JOIN pg_class r ON r.oid = c.confrelid
         WHERE c.contype = 'f' AND c.conrelid = tabla::regclass
    LOOP
        valor := fila ->> referencia.columna;
        CONTINUE WHEN valor IS NULL;
        SELECT m.id_local INTO valor FROM _fusion_mapa m WHERE m.tabla = referencia.tabla_ref AND m.id_entrante = valor;
        valor := COALESCE(valor, fila ->> referencia.columna);
        fila := jsonb_set(fila, ARRAY[referencia.columna], to_jsonb(valor));
        EXECUTE format('SELECT EXISTS (SELECT 1 FROM %I WHERE id::text = $1)', referencia.tabla_ref) INTO existe USING valor;
        IF NOT existe THEN
            IF referencia.obligatoria THEN
                PERFORM fusion_descartar(tabla, identificador, NULL, fila, 'huerfana',
                                         format('Fila descartada: su %s ya no existe en la base de destino', referencia.tabla_ref));
                RETURN;
            END IF;
            INSERT INTO _fusion_pendientes (tabla, id, columna, valor, referencia) VALUES (tabla, identificador, referencia.columna, valor, referencia.tabla_ref);
            fila := jsonb_set(fila, ARRAY[referencia.columna], 'null'::jsonb);
        END IF;
    END LOOP;

    -- 2. Una tumba mas nueva que la fila: se borro aqui despues de tocarla en el origen.
    SELECT b.borrado_en INTO borrado FROM borrados b WHERE b.tabla = fusion_fila.tabla AND b.id = identificador;
    IF borrado IS NOT NULL AND borrado > COALESCE((fila ->> 'updated_at')::timestamp, '-infinity') THEN
        PERFORM fusion_descartar(tabla, identificador, NULL, fila, 'borrada_aqui', 'Fila descartada: se borró aquí después de su última edición en el origen');
        RETURN;
    END IF;

    EXECUTE format('SELECT to_jsonb(t) FROM %I t WHERE t.id = (jsonb_populate_record(NULL::%I, $1)).id', tabla, tabla) INTO local USING fila;

    IF local IS NULL THEN
        -- 3. Otra fila con la misma clave unica es la misma cosa con otro id: se queda el de aqui.
        FOR clave IN
            SELECT array_agg(a.attname::text ORDER BY k.orden)
              FROM pg_index i
              CROSS JOIN LATERAL unnest(i.indkey) WITH ORDINALITY AS k(numero, orden)
              JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = k.numero
             WHERE i.indisunique AND NOT i.indisprimary AND i.indrelid = tabla::regclass
             GROUP BY i.indexrelid
        LOOP
            -- el orden de los mensajes del chat no es identidad: se renumera mas abajo
            CONTINUE WHEN tabla = 'chat_mensajes' AND clave = ARRAY['sequence'];
            CONTINUE WHEN EXISTS (SELECT 1 FROM unnest(clave) AS c WHERE fila ->> c IS NULL);
            condicion := (SELECT string_agg(format('t.%I::text = $2 ->> %L', c, c), ' AND ') FROM unnest(clave) AS c);
            EXECUTE format('SELECT to_jsonb(t) FROM %I t WHERE %s AND t.id::text <> $1 LIMIT 1', tabla, condicion) INTO conflicto USING identificador, fila;
            IF conflicto IS NOT NULL THEN
                INSERT INTO _fusion_mapa (tabla, id_entrante, id_local) VALUES (tabla, identificador, conflicto ->> 'id') ON CONFLICT DO NOTHING;
                fila := jsonb_set(fila, ARRAY['id'], conflicto -> 'id');
                local := conflicto;
                identificador := fila ->> 'id';
                EXIT;
            END IF;
        END LOOP;
    END IF;

    IF tabla = 'menus' THEN
        fila := fusion_ajustar_menu(fila, local);
    END IF;

    IF local IS NULL THEN
        IF tabla = 'chat_mensajes' THEN
            EXECUTE 'SELECT 1 FROM chat_mensajes WHERE sequence = $1' INTO maximo USING (fila ->> 'sequence')::bigint;
            IF maximo IS NOT NULL THEN
                SELECT COALESCE(max(sequence), 0) + 1 INTO maximo FROM chat_mensajes;
                fila := jsonb_set(fila, ARRAY['sequence'], to_jsonb(maximo));
                PERFORM fusion_contar('mensajes_renumerados');
            END IF;
        END IF;
        EXECUTE format('INSERT INTO %I SELECT * FROM jsonb_populate_record(NULL::%I, $1)', tabla, tabla) USING fila;
        PERFORM fusion_contar('insertadas');
        RETURN;
    END IF;

    -- 4. La misma fila en los dos lados: se queda la mas nueva.
    distintas := ARRAY(SELECT key FROM jsonb_each(fila) WHERE key <> 'updated_at' AND local -> key IS DISTINCT FROM fila -> key ORDER BY key);
    IF distintas = '{}' THEN
        PERFORM fusion_contar('iguales');
        RETURN;
    END IF;

    actualizacion := COALESCE((fila ->> 'updated_at')::timestamp, '-infinity');
    gana_entrante := actualizacion > COALESCE((local ->> 'updated_at')::timestamp, '-infinity')
        OR (actualizacion = COALESCE((local ->> 'updated_at')::timestamp, '-infinity') AND md5(fila::text) > md5(local::text));

    IF gana_entrante THEN
        PERFORM fusion_descartar(tabla, identificador, fila, local, 'sustituida', 'Versión de esta base sustituida por otra más nueva de la fusión');
        columnas := (SELECT string_agg(format('%I', c), ', ') FROM unnest(distintas) AS c WHERE c <> 'id');
        IF columnas IS NOT NULL THEN
            EXECUTE format('UPDATE %I t SET (%s) = (SELECT %s FROM jsonb_populate_record(NULL::%I, $1) r) WHERE t.id = (jsonb_populate_record(NULL::%I, $1)).id',
                           tabla, columnas, (SELECT string_agg(format('r.%I', c), ', ') FROM unnest(distintas) AS c WHERE c <> 'id'), tabla, tabla)
                USING fila;
        END IF;
        EXECUTE format('UPDATE %I t SET updated_at = $2::timestamp WHERE t.id = (jsonb_populate_record(NULL::%I, $1)).id', tabla, tabla) USING fila, fila ->> 'updated_at';
        PERFORM fusion_contar('actualizadas');
    ELSE
        PERFORM fusion_descartar(tabla, identificador, local, fila, 'descartada', 'Versión de la fusión descartada: la de esta base es más nueva');
    END IF;
END
$f$
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION fusion_tabla(tabla text, filas jsonb) RETURNS void LANGUAGE plpgsql AS $f$
DECLARE
    fila jsonb;
BEGIN
    IF NOT (tabla = ANY (fusion_tablas())) THEN
        RAISE EXCEPTION 'La tabla % no se fusiona.', tabla USING ERRCODE = 'invalid_parameter_value';
    END IF;
    FOR fila IN SELECT e.value FROM jsonb_array_elements(filas) WITH ORDINALITY AS e(value, posicion) ORDER BY e.posicion LOOP
        PERFORM fusion_fila(tabla, fila);
    END LOOP;
END
$f$
SQL);

        // Las tumbas del origen: una fila de aqui que no se ha tocado desde que se borro alli, se borra; si se toco despues, se queda.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION fusion_borrados(filas jsonb) RETURNS void LANGUAGE plpgsql AS $f$
DECLARE
    tumba jsonb;
    nombre text;
    identificador text;
    cuando timestamp;
    local jsonb;
BEGIN
    FOR tumba IN SELECT e.value FROM jsonb_array_elements(filas) AS e(value) LOOP
        nombre := tumba ->> 'tabla';
        identificador := tumba ->> 'id';
        cuando := (tumba ->> 'borrado_en')::timestamp;
        CONTINUE WHEN NOT (nombre = ANY (fusion_tablas()));

        EXECUTE format('SELECT to_jsonb(t) FROM %I t WHERE t.id::text = $1', nombre) INTO local USING identificador;
        IF local IS NOT NULL AND COALESCE((local ->> 'updated_at')::timestamp, '-infinity') >= cuando THEN
            PERFORM fusion_descartar(nombre, identificador, local, NULL, 'borrado_descartado',
                                     'Borrado de la fusión descartado: esta fila se editó aquí después de borrarse en el origen');
            CONTINUE;
        END IF;
        IF local IS NOT NULL THEN
            EXECUTE format('DELETE FROM %I WHERE id::text = $1', nombre) USING identificador;
            PERFORM fusion_descartar(nombre, identificador, NULL, local, 'borrada_en_el_origen', 'Fila borrada: se borró en el origen después de su última edición aquí');
            PERFORM fusion_contar('borradas');
        END IF;
        INSERT INTO borrados (tabla, id, borrado_en, instancia) VALUES (nombre, identificador, cuando, tumba ->> 'instancia')
        ON CONFLICT (tabla, id) DO UPDATE SET borrado_en = GREATEST(borrados.borrado_en, EXCLUDED.borrado_en);
    END LOOP;
END
$f$
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION fusion_auditorias(filas jsonb) RETURNS void LANGUAGE plpgsql AS $f$
DECLARE
    n integer;
BEGIN
    INSERT INTO auditorias SELECT * FROM jsonb_populate_recordset(NULL::auditorias, filas) ON CONFLICT (id) DO NOTHING;
    GET DIAGNOSTICS n = ROW_COUNT;
    PERFORM fusion_contar('auditorias', n);
END
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
    -- El contador de mensajes del chat sigue al ultimo mensaje.
    UPDATE chat_conversacion SET ultima_secuencia = GREATEST(ultima_secuencia, (SELECT COALESCE(max(sequence), 0) FROM chat_mensajes));

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

    public function down(): void
    {
        foreach (['fusion_terminar()', 'fusion_auditorias(jsonb)', 'fusion_borrados(jsonb)', 'fusion_tabla(text, jsonb)', 'fusion_fila(text, jsonb)',
            'fusion_ajustar_menu(jsonb, jsonb)', 'fusion_descartar(text, text, jsonb, jsonb, text, text)', 'fusion_contar(text, integer)',
            'fusion_iniciar(text, text, text)', 'fusion_esquema()', 'fusion_tablas()'] as $funcion) {
            DB::unprepared("DROP FUNCTION IF EXISTS {$funcion}");
        }
    }
};
