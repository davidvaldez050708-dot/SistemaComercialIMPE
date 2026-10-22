#!/usr/bin/env bash
# SOLO SQL de asociación PBX al expediente Analista: MySQL UNION 1271.
# No cambia Recibidas, Recepcion/Ventas, PBX, webhooks, SQL schema ni audios.
set -euo pipefail
umask 077
python3 - "$@" <<'PY'
import os,sys,json,hashlib,subprocess,tempfile,stat
from pathlib import Path
SPEC=json.loads(r'''{"file":"app/services/TelefoniaGrabacionExpedienteTransferidaService.php","before":"        $fuentes=[];\n        foreach(['telefonia_transferencia_contextos_v2'=>'referencia_llamada','telefonia_transferencia_contextos'=>'pbx_call_id'] as $nombre=>$campo){\n            $existe=$this->db->query(\"SHOW TABLES LIKE '$nombre'\");\n            if($existe&&$existe->num_rows>0)$fuentes[]=\"SELECT $campo AS pbx_call_id,destino_usuario_id,destino_extension,seguimiento_id,telefono10,created_at FROM $nombre\";\n        }\n        if($this->db->query(\"SHOW TABLES LIKE 'telefonia_disponibilidad'\")->num_rows){\n            $fuentes[]=\"SELECT d.referencia AS pbx_call_id,d.destino_usuario AS destino_usuario_id,d.destino_extension,\n                CAST(JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(d.ruta_json),d.ruta_json,'{}'),'$.seguimiento_id')) AS UNSIGNED) AS seguimiento_id,\n                RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(d.numero,'+',''),' ',''),'-',''),'(',''),')',''),10) AS telefono10,d.created_at","after":"        // Producción mezcla collations de las tablas históricas de contextos\n        // y disponibilidad (MySQL 1271 al construir UNION ALL).\n        // Normalizar SOLO las columnas textuales de las proyecciones,\n        // nunca las tablas ni su contenido; mismo tipo/collation en cada rama.\n        $utf8=static fn(string $field):string=>\n            \"CONVERT($field USING utf8mb4) COLLATE utf8mb4_general_ci\";\n        $fuentes=[];\n        foreach(['telefonia_transferencia_contextos_v2'=>'referencia_llamada','telefonia_transferencia_contextos'=>'pbx_call_id'] as $nombre=>$campo){\n            $existe=$this->db->query(\"SHOW TABLES LIKE '$nombre'\");\n            if($existe&&$existe->num_rows>0){\n                $fuentes[]=\"SELECT \".$utf8($campo).\" AS pbx_call_id,\n                    destino_usuario_id,\".$utf8('destino_extension').\" AS destino_extension,\n                    seguimiento_id,\".$utf8('telefono10').\" AS telefono10,created_at FROM $nombre\";\n            }\n        }\n        if($this->db->query(\"SHOW TABLES LIKE 'telefonia_disponibilidad'\")->num_rows){\n            $fuentes[]=\"SELECT \".$utf8('d.referencia').\" AS pbx_call_id,\n                d.destino_usuario AS destino_usuario_id,\n                \".$utf8('d.destino_extension').\" AS destino_extension,\n                CAST(JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(d.ruta_json),d.ruta_json,'{}'),'$.seguimiento_id')) AS UNSIGNED) AS seguimiento_id,\n                \".$utf8(\"RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(d.numero,'+',''),' ',''),'-',''),'(',''),')',''),10)\").\" AS telefono10,d.created_at","smoke":"<?php\ndeclare(strict_types=1);\n/**\n * Prueba MySQL 8 y MariaDB 10.11 de las tres fuentes con collations distintas.\n * Usa exclusivamente una BD desechable si IMPE_TEST_MYSQL_PORT está definido.\n */\n$source=file_get_contents(__DIR__.'/../app/services/TelefoniaGrabacionExpedienteTransferidaService.php');\nfunction must($ok,string $message):void{if(!$ok)throw new RuntimeException($message);}\nmust(strpos($source,\"CONVERT(\\$field USING utf8mb4) COLLATE utf8mb4_general_ci\")!==false,\n    'Todas las proyecciones del expediente deben usar el mismo charset/collation');\nforeach([\"\\$utf8(\\$campo)\",\"\\$utf8('destino_extension')\",\"\\$utf8('telefono10')\",\n         \"\\$utf8('d.referencia')\",\"\\$utf8('d.destino_extension')\",\n         \"\\$utf8(\\\"RIGHT(REPLACE(\"] as $token){\n    must(strpos($source,$token)!==false,'Falta normalización de una fuente: '.$token);\n}\n$port=(int)(getenv('IMPE_TEST_MYSQL_PORT')?:0);\nif($port<=0){echo \"OK: estructura SQL de tres fuentes normalizada; sin instancia de prueba MySQL.\\n\";exit(0);}\nmysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);\n$db=new mysqli('127.0.0.1',getenv('IMPE_TEST_MYSQL_USER')?:'root',\n    getenv('IMPE_TEST_MYSQL_PASSWORD')?:'',null,$port);\n$name='impe_collation_test_'.getmypid();\n$db->query(\"CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci\");\n$db->select_db($name);$db->set_charset('utf8mb4');\ntry{\n    $db->query(\"CREATE TABLE telefonia_transferencia_contextos_v2 (\n      referencia_llamada VARCHAR(100) COLLATE utf8mb4_unicode_ci,\n      destino_usuario_id INT,destino_extension VARCHAR(12) COLLATE utf8mb4_unicode_ci,\n      seguimiento_id INT,telefono10 CHAR(10) COLLATE utf8mb4_unicode_ci,created_at DATETIME)\");\n    $db->query(\"CREATE TABLE telefonia_transferencia_contextos (\n      pbx_call_id VARCHAR(100) COLLATE utf8mb4_general_ci,\n      destino_usuario_id INT,destino_extension VARCHAR(12) COLLATE utf8mb4_general_ci,\n      seguimiento_id INT,telefono10 CHAR(10) COLLATE utf8mb4_general_ci,created_at DATETIME)\");\n    $db->query(\"CREATE TABLE telefonia_disponibilidad (\n      referencia VARCHAR(100) COLLATE utf8mb4_bin,\n      destino_usuario INT,destino_extension VARCHAR(12) COLLATE utf8mb4_bin,\n      numero VARCHAR(40) COLLATE utf8mb4_bin,ruta_json TEXT COLLATE utf8mb4_bin,\n      estado VARCHAR(20),created_at DATETIME)\");\n    $pbx='out_'.str_repeat('a',32);$foreign='out_'.str_repeat('b',32);\n    $db->query(\"INSERT INTO telefonia_transferencia_contextos_v2 VALUES\n      ('$pbx',10,'101',337,'5551112233',NOW())\");\n    $db->query(\"INSERT INTO telefonia_transferencia_contextos VALUES\n      ('$foreign',10,'101',337,'5550001122',NOW())\");\n    $route=json_encode(['tipo'=>'INSTITUCION','seguimiento_id'=>337,\n       'responsable_id'=>10,'extension'=>'101','referencia_llamada'=>$pbx]);\n    $q=$db->prepare(\"INSERT INTO telefonia_disponibilidad\n      VALUES (?,10,'101','+525551112233',?,'TRANSFERIDA',NOW())\");\n    $q->bind_param('ss',$pbx,$route);$q->execute();$q->close();\n    $utf8=static fn(string $field):string=>\n      \"CONVERT($field USING utf8mb4) COLLATE utf8mb4_general_ci\";\n    $sources=[\n      \"SELECT \".$utf8('referencia_llamada').\" AS pbx_call_id,destino_usuario_id,\".\n       $utf8('destino_extension').\" AS destino_extension,seguimiento_id,\".\n       $utf8('telefono10').\" AS telefono10,created_at FROM telefonia_transferencia_contextos_v2\",\n      \"SELECT \".$utf8('pbx_call_id').\" AS pbx_call_id,destino_usuario_id,\".\n       $utf8('destino_extension').\" AS destino_extension,seguimiento_id,\".\n       $utf8('telefono10').\" AS telefono10,created_at FROM telefonia_transferencia_contextos\",\n      \"SELECT \".$utf8('d.referencia').\" AS pbx_call_id,d.destino_usuario AS destino_usuario_id,\".\n       $utf8('d.destino_extension').\" AS destino_extension,\n       CAST(JSON_UNQUOTE(JSON_EXTRACT(IF(JSON_VALID(d.ruta_json),d.ruta_json,'{}'),'$.seguimiento_id')) AS UNSIGNED) AS seguimiento_id,\".\n       $utf8(\"RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(d.numero,'+',''),' ',''),'-',''),'(',''),')',''),10)\").\" AS telefono10,\n       d.created_at FROM telefonia_disponibilidad d WHERE JSON_VALID(d.ruta_json) AND d.estado IN\n       ('ENVIANDO','ENVIADA','TRANSFERIDA','SIN_CONFIRMAR','SIN_RESPUESTA')\"\n    ];\n    $union='('.implode(' UNION ALL ',$sources).') AS contextos_verificados';\n    $sql=\"SELECT pbx_call_id,created_at FROM $union\n      WHERE destino_usuario_id=? AND destino_extension=? AND seguimiento_id=?\n        AND telefono10=? AND created_at>=DATE_SUB(NOW(),INTERVAL 60 DAY)\";\n    $q=$db->prepare($sql);\n    $user=10;$ext='101';$id=337;$phone='5551112233';\n    $q->bind_param('isis',$user,$ext,$id,$phone);$q->execute();\n    $rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();\n    must(count($rows)===2,'Contexto histórico y disponibilidad coinciden en la MISMA llamada');\n    foreach($rows as $row)must($row['pbx_call_id']===$pbx,'No asociar PBX ajeno del mismo destino');\n    $q=$db->prepare(\"SELECT DISTINCT destino_usuario_id,destino_extension,seguimiento_id\n      FROM $union WHERE pbx_call_id=? LIMIT 2\");\n    $q->bind_param('s',$pbx);$q->execute();\n    $owners=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();\n    must(count($owners)===1,'Las fuentes no duplican propietarios de una referencia PBX');\n    echo \"OK: UNION sin MySQL 1271 (tres collations mezcladas), mismo PBX y usuario en MySQL/MariaDB.\\n\";\n}finally{\n    $db->query(\"DROP DATABASE `$name`\");\n    $db->close();\n}\n"}''')
def fail(reason):
    sys.stderr.write('CONFLICTO: '+reason+'\n');sys.exit(2)
