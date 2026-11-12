<?php

namespace App\Services\Compra;

use App\Models\Auditoria;
use App\Models\CardsIlustracion;
use App\Models\CardsMazo;
use App\Services\Claude\EjecutorClaude;
use App\Services\Claude\LimiteUso;
use App\Services\Menu\TokenClaude;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Encarga al agente de Claude las ilustraciones de los alimentos de la lista que aun no tienen una (por demanda y en lote). El agente
 * recibe en el prompt solo nombres de alimentos y contesta con SVG de un subconjunto cerrado; Laravel lo comprueba (SaneadorSvg), lo guarda
 * en el catalogo (alimentos.ilustracion, compartido entre instancias; crea el alimento si falta) y deja su PNG local (Rasterizador). El agente nunca toca la base. Tope de coste: alimentos por lote, lotes al dia
 * e intentos por alimento (config/cards.php). Sin token de Claude no hace nada: las tarjetas usan la ilustracion de su categoria.
 */
class GeneradorIlustraciones
{
    public const MARCA = 'Turno de ilustraciones';

    public function __construct(private readonly Ilustraciones $ilustraciones) {}

    /**
     * Nombres de los alimentos de las filas que hay que ilustrar: sin ilustracion en el catalogo (alimentos.ilustracion), con intentos
     * disponibles y fuera de espera.
     *
     * @param  list<array<string, mixed>>  $filas
     * @return list<string>
     */
    public static function pendientes(array $filas, int $limite): array
    {
        $cfg = config('cards.ilustraciones');
        $hecho = CardsIlustracion::query()->get()->keyBy('clave');
        $conSvg = Ilustraciones::svgsDelCatalogo();
        $esperaMs = (int) $cfg['espera_reintento_min'] * 60000;
        $ahora = (int) (microtime(true) * 1000);
        $nombres = [];
        foreach ($filas as $fila) {
            $clave = Ilustraciones::clave((string) $fila['nombre']);
            if (isset($conSvg[$clave])) {
                continue;
            }
            $previo = $hecho->get($clave);
            if ($previo !== null && ($previo->intentos >= (int) $cfg['max_intentos']
                || ($previo->ultimo_intento_ms !== null && $ahora - $previo->ultimo_intento_ms < $esperaMs))) {
                continue;
            }
            $nombres[$clave] = mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string) $fila['nombre'])), 0, 60);
            if (count($nombres) >= $limite) {
                break;
            }
        }

        return array_values($nombres);
    }

    public static function prompt(array $nombres): string
    {
        return self::MARCA.'. Usa la skill ilustrar-alimentos. Alimentos (JSON): '.json_encode(array_values($nombres), JSON_UNESCAPED_UNICODE)
            .'. Responde solo con el JSON que describe la skill, sin texto alrededor.';
    }

    /** Un encargo al agente con el siguiente lote. Devuelve cuantas ilustraciones se guardaron. */
    public function generar(): int
    {
        $cfg = config('cards.ilustraciones');
        if (! $cfg['habilitadas'] || ! TokenClaude::existe()) {
            return 0;
        }
        $ahora = (int) (microtime(true) * 1000);
        if (DB::connection('chat')->table('cards_lotes')->where('creado_ms', '>', $ahora - 86400000)->count() >= (int) $cfg['max_lotes_dia']) {
            return 0;
        }
        $filas = CardsMazo::query()->first()?->tarjetas ?? [];
        $nombres = self::pendientes($filas, (int) $cfg['max_por_lote']);
        if ($nombres === []) {
            return 0;
        }

        DB::connection('chat')->table('cards_lotes')->insert(['creado_ms' => $ahora, 'alimentos' => count($nombres)]);
        foreach ($nombres as $nombre) {
            $fila = CardsIlustracion::query()->firstOrNew(['clave' => Ilustraciones::clave($nombre)]);
            $fila->fill(['nombre' => $nombre, 'estado' => 'pendiente', 'intentos' => ($fila->intentos ?? 0) + 1, 'ultimo_intento_ms' => $ahora])->save();
        }

        $porClave = [];
        foreach ($filas as $fila) {
            $porClave[Ilustraciones::clave((string) $fila['nombre'])] = $fila;
        }
        $guardadas = 0;
        $resultado = $this->preguntar($nombres);
        if ($resultado !== null) {
            foreach ($resultado as $dibujo) {
                $nombre = $this->nombreSolicitado($nombres, (string) ($dibujo['alimento'] ?? ''));
                if ($nombre !== null && is_string($dibujo['svg'] ?? null) && $this->ilustraciones->guardarGenerada($nombre, $dibujo['svg'], $porClave[Ilustraciones::clave($nombre)] ?? null)) {
                    $guardadas++;
                }
            }
        }
        CardsIlustracion::query()->whereIn('clave', array_map(Ilustraciones::clave(...), $nombres))->where('estado', 'pendiente')->update(['estado' => 'fallida']);
        Auditoria::escribir('cards.ilustraciones', 'Ilustraciones de la lista de la compra', ['pedidas' => count($nombres), 'guardadas' => $guardadas]);

        return $guardadas;
    }

    /**
     * Lanza el turno (esperando si el agente esta ocupado) y lee su JSON. Null si falla.
     *
     * @param  list<string>  $nombres
     * @return list<array<string, mixed>>|null
     */
    private function preguntar(array $nombres): ?array
    {
        $cfg = config('cards.ilustraciones');
        $hasta = microtime(true) + (int) $cfg['espera_agente_max_s'];
        while (true) {
            try {
                $r = (new EjecutorClaude)->ejecutar(self::prompt($nombres), (string) $cfg['herramientas'], (float) $cfg['timeout']);
                break;
            } catch (RuntimeException $e) {
                if (! str_contains($e->getMessage(), '(429)') || microtime(true) >= $hasta) {
                    report($e);

                    return null;
                }
                sleep(max(1, (int) $cfg['espera_agente_cada_s']));
            }
        }
        if ($r->codigo !== 0 || LimiteUso::renovacion($r->texto()) !== null) {
            return null;
        }
        $texto = $r->salida;
        $ini = strpos($texto, '{');
        $fin = strrpos($texto, '}');
        $datos = $ini === false || $fin === false ? null : json_decode(substr($texto, $ini, $fin - $ini + 1), true);
        $lista = is_array($datos) ? ($datos['ilustraciones'] ?? null) : null;

        return is_array($lista) && array_is_list($lista) ? array_values(array_filter($lista, 'is_array')) : null;
    }

    /** @param  list<string>  $nombres */
    private function nombreSolicitado(array $nombres, string $recibido): ?string
    {
        foreach ($nombres as $nombre) {
            if (Ilustraciones::clave($nombre) === Ilustraciones::clave($recibido)) {
                return $nombre;
            }
        }

        return null;
    }
}
