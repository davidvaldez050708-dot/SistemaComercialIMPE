<?php
/**
 * Resuelve las siluetas PNG usadas en Seguimiento e Información Territorial.
 * Retorna solo rutas locales verificadas, nunca archivos subidos por usuarios.
 */
if (!function_exists('impeRutaMapaEstado')) {
    function impeRutaMapaEstado(string $nombreEstado): string
    {
        $nombreEstado = trim($nombreEstado);
        if ($nombreEstado === '') {
            return '';
        }

        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT', $nombreEstado);
        if ($ascii === false) {
            return '';
        }

        $slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)), '-');
        // Nombre corto -> ruta utilizada por las tarjetas de Seguimiento.
        $alias = [
            'q-roo' => 'quintana-roo',
            'qroo' => 'quintana-roo',
            'cdmx' => 'ciudad-de-mexico',
            'edomex' => 'estado-de-mexico',
            'edo-mex' => 'estado-de-mexico',
            'n-l' => 'nuevo-leon',
            'slp' => 'san-luis-potosi',
            's-l-p' => 'san-luis-potosi',
            'bcs' => 'baja-california-sur',
            'bc' => 'baja-california',
            'qro' => 'queretaro',
            'michoacan' => 'michoacán',
        ];
        $slug = $alias[$slug] ?? $slug;
        if ($slug === '') {
            return '';
        }

        $ruta = 'public/img/estados/' . $slug . '.png';
        return is_file(dirname(__DIR__, 2) . '/' . $ruta) ? $ruta : '';
    }
}
