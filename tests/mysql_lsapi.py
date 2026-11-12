"""Prepara y prueba un vhost LSAPI aislado ya administrado por el operador."""

import argparse
import base64
from collections import Counter
from concurrent.futures import ThreadPoolExecutor
import hmac
import json
import os
from pathlib import Path
import re
import secrets
import signal
import subprocess
import sys
import tempfile
import time

from mysql_capacidad import iniciar, muestra
from mysql_capacidad_http import (CAMPANA, INICIO, autenticar, comprobar_evento_activo, escenario, peticion,
                                 preparar_base, preparar_sitio, servidor)
from mysql_limite_aperturas import LimitadorMySQL


def exigir_sonda(puerto, nonce):
    estado, _, cuerpo, _ = peticion(puerto, "/mysql_snapshot_web.php")
    if estado != 200:
        raise RuntimeError(f"El vhost devolvió HTTP {estado} al solicitar la sonda aislada.")
    sonda = json.loads(cuerpo)
    if sonda.get("sapi") != "litespeed" or sonda.get("laboratorio") != nonce:
        raise RuntimeError("El vhost no corresponde al laboratorio LSAPI de esta ejecución.")
    if not str(sonda.get("php", "")).startswith("8.2."):
        raise RuntimeError("La réplica debe ejecutar PHP web 8.2.")
    if not sonda.get("connection_reused_disponible") or str(sonda.get("estadisticas", "")).lower() not in ("1", "on", "yes", "true"):
        raise RuntimeError("El vhost no expone el contador activo de mysqlnd.")
    return sonda


def iniciar_lsapi(sitio, puerto, entorno, php_lsapi, evidencia):
    import grp
    import pwd

    if not Path("/.dockerenv").is_file() or {p.name for p in Path("/sys/class/net").iterdir()} != {"lo"}:
        raise RuntimeError("El inicio automático requiere un contenedor Docker sólo con loopback.")
    servidor_ols = Path("/usr/local/lsws")
    cuenta = pwd.getpwnam("nobody")
    sitio.parent.chmod(0o755)
    sockets = sitio.parent / "sockets"
    sockets.mkdir(mode=0o700)
    for carpeta in (sitio / "sesiones-prueba", sitio.parent / "admision-mysql", sitio / "storage", sockets):
        for ruta in (carpeta, *carpeta.rglob("*")):
            os.chown(ruta, cuenta.pw_uid, cuenta.pw_gid)
    (servidor_ols / "conf/httpd_config.conf").write_text(f"""
serverName impe-lab
httpdWorkers 1
user nobody
group {grp.getgrgid(cuenta.pw_gid).gr_name}
mime conf/mime.properties
errorlog {evidencia / 'ols-error.log'} {{
  logLevel INFO
}}
accesslog {evidencia / 'ols-access.log'} {{
  rollingSize 100M
}}
listener loopback {{
  address 127.0.0.1:{puerto}
  secure 0
  map laboratorio *
}}
virtualhost laboratorio {{
  vhRoot {sitio}
  configFile {sitio.parent / 'vhost.conf'}
  allowSymbolLink 0
  enableScript 1
  restrained 1
}}
""", encoding="utf-8")
    (sitio.parent / "vhost.conf").write_text(f"""
docRoot {sitio}
indexFiles index.php
extprocessor php-lab {{
  type lsapi
  address uds://{sockets / 'php.sock'}
  maxConns 30
  initTimeout 30
  retryTimeout 0
  persistConn 1
  autoStart 0
}}
scripthandler {{
  add lsapi:php-lab php
}}
""", encoding="utf-8")
    ini = sitio.parent / "php-conf"
    ini.mkdir()
    # The empty scan path component preserves the image's extension ini files.
    (ini / "99-lab.ini").write_text(
        f"session.save_path={sitio / 'sesiones-prueba'}\n"
        "mysqlnd.collect_statistics=1\ndisplay_errors=0\nlog_errors=1\n",
        encoding="utf-8",
    )
    binario = servidor_ols / "bin/openlitespeed"
    subprocess.run([str(binario), "-t"], check=True)
    env_php = {**entorno, "PATH": os.defpath, "PHP_LSAPI_CHILDREN": "30",
               "LSAPI_MAX_REQS": "100000", "PHP_INI_SCAN_DIR": f":{ini}"}
    with (evidencia / "lsphp.log").open("wb") as log:
        php = subprocess.Popen([str(php_lsapi), "-b", str(sockets / "php.sock")],
                               env=env_php, user=cuenta.pw_uid, group=cuenta.pw_gid,
                               stdout=log, stderr=log, start_new_session=True)
    try:
        for _ in range(50):
            if php.poll() is not None:
                raise RuntimeError(f"LSPHP terminó al arrancar: {php.returncode}. Revisa lsphp.log.")
            if (sockets / "php.sock").exists():
                break
            time.sleep(.1)
        else:
            raise RuntimeError("LSPHP no creó el socket de laboratorio.")
        return subprocess.Popen([str(binario), "-n"]), php
    except BaseException:
        detener_lsphp(php)
        raise


