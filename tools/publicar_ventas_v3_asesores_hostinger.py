#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Publicador del embudo institucional V3: 4 archivos existentes y 5 nuevos. UI apagada por defecto.
Python 3.6+; sin red ni SQL. Protege V4, Bridge, WebRTC y JS del tablero actual.
Utilizar con un paquete ya descargado del commit fijado y SHA Git verificados.
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

REPO_REV = "84e9e5dc2fd27bb72b194e30af64c2e443b8c27a"
CONFIRMACION = "PUBLICAR_SOLO_EMBUDO_ASESOR_V3_IMPE"
# (blob antes, blob nuevo); null = archivo NUEVO, prohibido sobrescribir otro.
ARCHIVOS = {
    # Cinco componentes V3 NUEVOS (no sobrescribir si ya existen).
    "app/services/EmbudoComercialV3MovimientosPermitidos.php":
        (None, "92950fe72f02c938050c8598cd0ed96cd1552c6e"),
    "app/services/EmbudoComercialV3AccesoControlado.php":
        (None, "1f5530335054bfb77a8cbe28669b45edef581ba4"),
    "app/views/embudo_comercial/operativo_v3.php":
        (None, "ed44384ebaf967c5161ad5eb5f2734554ea587ce"),
    "public/css/embudo_v3_operativo.css":
        (None, "f68fec01b9d87d9290ae9e58723f006e7b2cb9ec"),
    "public/javascript/embudo_comercial_v3_operativo.js":
        (None, "aef8ecf1ab601b559e142764cb0336470cbf103d"),
    # Cuatro archivos de CONSULTA y UI existentes con backup verificado.
    "app/services/EmbudoComercialRepository.php":
        ("f9f8c7b43a8c12e9a02ece1be1132431fa2fea5b",
         "ddd6611777cbc18d38673345fcf0dfbbee8ae8b5"),
    "app/views/embudo_comercial/detalle.php":
        ("e93e64c10088bb6351af3f0412b0d12cfee736a9",
         "715c3a36c890965f0343e33c89067f997aa4ba5a"),
    "app/controllers/EmbudoComercialController.php":
        ("a4342e88288321567487b20f0fa80c79564ad465",
         "b04fd2625a0e143949f83e98be5c0e3f2cc3f5b4"),
    # Habilitar el ENLACE opcional al final (no cambiar ruta predeterminada).
    "app/views/embudo_comercial/index.php":
        ("825b4c6af25450d4be21b47f8666955ada847eed",
         "0c5551ff346049e6136f310ff59e8784b9bdbc6a")
}
COMPROBAR_INTANGIBLES = {
    # IMPRESCINDIBLE: el piloto del Bridge y el arrastre actual continúan
    # exactamente como fueron publicados, sin habilitar contadores automáticos.
    "app/services/EmbudoComercialResultadoBridge.php":
        "6d990a8b12d4856c83e61dc67ad87b34e7767748",
    "app/services/EmbudoComercialMovimientoService.php":
        "9621a3e2a2b96d0c89ecd597a17c323c4ee09139",
    "app/services/TelefoniaResultadoVentasService.php":
        "1d4472ba73e7de4097ef2f592104160b254d7f8f",
    "app/services/TelefoniaResultadoVentasCatalogo.php":
        "6d5396e4dfb7a5c535ed5b8e964d93fab7a04a88",
    "public/javascript/embudo_comercial_movimientos.js":
        "a282167325a85f1ba153dc9b8c6b29092aa3a209",
    "public/css/embudo_comercial_compacto_v1.css":
        "c0ea09e7b35ee8c54d61f9386902bb8bcbac2ee4",
    "app/services/EmbudoComercialPoliticaPropuesta.php":
        "e5ec54f6fac872d09bf534a06debf7e38ee410c1",
    "app/services/EmbudoComercialV3IndicadoresVista.php":
        "c7419b2521392c48a0eebefb54bc9c7fd1cfda1c",
    "app/views/embudo_comercial/previsualizacion_v3.php":
        "04f1180034ea0f94ca78fb9eba87a36b96c45fe7",
    "public/css/embudo_v3_indicadores.css":
        "57ef9790d4868837e16807a0e56faf214c49219d"
}
# Orden seguro: primero dependencias, al final controlador/router.
ORDEN = list(ARCHIVOS)


