<?php

namespace App\Services\Chat\Claude;

use App\Enums\CategoriaComida;
use App\Enums\EstadoChat;
use App\Enums\EstadoMenu;
use App\Models\AgenteArchivo;
use App\Models\Alimento;
use App\Models\ChatMensaje;
use App\Models\ConsumoMenu;
use App\Models\DisponibilidadDia;
use App\Models\Existencia;
use App\Models\Feedback;
use App\Models\Hogar;
use App\Models\Menu;
use App\Models\Perfil;
use App\Models\RegistroComida;
use App\Services\Agente\AlmacenAgente;
use App\Services\Chat\Archivos;
use App\Services\Menu\EjecutorAgente;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Instantanea de SOLO LECTURA de los datos del dominio para un turno de chat: JSON/CSV legibles en agente/encargos/chat-{id}/
 * (con AlmacenAgente activo tambien en la base, de donde los envia EjecutorClaude al runner igual que los encargos de menus).
 *
 * El agente nunca ve la base: solo estos archivos. Cada tabla se exporta con una lista BLANCA de columnas (lo nuevo no se
 * filtra por descuido) y quedan fuera, a proposito: users (y sus contrasenas), sesiones, cola, cache, auditorias, tokens, notificaciones,
 * archivos del agente, el chat salvo su historial reciente y las fotos que este referencia, ajustes, el encargo y los registros
 * internos de los menus. LEEME.json describe lo exportado, lo recortado y lo excluido.
 *
 * Tamano acotado (config chat_claude.instantanea): cada archivo y el total tienen tope; si no cabe, se quitan filas del final
 * de la lista (siempre ordenadas de lo mas reciente o relevante a lo menos) y LEEME.json lo marca con `truncada`.
 */
class InstantaneaDatos
{
    /** Lo que NO se exporta (se publica en LEEME.json para que el agente no lo busque). */
    public const EXCLUIDO = ['users y contraseñas', 'sesiones', 'cola de trabajos', 'caché', 'auditorías', 'tokens', 'notificaciones',
        'archivos del agente', 'ajustes de la instancia', 'peticiones de secretos del chat', 'chat_archivos (salvo las fotos referenciadas en el historial)',
        'encargos y registros internos de los menús'];

    private string $carpeta = '';

    private int $restante = 0;

    /** @var array<int, array<string, mixed>> */
    private array $indice = [];

    /** @var array<string, string> ruta relativa -> contenido (para subirlo a la base del agente) */
    private array $archivos = [];

    public static function relativa(string $id): string
    {
        return "encargos/chat-{$id}";
    }

    public static function ruta(string $id): string
    {
        return EjecutorAgente::carpeta().'/'.static::relativa($id);
    }

    /** Borra la instantanea de un turno (disco y base del agente) y las de turnos abandonados hace mas de 6 horas. */
    public static function limpiar(string $id): void
    {
        static::borrarDirectorio(static::ruta($id));
        if (AlmacenAgente::activo()) {
            AgenteArchivo::query()->where('ruta', 'like', static::relativa($id).'/%')->delete();
        }
        foreach (glob(EjecutorAgente::carpeta().'/encargos/chat-*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (filemtime($dir) < time() - 6 * 3600) {
                static::borrarDirectorio($dir);
                AlmacenAgente::activo() && AgenteArchivo::query()->where('ruta', 'like', 'encargos/'.basename($dir).'/%')->delete();
            }
        }
    }

    private static function borrarDirectorio(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() && ! $f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }

