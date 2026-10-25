"""Réplicas descartables: nunca abre BD ni llama al proveedor."""
import ast,base64,gzip,hashlib,json,os,subprocess,sys,tempfile,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
SCRIPT=ROOT/'tools/publicar_telefonia_integridad.py'
SPEC=json.loads((ROOT/'tools/telefonia_integridad_release.json').read_text(encoding='utf8'))
FIRST=SPEC['files'][0]['path']
HOST='public/javascript/telefonia_host.js'
def data(value):return gzip.decompress(base64.b64decode(value))
def snapshot(root):return {p.relative_to(root).as_posix():p.read_bytes() for p in root.rglob('*') if p.is_file()}
class Publisher(unittest.TestCase):
 def scenario(self,kind):
  with tempfile.TemporaryDirectory() as temp:
   top=Path(temp);site=top/'site';site.mkdir();backup=top/'backups';(site/'index.php').write_bytes(b'<?php // synthetic')
   (site/'other.txt').write_bytes(b'NO CAMBIAR')
   for row in SPEC['files']:
    variant=row['variants'][-1] if kind=='hostinger' else row['variants'][0]
    if variant['before'] is not None:
     p=site/row['path'];p.parent.mkdir(parents=True,exist_ok=True);b=data(variant['before'])
     if kind=='crlf':b=b.replace(b'\n',b'\r\n')
     p.write_bytes(b)
   if kind=='unknown':(site/HOST).write_bytes(b'// variante no revisada')
   
   if kind=='partial':(site/FIRST).write_bytes(data(SPEC['files'][0]['variants'][0]['after']))
   before=snapshot(site)
   def run(mode,*extra):
    return subprocess.run([sys.executable,'-B',str(SCRIPT),'--site',str(site),'--backup-root',str(backup),mode,*extra],capture_output=True,text=True,encoding='utf8',env=dict(os.environ,PYTHONIOENCODING='utf-8'))
   if kind in ('unknown',):
    r=run('--apply');self.assertNotEqual(r.returncode,0,r.stdout+r.stderr);self.assertEqual(snapshot(site),before);self.assertFalse(backup.exists());return
   r=run('--check');self.assertEqual(r.returncode,0,r.stderr);self.assertEqual(snapshot(site),before);self.assertFalse(backup.exists())
   r=run('--apply');self.assertEqual(r.returncode,0,r.stdout+r.stderr);after=snapshot(site)
   for row in SPEC['files']:
    variant=row['variants'][-1] if kind=='hostinger' else row['variants'][0]
    self.assertEqual((site/row['path']).read_bytes().replace(b'\r\n',b'\n'),data(variant['after']))
   self.assertEqual((site/'other.txt').read_bytes(),b'NO CAMBIAR')
   r=run('--apply');self.assertEqual(r.returncode,0,r.stderr);self.assertEqual(len(list(backup.iterdir())),1)
   saved=next(backup.iterdir())
   if kind=='restore-conflict':
    (site/HOST).write_bytes(b'// cambio posterior')
    changed=snapshot(site);r=run('--restore',str(saved));self.assertNotEqual(r.returncode,0);self.assertEqual(snapshot(site),changed);return
   if kind=='interrupted':
    row=next(r for r in SPEC['files'] if r['path']==HOST)
    (site/row['path']).write_bytes(data(row['variants'][0]['before']))
   r=run('--restore',str(saved));self.assertEqual(r.returncode,0,r.stdout+r.stderr);self.assertEqual(snapshot(site),before)
 def test_base(self):self.scenario('base')
 def test_hostinger_exact(self):self.scenario('hostinger')
 def test_crlf(self):self.scenario('crlf')
 def test_partial(self):self.scenario('partial')
 def test_unknown(self):self.scenario('unknown')
 def test_restore_conflict(self):self.scenario('restore-conflict')
 def test_interrupted(self):self.scenario('interrupted')
 def test_python36(self):ast.parse(SCRIPT.read_text(encoding='utf8'),feature_version=(3,6))
if __name__=='__main__':unittest.main(verbosity=2)
