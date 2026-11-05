<?php

namespace App\Services\Chat\Bot;

use App\Enums\MomentoComida;
use App\Models\Ajuste;
use App\Models\ChatArchivo;
use App\Models\ChatMensaje;
use App\Models\ChatPeticionSecreto;
use App\Models\Hogar;
use App\Models\Perfil;
use App\Models\RegistroComida;
use App\Services\Chat\Marcado\MarcadoInvalido;
use App\Services\Chat\Marcado\Mensaje;
use App\Services\Chat\Marcado\Reglas;
use App\Services\Chat\Marcado\Widget;
use App\Services\Chat\Respondedor;
use App\Services\Chat\Secretos;
use App\Services\Menu\TokenClaude;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * El motor de respuesta sin IA: reconoce intenciones en español (Interprete), consulta los datos de la app y contesta con texto,
 * widgets (bloques html) y formularios (bloques keyboard) hechos con las clases de Marcado. Responde completo en un solo paso.
 *
 * Estado que necesita entre mensajes: nada en memoria. La foto sin datos va en el metadato `foto_pendiente` del mensaje del servicio
 * que pide los datos (se retoma si hay uno en los últimos 20 mensajes y ningún registro la usa aún); «ya pedí el token de Claude»
 * se lee de chat_peticiones_secreto.
 */
class RespondedorBot implements Respondedor
{
    /** Cuántos mensajes hacia atrás se busca una foto pendiente. */
    private const VENTANA_FOTO = 20;

    public function responder(ChatMensaje $pregunta, ChatMensaje $respuesta): void
    {
        $contestacion = $this->contestar($pregunta);

        if ($contestacion->metadata !== []) {
            // Antes de escribir: el mensaje sigue abierto y solo cambia esta columna.
            $respuesta->update(['metadata' => $contestacion->metadata]);
        }
        if (! $respuesta->anadirTexto($contestacion->texto)) {
            return;
        }
        if ($contestacion->secreto !== null) {
            $respuesta->pedirSecreto($contestacion->secreto['request_id'], $contestacion->secreto['nombre'], $contestacion->secreto['prosa']);

            return;
        }
        $respuesta->finalizar($contestacion->sugerencias);
    }

    public function contestar(ChatMensaje $pregunta): Respuesta
    {
        $hoy = now()->toImmutable();
        $hogar = Hogar::query()->orderBy('nombre')->first();
        /** @var Collection<int, Perfil> $perfiles */
        $perfiles = $hogar ? $hogar->perfiles()->activos()->orderBy('nombre')->get() : collect();
        $texto = $pregunta->texto();

        $intencion = Interprete::interpretar($texto, $pregunta->imagenes() !== [], $perfiles, $hoy);

        return match ($intencion->tipo) {
            Intencion::SALUDO => $this->saludo($hogar),
            Intencion::GRACIAS => new Respuesta('¡De nada! Aquí estoy para lo que necesites.', ['Menú de hoy', 'Registrar lo que he comido']),
            Intencion::ADIOS => new Respuesta('¡Hasta luego! Cuando quieras, aquí me tienes.'),
            Intencion::AYUDA => $this->ayuda(),
            Intencion::MENU, Intencion::SEMANA => $this->menu($intencion, $hogar, $perfiles, $hoy),
            Intencion::DESPENSA => $this->despensa($hogar, $hoy),
            Intencion::REGISTRAR_ORDEN, Intencion::REGISTRAR => $this->registrar($intencion, $pregunta, $hogar, $perfiles, $hoy),
            Intencion::IA => $this->ia(),
            default => $this->noEntiendo(),
        };
    }

    // ------------------------------------------------------------------ piezas comunes

    /** @param  list<string>  $sugerencias  @return list<string> */
    private function sugerencias(array $sugerencias): array
    {
        return array_slice(array_map(fn (string $s): string => Reglas::cortar($s, 64), $sugerencias), 0, (int) config('chat.max_sugerencias', 3));
    }

