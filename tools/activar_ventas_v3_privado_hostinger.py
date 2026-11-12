#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Gestión Comercial V3: check/apply/disable del acceso privado por rol.
Solo modifica un JSON privado FUERA de public_html. Sin SQL de escritura.
Compatible con Python 3.6+ y el control publicado de acceso V3.
"""
from __future__ import print_function
import argparse
import datetime
import hashlib
import json
import os
import shutil
import stat
import subprocess
import sys
import tempfile
import uuid

PHP_BLOB = "480ce33e3622eb44116c3aed578c0a753378eaa9"
AUDIENCIA = "asesores_rol_3_con_permiso"
CONFIRMAR = "ACTIVAR_V3_TODOS_ASESORES_IMPE"
CONFIRMAR_APAGAR = "DESACTIVAR_V3_TODOS_ASESORES_IMPE"

PHP_AUDIT = r"""
$site=$argv[1];
require $site.'/config/db_connection.php';
try {
    $db=(new Database())->connect(false);
    $q=$db->query("SELECT COUNT(*) n FROM rol_permisos rp
      JOIN permisos p ON p.id=rp.permiso_id
      JOIN roles r ON r.id=rp.rol_id
      WHERE rp.rol_id=3 AND r.estado=1 AND p.estado=1
      AND p.codigo='ventas.embudo.ver'");
    $permiso=(int)$q->fetch_assoc()['n']; $q->free();
    $q=$db->query("SELECT COUNT(*) n FROM usuarios u
      JOIN roles r ON r.id=u.rol_id WHERE u.rol_id=3
      AND u.estado=1 AND r.estado=1");
    $asesores=(int)$q->fetch_assoc()['n']; $q->free();
    $db->close();
    if ($permiso!==1 || $asesores<1) {
        fwrite(STDERR,"BLOQUEADO: rol/permiso sin condiciones válidas.\n");
        exit(2);
    }
    echo "ROL_3_PERMISO_OK | ASESORES_ACTIVOS=".$asesores."\n";
} catch(Throwable $e) {
    fwrite(STDERR,"BLOQUEADO: no se pudo comprobar rol/permiso.\n");
    exit(2);
}
"""

def sha_blob(data):
    return hashlib.sha1(("blob %d\0" % len(data)).encode("ascii") + data).hexdigest()

def leer(ruta):
    with open(ruta, "rb") as f:
        return f.read()

def guardado_atomico(ruta, data, mode):
    fd, tmp = tempfile.mkstemp(prefix=".ventas-v3-", dir=os.path.dirname(ruta))
    try:
        with os.fdopen(fd, "wb") as f:
            f.write(data)
            f.flush()
            os.fsync(f.fileno())
        os.chmod(tmp, mode)
        os.replace(tmp, ruta)
    finally:
        if os.path.lexists(tmp):
            os.unlink(tmp)

def validar(a):
    sitio = os.path.realpath(os.path.abspath(a.site))
    if os.path.islink(a.site) or not os.path.isfile(os.path.join(sitio, "config/db_connection.php")):
        raise RuntimeError("raíz del CRM no verificada.")
    archivo_php = os.path.join(sitio, "app/services/EmbudoComercialV3AccesoControlado.php")
    if os.path.islink(archivo_php) or not os.path.isfile(archivo_php):
        raise RuntimeError("control V3 ausente o enlazado.")
    if sha_blob(leer(archivo_php)) != PHP_BLOB:
        raise RuntimeError("control de acceso V3 personalizado o distinto del autorizado.")
    subprocess.check_output(["php", "-l", archivo_php], stderr=subprocess.STDOUT)

    raiz_dominio = os.path.dirname(sitio)
    directorio = os.path.join(raiz_dominio, "private-impe")
    archivo = os.path.join(directorio, "ventas_v3_asesores.json")
    if sitio != os.path.join(raiz_dominio, "public_html") or (
        os.path.commonpath([sitio, directorio]) == sitio
    ):
        raise RuntimeError("configuración privada no está fuera de public_html.")
    if os.path.islink(directorio) or os.path.islink(archivo) or (
        not os.path.isdir(directorio) or not os.path.isfile(archivo)
    ):
        raise RuntimeError("configuración privada ausente o enlazada.")
    if stat.S_IMODE(os.stat(directorio).st_mode) & 0o077:
        raise RuntimeError("permisos del directorio privado demasiado abiertos.")
    if stat.S_IMODE(os.stat(archivo).st_mode) & 0o077:
        raise RuntimeError("permisos del archivo privado demasiado abiertos.")

    original = leer(archivo)
    if len(original) > 1024:
        raise RuntimeError("configuración demasiado grande.")
    try:
        obj = json.loads(original.decode("utf-8"))
    except (ValueError, UnicodeDecodeError):
        raise RuntimeError("configuración privada inválida.")
    if (not isinstance(obj, dict) or
        set(obj) != set(("version", "habilitado", "audiencia")) or
        type(obj["version"]) is not int or obj["version"] != 1 or
        type(obj["habilitado"]) is not bool or
        obj["audiencia"] != AUDIENCIA):
        raise RuntimeError("configuración privada inesperada.")
    # Verificación contra BD únicamente SELECT: no nombres ni datos sensibles.
    salida = subprocess.check_output(["php", "-r", PHP_AUDIT, sitio],
                                     stderr=subprocess.STDOUT)
    print(salida.decode("utf-8", "replace").strip())
    print("CODIGO_V3_OK | CONFIG_PRIVADA_OK | SIN_CAMBIOS_SQL")
    return archivo, original, obj

def archivo_config(obj):
    return (json.dumps(obj, sort_keys=True, separators=(",", ":")) + "\n").encode("utf-8")

def backup_privado(src, contenido, raiz):
    if os.path.islink(raiz):
        raise RuntimeError("directorio de respaldo enlazado.")
    if not os.path.isdir(raiz):
        os.makedirs(raiz, 0o700)
    os.chmod(raiz, 0o700)
    nombre = "config_v3_" + datetime.datetime.utcnow().strftime("%Y%m%d_%H%M%S") + "_" + uuid.uuid4().hex[:8] + ".bak"
    dst = os.path.join(raiz, nombre)
    fd = os.open(dst, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(fd, "wb") as f:
        f.write(contenido)
        f.flush()
        os.fsync(f.fileno())
    if leer(dst) != contenido:
        raise RuntimeError("respaldo no verificado.")
    return dst

def main():
    p=argparse.ArgumentParser()
    p.add_argument("--site", required=True)
    p.add_argument("--backup-root")
    p.add_argument("--confirm")
    modo=p.add_mutually_exclusive_group(required=True)
    modo.add_argument("--check", action="store_true")
    modo.add_argument("--apply", action="store_true")
    modo.add_argument("--disable", action="store_true")
    a=p.parse_args()

    ruta, contenido, obj=validar(a)
    activo=obj["habilitado"]
    if a.check:
        print("ESTADO_ACTUAL: " + ("V3_ACTIVA_POR_ROL" if activo else "V3_DESACTIVADA"))
        print("CHECK_OK: configuración apta para el control de acceso V3.")
        return
    if not a.backup_root:
        raise RuntimeError("falta directorio de respaldo privado.")
    if a.apply and a.confirm != CONFIRMAR:
        raise RuntimeError("confirmación de activación incorrecta.")
    if a.disable and a.confirm != CONFIRMAR_APAGAR:
        raise RuntimeError("confirmación de desactivación incorrecta.")
    nuevo_estado=bool(a.apply)
    if activo == nuevo_estado:
        print("SIN_CAMBIOS: V3 ya está " + ("activa." if activo else "desactivada."))
        return

    respaldo=backup_privado(ruta, contenido, os.path.realpath(a.backup_root))
    print("RESPALDO_PRIVADO_OK: " + respaldo)
    nuevo=dict(obj)
    nuevo["habilitado"]=nuevo_estado
    # Si otro proceso editó la configuración desde check/backup, no sobrescribir.
    if leer(ruta) != contenido:
        raise RuntimeError("la configuración cambió durante el proceso.")
    guardado_atomico(ruta, archivo_config(nuevo), 0o600)
    try:
        verificado=json.loads(leer(ruta).decode("utf-8"))
        if verificado != nuevo:
            raise RuntimeError("no se verificó la nueva configuración.")
    except Exception:
        if leer(ruta) == archivo_config(nuevo):
            guardado_atomico(ruta, contenido, 0o600)
        raise
    print("APPLY_OK: " + ("V3 habilitada para rol 3 con permiso." if nuevo_estado else "V3 desactivada."))
    print("TABLERO_ANTERIOR_INTACTO | TELEFONIA_INTACTA | SIN_SQL")
    if nuevo_estado:
        print("PRUEBA_HTTP_PENDIENTE: iniciar sesión como asesor y abrir Nuevo embudo V3.")

if __name__=="__main__":
    try:
        main()
    except (RuntimeError, OSError, ValueError, subprocess.CalledProcessError) as exc:
        print("BLOQUEADO: " + str(exc), file=sys.stderr)
        sys.exit(2)
