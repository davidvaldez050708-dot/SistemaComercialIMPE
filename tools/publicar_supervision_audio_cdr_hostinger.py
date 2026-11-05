#!/usr/bin/env python3
"""Publicador selectivo: Supervisión de Ventas, grabación CDR propia sin OUT_END. Sin SQL.

Sólo admite las versiones verificadas de Hostinger; preserva los servicios
de llamadas y evidencia de telefonía. Incluye --check, --apply y --restore.
"""
import argparse
import datetime
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import urllib.request

REVISION = "b97b7c987cf0630ec25c508649952e506ca2f313"
REPO = "davidvaldez050708-dot/SistemaComercialIMPE"
FILES = {
    "app/services/SupervisionLlamadasRepository.php": (
        "blob:258678d9984ef4c55d32168c770efae3f2d8daaf",
        "6138ac468a8376ed42862d14bece6ed2b014593a"),
    "app/services/SupervisionLlamadasAudioService.php": (
        "blob:85004529365987c3977118f655ae4a505f1c02a7",
        "9063770cd64297c2710dfa1a756abfd4c7351178"),
    "app/services/SupervisionVentasAudioCdrPolicy.php": (
        None, "fd37188517071057c508a3e72cf67a503b0b0956"),
}
DEPS = {
    "app/controllers/SupervisionLlamadasController.php":
        "blob:de3d17a8440e4f4c23c64ef02ee20dbfc74298d3",
    "app/views/telefonia/supervision_llamadas.php":
        "blob:8935942e49e0fe070f29e255e0c4dfbddf5b0018",
    "app/services/TelefoniaVentasGrabacionCdrService.php":
        "blob:61bb19490578975fd214bf4ab870de1bde480d4f",
    "app/services/ZadarmaCallLookupService.php":
        "blob:e91635ae104ddebc033c4e4a543203f3cec9b7ec",
}
CUSTOM = {
    "app/services/SupervisionLlamadasRepository.php": {
        "base": "blob:258678d9984ef4c55d32168c770efae3f2d8daaf",
        "edits": [
            [
                "require_once __DIR__.'/TelefoniaResultadoVentasCatalogo.php';",
                "require_once __DIR__.'/TelefoniaResultadoVentasCatalogo.php';\nrequire_once __DIR__.'/SupervisionVentasAudioCdrPolicy.php';"
            ],
            [
                "'audio_consultable'=>$source==='venta'?TelefoniaVentasLlamadasService::audioConsultable(array_replace($r,$facts,['pbx_call_id'=>$ref,'extension'=>$ext,'direccion'=>$direction,'registro'=>$registroVenta,'resultado_guardado'=>$resultadoGuardado])):($facts['conectada']||($session!==''&&$classification==='INTERNA'))",
                "'audio_consultable'=>$source==='venta'?(\n                TelefoniaVentasLlamadasService::audioConsultable(array_replace($r,$facts,[\n                    'pbx_call_id'=>$ref,'extension'=>$ext,'direccion'=>$direction,\n                    'registro'=>$registroVenta,'resultado_guardado'=>$resultadoGuardado]))\n                || SupervisionVentasAudioCdrPolicy::candidata([\n                    'source'=>'venta','area'=>'asesores','usuario_id'=>$uid,\n                    'pbx_call_id'=>$ref,'extension'=>$ext,'direccion'=>$direction,\n                    'fecha'=>(string)($r['fecha']??''),'resultado'=>$result,\n                    'resultado_guardado'=>$resultadoGuardado,\n                    'conectada'=>!empty($facts['conectada']),\n                    'finalizada'=>!empty($facts['finalizada']),\n                    'origen_extension'=>$r['origen_extension']??'',\n                    'solicitud_id'=>$r['solicitud_id']??null,\n                ])\n            ):($facts['conectada']||($session!==''&&$classification==='INTERNA'))"
            ]
        ]
    },
    "app/services/SupervisionLlamadasAudioService.php": {
        "base": "blob:85004529365987c3977118f655ae4a505f1c02a7",
        "edits": [
            [
                "require_once __DIR__.'/TelefoniaResultadoVentasService.php';",
                "require_once __DIR__.'/TelefoniaResultadoVentasService.php';\nrequire_once __DIR__.'/SupervisionVentasAudioCdrPolicy.php';\nrequire_once __DIR__.'/TelefoniaVentasGrabacionCdrService.php';"
            ],
            [
                "        $ref=$call['pbx_call_id'];$ext=$call['extension'];$owner=$call['usuario_id'];\n        // Un envío no anula un resultado sin contacto, ni habilita resultados pendientes.\n",
                "        $ref=$call['pbx_call_id'];$ext=$call['extension'];$owner=$call['usuario_id'];\n        // Este punto ocurre DESPUÉS de obtener(..., true): el alcance vigente\n        // del supervisor y la pertenencia de la llamada están comprobados.\n        // Nunca usar el endpoint personal del asesor en nombre del supervisor.\n        if(SupervisionVentasAudioCdrPolicy::candidata($call))\n            return self::resolverCdrPendiente($call,$provider);\n        // Un envío no anula un resultado sin contacto, ni habilita resultados pendientes.\n"
            ],
            [
                "    public static function sanear(array $response): array",
                "    /**\n     * Solo saliente PROPIA sin OUT_END, confirmada en CDR Zadarma de esa PBX,\n     * extensión y horario. No modifica eventos/duración almacenados ni audio.\n     * Los tests pueden inyectar estadísticas para no contactar al proveedor.\n     */\n    public static function resolverCdrPendiente(\n        array $call,\n        object $provider,\n        ?callable $stats=null,\n        ?DateTimeImmutable $now=null\n    ): array {\n        if(!SupervisionVentasAudioCdrPolicy::candidata($call))\n            throw new DomainException('La llamada no cumple los requisitos de grabación CDR propia.');\n        $cdr=TelefoniaVentasGrabacionCdrService::resolver(\n            SupervisionVentasAudioCdrPolicy::paraVerificar($call),$stats,$now);\n        if(!$cdr)return self::sanear([\n            'disponible'=>false,'segmentos_info'=>[],'records'=>[],\n            'estado'=>'TRAMO_PENDIENTE_VINCULACION']);\n        try {\n            $record=$provider->obtenerGrabacionPorCallId($cdr['call_id']);\n        } catch(Throwable $error) {\n            return self::sanear([\n                'disponible'=>false,'segmentos_info'=>[],'records'=>[],\n                'estado'=>'ERROR_TEMPORAL']);\n        }\n        if(!is_array($record)||empty($record['link']))return self::sanear([\n            'disponible'=>false,'segmentos_info'=>[],'records'=>[],\n            'estado'=>'GRABACION_EN_PREPARACION']);\n        return self::sanear([\n            'disponible'=>true,'segmentos_info'=>[[\n                'indice'=>0,'etiqueta'=>'Grabación de la llamada',\n                'segundos'=>(int)$cdr['seconds']]],\n            'records'=>[0=>$record],'estado'=>'DISPONIBLE']);\n    }\n\n    public static function sanear(array $response): array"
            ]
        ]
    }
}

