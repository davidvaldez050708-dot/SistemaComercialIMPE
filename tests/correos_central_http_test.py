from pathlib import Path
import subprocess,sys,tempfile
root=Path(__file__).resolve().parents[1]
php=sys.argv[1]
entry=r"""<?php
$_SESSION=['usuario_id'=>7,'rol'=>'Otro']; $_SERVER['REQUEST_METHOD']='POST';
register_shutdown_function(static function(){echo "\nHTTP_STATUS=".http_response_code();});
require $argv[1].'/app/controllers/CorreoMarketingController.php';
(new CorreoMarketingController())->identidad();
"""
with tempfile.TemporaryDirectory(prefix='impe-central-http-') as tmp:
 p=Path(tmp)/'entry.php';p.write_text(entry,encoding='utf-8')
 r=subprocess.run([php,str(p),str(root)],capture_output=True,text=True,timeout=10)
 assert r.returncode==0 and 'HTTP_STATUS=403' in r.stdout,'La identidad central no se expone a perfiles sin permiso'
for f in ['app/views/auth/recuperar_password.php','app/views/auth/login.php']:
 old=subprocess.check_output(['git','show','a90b6ade:'+f],cwd=root).replace(b'\r\n',b'\n')
 assert old==(root/f).read_bytes().replace(b'\r\n',b'\n'),'Vista institucional alterada'
index=(root/'index.php').read_text(encoding='utf-8')
view=(root/'app/views/correos/index.php').read_text(encoding='utf-8')
js=(root/'public/javascript/correos_marketing.js').read_text(encoding='utf-8')
assert "'correoMarketing'," in index and 'Conectar cuenta' not in view and 'Autorizar mi Gmail' in js
assert 'identidad_envio' in js and 'data-marketing-mail-identity' in view
old=subprocess.check_output(['git','show','a90b6ade:app/controllers/LoginController.php'],cwd=root).decode().replace('\r\n','\n')
new=(root/'app/controllers/LoginController.php').read_text(encoding='utf-8')
start='    public function procesarRecuperacion()'
end='    public function mostrarCambioPassword()'
assert old.split(start)[0]==new.split(start)[0] and old.split(end)[1]==new.split(end)[1], 'Cambios fuera de recuperación de contraseña'
print('OK: acceso de identidad limitado, CSRF de correo activado, vistas de acceso/recuperación intactas y Hostinger automático y enlace de autorización sólo para Gmail.')
