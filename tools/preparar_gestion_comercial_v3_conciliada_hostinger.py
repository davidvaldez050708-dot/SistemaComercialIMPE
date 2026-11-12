#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Prepara ENTREGA PRIVADA conciliada de Gestión Comercial V3.

Conserva cuatro personalizaciones de Telefonía Hostinger: sólo copia del
stage original a un directorio privado NUEVO; no publica, no toca BD ni
public_html. El publicador resultante exige los 4 SHA productivos reales.
Python >=3.6; no requiere Node ni dependencias de terceros.

Requiere haber ejecutado preview_fusion_telefonia_hostinger.py y tener
las dos fusiones reales de telefono_marcador.js y TelefoniaController.php
en _conciliacion_telefonia_privada.
"""
from __future__ import print_function
import argparse
import hashlib
import os
import shutil
import subprocess
import sys
import tempfile

import publicar_gestion_comercial_v3_integral_hostinger as release

COMBINE = {
    "app/services/TelefoniaMarcacionesVentasService.php": (
        "77c264a738ef5fdc9298a6cd033310b65eb83d49",
        "7e0f46b491a27d7e8f62eeac3dc50dc191bd2ea1"),
    "prueba_telefonia/api/zadarma_estado_llamada.php": (
        "d1fc14e0e53b8e0acf0119b894afcd11228ea20e",
        "88956a12e6bfed364cb007e1555ed0644d8b8cb0"),
    "public/javascript/telefonia_marcador.js": (
        "fd02804623f3820d59c92e52cb1e9b87ce353020",
        "7e1c6bd663ec568f73d37d902471f89acbb79982"),
    "app/controllers/TelefoniaController.php": (
        "5a72ea2a602cc3d6fadbb723df3b78cfc6b514c4",
        "ad6c0725cd1a1725d7454dc0c9958abb840746c7"),
}
RECONCILED_COMMIT = "469b6c288567c14ae97f4319e433934cb4573ff2"
ROOT_URL = ("https://raw.githubusercontent.com/davidvaldez050708-dot/"
            "SistemaComercialIMPE/{}/".format(RECONCILED_COMMIT))
CHECKPOINT = "prueba_telefonia/api/zadarma_estado_llamada.php"
CONTROLLER_CUSTOM = [
    b"function actualizarEvidenciaVenta(",
    b"function agregarObservacionVenta(",
    b"function datosProspectoVenta(",
    b"function observacionesVenta(",
    b"function validarAccesoControlLlamadas(",
]
def fail(msg):
    raise RuntimeError(msg)
def blob(data):
    return hashlib.sha1(("blob %d\0" % len(data)).encode("ascii")+data).hexdigest()
def file_data(root,rel,expected):
    if rel.startswith("/") or ".." in rel.split("/"):
        fail("ruta no admitida")
    fp=os.path.join(root,*rel.split("/"))
    if not os.path.isfile(fp) or os.path.islink(fp):
        fail("archivo ausente o enlace: "+rel)
    with open(fp,"rb") as f:data=f.read()
    if blob(data)!=expected:fail("SHA incorrecto: "+rel)
    return data
def put(root,rel,data):
    dest=os.path.join(root,*rel.split("/"))
    folder=os.path.dirname(dest)
    if not os.path.isdir(folder):os.makedirs(folder,0o700)
    with open(dest,"wb") as f:f.write(data)
    os.chmod(dest,0o600)
def download(rel,expected):
    proc=subprocess.Popen(
        ["curl","-fsSL","--retry","2",ROOT_URL+rel],
        stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    data,err=proc.communicate()
    if proc.returncode!=0 or blob(data)!=expected:
        fail("descarga GitHub con SHA inesperado: "+rel)
    return data
def comprobar(rel,data):
    if rel=="app/services/TelefoniaMarcacionesVentasService.php":
        if not all(p in data for p in (
            b"ProspectoNoContactarV3::exigirPermitido",
            b"catch (mysqli_sql_exception $e)",
            b"function reconciliarPendientes(")):
            fail("se perdio vinculación segura de PBX")
        if data.index(b"ProspectoNoContactarV3::exigirPermitido") >= data.index(
                b"INSERT INTO telefonia_ventas_marcaciones"):
            fail("guardia PBX debe preceder al registro")
    elif rel==CHECKPOINT:
        if not all(p in data for p in (
            b"Database::compartida()",b"Database::responderFalloTemporal",
            b"ProspectoNoContactarV3::exigirPermitido")):
            fail("faltan contratos de conexión de Zadarma")
        if not (data.index(b"if ($solicitarCheckpoint)") <
                data.index(b"ProspectoNoContactarV3::exigirPermitido") <
                data.index(b"ultimoInicioSalienteId")):
            fail("checkpoint sale antes de autorizar contacto")
    elif rel=="public/javascript/telefonia_marcador.js":
        if not (b"await registrar;" in data and
                b"await api.startCall(" in data and
                data.index(b"await registrar;") < data.index(b"await api.startCall(")):
            fail("marcador no espera autorización antes de WebRTC")
    elif rel=="app/controllers/TelefoniaController.php":
        if not all(marker in data for marker in CONTROLLER_CUSTOM) or (
            b"catch (DomainException $e)" not in data):
            fail("controlador perdería métodos personalizados")
    if b"<<<<<<<" in data or b">>>>>>>" in data:
        fail("se encontraron conflictos de fusion en "+rel)

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--site",required=True)
    parser.add_argument("--stage",required=True)
    args=parser.parse_args()
    site=os.path.realpath(args.site)
    stage=os.path.realpath(args.stage)
    if not os.path.isfile(os.path.join(site,"config/db_connection.php")):
        fail("sitio CRM no reconocido")
    if not os.path.isfile(os.path.join(
            stage,"tools/publicar_gestion_comercial_v3_integral_hostinger.py")):
        fail("stage original ausente")
    if os.path.commonpath([stage,site])==site:
        fail("stage privado dentro de producción")
    preview=os.path.join(stage,"_conciliacion_telefonia_privada")
    if not os.path.isdir(preview) or os.path.islink(preview):
        fail("no está la previsualización privada de telefonía")
    root=os.path.realpath(os.path.expanduser("~"))
    if os.path.commonpath([root,site])==root and root==site:
        fail("directorio raíz inseguro")
    # Verificar TODOS los orígenes antes de crear la entrega.
    sources={}
    for rel,old,new in release.p.ARCHIVOS:
        source_sha=old if rel in COMBINE else new
        if rel in COMBINE:
            expected_live,expected_new=COMBINE[rel]
            if expected_new==new and expected_live==old:
                fail("el paquete original ya incluye la fusión; no duplicar")
            file_data(site,rel,expected_live)
            if rel==CHECKPOINT:
                # El checkpoint reconciliado está versionado en GitHub.
                data=download(rel,expected_new)
            else:
                data=file_data(preview,rel,expected_new)
            comprobar(rel,data)
            sources[rel]=data
            print("CONCILIADO_PRIVADO | {} | SITIO={} | NUEVO={}".format(
                rel,expected_live,expected_new))
        else:
            sources[rel]=file_data(stage,rel,new)
    for rel,hashbase in release.p.NO_MODIFICAR.items():
        file_data(site,rel,hashbase)
    for rel in [
        "tools/publicar_gestion_comercial_v3_integral_hostinger.py",
        "tools/publicar_ventas_v3_gestion_potenciales_hostinger.py",
        "tools/gestion_comercial_v3_inscripcion_schema_hostinger.php",
        "tools/gestion_comercial_v3_auditar_distribucion_hostinger.php",
        release.MIGRACION,
    ]:
        if rel==release.MIGRACION:
            expected=release.MIGRACION_HASH
            sources[rel]=file_data(stage,rel,expected)
        elif rel=="tools/publicar_gestion_comercial_v3_integral_hostinger.py":
            sources[rel]=open(os.path.join(stage,rel),"rb").read()
        else:
            sources[rel]=open(os.path.join(stage,rel),"rb").read()
    code=sources["tools/publicar_gestion_comercial_v3_integral_hostinger.py"]
    for rel,old,new in release.p.ARCHIVOS:
        if rel not in COMBINE:continue
        prod,merged=COMBINE[rel]
        for original,revised in [(old,prod),(new,merged)]:
            from_bytes=('"'+original+'"').encode("ascii")
            to_bytes=('"'+revised+'"').encode("ascii")
            if code.count(from_bytes)!=1:
                fail("hash de publicador ausente o ambiguo: "+rel)
            code=code.replace(from_bytes,to_bytes)
    sources["tools/publicar_gestion_comercial_v3_integral_hostinger.py"]=code
    output=tempfile.mkdtemp(prefix=".gestion-v3-conciliada-",dir=root)
    os.chmod(output,0o700)
    try:
        for rel,data in sources.items():put(output,rel,data)
        publisher=os.path.join(output,
            "tools/publicar_gestion_comercial_v3_integral_hostinger.py")
        for rel in COMBINE:
            src=os.path.join(output,rel)
            if rel.endswith(".php"):
                if subprocess.call(["php","-l",src],
                    stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL):
                    fail("sintaxis PHP de fusion inválida: "+rel)
        print("ENTREGA_INTEGRAL_CONCILIADA_OK: {} archivos".format(
            len(release.p.ARCHIVOS)))
        print("RUTA_ENTREGA_PRIVADA: "+output)
        print("COMPROBACION_SIN_PUBLICAR:")
        print("python3 -B '{}' --site '{}' --stage '{}' --check".format(
            publisher,site,output))
        print("SIN_PUBLICAR_SIN_SQL_SIN_LLAMADAS")
    except BaseException:
        shutil.rmtree(output)
        raise

if __name__=="__main__":
    try:main()
    except (RuntimeError,OSError,ValueError) as exc:
        print("BLOQUEADO: "+str(exc),file=sys.stderr)
        sys.exit(2)
