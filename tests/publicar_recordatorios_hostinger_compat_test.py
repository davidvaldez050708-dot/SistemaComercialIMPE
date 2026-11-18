"""Publicación/reversión desde los hashes exactos auditados, sin conectar a producción."""
from pathlib import Path
import contextlib
import importlib.util
import io
import os
import subprocess
import sys
import tempfile

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools'))
import publicar_recordatorios_comerciales_v3_compatible_hostinger as p
spec=importlib.util.spec_from_file_location('fixture',ROOT/'tests/fixtures/recordatorios_hostinger_20261118.py')
fixture=importlib.util.module_from_spec(spec);spec.loader.exec_module(fixture)
bases=fixture.reconstruir()
schema=sys.argv[1] if len(sys.argv)>1 else None
if schema:assert schema.startswith('impe_supervision_test_') and schema.replace('_','').isalnum()

def blob(hash_):return subprocess.check_output(['git','cat-file','blob',hash_],cwd=ROOT)
def negar(fn):
    try:fn()
    except p.publicador.Bloqueo:return
    raise AssertionError('Se esperaba bloqueo')

with tempfile.TemporaryDirectory(prefix='impe-hostinger-compat-deploy-') as tmp:
    sitio=Path(tmp)/'public_html';stage=Path(tmp)/'stage';backups=Path(tmp)/'backups'
    for rel,old,new in p.ARCHIVOS_CANONICOS:
        if old:
            path=sitio/rel;path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(blob(old))
        path=stage/rel;path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(blob(new))
    for rel,h in p.DEPENDENCIAS_CANONICAS.items():
        path=sitio/rel;path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(blob(h))
    for rel,data in bases.items():
        path=sitio/rel;path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(data)
    for rel in p.ADAPTADOS:
        path=stage/(p.PREFIJO+rel);path.parent.mkdir(parents=True,exist_ok=True)
        path.write_bytes((ROOT/(p.PREFIJO+rel)).read_bytes())
    for rel in ['app/services/AgendaComentarioService.php','app/helpers/SeguimientoTerritorialScope.php']:
        path=sitio/rel;path.parent.mkdir(parents=True,exist_ok=True);path.write_text('<?php',encoding='utf-8')
    conservados={rel:(sitio/rel).read_bytes() for rel in [
        'app/helpers/ReminderHelper.php','app/services/AgendaReunionService.php',
        'app/views/layout/topbar.php','app/views/layout/dashboard_footer.php']}

    def run(*args):
        previous=sys.argv.copy()
        try:
            sys.argv=['compatible','--site',str(sitio),'--stage',str(stage),*args]
            p.main()
        finally:sys.argv[:]=previous

    with contextlib.redirect_stdout(io.StringIO()):
        # El CHECK real bloquea sin conexión/esquema y no sustituye archivos.
        negar(lambda:run('--check'))
        assert not backups.exists()
        assert p.HOSTINGER and len(p.ARCHIVOS)==6
        assert all(rel!='app/views/layout/topbar.php' for rel,_,_ in p.ARCHIVOS)
        esquema=p.comprobar_esquema
        if schema:
            (sitio/'config').mkdir()
            (sitio/'config/db_connection.php').write_text("<?php class Database {function connect($x=false){return new mysqli('127.0.0.1',getenv('MYSQL_USER'),getenv('MYSQL_PASSWORD'),'"+schema+"',(int)getenv('MYSQL_PORT'));}}",encoding='utf-8')
        else:p.comprobar_esquema=lambda sitio:None  # El esquema se comprueba por separado en MariaDB aislada.
        try:
            run('--check')
            h=sitio/'app/helpers/ReminderHelper.php';original=h.read_bytes();h.write_bytes(original+b'\n// desconocido')
            negar(lambda:run('--apply','--backup-root',str(backups),'--confirm','PUBLICAR_SEGUIMIENTO_ACCIONES_V3_IMPE'))
            assert not backups.exists();h.write_bytes(original)
            # No basta permitir hashes: también se verifica el paquete fusionado.
            js=stage/(p.PREFIJO+'public/javascript/recordatorios.js');original=js.read_bytes();js.write_bytes(original+b'\n// alterado')
            negar(lambda:run('--apply','--backup-root',str(backups),'--confirm','PUBLICAR_SEGUIMIENTO_ACCIONES_V3_IMPE'))
            assert not backups.exists();js.write_bytes(original)
            run('--apply','--backup-root',str(backups),'--confirm','PUBLICAR_SEGUIMIENTO_ACCIONES_V3_IMPE')
            run('--check')
            run('--apply','--backup-root',str(backups),'--confirm','PUBLICAR_SEGUIMIENTO_ACCIONES_V3_IMPE')
            assert len(list(backups.iterdir()))==1
            backup=next(backups.iterdir())
            for rel,data in conservados.items():assert (sitio/rel).read_bytes()==data,rel
            for rel,old,new in p.ARCHIVOS_HOSTINGER:
                assert p.publicador.blob(str(sitio/rel))==new
                if old:assert p.publicador.blob(str(backup/rel))==old
            js=sitio/'public/javascript/recordatorios.js';actual=js.read_bytes();js.write_bytes(actual+b'\n// personalizacion nueva')
            negar(lambda:run('--rollback','--backup-dir',str(backup),'--confirm','REVERTIR_SEGUIMIENTO_ACCIONES_V3_IMPE'))
            js.write_bytes(actual)
            run('--rollback','--backup-dir',str(backup),'--confirm','REVERTIR_SEGUIMIENTO_ACCIONES_V3_IMPE')
            assert p.publicador.estado(str(sitio))=='BASE'
            for rel,data in conservados.items():assert (sitio/rel).read_bytes()==data,rel
            real=p.publicador.atomico;calls=[0]
            def falla(dest,source,mode):
                calls[0]+=1
                if calls[0]==2:raise OSError('fallo sintético')
                return real(dest,source,mode)
            p.publicador.atomico=falla
            try:
                try:run('--apply','--backup-root',str(backups),'--confirm','PUBLICAR_SEGUIMIENTO_ACCIONES_V3_IMPE')
                except OSError:pass
                else:raise AssertionError('Se esperaba fallo de publicación')
            finally:p.publicador.atomico=real
            assert p.publicador.estado(str(sitio))=='BASE'
            for rel,data in conservados.items():assert (sitio/rel).read_bytes()==data,rel
        finally:p.comprobar_esquema=esquema
print('CHECK_OK bases Hostinger exactas: CHECK, bloqueo de cambios desconocidos, paquete verificado, respaldo, APPLY idempotente, ROLLBACK, fallo parcial y cuatro archivos conservados byte por byte.')
