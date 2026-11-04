"""Publicación aislada sin llamadas reales, SQL ni tocar el sitio público."""
import json
from pathlib import Path
import subprocess
import sys
import tempfile

ROOT=Path(__file__).resolve().parents[1]
MANIFEST=ROOT/'tools/analista_historial_overlay_manifest.json'
PUBLISH=ROOT/'tools/publicar_analista_historial_overlay.py'
spec=json.loads(MANIFEST.read_text(encoding='utf8'))
assert len(spec['files'])==8
with tempfile.TemporaryDirectory(prefix='impe-analista-overlay-test-') as d:
    root=Path(d);site=root/'site';site.mkdir()
    (site/'index.php').write_text('<?php // CRM de prueba',encoding='utf8')
    backup=root/'private'
    originals={}
    for row in spec['files']:
        path=site/row['path']
        if row['action']=='patch':
            old=subprocess.check_output(['git','show',spec['base']+':'+row['path']],cwd=str(ROOT))
            path.parent.mkdir(parents=True,exist_ok=True)
            # Hostinger puede tener personalizaciones DENTRO del hunk
            # histórico de shell.php; conservarlas y sólo insertar el include.
            if row['path']=='app/views/telefonia/shell.php':
                marker=b'public/javascript/telefonia_analista_historial_pasivo.js?v='
                assert old.count(marker)==1
                start=old.rfind(b'\n',0,old.index(marker))+1
                old=(old[:start]+b'    <!-- BLOQUE PERSONALIZADO DE HOSTINGER -->\n'+old[start:])
            if row['path']=='app/services/TelefoniaEntrantesAnalistaService.php':
                # Personalización dentro del primer fragmento heredado.
                original='        // Dos fuentes distintas: PBX firmada y observación de WebRTC local.'.encode('utf-8')
                changed='        // HOSTINGER: conservar la separación de PBX y WebRTC.'.encode('utf-8')
                assert old.count(original)==1
                old=old.replace(original,changed)
            if row['path']=='app/views/telefonia/mis_llamadas.php':
                # Cambios reales de diseño dentro de los tres hunks previos:
                # enlace de devolución, tarjeta y leyenda del historial.
                link=b"\$detalleTexto = 'Ver devoluci"
                assert old.count(link)==1
                old=old.replace(link, b"\$detalleTexto = 'Consultar devoluci",1)
                marker=b'<div class="linkage-call-history-footer">'
                assert old.count(marker)==1
                old=old.replace(marker,marker+b'\n                        <!-- ESTILO HOSTINGER CONSERVADO -->',1)
                old=old.replace('Este historial muestra tramos'.encode('utf-8'),
                                'Historial personalizado: muestra tramos'.encode('utf-8'),1)
            path.write_bytes(old+b'\n/* PERSONALIZACION LOCAL INTACTA */\n')
            originals[row['path']]=path.read_bytes()
        else:
            originals[row['path']]=None
    def snapshot():
        return {p.relative_to(site).as_posix():p.read_bytes()
                for p in site.rglob('*') if p.is_file()}
    def run(*args,ok=True):
        x=subprocess.run([sys.executable,str(PUBLISH),'--site',str(site),
            '--backup-root',str(backup),'--manifest',str(MANIFEST)]+list(args),
            stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        out=(x.stdout+x.stderr).decode('utf-8','replace')
        if (x.returncode==0)!=ok:
            raise AssertionError(out)
        return out
    initial=snapshot()
    assert 'CHECK OK' in run('--check')
    assert snapshot()==initial and not backup.exists(), 'Preflight no debe escribir nada'
    target=site/spec['files'][2]['path']
    old=target.read_bytes()
    h=spec['files'][2]['changes'][0]['before'].encode()
    assert old.count(h)==1
    target.write_bytes(old.replace(h,b'// CONFLICTO AJENO',1))
    broken=snapshot()
    assert 'CONFLICTO' in run('--check',ok=False)
    assert snapshot()==broken and not backup.exists()
    target.write_bytes(old)
    assert 'APLICADO' in run('--apply')
    after=snapshot()
    for row in spec['files']:
        path=site/row['path']
        assert path.is_file(),row['path']
        if row['action']=='patch':
            assert b'/* PERSONALIZACION LOCAL INTACTA */' in path.read_bytes()
        else:
            assert path.read_bytes()==row['content'].encode()
    shell=site/'app/views/telefonia/shell.php'
    assert shell.read_bytes().count(b'BLOQUE PERSONALIZADO DE HOSTINGER')==1
    assert shell.read_bytes().count(b'public/javascript/telefonia_analista_resultado_superpuesto.js?v=')==1
    assert shell.read_bytes().index(b'public/javascript/telefonia_analista_resultado_superpuesto.js?v=') < shell.read_bytes().index(b'public/javascript/telefonia_analista_historial_pasivo.js?v=')
    service=site/'app/services/TelefoniaEntrantesAnalistaService.php'
    assert service.read_bytes().count('HOSTINGER: conservar la separación de PBX y WebRTC.'.encode('utf-8'))==1
    assert service.read_bytes().count(b'$stmtSalientes = $this->db->prepare(')==1
    assert service.read_bytes().index(b'$stmtSalientes = $this->db->prepare(')<service.read_bytes().index(b'$sesiones = $clasificadorInterno->listar($usuarioId);')
    history=site/'app/views/telefonia/mis_llamadas.php'
    content=history.read_bytes()
    assert b'ESTILO HOSTINGER CONSERVADO' in content
    assert b"Consultar devoluci" in content
    assert 'Historial personalizado: muestra tramos'.encode('utf-8') in content
    assert b"(!$saliente && $pbxId" not in content
    assert content.count(b'sdk_session_id=')==1
    assert content.count(b'pbx_call_id=')>=1
    assert b'Conexi' in content
    archives=[p for p in backup.iterdir() if p.is_dir()]
    assert len(archives)==1
    assert 'YA INSTALADO' in run('--check')
    assert 'YA INSTALADO' in run('--apply')
    assert len([p for p in backup.iterdir() if p.is_dir()])==1
    target.write_bytes(target.read_bytes()+b'\n// CAMBIO CONCURRENTE\n')
    assert 'CONFLICTO' in run('--restore',str(archives[0]),ok=False)
    target.write_bytes(after[spec['files'][2]['path']])
    assert 'RESTAURADO' in run('--restore',str(archives[0]))
    assert snapshot()==initial, 'Restauración íntegra incluso archivo nuevo'
print('PASS publicador: 8 archivos, cambios locales, idempotencia, conflicto y reversión')
