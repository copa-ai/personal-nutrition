<?php

namespace App\Services\Compra;

use App\Enums\CategoriaComida;
use App\Models\Alimento;
use App\Models\CardsIlustracion;
use App\Services\Chat\Archivos;
use App\Services\Chat\ErrorChat;
use Illuminate\Support\Str;

/**
 * Imagen de cada alimento de la lista de la compra. Siempre hay una: la ilustracion que dibujo el agente (GeneradorIlustraciones), que vive
 * como SVG en alimentos.ilustracion (compartida por todas las instancias de la base), o, mientras no la haya, una fija de su categoria
 * (SVG incluido aqui). Ambas pasan por SaneadorSvg (tambien la de la base, que puede haber escrito otra instancia) y Rasterizador y se
 * guardan como PNG en chat_archivos (por hash, cache local): cards.md solo admite image/jpeg e image/png. Sin la extension gd no hay
 * imagen y la tarjeta es solo texto.
 */
class Ilustraciones
{
    private const FONDO = '<rect x="0" y="0" width="100" height="100" fill="%s"/>';

    /** Dibujo por categoria sobre un fondo suave: [color de fondo, formas]. */
    private const POR_CATEGORIA = [
        'verdura' => ['#e3f1dc', '<ellipse cx="50" cy="56" rx="26" ry="32" fill="#43a047"/><ellipse cx="50" cy="56" rx="9" ry="26" fill="#a5d6a7"/><rect x="47" y="14" width="6" height="14" rx="3" fill="#2e7d32"/>'],
        'fruta' => ['#fdecea', '<circle cx="50" cy="56" r="29" fill="#e53935"/><ellipse cx="60" cy="22" rx="12" ry="6" fill="#43a047"/><rect x="48" y="20" width="4" height="12" fill="#6d4c41"/><circle cx="41" cy="48" r="6" fill="#ffffff" opacity="0.35"/>'],
        'carne_roja' => ['#f6e4e0', '<rect x="18" y="28" width="64" height="46" rx="20" fill="#b71c1c"/><rect x="30" y="40" width="40" height="22" rx="10" fill="#e57373"/><circle cx="68" cy="50" r="5" fill="#fff8e1"/>'],
        'carne_blanca' => ['#fbf0dc', '<ellipse cx="46" cy="46" rx="28" ry="22" fill="#e0a458"/><rect x="62" y="56" width="26" height="8" rx="4" fill="#fff8e1"/><circle cx="90" cy="60" r="5" fill="#fff8e1"/>'],
        'pescado' => ['#e1f0fa', '<ellipse cx="46" cy="50" rx="30" ry="18" fill="#42a5f5"/><polygon points="72,50 92,34 92,66" fill="#1e88e5"/><circle cx="32" cy="45" r="4" fill="#ffffff"/><circle cx="31" cy="45" r="2" fill="#0d47a1"/>'],
        'marisco' => ['#fdebdc', '<ellipse cx="50" cy="52" rx="28" ry="20" fill="#ff8a50"/><polygon points="76,52 92,38 92,66" fill="#f4511e"/><rect x="34" y="40" width="4" height="24" fill="#ffccbc"/><rect x="48" y="38" width="4" height="28" fill="#ffccbc"/><rect x="62" y="40" width="4" height="24" fill="#ffccbc"/>'],
        'huevo' => ['#f3efe4', '<ellipse cx="50" cy="52" rx="27" ry="34" fill="#fffdf6" stroke="#d7ccc8" stroke-width="2"/><circle cx="50" cy="56" r="13" fill="#ffca28"/>'],
        'lacteo' => ['#e6f0fa', '<rect x="30" y="30" width="40" height="58" rx="6" fill="#ffffff" stroke="#90caf9" stroke-width="2"/><rect x="34" y="14" width="32" height="18" rx="3" fill="#42a5f5"/><rect x="38" y="52" width="24" height="18" rx="4" fill="#bbdefb"/>'],
        'legumbre' => ['#ece5d6', '<ellipse cx="34" cy="58" rx="14" ry="20" fill="#8d6e63"/><ellipse cx="62" cy="48" rx="14" ry="20" fill="#a1887f"/><ellipse cx="52" cy="72" rx="12" ry="16" fill="#6d4c41"/>'],
        'cereal' => ['#faf1d3', '<rect x="48" y="30" width="4" height="58" fill="#9e8a3c"/><ellipse cx="42" cy="34" rx="8" ry="13" fill="#f2c94c"/><ellipse cx="58" cy="34" rx="8" ry="13" fill="#f2c94c"/><ellipse cx="42" cy="54" rx="8" ry="13" fill="#e0b73a"/><ellipse cx="58" cy="54" rx="8" ry="13" fill="#e0b73a"/><ellipse cx="50" cy="18" rx="7" ry="12" fill="#f2c94c"/>'],
        'frutos_secos' => ['#efe3d6', '<ellipse cx="38" cy="54" rx="18" ry="24" fill="#a47551"/><ellipse cx="64" cy="50" rx="16" ry="22" fill="#8a5a3a"/><ellipse cx="38" cy="54" rx="6" ry="14" fill="#c9a27c"/>'],
        'procesado' => ['#e8eaed', '<rect x="24" y="22" width="52" height="62" rx="5" fill="#90a4ae"/><rect x="24" y="40" width="52" height="20" fill="#eceff1"/><circle cx="50" cy="50" r="6" fill="#ef5350"/>'],
        'dulce' => ['#fbe5ef', '<circle cx="50" cy="38" r="22" fill="#ec407a"/><circle cx="50" cy="38" r="13" fill="#f8bbd0"/><circle cx="50" cy="38" r="5" fill="#ec407a"/><rect x="47" y="58" width="6" height="32" rx="3" fill="#8d6e63"/>'],
        'otro' => ['#eceae4', '<polygon points="16,44 84,44 74,86 26,86" fill="#bc8f5f"/><rect x="12" y="38" width="76" height="9" rx="3" fill="#a1744a"/><rect x="44" y="18" width="12" height="22" rx="6" fill="none" stroke="#a1744a" stroke-width="4"/>'],
    ];

