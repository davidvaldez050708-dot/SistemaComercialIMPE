#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Ajuste visual puntual para Hostinger tras la primera publicación del Kanban.

--check: no escribe nada. --apply: respaldo de 3 archivos, conciliación estricta
y rollback automático ante error. No toca SQL, roles, permisos, teléfono ni motores.
Compatible con Python 3.6+.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import sys
import tempfile
import urllib.request
import subprocess
from datetime import datetime

REV = "3732fad6eb9a5dd866fda371c177b51ced88f2ab"
BASE = "https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/" + REV
VIEW = "app/views/embudo_comercial/index.php"
CTRL = "app/controllers/EmbudoComercialController.php"
SIDEBAR = "app/views/layout/sidebar.php"
# Comparación contra los archivos de la fase 1 ya publicada.
BLOBS = {
    VIEW: ("dcd8f3879bfc8eca544524b4fee2683a524206bc",
           "279f77446d4840fd2425510290c8a5490a77a3e1"),
    CTRL: ("a51277cb324f312a50a531efa4aa2327ab59b43f",
           "01e4565fbb8874ee080714955fafa41d907305fa"),
}

# Bloque exacto que insertó el publicador anterior en escritorio y móvil.
OLD_NAV = """        <?php if ($mostrarEmbudoComercial || $mostrarRecuperacionComercial || $mostrarDescartadosComercial): ?>
            <div class="sidebar-section">
                <p class="sidebar-section-title">COMERCIAL</p>
                <?php if ($mostrarEmbudoComercial): ?>
                <a href="<?= BASE_URL ?>index.php?controller=embudoComercial&amp;action=index&amp;vista=embudo"
                   class="sidebar-link <?= $seccionComercialActiva && $vistaComercialActual==='embudo'?'active':'' ?>">
                    <i class="bi bi-kanban" aria-hidden="true"></i> Embudo comercial
                </a>
                <?php endif; ?>
                <?php if ($mostrarRecuperacionComercial): ?>
                <a href="<?= BASE_URL ?>index.php?controller=embudoComercial&amp;action=index&amp;vista=recuperacion"
                   class="sidebar-link <?= $seccionComercialActiva && $vistaComercialActual==='recuperacion'?'active':'' ?>">
                    <i class="bi bi-arrow-repeat" aria-hidden="true"></i> Recuperación
                </a>
                <?php endif; ?>
                <?php if ($mostrarDescartadosComercial): ?>
                <a href="<?= BASE_URL ?>index.php?controller=embudoComercial&amp;action=index&amp;vista=descartados"
                   class="sidebar-link <?= $seccionComercialActiva && $vistaComercialActual==='descartados'?'active':'' ?>">
                    <i class="bi bi-archive" aria-hidden="true"></i> Descartados
                </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

"""

# Sin nuevo encabezado: en la instalación personalizada ya existe COMERCIAL.
# Un único acceso por menú, que respeta los tres permisos independientemente.
NEW_NAV = """        <?php if ($mostrarEmbudoComercial || $mostrarRecuperacionComercial || $mostrarDescartadosComercial): ?>
            <div class="sidebar-section">
                <a href="<?= BASE_URL ?>index.php?controller=embudoComercial&amp;action=index&amp;vista=<?= $mostrarEmbudoComercial?'embudo':($mostrarRecuperacionComercial?'recuperacion':'descartados') ?>"
                   class="sidebar-link <?= $seccionComercialActiva?'active':'' ?>">
                    <i class="bi bi-kanban" aria-hidden="true"></i> Gestión comercial
                </a>
            </div>
        <?php endif; ?>

"""

class Conflicto(Exception):
    pass

def blob(data):
    return hashlib.sha1(("blob %d\0" % len(data)).encode("ascii") + data).hexdigest()

def cargar_origen(path, root):
    if root:
        data = (root/path).read_bytes()
    else:
        with urllib.request.urlopen(BASE+"/"+path, timeout=25) as res:
            data=res.read()
    if blob(data) != BLOBS[path][1]:
        raise Conflicto("Integridad de versión corregida incorrecta: "+path)
    return data

def parchear_menu(original):
    try:
        text=original.decode("utf-8")
    except UnicodeError:
        raise Conflicto("sidebar no es UTF-8")
    vieja=text.count(OLD_NAV)
    nueva=text.count(NEW_NAV)
    if vieja==2 and nueva==0:
        patched=text.replace(OLD_NAV,NEW_NAV)
    elif vieja==0 and nueva==2:
        patched=text
    else:
        raise Conflicto("Sidebar no coincide con el menú instalado (antiguo={} nuevo={}). Sin modificar.".format(vieja,nueva))
    if patched.count("Gestión comercial")!=2:
        raise Conflicto("El menú no contiene una entrada única en escritorio y móvil")
    # No se cambia ni se elimina un encabezado COMERCIAL previo del sitio.
    if patched.replace(NEW_NAV,"").count("COMERCIAL") != text.replace(OLD_NAV,"").count("COMERCIAL"):
        raise Conflicto("Se alteró el encabezado institucional existente")
    return patched.encode("utf-8")

