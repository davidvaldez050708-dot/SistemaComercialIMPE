#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Gestión Comercial V3: gestión de Potenciales, subetapas y protección PBX.
CHECK/APPLY/ROLLBACK protegidos por hashes Git y respaldos fuera de public_html.
Python 3.6+; sin SQL, sin escrituras PBX y sin modificar permisos/activación.
"""
from __future__ import print_function
import argparse
import datetime
import hashlib
import json
import os
import shutil
import stat
import subprocess
import sys
import tempfile
import uuid

# Base conocida en Hostinger -> nueva versión de gestión segura.
# La creación de un único servicio PHP adicional es explícita.
ARCHIVOS = [
    ("app/services/EmbudoComercialResultadoBridge.php",
     "cab8890f6773f2acb69f5b8e6ead312b321325a1",
     "0aca359fc0fef1e462dab6802fe428e9f62c23e7"),
    ("app/services/EmbudoComercialV3GestionPotencialService.php",
     None, "4c9bb4ef399001759a2ad469f3fb0d8e570d45b8"),
    ("app/services/EmbudoComercialRepository.php",
     "663afcf50ce374fd957da6d84282f8ef394b3cc6",
     "3e4c6e6acc909e4478c76358f20d96a8f261c055"),
    ("public/javascript/embudo_comercial_v3_operativo.js",
     "9234a6d1607b9028760d172aa7894fbc8f92c449",
     "483ecc09201ce51be7a752c0c653fc7f1159c86d"),
    ("public/css/embudo_v3_operativo.css",
     "6a853b84b9dce7ffa53298ff9e755ef00ac405e4",
     "b7b0bbe56a9bc191c0475ac1efaaa57a41e0bc48"),
    ("app/views/embudo_comercial/operativo_v3.php",
     "e306bb8995778c489c55735d78bafd9ef51832c5",
     "e5b26a84ad2baa178feb74c54cd4b4771ee3e00d"),
    ("app/views/embudo_comercial/detalle.php",
     "715c3a36c890965f0343e33c89067f997aa4ba5a",
     "f4c7449bf96a4c680db7ce5e0552a3b0cd624068"),
    ("app/controllers/EmbudoComercialController.php",
     "b88ee8c9c961c7b930e605147d55230a159d956b",
     "16ba342a7fd88534e072eb5c28d98d6702926ea7"),
]
NO_MODIFICAR = {
    "app/views/embudo_comercial/index.php":
      "766f3d06c510856f30b1b8ae1e376f686be74177",
    "app/services/EmbudoComercialV3AccesoControlado.php":
      "480ce33e3622eb44116c3aed578c0a753378eaa9",
    "app/services/EmbudoComercialV3MovimientosPermitidos.php":
      "92950fe72f02c938050c8598cd0ed96cd1552c6e",
    "app/services/EmbudoComercialMovimientoService.php":
      "9621a3e2a2b96d0c89ecd597a17c323c4ee09139",
    "app/services/EmbudoComercialPoliticaPropuesta.php":
      "e5ec54f6fac872d09bf534a06debf7e38ee410c1",
    "app/services/EmbudoComercialV3IndicadoresVista.php":
      "c7419b2521392c48a0eebefb54bc9c7fd1cfda1c",
}
CONFIRMAR = "PUBLICAR_GESTION_POTENCIALES_V3_IMPE"
REVERTIR = "REVERTIR_GESTION_POTENCIALES_V3_IMPE"

class Bloqueo(Exception):
    pass

def blob_bytes(b):
    return hashlib.sha1(("blob %d\0" % len(b)).encode("ascii") + b).hexdigest()

def leer(path):
    with open(path, "rb") as f:
        return f.read()

def blob(path):
    return blob_bytes(leer(path))

def raiz(path, required=True):
    if os.path.islink(path):
        raise Bloqueo("carpeta raíz enlazada: " + path)
    absolute = os.path.realpath(os.path.abspath(path))
    if required and not os.path.isdir(absolute):
        raise Bloqueo("carpeta no existe: " + path)
    return absolute

def dentro(path, base):
    return path == base or path.startswith(base + os.sep)

def confiable(base, relative):
    p = base
    for part in relative.split("/"):
        p = os.path.join(p, part)
        if os.path.islink(p):
            raise Bloqueo("ruta enlazada: " + relative)
    if not dentro(os.path.realpath(p), base):
        raise Bloqueo("ruta fuera de la carpeta: " + relative)
    return p

def lint_php(path):
    try:
        subprocess.check_output(["php", "-l", path], stderr=subprocess.STDOUT)
    except (OSError, subprocess.CalledProcessError):
        raise Bloqueo("sintaxis PHP no válida: " + path)

def validacion_contratos(stage):
    servicio=leer(confiable(stage,"app/services/EmbudoComercialV3GestionPotencialService.php"))
    bridge=leer(confiable(stage,"app/services/EmbudoComercialResultadoBridge.php"))
    repo=leer(confiable(stage,"app/services/EmbudoComercialRepository.php"))
    ctrl=leer(confiable(stage,"app/controllers/EmbudoComercialController.php"))
    view=leer(confiable(stage,"app/views/embudo_comercial/operativo_v3.php"))
    detalle=leer(confiable(stage,"app/views/embudo_comercial/detalle.php"))
    js=leer(confiable(stage,"public/javascript/embudo_comercial_v3_operativo.js"))
    css=leer(confiable(stage,"public/css/embudo_v3_operativo.css"))
    clauses=[
       (b"begin_transaction()" in servicio, "transaccion comercial"),
       (b"FOR UPDATE" in servicio, "bloqueo de asignacion"),
       (b"rollback()" in servicio, "reversion de transaccion"),
       (b"subetapaEsperada" in servicio, "control de concurrencia"),
       (b"prospecto_embudo_subetapas_historial" in servicio, "auditoria"),
       (b"prospecto_embudo_agenda" in servicio, "agenda"),
       (b"INTERESADO" in bridge and b"PROCESO_INSCRIPCION" in bridge, "regresiones PBX"),
       (b"gestionarPotencialV3()" in ctrl, "endpoint V3 protegido"),
       (b"hash_equals" in ctrl, "CSRF"),
       (b"historial_subetapas" in repo, "historial propietario"),
       (b"historial_subetapas" in detalle, "historial visible"),
       (b"data-v3-gestionar" in view, "icono de gestion"),
       (b"impe-v3o-potencial-dialog" in view, "formulario institucional"),
       (b"data-v3-subetapa" in view, "subetapa anterior"),
       (b"gestionarPotencialV3" in js, "guardado desde navegador"),
       (b"IMPE_TELEPHONY_PERSISTENT" in js, "telefono inalterado"),
       (b"data-v3-mover" in view, "arrastre y movimientos conservados"),
       (b"impe-v3o-ayuda" in css, "estilos institucionales"),
    ]
    for ok,reason in clauses:
        if not ok:
            raise Bloqueo("contrato no verificado: "+reason)
    print("CONTRATOS_GESTION_OK: propietario, etapa, subetapa, agenda, PBX, CSRF e historial.")

def lint_js(path):
    # Se usa Node si está disponible. El JavaScript no se ejecuta en check.
    node=shutil.which("node")
    if node is None:
        print("AVISO: Node.js ausente; se verifican contratos y hashes JS.")
        return
    try:
        subprocess.check_output([node,"--check",path],stderr=subprocess.STDOUT)
        print("SINTAXIS_JS_OK")
    except (OSError,subprocess.CalledProcessError):
        raise Bloqueo("sintaxis JS inválida: "+path)

def paquete(stage):
    for rel, old, new in ARCHIVOS:
        path = confiable(stage, rel)
        if not os.path.isfile(path) or blob(path) != new:
            raise Bloqueo("archivo incorrecto en stage: " + rel)
        if rel.endswith(".php"):
            lint_php(path)
        if rel.endswith(".js"):
            lint_js(path)
    validacion_contratos(stage)
    print("PAQUETE_GESTION_V3_OK: ocho archivos exactos y contratos verificados.")

def validar_intangibles(site):
    for rel, expected in NO_MODIFICAR.items():
        path = confiable(site, rel)
        if not os.path.isfile(path) or blob(path) != expected:
            raise Bloqueo("dependencia protegida cambió: " + rel)

def estado(site):
    validar_intangibles(site)
    estados=[]
    for rel,old,new in ARCHIVOS:
        path=confiable(site,rel)
        if os.path.isfile(path):
            actual=blob(path)
            if actual==new:
                print("V3_GESTION_LISTO | "+rel)
                estados.append("READY")
            elif old is not None and actual==old:
                print("BASE_OK | "+rel)
                estados.append("BASE")
            else:
                raise Bloqueo("personalizacion o base inesperada: "+rel)
        elif old is None and not os.path.lexists(path):
            print("AUSENTE_NUEVO | "+rel)
            estados.append("BASE")
        else:
            raise Bloqueo("archivo ausente o enlazado: "+rel)
    if all(x=="BASE" for x in estados):
        print("COMPATIBLE: listo para publicar gestión segura V3.")
        return "BASE"
    if all(x=="READY" for x in estados):
        print("YA_PUBLICADA: gestión segura de Potenciales V3.")
        return "READY"
    raise Bloqueo("estado mixto; no sobreescribir archivos.")

def atomico(dest, source, mode):
    fd,tmp=tempfile.mkstemp(prefix=".impe-v3-compact-", dir=os.path.dirname(dest))
    try:
        with os.fdopen(fd,"wb") as out,open(source,"rb") as inp:
            shutil.copyfileobj(inp,out)
            out.flush()
            os.fsync(out.fileno())
        os.chmod(tmp,mode)
        os.replace(tmp,dest)
    finally:
        if os.path.isfile(tmp):
            os.unlink(tmp)

def manifest(dir_, data):
    path=os.path.join(dir_,"manifest.json")
    fd,tmp=tempfile.mkstemp(prefix=".manifest-v3-",dir=dir_)
    try:
        with os.fdopen(fd,"w") as f:
            json.dump(data,f,sort_keys=True,indent=2)
            f.flush()
            os.fsync(f.fileno())
        os.chmod(tmp,0o600)
        os.replace(tmp,path)
    finally:
        if os.path.isfile(tmp):
            os.unlink(tmp)

def respaldar(site,root):
    if not os.path.isdir(root):
        os.makedirs(root,0o700)
    root=raiz(root)
    if dentro(root,site):
        raise Bloqueo("respaldo no puede estar dentro del sitio publico.")
    os.chmod(root,0o700)
    name=datetime.datetime.utcnow().strftime("%Y%m%d-%H%M%S")+"-"+uuid.uuid4().hex[:8]
    directory=os.path.join(root,name)
    os.mkdir(directory,0o700)
    modes={}
    for rel,old,new in ARCHIVOS:
        p=confiable(site,rel)
        if old is None:
            if os.path.lexists(p):
                raise Bloqueo("archivo nuevo no esperado: "+rel)
            modes[rel]=None
            continue
        if not os.path.isfile(p) or blob(p)!=old:
            raise Bloqueo("base cambio durante respaldo: "+rel)
        dst=os.path.join(directory,rel)
        parent=os.path.dirname(dst)
        if not os.path.isdir(parent):
            os.makedirs(parent,0o700)
        shutil.copy2(p,dst)
        os.chmod(dst,0o600)
        if blob(dst)!=old:
            raise Bloqueo("respaldo incorrecto: "+rel)
        modes[rel]=stat.S_IMODE(os.stat(p).st_mode)
    data={"version":1,"site":site,
          "fecha_utc":datetime.datetime.utcnow().isoformat()+"Z",
          "estado":"RESPALDADO","modes":modes}
    manifest(directory,data)
    return directory,data

def restaurar(site,directory,data,permit_old=False):
    # Revisar TODO el sitio antes de revertir para no pisar personalizaciones.
    for rel,old,new in ARCHIVOS:
        dest=confiable(site,rel)
        if old is None:
            if not os.path.isfile(dest):
                if permit_old and not os.path.lexists(dest):
                    continue
                raise Bloqueo("archivo nuevo no encontrado: "+rel)
            if blob(dest)!=new:
                raise Bloqueo("servicio nuevo modificado: "+rel)
            continue
        backup=os.path.join(directory,rel)
        if not os.path.isfile(backup) or blob(backup)!=old:
            raise Bloqueo("respaldo alterado: "+rel)
        if not os.path.isfile(dest):
            raise Bloqueo("archivo del sitio desaparecio: "+rel)
        validos=[new,old] if permit_old else [new]
        if blob(dest) not in validos:
            raise Bloqueo("archivo personalizado, no revertir: "+rel)
    for rel,old,new in reversed(ARCHIVOS):
        dest=confiable(site,rel)
        if old is None:
            if os.path.isfile(dest):
                os.unlink(dest)
        else:
            atomico(dest,os.path.join(directory,rel),data["modes"][rel])
            if blob(dest)!=old:
                raise Bloqueo("fallo integridad restaurada: "+rel)
    data["estado"]="REVERTIDO"
    manifest(directory,data)

def publicar(site,stage,root):
    current=estado(site)
    if current=="READY":
        print("SIN_CAMBIOS: gestión de Potenciales V3 ya publicada.")
        return
    directory,data=respaldar(site,root)
    print("RESPALDO_PRIVADO_OK: "+directory)
    try:
        for rel,old,new in ARCHIVOS:
            dest=confiable(site,rel)
            src=confiable(stage,rel)
            if old is None:
                if os.path.lexists(dest):
                    raise Bloqueo("servicio nuevo inesperado: "+rel)
                mode=0o644
            else:
                if not os.path.isfile(dest) or blob(dest)!=old:
                    raise Bloqueo("base cambió antes de publicar: "+rel)
                mode=stat.S_IMODE(os.stat(dest).st_mode)
            atomico(dest,src,mode)
            if blob(dest)!=new:
                raise Bloqueo("integridad posterior incorrecta: "+rel)
            if rel.endswith(".php"):
                lint_php(dest)
            print("PUBLICADO: "+rel)
        if estado(site)!="READY":
            raise Bloqueo("comprobación posterior no concuerda.")
        data["estado"]="PUBLICADO"
        manifest(directory,data)
        print("APPLY_OK: gestión de Potenciales V3 con auditoría.")
        print("No se modificó SQL, PBX, V4, resultados, asignaciones ni permisos.")
        print("ROLLBACK_BACKUP: "+directory)
    except BaseException:
        try:
            restaurar(site,directory,data,permit_old=True)
            print("REVERSION_AUTOMATICA_OK")
        except Exception as e:
            print("REVERSION_BLOQUEADA: "+str(e),file=sys.stderr)
        raise

def revertir(site,directory):
    directory=raiz(directory)
    with open(os.path.join(directory,"manifest.json"),"r") as f:
        data=json.load(f)
    if (data.get("site")!=site or data.get("estado") not in ("RESPALDADO","PUBLICADO") or
        set(data.get("modes",{}))!=set(rel for rel,_,_ in ARCHIVOS)):
        raise Bloqueo("manifest no pertenece a esta publicación.")
    restaurar(site,directory,data,permit_old=(data["estado"]=="RESPALDADO"))
    print("ROLLBACK_OK: tablero V3 anterior restaurado; sin tocar registros.")

def check_db_lectura(site):
    # Metadatos exclusivamente: se bloquea si faltan las columnas del contrato.
    auditoria=r"""
