"""Sincronizacion de agente/ con un bucket S3 (o compatible: Garage, MinIO, R2...), solo biblioteca estandar.

Es el modo alternativo a compartir agente/ como volumen: si AGENTE_S3_BUCKET esta definido, administracion y agente
intercambian los archivos a traves del bucket (clave = AGENTE_S3_PREFIJO + ruta relativa a agente/). Sin bucket, `activo()`
es False y el runner no hace nada (modo volumen).

Variables: AGENTE_S3_BUCKET, AGENTE_S3_PREFIJO, AGENTE_S3_REGION, AGENTE_S3_ENDPOINT (vacio = AWS),
AGENTE_S3_CLAVE, AGENTE_S3_SECRETO, AGENTE_S3_RUTA_ESTILO (1 = bucket en la ruta, por defecto con endpoint propio).
"""
import datetime
import hashlib
import hmac
import os
import urllib.error
import urllib.request
import xml.etree.ElementTree as ET
from pathlib import Path
from urllib.parse import quote, urlparse

BUCKET = os.environ.get("AGENTE_S3_BUCKET", "")
PREFIJO = os.environ.get("AGENTE_S3_PREFIJO", "").strip("/")
REGION = os.environ.get("AGENTE_S3_REGION", "us-east-1")
ENDPOINT = os.environ.get("AGENTE_S3_ENDPOINT", "").rstrip("/")
CLAVE = os.environ.get("AGENTE_S3_CLAVE", "")
SECRETO = os.environ.get("AGENTE_S3_SECRETO", "")
# Docker Compose pasa las variables sin definir como vacias: vacio = el valor por defecto, no «no».
RUTA_ESTILO = (os.environ.get("AGENTE_S3_RUTA_ESTILO") or ("1" if ENDPOINT else "0")) == "1"


def activo():
    return bool(BUCKET)


def _clave(relativa):
    relativa = relativa.replace("\\", "/").lstrip("/")
    return (PREFIJO + "/" + relativa) if PREFIJO else relativa


def _hmac(llave, texto):
    return hmac.new(llave, texto.encode(), hashlib.sha256).digest()


def _peticion(metodo, clave="", consulta=None, cuerpo=b""):
    """Peticion firmada con AWS Signature V4. Devuelve (codigo, cuerpo)."""
    if ENDPOINT:
        base = urlparse(ENDPOINT)
        esquema, host = base.scheme, base.netloc
    else:
        esquema = "https"
        host = "s3.%s.amazonaws.com" % REGION if RUTA_ESTILO else "%s.s3.%s.amazonaws.com" % (BUCKET, REGION)
    ruta = "/" + (BUCKET + "/" if RUTA_ESTILO else "") + quote(clave, safe="/-_.~")
    consulta = consulta or {}
    q = "&".join("%s=%s" % (quote(k, safe="-_.~"), quote(str(v), safe="-_.~")) for k, v in sorted(consulta.items()))
    ahora = datetime.datetime.now(datetime.timezone.utc)
    fecha, sello = ahora.strftime("%Y%m%d"), ahora.strftime("%Y%m%dT%H%M%SZ")
    hash_cuerpo = hashlib.sha256(cuerpo).hexdigest()
    cabeceras = {"host": host, "x-amz-content-sha256": hash_cuerpo, "x-amz-date": sello}
    firmadas = ";".join(sorted(cabeceras))
    canonica = "\n".join([metodo, ruta, q, "".join("%s:%s\n" % (k, cabeceras[k]) for k in sorted(cabeceras)), firmadas, hash_cuerpo])
    alcance = "%s/%s/s3/aws4_request" % (fecha, REGION)
    a_firmar = "\n".join(["AWS4-HMAC-SHA256", sello, alcance, hashlib.sha256(canonica.encode()).hexdigest()])
    llave = _hmac(_hmac(_hmac(_hmac(("AWS4" + SECRETO).encode(), fecha), REGION), "s3"), "aws4_request")
    firma = hmac.new(llave, a_firmar.encode(), hashlib.sha256).hexdigest()
    cabeceras["Authorization"] = "AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s" % (CLAVE, alcance, firmadas, firma)
    del cabeceras["host"]
    req = urllib.request.Request("%s://%s%s%s" % (esquema, host, ruta, ("?" + q) if q else ""), data=cuerpo or None,
                                 method=metodo, headers=cabeceras)
    try:
        with urllib.request.urlopen(req, timeout=120) as r:
            return r.status, r.read()
    except urllib.error.HTTPError as e:
        return e.code, e.read()


