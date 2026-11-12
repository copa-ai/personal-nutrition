<?php

namespace App\Services\Compra;

use App\Enums\CategoriaComida;
use App\Jobs\GenerarIlustraciones;
use App\Models\Auditoria;
use App\Models\CardsDecision;
use App\Models\CardsEvento;
use App\Models\CardsMazo;
use App\Models\Hogar;
use App\Services\Chat\ErrorChat;
use App\Services\Menu\TokenClaude;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;

/**
 * El mazo de la lista de la compra (interfaz «cards») y su estado por instancia en el SQLite del chat. Laravel es la fuente de verdad: el
 * traductor gRPC solo pide el mazo, los eventos con version y envia las decisiones (docs/chat-api-interna.md, «Cards»).
 *
 * - El mazo se GENERA CUANDO LA PERSONA LO PIDE (abre la pantalla de cards o la reconecta: mazo()), con lo que pide el menu activo y lo
 *   que hay en la despensa en ese momento (ListaCompra). Puede usarse tantas veces como se quiera: una compra ya hecha esta en la despensa
 *   y no vuelve a salir. Durante una sesion el mazo no cambia (una tarjeta ya comprada sigue en el mazo con su decision y «comprado» suma
 *   exactamente lo que ponia la tarjeta). Si la pantalla se reconecta mientras la sesion sigue (hay actividad hace menos de
 *   `cards.reanudar_s` y quedan tarjetas por resolver) se reanuda el mismo mazo; si no, se genera uno nuevo.
 * - `deck_id` es unico por mazo generado: las decisiones de un mazo anterior ya no cuentan.
 * - Acciones del mazo: `comprado` (derecha) suma a la despensa, `no_encontrado` (izquierda, keep) deja la tarjeta para el final del mazo.
 *   Boton de tarjeta: `ya_lo_tengo` (resuelve sin sumar). Deshacer revierte el efecto en existencias. Una clave de idempotencia ya vista no repite nada.
 * - Cada cambio es un evento con version (cards_eventos.id, nunca baja). Todo se serializa con un cerrojo de fichero, asi que no hay carreras
 *   entre la web y los workers.
 */
class Mazo
{
    public const COMPRADO = 'comprado';

    public const NO_ENCONTRADO = 'no_encontrado';

    public const YA_LO_TENGO = 'ya_lo_tengo';

    private const PATRON = '/^[A-Za-z0-9_-]{1,64}$/';

    public function __construct(
        private readonly ListaCompra $lista,
        private readonly Despensa $despensa,
        private readonly Ilustraciones $ilustraciones,
    ) {}

    // ------------------------------------------------------------------ API

    /** @return array{version: int, deck: array<string, mixed>} el mazo entero y la version a la que corresponde */
    public function mazo(): array
    {
        return $this->exclusivo(function (): array {
            $this->abrir();

            return ['version' => $this->version(), 'deck' => $this->deck()];
        });
    }

    /**
     * Eventos con version mayor que `$despues`. `reiniciar` = true si el que llama esta tan atras (o tan adelantado) que debe volver a pedir el mazo.
     *
     * @return array{version: int, reiniciar: bool, eventos: list<array<string, mixed>>}
     */
    public function eventos(int $despues): array
    {
        return $this->exclusivo(function () use ($despues): array {
            if (($mazo = CardsMazo::query()->first()) !== null) {
                $this->tocar($mazo);   // un Watch abierto mantiene viva la sesion
            }
            $version = $this->version();
            $primero = CardsEvento::query()->min('id');
            if ($despues > $version || ($primero !== null && $despues > 0 && $despues < (int) $primero - 1)) {
                return ['version' => $version, 'reiniciar' => true, 'eventos' => []];
            }
            $eventos = CardsEvento::query()->where('id', '>', $despues)->orderBy('id')->limit((int) config('cards.max_eventos_leidos'))->get()
                ->map(fn (CardsEvento $e): array => ['version' => (int) $e->id, 'deck_id' => $e->deck_id, 'tipo' => $e->tipo, 'carga' => $e->carga])->all();

            return ['version' => $version, 'reiniciar' => false, 'eventos' => $eventos];
        });
    }

