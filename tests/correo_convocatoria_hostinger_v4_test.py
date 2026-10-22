"""Contrato real instalado v4 + compositor actual; API/BD simuladas, sin credenciales reales."""
from pathlib import Path
import os,subprocess,sys,tempfile
root=Path(__file__).resolve().parents[1]
php=sys.argv[1] if len(sys.argv)>1 else 'C:/xampp/php/php.exe'
legacy='--legacy' in sys.argv
files=['app/services/'+name+'.php' for name in ['CorreoMarketingService','CorreoSalidaInstitucionalService',
 'CorreoCuentaConectadaService','CorreoHostingerApiService','CorreoEnvioControl','CorreoEstadoPrivado','FirmaCorreoService']]
files+=['config/private_config.php','tests/correos_marketing_test.php']
with tempfile.TemporaryDirectory(prefix='impe-convocatoria-v4-') as tmp:
    base=Path(tmp);stage=base/'public_html';stage.mkdir()
    for f in files:
        target=stage/f;target.parent.mkdir(parents=True,exist_ok=True)
        target.write_bytes(subprocess.check_output(['git','show','1375a90a:'+f],cwd=root))
    for f in ['app/services/CorreoConvocatoriaComposicion.php','app/models/CorreoConvocatoriaConsultaModel.php']:
        target=stage/f;target.parent.mkdir(parents=True,exist_ok=True)
        target.write_bytes(subprocess.check_output(['git','show','57b8e062:'+f],cwd=root) if legacy else (root/f).read_bytes())
    (stage/'app/services/CorreoConvocatoriaEnlace.php').write_bytes((root/'app/services/CorreoConvocatoriaEnlace.php').read_bytes())
    (stage/'tools').mkdir()
    (stage/'tools/diagnosticar_envio_convocatoria.php').write_bytes((root/'tools/diagnosticar_envio_convocatoria.php').read_bytes())
    (stage/'config/db_connection.php').write_text('<?php class Database { public function connect(){throw new LogicException("BD real prohibida");}}',encoding='utf-8')
    (stage/'app/models/UsuarioModel.php').write_text('<?php class UsuarioModel {}',encoding='utf-8')
    env=os.environ.copy();env['IMPE_PRIVATE_CONFIG_DIR']=str(base/'private');env['IMPE_MAIL_STATE_DIR']=str(base/'state')
    env['HOSTINGER_MAIL_API_TOKEN']='TOKEN_FICTICIO_AISLADO'
    (base/'private').mkdir()
    fixture=root/'tests/correo_convocatoria_hostinger_v4_fixture.php'
    result=subprocess.run([php,str(fixture),str(stage),'legacy' if legacy else 'fixed'],cwd=base,env=env,capture_output=True,text=True,timeout=30)
    print(result.stdout,end='');print(result.stderr,end='',file=sys.stderr)
    assert result.returncode==0,'Fallo de prueba v4 aislada'
    assert not any(s in result.stderr for s in ['TOKEN_FICTICIO_AISLADO','@','Texto completo',str(stage)]),'Traza insegura'
    diagnostic=subprocess.run([php,str(stage/'tools/diagnosticar_envio_convocatoria.php'),'--check'],cwd=base,env=env,capture_output=True,text=True,timeout=10)
    assert diagnostic.returncode==(2 if legacy else 0),diagnostic.stdout+diagnostic.stderr
    assert ('autorizacion_delegada' in diagnostic.stdout) if not legacy else ('fase=prepararSalida clase=Error' in diagnostic.stderr)
    print('OK: diagnóstico CLI identifica delegación reparada o fase exacta del fallo anterior, sin API ni secretos.')
