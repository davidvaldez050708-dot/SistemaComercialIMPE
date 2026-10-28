<?php
// Adaptador SQL sólo para pruebas aisladas.
class ComentarioResultadoPrueba {
    private $filas;
    public function __construct($filas){$this->filas=$filas;}
    public function fetch_assoc(){return array_shift($this->filas)?:null;}
    public function fetch_all($tipo){return $this->filas;}
}
class ComentarioSentenciaPrueba {
    private $stmt,$db,$args=[];public $affected_rows=0;
    public function __construct($db,$sql){$this->db=$db;$this->stmt=$db->pdo->prepare($sql);}
    public function bind_param($types,&...$args){if(strlen($types)!==count($args))throw new RuntimeException('Tipos SQL incorrectos');$this->args=&$args;}
    public function execute(){$this->stmt->execute($this->args);$this->affected_rows=$this->stmt->rowCount();$this->db->insert_id=(int)$this->db->pdo->lastInsertId();return true;}
    public function get_result(){return new ComentarioResultadoPrueba($this->stmt->fetchAll(PDO::FETCH_ASSOC));}
}
class ComentarioDbPrueba {
    public $pdo,$insert_id=0,$queries=[];
    public function __construct($path=':memory:', $init=true){
        $this->pdo=new PDO('sqlite:'.$path);$this->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $this->pdo->sqliteCreateFunction('CONCAT',static fn(...$s)=>implode('',$s));
        $this->pdo->sqliteCreateFunction('IF',static fn($c,$a,$b)=>$c?$a:$b);
        if(!$init)return;
        $this->pdo->exec("CREATE TABLE usuarios(id INTEGER PRIMARY KEY,nombre TEXT,apellidos TEXT)");
        $this->pdo->exec("INSERT INTO usuarios VALUES(5,'Analista','de Prueba'),(7,'Cuenta','Clave'),(8,'Consulta','Agenda'),(9,'Sin','Agenda')");
        $this->pdo->exec('CREATE TABLE agenda_comentarios(id INTEGER PRIMARY KEY AUTOINCREMENT,fecha TEXT,recordatorio_at TEXT,comentario TEXT,creado_por INTEGER,autor_nombre TEXT,creado_at TEXT,eliminado_at TEXT,eliminado_por INTEGER)');
        $this->pdo->exec('CREATE TABLE agenda_comentarios_lecturas(comentario_id INTEGER,usuario_id INTEGER,novedad_leida_at TEXT,recordatorio_atendido_at TEXT,PRIMARY KEY(comentario_id,usuario_id))');
    }
    public function query($sql){$this->queries[]=$sql;return $this->pdo->query($sql);}
    public function prepare($sql){
        $this->queries[]=$sql;
        $key=strpos($sql,'INSERT INTO agenda_comentarios_vistos_avisos')!==false?'comentario_id,lector_id':'comentario_id,usuario_id';
        $sql=str_replace('ON DUPLICATE KEY UPDATE','ON CONFLICT('.$key.') DO UPDATE SET',$sql);
        $sql=preg_replace('/VALUES\((\w+)\)/','excluded.$1',$sql);
        return new ComentarioSentenciaPrueba($this,$sql);
    }
}
