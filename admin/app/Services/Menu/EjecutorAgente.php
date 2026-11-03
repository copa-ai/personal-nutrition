<?php

namespace App\Services\Menu;

use App\Enums\EstadoMenu;
use App\Jobs\GenerarMenu;
use App\Models\Menu;
use App\Services\Agente\AlmacenAgente;
use App\Services\Claude\EjecutorClaude;
use App\Services\Claude\LimiteUso;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Una «tanda» (ciclo) de trabajo del agente (aqui, generar, editar o intercambiar sobre el menú semanal; es una sola tanda):
 *   exportar encargo -> guardarlo en la base -> claude -p (stream; el runner recibe y devuelve los archivos por HTTP) -> bajar resultado
 *   de la base -> importar -> programar la siguiente tanda.
 * Si se agota la ventana de uso del plan («hit your session limit · resets 3:20pm») NO es un fallo:
 * la tanda se reintenta cuando se renueva (LimiteUso).
 *
 * El encargo lo construye Menu::construirEncargo() y la entrega se valida e importa con Menu::importarEntrega().
 * Lo demas (estados, log, almacen del agente, limite de uso, parada pedida, cancelacion) es infraestructura: no tocar sin motivo.
 */
class EjecutorAgente
{
    public static function carpeta(): string
    {
        return rtrim(config('agente.carpeta'), '/\\');
    }

    /** Ruta, relativa a agente/, donde el agente deja la entrega. */
    public static function salidaRelativa(Menu $e): string
    {
        return "workspace/resultado-{$e->id}.json";
    }

    /** Skill que atiende cada tarea del menú (`menus.tarea`). */
    public static function skill(Menu $e): string
    {
        return match ($e->tarea) {
            'editar' => 'editar-menu',
            'intercambiar' => 'intercambiar-menu',
            default => 'planificar-menu',
        };
    }

    /**
     * Texto que recibe `claude -p`. No lleva datos: el agente lee su encargo de encargos/encargo-{id}.json.
     * Es el mismo en cada intento (p. ej. tras agotarse el limite de uso): una tanda siempre rehace el trabajo entero.
     * Solo cambia la skill segun la tarea; lo que hay que hacer (y el comentario o lo comido) va en el encargo.
     */
    public static function prompt(Menu $e, int $ciclo = 1): string
    {
        return "Realiza el trabajo {$e->id}. Usa la skill ".static::skill($e)." en modo encargo (ciclo {$ciclo}): lee encargos/encargo-{$e->id}.json, no hagas preguntas, "
            .'y deja el resultado exportado donde dice el campo «salida» de ese archivo.';
    }

    public static function intervaloMinutos(Menu $e): int
    {
        return max(0, (int) ($e->intervalo_ciclos_min ?? config('agente.ciclos.intervalo_min')));
    }

