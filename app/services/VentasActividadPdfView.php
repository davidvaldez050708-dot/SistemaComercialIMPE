<?php
require_once __DIR__.'/VentasActividadMetricas.php';
require_once __DIR__.'/ReporteNombreArchivoService.php';

/** Usa la misma hoja, tipografía, encabezado, tablas y pie del PDF del analista. */
final class VentasActividadPdfView
{
    private static function e($v): string { return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
    public static function nombre(array $d): string
    {
        $scope=['Asesores'];
        if (count($d['personas_resumen'])===1) $scope[]=$d['personas_resumen'][0]['nombre_completo'];
        return ReporteNombreArchivoService::conPeriodo($d['es_desempeno']?'Desempeno':'Actividad_Telefonica',$scope,$d['periodo'],date('Y-m-d'));
    }
    private static function tabla(array $headers,array $rows): string
    {
        $html='<table class="table"><thead><tr>';
        foreach ($headers as $h) $html.='<th>'.self::e($h).'</th>';
        $html.='</tr></thead><tbody>';
        foreach ($rows as $row) { $html.='<tr>';foreach ($row as $v) $html.='<td style="overflow-wrap:break-word;word-wrap:break-word">'.self::e($v).'</td>';$html.='</tr>'; }
        if (!$rows) $html.='<tr><td colspan="'.count($headers).'">Sin registros en este periodo.</td></tr>';
        return $html.'</tbody></table>';
    }
    private static function titulo(string $t): string { return '<div class="section-title"><h2>'.self::e($t).'</h2></div>'; }
    public static function resultados(array $d): string
    {
        $rows=[];foreach($d['resultados'] as $code=>$n)$rows[]=[TelefoniaResultadoVentasCatalogo::opcionesConsulta()[$code]??'Sin resultado registrado',$n];
        return self::titulo('Distribución de resultados').'<p class="notice">Una llamada puede tener varios resultados. Las categorías no se suman como llamadas adicionales.</p>'.self::tabla(['Resultado','Llamadas'],$rows);
    }
    private static function horarios(array $d): string
    {
        if(!VentasActividadMetricas::mostrarHorarioIndividual($d))return '';
        $html=self::titulo('Horario de llamadas').'<p class="notice">'.self::e($d['personas_resumen'][0]['nombre_completo']).' · Hora de México. Inicio de las llamadas del reporte, con los mismos filtros de tipo y resultado. Se excluyen intentos y transferencias sin confirmar. Los días sin llamadas se muestran con —.</p>';
        if(in_array($d['periodo']['clave'],['hoy','ayer'],true)) {
            $day=$d['dias'][0];$html.='<table class="metrics"><tr>';
            foreach(['primera_llamada'=>'Primera llamada','ultima_llamada'=>'Última llamada'] as $key=>$label)
                $html.='<td><span>'.self::e($label).'</span><strong>'.self::e(VentasActividadMetricas::horaInicio($day[$key])).'</strong><span>'.self::e($day['fecha'].(!$day['llamadas']?' · Sin llamadas':'')).'</span></td>';
            return $html.'</tr></table>';
        }
        $rows=[];foreach($d['dias'] as $day)$rows[]=[$day['fecha'],$day['llamadas'],VentasActividadMetricas::horaInicio($day['primera_llamada']),VentasActividadMetricas::horaInicio($day['ultima_llamada'])];
        return $html.self::tabla(['Fecha','Llamadas','Primera llamada','Última llamada'],$rows);
    }
    public static function pendientes(array $d): string
    {
        $html=self::titulo('Pendientes y vencimientos actuales');
        $o=$d['organizacion']??[];
        $html.='<p class="notice">Estado actual de los asesores seleccionados, al consultar '.self::e($o['consultado_at']??$d['fecha_generacion']).' (México). No representa el estado histórico del periodo ni cambia al filtrar resultados. Las programadas vencen en la fecha y hora agendadas; las devoluciones, al terminar el día de la solicitud. Las vencidas están incluidas en los pendientes. No cuentan como llamadas ni alteran el ranking.</p>';
        if (!empty($o['disponible'])) {
            $pending=[];
            foreach ($d['personas_resumen'] as $p) {
                $v=$o['personas'][(int)$p['id']]??[];
                $pending[]=[$p['nombre_completo'],(int)($v['programadas']??0),(int)($v['vencidas']??0),(int)($v['devoluciones']??0),(int)($v['devoluciones_vencidas']??0)];
            }
            $pending[]=['Total',(int)$o['programadas'],(int)$o['vencidas'],(int)$o['devoluciones'],(int)($o['devoluciones_vencidas']??0)];
            $html.=self::tabla(['Asesor','Programadas pendientes','De ellas, vencidas','Devoluciones pendientes','De ellas, vencidas'],$pending);
            if (!empty($o['devoluciones_sin_fecha'])) $html.='<p class="notice">'.(int)$o['devoluciones_sin_fecha'].' devoluciones sin fecha de solicitud confirmada; no se les atribuye vencimiento.</p>';
        } else $html.='<p class="notice">Organización no disponible con la estructura instalada.</p>';
        return $html;
    }
    public function html(array $d,string $logo,string $css): string
    {
        $e=[self::class,'e'];$s=$d['resumen'];$time=[VentasActividadMetricas::class,'tiempo'];
        $title=$d['es_desempeno']?'Desempeño telefónico':'Reporte de actividad telefónica';
        $html='<!doctype html><html lang="es"><meta charset="utf-8"><style>'.$css.'.table{table-layout:fixed;font-size:6.8pt}.table td,.table th{word-wrap:break-word}.call-notes td{font-size:6.5pt;color:#556274}</style><body><div class="top-rule"></div><table class="header"><tr><td class="brand">';
        if ($logo!=='') $html.='<img src="'.$e($logo).'" alt="Grupo Porcayo">';
        $html.='</td><td class="header-copy"><div class="system-name">Sistema de Gestión Comercial</div><h1>'.$e($title).'</h1><table class="header-meta">';
        foreach (['Generado por'=>$d['generado_por'],'Rol'=>$d['generado_por_rol'],'Generado el'=>$d['fecha_generacion']] as $k=>$v)
            $html.='<tr><td>'.$e($k).'</td><th>'.$e($v).'</th></tr>';
        $html.='</table></td></tr></table><table class="scope"><tr>';
        $scope=['propio'=>'Personal','equipo'=>'Mi equipo','global'=>'Nacional'][$d['vista']];
        foreach (['Alcance'=>$scope,'Periodo'=>$d['periodo']['label'],'Desde'=>$d['periodo']['fecha_desde'],'Hasta'=>$d['periodo']['fecha_hasta']] as $k=>$v)
            $html.='<td><span>'.$e($k).'</span><strong>'.$e($v).'</strong></td>';
        $html.='</tr></table><p class="notice">Tipo: '.$e($d['direccion']?:'Entrantes y salientes').' · Resultado: '.$e(TelefoniaResultadoVentasCatalogo::opcionesConsulta()[$d['resultado']]??($d['resultado']==='PENDIENTE'?'Sin resultado registrado':'Todos')).'</p>';
        $html.=self::titulo('Resumen del periodo').'<table class="metrics"><tr>';
        foreach (['Salientes'=>$s['salientes'],'Entrantes'=>$s['entrantes'],'Buzones de voz'=>$s['buzones'],'Conversaciones registradas'=>$s['conversaciones']] as $k=>$v)
            $html.='<td><span>'.$e($k).'</span><strong>'.$e($v).'</strong></td>';
        $html.='</tr></table><p class="notice">Tiempo confirmado del asesor: '.VentasActividadMetricas::tiempoConfirmado($s).' · Promedio con duración confirmada: '.$time($s['promedio_segundos']).' · Duraciones pendientes: '.$s['duraciones_pendientes'].' · Resultados pendientes: '.$s['por_registrar'].' · Intentos sin confirmar: '.$s['intentos'].' · Transferencias pendientes: '.$s['transferencias_pendientes'].'.</p>';
        $html.='<p class="notice">Tasa de contacto: '.($s['tasa_contacto']===null?'—':$s['tasa_contacto'].'%').' · Cumplimiento del registro: '.($s['cumplimiento_registro']===null?'—':$s['cumplimiento_registro'].'%').'.</p>';
        $html.='<p class="notice">Buzones de voz: llamadas clasificadas como buzón por el asesor, dentro del periodo y filtros seleccionados.</p>';
        $html.=self::horarios($d);
        $html.=self::titulo('Actividad por asesor');$people=[];
        foreach ($d['personas_resumen'] as $p) $people[]=[$p['nombre_completo'],$p['salientes'],$p['entrantes'],$p['conversaciones'],VentasActividadMetricas::tiempoConfirmado($p),$p['por_registrar'],$p['cumplimiento_registro']===null?'—':$p['cumplimiento_registro'].'%'];
        $html.=self::tabla(['Asesor','Salientes','Entrantes','Conversaciones','Tiempo','Por registrar','Registro'],$people);
        $html.=self::titulo('Evolución por día');$days=[];
        foreach ($d['dias'] as $p) $days[]=[$p['fecha'],$p['salientes'],$p['entrantes'],$p['conversaciones'],$p['duraciones_confirmadas']?$time($p['segundos']):'—',$p['duraciones_pendientes']];
        $html.=self::tabla(['Fecha','Salientes','Entrantes','Conversaciones','Tiempo','Duraciones pendientes'],$days);
        $html.=self::resultados($d);
        $html.=self::pendientes($d);
        $html.=self::titulo('Detalle de llamadas').'<table class="table"><thead><tr>';
        foreach (['Fecha y hora','Asesor','Teléfono','Tipo','Resultado','Duración'] as $h) $html.='<th>'.$e($h).'</th>';
        $html.='</tr></thead><tbody>';
        foreach ($d['llamadas'] as $c) {
            $html.='<tr>';
            foreach ([$c['fecha'],$c['asesor'],$c['numero'],$c['direccion'],
                $c['pbx_call_id']===''?'Sin confirmar':TelefoniaResultadoVentasCatalogo::textoRegistro($c['registro']??null),$time($c['segundos'])] as $v)
                $html.='<td>'.$e($v).'</td>';
            $html.='</tr>';
            if($aclaracion=TelefoniaResultadoVentasCatalogo::aclaracionesRegistro($c['registro']??null))$html.='<tr class="call-notes"><td colspan="6">Detalle del resultado: '.$e($aclaracion).'</td></tr>';
            if (trim($c['registro']['observacion']??'')!=='') $html.='<tr class="call-notes"><td colspan="6">Observaciones ('.$e($c['fecha']).'): '.$e($c['registro']['observacion']).'</td></tr>';
        }
        if (!$d['llamadas']) $html.='<tr><td colspan="6">Sin registros en este periodo.</td></tr>';
        $html.='</tbody></table>';
        return $html.'<p class="notice">Una llamada registrada no acredita conversación con una persona. Las conversaciones se clasifican con el resultado del asesor; la tasa de contacto requiere además conexión confirmada. El tiempo corresponde únicamente al asesor. Las duraciones pendientes no se consideran cero ni entran en el promedio. Los intentos sin confirmar se muestran aparte. No se asignan calificaciones de ventas.</p></body></html>';
    }
}
