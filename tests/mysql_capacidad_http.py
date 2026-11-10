"""Ensayo HTTP sobre copias exactas del código y una MariaDB desechable."""

import argparse
from collections import Counter
from concurrent.futures import ThreadPoolExecutor
import http.client
import json
import math
import os
from pathlib import Path
import re
import shutil
import socket
import subprocess
import tempfile
import time
from urllib.parse import urlencode

from mysql_capacidad import iniciar, muestra
from mysql_limite_aperturas import LimitadorMySQL


REPO = Path(__file__).resolve().parents[1]
PASSWORD = "impe-fixture-2026"
ROLES = {1: "Administrador", 3: "Asesor de Ventas", 4: "Analista de Datos", 6: "Cuenta Clave",
         7: "Marketing", 2: "Coordinador Comercial", 5: "Finanzas", 8: "Director de Operaciones"}
PERMISOS = {
    1: ["reuniones.ver"],
    3: ["telefonia.usar", "telefonia.salientes", "telefonia.recibir"],
    4: ["reuniones.ver", "reuniones.solicitar", "telefonia.usar", "telefonia.recibir"],
    6: ["reuniones.ver", "reuniones.gestionar"],
    7: ["telefonia.usar", "telefonia.salientes", "telefonia.recibir", "convocatorias.gestionar"],
}
PROVEEDOR = """<?php
namespace Zadarma_API;
class Api {
    public function __construct(...$args) {}
    public function getPbxInternal() { return (object)['pbx_id' => 'LAB', 'numbers' => range(101, 130)]; }
    public function getWebrtcKey($login) { return (object)['key' => 'fixture-webrtc']; }
}
class ApiException extends \\RuntimeException {}
namespace PHPMailer\\PHPMailer;
interface OAuthTokenProvider { public function getOauth64(); }
class SMTP { public function data($message) { throw new \\RuntimeException('SMTP deshabilitado en el laboratorio'); } }
"""
INICIO = "/index.php?controller=home&action=index"
LOGIN = "/index.php?controller=login&action=iniciarSesion"
CAMPANA = "/index.php?controller=reminder&action=pendientes"
WEBRTC = "/prueba_telefonia/api/zadarma_webrtc.php"
ESTADO = "/prueba_telefonia/api/zadarma_estado_llamada.php?destination=525500000001&checkpoint=1"
ENTRADA = "/prueba_telefonia/api/zadarma_entrada_activa.php"
TRANSFERENCIA = "/prueba_telefonia/api/transferencia_operacion.php?id=1"


