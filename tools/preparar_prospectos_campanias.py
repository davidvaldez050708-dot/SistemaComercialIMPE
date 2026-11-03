"""Paquete de código offline. Conserva ambas variantes revisadas de Roles."""
import base64,gzip,hashlib,importlib.util,json,subprocess
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
BASE='044175f75c116f47609fb409712afc1fe26f38a6'
def pack(b):return base64.b64encode(gzip.compress(b,mtime=0)).decode() if b is not None else None
def sha(b):return hashlib.sha256(b).hexdigest() if b is not None else None
def source(name):return subprocess.check_output(['git','show',BASE+':'+name],cwd=ROOT)
spec=importlib.util.spec_from_file_location('p',ROOT/'tools/publicar_prospectos_campanias.py');p=importlib.util.module_from_spec(spec);spec.loader.exec_module(p)
new=set(p.FILES[:5]);rows=[]
for name in p.FILES:
    before=None if name in new else source(name);after=(ROOT/name).read_bytes().replace(b'\r\n',b'\n')
    rows.append(dict(path=name,base_sha256=sha(before),sha256=sha(after),base=pack(before),target=pack(after)))
role=next(r for r in rows if r['path']=='app/models/RolModel.php')
compat=json.loads((ROOT/'tools/prospectos_meta_rol_hostinger_compat.json').read_text(encoding='utf-8'))
custom=gzip.decompress(base64.b64decode(compat['target']))
assert sha(custom)=='5fc6a4e5cb5fce15231c4ab8b3cf171095decee1778c8280898b630a62f93abb'
# Aplicar únicamente los dos puntos de extensión de la función nueva.
before=source(role['path']).decode();after=(ROOT/role['path']).read_text(encoding='utf-8')
added=[line for line in after.splitlines() if 'prospectos.configurar_formularios' in line]
assert len(added)==2
anchors=["            'prospectos.importar_meta' => 'prospectos.ver_todos',",next(l for l in before.splitlines() if "'codigo' => 'prospectos.importar_meta'" in l)]
updated=custom.decode()
for anchor,line in zip(anchors,added):
    assert updated.count(anchor)==1
    updated=updated.replace(anchor,anchor+'\n'+line)
updated=updated.encode();role['variants']=[dict(base_sha256=sha(custom),sha256=sha(updated),base=pack(custom),target=pack(updated))]
deps=[dict(path=name,sha256=sha(source(name))) for name in p.DEPENDENCIES if name!=compat['dependency']['path']]
deps.append(dict(path=compat['dependency']['path'],sha256=compat['dependency']['sha256'],fixture=compat['dependency']['fixture'],when_role_hashes=[sha(custom),sha(updated)]))
manifest=dict(schema=1,source='Campañas y programas; base '+BASE,files=rows,dependencies=deps)
(ROOT/'tools/prospectos_campanias_release.json').write_text(json.dumps(manifest,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
print('Paquete generado: 16 archivos, variante Hostinger conservada; sin SQL automático.')
