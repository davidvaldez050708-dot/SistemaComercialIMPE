"""Prepara y prueba un vhost LSAPI aislado ya administrado por el operador."""

import argparse
from collections import Counter
from concurrent.futures import ThreadPoolExecutor
import json
from pathlib import Path
import secrets
import sys
import tempfile
import time

from mysql_capacidad import iniciar, muestra
from mysql_capacidad_http import autenticar, escenario, peticion, preparar_base, preparar_sitio, servidor
from mysql_limite_aperturas import LimitadorMySQL


def exigir_sonda(puerto, nonce):
    estado, _, cuerpo, _ = peticion(puerto, "/mysql_snapshot_web.php")
    if estado != 200:
        raise RuntimeError("El vhost no entregó la sonda aislada.")
    sonda = json.loads(cuerpo)
    if sonda.get("sapi") != "litespeed" or sonda.get("laboratorio") != nonce:
        raise RuntimeError("El vhost no corresponde al laboratorio LSAPI de esta ejecución.")
    if not str(sonda.get("php", "")).startswith("8.2."):
        raise RuntimeError("La réplica debe ejecutar PHP web 8.2.")
    if not sonda.get("connection_reused_disponible") or str(sonda.get("estadisticas", "")).lower() not in ("1", "on", "yes", "true"):
        raise RuntimeError("El vhost no expone el contador activo de mysqlnd.")
    return sonda


def autenticar_usuarios(puerto, usuarios):
    cookies = [autenticar(puerto, i)[1] for i in range(1, usuarios + 1)]
    if not all(cookies):
        raise RuntimeError("No se pudieron preparar todas las sesiones de prueba.")
    return cookies


def rafaga(puerto, usuarios, cookies, observar):
    with ThreadPoolExecutor(max_workers=usuarios) as tareas:
        inicio = time.monotonic() + 0.8
        trabajos = [tareas.submit(escenario, puerto, i + 1, inicio, cookie, None, True)
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
    for opcion in ("base", "cambios", "esquema", "salida"):
        parser.add_argument("--" + opcion, required=True, type=Path)
    parser.add_argument("--port", type=int, required=True, help="MariaDB desechable en loopback")
    parser.add_argument("--puerto-lsapi", type=int, default=18082)
    parser.add_argument("--usuarios", type=int, choices=(10, 30), required=True)
    parser.add_argument("--perfil", choices=("frio", "caliente", "reciclaje", "reciclaje-login"), required=True)
    args = parser.parse_args()
    if sys.platform != "linux":
        parser.error("Este ensayo requiere Linux y un vhost OpenLiteSpeed aislado.")
    args.limitar = args.verificar_avisos = args.admision = args.traza = True
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
            print(json.dumps({
                "docroot": str(sitio), "session.save_path": str(sitio / "sesiones-prueba"),
                "IMPE_LAB_SNAPSHOT": "1", "IMPE_LAB_NONCE": nonce,
                "IMPE_PRIVATE_CONFIG_DIR": str(sitio / "config"),
                "IMPE_DB_ADMISION_DIR": str(sitio.parent / "admision-mysql"),
                "IMPE_DB_TRAZA": "1", "IMPE_DB_HOST": f"127.0.0.1:{limite.server_address[1]}",
                "IMPE_DB_NAME": "impe_test_capacidad", "IMPE_DB_USER": "impe_capacity",
                "IMPE_DB_PASSWORD": "", "IMPE_DB_PERSISTENT": "1", "IMPE_DB_INDEPENDENT_PERSISTENT": "1",
                "IMPE_BASE_URL": f"http://127.0.0.1:{args.puerto_lsapi}/", "APP_ENV": "development",
            }, indent=2), flush=True)
            try:
                input("Arranca el vhost LSAPI aislado con estos parámetros y confirma con Enter. ")
                puerto = args.puerto_lsapi
                sonda = exigir_sonda(puerto, nonce)
                cookies = [None] * args.usuarios
                if args.perfil != "frio":
                    cookies = autenticar_usuarios(puerto, args.usuarios)
                    rafaga(puerto, args.usuarios, cookies, observador)
                if args.perfil.startswith("reciclaje"):
                    input("Reemplaza diez hijos del grupo LSAPI de prueba, conserva el directorio de sesiones y pulsa Enter. ")
                    exigir_sonda(puerto, nonce)
                    if args.perfil == "reciclaje-login":
                        cookies = [None] * args.usuarios
                inicio_rafaga = time.monotonic() - limite.ventana.origen + .8
                resultado = rafaga(puerto, args.usuarios, cookies, observador)
                resultado.update({
                    "perfil": args.perfil, "usuarios": args.usuarios, "sonda": sonda,
                    "inicio_rafaga_s": inicio_rafaga,
                    "pico_intentos_apertura_s": limite.ventana.pico_intentos,
                    "pico_aperturas_admitidas_s": limite.ventana.pico_admitidas,
                    "rechazos_apertura": sum(f["rechazadas"] for f in limite.ventana.por_segundo.values()),
                    "aperturas_por_segundo": limite.ventana.por_segundo,
                })
                lecturas = sql("SELECT COUNT(*) FROM agenda_comentarios_lecturas "
                               "WHERE novedad_leida_at IS NOT NULL OR recordatorio_atendido_at IS NOT NULL;", leer=True)
                assert lecturas.strip() == b"0", "El sondeo marcó avisos"
                args.salida.write_text(json.dumps(resultado, indent=2) + "\n", encoding="utf-8")
                print(f"Métricas de la réplica: {args.salida}")
            finally:
                input("Detén el vhost de prueba antes de pulsar Enter para eliminar la copia desechable. ")
    finally:
        observador.stdin.close()
        observador.wait(timeout=10)
        observador.stdout.close()


if __name__ == "__main__":
    main()
