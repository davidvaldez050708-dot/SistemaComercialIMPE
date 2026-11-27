#!/usr/bin/env python3
"""Selective publication, exact hashes, private backup. Python 3.6+."""
import argparse
import fcntl
import json
import os
import shutil
import stat
import subprocess
import sys
import tempfile
from pathlib import Path
from comprobar_recepcion import candidate, blob, check_schema, ROOT

FILES = [
    ("app/models/WhatsAppModel.php", "0ed455ba900aadb664ffc0c11872c6336391c732", "12657dd1950871b3ebe675451563311afa314dbe"),
    ("app/controllers/WhatsAppWebhookController.php", "9c0a7972125fb51784b0b0e8cb9d7038e8e263a1", "3828bc93f56f3cb92b7d26a9c5f53fe6e2518d7c"),
]
MANIFEST = json.loads((ROOT / "recepcion_v1.json").read_text(encoding="utf-8"))

def outside(path, site):
    p = path.resolve()
    if p == site or site in p.parents:
        raise ValueError("ruta privada dentro del sitio")
    return p

def target(site, name):
    p = site / name
    if p.is_symlink() or p.resolve() != p or not p.is_file():
        raise ValueError("ruta de archivo insegura: " + name)
    return p

def inspect(site):
    states = []
    for name, before, after in FILES:
        p = target(site, name)
        data = p.read_bytes()
        sha = blob(data)
        if sha == before:
            result = candidate(data, MANIFEST[name])
            if blob(result) != after:
                raise ValueError("candidato no coincide: " + name)
            states.append((p, data, result, before, after))
        elif sha == after:
            states.append((p, data, data, before, after))
        else:
            raise ValueError("archivo personalizado o modificado: " + name)
    return states

