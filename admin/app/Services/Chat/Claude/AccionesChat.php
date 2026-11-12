<?php

namespace App\Services\Chat\Claude;

use App\Models\Auditoria;
use App\Services\Agente\AlmacenAgente;
use App\Services\Chat\Claude\Acciones\GrupoAcciones;
use App\Services\Chat\Claude\Acciones\GrupoComidas;
use App\Services\Chat\Claude\Acciones\GrupoDespensa;
use App\Services\Chat\Claude\Acciones\GrupoMenus;
use App\Services\Chat\Claude\Acciones\GrupoPersonas;
use App\Services\Menu\EjecutorAgente;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Acciones que el agente PROPONE en workspace/chat-acciones-{id}.json (el agente nunca escribe en la base ni tiene credenciales).
 * Laravel las valida con reglas estrictas y reparte cada una en su grupo (Acciones/Grupo*): las invalidas se rechazan con el motivo.
 *  - Las NO destructivas se aplican al llegar, cada una aislada de las demas (un fallo previsible rechaza solo esa; un error
 *    inesperado deshace todas las de este turno).
 *  - Las DESTRUCTIVAS (borrar, descartar cambios, activar otro menu, rehacer un menu, restar existencias, cancelar una generacion) NO se
 *    ejecutan: quedan como `pendiente` y ConfirmacionAcciones las guarda para que la persona las confirme con un teclado. El agente no
 *    puede confirmar: la confirmacion es un mensaje de la persona.
 * Cada accion queda en la auditoria (chat.accion_aplicada / chat.accion_rechazada / chat.accion_pendiente / chat.accion_confirmada).
 *
 * Archivo: {"acciones": [ {"tipo": "...", ...}, ... ]}. Catalogo (campos en cada Grupo*; * = pide confirmacion):
 *   comidas   registrar_comida corregir_comida borrar_registro_comida* anotar_feedback editar_feedback borrar_feedback*
 *   personas  crear_hogar renombrar_hogar borrar_hogar* crear_perfil editar_perfil borrar_perfil* fijar_tiempos borrar_tiempos*
 *   despensa  crear_alimento editar_alimento borrar_alimento* anadir_existencia editar_existencia borrar_existencia* restar_existencias*
 *   menus     crear_menu generar_menu reintentar_generacion cancelar_generacion* activar_menu(*) elegir_opcion editar_menu_con_comentario*
 *             intercambiar_menu descartar_cambios_menu* editar_opcion_menu cambiar_categorias_toma borrar_menu*
 * Todas se validan ANTES de aplicar ninguna: una accion no puede apoyarse en algo que otra crea en el mismo turno.
 * Sin tipo desconocido ni claves desconocidas.
 */
class AccionesChat
{
    /** @var list<GrupoAcciones> */
    private array $grupos;

    public function __construct(Validadores $validadores)
    {
        $this->grupos = [new GrupoComidas($validadores), new GrupoPersonas($validadores), new GrupoDespensa($validadores), new GrupoMenus($validadores)];
    }

    /** Lista cerrada de tipos de accion. @return list<string> */
    public function tipos(): array
    {
        return array_merge(...array_map(fn (GrupoAcciones $g): array => array_keys($g->claves()), $this->grupos));
    }

    public static function relativa(string $id): string
    {
        return "workspace/chat-acciones-{$id}.json";
    }

    /** Lee el archivo que dejo el agente (de la base si el almacen esta activo, si no del disco). Null si no hay. */
    public static function leerArchivo(string $id): ?string
    {
        $ruta = EjecutorAgente::carpeta().'/'.static::relativa($id);
        AlmacenAgente::activo() && AlmacenAgente::bajar(EjecutorAgente::carpeta(), static::relativa($id));

        if (! is_file($ruta) || is_link($ruta)) {
            return null;
        }

        // Como mucho un byte mas que el tope: procesar() lo rechaza, pero no se carga en memoria un archivo enorme.
        return (string) file_get_contents($ruta, false, null, 0, (int) config('chat_claude.acciones.max_bytes_archivo') + 1);
    }

    public static function borrarArchivo(string $id): void
    {
        @unlink(EjecutorAgente::carpeta().'/'.static::relativa($id));
        AlmacenAgente::borrar(static::relativa($id));
    }

