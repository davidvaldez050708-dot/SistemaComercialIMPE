"""CHECK/APPLY/ROLLBACK con las trece bases exactas auditadas y MariaDB sintética."""
from pathlib import Path
import contextlib
import importlib.util
import io
import subprocess
import sys
import tempfile
ROOT=Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'tools'))
import publicar_whatsapp_contextual_compatible_hostinger as p
spec=importlib.util.spec_from_file_location('wa_host_fixture',ROOT/'tests/fixtures/whatsapp_hostinger_20261118.py')
fixture=importlib.util.module_from_spec(spec);spec.loader.exec_module(fixture);bases=fixture.reconstruir()
schema=sys.argv[1]
assert schema.startswith('impe_whatsapp_test_') and schema.replace('_','').isalnum()
def write(path,data):path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(data)
def deny(fn):
    try:fn()
    except p.motor.Bloqueo:return
    raise AssertionError('Se esperaba bloqueo')
with tempfile.TemporaryDirectory(prefix='impe-wa-host-compat-') as temp:
    site=Path(temp)/'public_html';stage=Path(temp)/'stage';backups=Path(temp)/'backups'
    for rel,old,new in p.ARCHIVOS_HOSTINGER:
        (site/rel).parent.mkdir(parents=True,exist_ok=True)
        if old:write(site/rel,bases[rel])
        if rel in p.ADAPTADOS:write(stage/(p.PREFIJO+rel),(ROOT/(p.PREFIJO+rel)).read_bytes())
        else:write(stage/rel,(ROOT/rel).read_bytes())
    for rel in p.DEPENDENCIAS_HOSTINGER:write(site/rel,bases[rel])
    write(stage/p.canonico.SQL_REL,(ROOT/p.canonico.SQL_REL).read_bytes())
    config=b'<?php return []; // fixture privada, no credenciales reales'
    write(site/'config/whatsapp.local.php',config)
    write(site/'config/db_connection.php',("<?php class Database{function connect($x=false){return new mysqli('127.0.0.1',getenv('MYSQL_USER'),getenv('MYSQL_PASSWORD'),'"+schema+"',(int)getenv('MYSQL_PORT'));}}").encode())
    def run(*args):
        prev=sys.argv.copy()
        try:sys.argv=['wa-compatible','--site',str(site),'--stage',str(stage),*args];p.main()
        finally:sys.argv[:]=prev
    apply=lambda:run('--apply','--backup-root',str(backups),'--confirm','PUBLICAR_WHATSAPP_CONTEXTUAL_IMPE')
    preserved={rel:bases[rel] for rel in p.DEPENDENCIAS_HOSTINGER}
    with contextlib.redirect_stdout(io.StringIO()):
        run('--check');assert p.canonico.ARCHIVOS==p.ARCHIVOS_HOSTINGER and not backups.exists()
        for rel in ['app/helpers/PermissionHelper.php','app/helpers/AdminRolePolicy.php','index.php']:
            file=site/rel;file.write_bytes(bases[rel]+b'\n// desconocido')
            deny(apply);file.write_bytes(bases[rel]);assert not backups.exists()
        view=stage/(p.PREFIJO+'app/views/aliados/estado.php');data=view.read_bytes();view.write_bytes(data+b'\n<!-- alterado -->')
        deny(apply);view.write_bytes(data);assert not backups.exists()
        apply();run('--check');apply();assert len(list(backups.iterdir()))==1
        backup=next(backups.iterdir())
        for rel,old,new in p.ARCHIVOS_HOSTINGER:
            assert p.motor.blob(str(site/rel))==new
            if old:assert (backup/rel).read_bytes()==bases[rel]
        for rel,data in preserved.items():assert (site/rel).read_bytes()==data
        assert (site/'config/whatsapp.local.php').read_bytes()==config
        revert=lambda:run('--rollback','--backup-dir',str(backup),'--confirm','REVERTIR_WHATSAPP_CONTEXTUAL_IMPE')
        view=site/'app/views/aliados/estado.php';data=view.read_bytes();view.write_bytes(data+b'\n<!-- personalizacion posterior -->')
        deny(revert);view.write_bytes(data);revert();assert p.motor.estado(str(site))=='BASE'
        real=p.motor.atomico;calls=[0]
        def broken(*args):
            calls[0]+=1
            if calls[0]==2:raise OSError('Fallo parcial sintético')
            return real(*args)
        p.motor.atomico=broken
        try:
            try:apply()
            except OSError:pass
            else:raise AssertionError('Se esperaba fallo')
        finally:p.motor.atomico=real
        assert p.motor.estado(str(site))=='BASE'
        for rel,data in preserved.items():assert (site/rel).read_bytes()==data
print('CHECK_OK Hostinger: 13 SHA-256 exactos, metadatos reales, CHECK/APPLY idempotente, respaldo y ROLLBACK de las bases reales, rechazo de cambios desconocidos, fallo parcial y políticas/rutas preservadas.')