if len(sys.argv)<2 or sys.argv[1] not in ('--check','--apply','--restore'):
    sys.stderr.write('Uso: IMPE_CRM_PUBLIC=/ruta/public_html bash archivo --check|--apply|--restore [ruta_respaldo]\n')
    sys.exit(1)
action=sys.argv[1];env_root=os.environ.get('IMPE_CRM_PUBLIC','')
if not env_root:fail('Falta IMPE_CRM_PUBLIC')
root=Path(env_root).resolve()
if not (root/'index.php').is_file():fail('Raiz del CRM no identificada')
folder=Path(os.environ.get('IMPE_CRM_BACKUPS',str(Path.home()/'impe-analista-audios-backups'))).resolve()
if folder==root or folder in root.parents or root in folder.parents:fail('Respaldo dentro de public_html')
name=SPEC['file'];file=root/name
if not file.is_file() or file.is_symlink() or root not in file.resolve().parents:
    fail('El archivo de expediente no existe o es inseguro')
def sha(data):return hashlib.sha256(data).hexdigest()
def atomic(data,mode):
    fd,temp=tempfile.mkstemp(prefix='.analista-collation-',dir=str(file.parent))
    try:
        os.fchmod(fd,mode)
        with os.fdopen(fd,'wb') as stream:
            stream.write(data);stream.flush();os.fsync(stream.fileno())
        os.replace(temp,str(file))
    finally:
        if os.path.exists(temp):os.unlink(temp)
