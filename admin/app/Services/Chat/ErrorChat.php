<?php

namespace App\Services\Chat;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Error de la API interna del chat. `codigo` es exactamente el `chat-reason` de chat.md §9 y `estado` el HTTP de la tabla de
 * docs/chat-api-interna.md; el traductor gRPC lo convierte en su estado gRPC.
 */
class ErrorChat extends RuntimeException
{
    public function __construct(public readonly string $codigo, string $mensaje, public readonly int $estado)
    {
        parent::__construct($mensaje);
    }

    public static function badRequest(string $mensaje): self
    {
        return new self('bad_request', $mensaje, 422);
    }

    public static function tooLarge(string $mensaje): self
    {
        return new self('too_large', $mensaje, 413);
    }

    public static function unsupportedMedia(string $mensaje): self
    {
        return new self('unsupported_media', $mensaje, 415);
    }

    public static function hashMismatch(string $mensaje): self
    {
        return new self('hash_mismatch', $mensaje, 422);
    }

    public static function notFound(string $mensaje): self
    {
        return new self('not_found', $mensaje, 404);
    }

    public static function fileMissing(string $mensaje): self
    {
        return new self('file_missing', $mensaje, 409);
    }

    public static function badState(string $mensaje): self
    {
        return new self('bad_state', $mensaje, 409);
    }

    public static function historyFull(): self
    {
        return new self('history_full', 'La conversación está llena. Hay que empezar una nueva.', 507);
    }

    public static function storageFull(string $mensaje): self
    {
        return new self('storage_full', $mensaje, 507);
    }

    public static function busy(): self
    {
        return new self('busy', 'Hay demasiadas respuestas en curso. Espera un momento.', 503);
    }

    public static function serviceError(string $mensaje = 'Error interno del servicio.'): self
    {
        return new self('service_error', $mensaje, 500);
    }

    public static function unimplemented(string $mensaje): self
    {
        return new self('unimplemented', $mensaje, 501);
    }

    public static function deshabilitado(): self
    {
        return new self('deshabilitado', 'El chat está deshabilitado.', 503);
    }

    public function respuesta(): JsonResponse
    {
        return response()->json(['codigo' => $this->codigo, 'mensaje' => $this->getMessage()], $this->estado);
    }
}
