#!/usr/bin/env python3
"""Laboratorio nuevo y privado; sólo loopback:3308. Nunca lee config de producción."""
import argparse
import json
import os
from pathlib import Path
import secrets
import shutil
import socket
import subprocess
import tempfile
import time

SOURCE=Path(__file__).resolve().parents[3]


def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('--mysql-bin',default='C:/xampp/mysql/bin' if os.name=='nt' else '')
    p.add_argument('--php',default=shutil.which('php') or 'C:/xampp/php/php.exe')
    p.add_argument('--root',help='Directorio NUEVO; por defecto un temporal privado')
    args=p.parse_args()
    probe=socket.socket();probe.settimeout(.5)
    try:
        if probe.connect_ex(('127.0.0.1',3308))==0:raise RuntimeError('3308 ocupado; no se toca otro servidor.')
    finally:probe.close()
    root=Path(args.root).resolve() if args.root else Path(tempfile.mkdtemp(prefix='impe-phone-lab-'))
    if args.root:root.mkdir(mode=0o700,parents=False,exist_ok=False)
    root.chmod(0o700);data=root/'mysql';site=root/'site';site.mkdir()
    mysqlbin=Path(args.mysql_bin) if args.mysql_bin else Path(shutil.which('mariadbd') or shutil.which('mysqld')).parent
    server=mysqlbin/('mysqld.exe' if os.name=='nt' else 'mariadbd')
    installer=mysqlbin/('mysql_install_db.exe' if os.name=='nt' else 'mariadb-install-db')
    rootpass=secrets.token_hex(24);apppass=secrets.token_hex(24)
    command=[str(installer),'--datadir='+str(data)]
    if os.name=='nt':command+=['--password='+rootpass]
    else:command+=['--auth-root-authentication-method=normal','--skip-test-db']
    init=subprocess.run(command,capture_output=True)
    if init.returncode:raise RuntimeError('No se pudo inicializar MariaDB; revisar instalación local. No se imprimen argumentos privados.')
    log=open(root/'mysql-stdout.log','ab')
    opts=[str(server),'--no-defaults','--datadir='+str(data),'--basedir='+str(mysqlbin.parent),
          '--port=3308','--bind-address=127.0.0.1','--skip-name-resolve','--skip-log-bin',
          '--max-connections=30','--innodb-buffer-pool-size=64M','--log-error='+str(root/'mysql-error.log')]
    proc=subprocess.Popen(opts,stdout=log,stderr=log,creationflags=subprocess.CREATE_NO_WINDOW if os.name=='nt' else 0)
    credentials=dict(host='127.0.0.1',port=3308,database='impe_telefonia_lab',root_user='root',root_password=rootpass if os.name=='nt' else '',user='impe_phone_app',password=apppass)
    (root/'credentials.json').write_text(json.dumps(credentials),encoding='utf-8');(root/'credentials.json').chmod(0o600)
    for _ in range(100):
        probe=socket.socket();probe.settimeout(.2)
        ready=probe.connect_ex(('127.0.0.1',3308))==0;probe.close()
        if ready:break
        if proc.poll() is not None:raise RuntimeError('MariaDB de laboratorio terminó; revisar log privado.')
        time.sleep(.1)
    else:raise RuntimeError('El servidor de laboratorio no inició dentro del plazo.')
    tracked=subprocess.check_output(['git','ls-files','-z'],cwd=SOURCE).decode().split('\0')
    for name in tracked:
        if not name or not (name.startswith(('app/','public/','prueba_telefonia/','vendor/')) or name=='index.php'):continue
        if '/config/' in '/'+name or name.endswith(('zadarma_config.php','db_connection.php')):continue
        source=SOURCE/name
        if source.is_file():
            dest=site/name;dest.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(source,dest)
    # Incluir los cambios candidatos aún no confirmados de las mismas carpetas.
    for folder in ['app','public','prueba_telefonia']:
        for source in (SOURCE/folder).rglob('*'):
            if not source.is_file() or source.suffix not in ('.php','.js','.css') or source.is_symlink():continue
            name=source.relative_to(SOURCE)
            if 'config' in name.parts or source.name in ('zadarma_config.php','db_connection.php'):continue
            dest=site/name;dest.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(source,dest)
    # Instalar exactamente las dependencias bloqueadas, sin scripts ni plugins.
    # El vendor parcial versionado no basta para cargar los controladores reales.
    for name in ['composer.json','composer.lock']:shutil.copyfile(SOURCE/name,site/name)
    composer=shutil.which('composer')
    phar=Path(composer).with_name('composer.phar') if composer else None
    if phar and phar.is_file():composer_command=[args.php,str(phar)]
    elif composer:composer_command=[composer]
    else:raise RuntimeError('Composer es un requisito del laboratorio; no se omiten dependencias.')
    deps=subprocess.run(composer_command+['install','--no-dev','--no-interaction','--prefer-dist','--no-scripts','--no-plugins','--working-dir='+str(site)],capture_output=True,text=True)
    if deps.returncode:raise RuntimeError('No se pudo instalar composer.lock en el laboratorio: '+deps.stderr)
    config=site/'config';config.mkdir()
    encoded=json.dumps(str(root/'credentials.json').replace('\\','/'))
    (config/'db_connection.php').write_text("""<?php
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
if(!defined('ROOT_PATH'))define('ROOT_PATH',dirname(__DIR__));
if(!defined('BASE_URL'))define('BASE_URL','http://127.0.0.1:33180/');
class Database {
 private static ?mysqli $db=null;
 public function connect($persistent=false):mysqli{return self::compartida();}
 public static function compartida():mysqli{
  if(self::$db)return self::$db;
  $c=json_decode(file_get_contents(CREDENTIAL_PATH),true,32,JSON_THROW_ON_ERROR);
  if($c['host']!=='127.0.0.1'||$c['port']!==3308||$c['database']!=='impe_telefonia_lab')throw new RuntimeException('Destino de laboratorio inválido');
  self::$db=new mysqli($c['host'],$c['user'],$c['password'],$c['database'],$c['port']);self::$db->set_charset('utf8mb4');return self::$db;
 }
 public static function responderFalloTemporal($e,$json=true):bool{return false;}
}
""".replace('CREDENTIAL_PATH',encoded),encoding='utf-8')
    (config/'db_connection.php').chmod(0o600)
    (config/'zadarma_config.php').write_text("<?php throw new RuntimeException('El laboratorio no permite credenciales o llamadas de proveedor reales.');\n",encoding='utf-8')
    (config/'private_config.php').write_text("<?php\nfunction impeLeerConfigPrivada($name):array {throw new RuntimeException('External integrations are disabled in the synthetic laboratory.');}\nfunction impeRutaConfigPrivada($name):?string {return null;}\n",encoding='utf-8')
    (config/'api_keys.php').write_text("<?php // Synthetic lab: no external API keys.\n",encoding='utf-8')
    env=os.environ.copy();env['IMPE_PHONE_LAB_ROOT']=str(root)
    setup=subprocess.run([args.php,str(Path(__file__).with_name('bootstrap.php'))],env=env,cwd=SOURCE,capture_output=True,text=True)
    if setup.returncode:raise RuntimeError('Falló el fixture SQL: '+setup.stdout+setup.stderr)
    for fixture in ['prepare_sales.php','prepare_attention.php','prepare_audio.php']:
        provision=subprocess.run([args.php,str(Path(__file__).resolve().parents[1]/'integration'/fixture)],env=env,cwd=SOURCE,capture_output=True,text=True)
        if provision.returncode:raise RuntimeError('Falló el esquema sintético '+fixture+': '+provision.stdout+provision.stderr)
    (root/'lab.json').write_text(json.dumps(dict(version=1,synthetic=True,pid=proc.pid,root=str(root),source=str(SOURCE),php=args.php,port=3308,prepared_utc=time.time()),indent=2),encoding='utf-8')
    print(setup.stdout.strip());print('IMPE_PHONE_LAB_ROOT='+str(root));print('SERVIDOR_LOCAL_PID='+str(proc.pid))


if __name__=='__main__':main()
