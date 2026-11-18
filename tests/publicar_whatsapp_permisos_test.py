"""Publicación y reversión con archivos auditados y MariaDB sintética, sin Meta."""
from pathlib import Path
import contextlib
import importlib.util
import io
import json
import os
import subprocess
import sys
import tempfile
ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools'))
import publicar_whatsapp_permisos_bandeja_hostinger as p
schema=sys.argv[1]
assert schema.startswith('impe_whatsapp_test_') and schema.replace('_','').isalnum()
def fixture(name):
    spec=importlib.util.spec_from_file_location(name,ROOT/'tests/fixtures'/f'{name}.py')
    mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod)
    return mod.reconstruir()
bases={**fixture('whatsapp_hostinger_20261118'),**fixture('whatsapp_roles_hostinger_20261118')}
references=json.loads((ROOT/'tests/fixtures/whatsapp_roles_hostinger_20261118.patch.json').read_text(encoding='utf8'))['extra_files_reference']
def write(path,data):
    path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(data)
def blob(oid):return subprocess.check_output(['git','cat-file','blob',oid],cwd=ROOT)
def deny(fn):
    try:fn()
    except p.motor.Bloqueo:return
    raise AssertionError('Se esperaba bloqueo')
with tempfile.TemporaryDirectory(prefix='impe-wa-permisos-test-') as temp:
    for host in [False,True]:
        site=Path(temp)/('host' if host else 'canonical')/'public_html'
        stage=Path(temp)/('host' if host else 'canonical')/'stage'
        backups=site.parent/'backups'
        files=p.ARCHIVOS_HOSTINGER if host else p.ARCHIVOS_CANONICOS
        for rel,old,new in files:
            write(site/rel,bases[rel] if host and rel in p.ADAPTADOS else blob(old))
            source=(p.PREFIJO if host and rel in p.ADAPTADOS else '')+rel
            write(stage/source,(ROOT/source).read_bytes())
        deps=p.DEPENDENCIAS_HOSTINGER if host else p.DEPENDENCIAS_CANONICAS
        for rel,oid in deps.items():write(site/rel,bases[rel] if host and rel.startswith('app/helpers/') else blob(oid))
        for rel,data in references.items():write(site/rel,data.encode())
        write(site/'app/views/roles/panel_permisos.php',(ROOT/'app/views/roles/panel_permisos.php').read_bytes())
        write(site/'config/db_connection.php',("<?php class Database{function connect($x=false){$db=new mysqli('127.0.0.1',getenv('MYSQL_USER'),getenv('MYSQL_PASSWORD'),'"+schema+"',(int)getenv('MYSQL_PORT'));$db->set_charset('utf8mb4');return $db;}} if(!defined('BASE_URL'))define('BASE_URL','https://wa.test/');").encode())
        def sql(statement):
            code="require $argv[1].'/config/db_connection.php';$db=(new Database())->connect();$result=$db->query($argv[2]);echo json_encode($result instanceof mysqli_result?$result->fetch_all(MYSQLI_ASSOC):true);"
            return json.loads(subprocess.check_output(['php','-r',code,str(site),statement]))
        def scopes():
            return [sql("SELECT * FROM permisos WHERE codigo IN ('whatsapp.gestionar_conversaciones','whatsapp.ver_todas','whatsapp.responder_todas') ORDER BY id"),sql("SELECT rp.* FROM rol_permisos rp JOIN permisos p ON p.id=rp.permiso_id WHERE p.codigo IN ('whatsapp.gestionar_conversaciones','whatsapp.ver_todas','whatsapp.responder_todas') ORDER BY rol_id,permiso_id")]
        def data():return [sql('SELECT * FROM whatsapp_mensajes ORDER BY id'),sql('SELECT * FROM prospecto_asignaciones ORDER BY id')]
        sql("INSERT IGNORE INTO roles VALUES(2,'Supervisor sintético',1)")
        sql("UPDATE permisos SET estado=1 WHERE codigo='whatsapp.ver_todas'")
        sql("DELETE rp FROM rol_permisos rp JOIN permisos p ON p.id=rp.permiso_id WHERE p.codigo IN ('whatsapp.gestionar_conversaciones','whatsapp.ver_todas','whatsapp.responder_todas')")
        sql("INSERT IGNORE INTO rol_permisos SELECT 3,id FROM permisos WHERE codigo IN ('whatsapp.ver','whatsapp.enviar','whatsapp.gestionar_conversaciones','whatsapp.responder_todas')")
        sql("INSERT IGNORE INTO rol_permisos SELECT 1,id FROM permisos WHERE codigo IN ('whatsapp.gestionar_conversaciones','whatsapp.responder_todas')")
        sql("INSERT IGNORE INTO rol_permisos SELECT 2,id FROM permisos WHERE codigo IN ('whatsapp.ver','whatsapp.ver_todas')")
        before=scopes();untouched=data()
        def run(*args):
            prev=sys.argv.copy()
            try:sys.argv=['wa-permissions','--site',str(site),'--stage',str(stage),*args];p.main()
            finally:sys.argv[:]=prev
        apply=lambda:run('--apply','--backup-root',str(backups),'--confirm','PUBLICAR_WHATSAPP_PERMISOS_IMPE')
        with contextlib.redirect_stdout(io.StringIO()):
            run('--check');assert not backups.exists() and scopes()==before
            src=stage/((p.PREFIJO if host else '')+'app/models/RolModel.php');original=src.read_bytes()
            src.write_bytes(original+b'\n// altered');deny(apply);src.write_bytes(original)
            dep=site/'app/helpers/PermissionHelper.php';original=dep.read_bytes()
            dep.write_bytes(original+b'\n// customization');deny(apply);dep.write_bytes(original)
            assert not backups.exists() and scopes()==before
            apply();run('--check');after=scopes()
            assert after!=before and data()==untouched
            assert not sql("SELECT rp.* FROM rol_permisos rp JOIN permisos p ON p.id=rp.permiso_id WHERE rp.rol_id=3 AND p.codigo IN ('whatsapp.gestionar_conversaciones','whatsapp.responder_todas')")
            assert sql("SELECT rp.* FROM rol_permisos rp JOIN permisos p ON p.id=rp.permiso_id WHERE rp.rol_id=2 AND p.codigo='whatsapp.gestionar_conversaciones'")
            assert not sql("SELECT rp.* FROM rol_permisos rp JOIN permisos p ON p.id=rp.permiso_id WHERE rp.rol_id=2 AND p.codigo IN ('whatsapp.enviar','whatsapp.responder_todas')")
            apply();assert len(list(backups.iterdir()))==1
            backup=next(backups.iterdir());snapshot=backup/p.SNAPSHOT
            assert snapshot.exists() and Path(str(snapshot)+'.sha256').exists()
            for rel,old,new in files:assert p.motor.blob(str(site/rel))==new
            revert=lambda:run('--rollback','--backup-dir',str(backup),'--confirm','REVERTIR_WHATSAPP_PERMISOS_IMPE')
            # Una concesión posterior no es retirada al repetir APPLY y bloquea ROLLBACK.
            sql("INSERT IGNORE INTO rol_permisos SELECT 3,id FROM permisos WHERE codigo='whatsapp.gestionar_conversaciones'")
            changed=scopes();apply();assert scopes()==changed;deny(revert)
            for rel,old,new in files:assert p.motor.blob(str(site/rel))==new
            sql("DELETE rp FROM rol_permisos rp JOIN permisos p ON p.id=rp.permiso_id WHERE rp.rol_id=3 AND p.codigo='whatsapp.gestionar_conversaciones'")
            assert scopes()==after
            # Verificación real del modelo y dependencias sobre la variante instalada.
            render=Path(os.environ.get('IMPE_WA_RENDER_DIR',temp))/('roles-host.html' if host else 'roles-canonical.html')
            render.parent.mkdir(parents=True,exist_ok=True)
            catalog=subprocess.run(['php',str(ROOT/'tests/whatsapp_permisos_catalogo.php'),str(site),schema,str(render)],capture_output=True,text=True)
            assert catalog.returncode==0,catalog.stdout+catalog.stderr
            # El inicializador puede agregar permisos ajenos; nunca deben revertirse.
            sql("INSERT IGNORE INTO rol_permisos SELECT 2,id FROM permisos WHERE codigo='roles.ver'")
            assert scopes()==after,(scopes(),after)
            raw=snapshot.read_bytes();snapshot.write_bytes(raw+b' ');deny(revert);snapshot.write_bytes(raw)
            revert();assert p.motor.estado(str(site))=='BASE' and scopes()==before and data()==untouched
            assert sql("SELECT rp.* FROM rol_permisos rp JOIN permisos p ON p.id=rp.permiso_id WHERE rp.rol_id=2 AND p.codigo='roles.ver'")
            real=p.motor.atomico;calls=[0]
            def broken(*args):
                calls[0]+=1
                if calls[0]==2:raise OSError('Fallo parcial sintético')
                return real(*args)
            p.motor.atomico=broken
            try:
                try:apply()
                except OSError:pass
                else:raise AssertionError('Se esperaba fallo parcial')
            finally:p.motor.atomico=real
            assert p.motor.estado(str(site))=='BASE' and scopes()==before and data()==untouched
        print('CHECK_OK permisos '+('Hostinger auditado' if host else 'canónico')+': CHECK sin escrituras, bases protegidas, respaldo, APPLY único, permisos explícitos posteriores, catálogo real, ROLLBACK sin pisar cambios y recuperación parcial; datos intactos.')
