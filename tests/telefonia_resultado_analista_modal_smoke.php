<?php
declare(strict_types=1);

// No requiere BD ni navegador: valida el contrato HTML/CSS del formulario.
// En el pasado, <form> interrumpía la cadena de scroll de Bootstrap
// y ocultaba permanentemente "Guardar resultado" por debajo del viewport.
$root = dirname(__DIR__);
$view = file_get_contents($root . '/app/views/seguimiento_vinculacion/llamadas_entrantes.php').file_get_contents($root . '/app/views/telefonia/resultado_analista_form.php');
$css = file_get_contents($root . '/public/css/seguimiento_entrantes_analista.css');
$assert = static function (bool $ok, string $message): void {
    if (!$ok) {
        fwrite(STDERR, "FALLO: {$message}\n");
        exit(1);
    }
};
$assert(is_string($view) && is_string($css), 'No se leyeron los archivos.');
$start = strpos($view, '<form data-incoming-form class="linkage-incoming-form">');
$body = strpos($view, 'data-incoming-scroll-region');
$footer = strpos($view, 'class="modal-footer system-form-modal-footer linkage-incoming-form-footer"');
$save = strpos($view, 'data-incoming-submit');
$end = strpos($view, '</form>', $start === false ? 0 : $start);
$assert($start !== false && $body !== false && $footer !== false &&
    $save !== false && $end !== false &&
    $start < $body && $body < $footer && $footer < $save && $save < $end,
    'Guardar debe permanecer en el footer del formulario desplazable.');
$assert(str_contains($view, 'data-incoming-sdk-notice') &&
    !str_contains($view, 'Registro de llamada WebRTC') &&
    !str_contains($view, 'class="alert alert-info mt-3 mb-0"'),
    'No debe reaparecer el aviso WebRTC grande en el modal.');
$assert(str_contains($view, 'data-incoming-sdk-confirm') &&
    str_contains($view, 'name="confirmacion_manual_sdk"') &&
    str_contains($view, 'data-incoming-pbx') &&
    str_contains($view, 'data-incoming-sdk-id'),
    'Se perdió la distinción PBX/WebRTC o la confirmación manual.');

$rule = static function (string $selector) use ($css): string {
    $offset = strpos($css, $selector . ' {');
    if ($offset === false) {
        // El componente comparte exactamente las mismas reglas con el formulario comercial.
        $shared=str_replace('#modalLlamadaEntranteAnalista',':is(#modalLlamadaEntranteAnalista,#modalResultadoVenta)',$selector);
        $offset=strpos($css,$shared.' {');
    }
    if ($offset === false) return '';
    $end = strpos($css, '}', $offset);
    return $end === false ? '' : substr($css, $offset, $end - $offset);
};
$formCss = $rule('#modalLlamadaEntranteAnalista .linkage-incoming-form');
$bodyCss = $rule('#modalLlamadaEntranteAnalista .linkage-incoming-form > .modal-body');
$footerCss = $rule('#modalLlamadaEntranteAnalista .linkage-incoming-form-footer');
$contentCss = $rule('#modalLlamadaEntranteAnalista .modal-content');
$noticeCss = $rule('#modalLlamadaEntranteAnalista .linkage-incoming-sdk-notice');
$assert(str_contains($formCss, 'display: flex;') &&
    str_contains($formCss, 'flex-direction: column;') &&
    str_contains($formCss, 'min-height: 0;'),
    'La etiqueta form debe participar en la estructura flexible del modal.');
$assert(str_contains($bodyCss, 'overflow-y: auto;') &&
    str_contains($bodyCss, 'min-height: 0;') &&
    str_contains($bodyCss, 'max-height:'),
    'El contenido del formulario debe hacer scroll interno.');
$assert(str_contains($footerCss, 'flex: 0 0 auto;') &&
    str_contains($footerCss, 'background: #fff;'),
    'Guardar/Cancelar deben permanecer visibles fuera del área de scroll.');
$assert(str_contains($contentCss, 'overflow: hidden;') &&
    str_contains($contentCss, 'max-height:'),
    'El modal debe respetar la altura de la ventana.');
$assert(str_contains($css, '[data-incoming-sdk-notice]') &&
    str_contains($view, 'data-incoming-confirmed-name') &&
    str_contains($view, 'data-incoming-change-expediente') &&
    str_contains($view, 'data-current-user') &&
    str_contains($css, '@media (max-width: 575.98px)'),
    'La precarga institucional y la adaptación móvil deben conservar el diseño CRM.');
echo "Formulario Entrantes Analista: OK (Guardar, scroll, identificación de Recepción y UI compacta)\n";
