"""Tests for docker/servicio/ssh-proxy.py. Run: python3 -m unittest docker/pruebas/test_ssh_proxy.py"""
import asyncio
import importlib.util
import json
import os
import tempfile
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
SPEC = importlib.util.spec_from_file_location(
    "ssh_proxy", os.path.join(HERE, "..", "servicio", "ssh-proxy.py"))
ssh_proxy = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(ssh_proxy)


class ValidateTest(unittest.TestCase):
    def test_valid(self):
        self.assertEqual(ssh_proxy.validate_target({"host": "10.0.0.2", "port": 22}), ("10.0.0.2", 22))
        self.assertEqual(ssh_proxy.validate_target({"host": "::1", "port": 2222}), ("::1", 2222))
        self.assertEqual(ssh_proxy.validate_target({"host": "coder-1.local", "port": 65535}), ("coder-1.local", 65535))

    def test_invalid(self):
        bad = [None, [], "x", {}, {"host": "a"}, {"port": 22},
               {"host": "a", "port": 0}, {"host": "a", "port": 65536}, {"host": "a", "port": "22"},
               {"host": "a", "port": True}, {"host": "a", "port": 2.5}, {"host": 5, "port": 22},
               {"host": "", "port": 22}, {"host": "a b", "port": 22}, {"host": "a;rm", "port": 22},
               {"host": "-a", "port": 22}, {"host": "a..b", "port": 22}, {"host": "a/b", "port": 22},
               {"host": "a\n", "port": 22}]
        for item in bad:
            self.assertIsNone(ssh_proxy.validate_target(item), item)