def preparar_base(args):
    mysql = [args.mysql, "--no-defaults", "-h127.0.0.1", "--port=" + str(args.port), "-uroot", "impe_test_capacidad"]
    def sql(texto):
        subprocess.run(mysql + ["--default-character-set=utf8mb4"], input=texto.encode("utf-8"),
                       check=True, stdout=subprocess.DEVNULL, timeout=30)
    if args.esquema:
        esquema = args.esquema.read_text(encoding="utf-8-sig")
        tablas = re.findall(r"(?ims)^CREATE TABLE `[a-z0-9_]+` \(.*?;[ \t]*$", esquema)
        if not tablas or re.sub(r"(?ims)^CREATE TABLE `[a-z0-9_]+` \(.*?;[ \t]*$", "", esquema).strip():
            raise ValueError("Se esperaba una exportación de SHOW CREATE TABLE, sin otras sentencias.")
        sql("SET FOREIGN_KEY_CHECKS=0;\n" + "\n".join(tablas))
    else:
        # Extraer únicamente definiciones. Los INSERT de la copia histórica jamás
        # se ejecutan; las cuentas y extensiones de este ensayo son sintéticas.
        dump = (REPO / "database/sistema_comercial_impe.sql").read_text(encoding="utf-8")
        ddl = re.findall(r"(?ims)^CREATE TABLE\b.*?;[ \t]*$", dump)
        ddl = [definicion for definicion in ddl
               if not re.match(r"CREATE TABLE IF NOT EXISTS convocatorias\s*\(", definicion, re.I)]
        claves = re.findall(r"(?ims)^ALTER TABLE\b(?![^;]*FOREIGN KEY).*?;[ \t]*$", dump)
        sql("SET FOREIGN_KEY_CHECKS=0;\n" + "\n".join(ddl + claves))
        migraciones = sorted((REPO / "database/migrations").glob("*.sql"))
        for archivo in migraciones:
            texto = archivo.read_text(encoding="utf-8")
            tablas = re.findall(r"(?ims)^CREATE TABLE IF NOT EXISTS\b.*?;[ \t]*$", texto)
            if tablas:
                # El esquema histórico y las migraciones no reflejan los mismos
                # tipos de claves. El laboratorio mide conexiones, no integridad FK.
                sin_fk = [re.sub(r",\s*(?:CONSTRAINT [^\n]+\s+)?FOREIGN KEY\b[^,;]*?"
                                r"(?=,\s*\n|\n\)\s*ENGINE)", "", tabla, flags=re.I)
                          for tabla in tablas]
                sql("SET FOREIGN_KEY_CHECKS=0;\n" + "\n".join(sin_fk))
        for nombre in (
            "2026_09_04_reprogramacion_reuniones_vinculacion.sql",
            "2026_09_23_agenda_reactivacion_integridad.sql",
            "2026_09_28_convocatorias_categoria.sql",
            "2026_09_29_convocatorias_tipo_subtipo.sql",
            "2026_09_30_convocatorias_activacion_notificaciones.sql",
            "2026_10_06_convocatorias_enlace_registro.sql",
        ):
            texto = (REPO / "database/migrations" / nombre).read_text(encoding="utf-8")
            sql("\n".join(re.findall(r"(?ims)^ALTER TABLE\b[^;]+;", texto)))
        ventas = subprocess.check_output([
            args.php, "-r",
            "require 'app/services/TelefoniaVentasSchema.php'; echo json_encode(["
            "TelefoniaVentasSchema::cambios(), TelefoniaVentasSchema::columnasResultado()]);",
        ], cwd=REPO)
        tablas, columnas = json.loads(ventas)
        sql(";\n".join(ddl.replace("CREATE TABLE ", "CREATE TABLE IF NOT EXISTS ", 1)
                       for ddl in tablas.values()) + ";")
        sql("ALTER TABLE telefonia_ventas_resultados " + ", ".join(
            f"ADD COLUMN IF NOT EXISTS {nombre} {tipo}" for nombre, tipo in columnas.items()) + ";")
        sql("ALTER TABLE telefonia_contactos_personales "
            "ADD COLUMN IF NOT EXISTS correo VARCHAR(180) NOT NULL DEFAULT '', "
            "ADD COLUMN IF NOT EXISTS observacion VARCHAR(500) NOT NULL DEFAULT '', "
            "ADD COLUMN IF NOT EXISTS proxima_accion_at DATETIME NULL;")
    hash_pw = subprocess.check_output([args.php, "-r", "echo password_hash('" + PASSWORD + "', PASSWORD_BCRYPT, ['cost'=>4]);"]).decode()
    filas = []
    for id_, nombre in ROLES.items():
        filas.append("INSERT INTO roles (id,nombre,descripcion,estado) VALUES ({},'{}','Laboratorio',1);".format(id_, nombre))
    for codigo in sorted({p for lista in PERMISOS.values() for p in lista}):
        filas.append("INSERT INTO permisos (modulo,codigo,nombre,descripcion,estado) VALUES ('Prueba','{0}','{0}','',1);".format(codigo))
    for rol, codigos in PERMISOS.items():
        for codigo in codigos:
            filas.append("INSERT INTO rol_permisos (rol_id,permiso_id) SELECT {},id FROM permisos WHERE codigo='{}';".format(rol,codigo))
    for usuario in range(1, 31):
        rol = list(ROLES)[(usuario - 1) % len(ROLES)]
        filas.append("INSERT INTO usuarios (id,nombre,apellidos,correo,usuario,password,rol_id) "
                     "VALUES ({0},'Prueba','{0}','prueba{0}@invalid.test','prueba{0}','{1}',{2});".format(usuario,hash_pw,rol))
        filas.append("INSERT INTO telefonia_extensiones (usuario_id,proveedor,extension,activo,permite_salientes,permite_entrantes) "
                     "VALUES ({},'ZADARMA','{}',1,1,1);".format(usuario,100+usuario))
    sql("\n".join(filas))
    return sql