def lint(data, nombre):
    fd,p=tempfile.mkstemp(prefix="impe-menu-",suffix=".php")
    try:
        with os.fdopen(fd,"wb") as f:
            f.write(data)
        proc=subprocess.run(["php","-l",p],stdout=subprocess.PIPE,
                            stderr=subprocess.PIPE,universal_newlines=True)
        if proc.returncode:
            raise Conflicto("PHP inválido: {}: {}".format(nombre,(proc.stdout+proc.stderr)[:400]))
    finally:
        os.unlink(p)

def preparar(site,root):
    cambios={}
    for path in (SIDEBAR,VIEW,CTRL):
        target=site/path
        if not target.is_file() or target.is_symlink():
            raise Conflicto("Archivo del sistema no disponible o enlace: "+path)
        current=target.read_bytes()
        if path==SIDEBAR:
            proposed=parchear_menu(current)
        else:
            hash_actual=blob(current)
            old,new=BLOBS[path]
            if hash_actual==new:
                proposed=current
            elif hash_actual==old:
                proposed=cargar_origen(path,root)
            else:
                raise Conflicto("Archivo personalizado no conciliado: "+path+" (sin sobrescribir)")
        lint(proposed,path)
        if proposed!=current:
            cambios[path]=proposed
        print("{}: {}".format("COMPATIBLE" if proposed!=current else "APLICADO",path))
    return cambios

def publicar(site,backups,cambios):
    if not cambios:
        print("SIN CAMBIOS: ya está aplicado")
        return
    if not backups:
        raise Conflicto("--apply requiere --backup-root")
    if backups.resolve() == site.resolve() or site.resolve() in backups.resolve().parents:
        raise Conflicto("Respaldo debe quedar fuera del sitio")
    path=backups/("impe-menu-gestion-comercial-"+datetime.utcnow().strftime("%Y%m%d-%H%M%S")+"-"+str(os.getpid()))
    path.mkdir(parents=True,mode=0o700,exist_ok=False)
    manifest=[]
    for name,proposed in cambios.items():
        dest=site/name
        original=dest.read_bytes()
        backup=path/name
        backup.parent.mkdir(parents=True,exist_ok=True)
        shutil.copy2(str(dest),str(backup))
        manifest.append({
            "archivo":name,
            "sha256_antes":hashlib.sha256(original).hexdigest(),
            "sha256_despues":hashlib.sha256(proposed).hexdigest()
        })
    (path/"manifest.json").write_text(
        json.dumps(manifest,indent=2,ensure_ascii=False),encoding="utf-8"
    )
    publicados=[]
    try:
        for name,proposed in cambios.items():
            dest=site/name
            info=next(x for x in manifest if x["archivo"]==name)
            if hashlib.sha256(dest.read_bytes()).hexdigest()!=info["sha256_antes"]:
                raise Conflicto("Archivo cambió entre check y apply: "+name)
            fd,tmp=tempfile.mkstemp(prefix=".impe-menu-",dir=str(dest.parent))
            try:
                with os.fdopen(fd,"wb") as f:
                    f.write(proposed)
                    f.flush()
                    os.fsync(f.fileno())
                os.chmod(tmp,dest.stat().st_mode & 0o777)
                os.replace(tmp,str(dest))
                publicados.append(name)
                lint(dest.read_bytes(),name)
            finally:
                if os.path.exists(tmp):
                    os.unlink(tmp)
        print("APLICADO: {} archivos".format(len(publicados)))
        print("RESPALDO PRIVADO: {}".format(path))
    except Exception:
        for name in reversed(publicados):
            shutil.copy2(str(path/name),str(site/name))
        print("RESTAURADO TRAS ERROR: {}".format(path),file=sys.stderr)
        raise

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument("--site",required=True)
    ap.add_argument("--source-root")
    ap.add_argument("--backup-root")
    action=ap.add_mutually_exclusive_group(required=True)
    action.add_argument("--check",action="store_true")
    action.add_argument("--apply",action="store_true")
    args=ap.parse_args()
    site=Path(args.site).resolve()
    if not site.is_dir():
        raise Conflicto("No existe SITIO")
    root=Path(args.source_root).resolve() if args.source_root else None
    cambios=preparar(site,root)
    if args.check:
        print("CHECK COMPATIBLE: {} archivos, sin escrituras".format(len(cambios)))
        return
    if not args.backup_root:
        raise Conflicto("--apply requiere --backup-root")
    publicar(site,Path(args.backup_root).resolve(),cambios)
    if preparar(site,root):
        raise Conflicto("Post-check inesperado; revisar respaldo")
    print("CHECK POSTERIOR OK: menú único, pestañas internas sin SQL ni llamadas alteradas")

if __name__=="__main__":
    try:
        main()
    except (Conflicto,OSError,ValueError) as e:
        print("BLOQUEADO: {}".format(e),file=sys.stderr)
        sys.exit(2)