    /**
     * Valida y aplica. Devuelve un resultado por accion, en orden: ['tipo', 'estado' => aplicada|rechazada|pendiente, 'detalle' => texto
     * para la persona]. Las pendientes (destructivas) llevan ademas 'accion': la accion tal como la propuso el agente, para guardarla.
     *
     * @return array<int, array{tipo: string, estado: string, detalle: string, accion?: array<string, mixed>}>
     */
    public function procesar(string $json): array
    {
        if (strlen($json) > (int) config('chat_claude.acciones.max_bytes_archivo')) {
            return $this->rechazoGlobal('El archivo de acciones es demasiado grande.');
        }
        $datos = json_decode($json, true);
        if (! is_array($datos) || ! isset($datos['acciones']) || ! is_array($datos['acciones']) || ! array_is_list($datos['acciones'])) {
            return $this->rechazoGlobal('El archivo de acciones no tiene el formato esperado ({"acciones": [...]}).');
        }
        $acciones = $datos['acciones'];
        $max = (int) config('chat_claude.acciones.max_por_turno');

        $resultados = [];
        $inmediatas = [];   // indice -> accion normalizada
        foreach ($acciones as $i => $accion) {
            if ($i >= $max) {
                $resultados[$i] = $this->resultado(is_array($accion) ? (string) ($accion['tipo'] ?? '?') : '?', 'rechazada', "Hay más de {$max} acciones en un turno; esta no se aplica.");

                continue;
            }
            $r = $this->validar($accion);
            if (is_string($r)) {
                $resultados[$i] = $this->resultado(is_array($accion) && is_string($accion['tipo'] ?? null) ? $accion['tipo'] : '?', 'rechazada', $r);
            } elseif ($this->grupoDe($r['tipo'])->destructiva($r)) {
                $resultados[$i] = $this->resultado($r['tipo'], 'pendiente', (string) $r['quiero']) + ['accion' => $accion];
            } else {
                $inmediatas[$i] = $r;
            }
        }

        foreach ($this->ejecutar($inmediatas) as $i => $r) {
            $resultados[$i] = $r;
        }

        ksort($resultados);
        foreach ($resultados as $i => $r) {
            $evento = ['aplicada' => 'chat.accion_aplicada', 'pendiente' => 'chat.accion_pendiente', 'rechazada' => 'chat.accion_rechazada'][$r['estado']];
            $texto = ['aplicada' => 'aplicada', 'pendiente' => 'a la espera de confirmación', 'rechazada' => 'rechazada'][$r['estado']];
            Auditoria::registrar($evento, "Acción del chat {$texto}: {$r['tipo']}",
                ['tipo' => $r['tipo'], 'motivo' => $r['estado'] === 'rechazada' ? $r['detalle'] : null, 'accion' => $this->paraAuditoria($acciones[$i] ?? null)]);
        }

        return array_values($resultados);
    }

    /**
     * Ejecuta acciones destructivas que la persona ha confirmado. Se vuelven a validar con los datos de ahora (pudo cambiar algo desde
     * la propuesta) y se aplican como las demas.
     *
     * @param  list<array<string, mixed>>  $acciones  tal como las guardo ConfirmacionAcciones
     * @return array<int, array{tipo: string, estado: string, detalle: string}>
     */
    public function ejecutarConfirmadas(array $acciones): array
    {
        $resultados = [];
        $validas = [];
        foreach ($acciones as $i => $accion) {
            $r = $this->validar($accion);
            if (is_string($r)) {
                $resultados[$i] = $this->resultado(is_array($accion) && is_string($accion['tipo'] ?? null) ? $accion['tipo'] : '?', 'rechazada', $r);
            } else {
                $validas[$i] = $r;
            }
        }
        foreach ($this->ejecutar($validas) as $i => $r) {
            $resultados[$i] = $r;
        }
        ksort($resultados);
        foreach ($resultados as $i => $r) {
            Auditoria::registrar($r['estado'] === 'aplicada' ? 'chat.accion_confirmada' : 'chat.accion_rechazada',
                $r['estado'] === 'aplicada' ? "Acción del chat confirmada y aplicada: {$r['tipo']}" : "Acción del chat confirmada pero rechazada: {$r['tipo']}",
                ['tipo' => $r['tipo'], 'motivo' => $r['estado'] === 'rechazada' ? $r['detalle'] : null, 'accion' => $this->paraAuditoria($acciones[$i] ?? null)]);
        }

        return array_values($resultados);
    }

