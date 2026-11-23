import difflib,hashlib,json,subprocess
from pathlib import Path
from publicar_rendimiento_inicio import FILES
root=Path(__file__).resolve().parents[1]
def before(name):return subprocess.check_output(['git','show','a4e7ac7:'+name],cwd=str(root)).replace(b'\r\n',b'\n')
def build():
 rows=[]
 for name in FILES:
  old=before(name).decode();new=(root/name).read_text(encoding='utf-8');hunks=[]
  if name=='app/models/RolModel.php':
   anchor='        $sql = "INSERT INTO permisos (\n'
   start=new.index('        // El catálogo es común');end=new.index(anchor,start)
   hunks.append(dict(before=anchor,after=new[start:end]+anchor))
   a='                ) VALUES (?, ?, ?, ?, 1)\n                ON DUPLICATE KEY UPDATE\n                    modulo = VALUES(modulo),'
   b='                ) VALUES " . implode(\', \', $filasCatalogo) . "\n                ON DUPLICATE KEY UPDATE\n                    modulo = VALUES(modulo),'
   hunks.append(dict(before=a,after=b))
   start=old.index('        foreach ($permisos as $permiso) {',old.index(anchor));end=old.index('        $sinPermisosActivosSistema',start)
   nstart=new.index('        $tiposCatalogo =',new.index(anchor));nend=new.index('        $sinPermisosActivosSistema',nstart)
   hunks.append(dict(before=old[start:end],after=new[nstart:nend]))
  else:
   a,b=old.splitlines(True),new.splitlines(True)
   for group in difflib.SequenceMatcher(None,a,b,autojunk=False).get_grouped_opcodes(3):
    f,l=group[0],group[-1];hunks.append(dict(before=''.join(a[f[1]:l[2]]),after=''.join(b[f[3]:l[4]])))
  rows.append(dict(path=name,new_file=False,after_sha=hashlib.sha256(new.encode()).hexdigest(),hunks=hunks))
 (root/'tools/rendimiento_inicio_release.json').write_text(json.dumps(dict(release='rendimiento-inicio-v1',files=rows),ensure_ascii=False,indent=2),encoding='utf-8')
if __name__=='__main__':build()
