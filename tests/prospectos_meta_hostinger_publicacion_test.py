"""Instala sólo sobre copias exactas de la variante revisada. Sin SQL ni red."""
import base64
import contextlib
import gzip
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import subprocess
import sys
import tempfile
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('publisher',ROOT/'tools/publicar_prospectos_importador_meta.py')
pub=importlib.util.module_from_spec(spec);spec.loader.exec_module(pub)
compat=pub.compatibility(ROOT/'tools/prospectos_meta_rol_hostinger_compat.json')
before_model=gzip.decompress(base64.b64decode(compat['base']))
policy=gzip.decompress(base64.b64decode(compat['dependency']['fixture']))
assert hashlib.sha256(policy).hexdigest()==compat['dependency']['sha256']
assert 'telefonia.control_ver' in compat['payload'].decode()
assert 'permisoReservadoParaRol' in compat['payload'].decode()
assert 'prospectos.importar_meta' in compat['payload'].decode()


def source(ref,name):
    return subprocess.check_output(['git','show',ref+':'+name],cwd=str(ROOT))


def execute(site,backup,*flags):
    args=['publisher','--site',str(site),'--backup-root',str(backup)]+list(flags)
    with patch.object(sys,'argv',args),patch.object(pub,'check_extensions',return_value=None),contextlib.redirect_stdout(io.StringIO()):
        with patch.object(pub,'fetch',side_effect=lambda n,h:(ROOT/n).read_bytes().replace(b'\r\n',b'\n')):
            pub.main()


for eol in [b'\n',b'\r\n']:
    with tempfile.TemporaryDirectory(prefix='meta-hostinger-publicacion-') as temp:
        site,backup=Path(temp)/'site',Path(temp)/'private';site.mkdir()
        (site/'index.php').write_bytes(b'<?php')
        for dependency in ['config/db_connection.php','app/services/ProspectoCentralSchema.php',
                'public/javascript/prospectos_fase1.js','public/javascript/prospectos_central.js',
                'app/services/ProspectoCentralRepository.php','app/views/prospectos/partials/ficha_panel.php']:
            p=site/dependency;p.parent.mkdir(parents=True,exist_ok=True);p.write_bytes((ROOT/dependency).read_bytes())
        # El importador previo ya estaba instalado, pero conservaba el guard de Marketing.
        for name in pub.FILES:
            p=site/name;p.parent.mkdir(parents=True,exist_ok=True)
            content=source('13f0cae3da684f43149e9f51c6093598bd2e02fb',name)
            if name==compat['path']:content=before_model.replace(b'\n',eol)
            p.write_bytes(content)
        p=site/compat['dependency']['path'];p.write_bytes(policy)

        def snapshot():
            return {p.relative_to(site).as_posix():p.read_bytes() for p in site.rglob('*') if p.is_file()}

        original=snapshot();execute(site,backup,'--check');assert snapshot()==original
        model=site/compat['path'];model.write_bytes(model.read_bytes()+b'\n// otra variante')
        changed=snapshot()
        try:
            execute(site,backup,'--apply');raise AssertionError('Debió bloquear otro modelo')
        except RuntimeError as e:assert 'VERSIÓN DIFERENTE' in str(e)
        assert snapshot()==changed;model.write_bytes(original[compat['path']])
        dep=site/compat['dependency']['path'];dep.write_bytes(policy+b'\n// nueva politica')
        changed=snapshot()
        try:
            execute(site,backup,'--apply');raise AssertionError('Debió bloquear otra política')
        except RuntimeError as e:assert 'DEPENDENCIA DIFERENTE' in str(e)
        assert snapshot()==changed;dep.write_bytes(policy)
        execute(site,backup,'--apply');installed=snapshot()
        assert model.read_bytes()==compat['payload'].replace(b'\n',eol)
        assert dep.read_bytes()==policy
        for name in pub.FILES:
            if name!=compat['path']:assert (site/name).read_bytes()==(ROOT/name).read_bytes().replace(b'\r\n',b'\n')
        saved=next(backup.glob('*/respaldo.json')).parent
        execute(site,backup,'--apply');assert snapshot()==installed
        # Tras instalar los permisos personalizados, actualizar el selector
        # debe escribir exclusivamente la vista; no volver a tocar Roles.
        view='app/views/prospectos/partials/importar_meta.php'
        (site/view).write_bytes(source('13f0cae3da684f43149e9f51c6093598bd2e02fb',view))
        real_atomic,written=pub.atomic,[]

        def record_site(dest,*args,**kwargs):
            if site in dest.parents:written.append(dest.relative_to(site).as_posix())
            return real_atomic(dest,*args,**kwargs)

        with patch.object(pub,'atomic',record_site):execute(site,backup,'--apply')
        assert written==[view],written
        assert snapshot()==installed
        execute(site,backup,'--restore',str(saved));assert snapshot()==original
        # Error entre recursos: rollback completo, incluida la política protegida.
        real_atomic,writes=pub.atomic,[0]

        def failing_atomic(*args,**kwargs):
            writes[0]+=1
            if writes[0]==3:raise OSError('Fallo de escritura simulado')
            return real_atomic(*args,**kwargs)

        # Los respaldos usan atomic también; contar sólo escrituras al sitio.
        def fail_site(dest,*args,**kwargs):
            if site in dest.parents:return failing_atomic(dest,*args,**kwargs)
            return real_atomic(dest,*args,**kwargs)

        with patch.object(pub,'atomic',fail_site):
            try:
                execute(site,backup,'--apply');raise AssertionError('Debió revertir')
            except OSError:pass
        assert snapshot()==original
print('PASS: variante exacta LF/CRLF, importador previo, política intacta, check, bloqueo de desconocidos, instalación, idempotencia, restore y rollback. Sin SQL.')
