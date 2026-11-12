<?php

namespace App\Services\Chat\Claude;

use App\Enums\CategoriaComida;
use App\Enums\OrigenComida;
use App\Models\Alimento;
use App\Models\ChatMensaje;
use App\Models\Hogar;
use App\Models\Perfil;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use App\Services\Chat\Archivos;

/**
 * Validadores estrictos que comparten los grupos de acciones del chat. Cada uno devuelve el valor ya normalizado o un Rechazo con el
 * motivo (en castellano, para la persona). Nunca se fia de lo que propone el agente: ids uuid que existan, enums, fechas reales, tamaños.
 */
class Validadores
{
    /** El primer Rechazo de una lista de valores validados, o null si todos son validos. */
    public function motivo(mixed ...$valores): ?string
    {
        foreach ($valores as $valor) {
            if ($valor instanceof Rechazo) {
                return $valor->motivo;
            }
        }

        return null;
    }

    public function rechazo(string $texto): Rechazo
    {
        return new Rechazo($texto);
    }

    public function desde(): CarbonImmutable
    {
        return CarbonImmutable::today()->subDays((int) config('chat_claude.acciones.dias_atras'));
    }

    /** Perfil activo por id, o por nombre exacto si es unico. */
    public function perfil(mixed $valor): Perfil|Rechazo
    {
        return $this->perfilDe($valor, true);
    }

    /** Perfil (activo o no) por id, o por nombre exacto si es unico. */
    public function perfilCualquiera(mixed $valor): Perfil|Rechazo
    {
        return $this->perfilDe($valor, false);
    }

    private function perfilDe(mixed $valor, bool $soloActivos): Perfil|Rechazo
    {
        $consulta = fn () => $soloActivos ? Perfil::query()->activos() : Perfil::query();
        $tipo = $soloActivos ? 'un perfil activo' : 'un perfil';
        if (is_string($valor) && Str::isUuid($valor)) {
            return $consulta()->find($valor) ?? $this->rechazo("No existe {$tipo} con id {$valor}.");
        }
        if (is_string($valor) && trim($valor) !== '') {
            $encontrados = $consulta()->whereRaw('lower(nombre) = ?', [mb_strtolower(trim($valor))])->get();

            return match ($encontrados->count()) {
                1 => $encontrados->first(),
                0 => $this->rechazo('No existe '.$tipo.' llamado «'.mb_substr($valor, 0, 60).'».'),
                default => $this->rechazo('Hay varios perfiles llamados «'.mb_substr($valor, 0, 60).'»: hay que dar su id.'),
            };
        }

        return $this->rechazo('Falta el perfil (su id).');
    }

    /** Hogar por id, o por nombre exacto si es unico. */
    public function hogar(mixed $valor, string $campo = 'hogar'): Hogar|Rechazo
    {
        if (is_string($valor) && Str::isUuid($valor)) {
            return Hogar::query()->find($valor) ?? $this->rechazo("No existe un hogar con id {$valor}.");
        }
        if (is_string($valor) && trim($valor) !== '') {
            $encontrados = Hogar::query()->whereRaw('lower(nombre) = ?', [mb_strtolower(trim($valor))])->get();

            return match ($encontrados->count()) {
                1 => $encontrados->first(),
                0 => $this->rechazo('No existe un hogar llamado «'.mb_substr($valor, 0, 60).'».'),
                default => $this->rechazo('Hay varios hogares llamados «'.mb_substr($valor, 0, 60).'»: hay que dar su id.'),
            };
        }

        return $this->rechazo("Falta {$campo} (el id del hogar).");
    }

    /** Alimento por id, o por nombre exacto (el nombre es unico en el catalogo). */
    public function alimento(mixed $valor, string $campo = 'alimento'): Alimento|Rechazo
    {
        if (is_string($valor) && Str::isUuid($valor)) {
            return Alimento::query()->find($valor) ?? $this->rechazo("No existe un alimento con id {$valor}.");
        }
        if (is_string($valor) && trim($valor) !== '') {
            return Alimento::query()->whereRaw('lower(nombre) = ?', [mb_strtolower(trim($valor))])->first()
                ?? $this->rechazo('No existe un alimento llamado «'.mb_substr($valor, 0, 60).'».');
        }

        return $this->rechazo("Falta {$campo} (el id del alimento).");
    }

    /**
     * Un registro por su id uuid.
     *
     * @param  class-string<Model>  $clase
     */
    public function porId(string $clase, mixed $valor, string $campo, string $que): Model|Rechazo
    {
        if (! is_string($valor) || ! Str::isUuid($valor)) {
            return $this->rechazo("{$campo} tiene que ser el id de {$que}.");
        }

        return $clase::query()->find($valor) ?? $this->rechazo("No existe {$que} con id {$valor}.");
    }

    /** Fecha ya pasada o de hoy, dentro de los dias que admite el chat (para registrar lo comido). */
    public function fecha(mixed $valor): CarbonImmutable|Rechazo
    {
        $fecha = $this->fechaReal($valor, 'fecha');
        if ($fecha instanceof Rechazo) {
            return $fecha;
        }
        if ($fecha->gt(CarbonImmutable::today())) {
            return $this->rechazo("La fecha {$valor} es futura: solo se registra lo ya comido.");
        }
        if ($fecha->lt($this->desde())) {
            return $this->rechazo("La fecha {$valor} es demasiado antigua (máximo ".(int) config('chat_claude.acciones.dias_atras').' días).');
        }

        return $fecha;
    }