    /**
     * Una decision del usuario (SendAction, cards.md §6.2). Devuelve sin error cuando se ignora (mazo o tarjeta que ya no estan, accion
     * desconocida, clave repetida).
     *
     * @param  array<string, mixed>  $d  deck_id, item_id, action_id, idempotency_key, undo?, acted_at?
     *
     * @throws ErrorChat bad_request si falta un campo o no cumple las reglas
     */
    public function decidir(array $d): void
    {
        foreach (['deck_id', 'item_id', 'action_id', 'idempotency_key'] as $campo) {
            if (! is_string($d[$campo] ?? null) || preg_match(self::PATRON, $d[$campo]) !== 1) {
                throw ErrorChat::badRequest("El campo «{$campo}» falta o no es válido.");
            }
        }
        $deshacer = ($d['undo'] ?? false) === true;
        $cuando = isset($d['acted_at']) && is_int($d['acted_at']) && $d['acted_at'] > 0 ? $d['acted_at'] : null;

        $this->exclusivo(function () use ($d, $deshacer, $cuando): void {
            if (CardsDecision::query()->where('idempotency_key', $d['idempotency_key'])->exists()) {
                return;
            }
            $mazo = CardsMazo::query()->first();
            $fila = $mazo !== null && $mazo->deck_id === $d['deck_id'] ? $this->fila($mazo, $d['item_id']) : null;
            if ($fila === null) {
                return;
            }

            $this->tocar($mazo);
            $antes = $this->orden($mazo);
            if ($deshacer) {
                $this->deshacer($mazo, $fila, $d, $cuando);
            } else {
                $this->hacer($mazo, $fila, $d, $cuando);
            }
            $this->emitirOrden($mazo, $fila['item_id'], $antes);
        });
    }

    /** Pone en las tarjetas la ilustracion que haya ahora (la llama el encargo al agente al terminar): un card_changed por cada una que cambia. */
    public function refrescarImagenes(): void
    {
        $this->exclusivo(function (): void {
            $mazo = CardsMazo::query()->first();
            if ($mazo === null) {
                return;
            }
            $filas = $mazo->tarjetas;
            $imagenes = $this->imagenes($filas);
            $cambiadas = [];
            foreach ($filas as $i => $fila) {
                $nueva = $imagenes[Ilustraciones::clave($fila['nombre'])] ?? null;
                if (($fila['imagen']['hash'] ?? null) !== ($nueva['hash'] ?? null)) {
                    $filas[$i]['imagen'] = $nueva;
                    $cambiadas[] = $filas[$i]['item_id'];
                }
            }
            if ($cambiadas === []) {
                return;
            }
            $mazo->update(['tarjetas' => $filas, 'actualizado_ms' => $this->ahora()]);
            foreach ($cambiadas as $itemId) {
                $this->emitir($mazo->deck_id, 'card_changed', ['card' => $this->tarjeta($mazo, $this->fila($mazo, $itemId))]);
            }
        });
    }

    // ------------------------------------------------------------------ estado