class ProxyTest(unittest.IsolatedAsyncioTestCase):
    async def asyncSetUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.destino = os.path.join(self.tmp.name, "destino.json")
        self.echo_conns = []

        async def echo(reader, writer):
            self.echo_conns.append(writer)
            try:
                while True:
                    data = await reader.read(65536)
                    if not data:
                        break
                    writer.write(b"echo:" + data)
                    await writer.drain()
            except OSError:
                pass
            writer.close()

        self.echo = await asyncio.start_server(echo, "127.0.0.1", 0)
        self.echo_port = self.echo.sockets[0].getsockname()[1]
        self.proxies = []

    async def asyncTearDown(self):
        for proxy in self.proxies:
            await proxy.stop()
        self.echo.close()
        for writer in self.echo_conns:
            writer.close()
        self.tmp.cleanup()

    def write_dest(self, text=None, port=None):
        if text is None:
            text = json.dumps({"host": "127.0.0.1", "port": port or self.echo_port})
        with open(self.destino, "w") as handle:
            handle.write(text)

    async def make_proxy(self, **kw):
        kw.setdefault("poll", 0.05)
        proxy = ssh_proxy.Proxy(self.destino, **kw)
        port = await proxy.start("127.0.0.1", 0)
        self.proxies.append(proxy)
        return proxy, port

    async def assert_closed(self, reader, timeout=2):
        data = await asyncio.wait_for(reader.read(100), timeout)
        self.assertEqual(data, b"")

    async def test_bytes_both_ways(self):
        self.write_dest()
        _, port = await self.make_proxy()
        reader, writer = await asyncio.open_connection("127.0.0.1", port)
        writer.write(b"hello")
        self.assertEqual(await asyncio.wait_for(reader.readexactly(10), 2), b"echo:hello")
        big = os.urandom(300000)
        writer.write(big)
        got = b""
        while len(got.replace(b"echo:", b"")) < len(big):
            got += await asyncio.wait_for(reader.read(65536), 2)
        # The echo adds a prefix per chunk: remove the prefixes and compare the payload.
        self.assertEqual(got.replace(b"echo:", b""), big.replace(b"echo:", b""))
        writer.close()

    async def test_no_file_closes_at_once(self):
        _, port = await self.make_proxy()
        reader, writer = await asyncio.open_connection("127.0.0.1", port)
        await self.assert_closed(reader)
        writer.close()

    async def test_bad_files_close(self):
        _, port = await self.make_proxy()
        for text in ["not json", "[]", '{"host": "a b", "port": 22}', '{"host": "x", "port": 0}', "x" * 5000]:
            self.write_dest(text)
            reader, writer = await asyncio.open_connection("127.0.0.1", port)
            await self.assert_closed(reader)
            writer.close()

    async def test_unreachable_target_closes(self):
        probe = await asyncio.start_server(lambda r, w: None, "127.0.0.1", 0)
        free = probe.sockets[0].getsockname()[1]
        probe.close()
        await probe.wait_closed()
        self.write_dest(port=free)
        _, port = await self.make_proxy()
        reader, writer = await asyncio.open_connection("127.0.0.1", port)
        await self.assert_closed(reader)
        writer.close()

    async def test_file_removed_closes_pipes(self):
        self.write_dest()
        proxy, port = await self.make_proxy()
        reader, writer = await asyncio.open_connection("127.0.0.1", port)
        writer.write(b"a")
        await asyncio.wait_for(reader.readexactly(6), 2)
        os.remove(self.destino)
        await self.assert_closed(reader)
        writer.close()
        await asyncio.sleep(0.1)
        self.assertEqual(len(proxy.pipes), 0)

    async def test_destination_change_closes_pipes(self):
        self.write_dest()
        _, port = await self.make_proxy()
        reader, writer = await asyncio.open_connection("127.0.0.1", port)
        writer.write(b"a")
        await asyncio.wait_for(reader.readexactly(6), 2)
        self.write_dest(json.dumps({"host": "localhost", "port": self.echo_port}))
        await self.assert_closed(reader)
        writer.close()
        # A new connection uses the new target.
        reader, writer = await asyncio.open_connection("127.0.0.1", port)
        writer.write(b"b")
        self.assertEqual(await asyncio.wait_for(reader.readexactly(6), 2), b"echo:b")
        writer.close()

    async def test_same_content_keeps_pipes(self):
        self.write_dest()
        _, port = await self.make_proxy()
        reader, writer = await asyncio.open_connection("127.0.0.1", port)
        self.write_dest()
        await asyncio.sleep(0.3)
        writer.write(b"a")
        self.assertEqual(await asyncio.wait_for(reader.readexactly(6), 2), b"echo:a")
        writer.close()

    async def test_concurrency_limit(self):
        self.write_dest()
        proxy, port = await self.make_proxy(max_pipes=2)
        open_ones = []
        for _ in range(2):
            reader, writer = await asyncio.open_connection("127.0.0.1", port)
            writer.write(b"a")
            await asyncio.wait_for(reader.readexactly(6), 2)
            open_ones.append((reader, writer))
        reader3, writer3 = await asyncio.open_connection("127.0.0.1", port)
        await self.assert_closed(reader3)
        writer3.close()
        # After one pipe closes, a new connection is accepted.
        open_ones[0][1].close()
        await asyncio.sleep(0.2)
        reader4, writer4 = await asyncio.open_connection("127.0.0.1", port)
        writer4.write(b"z")
        self.assertEqual(await asyncio.wait_for(reader4.readexactly(6), 2), b"echo:z")
        writer4.close()
        open_ones[1][1].close()

    async def test_client_close_closes_target(self):
        self.write_dest()
        proxy, port = await self.make_proxy()
        reader, writer = await asyncio.open_connection("127.0.0.1", port)
        writer.write(b"a")
        await asyncio.wait_for(reader.readexactly(6), 2)
        writer.close()
        await asyncio.sleep(0.2)
        self.assertEqual(len(proxy.pipes), 0)

    async def test_idle_timeout(self):
        self.write_dest()
        _, port = await self.make_proxy(idle=0.2)
        reader, writer = await asyncio.open_connection("127.0.0.1", port)
        await self.assert_closed(reader)
        writer.close()

    async def test_log_has_no_bytes(self):
        self.write_dest()
        _, port = await self.make_proxy()
        with self.assertLogs("ssh-proxy", level="INFO") as logs:
            reader, writer = await asyncio.open_connection("127.0.0.1", port)
            writer.write(b"SECRET-PAYLOAD")
            await asyncio.wait_for(reader.read(100), 2)
            writer.close()
            await asyncio.sleep(0.2)
        self.assertTrue(logs.output)
        self.assertNotIn("SECRET-PAYLOAD", "\n".join(logs.output))


if __name__ == "__main__":
    unittest.main()
