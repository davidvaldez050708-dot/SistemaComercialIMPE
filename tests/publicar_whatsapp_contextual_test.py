"""Publicación en archivos y esquema sintéticos; conserva mensajes y configuraciones."""
from pathlib import Path
import contextlib
import hashlib
import io
import subprocess
import sys
import tempfile
ROOT=Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'tools'))
import publicar_whatsapp_contextual_hostinger as p
schema=sys.argv[1]
assert schema.startswith('impe_whatsapp_test_') and schema.replace('_','').isalnum()
def blob(h):return subprocess.check_output(['git','cat-file','blob',h],cwd=ROOT)
def write(path,data):path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(data)
def deny(fn):
    try:fn()
    except p.motor.Bloqueo:return
    raise AssertionError('Se esperaba bloqueo')
with tempfile.TemporaryDirectory(prefix='impe-wa-deploy-') as temp:
    site=Path(temp)/'public_html';stage=Path(temp)/'stage';backups=Path(temp)/'backups'
    for rel,old,new in p.ARCHIVOS:
        (site/rel).parent.mkdir(parents=True,exist_ok=True)
        if old:write(site/rel,blob(old))
        write(stage/rel,(ROOT/rel).read_bytes())
    for rel,h in p.DEPENDENCIAS.items():write(site/rel,blob(h))
    write(stage/p.SQL_REL,(ROOT/p.SQL_REL).read_bytes())
    secret=site/'config/whatsapp.local.php';write(secret,b'<?php return []; // configuracion sintetica conservada')
    write(site/'config/db_connection.php',("<?php class Database{function connect($x=false){return new mysqli('127.0.0.1',getenv('MYSQL_USER'),getenv('MYSQL_PASSWORD'),'"+schema+"',(int)getenv('MYSQL_PORT'));}}").encode())
    originals={rel:(site/rel).read_bytes() for rel in p.DEPENDENCIAS}
    config=secret.read_bytes()
    def run(*args):
        prev=sys.argv.copy()
        try:sys.argv=['wa-context','--site',str(site),'--stage',str(stage),*args];p.main()
        finally:sys.argv[:]=prev
    apply=lambda:run('--apply','--backup-root',str(backups),'--confirm','PUBLICAR_WHATSAPP_CONTEXTUAL_IMPE')
    with contextlib.redirect_stdout(io.StringIO()):
        run('--check');assert not backups.exists()
        src=stage/p.ARCHIVOS[0][0];data=src.read_bytes();src.write_bytes(data+b'\n// cambiado')
        deny(apply);src.write_bytes(data);assert not backups.exists()
        src=site/next(rel for rel,old,_ in p.ARCHIVOS if old);data=src.read_bytes();src.write_bytes(data+b'\n// personalizacion')
        deny(apply);src.write_bytes(data);assert not backups.exists()
        sql=stage/p.SQL_REL;data=sql.read_bytes();sql.write_bytes(data+b'\n-- alterado')
        deny(apply);sql.write_bytes(data);assert not backups.exists()
        apply();run('--check');apply();assert len(list(backups.iterdir()))==1
        backup=next(backups.iterdir())
        for rel,old,new in p.ARCHIVOS:assert p.motor.blob(str(site/rel))==new
        assert secret.read_bytes()==config
        for rel,data in originals.items():assert (site/rel).read_bytes()==data
        revert=lambda:run('--rollback','--backup-dir',str(backup),'--confirm','REVERTIR_WHATSAPP_CONTEXTUAL_IMPE')
        src=site/next(rel for rel,old,_ in p.ARCHIVOS if old);data=src.read_bytes();src.write_bytes(data+b'\n// personalizado luego')
        deny(revert);src.write_bytes(data);revert();assert p.motor.estado(str(site))=='BASE'
        real=p.motor.atomico;calls=[0]
        def broken(*args):
            calls[0]+=1
            if calls[0]==2:raise OSError('Fallo parcial de fixture')
            return real(*args)
        p.motor.atomico=broken
        try:
            try:apply()
            except OSError:pass
            else:raise AssertionError('Se esperaba fallo')
        finally:p.motor.atomico=real
        assert p.motor.estado(str(site))=='BASE' and secret.read_bytes()==config
        for rel,data in originals.items():assert (site/rel).read_bytes()==data
print('CHECK_OK entrega WhatsApp: metadatos reales, bases exactas, bloqueo de personalizaciones y SQL alterado, respaldo, APPLY idempotente, ROLLBACK y recuperación de fallo parcial; configuración Meta intacta.')
