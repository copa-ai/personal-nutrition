<?php

namespace App\Providers;

use App\Models\Auditoria;
use App\Services\ContextoAuditoria;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Engancha la auditoria. Las altas, cambios y bajas de las tablas de datos las anotan triggers de PostgreSQL (migracion
 * create_auditoria_triggers), asi que tambien quedan las que no pasan por Eloquent; aqui solo se les da el contexto (usuario, ip,
 * instancia) y se anotan los eventos de acceso.
 */
class AuditoriaServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Cada conexion nueva (tambien tras reconectar) deja el contexto en la sesion de PostgreSQL antes de cada escritura.
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $evento): void {
            if ($evento->connection->getDriverName() === 'pgsql') {
                $evento->connection->beforeExecuting(
                    fn (string $consulta, array $parametros, $conexion) => ContextoAuditoria::antesDeEscribir($consulta, $conexion),
                );
            }
        });

        Event::listen(Login::class, fn (Login $e) => Auditoria::escribir('login', 'Inicio de sesión', [], $e->user));
        Event::listen(Logout::class, fn (Logout $e) => Auditoria::escribir('logout', 'Cierre de sesión', [], $e->user));
        Event::listen(Failed::class, fn (Failed $e) => Auditoria::escribir(
            'login_fallido',
            'Intento de acceso fallido',
            ['email' => $e->credentials['email'] ?? null],
            $e->user,
        ));
        Event::listen(Lockout::class, fn (Lockout $e) => Auditoria::escribir(
            'bloqueo',
            'Acceso bloqueado por demasiados intentos',
            ['email' => $e->request->input('email')],
        ));
        Event::listen(PasswordReset::class, fn (PasswordReset $e) => Auditoria::escribir('contrasena_restablecida', 'Contraseña restablecida', [], $e->user));
    }
}
