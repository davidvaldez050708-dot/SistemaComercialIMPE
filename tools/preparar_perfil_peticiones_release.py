import base64,hashlib,json,subprocess
from pathlib import Path
root=Path(__file__).resolve().parents[1]
def build():
 helper=(root/'app/helpers/PerfilPeticion.php').read_bytes().replace(b'\r\n',b'\n')
 old=subprocess.check_output(['git','show','c60d99a:index.php'],cwd=str(root)).decode().replace('\r\n','\n')
 new=(root/'index.php').read_text(encoding='utf-8')
 pairs=[
 ('session_start();\n',"require_once __DIR__ . '/app/helpers/PerfilPeticion.php';\n$impePerfilPeticion = ImpePerfilPeticion::iniciar(__DIR__);\nsession_start();\n$impePerfilPeticion?->marca('sesion');\n"),
 ("require_once __DIR__ . '/app/helpers/PermissionHelper.php';\n","require_once __DIR__ . '/app/helpers/PermissionHelper.php';\n$impePerfilPeticion?->marca('config');\n"),
 ('switch ($controller) {',"$impePerfilPeticion?->marca('autorizacion');\nswitch ($controller) {"),
 ('if (!method_exists($controllerInstance, $action)) {',"$impePerfilPeticion?->marca('controlador');\nif (!method_exists($controllerInstance, $action)) {"),
 ('$controllerInstance->$action();',"$impePerfilPeticion?->marca('despacho');\n$controllerInstance->$action();")]
 test=old
 for a,b in pairs:assert test.count(a)==1;test=test.replace(a,b,1)
 assert test==new
 spec=dict(release='perfil-peticiones-v1',files=[
  dict(path='app/helpers/PerfilPeticion.php',new_file=True,after_sha=hashlib.sha256(helper).hexdigest(),content=base64.b64encode(helper).decode()),
  dict(path='index.php',new_file=False,after_sha=hashlib.sha256(new.encode()).hexdigest(),hunks=[dict(before=a,after=b) for a,b in pairs])])
 (root/'tools/perfil_peticiones_release.json').write_text(json.dumps(spec,ensure_ascii=False,indent=2),encoding='utf-8')
if __name__=='__main__':build()