    /** Fecha de un menu: de hace unas semanas a unos meses vista. */
    public function fechaDeMenu(mixed $valor, string $campo = 'fecha'): CarbonImmutable|Rechazo
    {
        $fecha = $this->fechaReal($valor, $campo);
        if ($fecha instanceof Rechazo) {
            return $fecha;
        }
        if ($fecha->lt(CarbonImmutable::today()->subDays(30)) || $fecha->gt(CarbonImmutable::today()->addDays(180))) {
            return $this->rechazo("{$campo} {$valor} está fuera de rango (de hace 30 días a 180 días vista).");
        }

        return $fecha;
    }

    /** Fecha real AAAA-MM-DD, sin mas limites. */
    public function fechaReal(mixed $valor, string $campo = 'fecha'): CarbonImmutable|Rechazo
    {
        if (! is_string($valor) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
            return $this->rechazo("{$campo} tiene que ser AAAA-MM-DD.");
        }
        $fecha = CarbonImmutable::createFromFormat('!Y-m-d', $valor);
        if ($fecha === false || $fecha->toDateString() !== $valor) {
            return $this->rechazo("La fecha {$valor} no existe.");
        }

        return $fecha;
    }

    /** @param  class-string<\BackedEnum>  $clase */
    public function enum(string $clase, mixed $valor, string $campo = 'momento'): \BackedEnum|Rechazo
    {
        $enum = is_string($valor) || is_int($valor) ? $clase::tryFrom($valor) : null;

        return $enum ?? $this->rechazo("{$campo} tiene que ser uno de: ".implode(', ', array_map(fn ($c): string => (string) $c->value, $clase::cases())).'.');
    }

    public function origen(mixed $valor): OrigenComida|Rechazo
    {
        $origen = is_string($valor) ? OrigenComida::tryFrom($valor) : null;

        return $origen === OrigenComida::Casa || $origen === OrigenComida::Fuera ? $origen : $this->rechazo('origen tiene que ser casa o fuera (lo que sale de una opción del menú lo registra la persona en el panel).');
    }

    /** @return array<int, string>|Rechazo */
    public function categorias(mixed $valor, bool $noVacia = false): array|Rechazo
    {
        $validas = array_map(fn (CategoriaComida $c): string => $c->value, CategoriaComida::cases());
        if (! is_array($valor) || ! array_is_list($valor) || count($valor) > 6 || ($noVacia && $valor === []) || array_diff($valor, $validas) !== [] || count($valor) !== count(array_unique($valor))) {
            return $this->rechazo('categorias tiene que ser una lista ('.($noVacia ? 'de 1 a 6' : 'hasta 6').') de: '.implode(', ', $validas).'.');
        }

        return array_values($valor);
    }

    public function descripcion(mixed $valor): string|Rechazo
    {
        $max = (int) config('chat_claude.acciones.max_descripcion');
        if (! is_string($valor) || ! $this->textoLimpio($valor, 3, $max)) {
            return $this->rechazo("descripcion tiene que ser un texto de 3 a {$max} caracteres.");
        }

        return trim(preg_replace('/\s+/u', ' ', $valor));
    }

    /** Un texto de una linea o mas, de la longitud pedida, sin caracteres de control. Devuelve el texto recortado. */
    public function texto(mixed $valor, string $campo, int $min, int $max): string|Rechazo
    {
        if (! is_string($valor) || ! $this->textoLimpio($valor, $min, $max)) {
            return $this->rechazo("{$campo} tiene que ser un texto de {$min} a {$max} caracteres.");
        }

        return trim($valor);
    }

    /** Entero entre dos limites; null si `$permiteNulo` y viene null. */
    public function entero(mixed $valor, string $campo, int $min, int $max, bool $permiteNulo = false): int|Rechazo|null
    {
        if ($valor === null && $permiteNulo) {
            return null;
        }
        if (! is_int($valor) || $valor < $min || $valor > $max) {
            return $this->rechazo("{$campo} tiene que ser un número entero entre {$min} y {$max}".($permiteNulo ? ' (o null)' : '').'.');
        }

        return $valor;
    }

    /** Numero (entero o decimal) entre dos limites. */
    public function numero(mixed $valor, string $campo, float $min, float $max): float|Rechazo
    {
        if ((! is_int($valor) && ! is_float($valor)) || $valor < $min || $valor > $max) {
            return $this->rechazo("{$campo} tiene que ser un número entre {$min} y {$max}.");
        }

        return (float) $valor;
    }

    /** Texto sin caracteres de control y de la longitud pedida (ya recortado). */
    public function textoLimpio(string $texto, int $min, int $max): bool
    {
        $largo = mb_strlen(trim($texto));

        return $largo >= $min && $largo <= $max && ! preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $texto) && mb_check_encoding($texto, 'UTF-8');
    }

    /** foto_hash: una foto de la persona en esta conversacion (y guardada). Devuelve el hash. */
    public function foto(mixed $valor): string|Rechazo
    {
        $hash = is_string($valor) ? Archivos::normalizarHash($valor) : null;
        if ($hash === null) {
            return $this->rechazo('foto_hash tiene que ser el SHA3-256 (64 caracteres hexadecimales) de una foto de la conversación.');
        }

        return $this->fotoDeLaConversacion($hash) ? $hash : $this->rechazo('foto_hash no es una foto enviada en esta conversación.');
    }

    private function fotoDeLaConversacion(string $hash): bool
    {
        if (! app(Archivos::class)->metadatos($hash)) {
            return false;
        }
        // CAST a texto vale para jsonb; el hash (64 hex, ya validado) es unico, y luego se confirma en PHP.
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
}
