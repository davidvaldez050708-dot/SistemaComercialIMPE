#!/usr/bin/env python3
"""Publicación selectiva del cierre por API de Ventas. Nunca ejecuta SQL.

Sólo admite las versiones verificadas de Hostinger; preserva los servicios
de llamadas y evidencia de telefonía. Incluye --check, --apply y --restore.
"""
import argparse
import datetime
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import urllib.request

REVISION = "6f9b6156954ba5504529a0fc18837076a20ad82f"
REPO = "davidvaldez050708-dot/SistemaComercialIMPE"
FILES = {
    "app/services/TelefoniaResultadoVentasService.php": (
        "f4c79730ed7ae641c58cdcbd654a169950fb6ee864bb0ea6ec2be13626f6c305",
        "3b25a3f5dae103b4d195fb619cde714f72977367"),
    "public/javascript/telefonia_ventas_resultados.js": (
        "8f7983d9b387c17a2581ad1ef2153abe1dd434291045285b96d4fc8e62058e19",
        "4735f7aaa556dfc93d24c10166e02137c676f016"),
    "app/services/TelefoniaVentasCierreApiVerificado.php": (
        None, "b60f6a57006392fa2002ca879ea42e8528080f0b"),
}
DEPS = {
    "app/services/TelefoniaVentasLlamadasService.php":
        "46bece86dcfdc3b3f2434276440644c71de9b206bf34b90848fc9de7ce18a0dd",
    "app/services/TelefoniaVentasEvidenciaService.php":
        "01b6e68744e90a1d2770823eb0c9f7deb683f5272ff4193d3c9eb85b27b253b4",
}
def digest(data):
    return hashlib.sha256(data).hexdigest()

def blob(data):
    return hashlib.sha1(b"blob "+str(len(data)).encode()+b"\0"+data).hexdigest()

def target(root, rel):
    path=root/rel
    if path.is_symlink() or os.path.commonpath([str(root.resolve()),str(path.resolve())]) != str(root.resolve()):
        raise RuntimeError("Ruta insegura: "+rel)
    return path

def fetch(rel, sha):
    url=f"https://raw.githubusercontent.com/{REPO}/{REVISION}/{rel}"
    with urllib.request.urlopen(url,timeout=20) as response:
        data=response.read(1000000)
    if len(data)>999999 or blob(data)!=sha:
        raise RuntimeError("Integridad Git no coincide: "+rel)
    return data

