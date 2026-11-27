#!/usr/bin/env python3
"""Selective deployment of the exact WhatsApp V2 candidate. Python 3.6+."""
import argparse
import fcntl
import json
import os
import stat
import sys
import tempfile
from pathlib import Path
from check import HERE, M, blob, build

RELEASE = "whatsapp-avisos-v2"
# Dependencies first; only expose the topbar once everything else exists.
FILES = [
    ("app/services/WhatsAppAvisosService.php", None, "94e85bc206080dedad6bd2d467ab150eb502fef5"),
    ("app/views/layout/whatsapp_avisos_globales.php", None, "27e75f35715255c8da30e8da91c004633e8df09e"),
    ("public/css/whatsapp_avisos_globales.css", None, "da355126a6ca6e9de1006df670742f81f19c57b7"),
    ("public/javascript/whatsapp_avisos_globales.js", None, "f2137c7c2f3844accc02c5a3331bc02d85f20674"),
    ("app/controllers/WhatsAppController.php", "419c54921a65c7fdfda8653a2c2209e638118397", "4e4abbf09f5c3c657c5d0ae21b013151381489bf"),
    ("public/javascript/recordatorios.js", "16d593cc7524bc91befcf45deb872c39dc5a3158", "5200a76bc6ec477cf2c62c30dfe0b91622187598"),
    ("public/javascript/whatsapp_contextual.js", "f916582fc46c7332aa95ab7e27ac5196b8aa1b8e", "84cdcc24b8515f3aa8c9795cc33fff6fda9a1dc2"),
    ("app/views/layout/topbar.php", "53f70bfec09d2b8e8a0fe6ff372ffb96e21711a3", "c7989f8851df941210693e2cff30b551c31a2e59"),
]

def outside(p, site):
    p=p.resolve()
    if p==site or site in p.parents:
        raise ValueError("ruta privada dentro del sitio")
    return p

def target(site,name):
    p=site/name
    if p.resolve()!=p or p.is_symlink() or not p.parent.is_dir():
        raise ValueError("ruta insegura: "+name)
    if p.exists() and not p.is_file():
        raise ValueError("no es archivo regular: "+name)
    return p

def current(p):
    return p.read_bytes() if p.exists() else None

def digest(data):
    return None if data is None else blob(data)

def guards(site):
    for name,sha in M["guards"].items():
        if digest(current(target(site,name)))!=sha:
            raise ValueError("dependencia modificada: "+name)

def inspect(site):
    guards(site)
    states=[]
    for name,before,after in FILES:
        p=target(site,name)
        data=current(p)
        if digest(data) not in (before,after):
            raise ValueError("archivo personalizado o alterado: "+name)
        states.append((name,p,data,before,after))
    if all(digest(d)==a for n,p,d,b,a in states):
        return None
    if not all(digest(d)==b for n,p,d,b,a in states):
        raise ValueError("estado mixto: recuperar respaldo anterior")
    candidates=dict(build(site))
    if set(candidates)!={x[0] for x in FILES}:
        raise ValueError("conjunto de publicacion inesperado")
    for n,p,d,b,a in states:
        if digest(candidates[n])!=a or current(p)!=d:
            raise ValueError("candidato distinto del validado: "+n)
    return [(n,p,d,candidates[n]) for n,p,d,b,a in states]

def replace(p,expected,data):
    if p.is_symlink() or current(p)!=expected:
        raise ValueError("cambio concurrente: "+str(p))
    if data is None:
        if expected is None:
            return
        p.unlink()
        return
    st=p.stat() if expected is not None else None
    fd,tmp=tempfile.mkstemp(prefix=".wa-avisos-",dir=str(p.parent))
    try:
        with os.fdopen(fd,"wb") as f:
            f.write(data)
            f.flush()
            os.fsync(f.fileno())
            if st is not None:
                actual=os.fstat(f.fileno())
                if (actual.st_uid,actual.st_gid)!=(st.st_uid,st.st_gid):
                    os.fchown(f.fileno(),st.st_uid,st.st_gid)
                os.fchmod(f.fileno(),stat.S_IMODE(st.st_mode))
            else:
                os.fchmod(f.fileno(),0o644)
        if p.is_symlink() or current(p)!=expected:
            raise ValueError("cambio concurrente antes de reemplazo")
        if expected is None:
            # Atomic creation without clobbering a new file from another process.
            os.link(tmp,str(p))
        else:
            os.replace(tmp,str(p))
    finally:
        if os.path.exists(tmp):
            os.unlink(tmp)

def record(site):
    return {"site":str(site),"release":RELEASE,"files":[list(x) for x in FILES]}

