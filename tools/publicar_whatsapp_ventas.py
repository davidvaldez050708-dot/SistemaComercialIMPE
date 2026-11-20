#!/usr/bin/env python3
"""Publicación por bloques exactos de WhatsApp Ventas; conserva personalizaciones."""
import argparse
import base64
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile

FILES=('app/services/WhatsAppVentasService.php', 'app/services/WhatsAppContextoService.php', 'app/services/WhatsAppCloudApiService.php', 'app/models/WhatsAppModel.php', 'app/controllers/WhatsAppController.php', 'app/models/RolModel.php', 'app/views/whatsapp/index.php', 'app/views/whatsapp/chat.php', 'app/views/whatsapp/ventas_config.php', 'app/views/whatsapp/ventas_etiqueta.php', 'app/views/whatsapp/ventas_inicio.php', 'public/javascript/whatsapp_ventas.js')
SCHEMA_CHECK=r'''
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
try {
 require $argv[1].'/config/db_connection.php';$db=(new Database())->connect(false);
 $required=[
 'whatsapp_cuentas'=>['id','activo','tipo','es_predeterminada'],
 'whatsapp_conversaciones'=>['id','cuenta_id','responsable_usuario_id','aliado_seguimiento_id','telefono_normalizado','nombre_contacto','ultimo_mensaje_preview','ultimo_mensaje_at'],
 'whatsapp_mensajes'=>['conversacion_id'],
 'whatsapp_conversacion_prospectos'=>['conversacion_id','prospecto_id'],
 'prospectos_comerciales'=>['id','telefono_normalizado'],
 'prospecto_asignaciones'=>['id','prospecto_id','asesor_id','vigente'],
 'seguimientos_vinculacion'=>['id','activo','estado_id','analista_id','telefono_fuente','telefono_verificado','whatsapp_verificado'],
 'usuarios'=>['id','nombre','apellidos','rol_id','estado'], 'roles'=>['id','nombre','estado'],
 'permisos'=>['id','codigo','estado'], 'rol_permisos'=>['rol_id','permiso_id'],
 'estados'=>['id','estado'],
 'asignaciones_territorio'=>['usuario_id','estado_id','tipo_asignacion','activo','fecha_inicio','fecha_fin']];
 foreach($required as $table=>$fields){$cols=array_column($db->query('SHOW COLUMNS FROM `'.$table.'`')->fetch_all(MYSQLI_ASSOC),'Field');if(array_diff($fields,$cols))throw new RuntimeException('ESQUEMA_INCOMPATIBLE: '.$table);}
 if($argv[2]==='apply')$db->query(base64_decode($argv[3],true));
 $exists=$db->query("SHOW TABLES LIKE 'whatsapp_ventas_config'")->num_rows>0;
 if($exists){
  $columns=array_column($db->query('SHOW COLUMNS FROM whatsapp_ventas_config')->fetch_all(MYSQLI_ASSOC),'Field');
  if(array_diff(['cuenta_id','messaging_account_id','plantilla_nombre','plantilla_idioma','plantilla_cuerpo','plantilla_categoria','verificada_at','actualizada_por'],$columns))throw new RuntimeException('CONFIG_INCOMPATIBLE');
 }
 echo $exists?'ESQUEMA_LISTO':'ESQUEMA_LISTO_CONFIG_PENDIENTE';
}catch(RuntimeException $e){echo $e->getMessage();exit(2);}
 catch(Throwable $e){echo 'NO_SE_PUDO_VERIFICAR_EL_ESQUEMA';exit(2);}
'''

def sha(data): return hashlib.sha256(data).hexdigest()

def safe(root,name):
    p=(root/name).resolve()
    if root not in p.parents:raise RuntimeError('Ruta fuera del directorio autorizado')
    return p

def candidate(old,row):
    if row['new_file']:
        new=base64.b64decode(row['content'])
        if sha(new)!=row['after_sha']:raise RuntimeError('Paquete alterado: '+row['path'])
        if old is not None and old.replace(b'\r\n',b'\n')!=new:raise RuntimeError('Archivo nuevo ya personalizado: '+row['path'])
        return old if old is not None else new
    if old is None:raise RuntimeError('Falta archivo instalado: '+row['path'])
    text=old.replace(b'\r\n',b'\n').decode('utf-8')
    for hunk in row['hunks']:
        before,after=hunk['before'],hunk['after']
        if text.count(after)==1:continue
        matches=[v for v in [before]+hunk.get('alternates',[]) if text.count(v)==1]
        if len(matches)!=1:raise RuntimeError('Bloque diferente: '+row['path']+'. Comparar antes de publicar; sitio intacto.')
        text=text.replace(matches[0],after,1)
    data=text.encode('utf-8')
    return data.replace(b'\n',b'\r\n') if b'\r\n' in old else data