    private function acciones(string $introduccion): string
    {
        return Mensaje::nuevo()->texto($introduccion)->teclado(Formularios::acciones())->render();
    }

    private function saludo(?Hogar $hogar): Respuesta
    {
        $casa = $hogar ? ' de '.$hogar->nombre : '';

        return new Respuesta($this->acciones("¡Hola! Soy el asistente de nutrición{$casa}. ¿Qué quieres hacer?"));
    }

    private function ayuda(): Respuesta
    {
        $texto = "Esto es lo que sé hacer, sin necesidad de inteligencia artificial:\n\n"
            ."- **Menú**: «menú de hoy», «qué ceno mañana», «qué tiene que comer Ana el viernes», «menú de la semana».\n"
            ."- **Registrar lo que se ha comido**: «he cenado pasta con tomate», o toca «Registrar lo que he comido». Puedes mandar una foto del plato.\n"
            ."- **Despensa**: «qué hay en la despensa», «qué caduca pronto».\n\n"
            .'Si hay varias personas en casa y no digo de quién hablo, te lo pregunto. Para análisis, recetas o recomendaciones hace falta conectar Claude.';

        return new Respuesta(Mensaje::nuevo()->texto($texto)->teclado(Formularios::acciones())->render());
    }

    private function noEntiendo(): Respuesta
    {
        return new Respuesta(
            $this->acciones('No te he entendido bien. Puedo enseñarte el menú, apuntar lo que se ha comido o mirar la despensa. Elige una opción o escríbemelo de otra forma.'),
            $this->sugerencias(['Menú de hoy', 'Despensa', 'Ayuda']),
        );
    }

    /** Un widget como texto; si no cabe (8000 caracteres) o no es válido, el aviso que se da en su lugar. */
    private function mostrar(Widget $widget, string $siNoCabe): string
    {
        try {
            return $widget->render();
        } catch (MarcadoInvalido) {
            return Mensaje::neutralizar($siNoCabe);
        }
    }

    // ------------------------------------------------------------------ menú

