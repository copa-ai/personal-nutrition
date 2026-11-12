"""Conversion entre los mensajes protobuf de chat.proto y las formas JSON de
docs/chat-api-interna.md. Solo decide lo que es del cable (chat.md, 6.4 y 5.1).
"""

from __future__ import annotations

import hashlib
import re
import time
from typing import Any

import chat_pb2 as pb
from laravel import ErrorChat

# Hash.type de SHA3-256: el digest de la entrada vacia, 32 bytes crudos.
SHA3_TIPO: bytes = hashlib.sha3_256(b"").digest()
SHA3_TIPO_HEX = "a7ffc6f8bf1ed76651c14756a061d662f580ff4de43b49fa82d80a4b80f8434a"
assert SHA3_TIPO.hex() == SHA3_TIPO_HEX

# chat.md 5.7: hasta 32 correcciones. El resto se descarta aqui para que un corrector enorme no haga pasar la peticion del limite de 128 KiB.
MAX_CORRECCIONES = 32

ID_EVENTO = re.compile(r"\A[A-Za-z0-9_-]{1,64}\Z")

ESTADO_A_PB = {
    "PENDING": pb.MESSAGE_STATUS_PENDING,
    "IN_PROGRESS": pb.MESSAGE_STATUS_IN_PROGRESS,
    "COMPLETED": pb.MESSAGE_STATUS_COMPLETED,
    "FAILED": pb.MESSAGE_STATUS_FAILED,
    "CANCELLED": pb.MESSAGE_STATUS_CANCELLED,
}
ABIERTOS = ("PENDING", "IN_PROGRESS")


def ahora_ms() -> int:
    return int(time.time() * 1000)


def mala_peticion(mensaje: str = "Peticion no valida.") -> ErrorChat:
    return ErrorChat("bad_request", mensaje)


# -- hashes ---------------------------------------------------------------


def hash_conocido(h: pb.Hash) -> str | None:
    """Hex del hash si es SHA3-256; None si el algoritmo es desconocido.

    Un type vacio o un value de longitud incorrecta es bad_request.
    """
    if len(h.type) == 0:
        raise mala_peticion("Falta el tipo de hash.")
    if h.type != SHA3_TIPO:
        return None
    if len(h.value) != 32:
        raise mala_peticion("El hash SHA3-256 debe medir 32 bytes.")
    return h.value.hex()


def hash_pb(hex_hash: str) -> pb.Hash:
    return pb.Hash(type=SHA3_TIPO, value=bytes.fromhex(hex_hash))


def hash_de_referencia(ref: pb.FileReference) -> str:
    """Hex del primer hash SHA3-256 de la referencia; se descartan los de tipo desconocido."""
    conocidos: list[str] = []
    for h in ref.hashes:
        hex_hash = hash_conocido(h)
        if hex_hash is not None:
            conocidos.append(hex_hash)
    if not conocidos:
        raise mala_peticion("La referencia no tiene ningun hash conocido.")
    if any(c != conocidos[0] for c in conocidos):
        raise mala_peticion("Los hashes de la referencia no coinciden.")
    return conocidos[0]


# -- partes ---------------------------------------------------------------


def _opcional(msg: Any, campo: str) -> Any:
    if msg.HasField(campo):
        valor = getattr(msg, campo)
        return valor if valor != "" else None
    return None


def _referencia_json(ref: pb.FileReference) -> dict:
    return {
        "hash": hash_de_referencia(ref),
        "mime": ref.mime_type if ref.HasField("mime_type") else "",
        "tamano": ref.size_bytes if ref.HasField("size_bytes") else 0,
        "nombre": _opcional(ref, "filename"),
    }


def partes_a_json(contenido: Any) -> list[dict]:
    """ContentPart[] del cliente -> Parte[] JSON. Solo texto, imagen y audio."""
    partes: list[dict] = []
    for parte in contenido:
        tipo = parte.WhichOneof("kind")
        if tipo == "text":
            partes.append({"tipo": "texto", "texto": parte.text.text})
        elif tipo == "image":
            d = {"tipo": "imagen", **_referencia_json(parte.image.file)}
            d["alt"] = _opcional(parte.image, "alt_text")
            partes.append(d)
        elif tipo == "audio":
            d = {"tipo": "audio", **_referencia_json(parte.audio.file)}
            d["transcripcion"] = _opcional(parte.audio, "transcript")
            d["duracion_ms"] = parte.audio.duration_ms if parte.audio.HasField("duration_ms") else None
            partes.append(d)
        else:
            # Sin kind, o un kind que un cliente de la revision 6 no envia.
            raise mala_peticion("Parte de contenido no valida.")
    return partes


