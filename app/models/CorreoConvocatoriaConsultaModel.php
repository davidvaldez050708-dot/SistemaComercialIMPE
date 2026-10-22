<?php
require_once dirname(__DIR__,2).'/config/db_connection.php';

/** Consultas exclusivas de Correos. No crea tablas ni cambia convocatorias/convenios. */
class CorreoConvocatoriaConsultaModel
{
    private $db;
    private $relacionesDisponibles;
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
    private function completar(array $rows): array
    {
        if(!$rows) { return []; }
        $items=[];
        foreach($rows as $r) {
            $id=(int)$r['id'];$items[$id]=['id'=>$id,'titulo'=>(string)$r['titulo'],'activa'=>(int)$r['estado']===1,
                'imagen_ref'=>(string)($r['imagen']??''),'estados'=>[],'convenios'=>[]];
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
        return array_values($items);
    }
    public function catalogo(): array
    {
        $states=$this->filas('SELECT id, nombre FROM estados WHERE estado = 1 ORDER BY nombre, id');
        $rows=$this->completar($this->filas('SELECT id, titulo, imagen, estado FROM convocatorias WHERE estado = 1 ORDER BY titulo, id'));
        foreach($rows as &$row) { unset($row['imagen_ref']); }unset($row);
        return ['estados'=>array_map(static fn($r)=>['id'=>(int)$r['id'],'nombre'=>(string)$r['nombre']],$states),'convocatorias'=>$rows,
            'convenios_disponibles'=>$this->tieneRelaciones()];
    }
    public function buscar(int $id): ?array
    {
        if($id<=0) { return null; }
        $rows=$this->completar($this->filas('SELECT id, titulo, imagen, estado FROM convocatorias WHERE id = ? LIMIT 1',[$id]));
        return $rows[0]??null;
    }
}
