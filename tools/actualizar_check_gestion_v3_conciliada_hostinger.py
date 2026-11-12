#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Actualiza sólo el CHECK de una entrega V3 conciliada ya preparada.

Rechaza cualquier archivo distinto del paquete exacto conocido. No escribe en
public_html ni ejecuta SQL; reemplaza atómicamente únicamente el publicador
dentro del directorio privado de preparación.
"""
from __future__ import print_function
import argparse
import hashlib
import os
import subprocess
import sys
import tempfile

REPO = "https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/99d0ccd25a51e06da5aaa113adb83bebed4db004/"
REL = "tools/publicar_gestion_comercial_v3_integral_hostinger.py"
NEW_BLOB = "0416abfe86d7662b5992fbba287de1ea7237a9ba"
OLD_BLOB = "648d18870964ca05fcad089b6ba3e2464c4af1a1"
MERGED = {
    "app/services/TelefoniaMarcacionesVentasService.php": (
        "77c264a738ef5fdc9298a6cd033310b65eb83d49", "7e0f46b491a27d7e8f62eeac3dc50dc191bd2ea1"),
    "prueba_telefonia/api/zadarma_estado_llamada.php": (
        "d1fc14e0e53b8e0acf0119b894afcd11228ea20e", "88956a12e6bfed364cb007e1555ed0644d8b8cb0"),
    "public/javascript/telefonia_marcador.js": (
        "fd02804623f3820d59c92e52cb1e9b87ce353020", "7e1c6bd663ec568f73d37d902471f89acbb79982"),
    "app/controllers/TelefoniaController.php": (
        "5a72ea2a602cc3d6fadbb723df3b78cfc6b514c4", "ad6c0725cd1a1725d7454dc0c9958abb840746c7"),
}
ORIGINAL = {
    "app/services/TelefoniaMarcacionesVentasService.php": (
        "b593331b00b961da312a04326952572bcf6bb194", "9d2b57adc2c7cdc4171b40a8e1a9d3453364a95a"),
    "prueba_telefonia/api/zadarma_estado_llamada.php": (
        "54faf612322f68935899857305b11413cf1bbc0c", "1fc93e96a5e70908c3b33fbd60f13b53fd58c9f1"),
    "public/javascript/telefonia_marcador.js": (
        "1880a0ab4914ed32dda851748941a1f3b8845716", "0287d86911a1851f0c756d905b48b8893df5116a"),
    "app/controllers/TelefoniaController.php": (
        "fbe30cfbd69b2d881c8e330fe5988e6674ff36b2", "40f57513643c8f91202c94b2c468b7d93d60e30d"),
}
SUPPORT = {
    "tools/publicar_ventas_v3_gestion_potenciales_hostinger.py": "8e563d3a3b270c85bda3b59586b06f5cdd7dd8fa",
    "tools/gestion_comercial_v3_inscripcion_schema_hostinger.php": "7418ac08eaac70a92ba4bfc2ca74c7b24f609c50",
    "tools/gestion_comercial_v3_auditar_distribucion_hostinger.php": "5002be6405afdde52d11f333975a3b2ed76aaded",
    "database/migrations/2026_11_12_ventas_inscripcion_v3_evidencia.sql": "8374abf8aae014f6eb5e72b29d7ba61cee82c474",
}

def blob(data):
    return hashlib.sha1(("blob %d\0" % len(data)).encode("ascii") + data).hexdigest()

def read(root, path):
    target = os.path.join(root, *path.split("/"))
    if os.path.islink(target) or not os.path.isfile(target):
        raise ValueError("archivo ausente o enlace: " + path)
    with open(target, "rb") as handle:
        return handle.read()

def substitute(code):
    for path, pair in ORIGINAL.items():
        for before, after in zip(pair, MERGED[path]):
            old = ('"' + before + '"').encode("ascii")
            new = ('"' + after + '"').encode("ascii")
            if code.count(old) != 1:
                raise ValueError("manifiesto inesperado: " + path)
            code = code.replace(old, new)
    return code

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--site", required=True)
    parser.add_argument("--stage", required=True)
    args = parser.parse_args()
    site, stage = os.path.realpath(args.site), os.path.realpath(args.stage)
    if not os.path.isfile(os.path.join(site, "config/db_connection.php")):
        raise ValueError("sitio CRM no reconocido")
    if os.path.islink(args.stage):
        raise ValueError("directorio privado no puede ser enlace")
    if os.path.commonpath([site, stage]) == site or site == stage:
        raise ValueError("la entrega privada está dentro del sitio")
    if not os.path.basename(stage).startswith(".gestion-v3-conciliada-"):
        raise ValueError("directorio privado no reconocido")
    target = os.path.join(stage, *REL.split("/"))
    old_data = read(stage, REL)
    # Descarga y verifica el publicador corregido completo antes de tocar el stage.
    proc = subprocess.Popen(["curl", "-fsSL", "--retry", "2", REPO + REL],
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    new_data, stderr = proc.communicate()
    if proc.returncode != 0 or blob(new_data) != NEW_BLOB:
        raise ValueError("descarga del publicador con hash inesperado")
    expected_new = substitute(new_data)
    if old_data == expected_new:
        print("ACTUALIZADO: CHECK ya corregido; sitio intacto.")
    else:
        # El publicador anterior se obtiene del commit padre y se verifica
        # antes de hacer la misma sustitución de cuatro pares de hashes.
        parent_url = REPO.replace("99d0ccd25a51e06da5aaa113adb83bebed4db004",
                                  "4ac0d5506d031f3b8639bd1a400dd3b2ac7dfae8") + REL
        proc = subprocess.Popen(["curl", "-fsSL", "--retry", "2", parent_url],
                                stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        parent_data, stderr = proc.communicate()
        if proc.returncode != 0 or blob(parent_data) != OLD_BLOB or old_data != substitute(parent_data):
            raise ValueError("publicador privado desconocido; no se modificó")
    # El paquete y el sitio deben corresponder a las veinte rutas conocidas.
    code = expected_new.decode("utf-8")
    # Extraer el manifiesto con AST, sin ejecutar el publicador que se reemplaza.
    import ast
    tree = ast.parse(code)
    manifest = None
    dependencies = None
    for node in tree.body:
        if isinstance(node, ast.Assign):
            for name in node.targets:
                if isinstance(name, ast.Attribute) and name.attr == "ARCHIVOS":
                    manifest = ast.literal_eval(node.value)
                if isinstance(name, ast.Attribute) and name.attr == "NO_MODIFICAR":
                    dependencies = ast.literal_eval(node.value)
    if manifest is None or len(manifest) != 20 or dependencies is None or len(dependencies) != 4:
        raise ValueError("manifiesto integral inesperado")
    for path, before, after in manifest:
        if blob(read(stage, path)) != after:
            raise ValueError("entrega alterada: " + path)
        live = os.path.join(site, *path.split("/"))
        if before is None:
            if os.path.exists(live):
                raise ValueError("archivo nuevo ya existe en el sitio: " + path)
        elif blob(read(site, path)) != before:
            raise ValueError("versión productiva diferente: " + path)
    for path, expected in dependencies.items():
        if blob(read(site, path)) != expected:
            raise ValueError("dependencia productiva diferente: " + path)
    for path, expected in SUPPORT.items():
        if blob(read(stage, path)) != expected:
            raise ValueError("archivo de apoyo alterado: " + path)
    if old_data != expected_new:
        fd, temporary = tempfile.mkstemp(prefix=".check-v3-", dir=os.path.dirname(target))
        try:
            with os.fdopen(fd, "wb") as handle:
                handle.write(expected_new)
                handle.flush()
                os.fsync(handle.fileno())
            os.chmod(temporary, 0o600)
            os.replace(temporary, target)
        finally:
            if os.path.exists(temporary):
                os.unlink(temporary)
    print("CHECK_ACTUALIZADO: 20 archivos y 4 dependencias exactos; sitio intacto.")
    print("SIGUIENTE: python3 -B '{}' --site '{}' --stage '{}' --check".format(target, site, stage))

if __name__ == "__main__":
    try:
        main()
    except (ValueError, OSError, SyntaxError) as exc:
        print("BLOQUEADO: " + str(exc), file=sys.stderr)
        sys.exit(2)
