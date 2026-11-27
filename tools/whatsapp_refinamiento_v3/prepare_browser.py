from pathlib import Path
import json,hashlib,subprocess
here=Path(__file__).resolve().parent
m=json.loads((here/'manifest.json').read_text(encoding='utf-8'))
out=here/'ci_assets';out.mkdir(exist_ok=True)
def blob(b):return hashlib.sha1(b'blob '+str(len(b)).encode()+b'\0'+b).hexdigest()
for p in m['patches']:
    if not p['path'].endswith('.js'):continue
    f=out/Path(p['path']).name
    subprocess.run(['curl','-fsSL','--max-time','30','--retry','2','https://fundacionrededumex.org/'+p['path'],'-o',str(f)],check=True)
    b=f.read_bytes()
    assert blob(b)==p['before'],p['path']+' '+blob(b)
    for h in p['hunks']:
        a,z=h['old'].encode(),h['new'].encode()
        assert b.count(a)==1,h['old'][:100]
        b=b.replace(a,z,1)
    f.write_bytes(b)
    subprocess.run(['node','--check',str(f)],check=True)
    print('CANDIDATO_CI | '+p['path']+' | '+blob(b))