class Bloqueo(RuntimeError):
    pass


def sha_blob_bytes(data):
    return hashlib.sha1(
        ("blob %d\0" % len(data)).encode("ascii") + data
    ).hexdigest()


def blob(path):
    with open(path, "rb") as fh:
        return sha_blob_bytes(fh.read())


def validar_raiz(path, debe_existir=True):
    root = os.path.realpath(os.path.abspath(path))
    if debe_existir and not os.path.isdir(root):
        raise Bloqueo("La carpeta requerida no existe.")
    if os.path.islink(path):
        raise Bloqueo("No se aceptan carpetas raíz enlazadas.")
    return root


def fuera_de_site(path, root):
    return path != root and not path.startswith(root + os.sep)


def ruta_confiable(root, rel):
    # La lista rel está fijada, no la modifica el navegador.
    current = root
    for part in rel.split("/"):
        current = os.path.join(current, part)
        if os.path.islink(current):
            raise Bloqueo("Ruta enlazada: " + rel)
    if not os.path.realpath(current).startswith(root + os.sep):
        raise Bloqueo("Ruta fuera del sitio: " + rel)
    return current


def verificar_php(path):
    try:
        subprocess.check_output(["php", "-l", path], stderr=subprocess.STDOUT)
    except (OSError, subprocess.CalledProcessError):
        raise Bloqueo("Error de sintaxis PHP: " + os.path.basename(path))


def validar_package(stage):
    for rel, (_, expected) in ARCHIVOS.items():
        path = ruta_confiable(stage, rel)
        if not os.path.isfile(path) or blob(path) != expected:
            raise Bloqueo("Paquete incorrecto o incompleto: " + rel)
        if rel.endswith(".php"):
            verificar_php(path)


def estado_site(site):
    estados = {}
    for rel, (before, after) in ARCHIVOS.items():
        path = ruta_confiable(site, rel)
        if not os.path.lexists(path):
            estados[rel] = "AUSENTE_NUEVO" if before is None else "FALTA_BASE"
            continue
        if not os.path.isfile(path):
            estados[rel] = "CONFLICTO"
            continue
        actual = blob(path)
        if actual == after:
            estados[rel] = "V3_LISTO"
        elif before is not None and actual == before:
            estados[rel] = "BASE_OK"
        else:
            estados[rel] = "CONFLICTO"
    for rel, sha in COMPROBAR_INTANGIBLES.items():
        path = ruta_confiable(site, rel)
        if not os.path.isfile(path) or blob(path) != sha:
            raise Bloqueo("Dependencia de telefonía/Kanban cambió: " + rel)
    es_base = all(estados[p] ==
                  ("AUSENTE_NUEVO" if ARCHIVOS[p][0] is None else "BASE_OK")
                  for p in ARCHIVOS)
    es_v3 = all(v == "V3_LISTO" for v in estados.values())
    for rel, status in estados.items():
        print("%s | %s" % (status, rel))
    if es_base:
        print("COMPATIBLE: preparado para publicar solo previsualización V3.")
        return "BASE"
    if es_v3:
        print("YA PUBLICADA: embudo V3 optativo para asesores exacta y compatible.")
        return "V3"
    raise Bloqueo(
        "Estado mixto o personalizado: sin publicación automática. "
        "No sobrescribir cambios de asesores."
    )


def escribir_atomico(destino, source, modo):
    carpeta = os.path.dirname(destino)
    fd, temp = tempfile.mkstemp(prefix=".impe-v3-", dir=carpeta)
    try:
        with os.fdopen(fd, "wb") as out:
            with open(source, "rb") as src:
                shutil.copyfileobj(src, out)
            out.flush()
            os.fsync(out.fileno())
        os.chmod(temp, modo)
        os.replace(temp, destino)
    finally:
        if os.path.exists(temp):
            os.unlink(temp)