    /**
     * La persona pide el mazo. Sin menu activo con contenido, el mazo queda vacio. Si el mazo guardado sigue en uso se reanuda; si no
     * (otra sesion, terminado o parado hace tiempo) se genera uno nuevo con el menu y la despensa de ahora.
     */
    private function abrir(): void
    {
        $hogar = $this->lista->hogar();
        $id = $this->lista->idMazo($hogar);
        $mazo = CardsMazo::query()->first();

        if ($id === null) {
            if ($mazo !== null) {
                CardsMazo::query()->delete();
            }
            if (CardsEvento::query()->orderByDesc('id')->value('deck_id') !== '') {
                $this->emitir('', 'deck', ['deck' => $this->deckVacio()]);
            }

            return;
        }

        if ($mazo !== null && $this->reanudable($mazo)) {
            $this->tocar($mazo);

            return;
        }

        $filas = $this->filas($hogar);
        CardsMazo::query()->delete();
        $nuevo = 'compra-'.substr(sha1($id.'|'.$this->ahora().'|'.random_int(0, PHP_INT_MAX)), 0, 20);
        CardsMazo::query()->create([
            'deck_id' => $nuevo, 'menu_id' => $hogar?->menu_id, 'titulo' => 'Lista de la compra', 'tarjetas' => $filas,
            'creado_ms' => $this->ahora(), 'actualizado_ms' => $this->ahora(),
        ]);
        $this->emitir($nuevo, 'deck', ['deck' => $this->deck()]);
        $this->podar();
        $this->pedirIlustraciones($filas);
    }

    /** True si la sesion del mazo sigue: hubo actividad hace menos de cards.reanudar_s y queda alguna tarjeta por resolver. */
    private function reanudable(CardsMazo $mazo): bool
    {
        if ($this->ahora() - (int) $mazo->actualizado_ms >= (int) config('cards.reanudar_s') * 1000) {
            return false;
        }
        $decisiones = $this->decisiones($mazo->deck_id);
        foreach ($mazo->tarjetas as $fila) {
            $d = $decisiones[$fila['item_id']] ?? null;
            if ($d === null || $d->action_id === self::NO_ENCONTRADO) {
                return true;
            }
        }

        return false;
    }

    /** Marca actividad en el mazo (como mucho cada 30 s, para no escribir en cada sondeo). */
    private function tocar(CardsMazo $mazo): void
    {
        if ($this->ahora() - (int) $mazo->actualizado_ms > 30000) {
            $mazo->update(['actualizado_ms' => $this->ahora()]);
        }
    }

    /**
     * Las filas de la lista con la imagen de cada una.
     *
     * @return list<array<string, mixed>>
     */
    private function filas(?Hogar $hogar): array
    {
        $filas = $this->lista->calcular($hogar);
        $imagenes = $this->imagenes($filas);
        foreach ($filas as $i => $fila) {
            $filas[$i]['imagen'] = $imagenes[Ilustraciones::clave($fila['nombre'])] ?? null;
        }

        return array_slice($filas, 0, (int) config('cards.max_tarjetas'));
    }

    /**
     * @param  list<array<string, mixed>>  $filas
     * @return array<string, array{hash: string, mime: string, tamano: int}|null>
     */
    private function imagenes(array $filas): array
    {
        return $this->ilustraciones->de(array_map(fn (array $f): array => [
            'nombre' => $f['nombre'], 'categoria' => CategoriaComida::tryFrom((string) $f['categoria']) ?? CategoriaComida::Otro,
        ], $filas));
    }

    /** Encola el encargo de ilustraciones si hay alimentos sin dibujo, el agente esta disponible y no hay otro encargo reciente. */
    private function pedirIlustraciones(array $filas): void
    {
        if (! config('cards.ilustraciones.habilitadas') || $filas === [] || ! TokenClaude::existe()) {
            return;
        }
        if (! GenerarIlustraciones::hayPendientes($filas) || ! Cache::add('cards-ilustrar', 1, 300)) {
            return;
        }
        GenerarIlustraciones::dispatch();
    }

    private function podar(): void
    {
        $limite = $this->ahora() - (int) config('cards.dias_decisiones') * 86400000;
        CardsDecision::query()->where('creado_ms', '<', $limite)->delete();
        $conservar = (int) CardsEvento::query()->max('id') - (int) config('cards.eventos_conservados');
        CardsEvento::query()->where('id', '<=', $conservar)->delete();
    }

    private function version(): int
    {
        return (int) CardsEvento::query()->max('id');
    }