    /** @param  Collection<int, Perfil>  $perfiles */
    private function menu(Intencion $intencion, ?Hogar $hogar, Collection $perfiles, CarbonImmutable $hoy): Respuesta
    {
        $menu = $hogar?->menuActivo;
        if ($hogar === null) {
            return new Respuesta('Todavía no hay ningún hogar configurado. Créalo en el panel de administración y vuelvo a ayudarte.');
        }
        if (! $menu) {
            return new Respuesta('El hogar no tiene un menú activo todavía. Genera uno desde el panel (Menús) y te lo enseño aquí.', $this->sugerencias(['Despensa', 'Ayuda']));
        }
        $dias = $menu->tomas();
        if ($dias === []) {
            return new Respuesta('El menú «'.$menu->nombre.'» aún no tiene contenido: el agente todavía lo está preparando ('.$menu->etiquetaEstado().').', $this->sugerencias(['Despensa', 'Ayuda']));
        }

        if ($intencion->tipo === Intencion::SEMANA) {
            $widget = $this->mostrar(Vistas::semana($menu, $dias), 'El menú es demasiado largo para mostrarlo entero. Pídeme un día concreto.');

            return new Respuesta(Mensaje::nuevo()->texto('Aquí tienes el menú de toda la semana. Elige una toma para verla con sus alternativas.')->render()."\n\n".$widget, $this->sugerencias(['Menú de hoy', 'Menú de mañana']));
        }

        $fecha = $intencion->datos['fecha'] ?? $hoy;
        $diaSemana = $intencion->datos['dia_semana'] ?? null;
        if ($diaSemana !== null) {
            $coincide = collect($dias)->first(fn (array $d): bool => CarbonImmutable::parse($d['fecha'])->dayOfWeekIso === $diaSemana);
            $fecha = $coincide ? CarbonImmutable::parse($coincide['fecha']) : $fecha;
        }
        $iso = $fecha->toDateString();
        $dia = collect($dias)->firstWhere('fecha', $iso);
        $cuando = $this->cuando($fecha, $hoy);
        if ($dia === null) {
            $primero = $dias[0]['fecha'];
            $ultimo = $dias[count($dias) - 1]['fecha'];

            return new Respuesta(
                Mensaje::nuevo()->texto("El menú «{$menu->nombre}» no incluye {$cuando}: cubre del ".CarbonImmutable::parse($primero)->format('d/m/Y').' al '.CarbonImmutable::parse($ultimo)->format('d/m/Y').'.')->render(),
                $this->sugerencias(['Menú de hoy', 'Menú de la semana']),
            );
        }
        /** @var list<string> $momentos */
        $momentos = $intencion->datos['momentos'] ?? [];

        if ($intencion->datos['quien'] === 'preguntar') {
            $dato = $fecha->equalTo($hoy->startOfDay()) ? 'hoy' : $iso;

            return new Respuesta(Mensaje::nuevo()
                ->texto("Hay varias personas en casa. ¿De quién quieres ver el menú de {$cuando}? Elige una, varias o «Todos».")
                ->teclado(Formularios::elegirPerfiles($perfiles, $dato, $momentos))->render());
        }

        $mostrados = $intencion->datos['quien'] === 'algunos'
            ? $perfiles->whereIn('id', $intencion->datos['perfiles'])->values()
            : $perfiles;
        $widget = $this->mostrar(Vistas::dia($menu, $dia, $mostrados, $momentos), 'El menú de ese día es demasiado largo para mostrarlo. Pídeme una sola toma, por ejemplo la cena.');
        $quien = $intencion->datos['quien'] === 'algunos' ? ' de '.$mostrados->pluck('nombre')->join(', ', ' y ') : '';

        return new Respuesta(
            Mensaje::nuevo()->texto("Esto es lo que toca {$cuando}{$quien}:")->render()."\n\n".$widget,
            $this->sugerencias([$fecha->equalTo($hoy->startOfDay()) ? 'Menú de mañana' : 'Menú de hoy', 'Menú de la semana', 'Registrar lo que he comido']),
        );
    }

    private function cuando(CarbonImmutable $fecha, CarbonImmutable $hoy): string
    {
        $dias = (int) $hoy->startOfDay()->diffInDays($fecha->startOfDay(), false);

        return match ($dias) {
            0 => 'hoy', 1 => 'mañana', 2 => 'pasado mañana', -1 => 'ayer',
            default => 'el '.$fecha->locale('es')->isoFormat('dddd D [de] MMMM'),
        };
    }

    // ------------------------------------------------------------------ despensa

    private function despensa(?Hogar $hogar, CarbonImmutable $hoy): Respuesta
    {
        if ($hogar === null) {
            return new Respuesta('Todavía no hay ningún hogar configurado. Créalo en el panel de administración.');
        }
        $widget = $this->mostrar(Vistas::despensa($hogar->existencias()->with('alimento')->get(), $hoy), 'La despensa es demasiado larga para mostrarla entera. Mírala en el panel (Existencias).');

        return new Respuesta(Mensaje::nuevo()->texto('Esto es lo que hay en casa:')->render()."\n\n".$widget, $this->sugerencias(['Menú de hoy', 'Registrar lo que he comido']));
    }

    // ------------------------------------------------------------------ registrar