    /**
     * Escribe la instantanea del turno y la deja en la base del agente (si esta activa).
     *
     * @return array<string, mixed> el contenido de LEEME.json
     */
    public function exportar(ChatMensaje $pregunta, ChatMensaje $respuesta): array
    {
        static::limpiar($respuesta->id);
        $this->carpeta = static::ruta($respuesta->id);
        $this->restante = (int) $this->limite('max_bytes_datos');
        $this->indice = [];
        $this->archivos = [];
        if (! is_dir($this->carpeta) && ! @mkdir($this->carpeta, 0775, true) && ! is_dir($this->carpeta)) {
            throw new \RuntimeException('No se pudo crear la carpeta de la instantánea.');
        }

        $this->hogares();
        $this->perfiles();
        $this->disponibilidad();
        $this->existencias();
        $this->menus();
        $this->registrosComida();
        $this->feedback();
        $this->alimentos();
        $this->consumos();
        $this->catalogos();
        $this->conversacion($pregunta, $respuesta);
        $leeme = $this->leeme($respuesta);
        $this->escribir('LEEME.json', (string) json_encode($leeme, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), false);

        if (AlmacenAgente::activo()) {
            foreach ($this->archivos as $relativa => $contenido) {
                AlmacenAgente::guardar(static::relativa($respuesta->id).'/'.$relativa, $contenido);
            }
        }
        $this->archivos = [];

        return $leeme;
    }

    private function limite(string $clave): int|float
    {
        return config("chat_claude.instantanea.{$clave}");
    }

    // ------------------------------------------------------------------ escritura acotada

    /** @param  bool  $contar  si cuenta contra el presupuesto de datos (LEEME y las fotos no) */
    private function escribir(string $relativa, string $contenido, bool $contar = true): void
    {
        $destino = $this->carpeta.'/'.$relativa;
        if (! is_dir(dirname($destino))) {
            @mkdir(dirname($destino), 0775, true);
        }
        file_put_contents($destino, $contenido);
        $this->archivos[$relativa] = $contenido;
        $contar && $this->restante -= strlen($contenido);
    }

    /** Ni siquiera el archivo vacio cabe en lo que queda del presupuesto de datos. */
    private function noCabe(string $contenido): bool
    {
        return strlen($contenido) > max(0, $this->restante);
    }

    /**
     * El presupuesto se agoto: el archivo no se escribe (no se pasa del tope) y el indice lo dice.
     *
     * @param  array<string, string>  $columnas
     * @param  array<int, string>  $relaciones
     */
    private function omitir(string $archivo, string $descripcion, string $formato, int $filasTotales, array $columnas, array $relaciones): void
    {
        $this->indice[] = ['archivo' => $archivo, 'descripcion' => $descripcion, 'formato' => $formato, 'filas' => 0, 'filas_totales' => $filasTotales,
            'truncada' => true, 'omitido' => 'sin presupuesto de datos', 'columnas' => $columnas, 'relaciones' => $relaciones];
    }

    /**
     * Quita filas del final hasta que el JSON quepa en el tope del archivo y en lo que queda de presupuesto.
     *
     * @param  array<int, mixed>  $filas
     * @return array{0: string, 1: array<int, mixed>, 2: bool} contenido, filas escritas, si se recorto
     */
    private function acotarLista(array $filas, callable $serializar): array
    {
        $tope = (int) min($this->limite('max_bytes_archivo'), max(0, $this->restante));
        $total = count($filas);
        $contenido = $serializar($filas);
        while (strlen($contenido) > $tope && $filas !== []) {
            $filas = array_slice($filas, 0, max(0, (int) floor(count($filas) * 0.85) - 1));
            $contenido = $serializar($filas);
        }

        return [$contenido, $filas, count($filas) < $total];
    }

    /**
     * @param  array<int, array<string, mixed>>  $filas
     * @param  array<string, string>  $columnas  nombre => descripcion
     * @param  array<int, string>  $relaciones
     */
    private function lista(string $archivo, string $descripcion, array $filas, array $columnas, array $relaciones = []): void
    {
        [$contenido, $escritas, $truncada] = $this->acotarLista($filas, fn (array $f): string => $this->jsonLineas($f));
        if ($this->noCabe($contenido)) {
            $this->omitir($archivo, $descripcion, 'json', count($filas), $columnas, $relaciones);

            return;
        }
        $this->escribir($archivo, $contenido);
        $this->indice[] = ['archivo' => $archivo, 'descripcion' => $descripcion, 'formato' => 'json', 'filas' => count($escritas), 'filas_totales' => count($filas),
            'truncada' => $truncada, 'columnas' => $columnas, 'relaciones' => $relaciones];
    }

