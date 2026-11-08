#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Prueba de conciliación del publicador en sitio falso sin conectarse a producción."""
import hashlib
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile

ROOT=Path(__file__).resolve().parents[1]
TOOL=ROOT/"tools/publicar_embudo_comercial_hostinger.py"
BASE="34de9e8291af01e137f077762093e2e6d44efb78"

def check(cond,what):
    if not cond:
        raise AssertionError(what)

def cli(site,backup,flag):
    return subprocess.run([
        sys.executable,str(TOOL),"--site",str(site),
        "--source-root",str(ROOT),flag,
    ]+([] if flag=="--check" else ["--backup-root",str(backup)]),
      stdout=subprocess.PIPE,stderr=subprocess.PIPE,universal_newlines=True)

def git_base(path):
    p=subprocess.run(["git","show",BASE+":"+path],cwd=str(ROOT),
        stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    check(p.returncode==0,"No se encontró historial de base: "+path)
    return p.stdout

def put(site,path,data):
    target=site/path
    target.parent.mkdir(parents=True,exist_ok=True)
    target.write_bytes(data)

with tempfile.TemporaryDirectory(prefix="impe-embudo-fixture-") as directory:
    base=Path(directory)
    site=base/"public_html"
    backups=base/"backups"
    site.mkdir()
    for path in ["index.php","app/views/layout/sidebar.php",
                 "app/helpers/PermissionHelper.php",
                 "app/helpers/AdminRolePolicy.php"]:
        put(site,path,git_base(path))
    before_index=(site/"index.php").read_bytes()
    before_sidebar=(site/"app/views/layout/sidebar.php").read_bytes()
    # Una personalización realista, fuera de las anclas conocidas.
    with (site/"index.php").open("ab") as dst:
        dst.write(b"\n// PERSONALIZACION LOCAL CONSERVADA\n")
    with (site/"app/views/layout/sidebar.php").open("ab") as dst:
        dst.write(b"\n<!-- SIDEBAR PERSONALIZADA CONSERVADA -->\n")
    personal_index=(site/"index.php").read_bytes()
    personal_sidebar=(site/"app/views/layout/sidebar.php").read_bytes()
    check(cli(site,backups,"--check").returncode==0,"--check compatible")
    check((site/"index.php").read_bytes()==personal_index,"--check no escribe index")
    check((site/"app/views/layout/sidebar.php").read_bytes()==personal_sidebar,"--check no escribe sidebar")
    check(not backups.exists(),"--check no crea backups")
    a=cli(site,backups,"--apply")
    check(a.returncode==0,"--apply no compatible: "+a.stderr)
    index=(site/"index.php").read_text(encoding="utf-8")
    menu=(site/"app/views/layout/sidebar.php").read_text(encoding="utf-8")
    check(index.count("case 'embudoComercial':")==1,"ruta única")
    check(index.count("'embudoComercial'")>=2,"ruta y CSRF")
    check(menu.count("controller=embudoComercial&amp;action=index&amp;vista=embudo")==2,"ambos menús")
    check("PERSONALIZACION LOCAL CONSERVADA" in index,"index local preservado")
    check("SIDEBAR PERSONALIZADA CONSERVADA" in menu,"sidebar local preservada")
    check((site/"app/helpers/PermissionHelper.php").read_bytes()==git_base(
        "app/helpers/PermissionHelper.php"),"PermissionHelper no se toca")
    check(cli(site,backups,"--check").returncode==0,"check tras publicación")
    check(cli(site,backups,"--apply").returncode==0,"apply idempotente")
    dirs=list(backups.iterdir())
    check(dirs and (dirs[0]/"manifest.json").exists(),"respaldo manifestado")
    # Un cambio inesperado de un nuevo PHP debe BLOQUEAR sin escribir.
    mod=site/"app/services/EmbudoComercialRepository.php"
    mod.write_bytes(mod.read_bytes()+b"\n// local override\n")
    old=mod.read_bytes()
    r=cli(site,backups,"--check")
    check(r.returncode!=0 and "personalizado" in r.stderr,
        "personalización nueva debe bloquear")
    check(mod.read_bytes()==old,"conflicto no edita")
    # Estructura diferente, no forzar reemplazo.
    mod.write_bytes((ROOT/"app/services/EmbudoComercialRepository.php").read_bytes())
    current=(site/"index.php").read_bytes()
    (site/"index.php").write_bytes(current.replace(b"case 'embudoComercial':",
                                                      b"case 'otroComercial':"))
    check(cli(site,backups,"--check").returncode!=0,"router modificado bloquear")
# Hostinger puede conservar el case de Mi cartera aunque haya quitado el comentario.
# Debe conciliar sólo en el ancla estructural y preservar el resto.
with tempfile.TemporaryDirectory(prefix="impe-embudo-hostinger-sin-comentario-") as directory:
    base=Path(directory)
    site=base/"public_html"
    backup=base/"respaldo"
    site.mkdir()
    for path in ["index.php","app/views/layout/sidebar.php",
                 "app/helpers/PermissionHelper.php","app/helpers/AdminRolePolicy.php"]:
        put(site,path,git_base(path))
    route=site/"index.php"
    marker=b"    // Mi cartera nunca reutiliza las rutas administrativas de Prospectos."
    original=route.read_bytes()
    check(original.count(marker)==1,"fixture contiene marcador historico")
    route.write_bytes(original.replace(marker,b"    // RUTA DE CARTERA PERSONALIZADA"))
    personal=route.read_bytes()
    first=cli(site,backup,"--check")
    check(first.returncode==0,
          "Hostinger sin comentario debe conciliar: "+first.stderr)
    check(route.read_bytes()==personal,"check con comentario distinto no escribe")
    applied=cli(site,backup,"--apply")
    check(applied.returncode==0,
          "Hostinger sin comentario debe publicar: "+applied.stderr)
    changed=route.read_text(encoding="utf-8")
    check(changed.count("case 'embudoComercial':")==1,"router nuevo unico")
    check(changed.count("case 'miCartera':")==1,"router cartera conservado")
    check("RUTA DE CARTERA PERSONALIZADA" in changed,"comentario Hostinger preservado")
    check(cli(site,backup,"--check").returncode==0,"idempotencia sin comentario")
    # No debe inventar un ancla si desaparece la ruta de cartera.
    route.write_bytes(route.read_bytes().replace(b"case 'miCartera':",
                                                b"case 'carteraMovida':").replace(
        b"case 'embudoComercial':",b"case 'embudoModificado':"))
    blocked=cli(site,backup,"--check")
    check(blocked.returncode!=0,"sin ancla de cartera bloquea")
print("CHECK OK: marcador ausente, Hostinger personalizado, rollback, idempotencia y aislamiento.")

