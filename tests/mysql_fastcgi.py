"""Puente HTTP a PHP FastCGI exclusivamente para el laboratorio de localhost."""

from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
import socket
import struct
import subprocess
import threading
import time
from urllib.parse import unquote, urlsplit


CABECERA = struct.Struct("!BBHHBB")


def registros(tipo, cuerpo=b""):
    if not cuerpo:
        return CABECERA.pack(1, tipo, 1, 0, 0, 0)
    return b"".join(CABECERA.pack(1, tipo, 1, len(parte), 0, 0) + parte
                    for parte in (cuerpo[i:i + 65535] for i in range(0, len(cuerpo), 65535)))


def longitud(n):
    return bytes([n]) if n < 128 else struct.pack("!I", n | 0x80000000)


def respuesta_php(puerto, parametros, cuerpo, log):
    campos = b"".join(longitud(len(k)) + longitud(len(v)) + k + v
                      for k, v in ((k.encode(), v.encode()) for k, v in parametros.items()))
    salida = bytearray()
    with socket.create_connection(("127.0.0.1", puerto), timeout=15) as enlace:
        enlace.sendall(registros(1, struct.pack("!HB5x", 1, 0)) +
                       registros(4, campos) + registros(4) +
                       (registros(5, cuerpo) if cuerpo else b"") + registros(5))
        with enlace.makefile("rb") as flujo:
            while True:
                cabecera = flujo.read(CABECERA.size)
                if len(cabecera) != CABECERA.size:
                    raise OSError("PHP cerró FastCGI antes de completar la respuesta")
                version, tipo, id_, cantidad, relleno, _ = CABECERA.unpack(cabecera)
                if version != 1 or id_ != 1:
                    raise OSError("Registro FastCGI inesperado")
                datos = flujo.read(cantidad)
                flujo.read(relleno)
                if tipo == 6:
                    salida.extend(datos)
                elif tipo == 7 and datos:
                    log.write(datos)
                    log.flush()
                elif tipo == 3:
                    if len(datos) != 8 or datos[4] != 0:
                        raise OSError("FastCGI no completó la solicitud")
                    return bytes(salida)


class PeticionLaboratorio(BaseHTTPRequestHandler):
    def log_message(self, *_):
        pass

    def atender(self):
        ruta = urlsplit(self.path)
        nombre = unquote(ruta.path)
        raiz = self.server.raiz
        archivo = (raiz / nombre.lstrip("/")).resolve()
        if not archivo.is_relative_to(raiz) or archivo.suffix != ".php" or not archivo.is_file():
            self.send_error(404)
            return
        parametros = {
            "GATEWAY_INTERFACE": "CGI/1.1", "SERVER_PROTOCOL": "HTTP/1.1",
            "SERVER_SOFTWARE": "impe-laboratorio", "REDIRECT_STATUS": "200",
            "SERVER_NAME": "127.0.0.1", "SERVER_PORT": str(self.server.server_port),
            "REMOTE_ADDR": "127.0.0.1", "REQUEST_METHOD": self.command,
            "REQUEST_URI": self.path, "QUERY_STRING": ruta.query, "SCRIPT_NAME": nombre,
            "SCRIPT_FILENAME": str(archivo), "DOCUMENT_ROOT": str(raiz),
            "CONTENT_TYPE": self.headers.get("Content-Type", ""),
            "CONTENT_LENGTH": self.headers.get("Content-Length", "0"),
        }
        parametros.update(("HTTP_" + k.upper().replace("-", "_"), v) for k, v in self.headers.items()
                          if k.lower() not in ("content-type", "content-length"))
        try:
            cantidad = int(parametros["CONTENT_LENGTH"])
            if not 0 <= cantidad <= 1024 * 1024:
                self.send_error(413)
                return
            respuesta = respuesta_php(self.server.fastcgi, parametros, self.rfile.read(cantidad), self.server.log_php)
            cabecera, cuerpo = respuesta.split(b"\r\n\r\n", 1)
            estado = 200
            cabeceras = []
            for linea in cabecera.decode("latin1").split("\r\n"):
                nombre, valor = linea.split(":", 1)
                if nombre.lower() == "status":
                    estado = int(valor.split()[0])
                elif nombre.lower() not in ("connection", "transfer-encoding", "content-length"):
                    cabeceras.append((nombre, valor.strip()))
        except (OSError, ValueError):
            self.send_error(502)
            return
        self.send_response(estado)
        for nombre, valor in cabeceras:
            self.send_header(nombre, valor)
        self.send_header("Content-Length", str(len(cuerpo)))
        self.end_headers()
        self.wfile.write(cuerpo)

    do_GET = atender
    do_POST = atender


class WorkerFastCGI:
    """Adapta el ciclo de vida al de un proceso del banco HTTP."""

    def __init__(self, comando, entorno, log, sitio, puerto_http, puerto_fcgi):
        self.proceso = subprocess.Popen(comando + ["-b", f"127.0.0.1:{puerto_fcgi}"],
                                         env=entorno, stdout=log, stderr=log)
        try:
            for _ in range(100):
                if self.proceso.poll() is not None:
                    raise RuntimeError("PHP FastCGI terminó antes de escuchar")
                try:
                    with socket.create_connection(("127.0.0.1", puerto_fcgi), timeout=0.1):
                        break
                except OSError:
                    time.sleep(0.05)
            else:
                raise RuntimeError("PHP FastCGI no inició")
            self.http = ThreadingHTTPServer(("127.0.0.1", puerto_http), PeticionLaboratorio)
            self.http.raiz = Path(sitio).resolve()
            self.http.fastcgi = puerto_fcgi
            self.http.log_php = log
            self.hilo = threading.Thread(target=self.http.serve_forever, kwargs={"poll_interval": .03}, daemon=True)
            self.hilo.start()
        except BaseException:
            self.proceso.terminate()
            self.proceso.wait(timeout=10)
            raise

    def poll(self):
        return self.proceso.poll()

    def terminate(self):
        self.http.shutdown()
        self.http.server_close()
        if self.proceso.poll() is None:
            self.proceso.terminate()

    def wait(self, timeout):
        self.hilo.join(timeout)
        return self.proceso.wait(timeout=timeout)