    /** @param  array<int, array<string, mixed>>  $filas */
    private function jsonLineas(array $filas): string
    {
        $lineas = array_map(fn (array $f): string => (string) json_encode($f, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR), $filas);

        return $lineas === [] ? "[]\n" : "[\n".implode(",\n", $lineas)."\n]\n";
    }

    /**
     * @param  array<int, array<int, mixed>>  $filas
     * @param  array<int, string>  $cabecera
     * @param  array<string, string>  $columnas
     * @param  array<int, string>  $relaciones
     */
    private function csv(string $archivo, string $descripcion, array $cabecera, array $filas, array $columnas, array $relaciones = []): void
    {
        [$contenido, $escritas, $truncada] = $this->acotarLista($filas, fn (array $f): string => $this->csvTexto($cabecera, $f));
        if ($this->noCabe($contenido)) {
            $this->omitir($archivo, $descripcion, 'csv', count($filas), $columnas, $relaciones);

            return;
        }
        $this->escribir($archivo, $contenido);
        $this->indice[] = ['archivo' => $archivo, 'descripcion' => $descripcion, 'formato' => 'csv', 'filas' => count($escritas), 'filas_totales' => count($filas),
            'truncada' => $truncada, 'columnas' => $columnas, 'relaciones' => $relaciones];
    }

    /**
     * @param  array<int, string>  $cabecera
     * @param  array<int, array<int, mixed>>  $filas
     */
    private function csvTexto(array $cabecera, array $filas): string
    {
        $f = fopen('php://memory', 'w+');
        fputcsv($f, $cabecera, ',', '"', '');
        foreach ($filas as $fila) {
            fputcsv($f, array_map(fn (mixed $v): string => is_bool($v) ? ($v ? '1' : '0') : (string) ($v ?? ''), $fila), ',', '"', '');
        }
        rewind($f);
        $texto = (string) stream_get_contents($f);
        fclose($f);

        return $texto;
    }

    // ------------------------------------------------------------------ tablas

    private function hogares(): void
    {
        $filas = Hogar::query()->orderBy('id')->get()->map(fn (Hogar $h): array => [
            'id' => $h->id, 'nombre' => $h->nombre, 'menu_activo_id' => $h->menu_id, 'cambios_por_intercambio' => $h->cambios,
        ])->all();
        $this->lista('hogares.json', 'Hogares de la instancia.', $filas, [
            'id' => 'identificador', 'nombre' => 'nombre del hogar', 'menu_activo_id' => 'menú que el hogar sigue ahora (menus.json), o null',
            'cambios_por_intercambio' => 'tomas del menú activo cambiadas por intercambio: {"AAAA-MM-DD/comida|cena": toma}',
        ], ['menu_activo_id -> menus.json id']);
    }

    private function perfiles(): void
    {
        $filas = Perfil::query()->orderBy('id')->get()->map(fn (Perfil $p): array => [
            'id' => $p->id, 'hogar_id' => $p->hogar_id, 'nombre' => $p->nombre, 'activo' => (bool) $p->activo, 'objetivo' => $p->objetivo,
            'kcal_diarias' => $p->kcal_diarias, 'proteina_pct' => $p->proteina_pct, 'carbohidratos_pct' => $p->carbohidratos_pct, 'grasa_pct' => $p->grasa_pct,
            'restricciones' => $p->restricciones ?? [], 'notas' => $p->notas,
        ])->all();
        $this->lista('perfiles.json', 'Personas del hogar para las que se planifica. Son los «perfiles» a los que se refiere la conversación.', $filas, [
            'id' => 'identificador (el que se usa en las acciones)', 'hogar_id' => 'hogar', 'nombre' => 'nombre', 'activo' => 'si entra en los menús',
            'objetivo' => 'objetivo nutricional (texto libre)', 'kcal_diarias' => 'calorías diarias objetivo', 'proteina_pct' => '% de proteína',
            'carbohidratos_pct' => '% de hidratos', 'grasa_pct' => '% de grasa', 'restricciones' => 'lista de restricciones que se respetan siempre', 'notas' => 'texto libre',
        ], ['hogar_id -> hogares.json id']);
    }