    /**
     * @param  Collection<int, Perfil>  $perfiles
     */
    private function registrar(Intencion $intencion, ChatMensaje $pregunta, ?Hogar $hogar, Collection $perfiles, CarbonImmutable $hoy): Respuesta
    {
        if ($perfiles->isEmpty()) {
            return new Respuesta('Todavía no hay perfiles activos en la instancia. Créalos en el panel (Perfiles) y podré apuntar lo que come cada uno.');
        }

        $fotos = array_values(array_filter(array_map(fn (array $p): string => (string) ($p['hash'] ?? ''), $pregunta->imagenes())));
        $fotos = array_values(array_filter($fotos, fn (string $h): bool => ChatArchivo::query()->where('hash', $h)->exists()));
        $foto = $fotos[0] ?? $this->fotoPendiente($pregunta);
        $dato = $intencion->datos;

        if ($intencion->tipo === Intencion::REGISTRAR_ORDEN) {
            $etiquetas = Formularios::etiquetas($perfiles);
            $perfil = $this->perfilPorEtiqueta($dato['perfil'], $perfiles, $etiquetas);
            if ($perfil === null) {
                return $this->pedirDatos('No encuentro el perfil «'.$dato['perfil'].'». Elígelo de nuevo:', $perfiles, null, $dato['momento'], $dato['fecha'], $dato['descripcion'], $foto, $hoy);
            }

            return $this->apuntar($perfil, $dato['momento'], $dato['fecha'], $dato['descripcion'], $foto, $hogar, $perfiles, $hoy);
        }

        $completo = $dato['perfil_id'] !== null && $dato['momento'] !== null && $dato['descripcion'] !== '';
        if (($fotos !== [] || $foto !== null) && $completo) {
            $perfil = $perfiles->firstWhere('id', $dato['perfil_id']);
            if ($perfil !== null) {
                $respuesta = $this->apuntar($perfil, $dato['momento'], $dato['fecha'], $dato['descripcion'], $foto, $hogar, $perfiles, $hoy);
                if (count($fotos) > 1) {
                    return new Respuesta($respuesta->texto."\n\nMe has mandado ".count($fotos).' fotos; solo puedo asociar una (la primera) a cada registro.', $respuesta->sugerencias);
                }

                return $respuesta;
            }
        }

        $intro = $fotos !== []
            ? (count($fotos) > 1 ? 'He guardado las fotos (solo asociaré la primera al registro), pero' : 'He guardado la foto, pero').' me faltan datos. Rellena lo que falte y la asocio al registrarlo:'
            : ($dato['con_imagenes'] ?? false ? 'Rellena los datos y lo apunto:' : 'Vamos a apuntarlo. Comprueba los datos y pulsa «Registrar»:');

        return $this->pedirDatos($intro, $perfiles, $dato['perfil_id'], $dato['momento'], $dato['fecha'], $dato['descripcion'], $fotos[0] ?? null, $hoy);
    }

    /**
     * @param  Collection<int, Perfil>  $perfiles
     * @param  array<int, string>  $etiquetas
     */
    private function perfilPorEtiqueta(string $valor, Collection $perfiles, array $etiquetas): ?Perfil
    {
        $norm = Interprete::normalizar($valor);
        $id = collect($etiquetas)->search(fn (string $e): bool => Interprete::normalizar($e) === $norm);
        if ($id === false) {
            $mencionados = Interprete::perfilesMencionados($norm, $perfiles);

            return $mencionados->count() === 1 ? $mencionados->first() : null;
        }

        return $perfiles->firstWhere('id', $id);
    }

    /**
     * Crea el registro y confirma con un widget.
     *
     * @param  Collection<int, Perfil>  $perfiles
     */
    private function apuntar(Perfil $perfil, ?string $momento, CarbonImmutable $fecha, string $descripcion, ?string $foto, ?Hogar $hogar, Collection $perfiles, CarbonImmutable $hoy): Respuesta
    {
        if ($fecha->startOfDay()->greaterThan($hoy->startOfDay())) {
            return $this->pedirDatos('Esa fecha es futura y solo apunto lo que ya se ha comido. Elige otro día:', $perfiles, $perfil->id, $momento, $fecha, $descripcion, $foto, $hoy);
        }
        $registro = RegistroDeComida::registrar($perfil, MomentoComida::from($momento ?? MomentoComida::Otro->value), $fecha, $descripcion, $foto, $hogar?->menuActivo);
        $conFoto = $registro->foto_hash !== null;
        $widget = $this->mostrar(Vistas::registrada($registro, $perfil, $conFoto), 'Comida registrada.');

        return new Respuesta(
            Mensaje::nuevo()->texto('Hecho, lo he apuntado'.($conFoto ? ' con su foto' : '').':')->render()."\n\n".$widget,
            $this->sugerencias(['Registrar otra comida', 'Menú de hoy']),
        );
    }

