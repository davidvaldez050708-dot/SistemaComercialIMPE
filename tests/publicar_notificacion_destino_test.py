"""Entrega desde las bases exactas ya instaladas; nunca utiliza producción."""
from pathlib import Path
import contextlib
import io
import subprocess
import sys
import tempfile

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools'))
import publicar_ubicar_notificaciones_v3_hostinger as p
compat=p.anterior
schema=sys.argv[1] if len(sys.argv)>1 else None
if schema:assert schema.startswith('impe_supervision_test_') and schema.replace('_','').isalnum()
def blob(h):return subprocess.check_output(['git','cat-file','blob',h],cwd=ROOT)
def write(path,data):path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(data)
def negar(fn):
    try:fn()
    except p.publicador.Bloqueo:return
    raise AssertionError('Debió bloquearse')

with tempfile.TemporaryDirectory(prefix='impe-destino-test-') as tmp:
    site=Path(tmp)/'public_html';stage=Path(tmp)/'stage';backups=Path(tmp)/'backups'
    for rel,h in compat.DEPENDENCIAS_CANONICAS.items():write(site/rel,blob(h))
    for rel,_,h in compat.ARCHIVOS_HOSTINGER:write(site/rel,blob(h))
    for rel in ['app/helpers/ReminderHelper.php','app/services/AgendaReunionService.php','app/views/layout/topbar.php','app/views/layout/dashboard_footer.php']:
        write(site/rel,blob(compat.BASES_HOSTINGER[rel]))
    for rel,old,new in p.ARCHIVOS:
        if old:write(site/rel,blob(old))
        write(stage/rel,(ROOT/rel).read_bytes())
    for rel in ['app/services/AgendaComentarioService.php','app/helpers/SeguimientoTerritorialScope.php']:
        write(site/rel,b'<?php')
    if schema:write(site/'config/db_connection.php',("<?php class Database {function connect($x=false){return new mysqli('127.0.0.1',getenv('MYSQL_USER'),getenv('MYSQL_PASSWORD'),'"+schema+"',(int)getenv('MYSQL_PORT'));}}").encode())
    conserved={rel:(site/rel).read_bytes() for rel in [*compat.BASES_HOSTINGER]}
    def run(*args):
        previous=sys.argv.copy()
        try:
            sys.argv=['destino','--site',str(site),'--stage',str(stage),*args];p.main()
        finally:sys.argv[:]=previous
    apply=lambda:run('--apply','--backup-root',str(backups),'--confirm','PUBLICAR_UBICAR_NOTIFICACIONES_V3_IMPE')
    with contextlib.redirect_stdout(io.StringIO()):
        if not schema:
            negar(lambda:run('--check'))
            p.comprobar_esquema=lambda _:None
        run('--check')
        src=stage/p.ARCHIVOS[0][0];data=src.read_bytes();src.write_bytes(data+b'\n// cambiado')
        negar(apply);src.write_bytes(data);assert not backups.exists()
        dst=site/p.ARCHIVOS[0][0];data=dst.read_bytes();dst.write_bytes(data+b'\n// personalizado')
        negar(apply);dst.write_bytes(data);assert not backups.exists()
        apply();run('--check');apply();assert len(list(backups.iterdir()))==1
        backup=next(backups.iterdir())
        for rel,old,new in p.ARCHIVOS:
            assert p.publicador.blob(str(site/rel))==new
            if old:assert p.publicador.blob(str(backup/rel))==old
        for rel,data in conserved.items():assert (site/rel).read_bytes()==data
        args=['--rollback','--backup-dir',str(backup),'--confirm','REVERTIR_UBICAR_NOTIFICACIONES_V3_IMPE']
        src=site/p.ARCHIVOS[0][0];data=src.read_bytes();src.write_bytes(data+b'\n// personalizacion nueva')
        negar(lambda:run(*args));src.write_bytes(data);run(*args)
        assert p.publicador.estado(str(site))=='BASE'
        atomic=p.publicador.atomico;calls=[0]
        def fail(*args):
            calls[0]+=1
            if calls[0]==2:raise OSError('Fallo parcial sintético')
            return atomic(*args)
        p.publicador.atomico=fail
        try:
            try:apply()
            except OSError:pass
            else:raise AssertionError('Debió fallar')
        finally:p.publicador.atomico=atomic
        assert p.publicador.estado(str(site))=='BASE'
        for rel,data in conserved.items():assert (site/rel).read_bytes()==data
print('CHECK_OK entrega: bases exactas, paquete íntegro, personalizaciones protegidas, APPLY idempotente, respaldo, ROLLBACK y reversión automática ante fallo parcial; Agenda intacta.')
