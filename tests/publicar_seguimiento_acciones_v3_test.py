"""Publicación y reversión sobre archivos sintéticos; nunca conecta a producción."""
from pathlib import Path
import contextlib
import importlib
import io
import os
import subprocess
import sys
import tempfile

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools'))
paquete=importlib.import_module(os.environ.get('IMPE_PUBLICADOR_MODULO','publicar_seguimiento_acciones_v3_hostinger'))
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
        paquete.configurar_dependencias(str(site))
        assert pub.estado(str(site))=='BASE'
        assert not backups.exists()
        # Reproduce exactly the production diff; arbitrary changes stay blocked.
        telefono=site/paquete.TELEFONIA_REL
        canonico=telefono.read_bytes()
        source=canonico.decode('utf-8')
        inicio=source.index('        $resultadoId=(int)$this->db->insert_id;\n')
        ultimo="            $version=$datos['catalogo_version'];"
        fin=source.index(ultimo,inicio)+len(ultimo)
        variante=(source[:inicio]+"        if($detalle!==null){\n            $resultadoId=(int)$this->db->insert_id;$version=$datos['catalogo_version'];"+source[fin:]).encode('utf-8')
        assert pub.blob_bytes(variante)==paquete.TELEFONIA_HOSTINGER
        # The effective result-to-funnel bridge is identical and does not read
        # the optional column; only the earlier result insert differs.
        marcador=b'        // IMPE_EMBUDO_PBX_POSTCOMMIT_V1'
        assert canonico.split(marcador)[1]==variante.split(marcador)[1]
        assert b"$usuarioId, (int)$idComercial, $call, $resultado, $next ?? null" in variante
        telefono.write_bytes(variante+b'\n// cambio no revisado\n')
        bloqueado(lambda:paquete.configurar_dependencias(str(site)))
        telefono.write_bytes(variante)
        paquete.configurar_dependencias(str(site))
        assert pub.estado(str(site))=='BASE'
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
        assert telefono.read_bytes()==variante,'personalización intacta tras apply y rollback'
        for rel,old,_ in paquete.ARCHIVOS:
            if old is None:assert not (site/rel).exists()
            else:assert pub.blob(str(site/rel))==old
        # Failure during publication returns the complete previous version.
        real=pub.atomico;calls=[0]
        def fail_once(dest,source,mode):
            calls[0]+=1
            if calls[0]==2:raise OSError('fallo sintético')
            return real(dest,source,mode)
        pub.atomico=fail_once
        try:
            try:pub.publicar(str(site),str(stage),str(backups))
            except OSError:pass
            else:raise AssertionError('Se esperaba fallo')
        finally:pub.atomico=real
        assert pub.estado(str(site))=='BASE'
        assert telefono.read_bytes()==variante,'personalización intacta tras fallo parcial'
print('CHECK_OK entrega: hashes, sintaxis, personalizaciones, esquema ausente, apply idempotente, respaldo, rollback y fallo parcial.')