def detener_lsphp(php):
    try:
        os.killpg(php.pid, signal.SIGTERM)
    except ProcessLookupError:
        pass
    php.wait(timeout=15)


def autenticar_usuarios(puerto, usuarios):
    cookies = [autenticar(puerto, i)[1] for i in range(1, usuarios + 1)]
    if not all(cookies):
        raise RuntimeError("No se pudieron preparar todas las sesiones de prueba.")
    return cookies


def comprobar_resultado_ventas(args, puerto, cookie, sql):
    def json_ok(ruta, datos=None):
        estado, _, cuerpo, _ = peticion(puerto, ruta, cookie, datos)
        if estado != 200:
            raise RuntimeError(f"HTTP {estado} en {ruta}: {cuerpo[:300]!r}")
        respuesta = json.loads(cuerpo)
        if respuesta.get("ok") is not True:
            raise RuntimeError(f"Respuesta sin éxito en {ruta}: {respuesta!r}")
        return respuesta

    estado, _, inicio, _ = peticion(puerto, INICIO, cookie)
    token = re.search(rb'data-phone-notification-csrf="([a-f0-9]{64})"', inicio)
    if estado != 200 or token is None:
        raise RuntimeError("Inicio del asesor no expuso un token de la sesión autenticada.")
    csrf = token[1].decode("ascii")
    controlador = "/index.php?controller=telefonia&action="
    poll = json_ok(controlador + "disponibilidad", {
        "accion": "POLL", "disponible": "1", "csrf_token": csrf,
    })
    if not {"solicitud", "entrante", "devoluciones"} <= poll.keys():
        raise RuntimeError("El sondeo devolvió un contrato incompleto.")
    if sql("SELECT COUNT(*) FROM telefonia_presencia WHERE usuario_id=2 AND ocupado=0;",
           leer=True).strip() != b"1":
        raise RuntimeError("El sondeo no persistió la disponibilidad sintética.")

    instante = subprocess.check_output([
        args.php, "-r",
        "date_default_timezone_set('America/Mexico_City'); echo date('Y-m-d H:i:s');",
    ]).decode()
    ref = "lab-activa-102"
    destino = "525500000001"
    grabacion = "lab-record-102"
    for tipo, campos, cadena in (
        ("NOTIFY_OUT_END", {"destination": destino, "call_start": instante,
                           "duration": "15", "disposition": "answered"}, "102" + destino + instante),
        ("NOTIFY_RECORD", {"call_id_with_rec": grabacion}, ref + grabacion),
    ):
        firma = base64.b64encode(hmac.new(b"fixture", cadena.encode(), "sha1").hexdigest().encode()).decode()
        codigo, _, cuerpo, _ = peticion(puerto, "/prueba_telefonia/api/zadarma_webhook.php",
                                        datos={"event": tipo, "internal": "102", "pbx_call_id": ref, **campos},
                                        cabeceras={"Signature": firma})
        if codigo != 200 or json.loads(cuerpo).get("status") != "success":
            raise RuntimeError(f"Webhook {tipo} falló con HTTP {codigo}.")

    def aviso_de_llamada():
        return [aviso for aviso in json_ok(CAMPANA)["recordatorios"]
                if f"pbx_call_id={ref}" in aviso.get("url", "")]

    avisos = aviso_de_llamada()
    if len(avisos) != 1 or avisos[0]["titulo"] != "Resultado pendiente":
        eventos = sql("SELECT evento, internal FROM telefonia_zadarma_eventos "
                      "WHERE pbx_call_id='lab-activa-102' ORDER BY id;", leer=True).decode().splitlines()
        llamadas = sql("SELECT COUNT(*) FROM telefonia_ventas_llamadas "
                       "WHERE pbx_call_id='lab-activa-102' AND usuario_id=2;", leer=True).strip()
        raise RuntimeError(f"Aviso de llamada fuera de contrato: {len(avisos)} avisos, "
                           f"{int(llamadas)} llamadas atribuidas, eventos={eventos}.")
    if [aviso["telefonia_id"] for aviso in aviso_de_llamada()] != [avisos[0]["telefonia_id"]]:
        raise RuntimeError("Un segundo sondeo cambió la identidad del aviso.")
    json_ok(controlador + "guardarResultadoVenta", {
        "pbx_call_id": ref, "extension": "102", "resultado": "BUZON_VOZ", "csrf_token": csrf,
    })
    detalle = json_ok(controlador + f"detalleVenta&pbx_call_id={ref}&extension=102")["llamada"]
    if detalle["registro"]["resultado"] != "BUZON_VOZ" or grabacion not in detalle["segmentos"]:
        raise RuntimeError("El detalle no conservó el resultado y la referencia de grabación simulada.")
    if aviso_de_llamada():
        raise RuntimeError("El aviso quedó pendiente después de guardar el resultado.")
    return {"presencia": True, "aviso_una_vez": True, "resultado": True,
            "referencia_grabacion": True}