def digest(data):
    return hashlib.sha256(data).hexdigest()

def blob(data):
    return hashlib.sha1(b"blob "+str(len(data)).encode()+b"\0"+data).hexdigest()

def target(root, rel):
    path=root/rel
    if path.is_symlink() or os.path.commonpath([str(root.resolve()),str(path.resolve())]) != str(root.resolve()):
        raise RuntimeError("Ruta insegura: "+rel)
    return path

def fetch(rel, sha):
    url=f"https://raw.githubusercontent.com/{REPO}/{REVISION}/{rel}"
    with urllib.request.urlopen(url,timeout=20) as response:
        data=response.read(1000000)
    if len(data)>999999 or blob(data)!=sha:
        raise RuntimeError("Integridad Git no coincide: "+rel)
    return data

def syntax(rel, data):
    if rel.endswith(".php"):
        php=shutil.which("php")
        if not php:
            raise RuntimeError("PHP CLI requerido para comprobar sintaxis.")
        suffix=".php"
        command=[php,"-l"]
    elif rel.endswith(".js"):
        node=shutil.which("node")
        if not node:
            print("AVISO: Node.js ausente; JavaScript verificado por CI de GitHub.")
            return
        suffix=".js"
        command=[node,"--check"]
    else:
        return
    with tempfile.NamedTemporaryFile(prefix="impe-ventas-check-",suffix=suffix,delete=False) as tmp:
        tmp.write(data)
        name=tmp.name
    try:
        p=subprocess.run(command+[name],stdout=subprocess.PIPE,stderr=subprocess.PIPE,universal_newlines=True)
        if p.returncode:
            raise RuntimeError("Sintaxis no compatible en "+rel+": "+p.stderr.strip())
    finally:
        os.unlink(name)

