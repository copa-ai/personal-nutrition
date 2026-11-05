"""Cliente HTTP de la API interna del chat (docs/chat-api-interna.md).

Es la unica frontera con Laravel. No registra cuerpos ni cabeceras: los logs
solo llevan metodo, ruta (plantilla) y estado.
"""

from __future__ import annotations

import http.client
import json
import logging
import urllib.parse
from dataclasses import dataclass
from typing import Any

import grpc

log = logging.getLogger("chat.laravel")

TIEMPO_ESPERA = 25.0

# codigo (chat-reason) -> estado gRPC. None en la razon = sin trailer.
ESTADOS = {
    "bad_request": grpc.StatusCode.INVALID_ARGUMENT,
    "too_large": grpc.StatusCode.INVALID_ARGUMENT,
    "unsupported_media": grpc.StatusCode.INVALID_ARGUMENT,
    "hash_mismatch": grpc.StatusCode.INVALID_ARGUMENT,
    "not_found": grpc.StatusCode.NOT_FOUND,
    "file_missing": grpc.StatusCode.FAILED_PRECONDITION,
    "bad_state": grpc.StatusCode.FAILED_PRECONDITION,
    "history_full": grpc.StatusCode.RESOURCE_EXHAUSTED,
    "storage_full": grpc.StatusCode.RESOURCE_EXHAUSTED,
    "busy": grpc.StatusCode.UNAVAILABLE,
    "service_error": grpc.StatusCode.INTERNAL,
    "unimplemented": grpc.StatusCode.UNIMPLEMENTED,
}


class ErrorChat(Exception):
    """Error de protocolo: razon (chat.md, 9) y texto para el usuario.

    `razon` es None para UNAVAILABLE sin chat-reason (instancia no disponible,
    chat deshabilitado). El texto nunca lleva datos de la peticion.
    """

    def __init__(self, razon: str | None, mensaje: str = "") -> None:
        super().__init__(razon or "unavailable")
        self.razon = razon
        self.mensaje = mensaje or "Error del servicio de chat."

    @property
    def estado(self) -> grpc.StatusCode:
        if self.razon is None:
            return grpc.StatusCode.UNAVAILABLE
        return ESTADOS.get(self.razon, grpc.StatusCode.INTERNAL)


def no_disponible(mensaje: str = "Instancia no disponible.") -> ErrorChat:
    return ErrorChat(None, mensaje)


@dataclass
class Respuesta:
    estado: int
    cuerpo: bytes
    tipo: str


