#!/usr/bin/env python3
import asyncio
import base64
import hashlib
import json
import os
import struct
import time
from urllib.parse import urlparse


PORT = int(os.environ.get("SIMANIS_PRESENCE_PORT", "8789"))
CLIENTS = set()


class PresenceClient:
    def __init__(self, reader, writer):
        self.reader = reader
        self.writer = writer
        self.user = None
        self.last_seen = time.time()


def now_iso():
    return time.strftime("%Y-%m-%dT%H:%M:%S%z", time.localtime())


def build_presence_users():
    online = {}
    for client in list(CLIENTS):
        user = client.user or {}
        id_user = str(user.get("id_user") or "").strip()
        if not id_user:
            continue

        item = online.get(id_user) or {
            "id_user": id_user,
            "nama_lengkap": user.get("nama_lengkap") or "User SIMANIS",
            "level_user": user.get("level_user") or "",
            "connections": 0,
            "last_seen_at": now_iso(),
        }
        item["connections"] += 1
        item["last_seen_at"] = now_iso()
        online[id_user] = item

    return sorted(online.values(), key=lambda row: row.get("nama_lengkap", ""))


def presence_payload():
    users = build_presence_users()
    return {
        "type": "presence",
        "users": users,
        "online_id_users": [user["id_user"] for user in users],
        "online_count": len(users),
        "checked_at": now_iso(),
    }


async def send_frame(writer, payload=b"", opcode=0x1):
    if isinstance(payload, str):
        payload = payload.encode("utf-8")

    length = len(payload)
    header = bytearray([0x80 | opcode])
    if length < 126:
        header.append(length)
    elif length < 65536:
        header.append(126)
        header.extend(struct.pack("!H", length))
    else:
        header.append(127)
        header.extend(struct.pack("!Q", length))

    writer.write(bytes(header) + payload)
    await writer.drain()


async def send_json(client, payload):
    await send_frame(client.writer, json.dumps(payload, separators=(",", ":")), 0x1)


async def broadcast_presence():
    payload = presence_payload()
    stale = []
    for client in list(CLIENTS):
        try:
            await send_json(client, payload)
        except Exception:
            stale.append(client)

    for client in stale:
        CLIENTS.discard(client)
        try:
            client.writer.close()
        except Exception:
            pass


def normalize_user(value):
    if not isinstance(value, dict):
        return None

    id_user = str(value.get("id_user") or value.get("id") or "").strip()
    if not id_user:
        return None

    name = str(value.get("nama_lengkap") or value.get("name") or value.get("email") or "User SIMANIS").strip()
    role = str(value.get("level_user") or "").strip()
    return {
        "id_user": id_user,
        "nama_lengkap": name or "User SIMANIS",
        "level_user": role,
    }


async def read_frame(reader):
    first = await reader.readexactly(2)
    first_byte, second_byte = first[0], first[1]
    opcode = first_byte & 0x0F
    masked = (second_byte & 0x80) == 0x80
    length = second_byte & 0x7F

    if length == 126:
        length = struct.unpack("!H", await reader.readexactly(2))[0]
    elif length == 127:
        length = struct.unpack("!Q", await reader.readexactly(8))[0]

    mask = await reader.readexactly(4) if masked else b""
    payload = await reader.readexactly(length) if length else b""

    if masked:
        payload = bytes(byte ^ mask[index % 4] for index, byte in enumerate(payload))

    return opcode, payload


async def handle_ws(client):
    CLIENTS.add(client)
    try:
        while True:
            opcode, payload = await read_frame(client.reader)
            client.last_seen = time.time()

            if opcode == 0x8:
                break

            if opcode == 0x9:
                await send_frame(client.writer, payload, 0xA)
                continue

            if opcode != 0x1:
                continue

            try:
                message = json.loads(payload.decode("utf-8"))
            except Exception:
                continue

            message_type = message.get("type")
            if message_type == "hello":
                client.user = normalize_user(message.get("user"))
                await send_json(client, {"type": "hello", "status": True})
                await broadcast_presence()
            elif message_type == "heartbeat":
                await send_json(client, {"type": "heartbeat", "status": True, "server_time": now_iso()})
    except Exception:
        pass
    finally:
        CLIENTS.discard(client)
        try:
            client.writer.close()
        except Exception:
            pass
        await broadcast_presence()


async def handle_http(reader, writer):
    try:
        request = await asyncio.wait_for(reader.readuntil(b"\r\n\r\n"), timeout=5)
    except Exception:
        writer.close()
        return

    header_text = request.decode("latin1", errors="ignore")
    lines = header_text.split("\r\n")
    request_line = lines[0] if lines else ""
    parts = request_line.split()
    path = urlparse(parts[1]).path if len(parts) > 1 else ""
    headers = {}

    for line in lines[1:]:
        if ":" in line:
            key, value = line.split(":", 1)
            headers[key.lower().strip()] = value.strip()

    if path == "/health":
        body = json.dumps({
            "status": True,
            "clients": len(CLIENTS),
            "online_users": len(build_presence_users()),
            "users": build_presence_users(),
        }).encode("utf-8")
        writer.write(
            b"HTTP/1.1 200 OK\r\n"
            b"Content-Type: application/json; charset=utf-8\r\n"
            b"Access-Control-Allow-Origin: *\r\n"
            b"Cache-Control: no-store\r\n"
            + ("Content-Length: %d\r\n\r\n" % len(body)).encode("ascii")
            + body
        )
        await writer.drain()
        writer.close()
        return

    key = headers.get("sec-websocket-key")
    if not key:
        body = b'{"status":false,"message":"Not found"}'
        writer.write(
            b"HTTP/1.1 404 Not Found\r\n"
            b"Content-Type: application/json\r\n"
            + ("Content-Length: %d\r\n\r\n" % len(body)).encode("ascii")
            + body
        )
        await writer.drain()
        writer.close()
        return

    accept = base64.b64encode(hashlib.sha1((key + "258EAFA5-E914-47DA-95CA-C5AB0DC85B11").encode("ascii")).digest())
    writer.write(
        b"HTTP/1.1 101 Switching Protocols\r\n"
        b"Upgrade: websocket\r\n"
        b"Connection: Upgrade\r\n"
        b"Sec-WebSocket-Accept: " + accept + b"\r\n\r\n"
    )
    await writer.drain()
    await handle_ws(PresenceClient(reader, writer))


async def heartbeat_loop():
    while True:
        await asyncio.sleep(30)
        stale = []
        current = time.time()
        for client in list(CLIENTS):
            if current - client.last_seen > 90:
                stale.append(client)
                continue
            try:
                await send_frame(client.writer, b"ping", 0x9)
            except Exception:
                stale.append(client)

        for client in stale:
            CLIENTS.discard(client)
            try:
                client.writer.close()
            except Exception:
                pass

        await broadcast_presence()


async def main():
    server = await asyncio.start_server(handle_http, "0.0.0.0", PORT)
    print("SIMANIS presence WebSocket listening on 0.0.0.0:%s" % PORT, flush=True)
    asyncio.ensure_future(heartbeat_loop())
    try:
        await asyncio.Future()
    finally:
        server.close()
        await server.wait_closed()


if __name__ == "__main__":
    asyncio.get_event_loop().run_until_complete(main())
