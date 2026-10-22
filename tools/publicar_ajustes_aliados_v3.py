#!/usr/bin/env python3
# coding: utf-8
"""Publicador V3 seguro para Aliados, Convenios y modal modular Hostinger.
No ejecuta SQL ni modifica crear_modal.php ni convocatoria_crear_compartido.js.
"""
import argparse
import datetime
import difflib
import hashlib
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import sys
import tempfile
from urllib.request import Request, urlopen

SOURCE = "7f3548dd8600a83b329c4c68139b0e396d3aea3e"
BASE = "a2d22524249570e90a32d196058d9cc50527c21e"
ROOT = "https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/"
MODULE = "app/views/convocatorias/partials/aliado_programas_modular.php"
MODULE_OLD = "945ae7588da7c1896cf71fbc5e39e59ee197bc1e91a0eb550dacf50e33edd458"
SHA = {
 "app/controllers/ConvocatoriaController.php":"0e892d7ea2793271a0aa21c281ea6dbc2b2dd2a6",
 "app/models/ConvocatoriaModel.php":"e20a05575587c0be3bf0673535d52dd5b849031a",
 "app/services/AliadoRegistroService.php":"bdfebaa79a05ae39392181a2b53f96753d7627b2",
 "app/views/aliados/estado.php":"a758c384d4dfd22c20913e94628a7c14b7ff8b50",
 "app/views/aliados/partials/registro_gestion.php":"a15e9dc961f66451db094c30c67b9098ae9e8696",
 "app/views/convocatorias/crear_desde_convenio.php":"1eaa8705eb2ed5aa8275b0cb5018e3c131e4d16b",
 "public/javascript/aliados_registro.js":"325a1b8f1a8a315b3b493f3cda31c652f83414c4",
 "public/css/aliados_v2_extension.css":"4ab5a6106cd54f7d3e51b06500b90f81e866f44c",
 "public/javascript/convocatorias_aliado_multiprograma_v3.js":"9c93fa26c67965c95721df6511e165120f5d4c89",
 "public/css/convocatorias_aliado_multiprograma_v3.css":"eea8966576c62bfe4c88bd551b01067f6bdcbd22",
 "tools/migrar_canales_manuales_20261022.php":"3d025804f6c5513614d28eff74be9f4566531a69",
 "tools/migrar_convocatorias_multiprograma_20261022.php":"034ef3f77991c1b969e4331092ec5257bacb7059",
 "database/migrations/2026_10_22_convocatorias_multiprograma_origen.sql":"1a56e57b89f1d3672a9627b2b407a16f9622481f"
}
NEW = frozenset(("public/javascript/convocatorias_aliado_multiprograma_v3.js",
                 "public/css/convocatorias_aliado_multiprograma_v3.css",
                 "tools/migrar_canales_manuales_20261022.php",
                 "tools/migrar_convocatorias_multiprograma_20261022.php",
                 "database/migrations/2026_10_22_convocatorias_multiprograma_origen.sql"))
SNIPPET = (b'\n<link rel="stylesheet" href="<?= $texto(BASE_URL) ?>public/css/'
           b'convocatorias_aliado_multiprograma_v3.css?v=3">\n'
           b'<script defer src="<?= $texto(BASE_URL) ?>public/javascript/'
           b'convocatorias_aliado_multiprograma_v3.js?v=3"></script>\n')
def digest(data):
    return hashlib.sha256(data).hexdigest()
def blob(data):
    return hashlib.sha1(("blob %d\0" % len(data)).encode("ascii")+data).hexdigest()
def lf(data):
    return data.replace(b'\r\n',b'\n')
def fetch(rev,name):
    url=ROOT+rev+"/"+name
    with urlopen(Request(url,headers={"User-Agent":"IMPE-aliados-v3"}),timeout=35) as rsp:
        return rsp.read()
def located(site,name):
    path=site/name
    resolved=path.resolve()
    if path.is_symlink() or not (resolved==site or site in resolved.parents):
        raise RuntimeError("Ruta insegura: "+name)
    return path
def module_patch(data):
    if data is None:
        raise RuntimeError("Falta el módulo de programas previamente instalado")
    if b"convocatorias_aliado_multiprograma_v3.js" in data:
        if data.count(SNIPPET)!=1:
            raise RuntimeError("Integración parcial/alterada V3 en el módulo modular")
        return data
    if digest(data)!=MODULE_OLD:
        raise RuntimeError("El módulo de producción fue personalizado; no insertar V3 sin revisarlo")
    marker=b"convocatorias_aliado_modular.js?v=1"
    if data.count(marker)!=1:raise RuntimeError("Falta el punto único de carga del JS modular")
    end=data.find(b"</script>",data.index(marker))
    if end<0:raise RuntimeError("No se encontró el cierre del script modular")
    end+=len(b"</script>")
    return data[:end]+SNIPPET+data[end:]
