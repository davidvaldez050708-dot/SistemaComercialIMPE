"""Compara componentes protegidos y comprueba acceso sin usar BD ni proveedores."""
from pathlib import Path
import subprocess,sys,tempfile
root=Path(__file__).resolve().parents[1]
protected=['app/services/CorreoSalidaInstitucionalService.php','app/services/CorreoMarketingService.php',
 'app/services/CorreoCuentaConectadaService.php','app/services/CorreoSmtpTransport.php','app/services/CorreoOAuthTokenProvider.php',
 'app/services/CorreoEnvioControl.php','app/services/CorreoEstadoPrivado.php','app/services/MailService.php','app/services/FirmaCorreoService.php',
 'app/controllers/LoginController.php','app/models/UsuarioModel.php','app/views/auth/login.php','app/views/auth/recuperar_password.php',
 'config/mail_config.php','config/private_config.php','app/models/ConvocatoriaModel.php','app/models/CrearConvocatoriaModel.php',
 'app/controllers/ConvocatoriaController.php','app/controllers/CrearConvocatoriaController.php','index.php',
 'public/css/global.css','public/css/correos_marketing.css','public/javascript/selectores_uniformes.js','public/css/marketing_selectores_uniformes.css']
for f in protected:
    before=subprocess.check_output(['git','show','origin/main:'+f],cwd=root).replace(b'\r\n',b'\n')
    assert (root/f).read_bytes().replace(b'\r\n',b'\n')==before,'Archivo protegido modificado: '+f
for f in ['app/controllers/CorreoMarketingController.php','public/javascript/correos_marketing.js']:
    before=subprocess.check_output(['git','show','16518156:'+f],cwd=root).replace(b'\r\n',b'\n')
    assert (root/f).read_bytes().replace(b'\r\n',b'\n')==before,'Endpoint/envío/borradores modificado: '+f
old=lambda f:subprocess.check_output(['git','show','16518156:'+f],cwd=root).decode().replace('\r\n','\n')
now=lambda f:(root/f).read_text(encoding='utf-8')
f='app/services/CorreoConvocatoriaComposicion.php';a=old(f);b=now(f)
for begin,end in [('    public function imagen(', '    public function publico('),('    public function envio(', '    public function enviar($data)'),('        try { $result=$this->delegate->enviar($data); }','        return $result;')]:
    assert a[a.index(begin):a.index(end)]==b[b.index(begin):b.index(end)],'Imagen, persistencia, autorización o transporte alterados'
begin='    public function enviar($data)';end='        $link=CorreoConvocatoriaEnlace::obtener($this->selection);'
assert a[a.index(begin):a.index('        try { $result=',a.index(begin))]==b[b.index(begin):b.index(end)],'Preparación de imagen alterada'
f='public/javascript/correo_convocatorias.js';a=old(f);b=now(f)
for begin,end in [('    const coincide','    function iniciar('),('        const uniform =','        const clearImage ='),('        const list =','        const detail =')]:
    assert a[a.index(begin):a.index(end)]==b[b.index(begin):b.index(end)],'Filtros/selección/limpieza/borradores alterados'
f='app/models/CorreoConvocatoriaConsultaModel.php';a=old(f);b=now(f)
assert a[a.index('        // IDs proceden'):a.index('    public function buscar')]==b[b.index('        // IDs proceden'):b.index('    public function buscar')],'Consulta/relaciones de filtros alteradas'
with tempfile.TemporaryDirectory(prefix='impe-cv-acceso-') as tmp:
    p=Path(tmp)/'test.php'
    p.write_text('''<?php
$_SESSION=['usuario_id'=>7,'rol'=>'Analista'];
register_shutdown_function(static function(){echo "HTTP_STATUS=".http_response_code();});
require $argv[1].'/app/controllers/CorreoMarketingController.php';
(new CorreoMarketingController())->{$argv[2]}();
''',encoding='utf-8')
    for endpoint in ['convocatorias','imagenConvocatoria']:
        r=subprocess.run([sys.argv[1],str(p),str(root),endpoint],capture_output=True,text=True,timeout=10)
        assert r.returncode==0 and 'HTTP_STATUS=403' in r.stdout,'Acceso indebido: '+endpoint
print('OK: '+str(len(protected))+' archivos protegidos idénticos a main; catálogo/imagen rechazan perfil sin permiso. Sin BD, SMTP, restablecimientos ni red.')
