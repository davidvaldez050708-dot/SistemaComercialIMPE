"""El diagnóstico usa el repositorio real con conexión sintética que prohíbe escrituras."""
import os
from pathlib import Path
import shutil
import subprocess
import tempfile

ROOT=Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='agenda-probe-',dir=ROOT/'tmp') as tmp:
    area=Path(tmp)
    for file in ['app/services/AgendaReunionRepository.php','app/services/AgendaReunionService.php',
                 'app/controllers/AgendaReunionController.php','tools/diagnosticar_confirmacion_reunion.php']:
        p=area/file;p.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(ROOT/file,p)
    (area/'config').mkdir()
    (area/'config/db_connection.php').write_text('''<?php
class ProbeResultado {
 public function __construct(private $rows){}public function fetch_assoc(){return array_shift($this->rows)?:null;}
 public function fetch_all($type){return $this->rows;}
}
function probeFila(){return ['id'=>8,'seguimiento_id'=>12,'analista_id'=>5,'cuenta_clave_id'=>7,'fecha_propuesta'=>'2026-10-29 10:00:00','duracion_minutos'=>60];}
class ProbeStmt {
 public function __construct(private $sql){}
 public function bind_param($types,&...$args){if(strlen($types)!==count($args)||substr_count($this->sql,'?')!==count($args))throw new ArgumentCountError('bind incorrecto');return true;}
 public function execute(){if(!str_starts_with(trim($this->sql),'SELECT'))throw new RuntimeException('INTENTO DE ESCRITURA');return true;}
 public function get_result(){return new ProbeResultado(str_contains($this->sql,'SELECT r.*')?[probeFila()]:[]);}
}
class ProbeDb {
 public function prepare($sql){if(getenv('PROBE_ERROR')&&str_contains($sql,"estado='CONFIRMADA'"))throw new RuntimeException('SECRETO no imprimible',1054);return new ProbeStmt($sql);}
 public function query($sql){
  if(str_starts_with($sql,'SELECT'))return new ProbeResultado([probeFila()]);
  if(str_starts_with($sql,'SHOW COLUMNS'))return new ProbeResultado([['Field'=>'canal','Type'=>"enum('SISTEMA','NOTA')"],['Field'=>'resultado','Type'=>"enum('OTRO')"]]);
  throw new RuntimeException('INTENTO DE ESCRITURA');
 }
}
class Database {public function connect($persistent=false){return new ProbeDb();}}
''',encoding='utf8')
    for error in [False,True]:
        env=dict(os.environ,IMPE_CORREOS_PUBLIC=str(area),PROBE_ERROR='1' if error else '')
        result=subprocess.run(['C:/xampp/php/php.exe',str(area/'tools/diagnosticar_confirmacion_reunion.php'),'--check'],env=env,capture_output=True,text=True,encoding='utf8')
        assert (result.returncode==0)==(not error),(result.stdout,result.stderr)
        assert 'INTENTO DE ESCRITURA' not in result.stdout+result.stderr and 'SECRETO' not in result.stdout+result.stderr
        assert ('preparar_confirmacion=ERROR' if error else 'preparar_confirmacion=OK') in result.stdout
        assert 'preparar_interaccion=OK' in result.stdout
print('OK: diagnóstico con SQL del repositorio real; nunca ejecuta escrituras y comunica fase/código sin mensajes privados.')
