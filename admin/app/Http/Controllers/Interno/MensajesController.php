<?php

namespace App\Http\Controllers\Interno;

use App\Http\Controllers\Controller;
use App\Models\ChatMensaje;
use App\Services\Chat\Conversacion;
use App\Services\Chat\ErrorChat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Rutas /mensajes de la API interna del chat (docs/chat-api-interna.md). */
class MensajesController extends Controller
{
    public function __construct(private readonly Conversacion $conversacion) {}

    public function store(Request $request): JsonResponse
    {
        $mensaje = $this->conversacion->enviar($this->cuerpo($request), strlen($request->getContent()));

        return $this->mensaje($mensaje);
    }

    public function index(Request $request): JsonResponse
    {
        $mensajes = $this->conversacion->leer(
            $this->entero($request, 'despues'), $this->entero($request, 'antes'),
            $this->entero($request, 'limite'), $this->entero($request, 'ultimos'),
        );

        return response()->json(['mensajes' => $mensajes->map(fn (ChatMensaje $m): array => $m->paraApi())->all()]);
    }

    public function show(string $id): JsonResponse
    {
        return $this->mensaje($this->conversacion->obtener($id));
    }

    public function fijar(Request $request, string $id): JsonResponse
    {
        $fijado = $this->cuerpo($request)['fijado'] ?? null;
        if (! is_bool($fijado)) {
            throw ErrorChat::badRequest('Falta «fijado» (booleano).');
        }

        return $this->mensaje($this->conversacion->fijar($id, $fijado));
    }

    public function cancelar(string $id): JsonResponse
    {
        return $this->mensaje($this->conversacion->cancelarPorId($id));
    }

    public function sincronizar(Request $request): JsonResponse
    {
        return response()->json($this->conversacion->sincronizar($this->cuerpo($request)));
    }

    private function mensaje(ChatMensaje $mensaje): JsonResponse
    {
        return response()->json($mensaje->paraApi());
    }

    /** @return array<string, mixed> */
    private function cuerpo(Request $request): array
    {
        $cuerpo = json_decode($request->getContent(), true);

        return is_array($cuerpo) && ! array_is_list($cuerpo) ? $cuerpo : [];
    }

    private function entero(Request $request, string $nombre): int
    {
        $valor = $request->query($nombre);
        if ($valor === null || $valor === '') {
            return 0;
        }
        if (! is_string($valor) || preg_match('/^[0-9]{1,15}$/', $valor) !== 1) {
            throw ErrorChat::badRequest("«{$nombre}» debe ser un entero no negativo.");
        }

        return (int) $valor;
    }
}
