<?php

namespace App\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Quien, desde donde y desde que instancia se hace una escritura. Lo usan dos sitios: `Auditoria::escribir` (los hechos que la
 * aplicacion anota) y la sesion de PostgreSQL, donde los triggers de auditoria (migracion create_auditoria_triggers) leen
 * `nutrition.*` para anotar cada alta, cambio y baja de las tablas de datos.
 */
final class ContextoAuditoria
{
    /** Las consultas que pueden escribir: antes de ellas se actualiza el contexto de la sesion. */
    private const ESCRITURA = '/^\s*(insert|update|delete|with)\b/i';

    /**
     * @return array{ip: ?string, user_agent: ?string, url: ?string, metodo: string}
     */
    public static function peticion(): array
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return ['ip' => null, 'user_agent' => null, 'url' => null, 'metodo' => 'consola'];
        }

        $peticion = request();

        return [
            'ip' => $peticion->ip(),
            'user_agent' => mb_substr((string) $peticion->userAgent(), 0, 500),
            'url' => mb_substr($peticion->fullUrl(), 0, 2000),
            'metodo' => $peticion->method(),
        ];
    }

    /**
     * Deja el contexto en la sesion de PostgreSQL antes de una escritura. Se hace siempre (no se recuerda lo ultimo enviado): una
     * transaccion revertida deshace tambien los ajustes de sesion, y asi nunca queda el usuario de otra peticion.
     * Nunca lanza: un fallo aqui no debe romper la escritura (el movimiento quedaria sin usuario, con metodo «sql»).
     */
    public static function antesDeEscribir(string $consulta, Connection $conexion): void
    {
        if (preg_match(self::ESCRITURA, $consulta) !== 1) {
            return;
        }

        try {
            $usuario = Auth::user();
            $peticion = self::peticion();
            $conexion->getPdo()->prepare(
                "SELECT set_config('nutrition.user_id', ?, false), set_config('nutrition.user_nombre', ?, false), set_config('nutrition.user_email', ?, false),
                        set_config('nutrition.ip', ?, false), set_config('nutrition.user_agent', ?, false), set_config('nutrition.url', ?, false),
                        set_config('nutrition.metodo', ?, false), set_config('nutrition.instancia', ?, false)",
            )->execute([
                self::texto($usuario?->getAuthIdentifier()),
                self::texto($usuario instanceof Authenticatable ? ($usuario->name ?? null) : null),
                self::texto($usuario instanceof Authenticatable ? ($usuario->email ?? null) : null),
                self::texto($peticion['ip']),
                self::texto($peticion['user_agent']),
                self::texto($peticion['url']),
                $peticion['metodo'],
                self::texto(Instancia::id()),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private static function texto(mixed $valor): string
    {
        return $valor === null ? '' : (string) $valor;
    }
}