$site=$argv[1];
require $site.'/config/db_connection.php';
$db=(new Database())->connect(false);
$required=[
 'prospecto_embudo_subetapas'=>['asignacion_id','subetapa','actualizado_por','actualizado_at','version'],
 'prospecto_embudo_subetapas_historial'=>['asignacion_id','etapa_en_evento','subetapa_anterior','subetapa_nueva','motivo','origen','usuario_id','creado_at'],
 'prospecto_embudo_agenda'=>['asignacion_id','proxima_accion_at','revision_fecha','actualizado_at','actualizado_por'],
 'prospecto_asignaciones'=>['id','asesor_id','vigente'],
 'prospecto_embudo_etapas'=>['asignacion_id','etapa']
];
foreach($required as $tabla=>$columnas){
 $t=$db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
 $t->bind_param('s',$tabla);$t->execute();$r=$t->get_result()->fetch_assoc();$t->close();
 if(!$r || strtoupper((string)$r['ENGINE'])!=='INNODB')exit(2);
 $c=$db->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
 $c->bind_param('s',$tabla);$c->execute();$rs=$c->get_result();$found=[];
 while($x=$rs->fetch_assoc())$found[$x['COLUMN_NAME']]=true;
 $c->close();
 foreach($columnas as $col)if(!isset($found[$col]))exit(3);
}
$db->close();
echo 'ESTRUCTURAS_POTENCIALES_OK';
"""
    try:
        valor=subprocess.check_output(["php","-r",auditoria,"--",site],
                   stderr=subprocess.STDOUT).decode("utf-8","replace").strip()
    except (OSError,subprocess.CalledProcessError):
        raise Bloqueo("esquema de BD V3 incompatible o no disponible; sin escrituras.")
    if valor!="ESTRUCTURAS_POTENCIALES_OK":
        raise Bloqueo("preflight de estructura no confirmado.")
    print("BD_SOLO_LECTURA_OK: tablas, columnas e InnoDB; sin SQL de escritura.")

def main():
    p=argparse.ArgumentParser()
    p.add_argument("--site",required=True)
    p.add_argument("--stage")
    p.add_argument("--backup-root")
    p.add_argument("--backup-dir")
    p.add_argument("--confirm")
    m=p.add_mutually_exclusive_group(required=True)
    m.add_argument("--check",action="store_true")
    m.add_argument("--apply",action="store_true")
    m.add_argument("--rollback",action="store_true")
    a=p.parse_args()
    site=raiz(a.site)
    if not os.path.isfile(os.path.join(site,"config/db_connection.php")):
        raise Bloqueo("raíz del CRM inválida.")
    if a.rollback:
        if a.confirm!=REVERTIR or not a.backup_dir:
            raise Bloqueo("rollback requiere confirmación y respaldo exacto.")
        revertir(site,a.backup_dir)
        return
    if not a.stage:
        raise Bloqueo("falta stage.")
    stage=raiz(a.stage)
    if dentro(stage,site):
        raise Bloqueo("stage dentro de public_html.")
    paquete(stage)
    check_db_lectura(site)
    if a.check:
        status=estado(site)
        print("CHECK_OK: "+("listo para APPLY." if status=="BASE" else "ya aplicado."))
        return
    if a.confirm!=CONFIRMAR or not a.backup_root:
        raise Bloqueo("apply requiere confirmación y respaldo privado.")
    root=raiz(a.backup_root,False)
    if dentro(root,site):
        raise Bloqueo("respaldo dentro del sitio público.")
    publicar(site,stage,root)

if __name__=="__main__":
    try:
        main()
    except (Bloqueo,OSError,ValueError,subprocess.CalledProcessError) as ex:
        print("BLOQUEADO: "+str(ex),file=sys.stderr)
        sys.exit(2)