    /**
     * Aplica las acciones ya normalizadas, cada una en su punto de guardado. Una InvalidArgumentException (el menu ya no esta en ese
     * estado...) rechaza solo esa; cualquier otro error deshace todo y rechaza todas.
     *
     * @param  array<int, array<string, mixed>>  $validas
     * @return array<int, array{tipo: string, estado: string, detalle: string}>
     */
    private function ejecutar(array $validas): array
    {
        $resultados = [];
        try {
            DB::transaction(function () use ($validas, &$resultados): void {
                foreach ($validas as $i => $normalizada) {
                    try {
                        $detalle = DB::transaction(fn (): string => $this->grupoDe($normalizada['tipo'])->aplicar($normalizada));
                        $resultados[$i] = $this->resultado($normalizada['tipo'], 'aplicada', $detalle);
                    } catch (InvalidArgumentException $e) {
                        $resultados[$i] = $this->resultado($normalizada['tipo'], 'rechazada', $e->getMessage());
                    }
                }
            });
        } catch (Throwable $e) {
            report($e);   // la transaccion se deshizo entera: ninguna se aplico
            foreach ($validas as $i => $normalizada) {
                $resultados[$i] = $this->resultado($normalizada['tipo'], 'rechazada', 'No se pudo guardar y no se aplicó ningún cambio de este turno.');
            }
        }

        return $resultados;
    }

    /**
     * Widget html con lo aplicado, lo pendiente de confirmar y lo rechazado, para el final del mensaje. Cadena vacia si no hubo acciones.
     *
     * @param  array<int, array{tipo: string, estado: string, detalle: string}>  $resultados
     */
    public static function resumen(array $resultados): string
    {
        if ($resultados === []) {
            return '';
        }
        $item = fn (array $r): string => '<li>'.self::e($r['detalle']).'</li>';
        $html = '';
        foreach (['aplicada' => 'Cambios guardados', 'pendiente' => 'Pendiente de tu confirmación (todavía no se ha hecho)', 'rechazada' => 'No se han aplicado'] as $estado => $titulo) {
            $lista = array_filter($resultados, fn (array $r): bool => $r['estado'] === $estado);
            if ($lista !== []) {
                $html .= "<p><strong>{$titulo}</strong></p><ul>".implode('', array_map($item, $lista)).'</ul>';
            }
        }

        return "\n\n```html\n<div>{$html}</div>\n```\n";
    }

    private static function e(string $texto): string
    {
        return str_replace('`', '&#96;', htmlspecialchars($texto, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }

    // ------------------------------------------------------------------ validacion

    /**
     * @return array<string, mixed>|string la accion normalizada, o el motivo del rechazo
     */
    private function validar(mixed $accion): array|string
    {
        if (! is_array($accion) || array_is_list($accion)) {
            return 'La acción no es un objeto.';
        }
        $tipo = $accion['tipo'] ?? null;
        if (! is_string($tipo) || ! in_array($tipo, $this->tipos(), true)) {
            return 'Tipo de acción desconocido: solo se admiten '.implode(', ', $this->tipos()).'.';
        }
        $grupo = $this->grupoDe($tipo);
        $extra = array_diff(array_keys($accion), $grupo->claves()[$tipo]);
        if ($extra !== []) {
            return 'Campos no admitidos en '.$tipo.': '.implode(', ', array_map(fn ($c): string => mb_substr((string) $c, 0, 40), $extra)).'.';
        }

        return $grupo->validar($tipo, $accion);
    }

    private function grupoDe(string $tipo): GrupoAcciones
    {
        foreach ($this->grupos as $grupo) {
            if (array_key_exists($tipo, $grupo->claves())) {
                return $grupo;
            }
        }
        throw new \LogicException("Tipo de acción sin grupo: {$tipo}.");
    }

    // ------------------------------------------------------------------ varios

    /** @return array<int, array{tipo: string, estado: string, detalle: string}> */
    private function rechazoGlobal(string $motivo): array
    {
        $r = $this->resultado('archivo', 'rechazada', $motivo);
        Auditoria::registrar('chat.accion_rechazada', 'Acciones del chat rechazadas: archivo no válido', ['tipo' => 'archivo', 'motivo' => $motivo]);

        return [$r];
    }

    /** @return array{tipo: string, estado: string, detalle: string} */
    private function resultado(string $tipo, string $estado, string $detalle): array
    {
        return ['tipo' => $tipo, 'estado' => $estado, 'detalle' => $detalle];
    }

    /** La accion tal como la propuso el agente (texto recortado y sin caracteres de control: un NUL no cabe en jsonb), para la auditoria. */
    private function paraAuditoria(mixed $accion): string
    {
        $texto = (string) json_encode($accion, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $texto = (string) preg_replace('/[\x00-\x1F\x7F]|\\\\u00[01][0-9a-f]/i', ' ', $texto);

        return mb_strlen($texto) > 1000 ? mb_substr($texto, 0, 1000).'…' : $texto;
    }
}