    /** @var array<string, ?array{hash: string, mime: string, tamano: int}> */
    private array $porDefecto = [];

    public function __construct(private readonly Archivos $archivos) {}

    /** Nombre normalizado (sin tildes, minusculas, espacios simples): la clave de la ilustracion de un alimento. */
    public static function clave(string $nombre): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/', ' ', Str::ascii(mb_strtolower($nombre)))), 0, 191);
    }

    public static function svgDeCategoria(CategoriaComida $categoria): string
    {
        [$fondo, $formas] = self::POR_CATEGORIA[$categoria->value] ?? self::POR_CATEGORIA['otro'];

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100">'.sprintf(self::FONDO, $fondo).$formas.'</svg>';
    }

    /** @return array{hash: string, mime: string, tamano: int}|null la ilustracion fija de la categoria (se crea la primera vez) */
    public function deCategoria(CategoriaComida $categoria): ?array
    {
        if (array_key_exists($categoria->value, $this->porDefecto)) {
            return $this->porDefecto[$categoria->value];
        }
        $modelo = SaneadorSvg::sanear(self::svgDeCategoria($categoria), 4096, 40);
        $png = $modelo === null ? null : Rasterizador::png($modelo, (int) config('cards.ilustraciones.lado_png'));

        return $this->porDefecto[$categoria->value] = $png === null ? null : $this->guardarPng($png, 'ilustracion-'.$categoria->value.'.png');
    }

    /**
     * Imagen de cada alimento: la de su SVG de alimentos.ilustracion si lo hay y se puede dibujar; si no, la de su categoria.
     *
     * @param  list<array{nombre: string, categoria: CategoriaComida}>  $alimentos
     * @return array<string, array{hash: string, mime: string, tamano: int}|null> clave => imagen
     */
    public function de(array $alimentos): array
    {
        $claves = array_values(array_unique(array_map(fn (array $a): string => self::clave($a['nombre']), $alimentos)));
        $svgs = $claves === [] ? [] : self::svgsDelCatalogo();
        $cache = $claves === [] ? collect() : CardsIlustracion::query()->whereIn('clave', $claves)->get()->keyBy('clave');

        $resultado = [];
        foreach ($alimentos as $a) {
            $clave = self::clave($a['nombre']);
            $svg = $svgs[$clave] ?? null;
            $resultado[$clave] = ($svg !== null ? $this->dePng($clave, $a['nombre'], $svg, $cache->get($clave)) : null) ?? $this->deCategoria($a['categoria']);
        }

        return $resultado;
    }

    /** El SVG de cada alimento del catalogo que ya tiene ilustracion. @return array<string, string> clave => svg */
    public static function svgsDelCatalogo(): array
    {
        $svgs = [];
        foreach (Alimento::query()->whereNotNull('ilustracion')->get(['nombre', 'ilustracion']) as $alimento) {
            $svgs[self::clave($alimento->nombre)] = (string) $alimento->ilustracion;
        }

        return $svgs;
    }

    /**
     * Guarda la ilustracion que dibujo el agente en el catalogo (alimentos.ilustracion), creando el alimento si no existia, y deja listo su
     * PNG local. False si el SVG no pasa el saneado o no se puede dibujar.
     *
     * @param  array<string, mixed>|null  $fila  la fila de la lista (categoria y familia de unidad) para crear el alimento si falta
     */
    public function guardarGenerada(string $nombre, string $svg, ?array $fila = null): bool
    {
        $svg = trim($svg);
        if ($this->rasterizar($svg) === null) {
            return false;
        }
        $clave = self::clave($nombre);
        $alimento = null;
        foreach (Alimento::query()->get(['id', 'nombre']) as $existente) {
            if (self::clave($existente->nombre) === $clave) {
                $alimento = Alimento::query()->find($existente->id);

                break;
            }
        }
        $alimento ??= new Alimento([
            'nombre' => mb_substr($nombre, 0, 255),
            'categoria' => CategoriaComida::tryFrom((string) ($fila['categoria'] ?? '')) ?? CategoriaComida::Otro,
            'unidad' => in_array($fila['familia'] ?? null, ['g', 'ml', 'ud'], true) ? $fila['familia'] : 'ud',
        ]);
        $alimento->ilustracion = $svg;
        $alimento->save();

        $this->dePng($clave, $alimento->nombre, $svg, CardsIlustracion::query()->find($clave));

        return true;
    }

    /** El PNG de un SVG tras el saneado, o null si no vale (no es un SVG aceptable, no se puede dibujar o pesa demasiado). */
    private function rasterizar(string $svg): ?string
    {
        $cfg = config('cards.ilustraciones');
        $modelo = SaneadorSvg::sanear($svg, (int) $cfg['max_svg_bytes'], (int) $cfg['max_formas']);
        $png = $modelo === null ? null : Rasterizador::png($modelo, (int) $cfg['lado_png']);

        return $png === null || strlen($png) > (int) $cfg['max_png_bytes'] ? null : $png;
    }

    /**
     * El PNG local de un SVG del catalogo: el de la cache si sigue siendo de ese SVG y esta en chat_archivos; si no, se dibuja y se guarda.
     *
     * @return array{hash: string, mime: string, tamano: int}|null
     */
    private function dePng(string $clave, string $nombre, string $svg, ?CardsIlustracion $cache): ?array
    {
        $sha = sha1($svg);
        if ($cache !== null && $cache->svg_sha === $sha && $cache->hash !== null && ($meta = $this->archivos->metadatos($cache->hash)) !== null) {
            return ['hash' => $meta->hash, 'mime' => $meta->mime, 'tamano' => (int) $meta->tamano];
        }
        $png = $this->rasterizar($svg);
        $archivo = $png === null ? null : $this->guardarPng($png, 'ilustracion-'.Str::slug($nombre).'.png');
        if ($archivo === null) {
            return null;
        }
        CardsIlustracion::query()->updateOrCreate(['clave' => $clave], ['nombre' => mb_substr($nombre, 0, 191), 'hash' => $archivo['hash'], 'svg_sha' => $sha, 'estado' => 'ok']);

        return $archivo;
    }

    /** @return array{hash: string, mime: string, tamano: int}|null */
    private function guardarPng(string $png, string $nombre): ?array
    {
        $hash = hash('sha3-256', $png);
        try {
            $this->archivos->guardar($hash, $png, 'image/png', (string) strlen($png), $nombre);
        } catch (ErrorChat) {
            return null;
        }

        return ['hash' => $hash, 'mime' => 'image/png', 'tamano' => strlen($png)];
    }
}
