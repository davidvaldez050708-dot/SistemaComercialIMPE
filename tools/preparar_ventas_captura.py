"""Empaqueta la captura independiente y la variante completa de Hostinger."""
import base64,gzip,hashlib,json,subprocess,sys
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
BASE='e047deea5835fdfa7652dcdedb176183bddc85f1'
FILES={'app/views/telefonia/shell.php':'a85ddc113ce24baf28d31e300338634ac55db8bce035add346a9ee2be8382ccc',
 'public/javascript/telefonia_enrutamiento.js':'721c0b519ee161b14117c25114ddd42a45d6ae4f53cd5848f6470f60cb3efbaa',
 'public/javascript/telefonia_ventas_prospecto.js':'b64f756982658f08eb4a73a1d1f2a8054c2c0a403ebaff788bcd20bc7701b1b0'}
def blob(ref,p):return subprocess.check_output(['git','show',ref+':'+p],cwd=ROOT).decode('utf8').replace('\r\n','\n')
def sha(b):return hashlib.sha256(b).hexdigest()
def packed(b):return base64.b64encode(gzip.compress(b,mtime=0)).decode('ascii')
def custom_shell(s):
    old="""        Activa JavaScript para usar telefonía integrada o
        <a href="<?= htmlspecialchars(BASE_URL . 'index.php?controller=home&action=index', ENT_QUOTES, 'UTF-8') ?>">
            entra al CRM clásico
        </a>."""
    new='        Activa JavaScript en tu navegador para utilizar el CRM y su teléfono integrado.'
    classic="""        <a class="impe-phone-classic-link"
           href="<?= htmlspecialchars(BASE_URL . 'index.php?controller=home&action=index', ENT_QUOTES, 'UTF-8') ?>"
           title="Volver al CRM sin navegación persistente">Modo clásico</a>
"""
    assert s.count(old)==1 and s.count(classic)==1
    return s.replace(old,new).replace(classic,'')
def main():
    target=sys.argv[1];rows=[]
    for path,expected in FILES.items():
        before=blob(BASE,path);after=blob(target,path);pairs=[(before,after)]
        installed=custom_shell(before) if path.endswith('/shell.php') else before
        assert sha(installed.encode())==expected,(path,sha(installed.encode()))
        if installed!=before:pairs.append((installed,custom_shell(after)))
        rows.append({'path':path,'verified_hostinger_sha':expected,'variants':[
            {'before':packed(a.encode()),'before_sha':sha(a.encode()),
             'after':packed(b.encode()),'after_sha':sha(b.encode())} for a,b in pairs]})
    (ROOT/'tools/ventas_captura_release.json').write_text(json.dumps(
        {'version':1,'base':BASE,'target':target,'files':rows},ensure_ascii=False,indent=2)+'\n',encoding='utf8')
    print('Paquete: tres archivos. Personalización del shell conservada; sin SQL.')
if __name__=='__main__':main()
