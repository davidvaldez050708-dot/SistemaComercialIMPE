from pathlib import Path
import tempfile,shutil,subprocess
root=Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='impe-aliados-schema-') as td:
 d=Path(td);(d/'tools').mkdir();(d/'config').mkdir();shutil.copyfile(root/'tools/configurar_avisos_aliados.php',d/'tools/configurar_avisos_aliados.php')
 (d/'config/db_connection.php').write_text(r'''<?php
class Resultado {private $s;function __construct($s){$this->s=$s;}function fetch_all($mode){return $this->s->fetchAll(PDO::FETCH_ASSOC);}}
class Database {function connect($mode=false){return new Conexion();}}
class Conexion {private $p;function __construct(){$this->p=new PDO('sqlite:'.__DIR__.'/../fixture.sqlite');$this->p->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);}
function query($sql){if(str_starts_with($sql,'SHOW INDEX')){$rows=$this->p->query('PRAGMA table_info(aliados_novedades_lecturas)')->fetchAll(PDO::FETCH_ASSOC);$result=[];foreach($rows as $row){if($row['pk'])$result[]=['Seq_in_index'=>$row['pk'],'Column_name'=>$row['name']];}return new class($result){private $r;function __construct($r){$this->r=$r;}function fetch_all($m){return $this->r;}};}
$sql=preg_replace('/,\s*KEY idx_aliado_novedad_usuario \(usuario_id\)/','',$sql);$sql=preg_replace('/\) ENGINE=InnoDB.*$/s',')',$sql);return new Resultado($this->p->query($sql));}}
''',encoding='utf-8')
 def run(mode,ok=True):
  r=subprocess.run(['C:/xampp/php/php.exe',str(d/'tools/configurar_avisos_aliados.php'),mode],capture_output=True,text=True)
  assert (r.returncode==0)==ok,r.stdout+r.stderr
 run('--check',False);run('--apply');run('--check');run('--apply')
 assert (d/'fixture.sqlite').exists()
 print('OK: instalador aditivo de lecturas, falta de tabla detectada, esquema real sobre SQL en memoria/archivo aislado, apply/check e idempotencia. Sin credenciales ni datos del CRM.')
