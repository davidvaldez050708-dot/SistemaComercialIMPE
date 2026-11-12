#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Gestión Comercial V3: arrastre seguro y compactación incremental.
CHECK/APPLY/ROLLBACK protegidos por hashes Git y respaldos fuera de public_html.
Python 3.6+; sin SQL, sin escrituras PBX y sin modificar permisos/activación.
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

# Contrato base V3 ya publicado -> V3 con arrastre seguro.
# Tres archivos: una vista, un JS y su CSS, sin alterar asignaciones ni PBX.
ARCHIVOS = [
    ("public/javascript/embudo_comercial_v3_operativo.js",
     "aef8ecf1ab601b559e142764cb0336470cbf103d",
     "9234a6d1607b9028760d172aa7894fbc8f92c449"),
    ("public/css/embudo_v3_operativo.css",
     "fe0f7c3b414ed9e549ff172df0896a2f9b2ea19e",
     "6a853b84b9dce7ffa53298ff9e755ef00ac405e4"),
    ("app/views/embudo_comercial/operativo_v3.php",
     "3d7d26094e1b5c7133a1c904dfff67c13d3e33b6",
     "e306bb8995778c489c55735d78bafd9ef51832c5"),
]
NO_MODIFICAR = {
    "app/controllers/EmbudoComercialController.php":
      "b88ee8c9c961c7b930e605147d55230a159d956b",
    "app/services/EmbudoComercialRepository.php":
      "663afcf50ce374fd957da6d84282f8ef394b3cc6",
    "app/views/embudo_comercial/index.php":
      "766f3d06c510856f30b1b8ae1e376f686be74177",
    "app/services/EmbudoComercialV3AccesoControlado.php":
      "480ce33e3622eb44116c3aed578c0a753378eaa9",
    "app/services/EmbudoComercialV3MovimientosPermitidos.php":
      "92950fe72f02c938050c8598cd0ed96cd1552c6e",
    "app/services/EmbudoComercialMovimientoService.php":
      "9621a3e2a2b96d0c89ecd597a17c323c4ee09139",
    "app/services/EmbudoComercialPoliticaPropuesta.php":
      "e5ec54f6fac872d09bf534a06debf7e38ee410c1",
    "app/services/EmbudoComercialV3IndicadoresVista.php":
      "c7419b2521392c48a0eebefb54bc9c7fd1cfda1c",
}
CONFIRMAR = "PUBLICAR_ARRASTRE_SEGURO_V3_IMPE"
REVERTIR = "REVERTIR_ARRASTRE_SEGURO_V3_IMPE"

class Bloqueo(Exception):
    pass

def blob_bytes(b):
    return hashlib.sha1(("blob %d\0" % len(b)).encode("ascii") + b).hexdigest()

def leer(path):
    with open(path, "rb") as f:
        return f.read()

def blob(path):
    return blob_bytes(leer(path))

def raiz(path, required=True):
    if os.path.islink(path):
        raise Bloqueo("carpeta raíz enlazada: " + path)
    absolute = os.path.realpath(os.path.abspath(path))
    if required and not os.path.isdir(absolute):
        raise Bloqueo("carpeta no existe: " + path)
    return absolute

def dentro(path, base):
    return path == base or path.startswith(base + os.sep)

def confiable(base, relative):
    p = base
    for part in relative.split("/"):
        p = os.path.join(p, part)
        if os.path.islink(p):
            raise Bloqueo("ruta enlazada: " + relative)
    if not dentro(os.path.realpath(p), base):
        raise Bloqueo("ruta fuera de la carpeta: " + relative)
    return p

def lint_php(path):
    try:
        subprocess.check_output(["php", "-l", path], stderr=subprocess.STDOUT)
    except (OSError, subprocess.CalledProcessError):
        raise Bloqueo("sintaxis PHP no válida: " + path)

def validacion_contratos(stage):
    view = leer(confiable(stage, "app/views/embudo_comercial/operativo_v3.php"))
    js = leer(confiable(stage, "public/javascript/embudo_comercial_v3_operativo.js"))
    css = leer(confiable(stage, "public/css/embudo_v3_operativo.css"))
    contratos = [
      (b'data-v3-destinos=' in view, "destinos de la tarjeta"),
      (b'draggable="true"' in view, "draggable solo con propuestas"),
      (b'data-etapa="<?= $esc($clave) ?>"' in view, "etapa real de la columna"),
      (b'data-v3-mover' in view, "botón alternativo de mover"),
      (b'data-embudo-marcar' in view, "teléfono conservado"),
      (b'const destinosDe=' in js, "validación de destino cliente"),
      (b'const limpiarArrastre=' in js, "limpieza de arrastre"),
      (b'const abrir=(card,destinoSolicitado=' in js, "modal con destino solicitado"),
      (b'destinoValido(arrastre,etapa)' in js, "rechazo de destinos ajenos"),
      (b"lane.addEventListener('drop'" in js, "evento soltar"),
      (b"lane.addEventListener('dragover'" in js, "evento acercar"),
      (b'event.preventDefault()' in js, "prevención de arrastre inválido"),
      (b'index.php?controller=embudoComercial&action=moverEtapaV3' in js,
        "endpoint V3 original"),
      (b'X-CSRF-Token' in js, "CSRF V3 conservado"),
      (b'window.IMPE_TELEPHONY_PERSISTENT' in js, "teléfono persistente"),
      (b'.impe-v3o-columna.is-drop-target' in css, "resaltado de destino"),
      (b'.impe-v3o-card.is-dragging' in css, "estado tarjeta en movimiento"),
    ]
    for ok, desc in contratos:
        if not ok:
            raise Bloqueo("contrato V3 no verificado: " + desc)
    if b"moverEtapa\x27" in js or b"moverEtapa?" in js:
        raise Bloqueo("se detectó ruta de movimientos antigua.")
    print("CONTRATOS_DRAG_V3_OK: destinos, modal, PBX, CSRF e iconos.")

