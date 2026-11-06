<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/services/FormularioEjecutivasValidacion.php';

function exige(bool $condicion, string $mensaje): void {
    if (!$condicion) { throw new RuntimeException('FAIL Ejecutivas: ' . $mensaje); }
}

$perfiles = FormularioEjecutivasPerfiles::todos();
exige(count($perfiles) === 10, 'catálogo exacto de diez perfiles');
exige(count(array_unique($perfiles)) === 10, 'ningún perfil duplicado');
foreach ([
    'Ingeniería computacional A286',
    'Ingeniería industrial A286',
    'Licenciatura en administración A286',
    'Licenciatura en ciencias de la comunicación A286',
    'Licenciatura en ciencias políticas y administración pública A286',
    'Licenciatura en contaduría A286',
    'Licenciatura en derecho A286',
    'Licenciatura en educación preescolar A286',
    'Licenciatura en mercadotecnia A286',
    'Licenciatura en pedagogía A286'
] as $perfil) {
    exige(FormularioEjecutivasPerfiles::admite($perfil), 'perfil autorizado: '.$perfil);
}
exige(!FormularioEjecutivasPerfiles::admite('Inscripciones Abiertas'),
    'no permitir perfil genérico');
exige(!FormularioEjecutivasPerfiles::admite('Bachillerato 286'),
    'no mezclar programas');

$base = 'GARR000101HDFRRS0'; // CURP sintética, no representa persona real.
$curp = $base . FormularioEjecutivasValidacion::digitoVerificador($base);
exige(strlen($curp) === 18, '18 caracteres con dígito calculado');
exige(FormularioEjecutivasValidacion::errorCurp($curp,'2000-01-01','H') === null,
    'CURP sintética estructuralmente válida');
exige(FormularioEjecutivasValidacion::errorCurp($curp,'2000-01-02','H') !== null,
    'rechazar DOB discordante');
exige(FormularioEjecutivasValidacion::errorCurp($curp,'2000-01-01','M') !== null,
    'rechazar sexo discordante');
$digit = substr($curp,0,-1) . ((int)substr($curp,-1) + 1)%10;
exige(FormularioEjecutivasValidacion::errorCurp($digit,'2000-01-01','H') !== null,
    'rechazar dígito final erróneo');
exige(FormularioEjecutivasValidacion::errorCurp('ABC','2000-01-01','H') !== null,
    'rechazar CURP truncada');
$estado = substr_replace($curp,'ZZ',11,2);
exige(FormularioEjecutivasValidacion::errorCurp($estado,'2000-01-01','H') !== null,
    'rechazar entidad de nacimiento incorrecta');

$datos = [
    'nombre'=>'Roberto',
    'apellidos'=>'García Ruiz',
    'fecha_nacimiento'=>'2000-01-01',
    'genero'=>'H',
    'curp'=>$curp,
    'nombre_padres'=>'ANA GARCIA / CARLOS RUIZ',
    'correo'=>'persona@example.net',
    'correo_confirmacion'=>'persona@example.net',
    'movil'=>'5512345678',
    'movil_confirmacion'=>'5512345678',
    'calle'=>'Av. Principal',
    'numero'=>'S/N',
    'ciudad'=>'Cuernavaca',
    'codigo_postal'=>'62350',
    'municipio'=>'Cuernavaca',
    'entidad_federativa'=>'Morelos',
    'empresa_labora'=>'Empresa Ejemplo',
    'cargo_puesto'=>'Analista',
    'perfil_evaluacion'=>'Licenciatura en contaduría A286',
    'fecha_inscripcion'=>'2026-10-20'
];
$ok = FormularioEjecutivasValidacion::validar($datos);
exige($ok['ok'] === true, 'captura completa pasa validación');
exige($ok['datos_guardados'] === false, 'no simular guardado');
exige($ok['curp_verificada_en_renapo'] === false, 'no simular validación RENAPO');
exige(count($ok['avisos']) > 0, 'avisar los límites de la validación');

foreach ([
    ['nombre','Roberto123'],
    ['apellidos','R123'],
    ['curp',substr($curp,0,17).'X'],
    ['genero','M'],
    ['fecha_nacimiento','2000-01-02'],
    ['correo_confirmacion','distinto@example.net'],
    ['movil_confirmacion','5512345679'],
    ['codigo_postal','1234'],
    ['perfil_evaluacion','Licenciatura en medicina A286'],
    ['fecha_inscripcion','2099-01-01'],
    ['nombre_padres',''],
    ['empresa_labora','']
] as $caso) {
    [$clave,$valor] = $caso;
    $copia=$datos;
    $copia[$clave]=$valor;
    $error=FormularioEjecutivasValidacion::validar($copia);
    exige($error['ok']===false && isset($error['errores'][$clave]),
        'rechazar modificación incorrecta: '.$clave);
}

define('ROOT_PATH', dirname(__DIR__));
define('BASE_URL', '/');
$perfilesEjecutivas = $perfiles;
$csrfEjecutivas = 'TESTTOKEN';
ob_start();
try {
    require dirname(__DIR__) . '/app/views/formularios/inscripcion_ejecutivas_previa.php';
    $markup=(string)ob_get_clean();
} catch (Throwable $e) {
    ob_end_clean();
    throw $e;
}
exige(substr_count($markup,'<option value="Licenciatura en') === 8,
    'ocho licenciaturas y dos ingenierías');
exige(substr_count($markup,'<option value="Ingeniería') === 2,
    'dos ingenierías A286');
foreach (['red-graduacion.jpeg','Logotipos-blanco.png','Logo-Sep-Blanco.png',
    'logo-huston.png','formularios_hero_institucional.css','formularios_publico.css',
    'name="curp"','name="fecha_nacimiento"','name="perfil_evaluacion"',
    'name="fecha_inscripcion"','name="csrf_piloto"',
    'Términos y condiciones A286','Aviso de privacidad',
    'Validar datos de prueba (sin guardar)'] as $needle) {
    // La foto institucional está referenciada por el CSS compartido.
    $hay = $needle === 'red-graduacion.jpeg'
        ? strpos(file_get_contents(ROOT_PATH.'/public/css/formularios_hero_institucional.css'),$needle)!==false
        : strpos($markup,$needle)!==false;
    exige($hay,'vista piloto requiere '.$needle);
}
exige(strpos($markup,'action=guardarInscripcion')===false,
    'vista piloto no puede guardar en ruta pública heredada');
exige(strpos($markup,'name="comprobante_pago"')===false,
    'no crear campo de upload transmisible');
exige(strpos($markup,'ya-estas-inscrito')!==false,
    'mensaje explícito que el enlace público está deshabilitado');
$controller=file_get_contents(ROOT_PATH.'/app/controllers/FormularioController.php');
exige(strpos($controller,'public function previsualizarEjecutivas()')!==false,
    'vista con autenticación Marketing');
exige(strpos($controller,'public function validarEjecutivas()')!==false,
    'endpoint de solo validación');
exige(strpos($controller,'hash_equals($esperado, $enviado)')!==false,
    'protección contra CSRF');

echo "PASS Ejecutivas: diez perfiles, CURP, fecha, sexo, nombre, contacto, vista y seguridad sin persistencia\n";
