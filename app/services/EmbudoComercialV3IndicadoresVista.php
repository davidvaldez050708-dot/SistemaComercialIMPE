<?php
/**
 * Indicadores de sólo lectura para la PREVISUALIZACIÓN V3 de supervisor.
 *
 * El origen es el vínculo comercial registrado por Bridge + llamada saliente
 * y resultado definitivo de Telefonía, usando identidad PBX exacta. Ningún
 * contador editable, DDL, conexión nueva, endpoint de HTTP ni escrituras.
 *
 * Solo debe invocarse con IDs ya autorizados y LIMITADOS por Repository::condiciones.
 * No es un controlador de autorización de asesor ni una fuente de movimientos.
 */
final class EmbudoComercialV3IndicadoresVista
{
    private mysqli $db;
    public const MAX_ASIGNACIONES = 120;

    public function __construct(mysqli $db)
    {
        $this->db=$db;
    }

    /**
     * @param int[] $asignaciones IDs previamente filtrados por propietario.
     * @return array<int,array{intentos:int,pendientes:int,fecha:string}>
     */
    public function intentosDelDia(array $asignaciones,string $diaLocal): array
    {
        $zone=new DateTimeZone('America/Mexico_City');
        $inicio=DateTimeImmutable::createFromFormat('!Y-m-d',$diaLocal,$zone);
        if (!$inicio || $inicio->format('Y-m-d')!==$diaLocal) {
            throw new InvalidArgumentException('La fecha de jornada no es válida.');
        }
        $ids=[];
        foreach($asignaciones as $id){
            if (!is_int($id) || $id<1) {
                throw new InvalidArgumentException('La asignación no es válida.');
            }
            $ids[$id]=$id;
        }
        if(count($ids)>self::MAX_ASIGNACIONES) {
            throw new InvalidArgumentException('Demasiadas asignaciones para la vista.');
        }
        if(!$ids) return [];

        $desde=$inicio->format('Y-m-d H:i:s');
        $hasta=$inicio->modify('+1 day')->format('Y-m-d H:i:s');
        $out=[];
        foreach($ids as $id) {
            $out[$id]=['intentos'=>0,'pendientes'=>3,'fecha'=>$diaLocal];
        }
        $holders=implode(',',array_fill(0,count($ids),'?'));
        $sql="SELECT l.asignacion_id,l.extension,l.pbx_call_id,
                     l.resultado,d.catalogo_version,d.contacto_antes_corte
              FROM prospecto_embudo_llamadas l
              JOIN prospecto_asignaciones a ON a.id=l.asignacion_id
                AND a.asesor_id=l.usuario_id AND a.vigente=1
              JOIN telefonia_ventas_llamadas c
                ON c.usuario_id=l.usuario_id
                AND BINARY c.extension=BINARY l.extension
                AND BINARY c.pbx_call_id=BINARY l.pbx_call_id
              JOIN telefonia_ventas_resultados r
                ON r.usuario_id=l.usuario_id
                AND BINARY r.extension=BINARY l.extension
                AND BINARY r.pbx_call_id=BINARY l.pbx_call_id
                AND BINARY r.resultado=BINARY l.resultado
              LEFT JOIN telefonia_ventas_resultado_detalles d
                ON d.resultado_id=r.id
              WHERE l.asignacion_id IN ($holders)
                AND c.direccion='Saliente' AND c.fecha>=? AND c.fecha<?
              ORDER BY c.fecha,c.id,l.id";
        $stmt=$this->db->prepare($sql);
        $types=str_repeat('i',count($ids)).'ss';
        $params=[...array_values($ids),$desde,$hasta];
        $stmt->bind_param($types,...$params);
        try {
            $stmt->execute();
            $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }finally{$stmt->close();}

        $seen=[];
        foreach($rows as $r){
            $id=(int)$r['asignacion_id'];
            if(!isset($out[$id])) continue;
            $ext=(string)$r['extension'];
            $pbx=(string)$r['pbx_call_id'];
            if(!preg_match('/^[0-9]{3,6}$/D',$ext) ||
               !preg_match('/^[A-Za-z0-9_-]{6,100}$/D',$pbx)) {
                continue;
            }
            $codigo=(string)$r['resultado'];
            $sinContacto=in_array($codigo,[
                'SIN_RESPUESTA','BUZON_VOZ','OCUPADO',
                'MENSAJE_AUTOMATICO','OTRO_SIN_CONTACTO'
            ],true);
            if($codigo==='COMUNICACION_INTERRUMPIDA' &&
               (string)($r['contacto_antes_corte']??'')==='NO') {
                $sinContacto=true;
            }
            if(in_array($codigo,[
                'ENVIO_WHATSAPP','ENVIO_SMS','ENVIO_WHATSAPP_SMS'
            ],true) && (string)($r['catalogo_version']??'')==='4') {
                $sinContacto=true;
            }
            if(!$sinContacto) continue;
            // Un tramo PBX solo una vez por asignación, incluso con JOINs
            // repetidos. El identificador nunca se devuelve a la vista.
            $key=$ext.'|'.$pbx;
            if(isset($seen[$id][$key])) continue;
            $seen[$id][$key]=true;
            $out[$id]['intentos']++;
        }
        foreach($out as &$fila){
            $fila['pendientes']=max(0,3-$fila['intentos']);
        }
        unset($fila);
        return $out;
    }
}