    private function disponibilidad(): void
    {
        $filas = DisponibilidadDia::query()->orderBy('hogar_id')->orderBy('dia_semana')->get()->map(fn (DisponibilidadDia $d): array => [
            'hogar_id' => $d->hogar_id, 'dia_semana' => $d->dia_semana->value, 'minutos_comida' => $d->minutos_comida, 'minutos_cena' => $d->minutos_cena,
            'minutos_desayuno' => $d->minutos_desayuno, 'minutos_almuerzo' => $d->minutos_almuerzo, 'minutos_merienda' => $d->minutos_merienda,
        ])->all();
        $this->lista('disponibilidad.json', 'Minutos que el hogar tiene para cocinar en cada toma según el día de la semana (null = no se cocina).', $filas, [
            'hogar_id' => 'hogar', 'dia_semana' => '1 = lunes … 7 = domingo', 'minutos_comida' => 'minutos para cocinar la comida', 'minutos_cena' => 'minutos para la cena',
            'minutos_desayuno' => 'idem desayuno', 'minutos_almuerzo' => 'idem almuerzo', 'minutos_merienda' => 'idem merienda',
        ], ['hogar_id -> hogares.json id']);
    }

    private function existencias(): void
    {
        $filas = Existencia::query()->with('alimento:id,nombre,categoria')->orderBy('caduca_el')->orderBy('id')->limit((int) $this->limite('max_existencias'))->get()->map(fn (Existencia $e): array => [
            'id' => $e->id, 'hogar_id' => $e->hogar_id, 'alimento_id' => $e->alimento_id, 'alimento' => $e->alimento?->nombre, 'categoria' => $e->alimento?->categoria?->value,
            'cantidad' => (float) $e->cantidad, 'unidad' => $e->unidad, 'caduca_el' => $e->caduca_el?->toDateString(),
        ])->all();
        $this->lista('existencias.json', 'Lo que hay en casa (despensa y nevera), ordenado por caducidad.', $filas, [
            'id' => 'identificador', 'hogar_id' => 'hogar', 'alimento_id' => 'alimentos.json id', 'alimento' => 'nombre del alimento', 'categoria' => 'categoría del alimento',
            'cantidad' => 'cantidad', 'unidad' => 'g, kg, ml, l o ud', 'caduca_el' => 'fecha de caducidad o null',
        ], ['hogar_id -> hogares.json id', 'alimento_id -> alimentos.json id']);
    }

    private function alimentos(): void
    {
        $filas = Alimento::query()->orderBy('nombre')->limit((int) $this->limite('max_alimentos'))->get()->map(fn (Alimento $a): array => [
            'id' => $a->id, 'nombre' => $a->nombre, 'categoria' => $a->categoria?->value, 'unidad' => $a->unidad,
        ])->all();
        $this->lista('alimentos.json', 'Catálogo de alimentos conocidos.', $filas, [
            'id' => 'identificador', 'nombre' => 'nombre', 'categoria' => 'categoría (carne_roja, pescado, verdura, fruta…)', 'unidad' => 'unidad habitual',
        ]);
    }

