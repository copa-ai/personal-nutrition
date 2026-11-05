<?php

namespace App\Providers;

use App\Models\AgenteArchivo;
use App\Models\Ajuste;
use App\Models\Auditoria;
use App\Models\ChatArchivo;
use App\Models\ChatConversacion;
use App\Models\ChatMensaje;
use App\Models\ChatPeticionSecreto;
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
    private const CONTENIDO_OMITIDO = [
        AgenteArchivo::class => ['contenido'],
        ChatMensaje::class => ['contenido', 'razonamiento', 'sugerencias', 'corrector', 'error'],
    ];

    /**
     * Modelos que no se auditan de forma generica: el chat los registra con sus propios hechos (chat.mensaje_recibido,
     * chat.archivo_subido, chat.secreto_respondido) y Ajuste con chat.interruptor_cambiado (su campo «clave» saldria enmascarado).
     */
    private const SILENCIADOS = [ChatArchivo::class, ChatConversacion::class, ChatPeticionSecreto::class, Ajuste::class];

    /** Estados finales de un mensaje del chat: solo entonces se audita su cambio (los deltas lo actualizan muchas veces). */
    private const ESTADOS_FINALES_CHAT = ['COMPLETED', 'FAILED', 'CANCELLED'];

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

        if (in_array($modelo::class, self::SILENCIADOS, true)) {
            return;
        }
        if ($modelo instanceof ChatMensaje && ! $this->mensajeChatAuditable($evento, $modelo)) {
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

    /** Un mensaje del chat solo se audita al pasar a un estado final: ni su alta (ya la recoge el chat) ni los deltas. */
    private function mensajeChatAuditable(string $evento, ChatMensaje $mensaje): bool
    {
        return $evento === 'actualizado' && in_array($mensaje->getChanges()['estado'] ?? null, self::ESTADOS_FINALES_CHAT, true);
    }

    /**
     * @param  array<string, mixed>  $atributos
     * @return array<string, mixed>
     */
    private function limpiar(Model $modelo, array $atributos): array
    {
        foreach (self::CONTENIDO_OMITIDO[$modelo::class] ?? [] as $campo) {
            if (array_key_exists($campo, $atributos)) {
                $valor = $atributos[$campo];
                $atributos[$campo] = '[omitido: '.strlen(is_string($valor) ? $valor : (string) json_encode($valor)).' bytes]';
            }
        }

        return Auditoria::enmascarar($atributos);
    }
}
