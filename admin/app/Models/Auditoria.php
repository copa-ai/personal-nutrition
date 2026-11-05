<?php

namespace App\Models;

use App\Services\ContextoAuditoria;
use App\Services\Instancia;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Throwable;

/**
 * Un movimiento del sistema (acceso, alta, cambio, baja, exportacion...) y quien lo hizo. Inmutable: no se actualiza ni se borra.
 */
class Auditoria extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    /** Texto que sustituye a los valores sensibles. */
    public const MASCARA = '***';

    protected $table = 'auditorias';

    protected $guarded = [];

    /** Si la tabla ya existe (se cachea solo el si, para no consultar en cada evento). */
    private static bool $tablaLista = false;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Los registros de auditoria son inmutables.');
        });
        static::deleting(function (): never {
            throw new LogicException('Los registros de auditoria no se pueden borrar.');
        });
    }

    protected function casts(): array
    {
        return [
            'valores_antiguos' => 'array',
            'valores_nuevos' => 'array',
            'datos' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Registra un movimiento con el usuario actual. Nunca lanza: un fallo de auditoria no debe romper la operacion.
     *
     * @param  array<string, mixed>  $datos
     */
    public static function registrar(string $evento, ?string $descripcion = null, array $datos = []): void
    {
        static::escribir($evento, $descripcion, $datos, Auth::user());
    }

    /**
     * Como registrar(), pero con un usuario explicito. Nunca lanza. Solo para hechos (acceso, exportaciones, chat...): los cambios
     * de las tablas de datos los anotan los triggers de la base.
     *
     * @param  array<string, mixed>  $datos
     */
    public static function escribir(
        string $evento,
        ?string $descripcion = null,
        array $datos = [],
        ?Authenticatable $usuario = null,
    ): void {
        try {
            if (! static::tablaLista()) {
                return;
            }

            $fila = [
                'user_id' => $usuario?->getAuthIdentifier(),
                'user_nombre' => $usuario?->name ?? null,
                'user_email' => $usuario?->email ?? null,
                'evento' => $evento,
                'descripcion' => $descripcion,
                'datos' => $datos === [] ? null : static::enmascarar($datos),
                'instancia' => Instancia::id(),
            ];

            $fila = [...$fila, ...ContextoAuditoria::peticion()];

            static::create($fila);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private static function tablaLista(): bool
    {
        if (! self::$tablaLista) {
            self::$tablaLista = Schema::hasTable('auditorias');
        }

        return self::$tablaLista;
    }

    /** El nombre del campo es de los que nunca se guardan (contrasenas, tokens, claves). */
    public static function esSensible(string $campo): bool
    {
        foreach (['password', 'token', 'secret', 'clave', 'key'] as $trozo) {
            if (stripos($campo, $trozo) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Oculta los campos sensibles (tambien en arrays anidados) y recorta los textos enormes.
     *
     * @param  array<string|int, mixed>  $valores
     * @return array<string|int, mixed>
     */
    public static function enmascarar(array $valores): array
    {
        foreach ($valores as $campo => $valor) {
            if (is_string($campo) && static::esSensible($campo)) {
                $valores[$campo] = self::MASCARA;
            } elseif (is_array($valor)) {
                $valores[$campo] = static::enmascarar($valor);
            } elseif (is_string($valor) && mb_strlen($valor) > 500) {
                $valores[$campo] = mb_substr($valor, 0, 500).'… [truncado]';
            }
        }

        return $valores;
    }

    /**
     * Cambios en forma legible: campo => «antes → despues».
     *
     * @return array<string, string>
     */
    public function diferencias(): array
    {
        $antiguos = $this->valores_antiguos ?? [];
        $nuevos = $this->valores_nuevos ?? [];
        $resultado = [];

        foreach (array_unique([...array_keys($antiguos), ...array_keys($nuevos)]) as $campo) {
            $antes = array_key_exists($campo, $antiguos) ? static::formatear($antiguos[$campo]) : '—';
            $despues = array_key_exists($campo, $nuevos) ? static::formatear($nuevos[$campo]) : '—';
            $resultado[(string) $campo] = $antes.' → '.$despues;
        }

        return $resultado;
    }

    /** @return array<string, string> */
    public function datosLegibles(): array
    {
        return array_map(fn (mixed $v): string => static::formatear($v), $this->datos ?? []);
    }

    public static function formatear(mixed $valor): string
    {
        if ($valor === null) {
            return 'vacío';
        }
        if (is_bool($valor)) {
            return $valor ? 'sí' : 'no';
        }
        if (is_scalar($valor)) {
            return (string) $valor;
        }

        return (string) json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
