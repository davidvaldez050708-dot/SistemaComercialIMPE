"""Compare real availability POSTs in the isolated MariaDB laboratory."""
import os
import uuid
import json
import pathlib
import re
import shutil
import subprocess
import tempfile
import time
from concurrent.futures import ThreadPoolExecutor
from mysql_fastcgi import WorkerFastCGI

import mysql_capacidad_http as lab

ROOT = pathlib.Path(__file__).resolve().parents[1]
PHP = 'C:/xampp/php/php.exe'
MYSQL = 'C:/xampp/mysql/bin/mysql.exe'
PORT = int(os.environ['IMPE_TEST_MYSQL_PORT'])
if os.environ.get('IMPE_LAB_CONFIRM') != 'SYNTHETIC_LOOPBACK':
    raise RuntimeError('Este ensayo requiere MariaDB local con datos sintéticos.')


def sql(query):
    return subprocess.check_output(
        [MYSQL, '--no-defaults', '--raw', '--host=127.0.0.1', '--port='+str(PORT),
         '-uroot', '-N', '-e', query], text=True, timeout=15)


route = '/index.php?controller=telefonia&action=disponibilidad'
controller = 'app/controllers/TelefoniaController.php'
metrics = ('Com_select','Com_insert','Com_update','Com_delete','Connections')
counter_sql = 'SHOW GLOBAL STATUS WHERE Variable_name IN ('+','.join(map(repr,metrics))+')'


def counters():
    return {k:int(v) for k,v in (row.split() for row in sql(counter_sql).splitlines())}