def guardar_manifest(dir_backup, data):
    destino = os.path.join(dir_backup, "manifest.json")
    fd, tmp = tempfile.mkstemp(prefix=".manifest-", dir=dir_backup)
    try:
        with os.fdopen(fd, "w") as fh:
            json.dump(data, fh, indent=2, sort_keys=True)
            fh.flush()
            os.fsync(fh.fileno())
        os.chmod(tmp, 0o600)
        os.replace(tmp, destino)
    finally:
        if os.path.exists(tmp):
            os.unlink(tmp)


def respaldar(site, backup_root):
    if not os.path.isdir(backup_root):
        os.makedirs(backup_root, mode=0o700)
    if os.path.islink(backup_root):
        raise Bloqueo("Respaldo enlazado no permitido.")
    raiz = validar_raiz(backup_root)
    if not fuera_de_site(raiz, site):
        raise Bloqueo("El respaldo no puede estar dentro de public_html.")
    os.chmod(raiz, 0o700)
    ident = datetime.datetime.utcnow().strftime("%Y%m%d-%H%M%S") + "-" + uuid.uuid4().hex[:10]
    carpeta = os.path.join(raiz, ident)
    os.mkdir(carpeta, 0o700)
    originals = {}
    for rel, (expected, _) in ARCHIVOS.items():
        path = ruta_confiable(site, rel)
        if expected is None:
            originals[rel] = {"existed": False, "sha": None}
        else:
            if not os.path.isfile(path) or blob(path) != expected:
                raise Bloqueo("Cambió archivo entre comprobación y respaldo: " + rel)
            backup = os.path.join(carpeta, rel)
            parent = os.path.dirname(backup)
            if not os.path.isdir(parent):
                os.makedirs(parent, mode=0o700)
            shutil.copy2(path, backup)
            os.chmod(backup, 0o600)
            if blob(backup) != expected:
                raise Bloqueo("No se pudo verificar el respaldo: " + rel)
            originals[rel] = {
                "existed": True, "sha": expected,
                "mode": stat.S_IMODE(os.stat(path).st_mode)
            }
    data = {
        "version": 1, "revision": REPO_REV,
        "site": site, "fecha_utc": datetime.datetime.utcnow().isoformat()+"Z",
        "estado": "RESPALDADO", "archivos": originals
    }
    guardar_manifest(carpeta, data)
    return carpeta, data


def restaurar(site, backup_dir, data, verify_target=True):
    originals = data["archivos"]
    # Comprobar ANTES de restaurar: no pisar trabajo posterior de terceros.
    for rel, info in originals.items():
        dest = ruta_confiable(site, rel)
        _, target = ARCHIVOS[rel]
        if not os.path.lexists(dest):
            if info["existed"]:
                raise Bloqueo("Un archivo existente fue eliminado después del backup: "+rel)
        elif not os.path.isfile(dest):
            raise Bloqueo("Archivo no regular al restaurar: "+rel)
        else:
            current = blob(dest)
            allowed = [target]
            if not verify_target:
                allowed.append(info["sha"])
            if current not in allowed:
                raise Bloqueo("Archivo modificado por tercero: NO revertir "+rel)
        if info["existed"]:
            old = os.path.join(backup_dir, rel)
            if not os.path.isfile(old) or blob(old) != info["sha"]:
                raise Bloqueo("Backup inválido: "+rel)
    for rel in reversed(ORDEN):
        info = originals[rel]
        dest = ruta_confiable(site, rel)
        if info["existed"]:
            escribir_atomico(dest, os.path.join(backup_dir, rel), info["mode"])
        elif os.path.isfile(dest):
            os.unlink(dest)
    data["estado"] = "REVERTIDO"
    guardar_manifest(backup_dir, data)


