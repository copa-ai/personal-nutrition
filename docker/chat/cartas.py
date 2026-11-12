"""CardsService y FileService de cards.md (revision 1) como traductor de la API interna de Laravel (/interno/cards, docs/chat-api-interna.md).

Va en el mismo proceso que el traductor del chat, en otro puerto (CARDS_PUERTO, 50052). Tampoco guarda datos: el mazo, su version y las
decisiones son de Laravel. Watch envia el mazo entero y despues sondea los eventos con version; SendAction reenvia la decision.
"""

from __future__ import annotations

import functools
import logging
import threading
from typing import Any

import cards_pb2 as cp
import cards_pb2_grpc as cp_grpc
import chat_pb2 as pb
import chat_pb2_grpc as pb_grpc
import grpc
from conversion import hash_conocido, hash_pb
from laravel import ErrorChat, Laravel

log = logging.getLogger("chat.cartas")

MIB = 1024 * 1024
TROZO_DESCARGA = 64 * 1024
INTERVALO_SONDEO = 1.0   # segundos entre consultas de eventos nuevos


class LaravelCards(Laravel):
    """Cliente HTTP de /interno/cards: reutiliza el transporte y el manejo de errores del chat con otro prefijo."""

    def __init__(self, url_base: str, secreto: str) -> None:
        super().__init__(url_base, secreto)
        self.prefijo = self.prefijo.removesuffix("/chat") + "/cards"

    def salud(self) -> dict:
        return self._json("GET", "/salud")

    def mazo(self) -> dict:
        return self._json("GET", "/mazo")

    def eventos(self, despues: int) -> dict:
        return self._json("GET", f"/eventos?despues={int(despues)}", plantilla="/eventos")

    def accion(self, cuerpo: dict) -> None:
        self._json("POST", "/acciones", cuerpo)


# -- conversion JSON -> protobuf ---------------------------------------------


def _enum(tipo: Any, nombre: Any, defecto: int = 0) -> int:
    try:
        return tipo.Value(str(nombre))
    except ValueError:
        return defecto


def boton_a_pb(b: dict, clase: Any) -> Any:
    return clase(
        action_id=b["action_id"],
        label=b["label"],
        icon=_enum(cp.Icon, b.get("icon")),
        tone=_enum(cp.Tone, b.get("tone")),
        sound=_enum(cp.Sound, b.get("sound")),
    )


def accion_a_pb(a: dict) -> cp.DeckAction:
    accion = boton_a_pb(a, cp.DeckAction)
    accion.direction = _enum(cp.Direction, a.get("direction"))
    accion.keep = bool(a.get("keep"))
    return accion


def imagen_a_pb(i: dict) -> pb.ImageContent:
    ref = pb.FileReference(hashes=[hash_pb(i["hash"])], mime_type=i.get("mime") or "image/png", size_bytes=int(i.get("tamano") or 0))
    imagen = pb.ImageContent(file=ref)
    if i.get("alt"):
        imagen.alt_text = i["alt"]
    return imagen


def carta_a_pb(c: dict) -> cp.Card:
    carta = cp.Card(
        item_id=c["item_id"],
        title=c["title"],
        buttons=[boton_a_pb(b, cp.CardButton) for b in c.get("buttons") or []],
        tone=_enum(cp.Tone, c.get("tone")),
        layout=_enum(cp.Layout, c.get("layout")),
    )
    for campo in ("text", "detail", "group", "decision"):
        if c.get(campo):
            setattr(carta, campo, c[campo])
    if c.get("image"):
        carta.image.CopyFrom(imagen_a_pb(c["image"]))
    return carta


def mazo_a_pb(d: dict) -> cp.Deck:
    mazo = cp.Deck(
        deck_id=d.get("deck_id") or "",
        actions=[accion_a_pb(a) for a in d.get("actions") or []],
        cards=[carta_a_pb(c) for c in d.get("cards") or []],
        show_progress=bool(d.get("show_progress")),
    )
    for json_campo, campo in (("title", "title"), ("end_text", "end_text"), ("done_text", "done_text")):
        if d.get(json_campo):
            setattr(mazo, campo, d[json_campo])
    return mazo


