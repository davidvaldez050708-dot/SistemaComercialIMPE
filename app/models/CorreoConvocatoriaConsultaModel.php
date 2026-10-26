<?php
require_once dirname(__DIR__,2).'/config/db_connection.php';

/** Consultas exclusivas de Correos. No crea tablas ni cambia convocatorias/convenios. */
class CorreoConvocatoriaConsultaModel
{
    private $db;
    private $relacionesDisponibles;
    private $tablas=[];
    public function __construct($db=null) { $this->db=$db; }
    private function filas(string $sql,array $params=[]): array
    {
        $this->db??=(new Database())->connect();
        if($params) {
            $stmt=$this->db->prepare($sql);$types=str_repeat('i',count($params));
            $stmt->bind_param($types,...$params);$stmt->execute();$result=$stmt->get_result();
        } else { $result=$this->db->query($sql); }
        if(!$result) { throw new RuntimeException('CONVOCATORIAS_CONSULTA'); }
        $rows=[];while($row=$result->fetch_assoc()) { $rows[]=$row; }return $rows;
    }
    private function tieneRelaciones(): bool
    {
        if($this->relacionesDisponibles===null) {
            $rows=$this->filas("SELECT COUNT(*) AS total FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'marketing_convenio_convocatorias'");
            $this->relacionesDisponibles=(int)($rows[0]['total']??0)>0;
        }
        return $this->relacionesDisponibles;
    }
    private function tabla(string $nombre): bool
    {
        // Nombres internos constantes, nunca procedentes de la petición.
        return $this->tablas[$nombre]??= (int)($this->filas("SELECT COUNT(*) AS total FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '$nombre'")[0]['total']??0)>0;
    }
    public static function convenioId($value)
    {
        if(is_string($value) && preg_match('/^manual:([1-9][0-9]{0,9})$/D',$value,$m) && (float)$m[1]<=2147483647) { return $value; }
        if($value===null || $value==='' || $value===0 || $value==='0') { return 0; }
        if((!is_string($value) && !is_int($value)) || !preg_match('/^[1-9][0-9]{0,9}$/D',(string)$value) || (float)$value>2147483647) { throw new RuntimeException('CONVOCATORIA_ID'); }
        return (int)$value;
    }
    private function completar(array $rows): array
    {
        if(!$rows) { return []; }
        $items=[];
        foreach($rows as $r) {
            $id=(int)$r['id'];$items[$id]=['id'=>$id,'titulo'=>(string)$r['titulo'],'activa'=>(int)$r['estado']===1,
                'imagen_ref'=>(string)($r['imagen']??''),'estados'=>[],'convenios'=>[]];
            if(array_key_exists('enlace_registro',$r)) { $items[$id]['enlace_ref']=(string)($r['enlace_registro']??''); }
        }
        // IDs proceden exclusivamente del resultado SQL; no se interpola entrada del usuario.
        $ids=implode(',',array_keys($items));
        foreach($this->filas("SELECT ce.convocatoria_id, e.id, e.nombre FROM convocatoria_estados ce INNER JOIN estados e ON e.id = ce.estado_id WHERE ce.convocatoria_id IN ($ids) ORDER BY e.nombre, e.id") as $r) {
            $items[(int)$r['convocatoria_id']]['estados'][]=['id'=>(int)$r['id'],'nombre'=>(string)$r['nombre']];
        }
        if($this->tieneRelaciones()) {
            foreach($this->filas("SELECT DISTINCT mc.convocatoria_id, s.id, s.nombre_entidad AS nombre, COALESCE(p.convenio_referencia, '') AS referencia
                FROM marketing_convenio_convocatorias mc INNER JOIN seguimientos_vinculacion s ON s.id = mc.seguimiento_id
                LEFT JOIN seguimientos_vinculacion_post_envio p ON p.seguimiento_id = s.id
                WHERE mc.convocatoria_id IN ($ids) ORDER BY s.nombre_entidad, s.id") as $r) {
                $items[(int)$r['convocatoria_id']]['convenios'][]=['id'=>(int)$r['id'],'nombre'=>(string)$r['nombre'],'referencia'=>(string)$r['referencia']];
            }
        }
        // Relaciones del módulo Convenios: ID real + origen; jamás por nombre.
        $sources=[];
        if($this->tabla('marketing_aliado_convocatorias_origen')) {
            $sources[]="SELECT DISTINCT mc.convocatoria_id, s.id, s.nombre_entidad AS nombre, COALESCE(p.convenio_referencia, '') AS referencia
                FROM marketing_aliado_convocatorias_origen mc INNER JOIN seguimientos_vinculacion s ON s.id=mc.origen_id AND mc.origen_tipo='institucional'
                INNER JOIN aliados_asignaciones a ON a.seguimiento_id=s.id AND a.activo=1
                LEFT JOIN seguimientos_vinculacion_post_envio p ON p.seguimiento_id=s.id
                WHERE s.activo=1 AND mc.convocatoria_id IN ($ids)";
        }
        if($this->tabla('aliados_manuales')) {
            foreach(['marketing_aliado_manual_convocatorias'=>'mc.manual_id','marketing_aliado_convocatorias_origen'=>'mc.origen_id'] as $table=>$key) {
                if(!$this->tabla($table)) { continue; }
                $type=$table==='marketing_aliado_convocatorias_origen'?" AND mc.origen_tipo='manual'":'';
                $sources[]="SELECT DISTINCT mc.convocatoria_id, CONCAT('manual:', a.id) AS id, a.nombre_entidad AS nombre, COALESCE(a.referencia, '') AS referencia
                    FROM $table mc INNER JOIN aliados_manuales a ON a.id=$key
                    WHERE a.activo=1$type AND mc.convocatoria_id IN ($ids)";
            }
        }
        foreach($sources as $sql) {
            foreach($this->filas($sql) as $r) {
                $items[(int)$r['convocatoria_id']]['convenios'][]=['id'=>self::convenioId($r['id']),'nombre'=>(string)$r['nombre'],'referencia'=>(string)$r['referencia']];
            }
        }
        foreach($items as &$item) {
            $unique=[];foreach($item['convenios'] as $c) { $unique[(string)$c['id']]=$c; }
            $item['convenios']=array_values($unique);
        }unset($item);
        return array_values($items);
    }
    public function catalogo(): array
    {
        $states=$this->filas('SELECT id, nombre FROM estados WHERE estado = 1 ORDER BY nombre, id');
        $rows=$this->completar($this->filas('SELECT id, titulo, imagen, estado FROM convocatorias WHERE estado = 1 ORDER BY titulo, id'));
        foreach($rows as &$row) { unset($row['imagen_ref']); }unset($row);
        $agreements=[];$warnings=[];
        try { $agreements=$this->listarConvenios(); }
        catch(Throwable $error) {
            error_log('MAIL_CONVOCATORIAS_CONVENIOS exception='.get_class($error).' code='.(int)$error->getCode());
            $warnings[]='CONVENIOS_CONSULTA_NO_DISPONIBLE';
        }
        return ['estados'=>array_map(static fn($r)=>['id'=>(int)$r['id'],'nombre'=>(string)$r['nombre']],$states),'convocatorias'=>$rows,'convenios'=>$agreements,'avisos'=>$warnings,
            'convenios_disponibles'=>$this->tieneRelaciones() || $this->tabla('marketing_aliado_manual_convocatorias') || $this->tabla('marketing_aliado_convocatorias_origen')];
    }
    public function listarConvenios(): array
    {
        // Misma fuente y reglas de CrearConvocatoriaModel::listarConveniosAliados,
        // sin invocar sus métodos de CREATE TABLE ni exigir una convocatoria relacionada.
        $rows=$this->filas("SELECT DISTINCT s.id, s.nombre_entidad AS nombre, s.estado_id, COALESCE(p.convenio_referencia, '') AS referencia
            FROM aliados_asignaciones a INNER JOIN seguimientos_vinculacion s ON s.id = a.seguimiento_id
            INNER JOIN estados e ON e.id = s.estado_id
            LEFT JOIN seguimientos_vinculacion_post_envio p ON p.seguimiento_id = s.id
            WHERE a.activo = 1 AND s.activo = 1 ORDER BY s.nombre_entidad, s.id");
        $unique=[];
        foreach($rows as $r) { $id=(int)$r['id'];$unique[$id]=['id'=>$id,'nombre'=>(string)$r['nombre'],'estado_id'=>(int)$r['estado_id'],'referencia'=>(string)$r['referencia']]; }
        // Mismas reglas SELECT de AliadoRegistroService::manuales/origen,
        // sin importar sus operaciones de escritura ni depender de perfiles de formulario.
        if($this->tabla('aliados_manuales')) {
            foreach($this->filas("SELECT a.id, a.nombre_entidad AS nombre, a.estado_id, COALESCE(a.referencia, '') AS referencia
                FROM aliados_manuales a INNER JOIN estados e ON e.id=a.estado_id
                WHERE a.activo=1 ORDER BY a.nombre_entidad, a.id") as $r) {
                $id='manual:'.(int)$r['id'];
                $unique[$id]=['id'=>$id,'origen'=>'manual','origen_id'=>(int)$r['id'],'nombre'=>(string)$r['nombre'],'estado_id'=>(int)$r['estado_id'],'referencia'=>(string)$r['referencia']];
            }
        }
        return array_values($unique);
    }
    public function buscar(int $id): ?array
    {
        if($id<=0) { return null; }
        $rows=$this->completar($this->filas('SELECT id, titulo, imagen, estado, enlace_registro FROM convocatorias WHERE id = ? LIMIT 1',[$id]));
        return $rows[0]??null;
    }
}
