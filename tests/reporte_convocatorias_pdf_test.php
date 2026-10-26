<?php
set_error_handler(static function ($severity, $message, $file, $line) { if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line); return false; });
// Sólo fixtures locales; no carga modelos ni consulta la base de datos.
require_once __DIR__ . '/../app/services/ReporteConvocatoriaPdfService.php';
function datosConvocatoriasPdfPrueba(int $cantidad): array
{
    $detalle = [];
    for ($i = 1; $i <= $cantidad; $i++) {
        $detalle[] = [
            'id' => $i, 'titulo' => sprintf('Convocatoria %03d · Educación, niñez & México', $i),
            'tipo_convocatoria' => $i % 2 ? 'titulacion' : 'bachillerato',
            'subtipo_convocatoria' => $i % 2 ? 'experiencia-laboral' : 'bachillerato-286',
            'fecha_inicio' => '2026-10-01', 'fecha_termino' => '2026-12-31',
            'created_at' => '2026-10-23 12:00:00', 'estados' => 'Guanajuato, Sinaloa, Ciudad de México',
            'estado_proceso' => $i % 3 ? 'activa' : 'inactiva'
        ];
    }
    if ($cantidad) {
        $detalle[0]['titulo'] .= ' — Inglés, acción, Ñandú, € y <etiqueta> ' . str_repeat('Programa académico ', 8);
    }
    return [
        'fecha_generacion' => '23/10/2026 14:30', 'generado_por' => 'María Núñez & José', 'generado_por_rol' => 'Marketing',
        'filtros' => ['tipo' => '', 'subtipo' => ''],
        'resumen' => ['total' => $cantidad, 'activas' => $cantidad ? $cantidad - intdiv($cantidad, 3) : 0, 'inactivas' => intdiv($cantidad, 3), 'vigentes' => $cantidad ? $cantidad - intdiv($cantidad, 3) : 0, 'publicaciones_mes_actual' => $cantidad, 'publicaciones_hoy' => $cantidad, 'vencen_hoy' => 0, 'vencen_2_dias' => 1, 'porcentaje_cobertura' => 9],
        'por_tipo' => ['bachillerato' => ['meses' => [['label' => 'Ago', 'total' => 0], ['label' => 'Sep', 'total' => 2], ['label' => 'Oct', 'total' => 7]]], 'titulacion' => ['meses' => [['label' => 'Ago', 'total' => 1], ['label' => 'Sep', 'total' => 5], ['label' => 'Oct', 'total' => 3]]]],
        'publicaciones_por_usuario' => [['nombre' => 'María', 'apellidos' => 'Núñez & José', 'rol' => 'Marketing', 'total_publicaciones' => $cantidad, 'activas' => $cantidad - intdiv($cantidad, 3), 'inactivas' => intdiv($cantidad, 3), 'ultima_publicacion' => '2026-10-23']],
        'hallazgos' => ['El corte conserva los totales recibidos, sin recalcular convocatorias por sus múltiples estados.', 'Acentos: Educación, Titulación, ñ, Ñ y símbolos & < > €.'],
        'alertas_vencimiento' => [['titulo' => 'Alerta técnica de vencimiento', 'tipo_convocatoria' => 'titulacion', 'estados' => 'Sinaloa', 'fecha_termino' => '2026-10-24', 'dias_restantes' => 1]],
        'cobertura' => ['territorios' => [['nombre' => 'Guanajuato', 'convocatorias_activas' => 3], ['nombre' => 'Sinaloa', 'convocatorias_activas' => 1], ['nombre' => 'Ciudad de México', 'convocatorias_activas' => 1]]],
        'detalle' => $detalle
    ];
}
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $root = dirname(__DIR__);
    $out = $root . '/tmp/pdfs';
    if (!is_dir($out)) mkdir($out, 0700, true);
    $hashes = [];
    foreach (['app/services/OficioDocxPdfService.php','app/services/OficioPdfService.php','app/services/ReporteAliadosPdfService.php','app/services/DesempenoPdfService.php','app/services/ReporteTerritorialPdfService.php','storage/templates/reportes/seguimiento_vinculacion/Plantilla_Reporte_Seguimiento.docx'] as $file) $hashes[$file] = hash_file('sha256', $root . '/' . $file);
    foreach (['vacio' => [], 'datos' => datosConvocatoriasPdfPrueba(4), 'volumen' => datosConvocatoriasPdfPrueba(160)] as $case => $data) {
        $r = (new ReporteConvocatoriaPdfService())->generar($data);
        if (!($r['ok'] ?? false)) throw new RuntimeException(json_encode($r));
        if (substr($r['contenido_pdf'], 0, 5) !== '%PDF-') throw new RuntimeException('PDF inválido');
        if ($r['conversor'] !== 'DOMPDF_PHP') throw new RuntimeException('Motor inesperado');
        file_put_contents($out . '/convocatorias-' . $case . '.pdf', $r['contenido_pdf']);
        echo $case . ': PDF válido, ' . strlen($r['contenido_pdf']) . " bytes\n";
    }
    // Las rutas compartidas permanecen idénticas y el renderer ajeno sigue generando.
    require_once $root . '/app/services/DesempenoPdfService.php';
    $other = (new DesempenoPdfService())->generar([]);
    if (!($other['ok'] ?? false) || substr($other['contenido_pdf'], 0, 5) !== '%PDF-') throw new RuntimeException('Regresión de exportador ajeno');
    foreach ($hashes as $file => $hash) if (!hash_equals($hash, hash_file('sha256', $root . '/' . $file))) throw new RuntimeException('Archivo protegido modificado: ' . $file);
    // Un recurso ausente produce un error controlado, sin rutas en el mensaje público.
    $service = new ReporteConvocatoriaPdfService();
    $property = new ReflectionProperty($service, 'templatePath');
    $property->setAccessible(true);$property->setValue($service, $out . '/no-existe.docx');
    $r = $service->generar([]);
    if ($r['ok'] || strpos($r['mensaje'], $root) !== false) throw new RuntimeException('Error público no controlado');
    echo "OK: cero comandos externos, sin BD; plantilla/oficios/otros reportes idénticos y PDF de desempeño válido.\n";
}
