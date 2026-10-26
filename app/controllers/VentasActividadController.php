<?php
require_once __DIR__.'/../services/VentasActividadService.php';
require_once __DIR__.'/../services/DesempenoPdfService.php';
require_once __DIR__.'/../services/VentasDesempenoService.php';

/** Entradas públicas delegadas desde Reportes y Desempeño; mismo alcance y datos para HTML/PDF. */
final class VentasActividadController
{
    public function mostrar(bool $desempeno = false, bool $pdf = false): void
    {
        if (VentasActividadAccess::alcance($desempeno)==='' || ($pdf && !tienePermiso($desempeno?'desempeno.exportar':'reportes.exportar'))) {
            http_response_code(403); echo 'No tienes permiso para consultar o exportar esta actividad.'; return;
        }
        header('Cache-Control: private, no-store, max-age=0');
        $actividad=[];$errorActividad='';$urlActividadPdf='';
        try {
            $actividad=$desempeno?(new VentasDesempenoService())->preparar($_GET):(new VentasActividadService())->preparar($_GET,false,$pdf||($_GET['generar']??'')==='1');
            if ($pdf) {
                if (!$desempeno && count($actividad['llamadas'])>2000) throw new InvalidArgumentException('El PDF admite hasta 2,000 registros completos. Selecciona un asesor o reduce el periodo para exportarlo.');
                $result=(new DesempenoPdfService())->generar($actividad);
                if (empty($result['ok'])) throw new RuntimeException((string)($result['mensaje_tecnico']??'PDF no disponible.'));
                header('Content-Type: application/pdf');header('X-Content-Type-Options: nosniff');
                header('Content-Disposition: attachment; filename="'.$result['nombre_archivo'].'"');
                echo $result['contenido_pdf']; return;
            }
            if (tienePermiso($desempeno?'desempeno.exportar':'reportes.exportar') && $actividad['generado']) {
                $params=['controller'=>$desempeno?'desempeno':'reporte','action'=>$desempeno?'exportarPdf':'ventasPdf','area'=>'asesores',
                    'periodo'=>$actividad['periodo']['clave'],'fecha_desde'=>$actividad['periodo']['fecha_desde'],
                    'fecha_hasta'=>$actividad['periodo']['fecha_hasta'],'persona_id'=>$actividad['persona_id'],
                    'direccion'=>$actividad['direccion'],'resultado'=>$actividad['resultado']];
                $urlActividadPdf=BASE_URL.'index.php?'.http_build_query($params);
            }
        } catch (DomainException $e) { http_response_code(403); echo htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8'); return; }
        catch (InvalidArgumentException $e) { http_response_code(400); $errorActividad=$e->getMessage(); }
        catch (Throwable $e) { error_log('[actividad_asesores] '.get_class($e).': '.$e->getMessage()); http_response_code(503);$errorActividad='No fue posible preparar la actividad. Intenta nuevamente o verifica la instalación del módulo.'; }
        if ($pdf) { header('Content-Type: text/plain; charset=utf-8');echo $errorActividad;return; }
        $tituloPagina=$desempeno?'Desempeño':'Reportes';$subtituloPagina='Actividad telefónica de asesores';
        $opcionActiva=$desempeno?'desempeno':'reportes';
        require __DIR__.'/../views/layout/dashboard_head.php';
        require __DIR__.'/../views/layout/sidebar.php';
        require __DIR__.'/../views/layout/topbar.php';
        if ($desempeno) {
            $desempeno=$actividad;$urlExportarPdf=$urlActividadPdf;$errorDesempeno=$errorActividad;
            require __DIR__.'/../views/desempeno/index.php';
        } else require __DIR__.'/../views/reportes/ventas_actividad.php';
        require __DIR__.'/../views/layout/dashboard_footer.php';
    }
}