class Laravel:
    def __init__(self, url_base: str, secreto: str) -> None:
        partes = urllib.parse.urlsplit(url_base)
        self.host = partes.hostname or "127.0.0.1"
        self.puerto = partes.port or 80
        self.prefijo = partes.path.rstrip("/") + "/interno/chat"
        self._secreto = secreto

    # -- bajo nivel -------------------------------------------------------

    def _peticion(
        self,
        metodo: str,
        ruta: str,
        cuerpo: bytes | None = None,
        cabeceras: dict[str, str] | None = None,
        plantilla: str | None = None,
    ) -> Respuesta:
        enviar = {"Authorization": "Bearer " + self._secreto, "Accept": "application/json"}
        if cabeceras:
            enviar.update(cabeceras)
        conexion = http.client.HTTPConnection(self.host, self.puerto, timeout=TIEMPO_ESPERA)
        try:
            conexion.request(metodo, self.prefijo + ruta, body=cuerpo, headers=enviar)
            resp = conexion.getresponse()
            datos = resp.read()
            estado = resp.status
            tipo = resp.getheader("Content-Type") or ""
        except (OSError, http.client.HTTPException) as exc:
            log.warning("%s %s -> sin conexion (%s)", metodo, plantilla or ruta, type(exc).__name__)
            raise no_disponible() from None
        finally:
            conexion.close()
        log.debug("%s %s -> %d", metodo, plantilla or ruta, estado)
        if estado >= 400:
            raise self._error(estado, datos)
        return Respuesta(estado, datos, tipo)

    @staticmethod
    def _error(estado: int, datos: bytes) -> ErrorChat:
        codigo = None
        mensaje = ""
        try:
            cuerpo = json.loads(datos.decode("utf-8"))
            if isinstance(cuerpo, dict):
                codigo = cuerpo.get("codigo")
                mensaje = cuerpo.get("mensaje") or ""
        except (ValueError, UnicodeDecodeError):
            pass
        if not isinstance(mensaje, str):
            mensaje = ""
        if codigo == "deshabilitado":
            return no_disponible("El chat esta deshabilitado.")
        if isinstance(codigo, str) and codigo in ESTADOS:
            return ErrorChat(codigo, mensaje)
        # 401, 404 de ruta, 5xx sin forma conocida: fallo del servicio.
        log.error("Laravel respondio %d sin codigo conocido", estado)
        return ErrorChat("service_error", "Error interno del servicio.")

    def _json(self, metodo: str, ruta: str, datos: Any = None, plantilla: str | None = None) -> Any:
        cuerpo = None
        cabeceras = None
        if datos is not None:
            cuerpo = json.dumps(datos, ensure_ascii=False).encode("utf-8")
            cabeceras = {"Content-Type": "application/json"}
        resp = self._peticion(metodo, ruta, cuerpo, cabeceras, plantilla)
        try:
            return json.loads(resp.cuerpo.decode("utf-8")) if resp.cuerpo else {}
        except (ValueError, UnicodeDecodeError):
            log.error("%s %s: respuesta que no es JSON", metodo, plantilla or ruta)
            raise ErrorChat("service_error", "Error interno del servicio.") from None

    # -- rutas ------------------------------------------------------------

    def capacidades(self) -> dict:
        return self._json("GET", "/capacidades")

    def conversacion(self) -> dict:
        return self._json("GET", "/conversacion")

    def enviar(self, cuerpo: dict) -> dict:
        return self._json("POST", "/mensajes", cuerpo)

    def mensajes(self, despues: int, antes: int, limite: int, ultimos: int) -> list[dict]:
        consulta = urllib.parse.urlencode(
            {"despues": despues, "antes": antes, "limite": limite, "ultimos": ultimos}
        )
        return list(self._json("GET", "/mensajes?" + consulta, plantilla="/mensajes")["mensajes"])

    def mensaje(self, id_mensaje: str) -> dict:
        return self._json("GET", "/mensajes/" + _q(id_mensaje), plantilla="/mensajes/{id}")

    def fijar(self, id_mensaje: str, fijado: bool) -> dict:
        return self._json(
            "POST", "/mensajes/" + _q(id_mensaje) + "/fijar", {"fijado": fijado},
            plantilla="/mensajes/{id}/fijar",
        )

    def cancelar(self, id_mensaje: str) -> dict:
        return self._json(
            "POST", "/mensajes/" + _q(id_mensaje) + "/cancelar", {},
            plantilla="/mensajes/{id}/cancelar",
        )

    def sincronizar(self, despues: int, abiertos: list[dict], titulo: str | None) -> dict:
        return self._json(
            "POST", "/sincronizar", {"despues": despues, "abiertos": abiertos, "titulo": titulo}
        )

    def consultar_archivos(self, hashes: list[str]) -> list[bool]:
        resp = self._json("POST", "/archivos/consultar", {"hashes": hashes})
        return [bool(r.get("disponible")) for r in resp["resultados"]]

    def subir_archivo(self, hex_hash: str, datos: bytes, mime: str, tamano: int, nombre: str | None) -> dict:
        cabeceras = {
            "Content-Type": "application/octet-stream",
            "X-Mime": mime,
            "X-Tamano": str(tamano),
        }
        if nombre:
            cabeceras["X-Nombre"] = urllib.parse.quote(nombre, safe="")
        resp = self._peticion(
            "PUT", "/archivos/" + hex_hash, datos, cabeceras, plantilla="/archivos/{hex}"
        )
        try:
            return json.loads(resp.cuerpo.decode("utf-8"))
        except (ValueError, UnicodeDecodeError):
            raise ErrorChat("service_error", "Error interno del servicio.") from None

    def descargar_archivo(self, hex_hash: str) -> bytes:
        return self._peticion("GET", "/archivos/" + hex_hash, plantilla="/archivos/{hex}").cuerpo

    def responder_secreto(
        self, id_mensaje: str, id_peticion: str, valor: str | None, omitido: bool
    ) -> None:
        # El valor solo viaja aqui y no se registra en ninguna parte.
        self._json(
            "POST",
            "/secretos",
            {"message_id": id_mensaje, "request_id": id_peticion, "valor": valor, "omitido": omitido},
        )


def _q(texto: str) -> str:
    return urllib.parse.quote(texto, safe="")
