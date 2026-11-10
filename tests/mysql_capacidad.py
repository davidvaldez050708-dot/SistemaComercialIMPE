"""Mide pools MySQLi con PHP y MariaDB locales. No llama al CRM ni a Zadarma."""

import argparse
from collections import Counter
import json
import math
from pathlib import Path
import subprocess
import time


WORKER = Path(__file__).with_name("mysql_capacidad_worker.php")
ESCENARIOS = (
    ("4_persistentes_rafaga", 4, True, 0),
    ("1_persistente_rafaga", 1, True, 0),
    ("1_no_persistente_rafaga", 1, False, 0),
    ("1_persistente_escalonado", 1, True, 0.09),
)


def iniciar(php, puerto, modo):
    return subprocess.Popen(
        [php, str(WORKER), str(puerto), modo],
        stdin=subprocess.PIPE, stdout=subprocess.PIPE, universal_newlines=True,
    )


def enviar(proceso, mensaje):
    proceso.stdin.write(mensaje + "\n")
    proceso.stdin.flush()


def muestra(observador):
    enviar(observador, "sample")
    return json.loads(observador.stdout.readline())


def medir(php, puerto, observador, usuarios, escenario):
    nombre, sesiones, persistente, paso = escenario
    procesos = []
    try:
        for _ in range(usuarios):
            procesos.append(iniciar(php, puerto, "worker"))
        for proceso in procesos:
            if proceso.stdout.readline().strip() != "listo":
                raise RuntimeError("Un trabajador de PHP no inició.")
        inicio = time.time() + 1.15
        for i, proceso in enumerate(procesos):
            enviar(proceso, json.dumps({"inicio": inicio + i * paso,
                                        "sesiones": sesiones, "persistente": persistente}))
        muestras = []
        while time.time() < inicio + (usuarios - 1) * paso + 1.1:
            muestras.append(muestra(observador))
            time.sleep(0.018)
        resultados = [r for proceso in procesos for r in json.loads(proceso.stdout.readline())]
        muestras.append(muestra(observador))
        # Muestreo del contador acumulado. El máximo observado usa ventanas
        # de hasta 1 s y puede subestimar una ráfaga entre dos muestras.
        pico_segundo = max(b[1] - a[1] for b in muestras for a in muestras if 0 <= b[0] - a[0] <= 1)
        ms = sorted(r["ms"] for r in resultados if not r["error"])
        return {
            "usuarios": usuarios, "escenario": nombre, "solicitudes": len(resultados),
            "pico_sesiones": max(m[2] for m in muestras),
            "sleep_final": muestras[-1][3],
            "intentos_totales": muestras[-1][1] - muestras[0][1],
            "intentos_max_1s_observados": pico_segundo,
            "conexiones_exitosas_distintas": len({i for r in resultados for i in r["ids"]}),
            "errores": dict(Counter(r["error"] for r in resultados if r["error"])),
            "p95_ms_exitosas": round(ms[math.ceil(len(ms) * 0.95) - 1], 1) if ms else None,
        }
    finally:
        for proceso in procesos:
            proceso.stdin.close()
            try:
                proceso.wait(timeout=10)
            except subprocess.TimeoutExpired:
                proceso.kill()
                proceso.wait()
            proceso.stdout.close()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--php", required=True)
    parser.add_argument("--port", type=int, required=True)
    args = parser.parse_args()
    observador = iniciar(args.php, args.port, "observer")
    try:
        if muestra(observador)[2] != 0:
            raise RuntimeError("La cuenta del laboratorio ya tiene sesiones abiertas.")
        for usuarios in (10, 30):
            for escenario in ESCENARIOS:
                print(json.dumps(medir(args.php, args.port, observador, usuarios, escenario)), flush=True)
                if muestra(observador)[2] != 0:
                    raise RuntimeError("No se liberaron las sesiones del escenario anterior.")
    finally:
        observador.stdin.close()
        observador.wait(timeout=10)
        observador.stdout.close()


if __name__ == "__main__":
    main()