def lint(data):
    with tempfile.NamedTemporaryFile(suffix=".php") as f:
        f.write(data)
        f.flush()
        r = subprocess.run(["php", "-l", f.name], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        if r.returncode:
            raise ValueError("sintaxis PHP no valida")

def replace(p, expected, data):
    if p.read_bytes() != expected:
        raise ValueError("cambio concurrente: " + str(p))
    st = p.stat()
    fd, name = tempfile.mkstemp(prefix=".wa-rx-", dir=str(p.parent))
    try:
        with os.fdopen(fd, "wb") as f:
            f.write(data)
            f.flush()
            os.fsync(f.fileno())
            current = os.fstat(f.fileno())
            if (current.st_uid, current.st_gid) != (st.st_uid, st.st_gid):
                os.fchown(f.fileno(), st.st_uid, st.st_gid)
            os.fchmod(f.fileno(), stat.S_IMODE(st.st_mode))
        if p.read_bytes() != expected:
            raise ValueError("cambio concurrente antes de reemplazo")
        os.replace(name, str(p))
    finally:
        if os.path.exists(name):
            os.unlink(name)

def restore(site, backup, quiet=False):
    backup = outside(backup, site)
    record = json.loads((backup/"backup.json").read_text(encoding="utf-8"))
    if record != {"site": str(site), "release": "whatsapp-recepcion-v1", "files": [list(x) for x in FILES]}:
        raise ValueError("respaldo de otro sitio o version")
    pending = []
    for name, before, after in reversed(FILES):
        p = target(site, name)
        src = backup/name
        if src.is_symlink() or backup not in src.resolve().parents:
            raise ValueError("ruta de respaldo insegura")
        old = src.read_bytes()
        current = p.read_bytes()
        if blob(old) != before or blob(current) not in (before, after):
            raise ValueError("respaldo alterado o cambio posterior: " + name)
        lint(old)
        pending.append((p,current,old))
    for p,current,old in pending:
        if current != old:
            replace(p,current,old)
    for name,before,after in FILES:
        if blob(target(site,name).read_bytes()) != before:
            raise ValueError("reversion incompleta")
    if not quiet:
        print("RESTORE_OK | 2 archivos originales | datos conservados")

def apply(site, backup_root, schema=True, writer=replace):
    states=inspect(site)
    for p,old,new,before,after in states:
        lint(new)
    if schema:
        check_schema(site)
    if all(blob(old)==after for p,old,new,before,after in states):
        print("YA_APLICADO | 2 archivos")
        return None
    if not all(blob(old)==before for p,old,new,before,after in states):
        raise ValueError("estado mixto; recuperar respaldo previo antes de continuar")
    backup=Path(tempfile.mkdtemp(prefix="whatsapp-recepcion-v1-",dir=str(backup_root)))
    os.chmod(str(backup),0o700)
    for p,old,new,before,after in states:
        dst=backup/p.relative_to(site)
        dst.parent.mkdir(parents=True,exist_ok=True)
        dst.write_bytes(old)
        os.chmod(str(dst),0o600)
        if blob(dst.read_bytes())!=before:
            raise ValueError("respaldo incorrecto")
    (backup/"backup.json").write_text(json.dumps({"site":str(site),"release":"whatsapp-recepcion-v1","files":FILES}),encoding="utf-8")
    print("RESPALDO | "+str(backup),flush=True)
    try:
        for p,old,new,before,after in states:
            if p.read_bytes()!=old:
                raise ValueError("cambio concurrente antes de publicar")
        for p,old,new,before,after in states:
            writer(p,old,new)
        for p,old,new,before,after in states:
            if blob(p.read_bytes())!=after:
                raise ValueError("verificacion posterior incorrecta")
    except BaseException:
        restore(site,backup)
        raise
    print("APPLY_OK | 2 archivos | sin DDL, DML ni envios")
    return backup

def self_test(site, private):
    states=inspect(site)
    if not all(blob(old)==before for p,old,new,before,after in states):
        raise ValueError("self-test requiere la base original")
    with tempfile.TemporaryDirectory(prefix="wa-rx-prueba-",dir=str(private)) as tmp:
        root=Path(tmp).resolve()
        copy=root/"site"
        backups=root/"backups"
        backups.mkdir()
        for p,old,new,before,after in states:
            dst=copy/p.relative_to(site)
            dst.parent.mkdir(parents=True,exist_ok=True)
            dst.write_bytes(old)
        b=apply(copy,backups,schema=False)
        if apply(copy,backups,schema=False) is not None:
            raise ValueError("repeticion no idempotente")
        p=copy/FILES[0][0]
        saved=p.read_bytes()
        p.write_bytes(saved+b"\n")
        try:
            restore(copy,b,quiet=True)
        except ValueError:
            pass
        else:
            raise ValueError("restauracion acepto cambio posterior")
        if p.read_bytes()!=saved+b"\n":
            raise ValueError("restauracion altero cambio posterior")
        p.write_bytes(saved)
        restore(copy,b,quiet=True)
        count=[0]
        def fail_second(p,old,new):
            count[0]+=1
            if count[0]==2:
                raise OSError("fallo sintetico")
            replace(p,old,new)
        try:
            apply(copy,backups,schema=False,writer=fail_second)
        except OSError:
            pass
        else:
            raise ValueError("fallo sintetico no detectado")
        if not all(blob(target(copy,n).read_bytes())==b for n,b,a in FILES):
            raise ValueError("fallo parcial no revertido")
        print("SELF_TEST_OK | aplicacion, repeticion, conflicto, restauracion y fallo parcial en copia privada")

def main():
    os.umask(0o077)
    p=argparse.ArgumentParser()
    p.add_argument("--site",required=True)
    p.add_argument("--backup-root",required=True)
    mode=p.add_mutually_exclusive_group(required=True)
    mode.add_argument("--check",action="store_true")
    mode.add_argument("--apply",action="store_true")
    mode.add_argument("--restore")
    p.add_argument("--confirm")
    args=p.parse_args()
    site=Path(args.site).resolve()
    outside(ROOT,site)
    private=outside(Path(args.backup_root),site)
    if not private.is_dir() or stat.S_IMODE(private.stat().st_mode)&0o077:
        raise ValueError("directorio de respaldo debe existir y tener permisos 700")
    lock=private/".whatsapp-recepcion-v1.lock"
    with lock.open("a") as f:
        fcntl.flock(f,fcntl.LOCK_EX|fcntl.LOCK_NB)
        if args.restore:
            if args.confirm!="REVERTIR_RECEPCION_WHATSAPP_V1":
                raise ValueError("confirmacion de reversion requerida")
            restore(site,Path(args.restore))
            return
        states=inspect(site)
        for path,old,new,before,after in states:
            lint(new)
            print(("COMPATIBLE" if blob(old)==before else "YA_APLICADO")+" | "+str(path.relative_to(site))+" | "+blob(new))
        check_schema(site)
        if all(blob(old)==before for path,old,new,before,after in states):
            self_test(site,private)
        elif not all(blob(old)==after for path,old,new,before,after in states):
            raise ValueError("estado mixto; no publicar")
        print("CHECK_OK | sin cambios al sitio ni base de datos")
        if args.apply:
            if args.confirm!="PUBLICAR_RECEPCION_WHATSAPP_V1":
                raise ValueError("confirmacion de publicacion requerida")
            apply(site,private)

if __name__=="__main__":
    try:
        main()
    except Exception as e:
        print("BLOQUEADO | "+str(e),file=sys.stderr)
        sys.exit(2)
