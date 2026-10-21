<?php
// Sólo se copia a la réplica aislada de la prueba HTTP. Nunca se publica.
final class OficiosFixtureData {
    public static function read() { return json_decode(file_get_contents(getenv('IMPE_OFICIOS_HTTP_STATE')),true); }
    public static function write($state) { file_put_contents(getenv('IMPE_OFICIOS_HTTP_STATE'),json_encode($state),LOCK_EX); }
    public static function row($id,$user) {
        $state=self::read(); $row=$state['rows'][$id]??null;
        return $row && (int)$row['analista_id']===(int)$user ? $row : null;
    }
}
class Database { public function connect() { return new OficiosFixtureConnection(); } }
final class OficiosFixtureConnection {
    public function prepare($sql) { return new OficiosFixtureStatement($sql); }
}
final class OficiosFixtureStatement {
    private $sql; private $args=[]; private $row=null;
    public function __construct($sql) { $this->sql=preg_replace('/\s+/',' ',trim($sql)); }
    public function bind_param($types,&...$args) { $this->args=&$args; }
    public function execute() {
        $state=OficiosFixtureData::read(); $sql=$this->sql; $args=$this->args;
        if (strpos($sql,'SELECT DISTINCT')===0) {
            $this->row=OficiosFixtureData::row($args[0],$args[1]); return true;
        }
        if (strpos($sql,'FROM plantillas_vinculacion')!==false) {
            $this->row=strpos($sql,'WHERE id = ?')!==false ? ($state['templates'][$args[0]]??null) : $state['templates']['11'];
            if (strpos($sql,'archivo_docx IS NOT NULL')!==false && empty($this->row['archivo_docx'])) { $this->row=null; }
            return true;
        }
        if (strpos($sql,'SELECT id, archivo_pdf')===0) {
            foreach ($state['rows'] as $row) { if ($row['oficio_id']==$args[0]) { $this->row=$row; break; } }
            return true;
        }
        if (strpos($sql,'UPDATE oficios_vinculacion SET plantilla_oficio_id')===0) {
            // La réplica ya tiene asignada la plantilla; el WHERE IS NULL no actualiza.
            return true;
        }
        if (strpos($sql,'UPDATE oficios_vinculacion SET archivo_pdf')===0) {
            if (!empty($state['fail_update'])) { return false; }
            foreach ($state['rows'] as &$row) {
                if ($row['oficio_id']==$args[1]) {
                    $row['archivo_pdf']=$args[0]; $row['estado_oficio']='GENERADO';
                    $row['fecha_generacion']='2026-10-21 12:00:00'; $state['updates']++;
                }
            }
            unset($row); OficiosFixtureData::write($state); return true;
        }
        throw new RuntimeException('UNEXPECTED_FIXTURE_QUERY');
    }
    public function get_result() { return $this; }
    public function fetch_assoc() { return $this->row; }
}
