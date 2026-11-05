<?php

namespace App\Services\Chat\Claude;

use App\Enums\CategoriaComida;
use App\Enums\MomentoComida;
use App\Enums\OrigenComida;
use App\Enums\TipoFeedback;
use App\Models\Auditoria;
use App\Models\ChatMensaje;
use App\Models\Feedback;
use App\Models\Perfil;
use App\Models\RegistroComida;
use App\Services\Agente\AlmacenAgente;
use App\Services\Chat\Archivos;
use App\Services\Menu\EjecutorAgente;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Acciones que el agente PROPONE en workspace/chat-acciones-{id}.json (el agente nunca escribe en la base). Laravel las valida con
 * reglas estrictas y aplica las validas en una sola transaccion; las invalidas se rechazan con el motivo. Cada una queda en la
 * auditoria (chat.accion_aplicada / chat.accion_rechazada) y el resumen para la persona es un widget html.
 *
 * Archivo: {"acciones": [ {"tipo": "...", ...}, ... ]}. Lista cerrada de tipos:
 *   registrar_comida {perfil, fecha, momento, descripcion, origen?, categorias?, foto_hash?}
 *   corregir_comida  {registro_id, fecha?, momento?, descripcion?, origen?, categorias?, foto_hash?}
 *   anotar_feedback  {perfil, valoracion: gusta|no_gusta, elemento, nota?}
 * `perfil` es el id de un perfil activo (o su nombre exacto si es unico). Sin tipo desconocido ni claves desconocidas.
 */
class AccionesChat
{
    public const TIPOS = ['registrar_comida', 'corregir_comida', 'anotar_feedback'];

    private const CLAVES = [
        'registrar_comida' => ['tipo', 'perfil', 'fecha', 'momento', 'descripcion', 'origen', 'categorias', 'foto_hash'],
        'corregir_comida' => ['tipo', 'registro_id', 'fecha', 'momento', 'descripcion', 'origen', 'categorias', 'foto_hash'],
        'anotar_feedback' => ['tipo', 'perfil', 'valoracion', 'elemento', 'nota'],
    ];

    public static function relativa(int $id): string
    {
        return "workspace/chat-acciones-{$id}.json";
    }

    /** Lee el archivo que dejo el agente (de la base si el almacen esta activo, si no del disco). Null si no hay. */
    public static function leerArchivo(int $id): ?string
    {
        $ruta = EjecutorAgente::carpeta().'/'.static::relativa($id);
        AlmacenAgente::activo() && AlmacenAgente::bajar(EjecutorAgente::carpeta(), static::relativa($id));

        if (! is_file($ruta) || is_link($ruta)) {
            return null;
        }

        // Como mucho un byte mas que el tope: procesar() lo rechaza, pero no se carga en memoria un archivo enorme.
        return (string) file_get_contents($ruta, false, null, 0, (int) config('chat_claude.acciones.max_bytes_archivo') + 1);
    }

    public static function borrarArchivo(int $id): void
    {
        @unlink(EjecutorAgente::carpeta().'/'.static::relativa($id));
        AlmacenAgente::borrar(static::relativa($id));
    }