def lint_js(path):
    # Se usa Node si está disponible. El JavaScript no se ejecuta en check.
    node=shutil.which("node")
    if node is None:
        print("AVISO: Node.js ausente; se verifican contratos y hashes JS.")
        return
    try:
        subprocess.check_output([node,"--check",path],stderr=subprocess.STDOUT)
        print("SINTAXIS_JS_OK")
    except (OSError,subprocess.CalledProcessError):
        raise Bloqueo("sintaxis JS inválida: "+path)

def paquete(stage):
    for rel, old, new in ARCHIVOS:
        path = confiable(stage, rel)
        if not os.path.isfile(path) or blob(path) != new:
            raise Bloqueo("archivo incorrecto en stage: " + rel)
        if rel.endswith(".php"):
            lint_php(path)
        if rel.endswith(".js"):
            lint_js(path)
    validacion_contratos(stage)
    print("PAQUETE_DRAG_V3_OK: tres archivos exactos y contratos verificados.")

def validar_intangibles(site):
    for rel, expected in NO_MODIFICAR.items():
        path = confiable(site, rel)
        if not os.path.isfile(path) or blob(path) != expected:
            raise Bloqueo("dependencia protegida cambió: " + rel)

def estado(site):
    validar_intangibles(site)
    encontrados=[]
    for rel, old, new in ARCHIVOS:
        p=confiable(site, rel)
        if not os.path.isfile(p):
            raise Bloqueo("archivo base inexistente: " + rel)
        actual=blob(p)
        if actual == old:
            print("BASE_OK | " + rel)
            encontrados.append("BASE")
        elif actual == new:
            print("V3_UNIFICADA | " + rel)
            encontrados.append("READY")
        else:
            raise Bloqueo("personalización detectada; no sobrescribir: " + rel)
    if all(x == "BASE" for x in encontrados):
        print("COMPATIBLE: listo para publicar solo arrastre seguro V3.")
        return "BASE"
    if all(x == "READY" for x in encontrados):
        print("YA_PUBLICADA: arrastre seguro V3.")
        return "READY"
    raise Bloqueo("estado mixto; no publicar ni sobrescribir personalizaciones.")

def atomico(dest, source, mode):
    fd,tmp=tempfile.mkstemp(prefix=".impe-v3-compact-", dir=os.path.dirname(dest))
    try:
        with os.fdopen(fd,"wb") as out,open(source,"rb") as inp:
            shutil.copyfileobj(inp,out)
            out.flush()
            os.fsync(out.fileno())
        os.chmod(tmp,mode)
        os.replace(tmp,dest)
    finally:
        if os.path.isfile(tmp):
            os.unlink(tmp)

def manifest(dir_, data):
    path=os.path.join(dir_,"manifest.json")
    fd,tmp=tempfile.mkstemp(prefix=".manifest-v3-",dir=dir_)
    try:
        with os.fdopen(fd,"w") as f:
            json.dump(data,f,sort_keys=True,indent=2)
            f.flush()
            os.fsync(f.fileno())
        os.chmod(tmp,0o600)
        os.replace(tmp,path)
    finally:
        if os.path.isfile(tmp):
            os.unlink(tmp)

def respaldar(site, root):
    if not os.path.isdir(root):
        os.makedirs(root,0o700)
    root=raiz(root)
    if dentro(root,site):
        raise Bloqueo("respaldo no puede estar dentro de public_html")
    os.chmod(root,0o700)
    name=datetime.datetime.utcnow().strftime("%Y%m%d-%H%M%S")+"-"+uuid.uuid4().hex[:8]
    directory=os.path.join(root,name)
    os.mkdir(directory,0o700)
    modes={}
    for rel, old, new in ARCHIVOS:
        p=confiable(site,rel)
        if blob(p)!=old:
            raise Bloqueo("base cambió durante respaldo: "+rel)
        dst=os.path.join(directory,rel)
        parent=os.path.dirname(dst)
        if not os.path.isdir(parent):
            os.makedirs(parent,0o700)
        shutil.copy2(p,dst)
        os.chmod(dst,0o600)
        if blob(dst)!=old:
            raise Bloqueo("respaldo incorrecto: "+rel)
        modes[rel]=stat.S_IMODE(os.stat(p).st_mode)
    data={"version":1,"site":site,"fecha_utc":datetime.datetime.utcnow().isoformat()+"Z",
          "estado":"RESPALDADO","modes":modes}
    manifest(directory,data)
    return directory,data