def overlay_convocatoria_controller(local, old, desired):
    """Inserta sólo un método en el controlador personalizado de Hostinger.

    La revisión fijada en SOURCE debe agregar exactamente ese método al BASE.
    No hace 3-way merge ni reemplaza helpers, rutas o comportamiento anterior.
    """
    name="app/controllers/ConvocatoriaController.php"
    if local is None or old is None:
        raise RuntimeError(name+": controlador de producción o versión base ausente")
    old_norm=lf(old)
    desired_norm=lf(desired)
    local_norm=lf(local)
    block_start=b"    /**\\n     * Crea una publicación real por programa autorizado"
    anchor=b"    public function actualizar()\\n    {"
    if desired_norm.count(block_start)!=1 or old_norm.count(block_start)!=0:
        raise RuntimeError(name+": marcador de método nuevo ambiguo")
    start=desired_norm.index(block_start)
    end=desired_norm.find(anchor,start)
    if end<0 or desired_norm[:start]+desired_norm[end:]!=old_norm:
        raise RuntimeError(name+": fuente no es una inserción pura sobre BASE; requiere revisión")
    method=desired_norm[start:end]
    signature=b"public function guardarProgramasAliado("
    if method.count(signature)!=1 or b"guardarProgramasAliado" in old_norm:
        raise RuntimeError(name+": método nuevo no identificable")
    # No sobreescribir una versión distinta ya instalada.
    if signature in local_norm:
        if local_norm.count(signature)==1 and local_norm.count(method)==1:
            print("CONSERVADO: "+name+" (método multiprograma ya instalado)")
            return local
        raise RuntimeError(name+": ya existe guardarProgramasAliado personalizado; no se sobrescribe")
    required=(b"class ConvocatoriaController",
              b"public function guardar(", b"public function actualizar(",
              b"function validarPermiso(", b"function validarMetodoPost(",
              b"function esClasificacionValida(", b"function esTerritorioChihuahua(",
              b"function limpiarDatos(", b"function validarDatos(",
              b"function procesarImagen(", b"function eliminarImagen(")
    for symbol in required:
        if local_norm.count(symbol)!=1:
            raise RuntimeError(name+": dependencia ausente o duplicada: "+
                               symbol.decode("ascii","replace"))
    if local_norm.count(anchor)!=1:
        raise RuntimeError(name+": punto de inserción actualizar() no es único")
    at=local_norm.index(anchor)
    # Guardar original byte por byte, incluyendo saltos CRLF y métodos propios.
    local_anchor=b"    public function actualizar()"
    if b"\\r\\n" in local:
        method=method.replace(b"\\n",b"\\r\\n")
    before=local.find(local_anchor)
    if before<0 or local.count(local_anchor)!=1:
        raise RuntimeError(name+": cabecera del método actualizar inesperada")
    result=local[:before]+method+local[before:]
    if result.replace(method,b"",1)!=local:
        raise RuntimeError(name+": no se conservó el controlador original")
    print("CONCILIACIÓN PUNTUAL: "+name+" (solo guardarProgramasAliado; personalizaciones intactas)")
    return result

def overlay_convocatoria_model(local, old, desired):
    """Reemplazar exclusivamente crear() si Hostinger no lo personalizó."""
    name="app/models/ConvocatoriaModel.php"
    if local is None or old is None:
        raise RuntimeError(name+": no existe base o modelo de producción")
    previous=lf(old)
    expected=lf(desired)
    live=lf(local)
    old_header=b"    public function crear($datos, $estadosIds)"
    new_header=b"    /** Transacción común:"
    after=b"    public function actualizar("
    old_start=previous.find(old_header)
    new_start=expected.find(new_header)
    if min(old_start,new_start)<0:
        raise RuntimeError(name+": faltan puntos de integración de crear()")
    old_end=previous.find(after,old_start)
    new_end=expected.find(after,new_start)
    if min(old_end,new_end)<0 or previous[:old_start]!=expected[:new_start] or previous[old_end:]!=expected[new_end:]:
        raise RuntimeError(name+": revisión V3 modifica otras funciones; no fusionar")
    old_method=previous[old_start:old_end]
    new_method=expected[new_start:new_end]
    if live.count(new_method)==1:
        print("CONSERVADO: "+name+" (transacción y vínculo V3 ya instalados)")
        return local
    if live.count(old_method)!=1:
        raise RuntimeError(name+": crear() fue personalizado; no cambiarlo sin revisar diferencias")
    # Nada fuera de crear() puede ser alterado por esta conciliación.
    local_method=old_method.replace(b"\\n",b"\\r\\n") if b"\\r\\n" in local else old_method
    patched_method=new_method.replace(b"\\n",b"\\r\\n") if b"\\r\\n" in local else new_method
    if local.count(local_method)!=1:
        raise RuntimeError(name+": definición crear() no coincide exactamente con la base")
    print("CONCILIACIÓN PUNTUAL: "+name+" (solo crear(); otras personalizaciones intactas)")
    return local.replace(local_method,patched_method,1)

