import difflib,json,subprocess
from pathlib import Path
root=Path(__file__).resolve().parents[1]
name='app/services/TelefoniaMarcacionesVentasService.php'
old=subprocess.check_output(['git','show','9c3846c:'+name],cwd=str(root)).decode('utf-8').replace('\r\n','\n')
new=(root/name).read_text(encoding='utf-8')
a,b=old.splitlines(True),new.splitlines(True)
hunks=[]
for group in difflib.SequenceMatcher(None,a,b,autojunk=False).get_grouped_opcodes(2):
    i1,i2=group[0][1],group[-1][2];j1,j2=group[0][3],group[-1][4]
    hunks.append(dict(before=''.join(a[i1:i2]),after=''.join(b[j1:j2])))
assert len(hunks)==3
spec=dict(release='zona-marcaciones-v1',files=[dict(path=name,new_file=False,hunks=hunks)])
(root/'tools/zona_marcaciones_release.json').write_text(json.dumps(spec,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
publisher=(root/'tools/publicar_consultas_repetidas.py').read_text(encoding='utf-8')
publisher=publisher.replace("FILES=('app/services/TelefoniaVentasLlamadasService.php', 'app/controllers/TelefoniaController.php', 'app/controllers/ReminderController.php')",'FILES='+repr((name,)))
publisher=publisher.replace('consultas_repetidas_release.json','zona_marcaciones_release.json').replace("prefix='consultas-repetidas-" ,"prefix='zona-marcaciones-")
publisher=publisher.replace('"""Publicación selectiva de consultas por conjuntos y liberación de sesión."""','"""Una consulta de zona horaria por revisión; conserva las validaciones PBX instaladas."""')
(root/'tools/publicar_zona_marcaciones.py').write_text(publisher,encoding='utf-8')
print('Paquete selectivo de zona horaria generado: un archivo, tres bloques.')
