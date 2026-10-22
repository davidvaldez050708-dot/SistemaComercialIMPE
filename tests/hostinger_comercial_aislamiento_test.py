"""Comparación dirigida de recuperación y módulos conservados; no ejecuta recuperaciones."""
from pathlib import Path
import subprocess,sys,tempfile
root=Path(__file__).resolve().parents[1]
protected=['app/controllers/LoginController.php','app/models/UsuarioModel.php','app/services/MailService.php',
 'app/services/CorreoSmtpTransport.php','app/services/CorreoEstadoPrivado.php','app/services/CorreoEnvioControl.php',
 'app/services/CorreoOAuthTokenProvider.php','config/mail_config.php','config/private_config.php',
 'app/views/auth/login.php','app/views/auth/recuperar_password.php',
 'app/services/SeguimientoCorreoService.php','app/services/OficioCorreoService.php','app/services/CorreoFirmadoService.php']
for f in protected:
 old=subprocess.check_output(['git','show','origin/main:'+f],cwd=root).replace(b'\r\n',b'\n')
 assert (root/f).read_bytes().replace(b'\r\n',b'\n')==old,'Archivo protegido modificado: '+f
index=(root/'index.php').read_text(encoding='utf-8')
assert "'correoMarketing'," in index and "case 'correoConexion':" in index
js=(root/'public/javascript/correos_marketing.js').read_text(encoding='utf-8')
assert 'correoConexion' not in js and 'action=remitentes' in js
entry=r'''<?php
$_SESSION=['usuario_id'=>7,'rol'=>'Otro'];$_SERVER['REQUEST_METHOD']='GET';
register_shutdown_function(static function(){echo "HTTP_STATUS=".http_response_code();});
require $argv[1].'/app/controllers/CorreoMarketingController.php';
(new CorreoMarketingController())->remitentes();
'''
with tempfile.TemporaryDirectory(prefix='impe-commercial-http-') as tmp:
 p=Path(tmp)/'test.php';p.write_text(entry,encoding='utf-8')
 r=subprocess.run([sys.argv[1],str(p),str(root)],capture_output=True,text=True,timeout=10)
 assert r.returncode==0 and 'HTTP_STATUS=403' in r.stdout,'Lista de buzones expuesta a perfil sin permiso'
print('OK: recuperación, login, SMTP y componentes compartidos idénticos a main; módulos conservados; endpoint comercial protegido. Sin contraseñas, red ni BD.')
