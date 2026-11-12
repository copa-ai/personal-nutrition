#!/usr/bin/env python3
"""Quita las tablas chat_* de una copia de seguridad (.sql o .sql.gz) hecha antes de que el chat pasara al disco de la instancia.

Uso: python3 admin/database/quitar-chat-de-copia.py copia.sql.gz > copia-sin-chat.sql
Despues: psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f copia-sin-chat.sql   (sobre una base ya migrada con la version nueva)
"""
import gzip
import re
import sys


def sentencias(texto):
    """Trozos terminados en ';' fuera de literales de texto ('' escapa la comilla; en E'' la barra invertida escapa el siguiente caracter)."""
    inicio, i, n, dentro, escape = 0, 0, len(texto), False, False
    while i < n:
        c = texto[i]
        if dentro:
            if escape and c == '\\':
                i += 1
            elif c == "'":
                if texto[i + 1:i + 2] == "'":
                    i += 1
                else:
                    dentro = False
        elif c == "'":
            dentro = True
            escape = texto[i - 1:i] in ('E', 'e')
        elif c == '-' and texto[i + 1:i + 2] == '-':
            i = texto.find('\n', i)
            if i < 0:
                break
        elif c == ';':
            yield texto[inicio:i + 1]
            inicio = i + 1
        i += 1
    if texto[inicio:].strip():
        yield texto[inicio:]


def sin_comentarios(s):
    return '\n'.join(l for l in s.splitlines() if not l.lstrip().startswith('--')).strip()


def main(ruta):
    abrir = gzip.open if ruta.endswith('.gz') else open
    with abrir(ruta, 'rt', encoding='utf-8') as f:
        texto = f.read()
    quitadas = 0
    for s in sentencias(texto):
        cuerpo = sin_comentarios(s)
        if re.match(r'INSERT INTO "chat_\w+"', cuerpo) or re.match(r'SELECT setval\(.*"chat_\w+"', cuerpo):
            quitadas += 1
            continue
        if cuerpo.startswith('TRUNCATE '):
            s = re.sub(r'"chat_\w+",\s*', '', s)
            s = re.sub(r',\s*"chat_\w+"(?=\s+RESTART)', '', s)
        sys.stdout.write(s)
    print(f'-- chat_*: {quitadas} sentencias quitadas', file=sys.stderr)


if __name__ == '__main__':
    main(sys.argv[1])
