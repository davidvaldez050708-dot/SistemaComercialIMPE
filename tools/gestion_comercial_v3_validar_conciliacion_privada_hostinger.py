#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Revisión de cuatro fusiones telefónicas en área PRIVADA de Hostinger.

- Verifica SHA de originales y previsualizaciones, que corresponden a la
  conciliación observada el 12 de noviembre de 2026.
- Descarga SOLO al área privada la versión de checkpoint Zadarma que resuelve
  el conflicto conservando Database::compartida y gestión de fallos temporales.
- PHP -l + contratos y presencia de funciones personalizadas.
- Detecta si hay motor JS CLI; si no, informa falta de parser, NO aprueba JS.
- Nunca modifica public_html, SQL, rutas telefónicas o archivos de producción.
- Python 3.6+; sin pip ni Node obligatorio.
"""
from __future__ import print_function
import argparse
import hashlib
import os
import shutil
import subprocess
import sys

ROOT_URL = (
    "https://raw.githubusercontent.com/davidvaldez050708-dot/"
    "SistemaComercialIMPE/469b6c288567c14ae97f4319e433934cb4573ff2/"
)
CHECKPOINT="prueba_telefonia/api/zadarma_estado_llamada.php"
EXPECTED = {
    "app/services/TelefoniaMarcacionesVentasService.php": {
        "site":"77c264a738ef5fdc9298a6cd033310b65eb83d49",
        "merged":"7e0f46b491a27d7e8f62eeac3dc50dc191bd2ea1"},
    CHECKPOINT: {
        "site":"d1fc14e0e53b8e0acf0119b894afcd11228ea20e",
        "merged":"88956a12e6bfed364cb007e1555ed0644d8b8cb0"},
    "public/javascript/telefonia_marcador.js": {
        "site":"fd02804623f3820d59c92e52cb1e9b87ce353020",
        "merged":"7e1c6bd663ec568f73d37d902471f89acbb79982"},
    "app/controllers/TelefoniaController.php": {
        "site":"5a72ea2a602cc3d6fadbb723df3b78cfc6b514c4",
        "merged":"ad6c0725cd1a1725d7454dc0c9958abb840746c7"},
}
CUSTOM_METHODS=(
    b"function actualizarEvidenciaVenta(",
    b"function agregarObservacionVenta(",
    b"function datosProspectoVenta(",
    b"function observacionesVenta(",
    b"function validarAccesoControlLlamadas(",
)

def blob(data):
    return hashlib.sha1(("blob %d\0" % len(data)).encode("ascii")+data).hexdigest()

def read(path,expected):
    if not os.path.isfile(path) or os.path.islink(path):
        raise RuntimeError("Archivo ausente o enlace: "+path)
    with open(path,"rb") as handle:data=handle.read()
    if blob(data)!=expected:
        raise RuntimeError("Archivo con hash diferente; NO continuar: "+path)
    return data

def lint_php(file):
    proc=subprocess.Popen(["php","-l",file],stdout=subprocess.PIPE,
                          stderr=subprocess.PIPE)
    out,err=proc.communicate()
    if proc.returncode:
        raise RuntimeError("Sintaxis PHP inválida: "+file)
    print("PHP_SINTAXIS_OK: "+os.path.basename(file))

def check_contract(path,data):
    checks={
        "app/services/TelefoniaMarcacionesVentasService.php":(
            b"ProspectoNoContactarV3::exigirPermitido",
            b"function reconciliarPendientes(",
            b"catch (mysqli_sql_exception $e)"),
        CHECKPOINT:(
            b"Database::compartida()",
            b"Database::responderFalloTemporal",
            b"ProspectoNoContactarV3::exigirPermitido",
            b"if ($solicitarCheckpoint)"),
        "public/javascript/telefonia_marcador.js":(
            b"await registrar;",
            b"await api.startCall("),
        "app/controllers/TelefoniaController.php":(
            b"catch (DomainException $e)",
            b"function iniciarMarcacionVenta("),
    }
    if any(marker not in data for marker in checks[path]):
        raise RuntimeError("Contrato comercial ausente: "+path)
    if b"<<<<<<<" in data or b">>>>>>>" in data:
        raise RuntimeError("Marcadores de conflicto detectados: "+path)
    if path=="public/javascript/telefonia_marcador.js":
        if data.index(b"await registrar;")>=data.index(b"await api.startCall("):
            raise RuntimeError("La marcación ocurriría antes del control.")
    if path=="app/controllers/TelefoniaController.php":
        if any(m not in data for m in CUSTOM_METHODS):
            raise RuntimeError("Se perdieron métodos personalizados de Hostinger.")
    print("CONTRATOS_OK: "+path)

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--site",required=True)
    parser.add_argument("--stage",required=True)
    args=parser.parse_args()
    site=os.path.realpath(args.site)
    stage=os.path.realpath(args.stage)
    if not os.path.isfile(os.path.join(site,"config/db_connection.php")):
        raise RuntimeError("No se reconoce el CRM de producción")
    if os.path.commonpath([site,stage])==site:
        raise RuntimeError("La carpeta de pruebas está dentro de producción")
    preview=os.path.join(stage,"_conciliacion_telefonia_privada")
    if not os.path.isdir(preview) or os.path.islink(preview):
        raise RuntimeError("No existe la previsualización privada")
    resolved=os.path.join(preview,CHECKPOINT+".resuelto_v3")
    print("=== VERIFICANDO VERSIONES PRODUCTIVAS (SOLO LECTURA) ===")
    for rel,hashes in EXPECTED.items():
        production=os.path.join(site,*rel.split("/"))
        read(production,hashes["site"])
        print("PRODUCCION_INTACTA: "+rel)
    print("=== OBTENIENDO CHECKPOINT CONCILIADO EN CARPETA PRIVADA ===")
    folder=os.path.dirname(resolved)
    if not os.path.isdir(folder):os.makedirs(folder,mode=0o700)
    if os.path.lexists(resolved):
        read(resolved,EXPECTED[CHECKPOINT]["merged"])
    else:
        proc=subprocess.Popen(["curl","-fsSL","--retry","2",ROOT_URL+CHECKPOINT],
            stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        data,err=proc.communicate()
        if proc.returncode!=0 or blob(data)!=EXPECTED[CHECKPOINT]["merged"]:
            raise RuntimeError("Descarga del checkpoint resuelto no íntegra")
        with open(resolved,"wb") as output:output.write(data)
        os.chmod(resolved,0o600)
    print("CHECKPOINT_RESUELTO_OK: SHA="+EXPECTED[CHECKPOINT]["merged"])
    print("=== VALIDANDO PREVISUALIZACIONES SIN PUBLICAR ===")
    for rel,hashes in EXPECTED.items():
        file=resolved if rel==CHECKPOINT else os.path.join(preview,*rel.split("/"))
        data=read(file,hashes["merged"])
        check_contract(rel,data)
        if rel.endswith(".php"):lint_php(file)
    print("=== VERIFICANDO MOTOR JAVASCRIPT LOCAL ===")
    js=os.path.join(preview,"public/javascript/telefonia_marcador.js")
    engine=shutil.which("node") or shutil.which("nodejs")
    if engine:
        if subprocess.call([engine,"--check",js],
                           stdout=subprocess.DEVNULL,
                           stderr=subprocess.DEVNULL):
            raise RuntimeError("Sintaxis JavaScript no válida")
        print("JS_SINTAXIS_OK")
    else:
        print("JS_SINTAXIS_PENDIENTE: sin Node.js en Hostinger; requiere validación con motor JS.")
    print("CONCILIACION_3_PHP_CONTRATOS_OK: guardia y personalizaciones conservadas")
    print("PREVIEW_JS_NO_PUBLICADA: "+
          ("SINTAXIS_OK" if engine else "SINTAXIS_PENDIENTE"))
    print("NO_SQL_NO_APPLY_NO_LLAMADAS_NO_WHATSAPP")

if __name__=="__main__":
    try:main()
    except (RuntimeError,OSError,ValueError) as exc:
        print("BLOQUEADO: "+str(exc),file=sys.stderr);sys.exit(2)