def restore(site,backup,quiet=False):
    backup=outside(backup,site)
    if json.loads((backup/"backup.json").read_text(encoding="utf-8"))!=record(site):
        raise ValueError("respaldo de otro sitio o version")
    pending=[]
    # Remove entry point first, dependencies last.
    for name,before,after in reversed(FILES):
        p=target(site,name)
        data=current(p)
        src=backup/name
        if before is None:
            if src.exists() or src.is_symlink():
                raise ValueError("respaldo inesperado: "+name)
            old=None
        else:
            if src.is_symlink() or backup not in src.resolve().parents:
                raise ValueError("ruta de respaldo insegura")
            old=src.read_bytes()
        if digest(old)!=before or digest(data) not in (before,after):
            raise ValueError("respaldo alterado o cambio posterior: "+name)
        pending.append((p,data,old))
    for p,data,old in pending:
        if data!=old:
            replace(p,data,old)
    for name,before,after in FILES:
        if digest(current(target(site,name)))!=before:
            raise ValueError("reversion incompleta")
    if not quiet:
        print("RESTORE_OK | 4 originales restaurados | 4 nuevos retirados")

def apply(site,private,writer=replace):
    states=inspect(site)
    if states is None:
        print("YA_APLICADO | 8 archivos")
        return None
    backup=Path(tempfile.mkdtemp(prefix=RELEASE+"-",dir=str(private)))
    for n,p,old,new in states:
        if old is None:
            continue
        dest=backup/n
        dest.parent.mkdir(parents=True,exist_ok=True)
        dest.write_bytes(old)
        if dest.read_bytes()!=old:
            raise ValueError("respaldo incorrecto")
    (backup/"backup.json").write_text(json.dumps(record(site)),encoding="utf-8")
    print("RESPALDO | "+str(backup),flush=True)
    try:
        guards(site)
        for n,p,old,new in states:
            if current(p)!=old:
                raise ValueError("cambio concurrente antes de publicar: "+n)
        for n,p,old,new in states:
            writer(p,old,new)
        guards(site)
        for n,b,a in FILES:
            if digest(current(target(site,n)))!=a:
                raise ValueError("verificacion posterior incorrecta: "+n)
    except BaseException:
        restore(site,backup)
        raise
    print("APPLY_OK | 8 archivos | sin migraciones, envios ni cambios de permisos")
    return backup

def self_test(site,private):
    states=inspect(site)
    if states is None:
        raise ValueError("prueba privada requiere base original")
    with tempfile.TemporaryDirectory(prefix="wa-avisos-prueba-",dir=str(private)) as tmp:
        root=Path(tmp).resolve()
        copy=root/"site"
        backups=root/"backups"
        backups.mkdir()
        for name in list(M["guards"])+[x[0] for x in FILES]:
            dest=copy/name
            dest.parent.mkdir(parents=True,exist_ok=True)
            old=current(target(site,name))
            if old is not None:
                dest.write_bytes(old)
        b=apply(copy,backups)
        if apply(copy,backups) is not None:
            raise ValueError("repeticion no idempotente")
        p=target(copy,FILES[-1][0])
        saved=p.read_bytes()
        p.write_bytes(saved+b"\n")
        try:
            restore(copy,b,quiet=True)
        except ValueError:
            pass
        else:
            raise ValueError("restauracion acepto cambio posterior")
        if p.read_bytes()!=saved+b"\n":
            raise ValueError("restauracion piso cambio posterior")
        p.write_bytes(saved)
        restore(copy,b,quiet=True)
        count=[0]
        def fail_last(p,old,new):
            count[0]+=1
            if count[0]==len(FILES):
                raise OSError("fallo sintetico antes de exponer topbar")
            replace(p,old,new)
        try:
            apply(copy,backups,writer=fail_last)
        except OSError:
            pass
        else:
            raise ValueError("fallo sintetico no detectado")
        for n,b,a in FILES:
            if digest(current(target(copy,n)))!=b:
                raise ValueError("fallo parcial no revertido: "+n)
        guards(copy)
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
    outside(HERE,site)
    private=outside(Path(args.backup_root),site)
    if not private.is_dir() or stat.S_IMODE(private.stat().st_mode)&0o077:
        raise ValueError("directorio privado debe existir con permisos 700")
    with (private/".whatsapp-publicacion.lock").open("a") as lock:
        fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        if args.restore:
            if args.confirm!="REVERTIR_AVISOS_WHATSAPP_V2":
                raise ValueError("confirmacion de reversion requerida")
            restore(site,Path(args.restore))
            return
        states=inspect(site)
        if states is not None:
            self_test(site,private)
        print("CHECK_OK | sin cambios al sitio ni base de datos")
        if args.apply:
            if args.confirm!="PUBLICAR_AVISOS_WHATSAPP_V2":
                raise ValueError("confirmacion de publicacion requerida")
            apply(site,private)

if __name__=="__main__":
    try:
        main()
    except Exception as e:
        print("BLOQUEADO | "+str(e),file=sys.stderr)
        sys.exit(2)