    /**
     * Valida y aplica. Devuelve un resultado por accion, en orden: ['tipo', 'estado' => aplicada|rechazada, 'detalle' => texto para la persona].
     *
     * @return array<int, array{tipo: string, estado: string, detalle: string}>
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
        $validas = [];   // indice -> [accion normalizada, descripcion]
        foreach ($acciones as $i => $accion) {
            if ($i >= $max) {
                $resultados[$i] = $this->resultado(is_array($accion) ? (string) ($accion['tipo'] ?? '?') : '?', 'rechazada', "Hay más de {$max} acciones en un turno; esta no se aplica.");

                continue;
            }
            $r = $this->validar($accion);
            if (is_string($r)) {
                $resultados[$i] = $this->resultado(is_array($accion) && is_string($accion['tipo'] ?? null) ? $accion['tipo'] : '?', 'rechazada', $r);
            } else {
                $validas[$i] = $r;
            }
        }

        try {
            $aplicadas = DB::transaction(function () use ($validas): array {
                $hechas = [];
                foreach ($validas as $i => $normalizada) {
                    $hechas[$i] = $this->aplicar($normalizada);
                }

                return $hechas;
            });
            foreach ($aplicadas as $i => $detalle) {
                $resultados[$i] = $this->resultado($validas[$i]['tipo'], 'aplicada', $detalle);
            }
        } catch (Throwable $e) {
            report($e);   // la transaccion se deshizo entera: ninguna de las validas se aplico
            foreach ($validas as $i => $normalizada) {
                $resultados[$i] = $this->resultado($normalizada['tipo'], 'rechazada', 'No se pudo guardar y no se aplicó ningún cambio de este turno.');
            }
        }

        ksort($resultados);
        foreach ($resultados as $i => $r) {
            Auditoria::registrar(
                $r['estado'] === 'aplicada' ? 'chat.accion_aplicada' : 'chat.accion_rechazada',
                $r['estado'] === 'aplicada' ? "Acción del chat aplicada: {$r['tipo']}" : "Acción del chat rechazada: {$r['tipo']}",
                ['tipo' => $r['tipo'], 'motivo' => $r['estado'] === 'rechazada' ? $r['detalle'] : null, 'accion' => $this->paraAuditoria($acciones[$i] ?? null)],
            );
        }

        return array_values($resultados);
    }

    /**
     * Widget html con lo aplicado y lo rechazado, para el final del mensaje. Cadena vacia si no hubo acciones.
     *
     * @param  array<int, array{tipo: string, estado: string, detalle: string}>  $resultados
     */
    public static function resumen(array $resultados): string
    {
        if ($resultados === []) {
            return '';
        }
        $item = fn (array $r): string => '<li>'.self::e($r['detalle']).'</li>';
        $aplicadas = array_filter($resultados, fn (array $r): bool => $r['estado'] === 'aplicada');
        $rechazadas = array_filter($resultados, fn (array $r): bool => $r['estado'] === 'rechazada');
        $html = '';
        if ($aplicadas !== []) {
            $html .= '<p><strong>Cambios guardados</strong></p><ul>'.implode('', array_map($item, $aplicadas)).'</ul>';
        }
        if ($rechazadas !== []) {
            $html .= '<p><strong>No se han aplicado</strong></p><ul>'.implode('', array_map($item, $rechazadas)).'</ul>';
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
        if (! is_string($tipo) || ! in_array($tipo, self::TIPOS, true)) {
            return 'Tipo de acción desconocido: solo se admiten '.implode(', ', self::TIPOS).'.';
        }
        $extra = array_diff(array_keys($accion), self::CLAVES[$tipo]);
        if ($extra !== []) {
            return 'Campos no admitidos en '.$tipo.': '.implode(', ', array_map(fn ($c): string => mb_substr((string) $c, 0, 40), $extra)).'.';
        }

        return match ($tipo) {
            'registrar_comida' => $this->validarRegistrar($accion),
            'corregir_comida' => $this->validarCorregir($accion),
            default => $this->validarFeedback($accion),
        };
    }

    /** @param  array<string, mixed>  $a */
    private function validarRegistrar(array $a): array|string
    {
        $perfil = $this->perfil($a['perfil'] ?? null);
        $fecha = $this->fecha($a['fecha'] ?? null);
        $momento = $this->enum(MomentoComida::class, $a['momento'] ?? null);
        $descripcion = $this->descripcion($a['descripcion'] ?? null);
        $origen = array_key_exists('origen', $a) ? $this->origen($a['origen']) : OrigenComida::Casa;
        $categorias = array_key_exists('categorias', $a) ? $this->categorias($a['categorias']) : [];
        $foto = array_key_exists('foto_hash', $a) ? $this->foto($a['foto_hash']) : null;
        foreach ([$perfil, $fecha, $momento, $descripcion, $origen, $categorias, $foto] as $r) {
            if ($this->esMotivo($r)) {
                return $r->motivo;
            }
        }

        return ['tipo' => 'registrar_comida', 'perfil_id' => $perfil->id, 'perfil' => $perfil->nombre, 'fecha' => $fecha, 'momento' => $momento, 'descripcion' => $descripcion,
            'origen' => $origen, 'categorias' => $categorias, 'foto' => $foto];
    }

    /** @param  array<string, mixed>  $a */
    private function validarCorregir(array $a): array|string
    {
        $id = $a['registro_id'] ?? null;
        if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
            return 'registro_id tiene que ser el número de un registro de comida.';
        }
        $registro = RegistroComida::query()->with('perfil')->find((int) $id);
        if (! $registro || ! $registro->perfil || ! $registro->perfil->activo) {
            return "No existe el registro de comida {$id}.";
        }
        if ($registro->fecha < $this->desde()) {
            return "El registro {$id} es demasiado antiguo para corregirlo desde el chat.";
        }
        $cambios = [];
        foreach (['fecha' => fn ($v) => $this->fecha($v), 'momento' => fn ($v) => $this->enum(MomentoComida::class, $v), 'descripcion' => fn ($v) => $this->descripcion($v),
            'origen' => fn ($v) => $this->origen($v), 'categorias' => fn ($v) => $this->categorias($v), 'foto_hash' => fn ($v) => $this->foto($v)] as $campo => $valida) {
            if (array_key_exists($campo, $a)) {
                $v = $valida($a[$campo]);
                if ($this->esMotivo($v)) {
                    return $v->motivo;
                }
                $cambios[$campo === 'foto_hash' ? 'foto' : $campo] = $v;
            }
        }
        if ($cambios === []) {
            return 'La corrección no cambia nada.';
        }

        return ['tipo' => 'corregir_comida', 'registro_id' => $registro->id, 'perfil' => $registro->perfil->nombre, 'cambios' => $cambios];
    }

