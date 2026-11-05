#!/usr/bin/env python3
"""Publicador conservador: recuperar grabación CDR de Ventas sin OUT_END. Sin SQL.

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

REVISION = "8a119509928920163222985110d97a88afae8fed"
REPO = "davidvaldez050708-dot/SistemaComercialIMPE"
FILES = {
    "app/services/ZadarmaCallLookupService.php": (
        "blob:c5839eaeddec5c88bfd8ae72d5adda5d85fc84c4",
        "e91635ae104ddebc033c4e4a543203f3cec9b7ec"),
    "public/javascript/telefonia_ventas_resultados.js": (
        "blob:d7d90fb6d4067217dc67f961da0ce5fee4e457f7",
        "b56f620d80326f4d5aa501015ebd7de2a66a7bec"),
    "app/services/TelefoniaVentasGrabacionCdrService.php": (
        None,"61bb19490578975fd214bf4ab870de1bde480d4f"),
    "prueba_telefonia/api/grabacion_ventas_cdr.php": (
        None,"0e65f80351d9f63054599d411f3aaf475cc02d38"),
    "app/views/telefonia/ventas_llamadas.php": (
        "blob:dc5f719342bc1d66f23780a29bd73bc575649482",
        "94589abb38f46337f0a4ce827ee4cdfe6540d473"),
}
DEPS = {
    "app/controllers/TelefoniaController.php":
        "239330afb2d24c635735b772591d336270836569e4c8b0d8b9cbabac43e77fbe",
    "app/services/TelefoniaVentasEvidenciaService.php":
        "01b6e68744e90a1d2770823eb0c9f7deb683f5272ff4193d3c9eb85b27b253b4",
}
CUSTOM = {
    "app/views/telefonia/ventas_llamadas.php": {
        "base": "blob:dc5f719342bc1d66f23780a29bd73bc575649482",
        "edits": [
            [
                "  $audioTexto=$transferida?'Consultar grabaciones':'Consultar grabación';",
                "  $audioTexto=$transferida?'Consultar grabaciones':'Consultar grabación';\n  $audioCdrPropio=$ref!==''&&$call['direccion']==='Saliente'&&!$call['finalizada']&&!empty($call['conectada'])&&!empty($result)&&TelefoniaResultadoVentasService::esConversacion($code);"
            ],
            [
                "<?php if(TelefoniaVentasLlamadasService::audioConsultable($call)): ?>",
                "<?php if(TelefoniaVentasLlamadasService::audioConsultable($call)||$audioCdrPropio): ?>"
            ],
            [
                "data-sales-audio=\"<?= $esc($ref) ?>\" data-extension=\"<?= $esc($call['extension']) ?>\"",
                "data-sales-audio=\"<?= $esc($ref) ?>\" data-extension=\"<?= $esc($call['extension']) ?>\" data-sales-cdr-pending=\"<?= $audioCdrPropio?'1':'0' ?>\""
            ]
        ]
    }
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
        p=subprocess.run(command+[name],stdout=subprocess.PIPE,stderr=subprocess.PIPE,universal_newlines=True)
        if p.returncode:
            raise RuntimeError("Sintaxis no compatible en "+rel+": "+p.stderr.strip())
    finally:
        os.unlink(name)

def same_version(data, pin):
    if data is None: return False
    if pin.startswith("blob:"): return blob(data)==pin[5:]
    if pin.startswith("sha256:"): return digest(data)==pin[7:]
    raise RuntimeError("Huella de archivo no reconocida.")

def reconcile_custom(rel, value):
    conf=CUSTOM[rel]
    if value is None:
        raise RuntimeError("VARIANTE HOSTINGER AUSENTE (sin cambios): "+rel)
    original=conf["base"]
    replacements=[(a.encode("utf-8"), b.encode("utf-8"))
                  for a,b in conf["edits"]]
    if same_version(value,original):
        final=value
        for a,b in replacements:
            if final.count(a)!=1 or final.count(b):
                raise RuntimeError("CONFLICTO DE BLOQUES (sin cambios): "+rel)
            final=final.replace(a,b,1)
        return final,"CONCILIADO PERSONALIZADO"
    # Verificación reversible: sólo reconoce exactamente nuestros cambios
    # sobre el archivo original, no versiones arbitrarias ni ediciones posteriores.
    reverted=value
    for a,b in reversed(replacements):
        if reverted.count(b)!=1 or reverted.count(a):
            raise RuntimeError("VARIANTE DESCONOCIDA (sin cambios): "+rel)
        reverted=reverted.replace(b,a,1)
    if not same_version(reverted,original):
        raise RuntimeError("VARIANTE DESCONOCIDA (sin cambios): "+rel)
    return value,"YA CONCILIADO PERSONALIZADO"

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
    problems=[]
    for rel,(before,afterblob) in FILES.items():
        try:
            path=target(site,rel)
            data=fetch(rel,afterblob)
            old=path.read_bytes() if path.exists() else None
            if rel in CUSTOM:
                data,status=reconcile_custom(rel,old)
            else:
                if old is None and before is not None:
                    raise RuntimeError("ARCHIVO AUSENTE (sin cambios): "+rel)
                if old is not None and before is None and old!=data:
                    raise RuntimeError("ARCHIVO NUEVO YA PERSONALIZADO (sin cambios): "+rel)
                if old is not None and old!=data and not same_version(old,before):
                    raise RuntimeError("VERSIÓN DIFERENTE (sin cambios): "+rel)
                status="YA INSTALADO" if old==data else "NUEVO" if old is None else "COMPATIBLE"
            # Comprobar el candidato fusionado, no sólo la versión remota.
            syntax(rel,data)
            print(status+":",rel)
            plan.append((rel,old,data))
        except Exception as ex:
            reason=str(ex)
            print("INCOMPATIBLE:",rel,"-",reason)
            problems.append(rel+": "+reason)
    if problems:
        raise RuntimeError("SIN PUBLICAR: "+str(len(problems))+
                           " incompatibilidad(es): "+" | ".join(problems))
    print("PREPUBLICACIÓN COMPATIBLE:",sum(x[1]!=x[2] for x in plan),"archivos; grabaciones históricas protegidas. Sin SQL.")
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
    print("APLICADO: grabación CDR propia sin OUT_END; controller, audio histórico y resultados intactos. Sin SQL.")
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