    private function menus(): void
    {
        $activos = Hogar::query()->whereNotNull('menu_id')->pluck('menu_id')->all();
        $recientes = Menu::query()->orderByDesc('fecha_inicio')->orderByDesc('id')->limit((int) $this->limite('menus_recientes'))->pluck('id')->all();
        $menus = Menu::query()->with('hogar')->whereIn('id', array_values(array_unique([...$activos, ...$recientes])))->orderByDesc('fecha_inicio')->orderByDesc('id')->get();

        $filas = $menus->map(fn (Menu $m): array => [
            'id' => $m->id, 'nombre' => $m->nombre, 'hogar_id' => $m->hogar_id, 'fecha_inicio' => $m->fecha_inicio?->toDateString(), 'estado' => $m->estado->value,
            'tarea' => $m->tarea, 'activo' => in_array($m->id, $activos, true), 'terminado_en' => $m->terminado_en?->toIso8601String(),
            'error' => $m->error ? mb_substr((string) $m->error, 0, 300) : null, 'en_curso' => $m->estado->enCurso(),
            'archivo' => $m->contenido ? "menus/menu-{$m->id}.json" : null,
        ])->all();
        $this->lista('menus.json', 'El menú activo de cada hogar y los más recientes. El contenido de cada uno está en menus/menu-ID.json y aplanado en tomas.csv.', $filas, [
            'id' => 'identificador', 'nombre' => 'nombre', 'hogar_id' => 'hogar', 'fecha_inicio' => 'primer día', 'estado' => 'borrador, en_cola, en_marcha, en_espera, terminado o fallido',
            'tarea' => 'generar, editar o intercambiar', 'activo' => 'si es el que el hogar sigue ahora', 'terminado_en' => 'cuando terminó', 'archivo' => 'archivo con su contenido, o null si aún no lo tiene',
            'error' => 'motivo del último fallo (recortado), o null', 'en_curso' => 'si tiene un trabajo en curso (en_cola, en_marcha o en_espera): no se puede editar ni activar',
        ], ['hogar_id -> hogares.json id']);

        $tomas = [];
        foreach ($menus as $menu) {
            if (! $menu->contenido) {
                continue;
            }
            $activo = in_array($menu->id, $activos, true);
            $contenido = $menu->contenidoVigente();   // el activo con los cambios por intercambio; los demás tal como se generaron
            $this->escribirMenu($menu, $contenido, $activo);
            foreach ($contenido['dias'] ?? [] as $dia) {
                foreach (['comida', 'cena'] as $momento) {
                    foreach ($dia[$momento]['opciones'] ?? [] as $i => $o) {
                        $elegida = ($menu->selecciones["{$dia['fecha']}/{$momento}"] ?? 0) === $i;
                        $tomas[] = [$menu->id, $dia['fecha'], $momento, '', $i, $o['titulo'] ?? '', implode(';', $o['categorias'] ?? []),
                            ($o['duracion_previa_min'] ?? 0) + ($o['duracion_momento_min'] ?? 0), $elegida];
                    }
                }
                foreach ($dia['individuales'] ?? [] as $ind) {
                    foreach (['desayuno', 'almuerzo', 'merienda'] as $momento) {
                        if (isset($ind[$momento]['titulo'])) {
                            $tomas[] = [$menu->id, $dia['fecha'], $momento, $ind['perfil_id'] ?? '', 0, $ind[$momento]['titulo'], '', '', true];
                        }
                    }
                }
            }
        }
        $this->csv('tomas.csv', 'Todas las tomas de esos menús, una por fila (las de comida y cena con una fila por opción).',
            ['menu_id', 'fecha', 'momento', 'perfil_id', 'opcion', 'titulo', 'categorias', 'minutos', 'elegida'], $tomas, [
                'menu_id' => 'menus.json id', 'fecha' => 'AAAA-MM-DD', 'momento' => 'desayuno, almuerzo, comida, merienda o cena', 'perfil_id' => 'solo en tomas individuales (desayuno, almuerzo, merienda); vacío si es de todo el hogar',
                'opcion' => 'índice de la opción (0, 1…)', 'titulo' => 'nombre del plato', 'categorias' => 'separadas por «;»', 'minutos' => 'cocina previa + al momento',
                'elegida' => '1 si es la opción que se sigue (por defecto la primera)',
            ], ['menu_id -> menus.json id', 'perfil_id -> perfiles.json id']);
    }