    public function ejecutar(Menu $e, ?callable $salida = null, ?Process $proceso = null): Menu
    {
        $carpeta = static::carpeta();
        $log = $carpeta."/workspace/encargo-{$e->id}.log";
        $ciclo = $e->ciclo + 1;
        try {
            $e->update(['estado' => 'en_marcha', 'terminado_en' => null, 'error' => null, 'ciclo' => $ciclo, 'proximo_ciclo_en' => null,
                'archivo_log' => $log, 'archivo_resultado' => $carpeta.'/'.static::salidaRelativa($e)]
                + ($ciclo === 1 ? ['iniciado_en' => now()] : []));

            // 1) Encargo: se construye con los datos de ahora (si falta algo, lanza y el menú queda fallido con el motivo),
            //    se guarda en el menú para validar la entrega y se escribe en agente/encargos/ y en la base (AlmacenAgente): el runner lo recibe al lanzar.
            //    En el menú no se guarda lo voluminoso de editar e intercambiar: la validación no lo necesita.
            $encargo = $e->construirEncargo();
            $e->update(['encargo' => Arr::except($encargo, ['contenido_actual', 'menu_activo'])]);
            $archivoEncargo = $carpeta."/encargos/encargo-{$e->id}.json";
            @mkdir(dirname($archivoEncargo), 0775, true);
            file_put_contents($archivoEncargo, json_encode(
                ['formato' => 'encargo-menu', 'version' => 3, 'encargo_id' => $e->id, 'salida' => static::salidaRelativa($e)] + $encargo,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE,
            ));
            @unlink($e->archivo_resultado);                       // asi se sabe si ESTA tanda dejo su entrega
            AlmacenAgente::borrar(static::salidaRelativa($e));
            AlmacenAgente::subir($carpeta, 'encargos/encargo-'.$e->id.'.json');
            @mkdir(dirname($log), 0775, true);
            // Una tanda posterior puede atenderla otro dispositivo: el registro de las anteriores esta en la base, no en su disco.
            $ciclo === 1 ? @unlink($log) : AlmacenAgente::bajar($carpeta, AlmacenAgente::rutaRegistro($e->id));
            $ciclo === 1 || file_put_contents($log, "\n===== Ciclo {$ciclo} · ".now()->toDateTimeString()." =====\n", FILE_APPEND);

            // 2) Ejecucion. stream:true => el log muestra cada paso del agente (herramientas, textos) y no solo la respuesta final.
            $cola = '';
            $subidoEn = 0.0;
            $guardarRegistro = function (bool $forzar = false) use ($carpeta, $e, &$subidoEn) {
                if (AlmacenAgente::activo() && ($forzar || microtime(true) - $subidoEn >= 3)) {   // el panel de cualquier dispositivo lo lee de la base
                    $subidoEn = microtime(true);
                    AlmacenAgente::subir($carpeta, AlmacenAgente::rutaRegistro($e->id));
                }
            };
            $r = (new EjecutorClaude)->ejecutar(
                static::prompt($e, $ciclo), config('agente.herramientas'), (float) config('agente.timeout'),
                function (string $tipo, string $texto) use ($log, $salida, &$cola) {
                    $cola = mb_substr($cola.$texto, -4000);       // la cola basta para ver el aviso de limite
                    file_put_contents($log, $texto, FILE_APPEND);
                    $guardarRegistro();
                    $salida && $salida($texto);
                },
                fn (?int $pid) => $e->update(['proceso_pid' => $pid]),          // solo si claude corre en este proceso
                $proceso,
                stream: true,
                alAlta: fn (string $id) => $e->update(['ejecucion_id' => $id]), // id remoto: para cancelar si se fuerza la parada
            );

            $guardarRegistro(true);

            // 3) Almacen en la base: EjecutorClaude guardo lo que devolvio el agente; el resultado se trae al disco.
            if (AlmacenAgente::activo() && $r->exitoso()) {
                AlmacenAgente::bajar($carpeta, static::salidaRelativa($e));
            }
            $entregado = is_file($e->archivo_resultado);

            // 4) Limite de uso del plan: no es un fallo, se espera a que se renueve.
            if (! $entregado && ($renovacion = LimiteUso::renovacion($cola.$r->errores.$r->salida, null, static::intervaloMinutos($e) ?: 60)) !== null) {
                return $this->esperar($e, $renovacion->addMinutes((int) config('agente.ciclos.margen_limite_min')),
                    'Se agotó la ventana de uso de Claude; la tanda se reintenta cuando se renueve.');
            }
            if (! $r->exitoso()) {
                throw new InvalidArgumentException('La ejecución no ha podido completarse (código '.$r->codigo.'). Detalle técnico: '.$log);
            }
            if (! $entregado) {
                // El agente puede acabar «bien» negandose a seguir: su ultimo mensaje dice por que.
                $motivo = trim($r->salida);
                throw new InvalidArgumentException('La tanda terminó pero no se generó el resultado.'
                    .($motivo !== '' ? ' Último mensaje del agente: '.Str::limit($motivo, 1500, '…') : ''));
            }

            // 5) Importar: valida la entrega contra el encargo y la guarda (con el consumo por respuesta) en una transaccion.
            $datos = json_decode(file_get_contents($e->archivo_resultado), true);
            if (! is_array($datos)) {
                throw new InvalidArgumentException('El resultado del agente no es un JSON válido: '.$e->archivo_resultado);
            }
            $e->importarEntrega($datos);
            $e->update(['proceso_pid' => null, 'ejecucion_id' => null]);

            // 6) ¿Fin o otra tanda? Con agente.ciclos.max = 1 siempre termina aqui; el criterio de «completo» lo declara el agente.
            $completo = (bool) ($datos['entrega']['completo'] ?? true);
            if ($completo || $ciclo >= (int) config('agente.ciclos.max')) {
                $e->update(['estado' => 'terminado', 'terminado_en' => now(), 'detener_pedido' => false]);
                $e->avisarResultado();
                $e->tarea === 'intercambiar' && $e->lanzarPendiente();
            } else {
                $this->esperar($e, now()->addMinutes(static::intervaloMinutos($e)));
            }
        } catch (Throwable $ex) {
            // Si se forzo la parada mientras corria, el trabajo ya esta cerrado: no se pisa con «fallido».
            if ($e->refresh()->estado === EstadoMenu::EnMarcha) {
                $e->cerrarConError($ex->getMessage());   // generar: fallido. editar/intercambiar: el menú sigue vigente, queda terminado con el motivo
            }
        }

        return $e->refresh();
    }

    /** Deja el trabajo en espera hasta $cuando y programa su siguiente tanda (un job con delay). */
    private function esperar(Menu $e, CarbonInterface $cuando, ?string $aviso = null): Menu
    {
        // Si mientras corria la tanda se pidio detener, aqui termina: no se programa otra.
        if ($e->refresh()->detener_pedido) {
            $e->update(['estado' => 'terminado', 'terminado_en' => now(), 'proximo_ciclo_en' => null, 'proceso_pid' => null, 'ejecucion_id' => null,
                'detener_pedido' => false, 'error' => null]);

            return $e->refresh();
        }
        $e->update(['estado' => 'en_espera', 'proximo_ciclo_en' => $cuando, 'proceso_pid' => null, 'ejecucion_id' => null, 'error' => $aviso]);
        GenerarMenu::dispatch($e->id)->delay($cuando);

        return $e->refresh();
    }
}
