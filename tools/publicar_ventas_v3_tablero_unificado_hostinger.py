#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Gestión Comercial V3: tablero unificado y tarjetas compactas.
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

# Antes -> después, ambos hashes Git blob. Orden: dependencia, vista, router.
ARCHIVOS = [
    ("app/services/EmbudoComercialRepository.php",
     "ddd6611777cbc18d38673345fcf0dfbbee8ae8b5",
     "663afcf50ce374fd957da6d84282f8ef394b3cc6"),
    ("public/css/embudo_v3_operativo.css",
     "f68fec01b9d87d9290ae9e58723f006e7b2cb9ec",
     "fe0f7c3b414ed9e549ff172df0896a2f9b2ea19e"),
    ("app/views/embudo_comercial/operativo_v3.php",
     "ed44384ebaf967c5161ad5eb5f2734554ea587ce",
     "3d7d26094e1b5c7133a1c904dfff67c13d3e33b6"),
    ("app/views/embudo_comercial/index.php",
     "0c5551ff346049e6136f310ff59e8784b9bdbc6a",
     "766f3d06c510856f30b1b8ae1e376f686be74177"),
    ("app/controllers/EmbudoComercialController.php",
     "b04fd2625a0e143949f83e98be5c0e3f2cc3f5b4",
     "b88ee8c9c961c7b930e605147d55230a159d956b"),
]
NO_MODIFICAR = {
    "app/services/EmbudoComercialV3AccesoControlado.php":
      "480ce33e3622eb44116c3aed578c0a753378eaa9",
    "app/services/EmbudoComercialV3MovimientosPermitidos.php":
      "92950fe72f02c938050c8598cd0ed96cd1552c6e",
    "app/services/EmbudoComercialMovimientoService.php":
      "9621a3e2a2b96d0c89ecd597a17c323c4ee09139",
    "public/javascript/embudo_comercial_v3_operativo.js":
      "aef8ecf1ab601b559e142764cb0336470cbf103d",
    "app/services/EmbudoComercialPoliticaPropuesta.php":
      "e5ec54f6fac872d09bf534a06debf7e38ee410c1",
    "app/services/EmbudoComercialV3IndicadoresVista.php":
      "c7419b2521392c48a0eebefb54bc9c7fd1cfda1c",
}
CONFIRMAR = "PUBLICAR_TABLERO_UNICO_V3_IMPE"
REVERTIR = "REVERTIR_TABLERO_UNICO_V3_IMPE"

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
    repo = leer(confiable(stage, "app/services/EmbudoComercialRepository.php"))
    ctl = leer(confiable(stage, "app/controllers/EmbudoComercialController.php"))
    view = leer(confiable(stage, "app/views/embudo_comercial/operativo_v3.php"))
    old = leer(confiable(stage, "app/views/embudo_comercial/index.php"))
    # Contratos con valor funcional: no reemplazar operatividad con CSS.
    if (b"clasificacionV3OperativoSql()" not in repo or
        b"WHEN e.etapa='INTERESADO' THEN 'POTENCIAL'" not in repo or
        b"WHEN e.etapa='PAGO_CONFIRMADO' THEN 'PROCESO_INSCRIPCION'" not in repo or
        b"clasificacionV3Sql()" not in repo or
        b"($_GET['tablero_anterior']??null)!=='1'" not in ctl or
        b"public function moverEtapaV3()" not in ctl or
        b"data-embudo-marcar=" not in view or
        b"data-v3-mover" not in view or
        b"aria-label=\"Llamar al prospecto\"" not in view or
        b"aria-label=\"Mis llamadas y resultados\"" not in view or
        b"aria-label=\"Ver ficha e historial\"" not in view or
        b"aria-label=\"Cambiar etapa con motivo\"" not in view or
        b"Nuevo embudo V3" in old):
        raise Bloqueo("contrato funcional de V3 compacta no verificado.")
    if b"<span>Llamar</span>" in view or b"<span>Mover</span>" in view:
        raise Bloqueo("se esperaban acciones sólo por iconos.")

def paquete(stage):
    for rel, old, new in ARCHIVOS:
        path = confiable(stage, rel)
        if not os.path.isfile(path) or blob(path) != new:
            raise Bloqueo("archivo incorrecto en stage: " + rel)
        if rel.endswith(".php"):
            lint_php(path)
    validacion_contratos(stage)
    print("PAQUETE_V3_OK: 5 archivos exactos, PHP válido, contratos de interfaz OK.")

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
        print("COMPATIBLE: listo para publicar solo tablero unificado V3.")
        return "BASE"
    if all(x == "READY" for x in encontrados):
        print("YA_PUBLICADA: V3 unificada y compacta.")
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
        print("SIN_CAMBIOS: ya publicada la V3 unificada.")
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
        print("APPLY_OK: tablero V3 principal y tarjetas compactas.")
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