def _exigir(codigo, cuerpo, que):
    if not 200 <= codigo < 300:
        raise RuntimeError("S3 %s fallo (%d): %s" % (que, codigo, cuerpo[:300].decode("utf-8", "replace")))


def listar_con_fecha(relativa):
    """[(clave relativa a agente/, ultima modificacion en segundos desde 1970)] bajo un prefijo."""
    prefijo, salida, token = _clave(relativa.rstrip("/") + "/"), [], None
    while True:
        consulta = {"list-type": 2, "prefix": prefijo}
        if token:
            consulta["continuation-token"] = token
        codigo, cuerpo = _peticion("GET", "", consulta)
        _exigir(codigo, cuerpo, "listar " + relativa)
        raiz = ET.fromstring(cuerpo)
        ns = {"s": raiz.tag.split("}")[0].strip("{")} if "}" in raiz.tag else {}
        p = "s:" if ns else ""
        for c in raiz.findall("%sContents" % p, ns):
            clave = c.findtext("%sKey" % p, namespaces=ns)
            fecha = c.findtext("%sLastModified" % p, namespaces=ns)
            salida.append((clave[len(PREFIJO) + 1:] if PREFIJO else clave, _epoch(fecha)))
        if (raiz.findtext("%sIsTruncated" % p, namespaces=ns) or "false") != "true":
            return salida
        token = raiz.findtext("%sNextContinuationToken" % p, namespaces=ns)


def _epoch(iso):
    """'2026-10-23T14:56:38.000Z' -> segundos desde 1970 (0.0 si no se entiende)."""
    try:
        return datetime.datetime.fromisoformat((iso or "").replace("Z", "+00:00")).timestamp()
    except ValueError:
        return 0.0


def listar(relativa):
    """Claves (relativas a agente/) bajo un prefijo."""
    return [k for k, _ in listar_con_fecha(relativa)]


def subir(carpeta, relativa):
    _exigir(*_peticion("PUT", _clave(relativa), cuerpo=(Path(carpeta) / relativa).read_bytes()), "subir " + relativa)


def bajar(carpeta, relativa):
    """True si existia y se escribio en carpeta/relativa."""
    codigo, cuerpo = _peticion("GET", _clave(relativa))
    if codigo == 404:
        return False
    _exigir(codigo, cuerpo, "bajar " + relativa)
    destino = Path(carpeta) / relativa
    destino.parent.mkdir(parents=True, exist_ok=True)
    destino.write_bytes(cuerpo)
    return True


def bajar_prefijo(carpeta, relativa):
    for k in listar(relativa):
        if not k.endswith("/"):
            bajar(carpeta, k)


def subir_prefijo(carpeta, relativa, desde=0.0, siempre=()):
    """Sube los archivos de carpeta/relativa modificados a partir de `desde` (marca de tiempo), y siempre los que
    acaben en alguno de los sufijos de `siempre` (p. ej. ".sqlite": la base es un unico archivo y no puede quedarse atras)."""
    raiz = Path(carpeta) / relativa
    if not raiz.is_dir():
        return
    for p in sorted(raiz.rglob("*")):
        if p.is_file() and (p.stat().st_mtime >= desde or p.name.endswith(tuple(siempre))):
            subir(carpeta, p.relative_to(carpeta).as_posix())
