#!/usr/bin/env python3
"""TCP forwarder for the SSH slot of nutrition (port 2222).

Nutrition never reads the SSH bytes. For each connection, the forwarder reads the target
{"host": "...", "port": N} from the destination file, opens a TCP connection to the target
and copies bytes in both directions.

Rules:
  - No destination file, or a bad file: close the connection at once.
  - The destination file is read again for each connection and once per second.
    If the file disappears or the target changes, the open pipes of the old target close.
  - The number of open pipes has a limit. A connection over the limit closes at once.
  - A pipe with no traffic in both directions closes after the idle time.
  - The forwarder never logs bytes. It logs one line per event.

Only the Python standard library is used. Settings (argument, then environment, then default):
  --port / SSH_PROXY_PORT          2222
  --bind / SSH_PROXY_BIND          0.0.0.0
  --destino / SSH_PROXY_DESTINO    /var/lib/nutrition/coder/destino.json
  --max / SSH_PROXY_MAX            16 concurrent pipes
  --idle / SSH_PROXY_IDLE          14400 seconds without traffic
  --connect-timeout / SSH_PROXY_CONNECT_TIMEOUT  10 seconds
  --poll / SSH_PROXY_POLL          1 second
"""
import argparse
import asyncio
import ipaddress
import json
import logging
import os
import re
import signal
import sys
import time

LOG = logging.getLogger("ssh-proxy")

MAX_FILE_BYTES = 4096
CHUNK = 65536
HOST_RE = re.compile(r"^[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$")


def validate_target(data):
    """Return (host, port) for a decoded destination, or None if it is not valid."""
    if not isinstance(data, dict):
        return None
    host, port = data.get("host"), data.get("port")
    if not isinstance(host, str) or isinstance(port, bool) or not isinstance(port, int):
        return None
    if not 1 <= port <= 65535:
        return None
    try:
        ipaddress.ip_address(host)
    except ValueError:
        if not HOST_RE.fullmatch(host) or ".." in host:
            return None
    return (host, port)


def read_target(path):
    """Read the destination file. Return (host, port), or None with no file or a bad file."""
    try:
        with open(path, "rb") as handle:
            raw = handle.read(MAX_FILE_BYTES + 1)
    except OSError:
        return None
    if len(raw) > MAX_FILE_BYTES:
        return None
    try:
        return validate_target(json.loads(raw.decode("utf-8")))
    except (ValueError, UnicodeDecodeError):
        return None


class Pipe:
    """One client connection joined to one target connection."""

    def __init__(self, target, client_writer, target_writer):
        self.target = target
        self.writers = (client_writer, target_writer)
        self.tasks = []
        self.last = time.monotonic()

    def abort(self):
        for task in self.tasks:
            task.cancel()
        for writer in self.writers:
            try:
                writer.close()
            except Exception:
                pass


class Proxy:
    def __init__(self, destino, max_pipes=16, idle=14400.0, connect_timeout=10.0, poll=1.0):
        self.destino = destino
        self.max_pipes = max_pipes
        self.idle = idle
        self.connect_timeout = connect_timeout
        self.poll = poll
        self.pipes = set()
        self.server = None
        self._watcher = None

    async def start(self, bind, port):
        self.server = await asyncio.start_server(self.handle, bind, port)
        self._watcher = asyncio.ensure_future(self.watch())
        return self.server.sockets[0].getsockname()[1]

    async def stop(self):
        if self._watcher:
            self._watcher.cancel()
        if self.server:
            self.server.close()
        for pipe in list(self.pipes):
            pipe.abort()
        await asyncio.sleep(0)

    async def watch(self):
        """Close the pipes whose target is not the current target."""
        while True:
            await asyncio.sleep(self.poll)
            current = read_target(self.destino)
            for pipe in list(self.pipes):
                if pipe.target != current:
                    LOG.info("destination changed or removed: closing a pipe to %s:%s", *pipe.target)
                    pipe.abort()

    @staticmethod
    def _close(writer):
        try:
            writer.close()
        except Exception:
            pass

    async def handle(self, reader, writer):
        if len(self.pipes) >= self.max_pipes:
            LOG.warning("refused a connection: limit of %d pipes", self.max_pipes)
            self._close(writer)
            return
        target = read_target(self.destino)
        if target is None:
            LOG.info("refused a connection: no valid destination file")
            self._close(writer)
            return
        try:
            t_reader, t_writer = await asyncio.wait_for(
                asyncio.open_connection(*target), self.connect_timeout)
        except (OSError, asyncio.TimeoutError):
            LOG.warning("refused a connection: cannot connect to %s:%s", *target)
            self._close(writer)
            return
        pipe = Pipe(target, writer, t_writer)
        # The pipe is in the set before any await: the watcher can abort it at once.
        self.pipes.add(pipe)
        LOG.info("pipe open to %s:%s (%d open)", *target, len(self.pipes))
        pipe.tasks = [asyncio.ensure_future(self._pump(reader, t_writer, pipe)),
                      asyncio.ensure_future(self._pump(t_reader, writer, pipe))]
        try:
            await asyncio.wait(pipe.tasks, return_when=asyncio.FIRST_COMPLETED)
        finally:
            pipe.abort()
            self.pipes.discard(pipe)
            LOG.info("pipe closed to %s:%s (%d open)", *target, len(self.pipes))

    async def _pump(self, reader, writer, pipe):
        try:
            while True:
                try:
                    data = await asyncio.wait_for(reader.read(CHUNK), self.idle)
                except asyncio.TimeoutError:
                    if time.monotonic() - pipe.last >= self.idle:
                        LOG.info("idle pipe to %s:%s closed", *pipe.target)
                        return
                    continue
                if not data:
                    return
                pipe.last = time.monotonic()
                writer.write(data)
                await writer.drain()
        except (OSError, asyncio.CancelledError):
            return


def parse_args(argv=None):
    env = os.environ.get
    parser = argparse.ArgumentParser(description="TCP forwarder for the Coder SSH slot")
    parser.add_argument("--port", type=int, default=int(env("SSH_PROXY_PORT", "2222")))
    parser.add_argument("--bind", default=env("SSH_PROXY_BIND", "0.0.0.0"))
    parser.add_argument("--destino", default=env("SSH_PROXY_DESTINO", "/var/lib/nutrition/coder/destino.json"))
    parser.add_argument("--max", type=int, dest="max_pipes", default=int(env("SSH_PROXY_MAX", "16")))
    parser.add_argument("--idle", type=float, default=float(env("SSH_PROXY_IDLE", "14400")))
    parser.add_argument("--connect-timeout", type=float, dest="connect_timeout",
                        default=float(env("SSH_PROXY_CONNECT_TIMEOUT", "10")))
    parser.add_argument("--poll", type=float, default=float(env("SSH_PROXY_POLL", "1")))
    return parser.parse_args(argv)


async def run(args):
    proxy = Proxy(args.destino, args.max_pipes, args.idle, args.connect_timeout, args.poll)
    port = await proxy.start(args.bind, args.port)
    LOG.info("listening on %s:%d, destination file %s", args.bind, port, args.destino)
    stop = asyncio.Event()
    loop = asyncio.get_running_loop()
    for sig in (signal.SIGTERM, signal.SIGINT):
        loop.add_signal_handler(sig, stop.set)
    await stop.wait()
    await proxy.stop()


def main(argv=None):
    logging.basicConfig(stream=sys.stderr, level=logging.INFO,
                        format="ssh-proxy: %(levelname)s %(message)s")
    asyncio.run(run(parse_args(argv)))


if __name__ == "__main__":
    main()
