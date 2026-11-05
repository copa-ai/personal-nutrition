<?php

namespace App\Http\Middleware;

use App\Models\Ajuste;
use App\Models\Auditoria;
use App\Models\User;
use Closure;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Login automatico del panel (ajuste autologin_activo): quien entra por la web sin sesion queda dentro con el usuario elegido
 * (autologin_usuario_id). La frontera de seguridad es el tunel del nodo, no la contrasena.
 *
 * Solo actua en navegaciones GET del panel. Para poder salir y entrar a mano:
 *  - al cerrar sesion (evento Logout) se deja la cookie de 10 minutos (httpOnly) que lo desactiva, y
 *  - /login?manual=1 lo evita (y deja la misma cookie).
 * Si Auth::login no llega a dejar sesion (navegador sin cookies), una cookie de un minuto corta el bucle de redirecciones.
 * Si el usuario elegido ya no existe, el ajuste se desactiva solo.
 */
class AutoLogin
{
    public const COOKIE_CORTE = 'autologin_off';

    public const COOKIE_INTENTO = 'autologin_intento';

    public const MINUTOS_CORTE = 10;

    private const LIMITE = 'autologin';

    private const MAX_POR_MINUTO = 30;

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->aplicable($request)) {
            return $next($request);
        }

        if ($request->query('manual') !== null) {
            Cookie::queue(Cookie::make(self::COOKIE_CORTE, '1', self::MINUTOS_CORTE, null, null, null, true));

            return $next($request);
        }

        if ($request->cookie(self::COOKIE_CORTE) !== null || $request->cookie(self::COOKIE_INTENTO) !== null) {
            return $next($request);
        }

        // Quien llega sin cookies (un sondeo, un cliente sin sesion) crearia una sesion y una fila de auditoria por peticion: tope global.
        if (RateLimiter::tooManyAttempts(self::LIMITE, self::MAX_POR_MINUTO)) {
            return $next($request);
        }

        $usuario = $this->usuarioElegido();
        if ($usuario === null) {
            return $next($request);
        }
        RateLimiter::hit(self::LIMITE, 60);

        Auth::login($usuario);
        $request->session()->regenerate();
        Auditoria::registrar('login_automatico', 'Inicio de sesión automático de '.$usuario->email, ['usuario' => $usuario->email]);
        Cookie::queue(Cookie::make(self::COOKIE_INTENTO, '1', 1, null, null, null, true));

        return redirect()->to($request->fullUrl());
    }

    /** Evento Logout: la cookie que desactiva el login automatico un rato. */
    public static function alCerrarSesion(Logout $evento): void
    {
        Cookie::queue(Cookie::make(self::COOKIE_CORTE, '1', self::MINUTOS_CORTE, null, null, null, true));
    }

    private function aplicable(Request $request): bool
    {
        if (Auth::check() || ! $this->activo()) {
            return false;
        }
        if (! $request->isMethod('GET') || $request->expectsJson() || $request->hasHeader('X-Livewire')) {
            return false;
        }

        return ! $request->is('interno/*', 'estado', 'estado/*', 'estado.json', 'app.log', 'livewire/*', 'up');
    }

    /** El interruptor; sin tabla todavia (antes de migrar) cuenta como apagado. */
    private function activo(): bool
    {
        try {
            return (bool) Ajuste::obtener(Ajuste::AUTOLOGIN_ACTIVO);
        } catch (QueryException) {
            return false;
        }
    }

    /** El usuario elegido; si se borro, desactiva el ajuste y devuelve null. */
    private function usuarioElegido(): ?User
    {
        $id = Ajuste::obtener(Ajuste::AUTOLOGIN_USUARIO_ID);
        $usuario = $id === null ? null : User::query()->find($id);
        if ($usuario === null) {
            Ajuste::fijar(Ajuste::AUTOLOGIN_ACTIVO, false);
        }

        return $usuario;
    }
}
