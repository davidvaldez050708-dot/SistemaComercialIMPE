#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Publicación conservadora del Kanban IMPE.
--check jamás modifica el sitio; --apply requiere que todos los tests pasen.
Python 3.6+ (Hostinger). Los PHP anteriores nunca se sustituyen completos:
se insertan rutas/enlaces preservando personalizaciones locales.
"""
import argparse
import datetime
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
import urllib.request

REV = "c37723f05426bc734d99573a27d9457ca8fbc90f"
BASE = "https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/" + REV
BLOBS = {
    "app/controllers/EmbudoComercialController.php": "a51277cb324f312a50a531efa4aa2327ab59b43f",
    "app/helpers/EmbudoComercialAcceso.php": "43f1d22e16ba0b98d27c5fbcb767e8570765df33",
    "app/services/EmbudoComercialRepository.php": "45c12542b42a0dbaeee282e7196defef999852e7",
    "app/views/embudo_comercial/index.php": "dcd8f3879bfc8eca544524b4fee2683a524206bc",
    "app/views/embudo_comercial/detalle.php": "d945736b309ebe546d7cba13b9beabb44a3698ba",
    "public/css/embudo_comercial.css": "acb7c428900aedcf2f50a564c0886742921927c9"
}
SQL_PATH = "database/migrations/2026_11_08_ventas_embudo_kanban_fase1.sql"
SQL_BLOB = "0f3eb2d23c5b5ee965cb227e6be07700195a3d23"
ROUTER = "index.php"
SIDEBAR = "app/views/layout/sidebar.php"

class Conflicto(Exception):
    pass

def blob(b):
    return hashlib.sha1(("blob %d\0" % len(b)).encode("ascii") + b).hexdigest()

def unico(text, needle, reemplazo, descripcion):
    n = text.count(needle)
    if n != 1:
        raise Conflicto("{}: marcador esperado una vez; encontrado {}".format(descripcion, n))
    return text.replace(needle, reemplazo, 1)

def insert_once(text, needle, addition, sentinel, desc):
    if sentinel in text:
        if text.count(sentinel) != 1:
            raise Conflicto("{}: inserción repetida".format(desc))
        if addition not in text:
            raise Conflicto("{}: inserción parcialmente personalizada".format(desc))
        return text
    return unico(text, needle, addition + needle, desc)

def patch_router(text):
    marker = "    // Mi cartera nunca reutiliza las rutas administrativas de Prospectos."
    addition = """    // Embudo comercial: autorización propia por vista y por asesor.
    case 'embudoComercial':

        require_once __DIR__ .
            '/app/controllers/EmbudoComercialController.php';

        $controllerInstance =
            new EmbudoComercialController();

        break;


"""
    text = insert_once(text, marker, addition,
                       "case 'embudoComercial':", "router del embudo")
    start = text.find("$controladoresProtegidosCsrf = [")
    if start < 0:
        raise Conflicto("Router: falta lista de CSRF")
    end = text.find("];", start)
    if end < 0:
        raise Conflicto("Router: bloque CSRF incompleto")
    block = text[start:end]
    if "'embudoComercial'" not in block:
        if block.count("'telefonia'") != 1:
            raise Conflicto("Router: marcador de telefonía en CSRF ambiguo")
        block = block.replace("'telefonia'", "'telefonia',\n    'embudoComercial'")
        text = text[:start] + block + text[end:]
    elif block.count("'embudoComercial'") != 1:
        raise Conflicto("Router: CSRF duplicado")
    return text

NAV = """        <?php if ($mostrarEmbudoComercial || $mostrarRecuperacionComercial || $mostrarDescartadosComercial): ?>
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