def evento_a_pb(e: dict) -> cp.DeckEvent | None:
    """Evento de /eventos -> DeckEvent. None si el tipo no se conoce (un evento de una version nueva)."""
    base = {"version": int(e["version"]), "deck_id": e.get("deck_id") or ""}
    tipo = e.get("tipo")
    carga = e.get("carga") or {}
    if tipo == "deck":
        return cp.DeckEvent(**base, deck=mazo_a_pb(carga["deck"]))
    if tipo == "cards_added":
        anadidas = cp.CardsAdded(cards=[carta_a_pb(c) for c in carga.get("cards") or []])
        if carga.get("before_item_id"):
            anadidas.before_item_id = carga["before_item_id"]
        return cp.DeckEvent(**base, cards_added=anadidas)
    if tipo == "cards_removed":
        return cp.DeckEvent(**base, cards_removed=cp.CardsRemoved(item_ids=list(carga.get("item_ids") or [])))
    if tipo == "card_moved":
        movida = cp.CardMoved(item_id=carga["item_id"])
        if carga.get("before_item_id"):
            movida.before_item_id = carga["before_item_id"]
        return cp.DeckEvent(**base, card_moved=movida)
    if tipo == "card_changed":
        return cp.DeckEvent(**base, card_changed=cp.CardChanged(card=carta_a_pb(carga["card"])))
    return None


# -- manejo de errores ------------------------------------------------------


def _abortar(context: grpc.ServicerContext, error: ErrorChat) -> None:
    if error.razon:
        context.set_trailing_metadata((("cards-reason", error.razon),))
    context.abort(error.estado, error.mensaje)


def _fallo_interno(nombre: str, exc: Exception) -> ErrorChat:
    # Solo la clase de la excepcion: su texto podria llevar datos de la peticion.
    log.error("%s: fallo inesperado (%s)", nombre, type(exc).__name__)
    return ErrorChat("service_error", "Error interno del servicio.")


def unaria(f):
    @functools.wraps(f)
    def envuelta(self, request, context):
        try:
            return f(self, request, context)
        except ErrorChat as exc:
            fallo = exc
        except Exception as exc:  # noqa: BLE001
            fallo = _fallo_interno(f.__name__, exc)
        _abortar(context, fallo)

    return envuelta


def flujo(f):
    @functools.wraps(f)
    def envuelta(self, request, context):
        try:
            yield from f(self, request, context)
            return
        except ErrorChat as exc:
            fallo = exc
        except grpc.RpcError:
            return  # el cliente cancelo
        except Exception as exc:  # noqa: BLE001
            fallo = _fallo_interno(f.__name__, exc)
        _abortar(context, fallo)

    return envuelta


# -- servicios --------------------------------------------------------------


class CardsService(cp_grpc.CardsServiceServicer):
    def __init__(self, laravel: LaravelCards, intervalo: float = INTERVALO_SONDEO) -> None:
        self.laravel = laravel
        self.intervalo = intervalo

    @staticmethod
    def _enviable(evento: cp.DeckEvent | None) -> bool:
        if evento is None:
            return False
        if evento.ByteSize() > MIB:
            log.error("Evento de mas de 1 MiB: no se envia")
            return False
        return True

    def _mazo(self) -> tuple[int, cp.DeckEvent]:
        estado = self.laravel.mazo()
        version = int(estado["version"])
        deck = estado["deck"]
        return version, cp.DeckEvent(version=version, deck_id=deck.get("deck_id") or "", deck=mazo_a_pb(deck))

    @flujo
    def Watch(self, request, context):
        parado = threading.Event()
        context.add_callback(parado.set)
        version, evento = self._mazo()
        yield evento
        while not parado.wait(self.intervalo):
            respuesta = self.laravel.eventos(version)
            if respuesta.get("reiniciar"):
                version, evento = self._mazo()
                yield evento
                continue
            for e in respuesta.get("eventos") or []:
                version = max(version, int(e["version"]))
                convertido = evento_a_pb(e)
                if self._enviable(convertido):
                    yield convertido

    @unaria
    def SendAction(self, request, context):
        self.laravel.accion(
            {
                "deck_id": request.deck_id,
                "item_id": request.item_id,
                "action_id": request.action_id,
                "idempotency_key": request.idempotency_key,
                "undo": request.undo,
                "acted_at": request.acted_at,
            }
        )
        return cp.SendActionResponse()


class FileService(pb_grpc.FileServiceServicer):
    """Solo DownloadFile (cards.md §4): QueryFiles y UploadFile quedan UNIMPLEMENTED."""

    def __init__(self, laravel: LaravelCards) -> None:
        self.laravel = laravel

    @flujo
    def DownloadFile(self, request, context):
        hex_hash = hash_conocido(request.hash)
        if hex_hash is None:
            raise ErrorChat("not_found", "El archivo no esta disponible.")
        datos = self.laravel.descargar_archivo(hex_hash)
        for i in range(0, len(datos), TROZO_DESCARGA):
            yield pb.DownloadFileResponse(chunk=datos[i : i + TROZO_DESCARGA])
