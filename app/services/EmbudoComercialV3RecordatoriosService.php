<?php
declare(strict_types=1);
require_once __DIR__.'/../helpers/PermissionHelper.php';
require_once __DIR__.'/EmbudoComercialV3SeguimientoService.php';
require_once __DIR__.'/EmbudoComercialPresentacion.php';
require_once __DIR__.'/../helpers/ReminderHelper.php';

/** Pendientes derivados de la actividad vigente. Consultar o abrir no los completa. */
final class EmbudoComercialV3RecordatoriosService
{
    private mysqli $db;
    private DateTimeImmutable $ahora;

    public function __construct(?mysqli $db=null,?DateTimeImmutable $ahora=null)
    {
        $this->db=$db??(new Database())->connect();
        $this->ahora=$ahora??new DateTimeImmutable('now',new DateTimeZone('UTC'));
    }

    public function obtener(int $usuario): array
    {
        if ($usuario<1 || (int)($_SESSION['usuario_id']??0)!==$usuario ||
            (int)($_SESSION['rol_id']??0)!==3 || !tienePermiso('ventas.embudo.ver')) {
            throw new DomainException('No tienes acceso a estos pendientes comerciales.');
        }
        if (!EmbudoComercialV3SeguimientoService::instalada($this->db)) {
            return ['recordatorios'=>[],'avisos'=>[]];
        }
        $q=$this->db->prepare("SELECT a.id asignacion_id,a.prospecto_id,p.nombre_completo,
                av.tipo_actividad,av.detalle_actividad,av.fecha_pendiente,c.id ciclo,c.motivo detalle_heredado
            FROM prospecto_asignaciones a
            JOIN prospectos_comerciales p ON p.id=a.prospecto_id
            JOIN prospecto_embudo_actividad_v3 av ON av.id=(
                SELECT MAX(v.id) FROM prospecto_embudo_actividad_v3 v WHERE v.asignacion_id=a.id)
            LEFT JOIN prospecto_embudo_actividad_v3 c ON c.id=(
                SELECT MAX(v.id) FROM prospecto_embudo_actividad_v3 v
                WHERE v.asignacion_id=a.id AND v.accion IN ('PROGRAMAR','REPROGRAMAR'))
            LEFT JOIN prospecto_embudo_etapas e ON e.asignacion_id=a.id
            LEFT JOIN prospecto_embudo_subetapas s ON s.asignacion_id=a.id
            WHERE a.asesor_id=? AND a.vigente=1 AND av.fecha_pendiente IS NOT NULL
                AND COALESCE(e.etapa,'NUEVO') IN ('NUEVO','CONTACTADO','POTENCIAL','INTERESADO','SEGUIMIENTO','PROCESO_INSCRIPCION')
                AND COALESCE(s.subetapa,'')<>'NO_CONTACTAR'
            ORDER BY av.fecha_pendiente,a.id");
        $q->bind_param('i',$usuario);$q->execute();
        $rows=$q->get_result()->fetch_all(MYSQLI_ASSOC);$q->close();
        $recordatorios=[];$avisos=[];
        foreach ($rows as $r) {
            $fecha=new DateTimeImmutable($r['fecha_pendiente'],new DateTimeZone('UTC'));
            $segundos=$fecha->getTimestamp()-$this->ahora->getTimestamp();
            // Los mismos umbrales que Analista: 3H, 1H, 10M y VENCIDA.
            $ventana=resolverTipoAvisoRecordatorio($segundos);
            $tipo=EmbudoComercialV3TipoActividad::etiqueta($r['tipo_actividad']);
            $detalle=trim((string)($r['detalle_actividad']??$r['detalle_heredado']??''));
            $fechaTexto=EmbudoComercialPresentacion::fechaUtc($r['fecha_pendiente']);
            $etiqueta=$segundos<=0?'Vencida':($segundos<=3600
                ?'En '.max(1,(int)ceil($segundos/60)).' min':($segundos<=10800?'En '.(int)ceil($segundos/3600).' h':'Programada'));
            $item=[
                'id'=>(int)$r['asignacion_id'],'comercial_id'=>(int)$r['asignacion_id'],
                'actividad_ciclo'=>(int)$r['ciclo'],'usuario_id'=>$usuario,
                'nombre_entidad'=>$r['nombre_completo'],'accion'=>$tipo,
                'detalle'=>$detalle,'fecha'=>$fecha->format('Y-m-d\TH:i:s\Z'),
                'fecha_texto'=>$fechaTexto.' · hora de México','etiqueta'=>$etiqueta,
                'estado'=>$segundos<=0?'vencida':'proxima','prioridad'=>$segundos<=0?0:2,
                'icono'=>match($r['tipo_actividad']) {
                    'LLAMADA'=>'bi-telephone','MENSAJE'=>'bi-chat-dots',
                    'DOCUMENTOS'=>'bi-file-earmark-check','COMPROBANTE'=>'bi-receipt',
                    'INSCRIPCION'=>'bi-file-earmark-person',default=>'bi-calendar-event'
                },
                'url'=>'index.php?'.http_build_query(['controller'=>'embudoComercial',
                    'action'=>'detalle','id'=>(int)$r['prospecto_id'],'panel'=>'actividad']),
                'ventana'=>$ventana,
            ];
            $recordatorios[]=$item;
            if ($ventana!=='') {
                $avisos[]=$item+['tipo'=>$ventana,
                    'titulo'=>$segundos<=0?'Actividad comercial vencida':'Próxima actividad comercial',
                    'mensaje'=>$r['nombre_completo'].' · '.$tipo.($detalle!==''?' · '.$detalle:'').' · '.$fechaTexto];
            }
        }
        return ['recordatorios'=>$recordatorios,'avisos'=>$avisos];
    }

    /** La agenda de Telefonía delega sólo las citas PBX realmente enlazadas a V3. */
    public function filtrarTelefonia(int $usuario,array $filas): array
    {
        if (!$filas || (int)($_SESSION['usuario_id']??0)!==$usuario ||
            (int)($_SESSION['rol_id']??0)!==3 || !tienePermiso('ventas.embudo.ver') ||
            !EmbudoComercialV3SeguimientoService::instalada($this->db)) return $filas;
        $q=$this->db->prepare("SELECT DISTINCT l.extension,l.pbx_call_id
            FROM prospecto_embudo_llamadas l
            WHERE l.usuario_id=? AND EXISTS (
                SELECT 1 FROM prospecto_embudo_actividad_v3 v
                WHERE v.asignacion_id=l.asignacion_id AND v.accion IN ('PROGRAMAR','REPROGRAMAR')
                    AND v.creado_at>=l.creado_at)");
        $q->bind_param('i',$usuario);$q->execute();$vinculadas=[];
        foreach($q->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $vinculadas[$r['extension'].'|'.$r['pbx_call_id']]=true;
        }
        $q->close();
        return array_values(array_filter($filas,static fn($r)=>$r['origen']!=='resultado' ||
            !isset($vinculadas[$r['extension'].'|'.$r['pbx_call_id']])));
    }
}
