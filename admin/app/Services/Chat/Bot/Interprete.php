<?php

namespace App\Services\Chat\Bot;

use App\Models\Perfil;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Entiende, sin IA y de forma determinista, lo que dice la persona. Solo mira el texto (y si trae fotos): no toca la base, así
 * que se prueba sin ella. El orden de las reglas importa y está en interpretar().
 */
final class Interprete
{
    public const MESES = ['enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6, 'julio' => 7, 'agosto' => 8,
        'septiembre' => 9, 'setiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12];

    public const DIAS = ['lunes' => 1, 'martes' => 2, 'miercoles' => 3, 'jueves' => 4, 'viernes' => 5, 'sabado' => 6, 'domingo' => 7];

    /** Máximo de caracteres de una descripción de comida. */
    public const MAX_DESCRIPCION = 500;

    /** Minúsculas, sin tildes, con todo lo que no sea letra, número, «/», «-» o «:» convertido en espacio. */
    public static function normalizar(string $texto): string
    {
        $texto = strtr(mb_strtolower($texto), ['á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i', 'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'ú' => 'u', 'ù' => 'u', 'ü' => 'u',
            'û' => 'u', 'ñ' => 'n', 'ç' => 'c']);

        return trim((string) preg_replace('/[^a-z0-9\/:\-]+/', ' ', $texto));
    }

    /**
     * @param  Collection<int, Perfil>  $perfiles  los perfiles activos
     */
    public static function interpretar(string $texto, bool $traeImagenes, Collection $perfiles, CarbonImmutable $hoy): Intencion
    {
        if (($orden = self::ordenDeRegistro($texto, $hoy)) !== null) {
            return new Intencion(Intencion::REGISTRAR_ORDEN, $orden);
        }
        $norm = self::normalizar($texto);

        if ($traeImagenes || self::quiereRegistrar($texto, $norm)) {
            return new Intencion(Intencion::REGISTRAR, self::datosDeRegistro($texto, $norm, $traeImagenes, $perfiles, $hoy));
        }
        if (self::pideIa($norm)) {
            return new Intencion(Intencion::IA);
        }
        if (self::pideCompra($norm)) {
            return new Intencion(Intencion::COMPRA);
        }
        if (preg_match('/\b(despensa|existencias?|nevera|frigorifico|stock|caduca\w*|que (hay|tengo|queda|tenemos|nos queda) en (casa|la nevera|la despensa|el frigorifico)|alimentos en casa|ingredientes en casa)\b/', $norm) === 1) {
            return new Intencion(Intencion::DESPENSA);
        }
        if (self::pideMenu($norm)) {
            return self::intencionDeMenu($norm, $perfiles, $hoy);
        }
        if (preg_match('/^(gracias|muchas gracias|mil gracias|vale gracias|perfecto gracias|genial gracias)\b/', $norm) === 1) {
            return new Intencion(Intencion::GRACIAS);
        }
        if (preg_match('/^(adios|hasta luego|hasta manana|chao|chau|nos vemos)\b/', $norm) === 1) {
            return new Intencion(Intencion::ADIOS);
        }
        if (preg_match('/\b(ayuda|ayudame|help|que (puedes|sabes|haces) hacer|para que sirves|que puedo (preguntar|pedir|hacer)|comandos|opciones)\b/', $norm) === 1) {
            return new Intencion(Intencion::AYUDA);
        }
        if (preg_match('/^(hola|holi|buenas|buenos dias|buenas tardes|buenas noches|buen dia|hey|ey|ei|hello|saludos|que tal|como estas|empezar|inicio)\b/', $norm) === 1 && count(explode(' ', $norm)) <= 6) {
            return new Intencion(Intencion::SALUDO);
        }

        return new Intencion(Intencion::NO_ENTIENDO);
    }

    /** «lista de la compra», «qué tengo que comprar», «voy al súper»… (ya normalizado). */
    public static function pideCompra(string $norm): bool
    {
        return preg_match('/\b(lista de (la )?compras?|la compra|compra de la semana|que (tengo|tenemos|hay|debo|debemos|hace falta|falta) (que )?comprar|que (me |nos )?falta|voy al (super|supermercado|mercado)|ir al (super|supermercado|mercado)|ir a comprar|hacer la compra)\b/', $norm) === 1;
    }

    // ------------------------------------------------------------------ menú

    private static function pideMenu(string $norm): bool
    {
        return preg_match('/\b(menus?|plan (de comidas|semanal|de la semana))\b/', $norm) === 1
            || preg_match('/\bque (ceno|cenamos|cenan|cena|como|comemos|comen|come|desayuno|desayunamos|desayunan|desayuna|almuerzo|almorzamos|almuerza|almuerzan|meriendo|merendamos|merienda|toca|hay para|hay de|cocino|cocinamos|preparo|preparamos|vamos a (cenar|comer))\b/', $norm) === 1
            || preg_match('/\bque (tiene|tienen|tengo|tenemos|tenia|tenian|toca|debe|deben|debo|deberia|hay) (que |de )?(comer|cenar|desayunar|almorzar|merendar)\b/', $norm) === 1
            || preg_match('/\b(cena|comida|desayuno|almuerzo|merienda) (de |para )?(hoy|manana|pasado manana|esta noche)\b/', $norm) === 1;
    }

    /** @param  Collection<int, Perfil>  $perfiles */
    private static function intencionDeMenu(string $norm, Collection $perfiles, CarbonImmutable $hoy): Intencion
    {
        $mencionados = self::perfilesMencionados($norm, $perfiles);
        $todos = preg_match('/\b(todos|todas|toda la familia|la familia|todo el mundo|los dos|las dos|ambos|ambas|entre todos)\b/', $norm) === 1;
        $persona = preg_match('/\b(yo|mi|mis|mio|me|hijo|hija|hijos|nino|nina|ninos|marido|mujer|pareja|esposo|esposa|el|ella|tengo|tiene|tienen|ceno|como|almuerzo|desayuno|meriendo|cocino|preparo)\b/', $norm) === 1;

        if ($mencionados->isNotEmpty() && ! $todos) {
            $quien = 'algunos';
        } elseif ($todos || $perfiles->count() <= 1 || ! $persona) {
            $quien = 'todos';
            $mencionados = collect();
        } else {
            $quien = 'preguntar';
        }

        $fecha = self::fecha($norm, $hoy, true);
        $semana = $fecha === null && preg_match('/\b(semana|semanal|proximos dias|toda la semana|todos los dias|esta semana)\b/', $norm) === 1;

        return new Intencion($semana ? Intencion::SEMANA : Intencion::MENU, [
            'quien' => $quien,
            'perfiles' => $mencionados->pluck('id')->all(),
            'fecha' => $fecha['fecha'] ?? null,
            'dia_semana' => $fecha['dia_semana'] ?? null,
            'momentos' => self::momentos($norm),
        ]);
    }

    /** Momentos que se piden: desayuno, almuerzo, comida, merienda, cena. Vacío = todo el día. @return list<string> */
    public static function momentos(string $norm): array
    {
        $momentos = [];
        foreach ([
            'desayuno' => '/\bdesayun\w*\b/', 'almuerzo' => '/\b(almuerz\w*|almorz\w*)\b/', 'comida' => '/\bcomidas?\b/',
            'merienda' => '/\bmeri?end\w*/', 'cena' => '/\b(cenas?|cenar|cenamos|cenan|ceno|cenaremos|cenaron)\b/',
        ] as $momento => $patron) {
            if (preg_match($patron, $norm) === 1) {
                $momentos[] = $momento;
            }
        }

        return $momentos;
    }

    // ------------------------------------------------------------------ IA

    private static function pideIa(string $norm): bool
    {
        return preg_match('/\b(cocinar con|cocino con|cocinamos con|preparar con|que puedo (cocinar|preparar|hacer|cenar|comer)|que podemos (cocinar|preparar|hacer|cenar|comer)|receta|recetas|recomiend\w*|recomendacion\w*|aconsej\w*|consejo|consejos|sugiere\w*|sugerencia\w*|analiz\w*|analisis|calorias|kcal|proteinas|nutrientes|macros|es sano|es saludable|saludable|sustitu\w*|reemplaz\w*|en vez de|por que|explica\w*|ideas?|evalua\w*|valora\w*|opinas|opinion|mejorar|adelgazar|engordar|dieta)\b/', $norm) === 1;
    }

    // ------------------------------------------------------------------ registrar

    private static function quiereRegistrar(string $texto, string $norm): bool
    {
        return preg_match('/\b(registr\w*|anota\w*|apunta\w*)\b/', $norm) === 1 && preg_match('/\b(comid\w*|cena\w*|desayun\w*|almuerz\w*|almorz\w*|merend\w*|comi\w*|plato)\b/', $norm) === 1
            || self::coincideVerboDeComer($texto) !== null;
    }

    /** @return array{0: int, 1: int}|null desplazamiento (en caracteres) y longitud del verbo de «he comido / cené...», o null */
    private static function coincideVerboDeComer(string $texto): ?array
    {
        $verbos = '(?:(?:he|ha|hemos|han|has)\s+(?:comido|cenado|almorzado|desayunado|merendado)|com[ií]|comimos|cen[eé]|almorc[eé]|desayun[eé]|merend[eé]|com[ií]ó|cenó|almorzó|desayunó|merendó|comieron|cenaron|almorzaron|desayunaron|merendaron)';
        if (preg_match('/(?<![\p{L}\p{N}])'.$verbos.'(?![\p{L}\p{N}])/iu', $texto, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        return [mb_strlen(substr($texto, 0, $m[0][1])), mb_strlen($m[0][0])];
    }

    /**
     * Datos que ya se saben de un registro de comida dicho en texto libre.
     *
     * @param  Collection<int, Perfil>  $perfiles
     * @return array{perfil_id: ?int, momento: ?string, fecha: CarbonImmutable, descripcion: string, con_imagenes: bool}
     */
    private static function datosDeRegistro(string $texto, string $norm, bool $traeImagenes, Collection $perfiles, CarbonImmutable $hoy): array
    {
        $mencionados = self::perfilesMencionados($norm, $perfiles);
        $perfil = $mencionados->count() === 1 ? $mencionados->first() : ($mencionados->isEmpty() && $perfiles->count() === 1 ? $perfiles->first() : null);

        $cena = preg_match('/\b(cena|cenar|cene|ceno|cenado|cenamos|cenaron|cenando|anoche)\b/', $norm) === 1;
        // «comí / he comido» no dice si fue comida o cena: solo el almuerzo cuenta como comida.
        $comida = preg_match('/\b(almorzado|almorce|almorzo|almorzamos|almorzaron|almuerzo)\b/', $norm) === 1 && ! $cena;
        $otro = preg_match('/\b(desayun\w*|meri?end\w*)\b/', $norm) === 1;
        $momento = $cena ? 'cena' : ($comida ? 'comida' : ($otro ? 'otro' : null));

        $fecha = self::fecha($norm, $hoy, false)['fecha'] ?? (preg_match('/\banoche\b/', $norm) === 1 ? $hoy->subDay() : $hoy);

        return [
            'perfil_id' => $perfil?->id,
            'momento' => $momento,
            'fecha' => $fecha,
            'descripcion' => self::descripcionLibre($texto, $mencionados),
            'con_imagenes' => $traeImagenes,
        ];
    }

    /** @param  Collection<int, Perfil>  $mencionados */
    private static function descripcionLibre(string $texto, Collection $mencionados): string
    {
        $texto = trim($texto);
        $descripcion = $texto;
        if (preg_match('/^[^:\n]*:\s*(.+)$/su', $texto, $m) === 1 && preg_match('/\b(registr\w*|anot\w*|apunt\w*)\b/iu', (string) strstr($texto, ':', true)) === 1) {
            $descripcion = $m[1];
        } elseif (($v = self::coincideVerboDeComer($texto)) !== null) {
            $descripcion = mb_substr($texto, $v[0] + $v[1]);
        } elseif (preg_match('/\b(?:registr\w*|anot\w*|apunt\w*)\b\s*(?:que\s+)?(?:(?:he|ha|hemos)\s+\w+\s+)?(.*)$/isu', $texto, $m) === 1) {
            $descripcion = $m[1];
            $descripcion = (string) preg_replace('/^(?:(?:una?|la|lo|mi|su|de)\s+)*(?:comida|cena|desayuno|almuerzo|merienda)\b\s*(?:de\s+hoy|de\s+ayer)?\s*/iu', '', $descripcion);
        }
        foreach ($mencionados as $perfil) {
            $descripcion = (string) preg_replace('/(?<![\p{L}\p{N}])'.preg_quote($perfil->nombre, '/').'(?![\p{L}\p{N}])/iu', ' ', $descripcion);
        }
        $descripcion = (string) preg_replace('/(?<![\p{L}\p{N}])(?:hoy|ayer|anteayer|anoche|esta noche)(?![\p{L}\p{N}])/iu', ' ', $descripcion);
        $descripcion = (string) preg_replace('/\s+/u', ' ', $descripcion);
        $descripcion = trim($descripcion, " \t\n\r,:;.-–");
        $descripcion = trim((string) preg_replace('/^(?:de|del|para|a|que)\s+|\s+(?:de|del|para|a|que)$/iu', '', $descripcion));

        return mb_substr($descripcion, 0, self::MAX_DESCRIPCION);
    }

    /**
     * La orden que genera la tecla del formulario: «registrar comida perfil=Ana momento=cena fecha=2026-11-05: pasta».
     *
     * @return array{perfil: string, momento: string, fecha: CarbonImmutable, descripcion: string}|null
     */
    public static function ordenDeRegistro(string $texto, CarbonImmutable $hoy): ?array
    {
        if (preg_match('/^\s*registrar\s+comida\s+perfil=(?<perfil>[^\r\n]+?)\s+momento=(?<momento>comida|cena|otro)\s+fecha=(?<fecha>\d{4}-\d{2}-\d{2})\s*:\s*(?<desc>[\s\S]*)$/iu', $texto, $m) !== 1) {
            return null;
        }
        [$a, $mes, $d] = array_map('intval', explode('-', $m['fecha']));
        if (! checkdate($mes, $d, $a)) {
            return null;
        }
        $descripcion = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', ' ', $m['desc'])));
        if ($descripcion === '') {
            return null;
        }

        return [
            'perfil' => trim($m['perfil']),
            'momento' => strtolower($m['momento']),
            'fecha' => CarbonImmutable::create($a, $mes, $d, 0, 0, 0, $hoy->getTimezone()),
            'descripcion' => mb_substr($descripcion, 0, self::MAX_DESCRIPCION),
        ];
    }

    // ------------------------------------------------------------------ perfiles y fechas

    /**
     * Perfiles de los que habla el texto (normalizado): por su nombre completo, o por una palabra de su nombre que no comparta otro.
     *
     * @param  Collection<int, Perfil>  $perfiles
     * @return Collection<int, Perfil>
     */
    public static function perfilesMencionados(string $norm, Collection $perfiles): Collection
    {
        $palabras = [];
        foreach ($perfiles as $perfil) {
            foreach (array_unique(array_filter(explode(' ', self::normalizar($perfil->nombre)), fn (string $p): bool => mb_strlen($p) >= 3)) as $palabra) {
                $palabras[$palabra][] = $perfil->id;
            }
        }

        return $perfiles->filter(function (Perfil $perfil) use ($norm, $palabras): bool {
            $nombre = self::normalizar($perfil->nombre);
            if ($nombre !== '' && preg_match('/(?<![a-z0-9])'.preg_quote($nombre, '/').'(?![a-z0-9])/', $norm) === 1) {
                return true;
            }
            foreach ($palabras as $palabra => $ids) {
                if ($ids === [$perfil->id] && in_array($palabra, explode(' ', $nombre), true) && preg_match('/(?<![a-z0-9])'.preg_quote((string) $palabra, '/').'(?![a-z0-9])/', $norm) === 1) {
                    return true;
                }
            }

            return false;
        })->values();
    }

    /**
     * Día del que habla el texto (normalizado): hoy, mañana, pasado mañana, ayer, anteayer, una fecha ISO, «5/11», «5 de noviembre»
     * o un día de la semana (en $futuro, el próximo; si no, el último pasado). Null si no dice ninguno.
     *
     * @return array{fecha: ?CarbonImmutable, dia_semana: ?int}|null `fecha` es null cuando solo se sabe el día de la semana
     */
    public static function fecha(string $norm, CarbonImmutable $hoy, bool $futuro): ?array
    {
        $dia = $hoy->startOfDay();
        if (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/', $norm, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return ['fecha' => $dia->setDate((int) $m[1], (int) $m[2], (int) $m[3]), 'dia_semana' => null];
        }
        if (preg_match('/\bpasado manana\b/', $norm) === 1) {
            return ['fecha' => $dia->addDays(2), 'dia_semana' => null];
        }
        if (preg_match('/\banteayer\b|\bantes de ayer\b/', $norm) === 1) {
            return ['fecha' => $dia->subDays(2), 'dia_semana' => null];
        }
        if (preg_match('/\bmanana\b/', $norm) === 1) {
            return ['fecha' => $dia->addDay(), 'dia_semana' => null];
        }
        if (preg_match('/\bayer\b/', $norm) === 1) {
            return ['fecha' => $dia->subDay(), 'dia_semana' => null];
        }
        if (preg_match('/\b(hoy|esta noche|esta tarde|este mediodia)\b/', $norm) === 1) {
            return ['fecha' => $dia, 'dia_semana' => null];
        }
        if (preg_match('/\b(\d{1,2}) de ('.implode('|', array_keys(self::MESES)).')\b/', $norm, $m) === 1
            && checkdate(self::MESES[$m[2]], (int) $m[1], $dia->year)) {
            return ['fecha' => $dia->setDate($dia->year, self::MESES[$m[2]], (int) $m[1]), 'dia_semana' => null];
        }
        if (preg_match('/\b(\d{1,2})\/(\d{1,2})(?:\/(\d{2,4}))?\b/', $norm, $m) === 1) {
            $anio = isset($m[3]) ? (int) $m[3] + (strlen($m[3]) === 2 ? 2000 : 0) : $dia->year;
            if (checkdate((int) $m[2], (int) $m[1], $anio)) {
                return ['fecha' => $dia->setDate($anio, (int) $m[2], (int) $m[1]), 'dia_semana' => null];
            }
        }
        if (preg_match('/\b('.implode('|', array_keys(self::DIAS)).')\b/', $norm, $m) === 1) {
            $numero = self::DIAS[$m[1]];
            $falta = $futuro ? ($numero - $dia->dayOfWeekIso + 7) % 7 : -(($dia->dayOfWeekIso - $numero + 7) % 7);

            return ['fecha' => $dia->addDays($falta), 'dia_semana' => $numero];
        }

        return null;
    }
}