def same_version(data, pin):
    if data is None: return False
    if pin.startswith("blob:"): return blob(data)==pin[5:]
    if pin.startswith("sha256:"): return digest(data)==pin[7:]
    raise RuntimeError("Huella de archivo no reconocida.")

def reconcile_custom(rel, value):
    conf=CUSTOM[rel]
    if value is None:
        raise RuntimeError("VARIANTE HOSTINGER AUSENTE (sin cambios): "+rel)
    original=conf["base"]
    replacements=[(a.encode("utf-8"), b.encode("utf-8"))
                  for a,b in conf["edits"]]
    if same_version(value,original):
        final=value
        for a,b in replacements:
            if final.count(a)!=1 or final.count(b):
                raise RuntimeError("CONFLICTO DE BLOQUES (sin cambios): "+rel)
            final=final.replace(a,b,1)
        return final,"CONCILIADO PERSONALIZADO"
    # Verificación reversible: sólo reconoce exactamente nuestros cambios
    # sobre el archivo original, no versiones arbitrarias ni ediciones posteriores.
    reverted=value
    for a,b in reversed(replacements):
        if reverted.count(b)!=1:
            raise RuntimeError("VARIANTE DESCONOCIDA (sin cambios): "+rel)
        reverted=reverted.replace(b,a,1)
    if not same_version(reverted,original):
        raise RuntimeError("VARIANTE DESCONOCIDA (sin cambios): "+rel)
    return value,"YA CONCILIADO PERSONALIZADO"

def save_atomic(path, data, mode=0o644):
    path.parent.mkdir(parents=True,exist_ok=True)
    handle,name=tempfile.mkstemp(dir=path.parent,prefix=".impe-ventas-")
    try:
        with os.fdopen(handle,"wb") as fd:
            fd.write(data)
            fd.flush()
            os.fsync(fd.fileno())
        os.chmod(name,mode)
        os.replace(name,path)
    finally:
        if os.path.exists(name):os.unlink(name)

def check(site):
    if not (site/"index.php").is_file():
        raise RuntimeError("La ruta del sitio no corresponde a public_html.")
    for rel,expected in DEPS.items():
        path=target(site,rel)
        if not path.is_file() or not same_version(path.read_bytes(),expected):
            raise RuntimeError("DEPENDENCIA DIFERENTE (sin cambios): "+rel)
        print("DEPENDENCIA COMPATIBLE:",rel)
    plan=[]
    problems=[]
    for rel,(before,afterblob) in FILES.items():
        try:
            path=target(site,rel)
            data=b'' if rel in CUSTOM else fetch(rel,afterblob)
            old=path.read_bytes() if path.exists() else None
            if rel in CUSTOM:
                data,status=reconcile_custom(rel,old)
                if blob(data)!=afterblob:
                    raise RuntimeError("Integridad del parche personalizado no coincide: "+rel)
            else:
                if old is None and before is not None:
                    raise RuntimeError("ARCHIVO AUSENTE (sin cambios): "+rel)
                if old is not None and before is None and old!=data:
                    raise RuntimeError("ARCHIVO NUEVO YA PERSONALIZADO (sin cambios): "+rel)
                if old is not None and old!=data and not same_version(old,before):
                    raise RuntimeError("VERSIÓN DIFERENTE (sin cambios): "+rel)
                status="YA INSTALADO" if old==data else "NUEVO" if old is None else "COMPATIBLE"
            # Comprobar el candidato fusionado, no sólo la versión remota.
            syntax(rel,data)
            print(status+":",rel)
            plan.append((rel,old,data))
        except Exception as ex:
            reason=str(ex)
            print("INCOMPATIBLE:",rel,"-",reason)
            problems.append(rel+": "+reason)
    if problems:
        raise RuntimeError("SIN PUBLICAR: "+str(len(problems))+
                           " incompatibilidad(es): "+" | ".join(problems))
    print("PREPUBLICACIÓN COMPATIBLE:",sum(x[1]!=x[2] for x in plan),"archivos; permisos y reproducciones previas protegidos. Sin SQL.")
    return plan

