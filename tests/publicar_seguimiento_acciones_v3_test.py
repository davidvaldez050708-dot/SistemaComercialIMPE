"""Publicación y reversión sobre archivos sintéticos; nunca conecta a producción."""
from pathlib import Path
import contextlib
import importlib
import io
import subprocess
import sys
import tempfile

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools'))
paquete=importlib.import_module('publicar_seguimiento_acciones_v3_hostinger')
pub=paquete.publicador
pub.ARCHIVOS=paquete.ARCHIVOS
pub.NO_MODIFICAR=paquete.DEPENDENCIAS

def gitblob(hash_):
    return subprocess.check_output(['git','cat-file','blob',hash_],cwd=ROOT)

def bloqueado(fn):
    try: fn()
    except pub.Bloqueo: return
    raise AssertionError('Se esperaba bloqueo')

with tempfile.TemporaryDirectory(prefix='impe-seguimiento-deploy-') as tmp:
    root=Path(tmp);site=root/'public_html';stage=root/'stage';backups=root/'backups'
    for rel,old,new in paquete.ARCHIVOS:
        target=site/rel;target.parent.mkdir(parents=True,exist_ok=True)
        if old: target.write_bytes(gitblob(old))
        source=stage/rel;source.parent.mkdir(parents=True,exist_ok=True)
        source.write_bytes(gitblob(new))
    for rel,hash_ in paquete.DEPENDENCIAS.items():
        target=site/rel;target.parent.mkdir(parents=True,exist_ok=True);target.write_bytes(gitblob(hash_))
    with contextlib.redirect_stdout(io.StringIO()):
        assert pub.estado(str(site))=='BASE'
        assert not backups.exists()
        # Missing schema/connection must fail before publication.
        bloqueado(lambda:paquete.comprobar_esquema(str(site)))
        target=site/paquete.ARCHIVOS[1][0];original=target.read_bytes()
        target.write_bytes(original+b'\n// personalizacion\n')
        bloqueado(lambda:pub.estado(str(site)))
        assert not backups.exists();target.write_bytes(original)
        for rel,_,_ in paquete.ARCHIVOS:
            if rel.endswith('.php'):pub.lint_php(str(stage/rel))
            if rel.endswith('.js'):pub.lint_js(str(stage/rel))
        pub.publicar(str(site),str(stage),str(backups))
        assert pub.estado(str(site))=='READY'
        backup=next(backups.iterdir())
        for rel,old,new in paquete.ARCHIVOS:
            assert pub.blob(str(site/rel))==new
            if old:assert pub.blob(str(backup/rel))==old
        pub.publicar(str(site),str(stage),str(backups))
        assert len(list(backups.iterdir()))==1,'apply idempotente'
        changed=site/paquete.ARCHIVOS[-1][0];approved=changed.read_bytes()
        changed.write_bytes(approved+b'\n// personalizacion\n')
        bloqueado(lambda:pub.revertir(str(site),str(backup)))
        changed.write_bytes(approved)
        pub.revertir(str(site),str(backup))
        assert pub.estado(str(site))=='BASE'
        assert not (site/paquete.ARCHIVOS[0][0]).exists()
        # Failure during publication returns the complete previous version.
        real=pub.atomico;calls=[0]
        def fail_once(dest,source,mode):
            calls[0]+=1
            if calls[0]==3:raise OSError('fallo sintético')
            return real(dest,source,mode)
        pub.atomico=fail_once
        try:
            try:pub.publicar(str(site),str(stage),str(backups))
            except OSError:pass
            else:raise AssertionError('Se esperaba fallo')
        finally:pub.atomico=real
        assert pub.estado(str(site))=='BASE'
print('CHECK_OK entrega: hashes, sintaxis, personalizaciones, esquema ausente, apply idempotente, respaldo, rollback y fallo parcial.')