def rafaga(puerto, usuarios, cookies, observar, verificar_avisos=False):
    with ThreadPoolExecutor(max_workers=usuarios) as tareas:
        inicio = time.monotonic() + 0.8
        trabajos = [tareas.submit(escenario, puerto, i + 1, inicio, cookie, None,
                                  verificar_avisos, not verificar_avisos)
                    for i, cookie in enumerate(cookies)]
        muestras = []
        while not all(t.done() for t in trabajos):
            muestras.append(muestra(observar))
            time.sleep(.025)
        muestras.append(muestra(observar))
    rutas = {}
    latencias = {}
    for tarea in trabajos:
        for nombre, estado, ms in tarea.result()[0]:
            rutas.setdefault(nombre, Counter())[str(estado)] += 1
            latencias.setdefault(nombre, []).append(round(ms, 1))
    return {
        "rutas": rutas, "latencias_ms": latencias, "muestras_mysql": muestras,
        "pico_simultaneas_observado": max(m[2] for m in muestras),
        "pico_activas_observado": max(m[2] - m[3] for m in muestras),
        "pico_sleep_observado": max(m[3] for m in muestras),
    }


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    for opcion in ("php", "mysql"):
        parser.add_argument("--" + opcion, required=True)
    for opcion in ("base", "salida"):
        parser.add_argument("--" + opcion, required=True, type=Path)
    for opcion in ("cambios", "esquema", "php-lsapi"):
        parser.add_argument("--" + opcion, type=Path)
    parser.add_argument("--port", type=int, required=True, help="MariaDB desechable en loopback")
    parser.add_argument("--puerto-lsapi", type=int, default=18082)
    parser.add_argument("--usuarios", type=int, choices=(10, 30), required=True)
    parser.add_argument("--perfil", choices=("frio", "caliente", "reciclaje", "reciclaje-login"), required=True)
    args = parser.parse_args()
    if sys.platform != "linux":
        parser.error("Este ensayo requiere Linux y un vhost OpenLiteSpeed aislado.")
    args.limitar = args.admision = args.traza = True
    args.verificar_avisos = args.php_lsapi is None
    args.php_cgi = args.max_persistentes = None
    args.mysqlnd = "activas"

    observador = iniciar(args.php, args.port, "observer-lsapi")
    try:
        muestra(observador)  # Rechaza datadir ajeno al laboratorio antes de ejecutar DDL.
        sql = preparar_base(args)
        with tempfile.TemporaryDirectory(prefix="impe-lsapi-") as raiz, LimitadorMySQL(args.port) as limite:
            sitio = Path(raiz) / "sitio"
            preparar_sitio(sitio, args.base, args.cambios)
            with (Path(raiz) / "primer.log").open("wb") as log:
                puerto, primer = servidor(args, sitio, log)
                try:
                    time.sleep(.7)
                    if not autenticar(puerto, 1)[1]:
                        raise RuntimeError("No se inicializó el catálogo sintético.")
                finally:
                    primer.terminate()
                    primer.wait(timeout=10)
            nonce = secrets.token_hex(16)
            entorno = {
                "docroot": str(sitio), "session.save_path": str(sitio / "sesiones-prueba"),
                "IMPE_LAB_SNAPSHOT": "1", "IMPE_LAB_NONCE": nonce,
                "IMPE_PRIVATE_CONFIG_DIR": str(sitio / "config"),
                "IMPE_DB_ADMISION_DIR": str(sitio.parent / "admision-mysql"),
                "IMPE_DB_TRAZA": "1", "IMPE_DB_HOST": f"127.0.0.1:{limite.server_address[1]}",
                "IMPE_DB_NAME": "impe_test_capacidad", "IMPE_DB_USER": "impe_capacity",
                "IMPE_DB_PASSWORD": "", "IMPE_DB_PERSISTENT": "1", "IMPE_DB_INDEPENDENT_PERSISTENT": "1",
                "IMPE_BASE_URL": f"http://127.0.0.1:{args.puerto_lsapi}/", "APP_ENV": "development",
            }
            evidencia = args.salida.resolve().parent
            evidencia.mkdir(parents=True, exist_ok=True)
            proceso = php = None
            try:
                if args.php_lsapi:
                    if args.perfil.startswith("reciclaje"):
                        raise RuntimeError("El reciclaje automático todavía no está disponible.")
                    proceso, php = iniciar_lsapi(sitio, args.puerto_lsapi, entorno, args.php_lsapi, evidencia)
                    for _ in range(30):
                        if proceso.poll() is not None:
                            raise RuntimeError(f"OpenLiteSpeed terminó al arrancar: {proceso.returncode}. Revisa ols-error.log.")
                        try:
                            exigir_sonda(args.puerto_lsapi, nonce)
                            break
                        except OSError:
                            time.sleep(1)
                else:
                    print(json.dumps(entorno, indent=2), flush=True)
                    input("Arranca el vhost LSAPI aislado con estos parámetros y confirma con Enter. ")
                puerto = args.puerto_lsapi
                sonda = exigir_sonda(puerto, nonce)
                cookies = [None] * args.usuarios
                if args.perfil != "frio":
                    cookies = autenticar_usuarios(puerto, args.usuarios)
                    rafaga(puerto, args.usuarios, cookies, observador, args.verificar_avisos)
                if args.perfil.startswith("reciclaje"):
                    input("Reemplaza diez hijos del grupo LSAPI de prueba, conserva el directorio de sesiones y pulsa Enter. ")
                    exigir_sonda(puerto, nonce)
                    if args.perfil == "reciclaje-login":
                        cookies = [None] * args.usuarios
                inicio_rafaga = time.monotonic() - limite.ventana.origen + .8
                resultado = rafaga(puerto, args.usuarios, cookies, observador, args.verificar_avisos)
                resultado.update({
                    "perfil": args.perfil, "usuarios": args.usuarios, "sonda": sonda,
                    "inicio_rafaga_s": inicio_rafaga,
                    "pico_intentos_apertura_s": limite.ventana.pico_intentos,
                    "pico_aperturas_admitidas_s": limite.ventana.pico_admitidas,
                    "rechazos_apertura": sum(f["rechazadas"] for f in limite.ventana.por_segundo.values()),
                    "aperturas_por_segundo": limite.ventana.por_segundo,
                })
                args.salida.write_text(json.dumps(resultado, indent=2) + "\n", encoding="utf-8")
                print(f"Métricas de la réplica: {args.salida}")
                if args.verificar_avisos:
                    lecturas = sql("SELECT COUNT(*) FROM agenda_comentarios_lecturas "
                                   "WHERE novedad_leida_at IS NOT NULL OR recordatorio_atendido_at IS NOT NULL;",
                                   leer=True)
                    if lecturas.strip() != b"0":
                        raise RuntimeError("El sondeo marcó avisos.")
                for ruta, estados in resultado["rutas"].items():
                    esperados = {"302"} if ruta == "login" else {"200"}
                    if set(estados) - esperados:
                        raise RuntimeError(f"{ruta}: respuestas fuera del contrato: {estados}")
                if resultado["rechazos_apertura"]:
                    raise RuntimeError("Se rechazaron aperturas MySQL.")
                if args.php_lsapi:
                    time.sleep(1)  # Ventana separada de la ráfaga fría.
                    login, cookie = autenticar(puerto, 2)
                    if not cookie:
                        raise RuntimeError(f"No se autenticó el asesor sintético: {login}")
                    resultado["webhook"] = comprobar_evento_activo(args, (puerto, puerto), cookie, sql)
                    args.salida.write_text(json.dumps(resultado, indent=2) + "\n", encoding="utf-8")
                    resultado["ventas"] = comprobar_resultado_ventas(args, puerto, cookie, sql)
                    args.salida.write_text(json.dumps(resultado, indent=2) + "\n", encoding="utf-8")
            finally:
                if proceso is not None:
                    try:
                        proceso.terminate()
                        proceso.wait(timeout=15)
                    finally:
                        detener_lsphp(php)
                elif not args.php_lsapi:
                    input("Detén el vhost de prueba antes de pulsar Enter para eliminar la copia desechable. ")
    finally:
        observador.stdin.close()
        observador.wait(timeout=10)
        observador.stdout.close()


if __name__ == "__main__":
    main()
