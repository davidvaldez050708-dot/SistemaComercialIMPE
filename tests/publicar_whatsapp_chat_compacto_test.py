"""Entrega visual limitada a cuatro archivos; no necesita conexión a una base de datos."""
from pathlib import Path
import contextlib
import io
import subprocess
import sys
import tempfile
ROOT=Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'tools'))
import publicar_whatsapp_chat_compacto_hostinger as p
def write(path,data):path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(data)
def deny(fn):
    try:fn()
    except p.motor.Bloqueo:return
    raise AssertionError('Debió bloquearse')
with tempfile.TemporaryDirectory(prefix='impe-wa-compact-test-') as temp:
    site=Path(temp)/'public_html';stage=Path(temp)/'stage';backups=Path(temp)/'backups'
    for rel,old,new in p.ARCHIVOS:
        write(site/rel,subprocess.check_output(['git','cat-file','blob',old],cwd=ROOT))
        write(stage/rel,(ROOT/rel).read_bytes())
    untouched={'config/db_connection.php':b'<?php throw new RuntimeException("No debe consultarse SQL");','config/whatsapp.local.php':b'<?php return [];','app/controllers/WhatsAppController.php':b'<?php // controlador conservado'}
    for rel,data in untouched.items():write(site/rel,data)
    def run(*args):
        previous=sys.argv.copy()
        try:sys.argv=['compacto','--site',str(site),'--stage',str(stage),*args];p.main()
        finally:sys.argv[:]=previous
    apply=lambda:run('--apply','--backup-root',str(backups),'--confirm','PUBLICAR_WHATSAPP_CHAT_COMPACTO_IMPE')
    with contextlib.redirect_stdout(io.StringIO()):
        run('--check');assert not backups.exists()
        file=site/p.ARCHIVOS[0][0];original=file.read_bytes();file.write_bytes(original+b'\n/* personalizado */')
        deny(apply);file.write_bytes(original);assert not backups.exists()
        apply();run('--check');apply();assert len(list(backups.iterdir()))==1
        backup=next(backups.iterdir())
        for rel,old,new in p.ARCHIVOS:assert p.motor.blob(str(site/rel))==new
        revert=lambda:run('--rollback','--backup-dir',str(backup),'--confirm','REVERTIR_WHATSAPP_CHAT_COMPACTO_IMPE')
        file=site/p.ARCHIVOS[0][0];original=file.read_bytes();file.write_bytes(original+b'\n/* personalizado luego */')
        deny(revert);file.write_bytes(original);revert();assert p.motor.estado(str(site))=='BASE'
        atomic=p.motor.atomico;calls=[0]
        def failure(*args):
            calls[0]+=1
            if calls[0]==2:raise OSError('Fallo parcial sintético')
            return atomic(*args)
        p.motor.atomico=failure
        try:
            try:apply()
            except OSError:pass
            else:raise AssertionError('Debió fallar')
        finally:p.motor.atomico=atomic
        assert p.motor.estado(str(site))=='BASE'
        for rel,data in untouched.items():assert (site/rel).read_bytes()==data
print('CHECK_OK entrega visual: cuatro archivos, CHECK/APPLY idempotente, respaldo, ROLLBACK, rechazo de personalizaciones y recuperación de fallo parcial; sin consultar SQL ni cambiar controladores o configuración.')