def lint(path):
    p=subprocess.run(['php','-l',str(path)],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    if p.returncode:fail('PHP no pasó sintaxis: '+(p.stdout+p.stderr).decode('utf8','replace')[-550:])
if action=='--restore':
    if len(sys.argv)!=3:fail('Indica ruta exacta del respaldo')
    backup=Path(sys.argv[2]).resolve()
    if folder not in backup.parents or backup.is_symlink():fail('Respaldo no autorizado')
    manifest=backup/'manifest.json'
    if not manifest.is_file():fail('Manifiesto faltante')
    row=json.loads(manifest.read_text(encoding='utf8'))
    if row.get('path')!=name:fail('Respaldo pertenece a otro archivo')
    original=(backup/'original.bak').read_bytes()
    if sha(original)!=row['old_sha']:fail('Respaldo alterado')
    actual=file.read_bytes()
    if sha(actual) not in (row['old_sha'],row['new_sha']):
        fail('Archivo modificado despues de publicar; no sobrescribir')
    if sha(actual)==row['new_sha']:atomic(original,row['mode'])
    print('OK: archivo del expediente restaurado; otros módulos intactos')
    sys.exit(0)
if len(sys.argv)!=2:fail('Argumentos no permitidos')
raw=file.read_bytes()
try:source=raw.decode('utf8')
except UnicodeDecodeError:fail('Archivo no UTF-8')
if b'\r\n' in raw and raw.count(b'\r\n')!=raw.count(b'\n'):
    fail('Saltos de linea mezclados')
win=b'\r\n' in raw
source=source.replace('\r\n','\n')
before=(SPEC['before']+'\n' if SPEC['before'].endswith('\r') else SPEC['before']).replace('\r\n','\n')
after=(SPEC['after']+'\n' if SPEC['after'].endswith('\r') else SPEC['after']).replace('\r\n','\n')
old_count=source.count(before);new_count=source.count(after)
if old_count==1 and new_count==0:
    updated=source.replace(before,after,1)
    state='pendiente'
elif old_count==0 and new_count==1:
    updated=source
    state='actualizado'
else:fail('Variante de expediente no reconocida. No publicar')
target=(updated.replace('\n','\r\n') if win else updated).encode('utf8')
if state=='pendiente':
    # Validar sintaxis y prueba PHP sobre código futuro sin tocar producción.
    with tempfile.TemporaryDirectory(prefix='impe-analista-union-') as temp:
        scratch=Path(temp)
        source_file=scratch/name
        source_file.parent.mkdir(parents=True,exist_ok=True);source_file.write_bytes(target)
        t=scratch/'tests/telefonia_analista_expediente_collations_db.php'
        t.parent.mkdir(parents=True,exist_ok=True);t.write_bytes(SPEC['smoke'].encode('utf8'))
        lint(source_file);lint(t)
        check_env=os.environ.copy();check_env.pop('IMPE_TEST_MYSQL_PORT',None)
        p=subprocess.run(['php',str(t)],stdout=subprocess.PIPE,stderr=subprocess.PIPE,env=check_env)
        if p.returncode:fail('Regresion UNION falló: '+(p.stdout+p.stderr).decode('utf8','replace')[-550:])
        print(p.stdout.decode('utf8','replace').strip())
print(name+': '+state)
print('Archivos a modificar:',1 if state=='pendiente' else 0)
if action=='--check':
    print('OK: verificacion de solo lectura; no se aplicaron cambios')
    sys.exit(0)
if state=='actualizado':
    print('OK: solucion SQL 1271 ya instalada');sys.exit(0)
folder.mkdir(parents=True,exist_ok=True,mode=0o700)
os.chmod(str(folder),0o700)
backup=Path(tempfile.mkdtemp(prefix='analista-union-1271-',dir=str(folder)))
os.chmod(str(backup),0o700)
p=backup/'original.bak';p.write_bytes(raw);os.chmod(str(p),0o600)
mode=stat.S_IMODE(file.stat().st_mode)
m=backup/'manifest.json'
m.write_text(json.dumps({'path':name,'old_sha':sha(raw),'new_sha':sha(target),'mode':mode}),
             encoding='utf8')
os.chmod(str(m),0o600)
if file.read_bytes()!=raw:fail('Edicion concurrente; no aplicar')
atomic(target,mode)
print('OK: correccion UNION MySQL 1271 publicada. Respaldo:',str(backup))
print('No se alteraron datos, grabaciones ni archivos de Recepcion/Ventas o Recibidas.')
PY