def lint(data,name):
    exe=shutil.which('php' if name.endswith('.php') else 'node')
    if not exe:
        if name.endswith('.php'):raise RuntimeError('PHP CLI es obligatorio')
        return
    with tempfile.TemporaryDirectory(prefix='wa-ventas-lint-') as tmp:
        p=Path(tmp)/Path(name).name;p.write_bytes(data)
        r=subprocess.run([exe,'-l' if name.endswith('.php') else '--check',str(p)],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        if r.returncode:raise RuntimeError('Sintaxis no válida: '+name)

def write(p,data,mode):
    fd,tmp=tempfile.mkstemp(prefix='.wa-ventas-',dir=str(p.parent))
    try:
        with os.fdopen(fd,'wb') as stream:stream.write(data);stream.flush();os.fsync(stream.fileno())
        os.chmod(tmp,mode);os.replace(tmp,str(p))
    finally:
        if os.path.exists(tmp):os.unlink(tmp)

def schema(site,spec,apply):
    php=shutil.which('php')
    if not php:raise RuntimeError('PHP CLI es obligatorio')
    sql=base64.b64decode(spec['schema'])
    if sha(sql)!=spec['schema_sha']:raise RuntimeError('Migración alterada')
    r=subprocess.run([php,'-d','display_errors=0','-d','log_errors=0','-r',SCHEMA_CHECK,str(site),'apply' if apply else 'check',spec['schema']],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    text=r.stdout.decode('utf-8',errors='replace').strip()
    if r.returncode or text not in ['ESQUEMA_LISTO','ESQUEMA_LISTO_CONFIG_PENDIENTE']:raise RuntimeError('Esquema no confirmado. No se muestran datos privados.')
    print(text)

def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--site',required=True);parser.add_argument('--backup-root',required=True)
    mode=parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--check',action='store_true');mode.add_argument('--apply',action='store_true');mode.add_argument('--restore')
    args=parser.parse_args();site=Path(args.site).resolve();backups=Path(args.backup_root).resolve()
    if not (site/'index.php').is_file():raise RuntimeError('No es la raíz del CRM')
    if backups==site or site in backups.parents:raise RuntimeError('El respaldo debe estar fuera del sitio público')
    spec=json.loads(Path(__file__).with_name('whatsapp_ventas_release.json').read_text(encoding='utf-8'))
    if tuple(row['path'] for row in spec['files'])!=FILES:raise RuntimeError('Paquete inválido')
    plan=[]
    if args.restore:
        backup=Path(args.restore).resolve()
        if backups not in backup.parents:raise RuntimeError('Respaldo no autorizado')
        record=json.loads((backup/'manifest.json').read_text(encoding='utf-8'))
        names=[x['path'] for x in record['files']]
        if record['release']!=spec['release'] or len(names)!=len(set(names)) or not set(names).issubset(FILES):raise RuntimeError('Respaldo inválido')
        for row in reversed(record['files']):
            p=safe(site,row['path']);old=p.read_bytes() if p.exists() else None
            new=None if row['old_sha'] is None else safe(backup,row['path']).read_bytes()
            if new is not None and sha(new)!=row['old_sha']:raise RuntimeError('Respaldo alterado')
            if old==new:continue
            if old is None or sha(old)!=row['new_sha']:raise RuntimeError('Cambios posteriores: '+row['path'])
            plan.append((row['path'],p,old,new,row['mode']))
    else:
        for row in spec['files']:
            p=safe(site,row['path']);old=p.read_bytes() if p.exists() else None;new=candidate(old,row);lint(new,row['path'])
            if old!=new:plan.append((row['path'],p,old,new,(p.stat().st_mode&0o777) if p.exists() else 0o644))
        schema(site,spec,False)
        if args.check:print('COMPROBACION_OK: '+str(len(plan))+' archivos; sitio intacto.');return
        if not plan:print('SIN_CAMBIOS: paquete ya aplicado.');return
        backups.mkdir(parents=True,exist_ok=True,mode=0o700)
        backup=Path(tempfile.mkdtemp(prefix='whatsapp-ventas-',dir=str(backups)))
        rows=[]
        for name,p,old,new,mode in plan:
            if old is not None:
                saved=safe(backup,name);saved.parent.mkdir(parents=True,exist_ok=True);saved.write_bytes(old);os.chmod(saved,0o600)
            rows.append(dict(path=name,old_sha=None if old is None else sha(old),new_sha=sha(new),mode=mode))
        (backup/'manifest.json').write_text(json.dumps(dict(release=spec['release'],files=rows)),encoding='utf-8')
        print('RESPALDO: '+str(backup));schema(site,spec,True)
    written=[]
    try:
        for name,p,old,new,mode in plan:
            if (p.read_bytes() if p.exists() else None)!=old:raise RuntimeError('Cambio concurrente: '+name)
            if new is None:p.unlink()
            else:write(p,new,mode)
            written.append((p,old,mode))
        for name,p,old,new,mode in plan:
            if (p.read_bytes() if p.exists() else None)!=new:raise RuntimeError('Verificación posterior falló: '+name)
    except BaseException:
        for p,old,mode in reversed(written):
            if old is None:p.unlink()
            else:write(p,old,mode)
        raise
    print(('RESTAURACION_OK' if args.restore else 'PUBLICACION_OK')+': '+str(len(plan))+' archivos. No se enviaron mensajes. Falta ejecutar el ajuste de permisos indicado en la guía.')

if __name__=='__main__':
    try:main()
    except (OSError,RuntimeError,ValueError,KeyError) as e:raise SystemExit('BLOQUEADO: '+str(e))
