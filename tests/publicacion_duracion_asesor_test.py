"""Réplicas desechables: no abre el CRM ni la base de datos."""
import ast,base64,gzip,json,os,subprocess,sys,tempfile,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
SCRIPT=ROOT/'tools/publicar_duracion_asesor.py'
SPEC=json.loads((ROOT/'tools/duracion_asesor_release.json').read_text(encoding='utf8'))
def unpack(b):return gzip.decompress(base64.b64decode(b))
def snapshot(root):return {str(p.relative_to(root)):p.read_bytes() for p in root.rglob('*') if p.is_file()}
class Publisher(unittest.TestCase):
 def scenario(self,kind):
  with tempfile.TemporaryDirectory() as tmp:
   site=Path(tmp)/'site';site.mkdir();backups=Path(tmp)/'private'
   (site/'index.php').write_bytes(b'<?php // fixture');(site/'other.txt').write_bytes(b'INTOCABLE')
   for row in SPEC['files']:
    v=row['variants'][-1] if kind=='hostinger' else row['variants'][0]
    b=unpack(v['before']);p=site/row['path'];p.parent.mkdir(parents=True,exist_ok=True)
    p.write_bytes(b.replace(b'\n',b'\r\n') if kind=='crlf' else b)
   first=site/SPEC['files'][0]['path'];v=SPEC['files'][0]['variants'][0]
   if kind=='unknown':first.write_bytes(b'<?php // personalizacion desconocida')
   if kind=='partial':first.write_bytes(unpack(v['after']))
   before=snapshot(site)
   def run(mode,*args):return subprocess.run([sys.executable,'-B',str(SCRIPT),'--site',str(site),
      '--backup-root',str(backups),mode,*args],capture_output=True,text=True,encoding='utf8',
      env=dict(os.environ,PYTHONIOENCODING='utf-8'))
   if kind=='unknown':
    r=run('--apply');self.assertNotEqual(r.returncode,0);self.assertEqual(snapshot(site),before);self.assertFalse(backups.exists());return
   r=run('--check');self.assertEqual(r.returncode,0,r.stderr);self.assertEqual(snapshot(site),before);self.assertFalse(backups.exists())
   r=run('--apply');self.assertEqual(r.returncode,0,r.stdout+r.stderr)
   for row in SPEC['files']:
    variant=row['variants'][-1] if kind=='hostinger' else row['variants'][0]
    self.assertEqual((site/row['path']).read_bytes().replace(b'\r\n',b'\n'),unpack(variant['after']))
   after=snapshot(site);r=run('--apply');self.assertEqual(r.returncode,0,r.stderr);self.assertEqual(snapshot(site),after)
   self.assertEqual(len(list(backups.iterdir())),1);saved=next(backups.iterdir())
   if kind=='restore-conflict':
    first.write_bytes(b'<?php // cambio posterior');changed=snapshot(site)
    r=run('--restore',str(saved));self.assertNotEqual(r.returncode,0);self.assertEqual(snapshot(site),changed);return
   if kind=='interrupted':first.write_bytes(unpack(v['before']))
   r=run('--restore',str(saved));self.assertEqual(r.returncode,0,r.stdout+r.stderr);self.assertEqual(snapshot(site),before)
 def test_base(self):self.scenario('base')
 def test_crlf(self):self.scenario('crlf')
 def test_partial(self):self.scenario('partial')
 def test_unknown(self):self.scenario('unknown')
 def test_restore_conflict(self):self.scenario('restore-conflict')
 def test_interrupted(self):self.scenario('interrupted')
 def test_python36(self):ast.parse(SCRIPT.read_text(encoding='utf8'),feature_version=(3,6))
if __name__=='__main__':unittest.main(verbosity=2)
