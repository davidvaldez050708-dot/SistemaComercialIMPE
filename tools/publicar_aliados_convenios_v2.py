#!/usr/bin/env python3
"""Publicador selectivo V2, Python 3.6+. --check no cambia el sitio ni ejecuta SQL.

Reconciliación a tres bandas sobre V1 revisada, con respaldo/restore de archivos.
Ante personalizaciones solapadas detiene TODO antes de escribir en public_html.
"""
import argparse
import base64
import datetime
import difflib
import gzip
import hashlib
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import sys
import tempfile
from urllib.error import HTTPError
from urllib.request import Request, urlopen

# Commit inmutable revisado ANTES de este publicador. Nunca usar HEAD/main flotante.
SOURCE = "2f57c1c29e1a838941530ba5ef6fde9d1b3af033"
OLD = "bc8e5c1611e7bff62fc91e12af7166e9dc36861d"
ROOT_URL = "https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/"
SHAS = {
    "app/controllers/AliadoRegistroActions.php": "5288d4bd7acc5fc48400637b12179c49271610d9",
    "app/controllers/ConvocatoriaController.php": "311729f2b562c44ed2ced541203aa3b8d2fda41b",
    "app/models/ConvocatoriaModel.php": "30bcde21608d42afeea11e9642a00b71327a3c37",
    "app/services/AliadoRegistroService.php": "207e894f57a4e8078020b30f1f960c17d39bf349",
    "app/views/aliados/estado.php": "6406d315faf1d16c0d7269b2beb7ed91e53104cd",
    "app/views/aliados/partials/registro_gestion.php": "51a775839ceda6b5fda0edc654cc272bc5c6e946",
    "app/views/convocatorias/crear_desde_convenio.php": "3c81e8e8b5bac9949fb8129700909066aba08046",
    "app/views/convocatorias/index.php": "a99399d125b11e4b74bc46baa5e725a4691dda6e",
    "app/views/layout/dashboard_head.php": "e776ec4b00ca63c7bb9a93a80a73e41f36e358b1",
    "database/migrations/2026_10_22_aliados_manuales_contactos_difusion.sql": "bcaeffa1f60a88582eba17e16929c3020d47ad9f",
    "database/migrations/2026_10_22_marketing_convocatorias_aliados_manuales.sql": "393d5555bb9045e3d861ced699214f899467416d",
    "public/css/aliados.css": "efa0cd5daafde9e44840c45ea6b0ce7ac04ad1d0",
    "public/css/convocatorias.css": "524544a8cfc3d885ea54652fb0c31e90285da159",
    "public/css/crear_convocatoria.css": "81b17891c723ad17baf9c6948092cbc9ba3b83fc",
    "public/javascript/aliados_registro.js": "eafbb8ebfe992fb4d4a7302690b891a331c1b5b8",
    "public/javascript/convocatorias_aliado_programas.js": "2991e0b05ca50f1abea0c33bed8e1773d2b034f3",
    "public/javascript/crear_convocatoria.js": "8f813ba726349f046156e920f4cff7f37b6289ea",
    "tools/migrar_extensiones_aliados_20261022.php": "996363da0611098cb4ec67e6055ad45d5bac5f66"
}
def blob(data):
    return hashlib.sha1(("blob %d\0" % len(data)).encode("ascii") + data).hexdigest()
def sha(data):
    return hashlib.sha256(data).hexdigest()
def lf(data):
    return data.replace(b"\r\n", b"\n")
def get(rev,path,missing=False):
    if not path or ".." in Path(path).parts or path.startswith("/"):
        raise RuntimeError("Ruta insegura")
    url=ROOT_URL + rev + "/" + path
    try:
        with urlopen(Request(url,headers={"User-Agent":"IMPE-aliados-v2"}),timeout=35) as req:
            data=req.read()
    except HTTPError as exc:
        if missing and exc.code==404: return None
        raise
    return data
def sitepath(site,name):
    p=site/name
    if p.is_symlink() or not (p.resolve()==site or site in p.resolve().parents):
        raise RuntimeError("Ruta/enlace inseguro: "+name)
    return p
def unpack(value):
    return gzip.decompress(base64.b64decode(value))
