#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Previsualización PRIVADA de reconciliación de 4 archivos de telefonía.

Fusión con tres versiones: Hostinger actual (ours), Git de origen (base)
y nuevo paquete V3 (theirs). NO escribe en public_html, no toca BD,
no contacta a nadie, y NO produce un paquete instalable sin revisión.
Compatible con Python 3.6. No imprime código de producción ni datos.
"""
from __future__ import print_function
import argparse
import difflib
import hashlib
import os
import shutil
import subprocess
import sys

ROOT_REV = "79f8ccda4f6f525318c7abbbb67324077c175c3c"
ROOT_URL = "https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/" + ROOT_REV
FILES = [
    ("app/services/TelefoniaMarcacionesVentasService.php",
     "77c264a738ef5fdc9298a6cd033310b65eb83d49",
     "b593331b00b961da312a04326952572bcf6bb194",
     "9d2b57adc2c7cdc4171b40a8e1a9d3453364a95a"),
    ("prueba_telefonia/api/zadarma_estado_llamada.php",
     "d1fc14e0e53b8e0acf0119b894afcd11228ea20e",
     "54faf612322f68935899857305b11413cf1bbc0c",
     "1fc93e96a5e70908c3b33fbd60f13b53fd58c9f1"),
    ("public/javascript/telefonia_marcador.js",
     "fd02804623f3820d59c92e52cb1e9b87ce353020",
     "1880a0ab4914ed32dda851748941a1f3b8845716",
     "0287d86911a1851f0c756d905b48b8893df5116a"),
    ("app/controllers/TelefoniaController.php",
     "5a72ea2a602cc3d6fadbb723df3b78cfc6b514c4",
     "fbe30cfbd69b2d881c8e330fe5988e6674ff36b2",
     "40f57513643c8f91202c94b2c468b7d93d60e30d"),
]

def blob(data):
    return hashlib.sha1(("blob %d\0" % len(data)).encode("ascii") + data).hexdigest()

def safe_path(root,rel):
    p=os.path.join(root,*rel.split("/"))
    if not os.path.commonpath([root,os.path.realpath(p)]).startswith(root):
        raise RuntimeError("Ruta fuera del directorio privado: "+rel)
    return p

def get_bytes(filename,expected):
    if not os.path.isfile(filename) or os.path.islink(filename):
        raise RuntimeError("Archivo ausente o enlace simbólico: "+filename)
    with open(filename,"rb") as f: data=f.read()
    if blob(data)!=expected:
        raise RuntimeError("Hash no coincide: "+filename)
    return data

def curl_baseline(url,expected):
    data=subprocess.check_output(["curl","-fsSL","--retry","2",url],
                                 stderr=subprocess.PIPE)
    if blob(data)!=expected:
        raise RuntimeError("Versión base remota inesperada")
    return data

def changed(a,b):
    aa=a.decode("utf-8","replace").splitlines()
    bb=b.decode("utf-8","replace").splitlines()
    return [x for x in difflib.SequenceMatcher(
        None,aa,bb,autojunk=False).get_opcodes() if x[0]!="equal"]

def methods_only(data):
    lines=data.decode("utf-8","replace").splitlines()
    out=[]
    for line in lines:
        candidate=line.strip()
        if "function " in candidate and not candidate.startswith("//"):
            if candidate.startswith(("public ","private ","protected ")):
                out.append(candidate.split("function ",1)[1].split("(",1)[0].strip())
    return sorted(set(out))

def contract(rel,data):
    if b"<<<<<<<" in data or b"=======" in data or b">>>>>>>" in data:
        return False
    needed={
        "app/services/TelefoniaMarcacionesVentasService.php":[
            b"ProspectoNoContactarV3::exigirPermitido",
            b"function vincular(",
            b"function reconciliarPendientes("],
        "prueba_telefonia/api/zadarma_estado_llamada.php":[
            b"ProspectoNoContactarV3::exigirPermitido",
            b"if ($solicitarCheckpoint)",
            b"ultimoInicioSalienteId"],
        "public/javascript/telefonia_marcador.js":[
            b"await registrar;",
            b"await api.startCall("],
        "app/controllers/TelefoniaController.php":[
            b"catch (DomainException $e)",
            b"function iniciarMarcacionVenta("],
    }
    if not all(x in data for x in needed[rel]): return False
    if rel=="public/javascript/telefonia_marcador.js":
        return data.index(b"await registrar;")<data.index(b"await api.startCall(")
    if rel=="prueba_telefonia/api/zadarma_estado_llamada.php":
        return (data.index(b"if ($solicitarCheckpoint)") <
                data.index(b"ProspectoNoContactarV3::exigirPermitido") <
                data.index(b"ultimoInicioSalienteId"))
    if rel=="app/services/TelefoniaMarcacionesVentasService.php":
        return data.index(b"ProspectoNoContactarV3::exigirPermitido")<data.index(
            b"INSERT INTO telefonia_ventas_marcaciones")
    return True

def main():
    ap=argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--site",required=True)
    ap.add_argument("--stage",required=True)
    args=ap.parse_args()
    site=os.path.realpath(args.site)
    stage=os.path.realpath(args.stage)
    if not os.path.isfile(os.path.join(site,"config/db_connection.php")):
        raise RuntimeError("Sitio CRM no reconocido")
    if not os.path.isfile(os.path.join(
        stage,"tools/publicar_gestion_comercial_v3_integral_hostinger.py")):
        raise RuntimeError("Paquete integral no encontrado")
    if os.path.commonpath([site,stage])==site:
        raise RuntimeError("Stage dentro de producción, bloqueado")
    merge_exe=shutil.which("git")
    if merge_exe is None:
        raise RuntimeError("Git no disponible. No realizar fusión por aproximación.")
    out=os.path.join(stage,"_conciliacion_telefonia_privada")
    if os.path.islink(out):raise RuntimeError("Directorio de salida inseguro")
    if not os.path.isdir(out):os.makedirs(out,mode=0o700)
    os.chmod(out,0o700)
    print("PREVIEW_CONCILIACION_CUATRO_ARCHIVOS_SOLO_PRIVADO")
    print("NO_MODIFICA_PRODUCCION")
    total_ok=0
    for rel,site_sha,base_sha,new_sha in FILES:
        live=get_bytes(safe_path(site,rel),site_sha)
        proposed=get_bytes(safe_path(stage,rel),new_sha)
        old=curl_baseline(ROOT_URL+"/"+rel,base_sha)
        private=os.path.join(out,*rel.split("/"))
        folder=os.path.dirname(private)
        if not os.path.isdir(folder):os.makedirs(folder,mode=0o700)
        ours=private+".ours"
        baseline=private+".base"
        theirs=private+".theirs"
        for filename,data in [(ours,live),(baseline,old),(theirs,proposed)]:
            with open(filename,"wb") as f:f.write(data)
            os.chmod(filename,0o600)
        proc=subprocess.Popen(
            [merge_exe,"merge-file","--diff3","-p",ours,baseline,theirs],
            stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        merged,err=proc.communicate()
        if proc.returncode not in (0,1):
            raise RuntimeError("Fallo git merge-file con "+rel)
        with open(private,"wb") as f:f.write(merged)
        os.chmod(private,0o600)
        compatible=(proc.returncode==0 and contract(rel,merged))
        state="MERGE_LIMPIO" if compatible else (
            "CONFLICTO_MANUAL" if proc.returncode==1 else "CONTRATO_INCOMPATIBLE")
        if compatible and rel.endswith(".php"):
            check=subprocess.Popen(["php","-l",private],
                                   stdout=subprocess.PIPE,stderr=subprocess.PIPE)
            chkout,chkerr=check.communicate()
            if check.returncode!=0:
                state="PHP_LINT_BLOQUEADO";compatible=False
        if compatible and rel.endswith(".js"):
            node=shutil.which("node")
            if node:
                if subprocess.call([node,"--check",private],
                                   stdout=subprocess.DEVNULL,
                                   stderr=subprocess.DEVNULL)!=0:
                    compatible=False;state="JS_LINT_BLOQUEADO"
            else:
                state="JS_SIN_NODE_REQUIERE_CI"
                compatible=False
        if compatible:total_ok+=1
        before=changed(old,live)
        after=changed(old,proposed)
        print("ARCHIVO | {} | SITIO={} | ORIGEN={} | DESTINO={} | MERGE={} | HASH_PREVIEW={}".format(
            rel,site_sha,base_sha,new_sha,state,blob(merged)))
        print("DIFERENCIAS | personalizado={} bloques | nuevo={} bloques | metodos_extra_sitio={}".format(
            len(before),len(after),
            ",".join(sorted(set(methods_only(live))-set(methods_only(old)))) or "ninguno"))
    print("RESULTADO_FUSIONES_LIMPIAS: {}/4".format(total_ok))
    print("RUTA_PRIVADA_PREVIEW: "+out)
    print("SIN_APPLY_SIN_SQL_SIN_WEBRTC_SIN_MENSAJES")
    print("ATENCION: esto NO modifica el publicador ni autoriza publicacion.")

if __name__=="__main__":
    try:main()
    except (RuntimeError,OSError,subprocess.CalledProcessError) as exc:
        print("BLOQUEADO: "+str(exc),file=sys.stderr)
        sys.exit(2)