    private function ahora(): int
    {
        return (int) (microtime(true) * 1000);
    }

    /** @param  array<string, mixed>  $carga */
    private function emitir(string $deckId, string $tipo, array $carga): void
    {
        CardsEvento::query()->create(['deck_id' => $deckId, 'tipo' => $tipo, 'carga' => $carga, 'creado_ms' => $this->ahora()]);
    }

    // ------------------------------------------------------------------ decisiones

    /**
     * @param  array<string, mixed>  $fila
     * @param  array<string, mixed>  $d
     */
    private function hacer(CardsMazo $mazo, array $fila, array $d, ?int $cuando): void
    {
        $accion = $d['action_id'];
        if (! in_array($accion, [self::COMPRADO, self::NO_ENCONTRADO, self::YA_LO_TENGO], true)) {
            return;
        }
        $actual = $this->decisiones($mazo->deck_id)[$fila['item_id']] ?? null;
        if ($actual !== null && $actual->action_id !== self::NO_ENCONTRADO) {
            return;   // la tarjeta ya esta resuelta: otra decision no la cambia (se deshace antes)
        }

        $efecto = null;
        if ($accion === self::COMPRADO && ($hogar = $this->lista->hogar()) !== null) {
            $efecto = $this->despensa->sumar($hogar, $fila);
        }
        try {
            CardsDecision::query()->create([
                'deck_id' => $mazo->deck_id, 'item_id' => $fila['item_id'], 'action_id' => $accion, 'idempotency_key' => $d['idempotency_key'],
                'undo' => false, 'acted_ms' => $cuando, 'efecto' => $efecto, 'creado_ms' => $this->ahora(),
            ]);
        } catch (QueryException $e) {
            $efecto !== null && $this->despensa->restar($efecto);

            throw $e;
        }
        Auditoria::registrar('cards.decision', 'Decision en la lista de la compra', ['alimento' => $fila['nombre'], 'accion' => $accion, 'efecto' => $efecto !== null]);
        $this->emitir($mazo->deck_id, 'card_changed', ['card' => $this->tarjeta($mazo, $fila)]);
    }

    /**
     * @param  array<string, mixed>  $fila
     * @param  array<string, mixed>  $d
     */
    private function deshacer(CardsMazo $mazo, array $fila, array $d, ?int $cuando): void
    {
        $ultima = $this->decisiones($mazo->deck_id)[$fila['item_id']] ?? null;
        if ($ultima === null) {
            return;
        }
        if (is_array($ultima->efecto)) {
            $this->despensa->restar($ultima->efecto);
        }
        $ultima->update(['deshecha' => true]);
        CardsDecision::query()->create([
            'deck_id' => $mazo->deck_id, 'item_id' => $fila['item_id'], 'action_id' => $d['action_id'], 'idempotency_key' => $d['idempotency_key'],
            'undo' => true, 'acted_ms' => $cuando, 'efecto' => null, 'creado_ms' => $this->ahora(),
        ]);
        Auditoria::registrar('cards.decision_deshecha', 'Decision deshecha en la lista de la compra', ['alimento' => $fila['nombre'], 'accion' => $ultima->action_id]);
        $this->emitir($mazo->deck_id, 'card_changed', ['card' => $this->tarjeta($mazo, $fila)]);
    }

    /** Si la decision cambio el sitio de la tarjeta (una tarjeta «no lo encuentro» va al final), un card_moved. @param list<string> $antes */
    private function emitirOrden(CardsMazo $mazo, string $itemId, array $antes): void
    {
        $despues = $this->orden($mazo);
        if ($antes === $despues) {
            return;
        }
        $posicion = array_search($itemId, $despues, true);
        $this->emitir($mazo->deck_id, 'card_moved', ['item_id' => $itemId, 'before_item_id' => $despues[$posicion + 1] ?? null]);
    }