def cuerpo_envio(req: pb.SendMessageRequest) -> dict:
    """SendMessageRequest -> cuerpo de POST /mensajes. No valida lo que valida Laravel."""
    if req.HasField("conversation_id") and req.conversation_id != "":
        raise ErrorChat("not_found", "La conversacion no existe.")
    corrector = None
    if req.HasField("corrector"):
        corrector = {
            "enabled": req.corrector.enabled,
            "corrections": [
                {"original": c.original, "replacement": c.replacement}
                for c in req.corrector.corrections[:MAX_CORRECCIONES]
            ],
        }
    return {
        "content": partes_a_json(req.content),
        "reply_to": _opcional(req, "reply_to_message_id"),
        "idempotency_key": _opcional(req, "idempotency_key"),
        "corrector": corrector,
    }


def exigir_principal(conversation_id: str | None) -> None:
    """Solo existe la conversacion principal (supports_fork = false)."""
    if conversation_id:
        raise ErrorChat("not_found", "La conversacion no existe.")


# -- mensajes -------------------------------------------------------------


def _referencia_pb(parte: dict) -> pb.FileReference:
    ref = pb.FileReference(
        hashes=[hash_pb(parte["hash"])],
        mime_type=parte.get("mime") or "",
        size_bytes=int(parte.get("tamano") or 0),
    )
    if parte.get("nombre"):
        ref.filename = parte["nombre"]
    return ref


def parte_a_pb(parte: dict) -> pb.ContentPart | None:
    tipo = parte.get("tipo")
    if tipo == "texto":
        return pb.ContentPart(text=pb.TextContent(text=parte.get("texto") or ""))
    if tipo == "imagen":
        imagen = pb.ImageContent(file=_referencia_pb(parte))
        if parte.get("alt"):
            imagen.alt_text = parte["alt"]
        return pb.ContentPart(image=imagen)
    if tipo == "audio":
        audio = pb.AudioContent(file=_referencia_pb(parte))
        if parte.get("transcripcion"):
            audio.transcript = parte["transcripcion"]
        if parte.get("duracion_ms") is not None:
            audio.duration_ms = int(parte["duracion_ms"])
        return pb.ContentPart(audio=audio)
    if tipo == "archivo":
        return pb.ContentPart(file=_referencia_pb(parte))
    if tipo == "peticion_secreto":
        peticion = pb.SecretRequest(request_id=parte["request_id"], name=parte["nombre"])
        if parte.get("prosa"):
            peticion.prose = parte["prosa"]
        return pb.ContentPart(secret_request=peticion)
    if tipo == "slot_link":
        enlace = pb.SlotLink(port=int(parte["port"]))
        if parte.get("label"):
            enlace.label = parte["label"]
        return pb.ContentPart(slot_link=enlace)
    return None  # parte de una revision nueva: se ignora


def mensaje_a_pb(d: dict) -> pb.Message:
    msg = pb.Message(
        message_id=d["message_id"],
        sequence=int(d["sequence"]),
        status=ESTADO_A_PB.get(d.get("status"), pb.MESSAGE_STATUS_UNSPECIFIED),
        created_at=int(d.get("created_ms") or 0),
        updated_at=int(d.get("updated_ms") or 0),
        pinned=bool(d.get("pinned")),
        from_service=bool(d.get("from_service")),
        reasoning=d.get("reasoning") or "",
    )
    for parte in d.get("content") or []:
        convertida = parte_a_pb(parte)
        if convertida is not None:
            msg.content.append(convertida)
    if d.get("conversation_id"):
        msg.conversation_id = d["conversation_id"]
    if d.get("reply_to"):
        msg.reply_to_message_id = d["reply_to"]
    for clave, valor in (d.get("metadata") or {}).items():
        msg.metadata[str(clave)] = "" if valor is None else str(valor)
    if d.get("idempotency_key"):
        msg.idempotency_key = d["idempotency_key"]
    if d.get("error"):
        msg.error = d["error"]
    msg.suggestions.extend(d.get("suggestions") or [])
    corrector = d.get("corrector")
    if corrector:
        estado = pb.CorrectorState(enabled=bool(corrector.get("enabled")))
        for c in corrector.get("corrections") or []:
            estado.corrections.append(pb.Correction(original=c["original"], replacement=c["replacement"]))
        msg.corrector.CopyFrom(estado)
    return msg


def longitudes(d: dict) -> tuple[int, int]:
    """Bytes UTF-8 del texto y del razonamiento de un Mensaje JSON."""
    texto = "".join(p.get("texto") or "" for p in d.get("content") or [] if p.get("tipo") == "texto")
    return len(texto.encode("utf-8")), len((d.get("reasoning") or "").encode("utf-8"))