    /** @param  Collection<int, Perfil>  $perfiles */
    private function pedirDatos(string $introduccion, Collection $perfiles, ?int $perfilId, ?string $momento, CarbonImmutable $fecha, string $descripcion, ?string $foto, CarbonImmutable $hoy): Respuesta
    {
        $texto = Mensaje::nuevo()->texto($introduccion)->teclado(Formularios::registro($perfiles, $perfilId, $momento, $fecha, $descripcion, $hoy))->render();

        return new Respuesta($texto, [], $foto !== null ? ['foto_pendiente' => $foto] : []);
    }

    /** La foto que quedó esperando datos en uno de los últimos mensajes del servicio y que ningún registro usa todavía. */
    private function fotoPendiente(ChatMensaje $pregunta): ?string
    {
        $recientes = ChatMensaje::query()->where('from_service', true)->where('sequence', '<', $pregunta->sequence)
            ->where('sequence', '>=', $pregunta->sequence - self::VENTANA_FOTO)->orderByDesc('sequence')->get();
        foreach ($recientes as $mensaje) {
            $hash = $mensaje->metadata['foto_pendiente'] ?? null;
            if (is_string($hash) && $hash !== '' && ! RegistroComida::query()->where('foto_hash', $hash)->exists()
                && ChatArchivo::query()->where('hash', $hash)->exists()) {
                return $hash;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------ lo que requiere IA

    private function ia(): Respuesta
    {
        $alternativas = $this->acciones('Mientras tanto puedo enseñarte el menú, apuntar lo que se ha comido o mirar la despensa.');

        // Desactivada a proposito (aunque haya token): no se ofrece ni se pide nada.
        if (! Ajuste::claudeChatHabilitado()) {
            return new Respuesta(
                Mensaje::nuevo()->texto('La inteligencia artificial está desactivada en los ajustes del servicio (Chat con Claude), así que respondo sin ella.')->render()."\n\n".$alternativas,
                $this->sugerencias(['Menú de hoy', 'Despensa']),
            );
        }

        if (TokenClaude::existe()) {
            return new Respuesta(
                Mensaje::nuevo()->texto('Eso necesita el asistente con inteligencia artificial y ahora mismo no está disponible. Inténtalo de nuevo dentro de un rato.')->render()."\n\n".$alternativas,
                $this->sugerencias(['Menú de hoy', 'Despensa']),
            );
        }

        $previa = ChatPeticionSecreto::query()->where('nombre', Secretos::NOMBRE_TOKEN_CLAUDE)->orderByDesc('id')->first();
        if ($previa === null) {
            return new Respuesta(
                'Para eso necesito la inteligencia artificial de Claude (análisis libres, recomendaciones, recetas con lo que tengas...), y todavía no está conectada. '
                .'Si quieres activarla, pégame ahora tu token de Claude. Si no, sigo respondiendo sin IA: menús, registros y despensa.',
                [],
                [],
                [
                    'request_id' => 'claude-'.Str::lower(Str::random(16)),
                    'nombre' => Secretos::NOMBRE_TOKEN_CLAUDE,
                    'prosa' => 'Pega aquí tu token de Claude (se genera con «claude setup-token»). Se guarda cifrado en esta instancia y no queda en la conversación. Puedes omitirlo.',
                ],
            );
        }

        $texto = $previa->estado === ChatPeticionSecreto::PENDIENTE
            ? 'Para eso necesito Claude. Antes te pedí el token y sigue pendiente: cuando lo pegues podré responder.'
            : 'Para eso hace falta la inteligencia artificial de Claude y no está conectada. Si cambias de idea, pega el token en el panel de administración (Conexión con Claude) y podré ayudarte.';

        return new Respuesta(Mensaje::nuevo()->texto($texto)->render()."\n\n".$alternativas, $this->sugerencias(['Menú de hoy', 'Despensa']));
    }
}