    /**
     * La decision actual de cada tarjeta (la ultima no deshecha), por item_id.
     *
     * @return array<string, CardsDecision>
     */
    private function decisiones(string $deckId): array
    {
        $porItem = [];
        foreach (CardsDecision::query()->where('deck_id', $deckId)->where('undo', false)->where('deshecha', false)->orderBy('id')->get() as $d) {
            $porItem[$d->item_id] = $d;
        }

        return $porItem;
    }

    // ------------------------------------------------------------------ forma del mazo (JSON de cards.proto)

    /** @return array<string, mixed>|null */
    private function fila(CardsMazo $mazo, string $itemId): ?array
    {
        foreach ($mazo->tarjetas as $fila) {
            if ($fila['item_id'] === $itemId) {
                return $fila;
            }
        }

        return null;
    }

    /**
     * Orden de las tarjetas: el de la lista, salvo las «no lo encuentro», que van al final por orden de decision.
     *
     * @return list<string>
     */
    private function orden(CardsMazo $mazo): array
    {
        $decisiones = $this->decisiones($mazo->deck_id);
        $normales = [];
        $apartadas = [];
        foreach ($mazo->tarjetas as $fila) {
            $d = $decisiones[$fila['item_id']] ?? null;
            if ($d !== null && $d->action_id === self::NO_ENCONTRADO) {
                $apartadas[(int) $d->id] = $fila['item_id'];
            } else {
                $normales[] = $fila['item_id'];
            }
        }
        ksort($apartadas);

        return [...$normales, ...array_values($apartadas)];
    }

    /** @return array<string, mixed> */
    private function deckVacio(): array
    {
        return ['deck_id' => '', 'actions' => [], 'cards' => [], 'show_progress' => false];
    }

    /** @return array<string, mixed> el Deck actual: acciones, tarjetas en orden y con su decision */
    private function deck(): array
    {
        $mazo = CardsMazo::query()->first();
        if ($mazo === null) {
            return $this->deckVacio();
        }
        $porId = [];
        foreach ($mazo->tarjetas as $fila) {
            $porId[$fila['item_id']] = $fila;
        }
        $decisiones = $this->decisiones($mazo->deck_id);
        $tarjetas = array_map(fn (string $id): array => $this->tarjeta($mazo, $porId[$id], $decisiones), $this->orden($mazo));

        return [
            'deck_id' => $mazo->deck_id,
            'title' => 'Lista de la compra',
            'actions' => [
                ['action_id' => self::NO_ENCONTRADO, 'label' => 'No lo encuentro', 'icon' => 'ICON_LATER', 'tone' => 'TONE_WARNING', 'direction' => 'DIRECTION_LEFT', 'keep' => true, 'sound' => 'SOUND_LATER'],
                ['action_id' => self::COMPRADO, 'label' => 'Comprado', 'icon' => 'ICON_CART', 'tone' => 'TONE_POSITIVE', 'direction' => 'DIRECTION_RIGHT', 'keep' => false, 'sound' => 'SOUND_CONFIRM'],
            ],
            'cards' => $tarjetas,
            'show_progress' => true,
            'end_text' => 'Lista terminada. Lo que has marcado como comprado ya está sumado a la despensa.',
            'done_text' => 'He terminado la lista de la compra.',
        ];
    }