def preparar_sitio(destino, base, cambios):
    shutil.copytree(base, destino)
    if cambios:
        shutil.copytree(cambios, destino, dirs_exist_ok=True)
    for archivo in ("config/config.php", "config/private_config.php"):
        shutil.copyfile(REPO / archivo, destino / archivo)
    shutil.copyfile(REPO / "logout.php", destino / "logout.php")
    vendor = destino / "vendor"
    vendor.mkdir()
    (vendor / "autoload.php").write_text(PROVEEDOR, encoding="utf-8")
    (destino / "config/mail_config.php").write_text("<?php return [];", encoding="utf-8")
    (destino / "config/zadarma_config.php").write_text(
        "<?php return ['api_key'=>'fixture','api_secret'=>'fixture'];", encoding="utf-8")
    (destino / "storage").mkdir()
    # El archivo de revisión trae PHP y JS. Los estilos son irrelevantes para HTTP.
    (destino / "public/css").mkdir()
    for estilo in ("global.css", "dashboard.css", "recordatorios.css"):
        (destino / "public/css" / estilo).touch()
    (destino / "sesiones-prueba").mkdir()


def libre():
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


def servidor(args, sitio, log, db_port=None):
    puerto = libre()
    env = {key: value for key, value in os.environ.items() if not key.upper().startswith("IMPE_")}
    env.update(IMPE_PRIVATE_CONFIG_DIR=str(sitio / "config"),
               IMPE_DB_PERSISTENT="1", IMPE_DB_INDEPENDENT_PERSISTENT="1",
               IMPE_DB_HOST="127.0.0.1:" + str(db_port or args.port),
               IMPE_DB_NAME="impe_test_capacidad", IMPE_DB_USER="impe_capacity", IMPE_DB_PASSWORD="",
               IMPE_BASE_URL="http://127.0.0.1:" + str(puerto) + "/", APP_ENV="development")
    proceso = subprocess.Popen(
        [args.php, "-d", "display_errors=0", "-d", "session.save_path=" + str(sitio / "sesiones-prueba"),
         "-S", "127.0.0.1:" + str(puerto), "-t", str(sitio)],
        env=env, stdout=log, stderr=log,
    )
    return puerto, proceso


def peticion(puerto, ruta, cookie=None, datos=None):
    headers = {"X-Requested-With": "fetch"}
    if cookie:
        headers["Cookie"] = cookie
    if datos is not None:
        headers["Content-Type"] = "application/x-www-form-urlencoded"
    conn = http.client.HTTPConnection("127.0.0.1", puerto, timeout=15)
    inicio = time.monotonic()
    try:
        conn.request("POST" if datos is not None else "GET", ruta,
                     urlencode(datos) if datos is not None else None, headers)
        r = conn.getresponse()
        body = r.read()
        return r.status, r.getheaders(), body, (time.monotonic() - inicio) * 1000
    finally:
        conn.close()


def autenticar(puerto, usuario):
    estado, headers, _, ms = peticion(puerto, LOGIN, datos={"usuario": "prueba" + str(usuario), "password": PASSWORD})
    cookie = next((v.split(";", 1)[0] for k, v in headers if k.lower() == "set-cookie" and v.startswith("PHPSESSID=")), None)
    location = next((v for k, v in headers if k.lower() == "location"), "")
    valido = estado == 302 and ("controller=home" in location or "controller=telefonia&action=shell" in location)
    return ("login", "login_rechazado" if estado == 302 and not valido else estado, ms), cookie if valido else None


