import base64,difflib,hashlib,json,subprocess
from pathlib import Path
from publicar_whatsapp_diseno import FILES
root=Path(__file__).resolve().parents[1]
def before(name):
 rev='46742db' if name=='app/views/whatsapp/asignaciones.php' else 'd26d0f6'
 p=subprocess.run(['git','show',rev+':'+name],cwd=str(root),stdout=subprocess.PIPE,stderr=subprocess.PIPE)
 return None if p.returncode else p.stdout.replace(b'\r\n',b'\n')
def build():
 rows=[]
 for name in FILES:
  old=before(name);new=(root/name).read_bytes().replace(b'\r\n',b'\n');row=dict(path=name,new_file=old is None,after_sha=hashlib.sha256(new).hexdigest())
  if old is None:row['content']=base64.b64encode(new).decode()
  else:
   a,b=old.decode().splitlines(True),new.decode().splitlines(True);row['hunks']=[]
   for group in difflib.SequenceMatcher(None,a,b,autojunk=False).get_grouped_opcodes(3):
    first,last=group[0],group[-1];row['hunks'].append(dict(before=''.join(a[first[1]:last[2]]),after=''.join(b[first[3]:last[4]])))
  rows.append(row)
 (root/'tools/whatsapp_diseno_release.json').write_text(json.dumps(dict(release='whatsapp-diseno-institucional-v1',files=rows),ensure_ascii=False,indent=2),encoding='utf-8')
if __name__=='__main__':build()