def combine(local,old,new,stage,name):
    if local is None:
        if old is not None:raise RuntimeError("Archivo existente ausente: "+name)
        return new
    if lf(local)==lf(new):return local
    if old is None:raise RuntimeError("Archivo nuevo con cambios propios: "+name)
    if lf(local)==lf(old):return new.replace(b"\n",b"\r\n") if b"\r\n" in local else new
    # Preservar cambios de producción mediante 3-way; rechazar solapamientos.
    for key,value in (("actual",local),("anterior",old),("nuevo",new)):
        (stage/key).write_bytes(lf(value))
    result=subprocess.run(["git","merge-file","-p",str(stage/"actual"),
                           str(stage/"anterior"),str(stage/"nuevo")],
                          stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    if result.returncode:
        raise RuntimeError("CONFLICTO de personalizaciones en "+name+"; sitio intacto")
    return result.stdout.replace(b"\n",b"\r\n") if b"\r\n" in local else result.stdout
def validate(name,data,stage,new):
    ext=Path(name).suffix
    if ext not in (".php",".js"):return
    file=stage/("validar"+ext)
    file.write_bytes(data)
    if ext==".php":
        cmd=["php","-l",str(file)]
    else:
        node=shutil.which("node") or shutil.which("nodejs")
        if not node:
            if new is not None and lf(data)==lf(new):
                print("JS SHA verificado sin Node: "+name)
                return
            raise RuntimeError("JS personalizado sin comprobación de sintaxis: "+name)
        cmd=[node,"--check",str(file)]
    result=subprocess.run(cmd,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    if result.returncode:
        raise RuntimeError("ERROR de sintaxis "+name+": "+
                           (result.stderr or result.stdout).decode("utf-8","replace")[-700:])
def atomic(path,data,mode):
    path.parent.mkdir(parents=True,exist_ok=True)
    temp=path.with_name(path.name+".aliados-v3-tmp")
    with open(str(temp),"wb") as out:
        out.write(data);out.flush();os.fsync(out.fileno())
    os.chmod(str(temp),mode)
    os.replace(str(temp),str(path))
def prepare(site,stage):
    plan=[]
    incompatibles=[]
    for name,sha in SHA.items():
        desired=fetch(SOURCE,name)
        if blob(desired)!=sha:
            raise RuntimeError("SHA origen inesperado: "+name)
        old=None if name in NEW else fetch(BASE,name)
        try:
            path=located(site,name)
            live=path.read_bytes() if path.is_file() else None
            if name=="app/controllers/ConvocatoriaController.php":
                candidate=overlay_convocatoria_controller(live,old,desired)
            elif name=="app/models/ConvocatoriaModel.php":
                candidate=overlay_convocatoria_model(live,old,desired)
            else:
                candidate=combine(live,old,desired,stage,name)
            if candidate!=live:
                validate(name,candidate,stage,desired)
                plan.append((name,path,live,candidate,stat.S_IMODE(path.stat().st_mode) if live is not None else 0o644))
                print("PREPARADO: "+name)
            else:
                print("SIN CAMBIOS: "+name)
        except RuntimeError as error:
            incompatibles.append(name+": "+str(error))
            print("CONFLICTO DETECTADO: "+name+"; se conserva sin cambios")
    try:
        path=located(site,MODULE)
        live=path.read_bytes() if path.is_file() else None
        candidate=module_patch(live)
        if candidate!=live:
            validate(MODULE,candidate,stage,None)
            plan.append((MODULE,path,live,candidate,stat.S_IMODE(path.stat().st_mode)))
            print("INTEGRACIÓN MODULAR: scripts V3 en parcial (sin tocar crear_modal.php)")
        else:
            print("SIN CAMBIOS: "+MODULE)
    except RuntimeError as error:
        incompatibles.append(MODULE+": "+str(error))
        print("CONFLICTO DETECTADO: "+MODULE+"; se conserva sin cambios")
    if incompatibles:
        raise RuntimeError(
            str(len(incompatibles))+" conflicto(s). SQL NO ejecutado; SITIO INTACTO.\\n - "+
            "\\n - ".join(incompatibles)
        )
    return plan
def restore(site,backup):
    info=json.loads((backup/"respaldo.json").read_text(encoding="utf-8"))
    if info["sitio"]!=str(site):raise RuntimeError("El respaldo es de otro sitio")
    for item in info["archivos"]:
        path=located(site,item["nombre"])
        if not path.is_file() or digest(path.read_bytes())!=item["instalado"]:
            raise RuntimeError("No restaurar: archivo modificado tras publicar "+item["nombre"])
    for item in reversed(info["archivos"]):
        path=located(site,item["nombre"])
        if item["original"] is None:path.unlink()
        else:
            file=backup/"originales"/item["nombre"]
            if digest(file.read_bytes())!=item["original"]:
                raise RuntimeError("Respaldo alterado: "+item["nombre"])
            atomic(path,file.read_bytes(),item["modo"])
    print("RESTAURADO: sólo archivos V3; base de datos intacta")
def main():
    parser=argparse.ArgumentParser()
    m=parser.add_mutually_exclusive_group(required=True)
    m.add_argument("--check",action="store_true")
    m.add_argument("--apply",action="store_true")
    m.add_argument("--restore")
    parser.add_argument("--site",required=True)
    parser.add_argument("--backup-root",default=str(Path.home()/"respaldos-impe-aliados"))
    args=parser.parse_args()
    site=Path(args.site).resolve()
    private=Path(args.backup_root).resolve()
    if not (site/"index.php").is_file():raise RuntimeError("public_html inválido")
    if site==private or site in private.parents:raise RuntimeError("Respaldo dentro del CRM")
    if args.restore:
        restore(site,Path(args.restore).resolve());return
    for dep in ("php","git"):
        if not shutil.which(dep):raise RuntimeError("Dependencia no disponible: "+dep)
    private.mkdir(parents=True,exist_ok=True)
    os.chmod(str(private),0o700)
    with tempfile.TemporaryDirectory(prefix="aliados-v3-test-",dir=str(private)) as temp:
        plan=prepare(site,Path(temp))
        print("REVISIÓN COMPATIBLE: "+str(len(plan))+" archivos. SQL NO ejecutado.")
        if args.check:
            print("SITIO INTACTO")
            return
        if not plan:
            print("SIN CAMBIOS: V3 ya instalada")
            return
        for name,path,old,candidate,mode in plan:
            now=path.read_bytes() if path.is_file() else None
            if now!=old:raise RuntimeError("Cambio concurrente: "+name)
        backup=private/("aliados-v3-"+datetime.datetime.utcnow().strftime("%Y%m%d-%H%M%S-%f"))
        backup.mkdir(mode=0o700)
        info={"sitio":str(site),"origen":SOURCE,"archivos":[]}
        for name,path,old,candidate,mode in plan:
            if old is not None:
                src=backup/"originales"/name
                src.parent.mkdir(parents=True,exist_ok=True)
                src.write_bytes(old);os.chmod(str(src),0o600)
            info["archivos"].append({"nombre":name,"original":digest(old) if old is not None else None,
                                     "instalado":digest(candidate),"modo":mode})
        meta=backup/"respaldo.json"
        meta.write_text(json.dumps(info,indent=2),encoding="utf-8")
        os.chmod(str(meta),0o600)
        completed=[]
        try:
            for name,path,old,candidate,mode in plan:
                if (path.read_bytes() if path.is_file() else None)!=old:
                    raise RuntimeError("Cambio concurrente: "+name)
                atomic(path,candidate,mode);completed.append((path,old,mode))
                if digest(path.read_bytes())!=digest(candidate):
                    raise RuntimeError("Verificación de escritura falló: "+name)
        except Exception:
            for path,old,mode in reversed(completed):
                if old is None:path.unlink()
                else:atomic(path,old,mode)
            raise
        print("PUBLICADO: ajustes V3. Respaldo privado: "+str(backup))
        print("PENDIENTE: migración explícita de Canales manuales. NO se ejecutó SQL.")
if __name__=="__main__":
    try:main()
    except Exception as e:
        print("ERROR: "+str(e),file=sys.stderr);sys.exit(1)
