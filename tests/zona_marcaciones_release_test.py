import ast,json,subprocess,sys,tempfile
from pathlib import Path
root=Path(__file__).resolve().parents[1];sys.path.insert(0,str(root/'tools'))
import publicar_zona_marcaciones as pub
spec=json.loads((root/'tools/zona_marcaciones_release.json').read_text(encoding='utf-8'))
old=(root/'tests/fixtures/TelefoniaMarcacionesVentasService.instalada_20261123.php.txt').read_bytes()
new=pub.candidate(old,spec['files'][0]);pub.lint(new,spec['files'][0]['path'])
assert pub.candidate(new,spec['files'][0])==new
assert pub.candidate(old.replace(b'\r\n',b'\n').replace(b'\n',b'\r\n'),spec['files'][0]).replace(b'\r\n',b'\n')==new.replace(b'\r\n',b'\n')
assert b'5 SECOND' in new and b'30 SECOND' in new and b'created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)' in new
assert b'WHERE pbx_call_id = ?' in new and b'Esta llamada pertenece a un expediente' in new
ast.parse((root/'tools/publicar_zona_marcaciones.py').read_text(encoding='utf-8'),feature_version=(3,6))
def run(args):
    p=subprocess.run(args,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    assert p.returncode==0,(p.stdout+p.stderr).decode(errors='replace')
    return p.stdout.decode(errors='replace')
with tempfile.TemporaryDirectory(prefix='impe-zona-release-') as tmp:
    tmp=Path(tmp);site=tmp/'public_html';private=tmp/'impe-private';private.mkdir();site.mkdir()
    (site/'index.php').write_text('<?php');p=site/spec['files'][0]['path'];p.parent.mkdir(parents=True)
    original=old+b'\n// PERSONALIZACION_CONSERVADA\n';p.write_bytes(original)
    helper=site/'app/helpers/PerfilPeticion.php';helper.parent.mkdir(parents=True);helper.write_text('<?php')
    command=[sys.executable,str(root/'tools/publicar_zona_marcaciones.py'),'--site',str(site),'--backup-root',str(private)]
    run(command+['--check']);assert not list(private.iterdir())
    run(command+['--apply']);assert p.read_bytes()==new+b'\n// PERSONALIZACION_CONSERVADA\n'
    flag=json.loads((private/'perfil-peticiones-activo.json').read_text());mark=json.loads((private/'consultas-repetidas-publicado.json').read_text())
    assert flag['version']==1 and flag['hasta']==mark['desde']+3600
    run(command+['--apply']);backups=list(private.glob('zona-marcaciones-*/manifest.json'));assert len(backups)==1
    run(command+['--restore',str(backups[0].parent)]);assert p.read_bytes()==original
    p.write_bytes(original.replace(b'$source=$this->zonaMySQL();',b'$source=otraZona();'))
    changed=p.read_bytes();result=subprocess.run(command+['--apply'],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    assert result.returncode!=0 and p.read_bytes()==changed
print('PASS publicación de zona: archivo instalado, PHP lint, CRLF, personalizaciones, idempotencia, rechazo sin escritura, respaldo/restauración, Python 3.6 y reactivación del perfil.')