    /** @param  array<string, mixed>  $a */
    private function validarFeedback(array $a): array|string
    {
        $perfil = $this->perfil($a['perfil'] ?? null);
        $tipo = $this->enum(TipoFeedback::class, $a['valoracion'] ?? null, 'valoracion');
        $elemento = $a['elemento'] ?? null;
        $nota = $a['nota'] ?? null;
        if ($this->esMotivo($perfil)) {
            return $perfil->motivo;
        }
        if ($this->esMotivo($tipo)) {
            return $tipo->motivo;
        }
        if (! is_string($elemento) || ! $this->textoLimpio($elemento, 2, 255)) {
            return 'elemento tiene que ser un texto de 2 a 255 caracteres.';
        }
        if ($nota !== null && (! is_string($nota) || ! $this->textoLimpio($nota, 1, 500))) {
            return 'nota tiene que ser un texto de hasta 500 caracteres.';
        }

        return ['tipo' => 'anotar_feedback', 'perfil_id' => $perfil->id, 'perfil' => $perfil->nombre, 'valoracion' => $tipo, 'elemento' => trim($elemento), 'nota' => $nota === null ? null : trim($nota)];
    }

    /** Un validador devuelve el valor ya normalizado o un Rechazo con el motivo. */
    private function esMotivo(mixed $v): bool
    {
        return $v instanceof Rechazo;
    }

    private function motivo(string $texto): Rechazo
    {
        return new Rechazo($texto);
    }

    private function perfil(mixed $valor): Perfil|Rechazo
    {
        if (is_int($valor) || (is_string($valor) && ctype_digit($valor))) {
            $perfil = Perfil::query()->activos()->find((int) $valor);

            return $perfil ?? $this->motivo("No existe un perfil activo con id {$valor}.");
        }
        if (is_string($valor) && trim($valor) !== '') {
            $encontrados = Perfil::query()->activos()->whereRaw('lower(nombre) = ?', [mb_strtolower(trim($valor))])->get();

            return match ($encontrados->count()) {
                1 => $encontrados->first(),
                0 => $this->motivo('No existe un perfil activo llamado «'.mb_substr($valor, 0, 60).'».'),
                default => $this->motivo('Hay varios perfiles llamados «'.mb_substr($valor, 0, 60).'»: hay que dar su id.'),
            };
        }

        return $this->motivo('Falta el perfil (su id).');
    }

    private function desde(): CarbonImmutable
    {
        return CarbonImmutable::today()->subDays((int) config('chat_claude.acciones.dias_atras'));
    }

    private function fecha(mixed $valor): CarbonImmutable|Rechazo
    {
        if (! is_string($valor) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
            return $this->motivo('fecha tiene que ser AAAA-MM-DD.');
        }
        $fecha = CarbonImmutable::createFromFormat('!Y-m-d', $valor);
        if ($fecha === false || $fecha->toDateString() !== $valor) {
            return $this->motivo("La fecha {$valor} no existe.");
        }
        if ($fecha->gt(CarbonImmutable::today())) {
            return $this->motivo("La fecha {$valor} es futura: solo se registra lo ya comido.");
        }
        if ($fecha->lt($this->desde())) {
            return $this->motivo("La fecha {$valor} es demasiado antigua (máximo ".(int) config('chat_claude.acciones.dias_atras').' días).');
        }

        return $fecha;
    }

    /** @param  class-string<\BackedEnum>  $clase */
    private function enum(string $clase, mixed $valor, string $campo = 'momento'): \BackedEnum|Rechazo
    {
        $enum = is_string($valor) ? $clase::tryFrom($valor) : null;

        return $enum ?? $this->motivo("{$campo} tiene que ser uno de: ".implode(', ', array_map(fn ($c): string => $c->value, $clase::cases())).'.');
    }

    private function origen(mixed $valor): OrigenComida|Rechazo
    {
        $origen = is_string($valor) ? OrigenComida::tryFrom($valor) : null;

        return $origen === OrigenComida::Casa || $origen === OrigenComida::Fuera ? $origen : $this->motivo('origen tiene que ser casa o fuera (lo que sale de una opción del menú lo registra la persona en el panel).');
    }