def apply(site,backup_root):
    plan=check(site)
    pending=[x for x in plan if x[1]!=x[2]]
    if not pending:
        print("YA INSTALADO: no hay cambios.");return
    backup_root.mkdir(mode=0o700,parents=True,exist_ok=True)
    name=datetime.datetime.now().strftime("%Y%m%d-%H%M%S-")+os.urandom(4).hex()
    backup=backup_root/name
    backup.mkdir(mode=0o700)
    manifest={"site":str(site.resolve()),"revision":REVISION,"files":[]}
    for rel,old,new in pending:
        path=target(site,rel)
        item={"path":rel,"before_sha256":digest(old) if old is not None else None,
              "after_sha256":digest(new),"mode":(path.stat().st_mode&0o777) if old else 0o644}
        if old is not None:
            copy=backup/rel;copy.parent.mkdir(parents=True,exist_ok=True)
            copy.write_bytes(old);os.chmod(copy,0o600)
        manifest["files"].append(item)
    (backup/"respaldo.json").write_text(json.dumps(manifest,indent=2),encoding="utf-8")
    os.chmod(backup/"respaldo.json",0o600)
    installed=[]
    try:
        for rel,old,new in pending:
            path=target(site,rel)
            current=path.read_bytes() if path.exists() else None
            if current!=old:
                raise RuntimeError("CAMBIO CONCURRENTE: "+rel)
            mode=(path.stat().st_mode&0o777) if old else 0o644
            save_atomic(path,new,mode)
            installed.append((rel,old,mode))
    except BaseException:
        for rel,old,mode in reversed(installed):
            path=target(site,rel)
            if old is None:
                if path.exists():path.unlink()
            else:save_atomic(path,old,mode)
        raise
    print("APLICADO: Supervisión permite consulta CDR propia sin OUT_END; controller, vistas y audio existente intactos. Sin SQL.")
    print("RESPALDO:",backup)

def restore(site,path):
    manifest_path=path/"respaldo.json"
    if not manifest_path.is_file():raise RuntimeError("Respaldo no encontrado.")
    m=json.loads(manifest_path.read_text(encoding="utf-8"))
    if m.get("site")!=str(site.resolve()) or m.get("revision")!=REVISION:
        raise RuntimeError("El respaldo no pertenece a este sitio/versión.")
    actions=[]
    for f in m["files"]:
        rel=f["path"]
        if rel not in FILES: raise RuntimeError("Ruta inesperada en respaldo.")
        dest=target(site,rel)
        if not dest.is_file() or digest(dest.read_bytes())!=f["after_sha256"]:
            raise RuntimeError("VERSIÓN DIFERENTE, restauración cancelada: "+rel)
        original=None
        if f["before_sha256"] is not None:
            original=(path/rel).read_bytes()
            if digest(original)!=f["before_sha256"]:
                raise RuntimeError("Respaldo corrupto: "+rel)
        actions.append((dest,original,int(f["mode"])))
    for dest,original,mode in actions:
        if original is None:dest.unlink()
        else:save_atomic(dest,original,mode)
    print("RESTAURADO: sin SQL ni grabaciones afectadas.")

def main():
    p=argparse.ArgumentParser()
    p.add_argument("--site",required=True)
    p.add_argument("--backup-root",default=str(Path.home()/"respaldos-impe-ventas-cierre-api"))
    g=p.add_mutually_exclusive_group(required=True)
    g.add_argument("--check",action="store_true")
    g.add_argument("--apply",action="store_true")
    g.add_argument("--restore",metavar="CARPETA")
    args=p.parse_args()
    site=Path(args.site).expanduser().resolve()
    if args.restore:restore(site,Path(args.restore).expanduser().resolve())
    elif args.apply:apply(site,Path(args.backup_root).expanduser())
    else:check(site);print("SITIO INTACTO")

if __name__=="__main__":
    try:main()
    except Exception as ex:
        print("ERROR:",ex)
        raise SystemExit(1)
