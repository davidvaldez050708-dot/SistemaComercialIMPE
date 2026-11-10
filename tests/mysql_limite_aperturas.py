"""Reproduce el límite de aperturas MySQL de una cuenta en un laboratorio local."""

from collections import Counter, deque
import select
import socket
import socketserver
import threading
import time


class VentanaAperturas:
    def __init__(self, maximo):
        self.maximo = maximo
        self.admitidas = deque()
        self.intentos = deque()
        self.origen = time.monotonic()
        self.por_segundo = {}
        self.pico_intentos = 0
        self.pico_admitidas = 0
        self.lock = threading.Lock()

    def admitir(self):
        with self.lock:
            ahora = time.monotonic()
            for ventana in (self.admitidas, self.intentos):
                while ventana and ahora - ventana[0] >= 1:
                    ventana.popleft()
            segundo = int(ahora - self.origen)
            fila = self.por_segundo.setdefault(segundo, Counter())
            self.intentos.append(ahora)
            fila["intentos"] += 1
            self.pico_intentos = max(self.pico_intentos, len(self.intentos))
            if len(self.admitidas) >= self.maximo:
                fila["rechazadas"] += 1
                return False
            self.admitidas.append(ahora)
            fila["admitidas"] += 1
            self.pico_admitidas = max(self.pico_admitidas, len(self.admitidas))
            return True


class ConexionMySQL(socketserver.BaseRequestHandler):
    def handle(self):
        if not self.server.ventana.admitir():
            # ERR de MySQL antes del saludo, número 1226 y SQLSTATE 42000.
            cuerpo = b"\xff\xca\x04#42000Limite de aperturas del laboratorio"
            try:
                self.request.sendall(len(cuerpo).to_bytes(3, "little") + b"\0" + cuerpo)
            except OSError:
                pass
            return
        try:
            with socket.create_connection(("127.0.0.1", self.server.destino), timeout=4) as mysql:
                self.request.settimeout(4)
                pares = {self.request: mysql, mysql: self.request}
                while not self.server.terminado.is_set():
                    listos, _, _ = select.select(tuple(pares), (), (), 0.5)
                    for emisor in listos:
                        datos = emisor.recv(65536)
                        if not datos:
                            return
                        pares[emisor].sendall(datos)
        except OSError:
            pass


class LimitadorMySQL(socketserver.ThreadingTCPServer):
    daemon_threads = True
    request_queue_size = 128

    def __init__(self, destino, maximo=20):
        self.destino = destino
        self.ventana = VentanaAperturas(maximo)
        self.terminado = threading.Event()
        super().__init__(("127.0.0.1", 0), ConexionMySQL)

    def __enter__(self):
        self.hilo = threading.Thread(target=self.serve_forever, daemon=True)
        self.hilo.start()
        return self

    def __exit__(self, *exc):
        self.terminado.set()
        self.shutdown()
        self.server_close()
        self.hilo.join()
