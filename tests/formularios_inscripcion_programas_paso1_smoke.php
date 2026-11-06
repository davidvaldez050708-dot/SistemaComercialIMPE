<?php
declare(strict_types=1);

/**
 * Paso 1 del nuevo módulo Inscripción: sin conexión a la base de datos.
 * La prueba NO crea inscripciones ni habilita URLs públicas.
 */
require_once dirname(__DIR__) . '/app/services/FormularioInscripcionProgramas.php';

function comprobar($condicion, string $mensaje): void
{
    if (!$condicion) {
        throw new RuntimeException('FAIL inscripción programas: ' . $mensaje);
    }
}

$programas = FormularioInscripcionProgramas::todos();
$esperados = [
    'bachillerato-en-2-anos' => 'Bachillerato en 2 años',
    'bachillerato-286' => 'Bachillerato General',
    'ingles' => 'Inglés',
    'titulacion-ejecutivas' => 'Titulación - Ejecutivas',
    'titulacion-experiencia-laboral' => 'Titulación por experiencia laboral'
];
comprobar(count($programas) === 5, 'exactamente cinco programas');
comprobar(array_column($programas, 'nombre', 'slug') === $esperados, 'catálogo estable');
comprobar(FormularioInscripcionProgramas::porSlug('inscripciones-abiertas') === null,
    'ningún sexto programa genérico');
comprobar(FormularioInscripcionProgramas::porSlug('../index.php') === null, 'slug inseguro rechazado');
comprobar(FormularioInscripcionProgramas::porSlug('ingles&aliado=x') === null, 'query falsa rechazada');
foreach ($esperados as $slug => $nombre) {
    comprobar(FormularioInscripcionProgramas::porSlug($slug)['nombre'] === $nombre,
        'resolver programa ' . $slug);
}

if (!defined('BASE_URL')) {
    define('BASE_URL', 'https://example.invalid/');
}

$render = static function (string $view, array $args): string {
    extract($args, EXTR_SKIP);
    ob_start();
    try {
        include dirname(__DIR__) . '/app/views/formularios/' . $view;
        return (string)ob_get_clean();
    } catch (Throwable $error) {
        ob_end_clean();
        throw $error;
    }
};

$catalogo = $render('inscripcion_programas.php', ['programasInscripcion'=>$programas]);
comprobar(substr_count($catalogo, '<article class="dashboard-panel formularios-inscripcion-programa">') === 5,
    'cinco tarjetas visibles');
foreach ($esperados as $slug => $nombre) {
    comprobar(strpos($catalogo, 'programa=' . $slug) !== false, 'enlace interno ' . $slug);
    comprobar(strpos($catalogo, htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8')) !== false,
        'nombre visible ' . $slug);
}
comprobar(strpos($catalogo, 'Formulario por programa') !== false,
    'se identifica el formulario por programa');
comprobar(strpos($catalogo, 'controller=formularioPublico') === false,
    'no distribuir enlaces públicos prematuros');
comprobar(strpos($catalogo, '<form') === false,
    'la vista inicial no muestra el formulario único antiguo');

$formulario = $render('inscripcion.php', [
    'estados'=>[],
    'perfilesInteres'=>['Inglés'],
    'programaInscripcion'=>FormularioInscripcionProgramas::porSlug('ingles')
]);
comprobar(strpos($formulario, 'name="programa_slug"') !== false, 'slug de programa en POST');
comprobar(strpos($formulario, 'value="ingles"') !== false, 'programa correcto en POST');
comprobar(strpos($formulario, 'type="hidden" name="perfil_interes"') !== false,
    'el valor de perfil se envía mediante input oculto');
comprobar(strpos($formulario, 'data-registro-perfil') !== false, 'JS existente conserva validación');
comprobar((bool)preg_match('/id="form_registro_perfil"\s+disabled/', $formulario),
    'selector de programa visible pero bloqueado');
comprobar(strpos($formulario, 'Volver a programas') !== false,
    'volver a catálogo de cinco programas');

$controller = file_get_contents(dirname(__DIR__) . '/app/controllers/FormularioController.php');
comprobar(strpos($controller, 'FormularioInscripcionProgramas::porSlug($slug)') !== false,
    'GET valido por slug cerrado');
comprobar(strpos($controller, 'FormularioInscripcionProgramas::porSlug($slugInscripcion)') !== false,
    'POST verifica programa y perfil');
comprobar(strpos($controller, "require __DIR__ . '/../views/formularios/inscripcion_programas.php'") !== false,
    'dashboard presenta selector inicial');

echo "PASS inscripción paso 1: cinco programas, vista interna y enlaces públicos no habilitados\n";