def syntax(rel, data):
    if rel.endswith(".php"):
        php=shutil.which("php")
        if not php:
            raise RuntimeError("PHP CLI requerido para comprobar sintaxis.")
        suffix=".php"
        command=[php,"-l"]
    elif rel.endswith(".js"):
        node=shutil.which("node")
        if not node:
            print("AVISO: Node.js ausente; JavaScript verificado por CI de GitHub.")
            return
        suffix=".js"
        command=[node,"--check"]
    else:
        return
    with tempfile.NamedTemporaryFile(prefix="impe-ventas-check-",suffix=suffix,delete=False) as tmp:
        tmp.write(data)
        name=tmp.name
    try:
        p=subprocess.run(command+[name],stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
        if p.returncode:
            raise RuntimeError("Sintaxis no compatible en "+rel+": "+p.stderr.strip())
    finally:
        os.unlink(name)

def save_atomic(path, data, mode=0o644):
    path.parent.mkdir(parents=True,exist_ok=True)
    handle,name=tempfile.mkstemp(dir=path.parent,prefix=".impe-ventas-")
    try:
        with os.fdopen(handle,"wb") as fd:
            fd.write(data)
            fd.flush()
            os.fsync(fd.fileno())
        os.chmod(name,mode)
        os.replace(name,path)
    finally:
        if os.path.exists(name):os.unlink(name)

def check(site):
    if not (site/"index.php").is_file():
        raise RuntimeError("La ruta del sitio no corresponde a public_html.")
    for rel,expected in DEPS.items():
        path=target(site,rel)
        if not path.is_file() or digest(path.read_bytes())!=expected:
            raise RuntimeError("DEPENDENCIA DIFERENTE (sin cambios): "+rel)
        print("DEPENDENCIA COMPATIBLE:",rel)
    plan=[]
    for rel,(before,afterblob) in FILES.items():
        path=target(site,rel)
        data=fetch(rel,afterblob)
        syntax(rel,data)
        old=path.read_bytes() if path.exists() else None
        if old is None and before is not None:
            raise RuntimeError("ARCHIVO AUSENTE (sin cambios): "+rel)
        if old is not None and before is None and old!=data:
            raise RuntimeError("ARCHIVO NUEVO YA PERSONALIZADO (sin cambios): "+rel)
        if old is not None and old!=data and digest(old)!=before:
            raise RuntimeError("VERSIÓN DIFERENTE (sin cambios): "+rel)
        status="YA INSTALADO" if old==data else "NUEVO" if old is None else "COMPATIBLE"
        print(status+":",rel)
        plan.append((rel,old,data))
    print("REVISIÓN COMPATIBLE:",sum(x[1]!=x[2] for x in plan),"archivos. Sin SQL.")
    return plan

def apply(site,backup_root):
    plan=check(site)
    pending=[x for x in plan if x[1]!=x[2]]
    if not pending:
        print("YA INSTALADO: no hay cambios.");return
    backup_root.mkdir(mode=0o700,parents=True,exist_ok=True)
    name=datetime.datetime.now().strftime("%Y%m%d-%H%M%S-")+os.urandom(4).hex()
    backup=backup_root/name
    backup.mkdir(mode=0o700)
    manifest={"site":str(site.resolve()),"revision":REVISION,"files":[]}
    for rel,old,new in pending:
        path=target(site,rel)
        item={"path":rel,"before_sha256":digest(old) if old is not None else None,
              "after_sha256":digest(new),"mode":(path.stat().st_mode&0o777) if old else 0o644}
        if old is not None:
            copy=backup/rel;copy.parent.mkdir(parents=True,exist_ok=True)
            copy.write_bytes(old);os.chmod(copy,0o600)
        manifest["files"].append(item)
    (backup/"respaldo.json").write_text(json.dumps(manifest,indent=2),encoding="utf-8")
    os.chmod(backup/"respaldo.json",0o600)
    installed=[]
    try:
        for rel,old,new in pending:
            path=target(site,rel)
            current=path.read_bytes() if path.exists() else None
            if current!=old:
                raise RuntimeError("CAMBIO CONCURRENTE: "+rel)
            mode=(path.stat().st_mode&0o777) if old else 0o644
            save_atomic(path,new,mode)
            installed.append((rel,old,mode))
    except BaseException:
        for rel,old,mode in reversed(installed):
            path=target(site,rel)
            if old is None:
                if path.exists():path.unlink()
            else:save_atomic(path,old,mode)
        raise
    print("APLICADO: verificación de cierre saliente Zadarma sólo al guardar; sin SQL.")
    print("RESPALDO:",backup)

def restore(site,path):
    manifest_path=path/"respaldo.json"
    if not manifest_path.is_file():raise RuntimeError("Respaldo no encontrado.")
    m=json.loads(manifest_path.read_text(encoding="utf-8"))
    if m.get("site")!=str(site.resolve()) or m.get("revision")!=REVISION:
        raise RuntimeError("El respaldo no pertenece a este sitio/versión.")
    actions=[]
    for f in m["files"]:
        rel=f["path"]
        if rel not in FILES: raise RuntimeError("Ruta inesperada en respaldo.")
        dest=target(site,rel)
        if not dest.is_file() or digest(dest.read_bytes())!=f["after_sha256"]:
            raise RuntimeError("VERSIÓN DIFERENTE, restauración cancelada: "+rel)
        original=None
        if f["before_sha256"] is not None:
            original=(path/rel).read_bytes()
            if digest(original)!=f["before_sha256"]:
                raise RuntimeError("Respaldo corrupto: "+rel)
        actions.append((dest,original,int(f["mode"])))
    for dest,original,mode in actions:
        if original is None:dest.unlink()
        else:save_atomic(dest,original,mode)
    print("RESTAURADO: sin SQL ni grabaciones afectadas.")

def main():
    p=argparse.ArgumentParser()
    p.add_argument("--site",required=True)
    p.add_argument("--backup-root",default=str(Path.home()/"respaldos-impe-ventas-cierre-api"))
    g=p.add_mutually_exclusive_group(required=True)
    g.add_argument("--check",action="store_true")
    g.add_argument("--apply",action="store_true")
    g.add_argument("--restore",metavar="CARPETA")
    args=p.parse_args()
    site=Path(args.site).expanduser().resolve()
    if args.restore:restore(site,Path(args.restore).expanduser().resolve())
    elif args.apply:apply(site,Path(args.backup_root).expanduser())
    else:check(site);print("SITIO INTACTO")

if __name__=="__main__":
    try:main()
    except Exception as ex:
        print("ERROR:",ex)
        raise SystemExit(1)