def patch_sidebar(text):
    require = "require_once __DIR__ . '/../../helpers/EmbudoComercialAcceso.php';"
    if text.count(require) == 0:
        text=unico(
            text,
            "require_once __DIR__ . '/../../helpers/PermissionHelper.php';",
            "require_once __DIR__ . '/../../helpers/PermissionHelper.php';\n"+require,
            "sidebar includes"
        )
    elif text.count(require)!=1:
        raise Conflicto("Sidebar: require duplicado")

    var="""$mostrarEmbudoComercial = EmbudoComercialAcceso::puede('embudo');
$mostrarRecuperacionComercial = EmbudoComercialAcceso::puede('recuperacion');
$mostrarDescartadosComercial = EmbudoComercialAcceso::puede('descartados');
$vistaComercialActual = (string)($_GET['vista']??'embudo');
$seccionComercialActiva = $opcionActiva === 'embudo_comercial';
"""
    if "$mostrarEmbudoComercial =" not in text:
        pattern = r"(\$mostrarMiCartera\s*=\s*[\s\S]{0,240}?tienePermiso\('prospectos\.mi_cartera'\)\s*;)"
        m = re.search(pattern,text)
        if not m or len(re.findall(pattern,text)) != 1:
            raise Conflicto("Sidebar: definición actual de Mi cartera no reconocida")
        text=text[:m.end()]+"\n"+var.rstrip()+text[m.end():]
    elif text.count("$mostrarEmbudoComercial =") != 1 or var not in text:
        raise Conflicto("Sidebar: estado de permisos parcialmente modificado")

    original="<?php if ($mostrarMiCartera): ?>"
    guarded="<?php if ($mostrarMiCartera && !$mostrarEmbudoComercial && !$mostrarRecuperacionComercial && !$mostrarDescartadosComercial): ?>"
    # Nunca eliminar ni reemplazar el HTML de Mi cartera personalizado.
    n=text.count(original)
    if n:
        if n!=2 or text.count(guarded):
            raise Conflicto("Sidebar: bloques Mi cartera no coinciden")
        text=text.replace(original, NAV+guarded)
    else:
        if text.count(guarded)!=2 or text.count(NAV)!=2:
            raise Conflicto("Sidebar: menú parcialmente modificado o distinto")
    return text

def read_source(path, source_root):
    if source_root:
        data=(source_root/path).read_bytes()
    else:
        with urllib.request.urlopen(BASE+"/"+path, timeout=25) as res:
            data=res.read()
    expected = BLOBS.get(path, SQL_BLOB if path==SQL_PATH else "")
    if blob(data) != expected:
        raise Conflicto("No coincide la integridad del origen: {}".format(path))
    return data

def php_lint(data, name):
    fd, tmp = tempfile.mkstemp(suffix=".php",prefix="impe-embudo-lint-")
    try:
        with os.fdopen(fd,"wb") as f:
            f.write(data)
        out=subprocess.run(["php","-l",tmp], stdout=subprocess.PIPE,
                           stderr=subprocess.PIPE, universal_newlines=True)
        if out.returncode != 0:
            raise Conflicto("Fallo de sintaxis {}: {}".format(name,(out.stderr+out.stdout)[:260]))
    finally:
        os.unlink(tmp)

def planificar(site, source_root):
    if not site.is_dir():
        raise Conflicto("La ruta del sitio no es un directorio")
    if not (site/"app/helpers/PermissionHelper.php").is_file():
        raise Conflicto("Falta PermissionHelper de producción")
    if not (site/"app/helpers/AdminRolePolicy.php").is_file():
        raise Conflicto("Falta AdminRolePolicy de producción")
    # Prohibido alterar la configuración de la distribución.
    src={}
    for path in BLOBS:
        src[path]=read_source(path,source_root)
    read_source(SQL_PATH,source_root)
    changes={}
    for path,data in src.items():
        target=site/path
        if target.exists() and target.read_bytes()!=data:
            raise Conflicto("Archivo de módulo previamente personalizado: {}".format(path))
        if not target.exists():
            changes[path]=data
        if path.endswith(".php"):
            php_lint(data,path)
    for path,fn in ((ROUTER,patch_router),(SIDEBAR,patch_sidebar)):
        target=site/path
        if not target.is_file():
            raise Conflicto("Falta archivo institucional: {}".format(path))
        existing=target.read_bytes()
        try:
            decoded=existing.decode("utf-8")
        except UnicodeError:
            raise Conflicto("Archivo institucional no UTF-8: {}".format(path))
        if "\r\n" in decoded:
            raise Conflicto("EOL CRLF en archivo personalizado: {}".format(path))
        proposed=fn(decoded).encode("utf-8")
        php_lint(proposed,path)
        if existing!=proposed: changes[path]=proposed
    return changes