def publicar(site, stage, backups):
    status = estado_site(site)
    if status == "V3":
        print("SIN CAMBIOS: la vista previa ya coincide con la versión autorizada.")
        return
    backup_dir, data = respaldar(site, backups)
    print("RESPALDO PRIVADO VERIFICADO: "+backup_dir)
    try:
        for rel in ORDEN:
            dest = ruta_confiable(site, rel)
            src = ruta_confiable(stage, rel)
            parent = os.path.dirname(dest)
            if not os.path.isdir(parent):
                raise Bloqueo("No existe carpeta de destino: "+rel)
            old = ARCHIVOS[rel][0]
            if old is None:
                if os.path.lexists(dest):
                    raise Bloqueo("Archivo nuevo apareció durante la publicación: "+rel)
                mode = 0o644
            else:
                if not os.path.isfile(dest) or blob(dest) != old:
                    raise Bloqueo("Archivo base cambió durante publicación: "+rel)
                mode = stat.S_IMODE(os.stat(dest).st_mode)
            escribir_atomico(dest, src, mode)
            if blob(dest) != ARCHIVOS[rel][1]:
                raise Bloqueo("Hash posterior no coincide: "+rel)
            if rel.endswith(".php"):
                verificar_php(dest)
            print("PUBLICADO: "+rel)
        if estado_site(site) != "V3":
            raise Bloqueo("Post-check V3 no coincide.")
        data["estado"] = "PUBLICADO"
        guardar_manifest(backup_dir, data)
        print("PUBLICACION OK: solo nueve archivos; el teléfono, "
              "el puente PBX, el arrastre y el V4 siguen intactos.")
        print("PARA REVERTIR: --rollback --backup-dir '"+backup_dir+"'")
    except Exception:
        try:
            restaurar(site, backup_dir, data, verify_target=False)
            print("REVERSION AUTOMATICA OK: se restauraron archivos originales.")
        except Exception as e:
            print("REVERSION NO COMPLETA: "+str(e), file=sys.stderr)
            print("BLOQUEADO: conservar backup privado; revisión manual.", file=sys.stderr)
        raise


def reversion(site, backup_dir):
    backup_dir=validar_raiz(backup_dir)
    with open(os.path.join(backup_dir,"manifest.json"),"r") as fh:
        data=json.load(fh)
    if data.get("site")!=site or data.get("revision")!=REPO_REV or \
            data.get("estado")!="PUBLICADO" or \
            set(data.get("archivos",{}))!=set(ARCHIVOS):
        raise Bloqueo("Manifest de respaldo no coincide con este sitio/publicación.")
    restaurar(site,backup_dir,data)
    print("REVERSION OK: estado previo restablecido; "
          "no se eliminaron registros de negocio.")


def main():
    parser=argparse.ArgumentParser(description="IMPE V3 embudo V3 operativo optativo, sólo asesor")
    parser.add_argument("--site",required=True)
    parser.add_argument("--stage")
    parser.add_argument("--backup-root")
    parser.add_argument("--backup-dir")
    parser.add_argument("--confirm")
    modes=parser.add_mutually_exclusive_group(required=True)
    modes.add_argument("--check",action="store_true")
    modes.add_argument("--publish",action="store_true")
    modes.add_argument("--rollback",action="store_true")
    a=parser.parse_args()
    site=validar_raiz(a.site)
    if not os.path.isfile(os.path.join(site, "config", "db_connection.php")):
        raise Bloqueo("Raíz de CRM inválida.")
    if a.check or a.publish:
        if not a.stage:
            raise Bloqueo("Falta carpeta de paquete verificado.")
        stage=validar_raiz(a.stage)
        if not fuera_de_site(stage,site):
            raise Bloqueo("Paquete no puede estar dentro del sitio público.")
        validar_package(stage)
        if a.check:
            estado_site(site)
        else:
            if a.confirm != CONFIRMACION or not a.backup_root:
                raise Bloqueo("Publicación requiere autorización y backup fuera del sitio.")
            publicar(site,stage,a.backup_root)
    else:
        if a.confirm != "REVERTIR_SOLO_EMBUDO_ASESOR_V3_IMPE" or not a.backup_dir:
            raise Bloqueo("Reversión requiere ruta exacta del respaldo y autorización.")
        reversion(site,a.backup_dir)


if __name__=="__main__":
    try:
        main()
    except (Bloqueo,OSError,ValueError) as e:
        print("BLOQUEADO: "+str(e), file=sys.stderr)
        sys.exit(2)