def merge(local,base,new,stage):
    if local is None:
        if base is not None: raise RuntimeError("Archivo previo ausente; no se puede instalar")
        return new
    if lf(local)==lf(new):return local
    if base is None:raise RuntimeError("Archivo nuevo modificado; CONFLICTO")
    if lf(local)==lf(base):merged=lf(new)
    else:
        for path,data in [("local",local),("base",base),("nuevo",new)]:
            (stage/path).write_bytes(lf(data))
        result=subprocess.run(["git","merge-file","-p",str(stage/"local"),str(stage/"base"),str(stage/"nuevo")],
            stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        if result.returncode:
            raise RuntimeError("CONFLICTO en cambios personalizados; el sitio permanece intacto")
        merged=result.stdout
    return merged.replace(b"\n",b"\r\n") if b"\r\n" in local else merged
def atomic(path,data,mode=0o644):
    path.parent.mkdir(parents=True,exist_ok=True)
    fd,tmp=tempfile.mkstemp(prefix=".aliados-v2-",dir=str(path.parent))
    try:
        os.fchmod(fd,mode)
        with os.fdopen(fd,"wb") as f:
            f.write(data);f.flush();os.fsync(f.fileno())
        os.replace(tmp,str(path))
    finally:
        if os.path.exists(tmp):os.unlink(tmp)
def validate_php_js(path,data,stage):
    suffix=Path(path).suffix
    if suffix not in (".php",".js"):return
    temp=stage/("validar"+suffix)
    temp.write_bytes(data)
    command=["php","-l",str(temp)] if suffix==".php" else ["node","--check",str(temp)]
    if suffix==".js" and not shutil.which("node"):
        raise RuntimeError("Se requiere Node.js para validar los JS de esta entrega")
    result=subprocess.run(command,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    if result.returncode:
        raise RuntimeError("Sintaxis inválida en "+path+": "+result.stderr.decode("utf-8","replace")[:500])
def read_old():
    release=json.loads(get(SOURCE,"tools/aliados_registro_release.json").decode("utf-8"))
    if release.get("source")!=OLD:raise RuntimeError("Manifest V1 inesperado")
    return {entry["path"]:entry for entry in release["files"]}
def load_base(name,old):
    row=old.get(name)
    return unpack(row["target"]) if row else get(OLD,name,missing=True)
def prepare(site,stage,verbose=False):
    old=read_old()
    plan=[]
    for name,expected in SHAS.items():
        new=get(SOURCE,name)
        if blob(new)!=expected:raise RuntimeError("SHA inesperado en "+name)
        base=load_base(name,old)
        path=sitepath(site,name)
        current=path.read_bytes() if path.is_file() else None
        try:result=merge(current,base,new,stage)
        except RuntimeError as e:raise RuntimeError(name+": "+str(e))
        if result==current:
            print("SIN CAMBIOS: "+name)
            continue
        validate_php_js(name,result,stage)
        plan.append((name,path,current,result,stat.S_IMODE(path.stat().st_mode) if current is not None else 0o644))
        print("PREPARADO: "+name)
        if verbose and current is not None and name.endswith((".php",".js",".css",".sql")):
            diff=difflib.unified_diff(lf(current).decode("utf-8","replace").splitlines(),
                lf(result).decode("utf-8","replace").splitlines(),fromfile=name+" actual",tofile=name+" candidato",lineterm="")
            print("\n".join(diff))
    return plan
def restore(site,backup,stamp):
    info=json.loads((backup/"respaldo.json").read_text(encoding="utf-8"))
    if info.get("site")!=str(site) or info.get("source")!=SOURCE:raise RuntimeError("Respaldo de otra entrega/sitio")
    for item in info["files"]:
        p=sitepath(site,item["path"])
        if not p.is_file() or sha(p.read_bytes())!=item["installed"]:
            raise RuntimeError("No se restaura: archivo modificado después de la publicación: "+item["path"])
        original=backup/"original"/item["path"]
        if item["old"] is not None and (not original.is_file() or sha(original.read_bytes())!=item["old"]):
            raise RuntimeError("Respaldo alterado: "+item["path"])
    for item in reversed(info["files"]):
        p=sitepath(site,item["path"]);old=item["old"]
        if old is None:p.unlink()
        else:atomic(p,(backup/"original"/item["path"]).read_bytes(),item["mode"])
    if stamp.exists():
        try:
            meta=json.loads(stamp.read_text(encoding="utf-8"))
            if meta.get("backup")==str(backup):stamp.unlink()
        except Exception:pass
    print("RESTAURADO: únicamente archivos V2. No se ha modificado la BD.")
def main():
    parser=argparse.ArgumentParser(description=__doc__)
    modes=parser.add_mutually_exclusive_group(required=True)
    modes.add_argument("--check",action="store_true")
    modes.add_argument("--apply",action="store_true")
    modes.add_argument("--restore",metavar="RESPALDO_EXACTO")
    parser.add_argument("--site",required=True)
    parser.add_argument("--backup-root",default=str(Path.home()/"respaldos-impe-aliados"))
    parser.add_argument("--diff",action="store_true")
    args=parser.parse_args()
    site=Path(args.site).resolve()
    if not (site/"index.php").is_file():raise RuntimeError("No parece public_html válido")
    private=Path(args.backup_root).resolve()
    if private==site or site in private.parents:raise RuntimeError("Los respaldos deben estar fuera de public_html")
    stamp=private/("aliados-v2-"+SOURCE[:12]+".json")
    if args.restore:
        backup=Path(args.restore).resolve()
        if site==backup or site in backup.parents:raise RuntimeError("Respaldo dentro del sitio")
        restore(site,backup,stamp);return
    if not shutil.which("git") or not shutil.which("php") or not shutil.which("node"):
        raise RuntimeError("Se requieren git, PHP y Node.js para publicar")
    if stamp.is_file():
        recorded=json.loads(stamp.read_text(encoding="utf-8"))
        if recorded.get("site")==str(site) and recorded.get("source")==SOURCE:
            current_ok=all(sitepath(site,p).is_file() and sha(sitepath(site,p).read_bytes())==h
                for p,h in recorded["installed"].items())
            if current_ok:
                print("SIN CAMBIOS: V2 verificada con hash de todos los archivos instalados.");return
            raise RuntimeError("CONFLICTO: archivo V2 cambió después del despliegue; revisar/restaurar")
    private.mkdir(parents=True,exist_ok=True)
    os.chmod(str(private),0o700)
    with tempfile.TemporaryDirectory(prefix="aliados-v2-revision-",dir=str(private)) as temp:
        stage=Path(temp)
        plan=prepare(site,stage,args.diff)
        print("REVISIÓN COMPATIBLE: %d cambios. No se ejecutó SQL." % len(plan))
        if args.check or not plan:
            print("SITIO INTACTO" if args.check else "SIN CAMBIOS");return
        for name,p,original,candidate,mode in plan:
            now=p.read_bytes() if p.is_file() else None
            if original!=now:raise RuntimeError("Cambio concurrente: "+name)
        backup=private/("aliados-convenios-v2-"+datetime.datetime.utcnow().strftime("%Y%m%d-%H%M%S-%f"))
        backup.mkdir(mode=0o700)
        info={"site":str(site),"source":SOURCE,"files":[]}
        for name,p,original,candidate,mode in plan:
            if original is not None:
                saved=backup/"original"/name;saved.parent.mkdir(parents=True,exist_ok=True);saved.write_bytes(original);os.chmod(str(saved),0o600)
            info["files"].append({"path":name,"old":sha(original) if original is not None else None,
                                  "installed":sha(candidate),"mode":mode})
        meta=backup/"respaldo.json";meta.write_text(json.dumps(info,indent=2),encoding="utf-8");os.chmod(str(meta),0o600)
        completed=[]
        try:
            for name,p,original,candidate,mode in plan:
                if (p.read_bytes() if p.is_file() else None)!=original:raise RuntimeError("Cambio concurrente: "+name)
                atomic(p,candidate,mode);completed.append((p,original,mode))
                if sha(p.read_bytes())!=sha(candidate):raise RuntimeError("Fallo de integridad: "+name)
            record={"site":str(site),"source":SOURCE,"backup":str(backup),
                    "installed":{name:sha(candidate) for name,p,old,candidate,mode in plan}}
            tmp=stamp.with_suffix(".tmp");atomic(tmp,json.dumps(record).encode("utf-8"),0o600);os.replace(str(tmp),str(stamp))
        except Exception:
            for p,old,mode in reversed(completed):
                if old is None:p.unlink()
                else:atomic(p,old,mode)
            raise
        print("PUBLICADO: V2. Respaldo privado: "+str(backup))
        print("PENDIENTE: migraciones explícitas, pruebas funcionales en CRM. BD intacta.")
if __name__=="__main__":
    try:main()
    except Exception as exc:
        print("ERROR: "+str(exc),file=sys.stderr);sys.exit(1)
