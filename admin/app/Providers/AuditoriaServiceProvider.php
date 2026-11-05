<?php

namespace App\Providers;

use App\Models\AgenteArchivo;
use App\Models\Auditoria;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Engancha la auditoria: altas, cambios y bajas de todos los modelos de App\Models (salvo Auditoria) y los eventos de acceso.
 */
class AuditoriaServiceProvider extends ServiceProvider
{
    /** Atributos que nunca se guardan, ni siquiera como mascara: solo se anota su tamano. */
    private const CONTENIDO_OMITIDO = [AgenteArchivo::class => ['contenido']];

    public function boot(): void
    {
        foreach (['created' => 'creado', 'updated' => 'actualizado', 'deleted' => 'eliminado', 'restored' => 'restaurado'] as $accion => $evento) {
            Event::listen("eloquent.{$accion}: *", function (string $nombre, array $datos) use ($evento): void {
                $modelo = $datos[0] ?? null;
                if ($modelo instanceof Model) {
                    $this->auditarModelo($evento, $modelo);
                }
            });
        }

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

    private function auditarModelo(string $evento, Model $modelo): void
    {
        if ($modelo instanceof Auditoria || ! str_starts_with($modelo::class, 'App\\Models\\')) {
            return;
        }

        $antiguos = null;
        $nuevos = null;

        if ($evento === 'actualizado') {
            $cambios = $modelo->getChanges();
            unset($cambios['updated_at']);
            if ($cambios === []) {
                return;
            }
            $nuevos = $this->limpiar($modelo, $cambios);
            $antiguos = $this->limpiar($modelo, array_intersect_key($modelo->getOriginal(), $cambios));
        } elseif ($evento === 'creado') {
            $nuevos = $this->limpiar($modelo, $modelo->getAttributes());
        } else {
            $antiguos = $this->limpiar($modelo, $modelo->getAttributes());
        }

        Auditoria::escribir($evento, null, [], auth()->user(), $modelo, $antiguos, $nuevos);
    }

    /**
     * @param  array<string, mixed>  $atributos
     * @return array<string, mixed>
     */
    private function limpiar(Model $modelo, array $atributos): array
    {
        foreach (self::CONTENIDO_OMITIDO[$modelo::class] ?? [] as $campo) {
            if (array_key_exists($campo, $atributos)) {
                $atributos[$campo] = '[omitido: '.strlen((string) $atributos[$campo]).' bytes]';
            }
        }

        return Auditoria::enmascarar($atributos);
    }
}