    /** @param  array<string, mixed>  $contenido */
    private function escribirMenu(Menu $menu, array $contenido, bool $activo): void
    {
        $texto = (string) json_encode(['id' => $menu->id, 'nombre' => $menu->nombre, 'hogar_id' => $menu->hogar_id, 'fecha_inicio' => $menu->fecha_inicio?->toDateString(),
            'activo' => $activo, 'selecciones' => $menu->selecciones ?? (object) [], 'contenido' => $contenido], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($texto) <= min($this->limite('max_bytes_archivo'), max(0, $this->restante))) {
            $this->escribir("menus/menu-{$menu->id}.json", $texto);
        }
    }

    private function registrosComida(): void
    {
        $foto = Schema::hasColumn('registros_comida', 'foto_hash');
        $filas = RegistroComida::query()->with('perfil:id,nombre')->orderByDesc('fecha')->orderByDesc('id')->limit((int) $this->limite('max_registros_comida'))->get()
            ->map(fn (RegistroComida $r): array => [$r->id, $r->perfil_id, $r->perfil?->nombre, $r->fecha?->toDateString(), $r->momento->value, $r->origen->value,
                $r->descripcion, implode(';', $r->categorias ?? []), $r->menu_id, $r->opcion_ref, ...($foto ? [(string) ($r->foto_hash ?? '')] : [])])->all();
        $this->csv('registros_comida.csv', 'Lo que cada perfil comió de verdad (lo más reciente primero). Es el historial; un menú planificado no cuenta como comido.',
            ['id', 'perfil_id', 'perfil', 'fecha', 'momento', 'origen', 'descripcion', 'categorias', 'menu_id', 'opcion_ref', ...($foto ? ['foto_hash'] : [])], $filas, [
                'id' => 'identificador (el que usa corregir_comida)', 'perfil_id' => 'perfiles.json id', 'perfil' => 'nombre del perfil', 'fecha' => 'AAAA-MM-DD', 'momento' => 'comida, cena u otro',
                'origen' => 'menu (una opción del menú), casa (sin seguir el menú) o fuera', 'descripcion' => 'qué se comió', 'categorias' => 'separadas por «;»',
                'menu_id' => 'menú del que salió (origen menu)', 'opcion_ref' => 'opción del menú elegida',
                ...($foto ? ['foto_hash' => 'SHA3-256 de la foto de la comida, si la tiene (está en fotos/ si cupo)'] : []),
            ], ['perfil_id -> perfiles.json id', 'menu_id -> menus.json id']);
    }

    private function feedback(): void
    {
        $filas = Feedback::query()->orderByDesc('id')->limit((int) $this->limite('max_feedback'))->get()->map(fn (Feedback $f): array => [
            'id' => $f->id, 'perfil_id' => $f->perfil_id, 'tipo' => $f->tipo->value, 'elemento' => $f->elemento, 'nota' => $f->nota, 'fecha' => $f->created_at?->toDateString(),
        ])->all();
        $this->lista('feedback.json', 'Gustos y rechazos que cada perfil ha dicho (lo más reciente primero).', $filas, [
            'id' => 'identificador', 'perfil_id' => 'perfiles.json id', 'tipo' => 'gusta o no_gusta', 'elemento' => 'plato o alimento', 'nota' => 'texto libre', 'fecha' => 'cuando se anotó',
        ], ['perfil_id -> perfiles.json id']);
    }

