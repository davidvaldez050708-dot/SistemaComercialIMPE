from pathlib import Path
import sys,subprocess,json,hashlib
root=Path(__file__).resolve().parents[2];here=Path(__file__).resolve().parent
subprocess.run([sys.executable,str(root/'tools/whatsapp_avisos_v2/prepare_sql.py')],check=True)
m=json.loads((here/'manifest.json').read_text(encoding='utf-8'))
def blob(b):return hashlib.sha1(b'blob '+str(len(b)).encode()+b'\0'+b).hexdigest()
for p in m['patches']:
    if p['path'] not in ('app/models/WhatsAppModel.php','app/services/WhatsAppAvisosService.php'):continue
    file=root/p['path'];b=file.read_bytes()
    assert blob(b)==p['before'],p['path']
    for h in p['hunks']:
        a,z=h['old'].encode(),h['new'].encode()
        assert b.count(a)==1,h['old'][:100]
        b=b.replace(a,z,1)
    file.write_bytes(b)
    subprocess.run(['php','-l',str(file)],check=True)
    print('CANDIDATO_CI | '+p['path']+' | '+blob(b))
(root/'app/services/WhatsAppPresentacionService.php').write_bytes((here/'WhatsAppPresentacionService.php').read_bytes())
p=root/'tests/whatsapp_ventas_db.php'
s=p.read_text(encoding='utf-8')
a=" require __DIR__.'/../tools/whatsapp_avisos_v2/test_sql.php';"
assert s.count(a)==1
p.write_text(s.replace(a,a+"\n require __DIR__.'/../tools/whatsapp_refinamiento_v3/test_sql.php';"),encoding='utf-8')
