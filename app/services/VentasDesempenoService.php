<?php
require_once __DIR__.'/VentasActividadService.php';

/** Conserva los hechos telefónicos del reporte y los presenta como desempeño. */
final class VentasDesempenoService
{
    private ?mysqli $db;
    public function __construct(?mysqli $db=null) { $this->db=$db; }
    public function preparar(array $f): array
    {
        // Desempeño compara toda la actividad del periodo; no hereda filtros ocultos del reporte.
        $f['direccion']='';$f['resultado']='';
        // No hay atribución territorial de contactos: ignorar también enlaces antiguos o manipulados.
        $f['estado_id']=0;
        return self::presentar((new VentasActividadService($this->db))->preparar($f,true));
    }

    public static function presentar(array $d): array
    {
        $ranking=$d['personas_resumen'];
        $maxCalls=$ranking?max(array_column($ranking,'llamadas')):0;
        $maxContacts=$ranking?max(array_column($ranking,'conversaciones_confirmadas')):0;
        $comparativo=count($ranking)>1 && $d['vista']!=='propio';
        foreach ($ranking as &$p) {
            $p['indice']=$comparativo?round(
                ($maxCalls>0?$p['llamadas']/$maxCalls*25:0)+
                ($maxContacts>0?$p['conversaciones_confirmadas']/$maxContacts*35:0)+
                ($p['tasa_contacto']??0)*0.25+($p['cumplimiento_registro']??0)*0.15,1):null;
        }
        unset($p);
        usort($ranking,static function($a,$b) {
            foreach (['indice','conversaciones_confirmadas','llamadas'] as $key) {
                $cmp=($b[$key]??0)<=>($a[$key]??0);if ($cmp) return $cmp;
            }
            return strnatcasecmp($a['nombre_completo'],$b['nombre_completo']) ?: (int)$a['id']<=>(int)$b['id'];
        });
        foreach ($ranking as $i=>&$p) $p['posicion']=$i+1;
        unset($p);
        $awards=[];
        if ($comparativo) foreach ([
            ['conversaciones_confirmadas','Mayor contacto','conversaciones confirmadas','bi-person-check'],
            ['llamadas','Mayor actividad telefónica','llamadas registradas','bi-activity'],
            ['tasa_contacto','Mejor tasa de contacto','% de contacto','bi-bullseye']
        ] as [$metric,$title,$unit,$icon]) {
            $max=max(array_map(static fn($p)=>(float)($p[$metric]??0),$ranking));
            if ($max<=0) continue;
            $leaders=array_values(array_filter($ranking,static fn($p)=>(float)($p[$metric]??0)===$max));
            $tied=count($leaders)>1;
            $awards[]=['titulo'=>$title.($tied?' · Empate':''),'nombre'=>$tied?implode(', ',array_column($leaders,'nombre_completo')):$leaders[0]['nombre_completo'],
                'foto_perfil'=>$tied?'':($leaders[0]['foto_perfil']??''),'valor'=>$metric==='tasa_contacto'?number_format($max,1):(int)$max,'unidad'=>$unit,'icono'=>$icon];
        }
        $d['ranking']=$ranking;$d['reconocimientos']=$awards;
        $d['tendencia']=array_map(static fn($day)=>['fecha'=>$day['fecha'],'label'=>date('d/m',strtotime($day['fecha'])),
            'principal'=>$day['llamadas'],'secundario'=>$day['conversaciones_confirmadas'],'terciario'=>$day['registradas']],$d['dias']);
        $d['criterios']=[
            'Se cuentan llamadas entrantes y salientes con referencia confirmada, una por asesor, extensión y llamada. Los intentos y transferencias sin confirmar se muestran aparte.',
            'Contacto confirmado: llamada conectada cuyo resultado registrado indica conversación con una persona. La tasa de contacto divide esos contactos entre las llamadas registradas.',
            'Cumplimiento del registro: resultados guardados entre llamadas que ya permiten registrar un resultado. Sin llamadas aplicables, se muestra —.',
            'El índice operativo compara el volumen de llamadas (25%), el volumen de contactos confirmados (35%), la tasa de contacto (25%) y el cumplimiento del registro (15%). Los volúmenes se dividen entre el máximo del grupo filtrado. Sólo se compara dentro del mismo periodo y alcance.',
            'Los empates en el índice se ordenan por contactos confirmados, llamadas y nombre. Los reconocimientos comparten el mérito cuando hay empate.',
            'La actividad se consulta por asesor y periodo, sin atribuir llamadas a estados. Cuenta Clave conserva únicamente el acceso a su equipo autorizado.',
            'El tiempo corresponde únicamente al asesor y a duraciones confirmadas. Las duraciones pendientes no se convierten en cero ni influyen en el índice.',
            'Este ranking mide actividad telefónica; no mide ventas, ingresos ni cierres y no asigna automáticamente incentivos.'
        ];
        $d['territorios']=[];$d['estado_id']=0;
        $d['actualizado_at']=date('d/m/Y H:i:s');
        $d['puede_cambiar_area']=$d['vista']!=='propio';
        $d['puede_cambiar_persona']=$d['vista']!=='propio' && count($d['personas'])>1;
        return $d;
    }
}
