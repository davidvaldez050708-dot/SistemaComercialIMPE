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
        $html.='</tr></table><p class="notice">Tipo: '.$e($d['direccion']?:'Entrantes y salientes').' · Resultado: '.$e(TelefoniaResultadoVentasService::opciones()[$d['resultado']]??($d['resultado']==='PENDIENTE'?'Sin resultado registrado':'Todos')).'</p>';
        $html.=self::titulo('Resumen del periodo').'<table class="metrics"><tr>';
        foreach (['Salientes'=>$s['salientes'],'Entrantes'=>$s['entrantes'],'Conectadas'=>$s['conectadas'],'Conversaciones registradas'=>$s['conversaciones']] as $k=>$v)
            $html.='<td><span>'.$e($k).'</span><strong>'.$e($v).'</strong></td>';
        $html.='</tr></table><p class="notice">Tiempo confirmado del asesor: '.$time($s['duraciones_confirmadas']?$s['segundos']:null).' · Promedio con duración confirmada: '.$time($s['promedio_segundos']).' · Duraciones pendientes: '.$s['duraciones_pendientes'].' · Resultados pendientes: '.$s['por_registrar'].' · Intentos sin confirmar: '.$s['intentos'].' · Transferencias pendientes: '.$s['transferencias_pendientes'].'.</p>';
        $html.=self::titulo('Actividad por asesor');$people=[];
        foreach ($d['personas_resumen'] as $p) $people[]=[$p['nombre_completo'],$p['salientes'],$p['entrantes'],$p['conversaciones'],$time($p['duraciones_confirmadas']?$p['segundos']:null),$p['por_registrar'],$p['cumplimiento_registro']===null?'—':$p['cumplimiento_registro'].'%'];
        $html.=self::tabla(['Asesor','Salientes','Entrantes','Conversaciones','Tiempo','Por registrar','Registro'],$people);
        $html.=self::titulo('Evolución por día');$days=[];
        foreach ($d['dias'] as $p) $days[]=[$p['fecha'],$p['salientes'],$p['entrantes'],$p['conversaciones'],$p['duraciones_confirmadas']?$time($p['segundos']):'—',$p['duraciones_pendientes']];
        $html.=self::tabla(['Fecha','Salientes','Entrantes','Conversaciones','Tiempo','Duraciones pendientes'],$days);
        $html.=self::titulo('Distribución de resultados');$results=[];
        foreach ($d['resultados'] as $code=>$n) $results[]=[TelefoniaResultadoVentasService::opciones()[$code]??'Sin resultado registrado',$n];
        $html.=self::tabla(['Resultado','Llamadas'],$results);
        $html.=self::titulo('Organización actual');
        $o=$d['organizacion']??[];
        $html.='<p class="notice">Pendientes al momento de generar; no representan el estado histórico del periodo ni cambian al filtrar resultados. '.(!empty($o['disponible'])?'Contactos programados: '.(int)$o['programadas'].' · De ellos, vencidos: '.(int)$o['vencidas'].' · Devoluciones: '.(int)$o['devoluciones']:'Organización no disponible con la estructura instalada.').'.</p>';
        $html.=self::titulo('Detalle de llamadas').'<table class="table"><thead><tr>';
        foreach (['Fecha y hora','Asesor','Teléfono','Tipo','Resultado','Duración'] as $h) $html.='<th>'.$e($h).'</th>';
        $html.='</tr></thead><tbody>';
        foreach ($d['llamadas'] as $c) {
            $html.='<tr>';
            foreach ([$c['fecha'],$c['asesor'],$c['numero'],$c['direccion'],
                $c['pbx_call_id']===''?'Sin confirmar':(TelefoniaResultadoVentasService::opciones()[$c['registro']['resultado']??'']??'Sin resultado registrado'),$time($c['segundos'])] as $v)
                $html.='<td>'.$e($v).'</td>';
            $html.='</tr>';
            if (trim($c['registro']['observacion']??'')!=='') $html.='<tr class="call-notes"><td colspan="6">Observaciones ('.$e($c['fecha']).'): '.$e($c['registro']['observacion']).'</td></tr>';
        }
        if (!$d['llamadas']) $html.='<tr><td colspan="6">Sin registros en este periodo.</td></tr>';
        $html.='</tbody></table>';
        return $html.'<p class="notice">Una llamada registrada no acredita conversación con una persona. Las conversaciones se clasifican con el resultado del asesor; la tasa de contacto requiere además conexión confirmada. El tiempo corresponde únicamente al asesor. Las duraciones pendientes no se consideran cero ni entran en el promedio. Los intentos sin confirmar se muestran aparte. No se asignan calificaciones de ventas.</p></body></html>';
    }
}