def escenario(puerto, usuario, inicio, cookie=None, registrar=None):
    time.sleep(max(0, inicio - time.monotonic()))
    datos = []

    def agregar(resultado, http=None):
        datos.append(resultado)
        if registrar is not None:
            registrar(resultado, http if http is not None else resultado[1])

    if cookie is None:
        login, cookie = autenticar(puerto, usuario)
        agregar(login, 302 if login[1] == "login_rechazado" else login[1])
        if cookie is None:
            return datos, None
    for nombre, ruta in (("inicio", INICIO), ("campana", CAMPANA), ("campana_recarga", CAMPANA)):
        e, _, body, ms = peticion(puerto, ruta, cookie)
        http = e
        if nombre.startswith("campana") and e == 200:
            try:
                r = json.loads(body)
                if not r.get("ok") and not r.get("parcial"):
                    e = "campana_no_disponible"
            except ValueError:
                e = "json_invalido"
        agregar((nombre, e, ms), http)
    if list(ROLES)[(usuario-1)%len(ROLES)] in (3,4,7):
        for nombre, ruta in (("webrtc", WEBRTC), ("entrada", ENTRADA), ("estado", ESTADO), ("transferencia", TRANSFERENCIA)):
            e, _, _, ms = peticion(puerto,ruta,cookie)
            agregar((nombre, e, ms))
    return datos, cookie