    /**
     * Una tarjeta (cards.proto Card) a partir de su fila de la foto del mazo y su decision.
     *
     * @param  array<string, mixed>  $fila
     * @param  array<string, CardsDecision>|null  $decisiones  las del mazo, si ya se leyeron
     * @return array<string, mixed>
     */
    private function tarjeta(CardsMazo $mazo, array $fila, ?array $decisiones = null): array
    {
        $unidad = (string) $fila['unidad'];
        $falta = $fila['faltante'] === null ? null : (float) $fila['faltante'];
        $texto = $falta === null ? 'Cantidad sin fijar' : 'Faltan '.ListaCompra::formatear($falta, $fila['familia'], $unidad);

        $tarjeta = [
            'item_id' => $fila['item_id'],
            'title' => mb_strcut($fila['nombre'], 0, (int) config('cards.max_titulo_bytes'), 'UTF-8'),
            'text' => mb_strcut($texto, 0, (int) config('cards.max_texto_bytes'), 'UTF-8'),
            'detail' => $this->detalle($fila, $this->topeDetalle(count($mazo->tarjetas))),
            'buttons' => [['action_id' => self::YA_LO_TENGO, 'label' => 'Ya lo tengo', 'icon' => 'ICON_CHECK', 'tone' => 'TONE_NEUTRAL', 'sound' => 'SOUND_CONFIRM']],
            'tone' => 'TONE_NEUTRAL',
            'layout' => ($fila['imagen'] ?? null) !== null ? 'LAYOUT_IMAGE_TOP' : 'LAYOUT_TEXT',
            'group' => mb_strcut(ListaCompra::pasillo((string) $fila['categoria']), 0, (int) config('cards.max_grupo_bytes'), 'UTF-8'),
        ];
        if (($fila['imagen'] ?? null) !== null) {
            $tarjeta['image'] = $fila['imagen'] + ['alt' => 'Ilustración de '.mb_strcut($fila['nombre'], 0, 100, 'UTF-8')];
        }
        $decision = ($decisiones ?? $this->decisiones($mazo->deck_id))[$fila['item_id']] ?? null;
        if ($decision !== null) {
            $tarjeta['decision'] = $decision->action_id;
        }

        return $tarjeta;
    }

    /** Bytes de detalle por tarjeta: los de cards.md, menos si hay tantas tarjetas que el mazo no cabria en 1 MiB. */
    private function topeDetalle(int $tarjetas): int
    {
        return max(512, min((int) config('cards.max_detalle_bytes'), intdiv((int) config('cards.max_bytes_mazo'), max(1, $tarjetas)) - 700));
    }

    /** @param  array<string, mixed>  $fila */
    private function detalle(array $fila, int $tope): string
    {
        $unidad = (string) $fila['unidad'];
        $familia = (string) $fila['familia'];
        $cabecera = [
            'Total necesario: '.($fila['total'] === null ? 'sin cantidad fija' : ListaCompra::formatear((float) $fila['total'], $familia, $unidad)),
            'En la despensa: '.ListaCompra::formatear((float) $fila['disponible'], $familia, $unidad),
            'Falta: '.($fila['faltante'] === null ? 'sin cantidad fija' : ListaCompra::formatear((float) $fila['faltante'], $familia, $unidad)),
            '',
            'Se usa en:',
        ];
        $texto = implode("\n", $cabecera);
        $usos = $fila['usos'];
        foreach ($usos as $i => $uso) {
            $linea = "\n- ".ListaCompra::textoUso($uso);
            $resto = count($usos) - $i;
            $cierre = $resto > 1 ? "\n…y ".($resto - 1).' usos más' : '';
            if (strlen($texto) + strlen($linea) + strlen($cierre) > $tope) {
                $texto .= "\n…y {$resto} usos más";

                break;
            }
            $texto .= $linea;
        }

        return mb_strcut($texto, 0, $tope, 'UTF-8');
    }

    // ------------------------------------------------------------------ cerrojo

    /**
     * Serializa el estado del mazo entre procesos (la web y los workers) con un cerrojo de fichero junto al SQLite del chat.
     *
     * @template T
     *
     * @param  Closure(): T  $trabajo
     * @return T
     */
    private function exclusivo(Closure $trabajo): mixed
    {
        $ruta = dirname((string) config('database.connections.chat.database')).'/cards.lock';
        $manejador = @fopen($ruta, 'c');
        if ($manejador === false) {
            throw ErrorChat::serviceError();
        }
        flock($manejador, LOCK_EX);
        try {
            return $trabajo();
        } finally {
            flock($manejador, LOCK_UN);
            fclose($manejador);
        }
    }
}