    /** @return array<int, string>|Rechazo */
    private function categorias(mixed $valor): array|Rechazo
    {
        $validas = array_map(fn (CategoriaComida $c): string => $c->value, CategoriaComida::cases());
        if (! is_array($valor) || ! array_is_list($valor) || count($valor) > 6 || array_diff($valor, $validas) !== [] || count($valor) !== count(array_unique($valor))) {
            return $this->motivo('categorias tiene que ser una lista (hasta 6) de: '.implode(', ', $validas).'.');
        }

        return array_values($valor);
    }

    private function descripcion(mixed $valor): string|Rechazo
    {
        $max = (int) config('chat_claude.acciones.max_descripcion');
        if (! is_string($valor) || ! $this->textoLimpio($valor, 3, $max)) {
            return $this->motivo("descripcion tiene que ser un texto de 3 a {$max} caracteres.");
        }

        return trim(preg_replace('/\s+/u', ' ', $valor));
    }

    /** Texto sin caracteres de control y de la longitud pedida (ya recortado). */
    private function textoLimpio(string $texto, int $min, int $max): bool
    {
        $largo = mb_strlen(trim($texto));

        return $largo >= $min && $largo <= $max && ! preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $texto) && mb_check_encoding($texto, 'UTF-8');
    }

    /** foto_hash: una foto de la persona en esta conversacion (y guardada). Devuelve el hash. */
    private function foto(mixed $valor): string|Rechazo
    {
        $hash = is_string($valor) ? Archivos::normalizarHash($valor) : null;
        if ($hash === null) {
            return $this->motivo('foto_hash tiene que ser el SHA3-256 (64 caracteres hexadecimales) de una foto de la conversación.');
        }

        return $this->fotoDeLaConversacion($hash) ? $hash : $this->motivo('foto_hash no es una foto enviada en esta conversación.');
    }

    private function fotoDeLaConversacion(string $hash): bool
    {
        if (! app(Archivos::class)->metadatos($hash)) {
            return false;
        }
        // CAST a texto vale en SQLite y en PostgreSQL (jsonb); el hash (64 hex, ya validado) es unico, y luego se confirma en PHP.
        $candidatos = ChatMensaje::query()->where('from_service', false)->whereRaw('CAST(contenido AS TEXT) LIKE ?', ['%'.$hash.'%'])->get();
        foreach ($candidatos as $mensaje) {
            foreach ($mensaje->imagenes() as $parte) {
                if (($parte['hash'] ?? null) === $hash) {
                    return true;
                }
            }
        }

        return false;
    }

    // ------------------------------------------------------------------ aplicacion

    /** @param  array<string, mixed>  $a  @return string lo que se hizo, para la persona */
    private function aplicar(array $a): string
    {
        $columnaFoto = Schema::hasColumn('registros_comida', 'foto_hash');
        switch ($a['tipo']) {
            case 'registrar_comida':
                $r = new RegistroComida;
                $r->fill(['perfil_id' => $a['perfil_id'], 'fecha' => $a['fecha']->toDateString(), 'momento' => $a['momento'], 'origen' => $a['origen'],
                    'descripcion' => $a['descripcion'], 'categorias' => $a['categorias'] ?: null]);
                $columnaFoto && $a['foto'] !== null && $r->forceFill(['foto_hash' => $a['foto']]);
                $r->save();

                return "Registrado para {$a['perfil']}: {$a['descripcion']} ({$a['momento']->getLabel()}, {$a['fecha']->toDateString()})"
                    .($a['foto'] !== null ? ($columnaFoto ? ', con la foto.' : '. La foto se ha guardado en el chat, pero este registro aún no puede enlazarla.') : '.');

            case 'corregir_comida':
                $r = RegistroComida::query()->lockForUpdate()->findOrFail($a['registro_id']);
                $c = $a['cambios'];
                $foto = $c['foto'] ?? null;
                unset($c['foto']);
                isset($c['fecha']) && $c['fecha'] = $c['fecha']->toDateString();
                $r->fill($c);
                $columnaFoto && $foto !== null && $r->forceFill(['foto_hash' => $foto]);
                $r->save();

                return "Corregido el registro {$a['registro_id']} de {$a['perfil']} (".implode(', ', array_keys($a['cambios'])).').';

            default:
                $f = new Feedback;
                $f->fill(['tipo' => $a['valoracion'], 'elemento' => $a['elemento'], 'nota' => $a['nota']]);
                $f->forceFill(['perfil_id' => $a['perfil_id']])->save();

                return "Anotado para {$a['perfil']}: {$a['valoracion']->getLabel()} «{$a['elemento']}».";
        }
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
