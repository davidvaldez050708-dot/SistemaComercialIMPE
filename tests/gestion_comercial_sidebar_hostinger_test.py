#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Pruebas offline del ajuste de menú con el publicador original ya aplicado."""
import importlib.util
from pathlib import Path
import subprocess
import tempfile
import sys

ROOT=Path(__file__).resolve().parents[1]
REV_ANT="948facd11bbb8a35ebdc7d64e13c7bffb7f6e722"
REV_BASE="34de9e8291af01e137f077762093e2e6d44efb78"
NEW_TOOL=ROOT/"tools/ajustar_sidebar_gestion_comercial_hostinger.py"
OLD_TOOL=ROOT/"tools/publicar_embudo_comercial_hostinger.py"

def require(ok,desc):
    if not ok: raise AssertionError(desc)

def gitshow(commit,path):
    p=subprocess.run(["git","show",commit+":"+path],cwd=str(ROOT),
      stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    require(p.returncode==0,"git show "+path+" "+p.stderr.decode("utf-8","replace"))
    return p.stdout

def put(site,name,data):
    path=site/name
    path.parent.mkdir(parents=True,exist_ok=True)
    path.write_bytes(data)

def exec_fix(site,backups,option):
    args=[sys.executable,str(NEW_TOOL),"--site",str(site),
          "--source-root",str(ROOT),option]
    if option=="--apply":
        args+=["--backup-root",str(backups)]
    return subprocess.run(args,stdout=subprocess.PIPE,
      stderr=subprocess.PIPE,universal_newlines=True)

spec=importlib.util.spec_from_file_location("publicador_previo",str(OLD_TOOL))
old=importlib.util.module_from_spec(spec)
spec.loader.exec_module(old)
spec2=importlib.util.spec_from_file_location("publicador_nuevo",str(NEW_TOOL))
new=importlib.util.module_from_spec(spec2)
spec2.loader.exec_module(new)

with tempfile.TemporaryDirectory(prefix="impe-menu-realista-") as d:
    site=Path(d)/"public_html"
    backups=Path(d)/"respaldo"
    site.mkdir()
    sidebar=old.patch_sidebar(gitshow(REV_BASE,"app/views/layout/sidebar.php").decode("utf-8"))
    require(sidebar.count(new.OLD_NAV)==2,"menús anteriores detectados")
    # Encabezado COMERCIAL ajeno al publicador: no tocarlo.
    original_marker='<p class="sidebar-section-title">COMERCIAL</p>'
    local_head='<p class="sidebar-section-title">COMERCIAL</p><!-- LOCAL EXISTENTE -->'
    sidebar=sidebar.replace(new.OLD_NAV,local_head+"\n"+new.OLD_NAV,1)
    sidebar+="\n<!-- PERSONALIZACION INSTITUCIONAL CONSERVADA -->\n"
    put(site,new.SIDEBAR,sidebar.encode("utf-8"))
    for name in [new.VIEW,new.CTRL]:
        put(site,name,gitshow(REV_ANT,name))
    unchanged={n:(site/n).read_bytes() for n in [new.SIDEBAR,new.VIEW,new.CTRL]}
    check=exec_fix(site,backups,"--check")
    require(check.returncode==0,"--check: "+check.stderr)
    require(not backups.exists(),"check no crea respaldos")
    for name,data in unchanged.items():
        require((site/name).read_bytes()==data,"check no escribe "+name)
    apply=exec_fix(site,backups,"--apply")
    require(apply.returncode==0,"--apply: "+apply.stderr)
    menu=(site/new.SIDEBAR).read_text(encoding="utf-8")
    require(menu.count(new.NEW_NAV)==2,"ambas vistas escritorio/móvil corregidas")
    require(menu.count(new.OLD_NAV)==0,"ningún menú anterior")
    require(menu.count("Gestión comercial")==2,"una entrada por menú")
    require(menu.count("LOCAL EXISTENTE")==1,"encabezado local preservado")
    require("PERSONALIZACION INSTITUCIONAL CONSERVADA" in menu,"personalización externa preservada")
    require("sidebar-section-title" not in new.NEW_NAV,"no duplicar COMERCIAL")
    require("vista=recuperacion\"\n" not in new.NEW_NAV,"Recuperación no en menú")
    require("vista=descartados\"\n" not in new.NEW_NAV,"Descartados no en menú")
    require("mostrarRecuperacionComercial?'recuperacion':'descartados'" in new.NEW_NAV,
            "permiso limitado abre su primera pestaña permitida")
    require((site/new.VIEW).read_bytes()==(ROOT/new.VIEW).read_bytes(),
           "vista de Gestión comercial nueva")
    require((site/new.CTRL).read_bytes()==(ROOT/new.CTRL).read_bytes(),
           "controlador titula Gestión comercial")
    confirm=exec_fix(site,backups,"--check")
    require(confirm.returncode==0,"idempotencia check: "+confirm.stderr)
    another=exec_fix(site,backups,"--apply")
    require(another.returncode==0,"idempotencia apply: "+another.stderr)
    bs=list(backups.iterdir())
    require(len(bs)==1 and (bs[0]/"manifest.json").is_file(),"respaldo privado íntegro")
    # Modificación ajena en uno de los archivos protegidos bloquea sin escrituras.
    view=site/new.VIEW
    view.write_bytes(view.read_bytes()+b"\n<!-- local -->\n")
    fail=exec_fix(site,backups,"--check")
    require(fail.returncode!=0 and "personalizado" in fail.stderr,
            "bloquear vista personalizada sin escribir")
    # Otro escenario: menú parcialmente modificado no debe repararse por fuerza.
    view.write_bytes((ROOT/new.VIEW).read_bytes())
    menu_path=site/new.SIDEBAR
    menu_path.write_bytes(menu_path.read_bytes().replace(
        b"Gestion comercial",b"Otro menu" # modificación no presente, se ajusta abajo
    ))
    menu_path.write_bytes(menu_path.read_bytes().replace(
        "Gestión comercial".encode("utf-8"),b"Otro menu",1
    ))
    conflict=exec_fix(site,backups,"--check")
    require(conflict.returncode!=0 and "Sidebar" in conflict.stderr,
            "bloquear menú parcialmente personalizado")
print("CHECK OK: menús compactos, encabezado ajeno preservado, permisos, respaldo, idempotencia y conflictos.")
