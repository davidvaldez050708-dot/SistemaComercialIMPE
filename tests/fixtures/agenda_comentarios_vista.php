<?php
require_once dirname(__DIR__,2).'/config/config.php';
$mesAgenda='2026-10';$mesAnterior='2026-09';$mesSiguiente='2026-11';$tituloMesAgenda='Octubre 2026';
$mesPrueba=getenv('AGENDA_QA_MES');
if(is_string($mesPrueba)&&preg_match('/^20[0-9]{2}-(0[1-9]|1[0-2])$/D',$mesPrueba)){
    $mesAgenda=$mesPrueba;$primero=new DateTimeImmutable($mesAgenda.'-01');
    $mesAnterior=$primero->modify('-1 month')->format('Y-m');$mesSiguiente=$primero->modify('+1 month')->format('Y-m');
    $tituloMesAgenda=$mesAgenda;
}
$agendaSeguimientoInicial=0;$agendaReunionInicial=0;$agendaRolId=4;$agendaModo='SOLICITUD';
$agendaPuedeSolicitar=true;$agendaPuedeGestionar=false;$agendaPuedeVerSeguimiento=false;$agendaRequiereMigracion=false;
$seguimientosElegibles=[];
$agendaReuniones=[['id'=>31,'seguimiento_id'=>12,'nombre_entidad'=>'Institución de prueba','fecha_propuesta'=>'2026-10-14 12:30:00','duracion_minutos'=>60,
 'estado'=>'SOLICITADA','estado_visual'=>'solicitada','estado_etiqueta'=>'Solicitada','fecha_legible'=>'14/10/2026 12:30','modalidad'=>'VIRTUAL',
 'analista_id'=>5,'cuenta_clave_id'=>7,'notas_analista'=>'Prueba sin modificaciones','objetivo'=>'Reunión existente']];
$celdasAgenda=[];$primero=new DateTimeImmutable($mesAgenda.'-01');$inicio=$primero->modify('-'.((int)$primero->format('N')-1).' days');
for($i=0;$i<42;$i++){$dia=$inicio->modify('+'.$i.' days');$fecha=$dia->format('Y-m-d');$celdasAgenda[]=['fecha'=>$fecha,'numero'=>$dia->format('j'),
 'es_mes'=>$dia->format('Y-m')===$mesAgenda,'es_hoy'=>false,'reuniones'=>$fecha==='2026-10-14'?$agendaReuniones:[]];}
require dirname(__DIR__,2).'/app/views/seguimiento_vinculacion/agenda.php';