def carga(args, sitio, observador, cantidad, intervalo, log, limitador=None):
    servers = []
    puerto_mysql = limitador.server_address[1] if limitador else args.port
    origen = limitador.ventana.origen if limitador else time.monotonic()
    eventos = []
    muestras = []
    segundos_db = {}

    def registrar(resultado, http):
        eventos.append((int(time.monotonic() - origen), resultado, http))

    def muestrear():
        registro = muestra(observador)
        muestras.append(registro)
        segundo = int(time.monotonic() - origen)
        fila = segundos_db.setdefault(segundo, {"pico_sesiones": 0, "pico_sleep": 0})
        fila["pico_sesiones"] = max(fila["pico_sesiones"], registro[2])
        fila["pico_sleep"] = max(fila["pico_sleep"], registro[3])
    try:
        for _ in range(cantidad):
            servers.append(servidor(args, sitio, log, puerto_mysql))
        for puerto, proceso in servers:
            for _ in range(100):
                try:
                    with socket.create_connection(("127.0.0.1",puerto),timeout=0.2):
                        break
                except OSError:
                    if proceso.poll() is not None:
                        raise RuntimeError("PHP terminó antes de escuchar")
                    time.sleep(0.05)
            else:
                raise RuntimeError("PHP no inició")
        sesiones = [None] * cantidad
        if args.preautenticar:
            # Las cookies se preparan con otro proceso para que la medición
            # parta de pools fríos, con todos los usuarios ya autenticados.
            login_puerto, login_proceso = servidor(args, sitio, log)
            try:
                time.sleep(0.6)
                for i in range(cantidad):
                    login, sesiones[i] = autenticar(login_puerto, i + 1)
                    if not sesiones[i]:
                        raise RuntimeError("No se pudo preautenticar: " + repr(login))
            finally:
                login_proceso.terminate()
                login_proceso.wait(timeout=10)
        if args.pool_caliente:
            # Arranque del laboratorio. Los usuarios de la ráfaga conservan el
            # mismo worker; el escalonamiento ocurre antes de medir la ráfaga.
            for i, (puerto, _) in enumerate(servers):
                _, cookie = autenticar(puerto, i + 1)
                if not cookie:
                    raise RuntimeError("No fue posible calentar el worker del laboratorio")
                escenario(puerto, i + 1, time.monotonic(), cookie)
                muestrear()
                time.sleep(0.28)
            time.sleep(1.05)
        primera_muestra_rafaga = len(muestras)
        with ThreadPoolExecutor(max_workers=cantidad) as executor:
            inicio=time.monotonic()+1.1
            tareas=[executor.submit(escenario,p,i+1,inicio+i*intervalo,sesiones[i],registrar) for i,(p,_) in enumerate(servers)]
            while not all(t.done() for t in tareas):
                muestrear()
                time.sleep(0.025)
            muestrear()
            resultados=[r for tarea in tareas for r in tarea.result()[0]]
        exitos=sorted(ms for _,e,ms in resultados if e in (200,302))
        por_segundo = {}
        for segundo, (ruta, estado, ms), http in eventos:
            fila = por_segundo.setdefault(segundo, {"resultados": Counter(), "http": Counter(), "latencias_ms": {}})
            fila["resultados"]["exitosas" if estado in (200, 302) else "fallidas"] += 1
            fila["http"][str(http)] += 1
            fila["latencias_ms"].setdefault(ruta, []).append(round(ms, 1))
        resumen={
            "usuarios":cantidad,"intervalo_arranque":intervalo,"preautenticado":args.preautenticar,
            "pool_caliente":args.pool_caliente, "pico_sesiones_rafaga":max(m[2] for m in muestras[primera_muestra_rafaga:]),
            "pico_sesiones":max(m[2] for m in muestras),"sleep_final":muestras[-1][3],
            "intentos_max_1s_observados":max(b[1]-a[1] for b in muestras for a in muestras if 0<=b[0]-a[0]<=1),
            "p95_ms_exitosas":round(exitos[math.ceil(.95*len(exitos))-1],1) if exitos else None,
            "muestras_db_por_segundo":segundos_db,"http_por_segundo":por_segundo,
            "rutas":{ruta:dict(Counter(str(e) for n,e,_ in resultados if n==ruta)) for ruta in sorted({n for n,_,_ in resultados})},
        }
        if limitador:
            ventana = limitador.ventana
            resumen.update({
                "pico_intentos_ventana": ventana.pico_intentos,
                "pico_admitidas_ventana": ventana.pico_admitidas,
                "rechazos_aperturas": sum(fila["rechazadas"] for fila in ventana.por_segundo.values()),
                "aperturas_por_segundo": ventana.por_segundo,
            })
        return resumen
    finally:
        for _,p in servers:
            p.terminate()
        for _,p in servers:
            p.wait(timeout=10)