def apply(site, root, changes):
    if not root:
        raise Conflicto("Para aplicar se necesita --backup-root")
    if root.resolve()==site.resolve() or site.resolve() in root.resolve().parents:
        raise Conflicto("El respaldo debe estar fuera de public_html")
    stamp=datetime.datetime.utcnow().strftime("%Y%m%d-%H%M%S")
    backup=root/("impe-embudo-fase1-"+stamp+"-"+str(os.getpid()))
    backup.mkdir(parents=True,mode=0o700,exist_ok=False)
    entries=[]
    for path,data in changes.items():
        target=site/path
        previous=target.read_bytes() if target.exists() else None
        row={"path":path,"existia":previous is not None,
             "antes_sha256":hashlib.sha256(previous).hexdigest() if previous is not None else None,
             "despues_sha256":hashlib.sha256(data).hexdigest()}
        if previous is not None:
            original=backup/path
            original.parent.mkdir(parents=True,exist_ok=True)
            shutil.copy2(str(target),str(original))
        entries.append(row)
    (backup/"manifest.json").write_text(
        json.dumps({"revision":REV,"archivos":entries},indent=2,ensure_ascii=False),
        encoding="utf-8"
    )
    touched=[]
    try:
        # Primero PHP independientes, después menú y enrutador.
        sequence=[k for k in changes if k not in (ROUTER,SIDEBAR)]+[k for k in (SIDEBAR,ROUTER) if k in changes]
        for path in sequence:
            dst=site/path
            dst.parent.mkdir(parents=True,exist_ok=True)
            if dst.exists():
                row=next(x for x in entries if x["path"]==path)
                if hashlib.sha256(dst.read_bytes()).hexdigest()!=row["antes_sha256"]:
                    raise Conflicto("Cambió durante la operación: {}".format(path))
            fdesc, temp=tempfile.mkstemp(prefix=".impe-embudo-",dir=str(dst.parent))
            try:
                with os.fdopen(fdesc,"wb") as f:
                    f.write(changes[path])
                    f.flush()
                    os.fsync(f.fileno())
                if dst.exists():
                    os.chmod(temp,dst.stat().st_mode & 0o777)
                else:
                    os.chmod(temp,0o644)
                os.replace(temp,str(dst))
            finally:
                if os.path.exists(temp): os.unlink(temp)
            touched.append(path)
            if path.endswith(".php"):
                with dst.open("rb") as f: php_lint(f.read(),path)
        print("PUBLICADO: {} archivos; sin SQL ejecutado".format(len(touched)))
        print("RESPALDO PRIVADO: {}".format(backup))
    except Exception:
        for path in reversed(touched):
            original=backup/path
            target=site/path
            if original.exists():
                shutil.copy2(str(original),str(target))
            elif target.exists():
                target.unlink()
        print("REVERTIDO: archivos restaurados tras error; backup={}".format(backup),file=sys.stderr)
        raise

def main():
    pa=argparse.ArgumentParser()
    pa.add_argument("--site",required=True)
    pa.add_argument("--source-root")
    pa.add_argument("--backup-root")
    group=pa.add_mutually_exclusive_group(required=True)
    group.add_argument("--check",action="store_true")
    group.add_argument("--apply",action="store_true")
    a=pa.parse_args()
    site=Path(a.site).resolve()
    source_root=Path(a.source_root).resolve() if a.source_root else None
    if a.apply and not a.backup_root:
        pa.error("--apply necesita --backup-root")
    changes=planificar(site,source_root)
    if a.check:
        for path in sorted(set(BLOBS)|{ROUTER,SIDEBAR}):
            print("{}: {}".format("COMPATIBLE" if path in changes else "APLICADO",path))
        print("CHECK COMPATIBLE: sin SQL, sin escrituras; cambios previstos={}".format(len(changes)))
    else:
        apply(site,Path(a.backup_root).resolve(),changes)
        again=planificar(site,source_root)
        if again: raise Conflicto("Verificación posterior incompleta: "+",".join(again))
        print("CHECK POSTERIOR OK: sin tocar telefonía, permisos base ni motores")
    return 0

if __name__=="__main__":
    try:
        sys.exit(main())
    except (Conflicto,OSError,ValueError,RuntimeError) as exc:
        print("BLOQUEADO: {}".format(exc),file=sys.stderr)
        sys.exit(2)