    private function consumos(): void
    {
        $desde = now()->subDays((int) $this->limite('dias_consumos'));
        $filas = ConsumoMenu::query()->where('created_at', '>=', $desde)->selectRaw('menu_id, count(*) as respuestas, sum(input_tokens) as entrada, sum(output_tokens) as salida, sum(coste_total) as coste')
            ->groupBy('menu_id')->orderByDesc('menu_id')->limit(50)->get()->map(fn (ConsumoMenu $c): array => [
                'menu_id' => (int) $c->menu_id, 'respuestas' => (int) $c->respuestas, 'tokens_entrada' => (int) $c->entrada, 'tokens_salida' => (int) $c->salida, 'coste_usd' => round((float) $c->coste, 4),
            ])->all();
        $this->lista('consumos.json', 'Consumo agregado de Claude por menú (últimos días): cuánto costó generar cada uno. Solo totales.', $filas, [
            'menu_id' => 'menus.json id', 'respuestas' => 'respuestas del modelo', 'tokens_entrada' => 'tokens de entrada', 'tokens_salida' => 'tokens de salida', 'coste_usd' => 'coste en dólares',
        ], ['menu_id -> menus.json id']);
    }

    /** Valores validos de los campos cerrados y lista de acciones admitidas (lo que el agente necesita para proponer sin equivocarse). */
    private function catalogos(): void
    {
        $datos = [
            'categorias' => array_map(fn (CategoriaComida $c): array => ['valor' => $c->value, 'etiqueta' => $c->getLabel()], CategoriaComida::cases()),
            'momentos_de_registro' => ['comida', 'cena', 'otro'],
            'momentos_de_toma_de_menu' => ['comida', 'cena'],
            'origenes_de_registro' => ['casa', 'fuera'],
            'valoraciones' => ['gusta', 'no_gusta'],
            'unidades' => array_keys(Alimento::UNIDADES),
            'dia_semana' => '1 = lunes … 7 = domingo',
            'estados_de_menu' => array_map(fn (EstadoMenu $e): string => $e->value, EstadoMenu::cases()),
            'acciones_admitidas' => app(AccionesChat::class)->tipos(),
            'tope_acciones_por_turno' => (int) config('chat_claude.acciones.max_por_turno'),
            'minutos_para_confirmar' => (int) config('chat_claude.acciones.caduca_min'),
        ];
        $this->escribir('catalogos.json', (string) json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), false);
        $this->indice[] = ['archivo' => 'catalogos.json', 'descripcion' => 'Valores válidos de los campos cerrados (categorías, unidades, estados…) y lista de acciones admitidas.',
            'formato' => 'json', 'filas' => 1, 'filas_totales' => 1, 'truncada' => false, 'columnas' => [], 'relaciones' => []];
    }

    // ------------------------------------------------------------------ conversacion y fotos

    private function conversacion(ChatMensaje $pregunta, ChatMensaje $respuesta): void
    {
        $mensajes = ChatMensaje::query()->where('sequence', '<', $respuesta->sequence)
            ->where(fn (Builder $q) => $q->where('from_service', false)->orWhere('estado', EstadoChat::Completado->value))
            ->orderByDesc('sequence')->limit((int) $this->limite('historial_mensajes'))->get();   // lo mas reciente primero: la pregunta, la primera
        $fotosRestantes = (int) $this->limite('max_fotos');
        $bytesRestantes = (int) $this->limite('max_bytes_fotos');
        $copiadas = [];
        $filas = [];
        foreach ($mensajes as $m) {
            $fotos = [];
            foreach ($m->imagenes() as $parte) {
                $hash = (string) ($parte['hash'] ?? '');
                if ($m->from_service || Archivos::normalizarHash($hash) === null) {
                    continue;   // solo las fotos de la persona
                }
                $archivo = $copiadas[$hash] ?? null;
                $motivo = null;
                if ($archivo === null) {
                    $f = app(Archivos::class)->buscar($hash);
                    if (! $f) {
                        $motivo = 'no disponible';
                    } elseif ($fotosRestantes <= 0 || $f->tamano > $bytesRestantes) {
                        $motivo = 'omitida por tamaño o por haber demasiadas fotos';
                    } else {
                        $archivo = 'fotos/'.substr($hash, 0, 16).($f->mime === 'image/png' ? '.png' : '.jpg');
                        $this->escribir($archivo, $f->bytes(), false);
                        $copiadas[$hash] = $archivo;
                        $fotosRestantes--;
                        $bytesRestantes -= (int) $f->tamano;
                    }
                }
                $fotos[] = ['foto_hash' => $hash, 'archivo' => $archivo, 'mime' => $parte['mime'] ?? null, 'nota' => $motivo];
            }
            $texto = $this->textoDelMensaje($m);
            $filas[] = [
                'sequence' => $m->sequence, 'de' => $m->from_service ? 'asistente' : 'persona', 'hora' => gmdate('c', intdiv((int) $m->created_ms, 1000)),
                'texto' => mb_strlen($texto) > $this->limite('max_texto_mensaje') ? mb_substr($texto, 0, (int) $this->limite('max_texto_mensaje')).'… [recortado]' : $texto,
                'fotos' => $fotos, 'es_la_pregunta' => $m->id === $pregunta->id,
            ];
        }
        $filas = array_reverse($filas);   // orden cronologico
        $this->escribir('conversacion.json', (string) json_encode(['pregunta_sequence' => $pregunta->sequence, 'mensajes' => $filas],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), false);
        $this->indice[] = ['archivo' => 'conversacion.json', 'descripcion' => 'Los últimos mensajes de la conversación (la pregunta de ahora es el que tiene es_la_pregunta). Las fotos están como archivos de imagen en fotos/: léelas con Read.',
            'formato' => 'json', 'filas' => count($filas), 'filas_totales' => count($filas), 'truncada' => false, 'columnas' => [
                'sequence' => 'orden del mensaje', 'de' => 'persona o asistente', 'hora' => 'ISO 8601 UTC', 'texto' => 'texto del mensaje (los widgets y formularios de asistente salen como el texto que se envió)',
                'fotos' => 'fotos de la persona: foto_hash (el que se usa en registrar_comida), archivo (ruta en fotos/, o null) y nota si no se copió',
            ], 'relaciones' => []];
    }

    private function textoDelMensaje(ChatMensaje $m): string
    {
        // Las partes peticion_secreto no son de texto: no salen. Los tokens de confirmacion de acciones pendientes tampoco: son de Laravel.
        return ConfirmacionAcciones::ocultarTokens($m->texto());
    }

    // ------------------------------------------------------------------ indice

    /** @return array<string, mixed> */
    private function leeme(ChatMensaje $respuesta): array
    {
        $hoy = now();

        return [
            'formato' => 'instantanea-chat', 'version' => 1, 'turno' => $respuesta->id,
            'generada' => $hoy->toIso8601String(), 'hoy' => $hoy->toDateString(), 'dia_de_la_semana' => (int) $hoy->dayOfWeekIso,
            'aviso' => 'Instantánea de SOLO LECTURA tomada justo antes de este turno. Cualquier cambio que escribas aquí se pierde y no llega a la base. '
                .'Para cambiar datos propón acciones en workspace/chat-acciones-'.$respuesta->id.'.json (ver la skill chat-nutricion).',
            'limites' => [
                'tamano_total_datos_bytes' => (int) $this->limite('max_bytes_datos'), 'tamano_maximo_archivo_bytes' => (int) $this->limite('max_bytes_archivo'),
                'registros_comida' => (int) $this->limite('max_registros_comida'), 'historial_mensajes' => (int) $this->limite('historial_mensajes'),
                'fotos' => (int) $this->limite('max_fotos'), 'menus_ademas_del_activo' => (int) $this->limite('menus_recientes'),
                'nota' => 'Si un archivo no cabe se quitan sus filas más antiguas y su entrada dice truncada: true. Lo que no está aquí no se conoce: no lo inventes.',
            ],
            'archivos' => $this->indice,
            'fotos' => array_values(array_filter(array_keys($this->archivos), fn (string $r): bool => str_starts_with($r, 'fotos/'))),
            'excluido' => self::EXCLUIDO,
        ];
    }
}