def probar_acceso(args, sitio, sql, log):
    puerto, web = servidor(args, sitio, log)
    caido, proceso_caido = servidor(args, sitio, log, libre())
    try:
        time.sleep(0.7)
        assert peticion(puerto, WEBRTC)[0] == 401, "Sin sesión"
        estados, cookie = escenario(puerto, 2, time.monotonic())
        assert cookie and ("webrtc", 200) in {(n,e) for n,e,_ in estados}, estados
        sql("DELETE FROM rol_permisos WHERE rol_id=3 AND permiso_id=(SELECT id FROM permisos WHERE codigo='telefonia.usar');")
        assert peticion(puerto, WEBRTC, cookie)[0] == 403, "Permiso revocado en sesión viva"
        sql("INSERT INTO rol_permisos (rol_id,permiso_id) SELECT 3,id FROM permisos WHERE codigo='telefonia.usar';")
        assert peticion(puerto, WEBRTC, cookie)[0] == 200, "Permiso restaurado"
        estado, headers, _, _ = peticion(caido, WEBRTC, cookie)
        assert estado == 503 and any(k.lower() == "retry-after" for k, _ in headers), "Fallo temporal 503"
        assert peticion(caido, CAMPANA, cookie)[0] == 503, "Campana ante fallo de conexión"
        login_caido, cookie_caida = autenticar(caido, 2)
        assert login_caido[1] == 503 and cookie_caida is None, "El fallo no autentica al usuario"
        assert peticion(puerto, WEBRTC, cookie)[0] == 200, "Recuperación sin invalidar la sesión"
        assert peticion(puerto, "/logout.php", cookie)[0] == 302, "Salida del CRM"
        assert peticion(puerto, WEBRTC, cookie)[0] == 401, "La cookie anterior pierde autorización"
        _, nueva_cookie = autenticar(puerto, 2)
        assert nueva_cookie and peticion(puerto, WEBRTC, nueva_cookie)[0] == 200, "Nuevo acceso después del cierre"
        print("WebRTC simulado: 401, 403 por revocación, 503 en autenticación, campana y WebRTC, recuperación y cierre verificados.", flush=True)
    finally:
        for proceso in (web, proceso_caido):
            proceso.terminate()
            proceso.wait(timeout=10)


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--php",required=True)
    parser.add_argument("--mysql",required=True)
    parser.add_argument("--port",type=int,required=True)
    parser.add_argument("--base",type=Path,required=True)
    parser.add_argument("--cambios",type=Path,required=True)
    parser.add_argument("--log",type=Path,required=True)
    parser.add_argument("--esquema", type=Path, help="Definiciones SHOW CREATE TABLE de la copia instalada")
    parser.add_argument("--limitar", action="store_true", help="Aplica el máximo de 20 aperturas en una ventana móvil")
    parser.add_argument("--sin-escalonar", action="store_true", help="Ejecuta sólo la ráfaga simultánea")
    parser.add_argument("--metricas", type=Path, help="Guarda resultados detallados por segundo en JSONL")
    parser.add_argument("--pool-caliente", action="store_true", help="Conserva las conexiones tras un arranque gradual medido")
    parser.add_argument("--solo", choices=("base", "candidato"))
    parser.add_argument("--preautenticar", action="store_true", help="Mide recargas con sesiones válidas y pools fríos")
    args=parser.parse_args()
    observador=iniciar(args.php,args.port,"observer")
    try:
        muestra(observador)  # Verifica el datadir de laboratorio antes de DDL.
        sql = preparar_base(args)
        with tempfile.TemporaryDirectory() as carpeta, args.log.open("wb") as log:
            for variante, cambios in (("base", None), ("candidato", args.cambios)):
                if args.solo and variante != args.solo:
                    continue
                sitio = Path(carpeta) / variante
                preparar_sitio(sitio, args.base, cambios)
                puerto_calentamiento, proceso = servidor(args, sitio, log)
                try:
                    time.sleep(0.6)
                    resultados, _ = escenario(puerto_calentamiento, 2, time.monotonic())
                    if resultados[0][1] != 302:
                        raise RuntimeError("No se pudo inicializar el catálogo de permisos del laboratorio.")
                finally:
                    proceso.terminate()
                    proceso.wait(timeout=10)
                for usuarios in (10,30):
                    intervalos = (0, 0.11) if variante == "candidato" and not args.sin_escalonar else (0,)
                    for intervalo in intervalos:
                        if args.limitar:
                            with LimitadorMySQL(args.port) as limitador:
                                resultado = carga(args, sitio, observador, usuarios, intervalo, log, limitador)
                        else:
                            resultado = carga(args, sitio, observador, usuarios, intervalo, log)
                        resultado["variante"] = variante
                        if args.metricas:
                            with args.metricas.open("a", encoding="utf-8") as metricas:
                                metricas.write(json.dumps(resultado) + "\n")
                        resumen = {k: v for k, v in resultado.items() if not k.endswith("_por_segundo")}
                        print(json.dumps(resumen), flush=True)
                if variante == "candidato":
                    probar_acceso(args, sitio, sql, log)
    finally:
        observador.stdin.close()
        observador.wait(timeout=10)
        observador.stdout.close()


if __name__=="__main__":
    main()