schema='impe_poll_test_'+uuid.uuid4().hex[:10]
account='root'
password=''
sql('CREATE DATABASE '+schema)
def setup(q): return sql('USE '+schema+'; '+q)
try:
    setup("""CREATE TABLE roles (id INT PRIMARY KEY, nombre VARCHAR(80), estado INT);
    CREATE TABLE usuarios (id INT PRIMARY KEY, rol_id INT, usuario VARCHAR(80), nombre VARCHAR(80), apellidos VARCHAR(80), estado INT, telefono VARCHAR(30), foto_perfil VARCHAR(100), correo VARCHAR(100), password VARCHAR(100), ultimo_acceso DATETIME, created_at DATETIME, updated_at DATETIME, requiere_cambio_password INT DEFAULT 0, password_temporal_expira DATETIME);
    CREATE TABLE permisos (id INT AUTO_INCREMENT PRIMARY KEY, modulo VARCHAR(100), codigo VARCHAR(120) UNIQUE, nombre VARCHAR(180), descripcion TEXT, estado INT);
    CREATE TABLE rol_permisos (rol_id INT, permiso_id INT, PRIMARY KEY(rol_id,permiso_id));
    CREATE TABLE telefonia_extensiones (usuario_id INT PRIMARY KEY, extension VARCHAR(12), activo INT, proveedor VARCHAR(12), permite_entrantes INT, permite_salientes INT, created_at DATETIME, updated_at DATETIME);
    CREATE TABLE telefonia_presencia (usuario_id INT PRIMARY KEY, extension VARCHAR(12), ocupado INT, updated_at DATETIME);
    CREATE TABLE telefonia_disponibilidad (id INT PRIMARY KEY, destino_usuario INT, origen_usuario INT, destino_extension VARCHAR(12), origen_extension VARCHAR(12), estado VARCHAR(24), expires_at DATETIME, updated_at DATETIME, ruta_json TEXT);
    INSERT INTO roles VALUES(4,'Analista de Datos',1);
    INSERT INTO usuarios(id,rol_id,usuario,nombre,apellidos,estado) VALUES(1,4,'sintetico','Prueba','Analista',1);
    INSERT INTO permisos(modulo,codigo,nombre,descripcion,estado) VALUES
    ('Telefonia','telefonia.usar','Uso','',1),('Telefonia','telefonia.recibir','Recepcion','',1),
    ('Telefonia','telefonia.salientes','Salientes','',1),('Telefonia','telefonia.transferir','Transferencia','',1);
    INSERT INTO rol_permisos SELECT 4,id FROM permisos;
    INSERT INTO telefonia_extensiones VALUES(1,'102',1,'ZADARMA',1,1,UTC_TIMESTAMP(),UTC_TIMESTAMP());""")
    with tempfile.TemporaryDirectory(prefix='impe-poll-http-') as temp:
        root=pathlib.Path(temp)
        base=root/'base';base.mkdir();(base/'public').mkdir()
        for folder in ('app','config','prueba_telefonia'):
            shutil.copytree(ROOT/folder,base/folder)
        for file in ('index.php','logout.php'):
            shutil.copyfile(ROOT/file,base/file)
        sitio=root/'sitio'
        lab.preparar_sitio(sitio,base,None)
        original=subprocess.check_output(['git','show','aea878f:'+controller],cwd=ROOT)
        candidate=(ROOT/controller).read_bytes()
        cookie='PHPSESSID=synthetic-poll'
        csrf='a'*64
        session=sitio/'sesiones-prueba'/'sess_synthetic-poll'
        def seed_session():
            session.write_text(f'usuario_id|i:1;rol_id|i:4;ultima_actividad|i:{int(time.time())};csrf_token|s:64:"{csrf}";')
        with (root/'web.log').open('wb') as log:
            for etiqueta,archivo in [('antes',original),('despues',candidate)]:
                seed_session()
                (sitio/controller).write_bytes(archivo)
                port=lab.libre()
                env={k:v for k,v in os.environ.items() if not k.upper().startswith('IMPE_')}
                env.update(IMPE_PRIVATE_CONFIG_DIR=str(sitio/'config'),IMPE_DB_HOST='127.0.0.1:'+str(PORT),
                    IMPE_DB_NAME=schema,IMPE_DB_USER=account,IMPE_DB_PASSWORD=password,
                    IMPE_BASE_URL='http://127.0.0.1:'+str(port)+'/',IMPE_DB_TRAZA='1',APP_ENV='development')
                server=subprocess.Popen([PHP,'-d','display_errors=0','-d','session.save_path='+str(session.parent),
                    '-S','127.0.0.1:'+str(port),'-t',str(sitio)],env=env,stdout=log,stderr=log)
                try:
                    time.sleep(.5)
                    antes=counters()
                    e,_,body,_=lab.peticion(port,route,cookie,{'accion':'POLL','disponible':'1','id':'0','consultar_devoluciones':'0','csrf_token':csrf})
                    despues=counters()
                    assert e==200 and json.loads(body)['ok'],(e,body,(root/'web.log').read_text(errors='replace')[-4000:])
                    print(etiqueta,{k:despues[k]-antes[k]-(k=='Connections') for k in metrics},flush=True)
                    if etiqueta=='despues':
                        setup('RENAME TABLE telefonia_disponibilidad TO disponibilidad_temporal')
                        payload={'accion':'POLL','disponible':'1','csrf_token':csrf,'consultar_devoluciones':'0'}
                        try:
                            e,_,body,_=lab.peticion(port,route,cookie,payload)
                            assert e==500 and json.loads(body)['ok'] is False,(e,body)
                        finally:
                            setup('RENAME TABLE disponibilidad_temporal TO telefonia_disponibilidad')
                        e,_,body,_=lab.peticion(port,route,cookie,payload)
                        assert e==200 and json.loads(body)['ok'],(e,body)
                        print('Fallo SQL: HTTP 500; recuperación: HTTP 200',flush=True)
                        setup("DELETE FROM rol_permisos WHERE permiso_id=(SELECT id FROM permisos WHERE codigo='telefonia.recibir')")
                        e,_,_,_=lab.peticion(port,route,cookie,{'accion':'POLL','csrf_token':csrf})
                        assert e==403,e
                        setup("INSERT INTO rol_permisos SELECT 4,id FROM permisos WHERE codigo='telefonia.recibir'; UPDATE usuarios SET estado=0 WHERE id=1")
                        e,_,_,_=lab.peticion(port,route,cookie,{'accion':'POLL','csrf_token':csrf})
                        assert e==403,e
                        print('Revocaciones de permiso y usuario: HTTP 403',flush=True)
                finally:
                    server.terminate();server.wait(timeout=10)
        setup('UPDATE usuarios SET estado=1 WHERE id=1')
        if os.environ.get('IMPE_LAB_ROTACIONES') == '1':
            for rol,nombre in [(3,'Asesor de Ventas'),(7,'Marketing')]:
                setup(f"INSERT INTO roles VALUES({rol},'{nombre}',1); INSERT INTO rol_permisos SELECT {rol},id FROM permisos;")
            for uid in range(2,31):
                rol=[3,4,7][uid%3]
                setup(f"INSERT INTO usuarios(id,rol_id,usuario,nombre,apellidos,estado) VALUES({uid},{rol},'prueba{uid}','Prueba','Sintetica',1);"
                      f"INSERT INTO telefonia_extensiones VALUES({uid},'{uid+200}',1,'ZADARMA',1,1,UTC_TIMESTAMP(),UTC_TIMESTAMP());")
            for uid in range(1,31):
                (session.parent/f'sess_rotation-{uid}').write_text(
                    f'usuario_id|i:{uid};ultima_actividad|i:{int(time.time())};csrf_token|s:64:"{csrf}";')
            fcgi=str(pathlib.Path(PHP).with_name('php-cgi.exe'))
            payload={'accion':'POLL','disponible':'1','id':'0','consultar_devoluciones':'0','csrf_token':csrf}
            for etiqueta,archivo in [('antes',original),('despues',candidate)]:
                (sitio/controller).write_bytes(archivo)
                workers=[]
                with (root/f'fcgi-{etiqueta}.log').open('wb') as log:
                    try:
                        for _ in range(30):
                            web,backend=lab.libre(),lab.libre()
                            while backend==web:backend=lab.libre()
                            env={k:v for k,v in os.environ.items() if not k.upper().startswith('IMPE_')}
                            env.update(IMPE_PRIVATE_CONFIG_DIR=str(sitio/'config'),IMPE_DB_HOST='127.0.0.1:'+str(PORT),
                                IMPE_DB_NAME=schema,IMPE_DB_USER=account,IMPE_DB_PASSWORD=password,
                                IMPE_BASE_URL='http://127.0.0.1:'+str(web)+'/',APP_ENV='development',
                                PHP_FCGI_CHILDREN='0',PHP_FCGI_MAX_REQUESTS='10000')
                            worker=WorkerFastCGI([fcgi,'-d','display_errors=0','-d','session.save_path='+str(session.parent)],
                                env,log,sitio,web,backend)
                            workers.append((web,worker))
                        samples=[]
                        with ThreadPoolExecutor(max_workers=30) as pool:
                            for ronda in range(10):
                                def poll(index):
                                    uid=(index+ronda)%30+1
                                    e,_,body,_=lab.peticion(workers[index][0],route,f'PHPSESSID=rotation-{uid}',payload)
                                    return e, bool(e==200 and json.loads(body).get('ok'))
                                status=list(pool.map(poll,range(30)))
                                sessions=int(sql(f"SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE DB='{schema}'").strip())
                                samples.append(sessions)
                                if not all(ok for _,ok in status):
                                    print(etiqueta,'rotacion',ronda+1,'HTTP',status,flush=True)
                                    print((root/f'fcgi-{etiqueta}.log').read_text(errors='replace')[-1800:],flush=True)
                                    break
                        print(etiqueta,'30 workers FastCGI, conexiones por ronda:',samples,flush=True)
                    finally:
                        for _,worker in workers:worker.terminate()
                        for _,worker in workers:worker.wait(timeout=10)
finally:
    sql('DROP DATABASE '+schema)
