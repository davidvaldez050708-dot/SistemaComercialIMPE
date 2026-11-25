"""Controlador instalado/candidato y constructores reales; datos sintéticos, sin red."""
import json,re,shutil,subprocess,tempfile
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
BASE=ROOT/'tests/fixtures/conexion_convocatorias_20261125'
PHP=shutil.which('php') or 'C:/xampp/php/php.exe'
DEPS=json.loads((BASE/'dependencias.json').read_text(encoding='utf-8'))

def ctor(s):
    start=s.index('    public function __construct(');i=s.index('{',start)+1;depth=1
    while depth:
        if s[i]=='{':depth+=1
        elif s[i]=='}':depth-=1
        i+=1
    return s[start:i]

def run(after,case):
    folder=ROOT if after else BASE
    controller=(folder/'app/controllers/ConvocatoriaNotificacionController.php').read_text(encoding='utf-8')
    model=(folder/'app/models/ConvocatoriaNotificacionModel.php').read_text(encoding='utf-8')
    code='''<?php
error_reporting(E_ALL & ~E_DEPRECATED);
class SinRed extends mysqli{public function __construct(){}public function query(string $q,int $m=MYSQLI_STORE_RESULT):mysqli_result|bool{return true;}}
class Database{public static $opens=0;private static $shared;public function connect(){self::$opens++;return new SinRed();}public static function compartida(){return self::$shared??=(new self())->connect();}}
class Estado{public static $handles=[];public static $sync=0;public static function ver($db){self::$handles[spl_object_id($db)]=true;}}
function tienePermiso($p){return in_array($p,$GLOBALS['permisos'],true);}
'''
    code+='require '+repr(str(ROOT/'app/helpers/ControlCargaMysql.php').replace('\\','/'))+';\n'
    methods={
      'ConvocatoriaModel':"public function sincronizarConvocatoriasPorFecha(){Estado::ver($this->connection);Estado::$sync++;}",
      'ConvocatoriaNotificacionModel':"public function contarNoLeidas($u){Estado::ver($this->connection);return 3;}public function obtenerPorUsuario($u,$l){return [['id'=>1,'titulo'=>'Convocatoria sintética','leida'=>0]];}public function marcarLeida($i,$u){return true;}public function marcarTodasLeidas($u){return true;}",
      'AgendaComentarioRepository':"public function conexion(){Estado::ver($this->db);return $this->db;}",
      'AgendaComentarioService':"public function notificaciones($u){Estado::ver($this->repo->conexion());return ['recordatorios'=>[['id'=>0,'agenda_comentario_id'=>9,'nombre_entidad'=>'Agenda','accion'=>'Revisar','fecha'=>'2026-11-25 09:00:00']],'avisos'=>[],'horarios'=>[]];}public function registrarEntregados($u,$r){}",
      'TelefoniaNotificacionService':"public function pendientes($u,$r){Estado::ver($this->db);if(!empty($GLOBALS['phone_error']))throw new RuntimeException('Fallo sintético');return [['id'=>10,'leida'=>1,'pendiente_operativo'=>true]];}",
      'AliadoConvocatoriaPendienteService':"public function obtener($u,$marketing,$l){Estado::ver($this->db);return [['id'=>0,'tipo_evento'=>'tarea','titulo'=>'Preparar convocatoria']];}"
    }
    for name in ['ConvocatoriaModel','ConvocatoriaNotificacionModel','AgendaComentarioRepository','AgendaComentarioService','TelefoniaNotificacionService','AliadoConvocatoriaPendienteService']:
        body=ctor(model) if name=='ConvocatoriaNotificacionModel' else DEPS[name]
        code+='#[AllowDynamicProperties] class '+name+'{'+body+methods[name]+'}\n'
    adapter=(BASE/'app/services/AgendaMarketingNotificationService.php').read_text(encoding='utf-8')
    for source in [adapter,controller]:
        code+=re.sub(r'require_once\s+[^;]+;','',source).removeprefix('<?php')+'\n'
    perms=['convocatorias.gestionar','convocatorias.crear','telefonia.usar','telefonia.recibir']
    if case=='sin_telefono':perms.remove('telefonia.recibir')
    if case=='sin_crear':perms.remove('convocatorias.crear')
    if case=='sin_permiso':perms.remove('convocatorias.gestionar')
    code+='$GLOBALS["permisos"]='+repr(perms).replace("'",'"')+';'
    role='Analista' if case=='otro_rol' else 'Marketing'
    code+='$_SESSION=["usuario_id"=>'+('0' if case=='sin_sesion' else '50')+',"rol"=>"'+role+'","csrf_token"=>"prueba"];'
    code+='$GLOBALS["phone_error"]='+('true' if case=='fallo_telefono' else 'false')+';'
    code+='register_shutdown_function(function(){fwrite(STDERR,"METRICAS=".json_encode(["http"=>http_response_code()?:200,"opens"=>Database::$opens,"handles"=>count(Estado::$handles),"sync"=>Estado::$sync]));});'
    code+='Database::compartida();(new ConvocatoriaNotificacionController())->pendientes();'
    with tempfile.TemporaryDirectory() as tmp:
        path=Path(tmp)/'test.php';path.write_text(code,encoding='utf-8')
        p=subprocess.run([PHP,str(path)],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        assert p.returncode==0,(p.stdout,p.stderr)
        data=json.loads(p.stdout);data.pop('servidor_at',None)
        metrics=json.loads(p.stderr.decode('utf-8').split('METRICAS=')[-1])
        return data,metrics

for case in ['normal','sin_telefono','sin_crear','fallo_telefono','sin_permiso','otro_rol','sin_sesion']:
    before,bm=run(False,case);after,am=run(True,case)
    assert before==after,(case,before,after)
    assert bm['http']==am['http'] and bm['sync']==am['sync'],(case,bm,am)
    if am['http']==200:
        assert am['opens']==1 and am['handles']==1,(case,am)
        assert bm['opens']>am['opens'],(case,bm,am)
    else:assert am['http']==403 and am['sync']==0 and am['handles']==0
    print(case+': JSON/HTTP y sincronizacion conservados; aperturas '+str(bm['opens'])+' -> '+str(am['opens']))
print('OK: campana de Marketing, permisos y fallo parcial comprobados; sin MySQL ni llamadas reales.')
