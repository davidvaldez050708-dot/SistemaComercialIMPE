<?php
require_once __DIR__.'/VentasActividadMetricas.php';
require_once __DIR__.'/VentasActividadPdfView.php';

/** El mismo corte y orden del ranking, con la hoja y los estilos del analista. */
final class VentasDesempenoPdfView
{
    private static function e($v): string { return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
    private static function titulo(string $v): string { return '<div class="section-title"><h2>'.self::e($v).'</h2></div>'; }
    private static function tabla(array $headers,array $rows): string
    {
        $html='<table class="table"><thead><tr>';
        foreach ($headers as $v) $html.='<th>'.self::e($v).'</th>';
        $html.='</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html.='<tr>';foreach ($row as $v) $html.='<td>'.self::e($v).'</td>';$html.='</tr>';
        }
        if (!$rows) $html.='<tr><td colspan="'.count($headers).'">Sin registros en este periodo.</td></tr>';
        return $html.'</tbody></table>';
    }
    private static function porcentaje($v): string { return $v===null?'—':number_format($v,1).'%'; }
    public function html(array $d,string $logo,string $css): string
    {
        $e=[self::class,'e'];$s=$d['resumen'];$ranking=$d['ranking'];
        $comparativo=count($ranking)>1 && $d['vista']!=='propio';
        $html='<!doctype html><html lang="es"><meta charset="utf-8"><style>'.$css.'.table{table-layout:fixed;font-size:6.7pt}.table td,.table th{word-wrap:break-word}.performance-comparison{width:100%;table-layout:fixed;border-collapse:collapse;margin-bottom:6px}.performance-comparison td{padding:5px;vertical-align:middle;font-size:7pt}.performance-comparison .person{width:35%}.performance-comparison .score{width:12%;text-align:right}.score-track{background:#EDF1F7;height:10px}.score-fill{height:10px;background:#273A8A}</style><body><div class="top-rule"></div><table class="header"><tr><td class="brand">';
        if ($logo!=='') $html.='<img src="'.$e($logo).'" alt="Grupo Porcayo">';
        $html.='</td><td class="header-copy"><div class="system-name">Sistema de Gestión Comercial</div><h1>Desempeño de asesores</h1><table class="header-meta">';
        foreach (['Generado por'=>$d['generado_por'],'Rol'=>$d['generado_por_rol'],'Generado el'=>$d['fecha_generacion']] as $k=>$v)
            $html.='<tr><td>'.$e($k).'</td><th>'.$e($v).'</th></tr>';
        $html.='</table></td></tr></table><table class="scope"><tr>';
        foreach (['Área'=>'Asesores','Alcance'=>['propio'=>'Personal','equipo'=>'Mi equipo','global'=>'Nacional'][$d['vista']],
            'Periodo'=>$d['periodo']['fecha_desde'].' al '.$d['periodo']['fecha_hasta'],'Participantes'=>count($ranking)] as $k=>$v)
            $html.='<td><span>'.$e($k).'</span><strong>'.$e($v).'</strong></td>';
        $html.='</tr></table>'.self::titulo('Resumen del periodo').'<table class="metrics"><tr>';
        foreach (['Llamadas registradas'=>$s['llamadas'],'Contacto confirmado'=>$s['conversaciones_confirmadas'],
            'Tasa de contacto'=>self::porcentaje($s['tasa_contacto']),'Cumplimiento del registro'=>self::porcentaje($s['cumplimiento_registro'])] as $k=>$v)
            $html.='<td><span>'.$e($k).'</span><strong>'.$e($v).'</strong></td>';
        $html.='</tr></table><p class="notice">Tiempo confirmado del asesor: '.$e(VentasActividadMetricas::tiempoConfirmado($s)).
            ' · Duraciones pendientes: '.(int)$s['duraciones_pendientes'].' · Llamadas por registrar: '.(int)$s['por_registrar'].
            ' · Intentos sin confirmar: '.(int)$s['intentos'].' · Transferencias sin confirmar: '.(int)$s['transferencias_pendientes'].'.</p>';
        $html.=self::titulo($comparativo?'Ranking del periodo':'Desempeño en el periodo');$rows=[];
        foreach ($ranking as $p) $rows[]=[($comparativo?$p['posicion'].'. ':'').$p['nombre_completo'],$p['llamadas'],$p['conversaciones_confirmadas'],
            self::porcentaje($p['tasa_contacto']),self::porcentaje($p['cumplimiento_registro']),VentasActividadMetricas::tiempoConfirmado($p),
            $p['indice']===null?'—':number_format($p['indice'],1)];
        $html.=self::tabla(['Asesor','Llamadas','Con contacto','Tasa contacto','Registro','Tiempo','Índice'],$rows);
        if ($comparativo) {
            $html.=self::titulo('Gráfica comparativa del ranking').'<p class="notice">Mismo índice y orden del ranking. Escala de 0 a 100.</p>';
            foreach ($ranking as $p) $html.='<table class="performance-comparison"><tr><td class="person">'.(int)$p['posicion'].'. '.$e($p['nombre_completo']).
                '</td><td><div class="score-track"><div class="score-fill" style="width:'.number_format((float)$p['indice'],1,'.','').'%"></div></div></td><td class="score">'.number_format((float)$p['indice'],1).'</td></tr></table>';
        }
        if ($d['reconocimientos']) {
            $html.=self::titulo('Destacados del periodo');$rows=[];
            foreach ($d['reconocimientos'] as $a) $rows[]=[$a['titulo'],$a['nombre'],$a['valor'].' '.$a['unidad']];
            $html.=self::tabla(['Reconocimiento','Asesor','Resultado'],$rows);
        }
        $html.=self::titulo('Historial diario');$rows=[];
        foreach ($d['dias'] as $day) $rows[]=[$day['fecha'],$day['llamadas'],$day['conversaciones_confirmadas'],$day['registradas']];
        $html.=self::tabla(['Fecha','Llamadas','Contactos confirmados','Resultados registrados'],$rows);
        $html.=VentasActividadPdfView::resultados($d);
        $html.=VentasActividadPdfView::pendientes($d);
        $html.=self::titulo('Criterios de medición');
        foreach ($d['criterios'] as $criterion) $html.='<p class="notice">'.$e($criterion).'</p>';
        return $html.'</body></html>';
    }
}