def restaurar(site, directory, data, permit_old=False):
    # Inspección global previa: si algún archivo cambió por un tercero, no pisar.
    for rel,old,new in ARCHIVOS:
        dest=confiable(site,rel)
        backup=os.path.join(directory,rel)
        if not os.path.isfile(backup) or blob(backup)!=old:
            raise Bloqueo("respaldo alterado: "+rel)
        if not os.path.isfile(dest):
            raise Bloqueo("archivo de sitio desapareció: "+rel)
        validos=[new,old] if permit_old else [new]
        if blob(dest) not in validos:
            raise Bloqueo("archivo personalizado: no revertir "+rel)
    for rel,old,new in reversed(ARCHIVOS):
        dest=confiable(site,rel)
        atomico(dest,os.path.join(directory,rel),data["modes"][rel])
        if blob(dest)!=old:
            raise Bloqueo("falló revisión del respaldo restaurado: "+rel)
    data["estado"]="REVERTIDO"
    manifest(directory,data)

def publicar(site,stage,root):
    current=estado(site)
    if current=="READY":
        print("SIN_CAMBIOS: arrastre seguro V3 ya publicado.")
        return
    directory,data=respaldar(site,root)
    print("RESPALDO_PRIVADO_OK: "+directory)
    try:
        for rel,old,new in ARCHIVOS:
            dest=confiable(site,rel)
            src=confiable(stage,rel)
            if blob(dest)!=old:
                raise Bloqueo("base cambió antes de publicar: "+rel)
            mode=stat.S_IMODE(os.stat(dest).st_mode)
            atomico(dest,src,mode)
            if blob(dest)!=new:
                raise Bloqueo("integridad posterior incorrecta: "+rel)
            if rel.endswith(".php"):
                lint_php(dest)
            print("PUBLICADO: "+rel)
        if estado(site)!="READY":
            raise Bloqueo("comprobación posterior no concuerda.")
        data["estado"]="PUBLICADO"
        manifest(directory,data)
        print("APPLY_OK: arrastre seguro V3 y densidad compacta.")
        print("No se modificó SQL, PBX, V4, resultados, asignaciones ni permisos.")
        print("ROLLBACK_BACKUP: "+directory)
    except Exception:
        try:
            restaurar(site,directory,data,permit_old=True)
            print("REVERSION_AUTOMATICA_OK")
        except Exception as e:
            print("REVERSION_BLOQUEADA: "+str(e),file=sys.stderr)
        raise

def revertir(site,directory):
    directory=raiz(directory)
    with open(os.path.join(directory,"manifest.json"),"r") as f:
        data=json.load(f)
    if (data.get("site")!=site or data.get("estado")!="PUBLICADO" or
        set(data.get("modes",{}))!=set(rel for rel,_,_ in ARCHIVOS)):
        raise Bloqueo("manifest no pertenece a esta publicación.")
    restaurar(site,directory,data)
    print("ROLLBACK_OK: tablero V3 anterior restaurado; sin tocar registros.")

def main():
    p=argparse.ArgumentParser()
    p.add_argument("--site",required=True)
    p.add_argument("--stage")
    p.add_argument("--backup-root")
    p.add_argument("--backup-dir")
    p.add_argument("--confirm")
    m=p.add_mutually_exclusive_group(required=True)
    m.add_argument("--check",action="store_true")
    m.add_argument("--apply",action="store_true")
    m.add_argument("--rollback",action="store_true")
    a=p.parse_args()
    site=raiz(a.site)
    if not os.path.isfile(os.path.join(site,"config/db_connection.php")):
        raise Bloqueo("raíz del CRM inválida.")
    if a.rollback:
        if a.confirm!=REVERTIR or not a.backup_dir:
            raise Bloqueo("rollback requiere confirmación y respaldo exacto.")
        revertir(site,a.backup_dir)
        return
    if not a.stage:
        raise Bloqueo("falta stage.")
    stage=raiz(a.stage)
    if dentro(stage,site):
        raise Bloqueo("stage dentro de public_html.")
    paquete(stage)
    if a.check:
        status=estado(site)
        print("CHECK_OK: "+("listo para APPLY." if status=="BASE" else "ya aplicado."))
        return
    if a.confirm!=CONFIRMAR or not a.backup_root:
        raise Bloqueo("apply requiere confirmación y respaldo privado.")
    root=raiz(a.backup_root,False)
    if dentro(root,site):
        raise Bloqueo("respaldo dentro del sitio público.")
    publicar(site,stage,root)

if __name__=="__main__":
    try:
        main()
    except (Bloqueo,OSError,ValueError,subprocess.CalledProcessError) as ex:
        print("BLOQUEADO: "+str(ex),file=sys.stderr)
        sys.exit(2)
