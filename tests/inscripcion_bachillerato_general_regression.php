<?php
declare(strict_types=1);

/** Regresión sin SQL ni datos personales reales: Bachillerato General, Examen Único A286. */
require_once dirname(__DIR__) . '/app/services/FormularioInscripcionValidacion.php';
function exigirBach(bool $ok, string $descripcion): void
{
    if (!$ok) throw new RuntimeException('ERROR Bachillerato General: ' . $descripcion);
}
$raiz = dirname(__DIR__);
$bach = 'bachillerato-286';
$legalBach = FormularioInscripcionValidacion::legales($bach);
$legalEjecutivas = FormularioInscripcionValidacion::legales('titulacion-ejecutivas');
$legalExperiencia = FormularioInscripcionValidacion::legales('titulacion-experiencia-laboral');

exigirBach($legalBach['terminos'] !== '' && $legalBach['privacidad'] !== '',
    'documentos originales completos');
exigirBach(strpos($legalBach['terminos'],'obtención del Certificado de Bachillerato') !== false,
    'cláusula específica de certificado');
exigirBach(strpos($legalBach['terminos'],'Educación Medio Superior') !== false,
    'naturaleza medio superior del contrato');
exigirBach(strpos($legalBach['terminos'],'Teopanzolco #98') !== false,
    'conservar domicilio original del contrato, sin corregirlo arbitrariamente');
exigirBach(strpos($legalBach['privacidad'],'Teopanzolco No. 98') !== false,
    'conservar domicilio original del aviso de Bachillerato');
exigirBach(strpos($legalBach['terminos'],'obtención del Título profesional') === false,
    'no mostrar el contrato de titulación por error');
exigirBach($legalBach['terminos'] !== $legalEjecutivas['terminos'],
    'contrato de Bachillerato diferente al de Titulación');
exigirBach($legalBach['privacidad'] !== $legalEjecutivas['privacidad'],
    'aviso original distinto del aviso de Titulación');
exigirBach($legalExperiencia['terminos'] === $legalEjecutivas['terminos'] &&
    $legalExperiencia['privacidad'] === $legalEjecutivas['privacidad'],
    'otros programas de titulación intactos');
exigirBach(hash_file('sha256',$raiz.'/app/resources/inscripcion/terminos-bachillerato-general.txt')
    === hash('sha256',$legalBach['terminos']), 'contrato íntegro');
exigirBach(hash_file('sha256',$raiz.'/app/resources/inscripcion/privacidad-bachillerato-general.txt')
    === hash('sha256',$legalBach['privacidad']), 'aviso íntegro');
foreach (['ingles','bachillerato-en-2-anos'] as $slug) {
    exigirBach(FormularioInscripcionValidacion::legales($slug)['terminos'] === '',
        'no habilitar otros programas: '.$slug);
}
exigirBach(FormularioInscripcionValidacion::perfiles($bach) === [],
    'Bachillerato General no posee perfil de evaluación');
exigirBach(count(FormularioInscripcionValidacion::perfiles('titulacion-ejecutivas')) === 10,
    'diez perfiles Ejecutivas intactos');
exigirBach(count(FormularioInscripcionValidacion::perfiles('titulacion-experiencia-laboral')) === 8,
    'ocho perfiles Experiencia intactos');

$prefijo='GARR000101HDFRRSA'; // Sintético, no corresponde a persona real.
$curp=$prefijo.FormularioEjecutivasValidacion::digitoVerificador($prefijo);
$datos=[
    'nombre'=>'Roberto','apellidos'=>'García Ruiz','fecha_nacimiento'=>'2000-01-01',
    'genero'=>'H','curp'=>$curp,'nombre_padres'=>'ANA GARCIA / CARLOS RUIZ',
    'correo'=>'persona@example.test','correo_confirmacion'=>'persona@example.test',
    'movil'=>'5512345678','movil_confirmacion'=>'5512345678','calle'=>'Avenida Principal',
    'numero'=>'S/N','ciudad'=>'Cuernavaca','codigo_postal'=>'62350',
    'municipio'=>'Cuernavaca','entidad_federativa'=>'Morelos',
    'empresa_labora'=>'Empresa de ejemplo','cargo_puesto'=>'Analista',
    'fecha_inscripcion'=>'2026-10-20',
    'confirmacion_identidad'=>'1','acepta_terminos'=>'1','acepta_privacidad'=>'1'
];
$validacion = FormularioInscripcionValidacion::validar($datos, $bach);
exigirBach($validacion['ok'] === true, 'inscripción sintética sin campo perfil aceptada');
foreach ([
    ['curp','INVALIDA'], ['correo_confirmacion','otro@example.test'],
    ['confirmacion_identidad','0'],['acepta_privacidad','0'],
    ['acepta_terminos','0']
] as $caso) {
    [$campo,$valor]=$caso;
    $copia=$datos; $copia[$campo]=$valor;
    $fallo=FormularioInscripcionValidacion::validar($copia,$bach);
    exigirBach(!$fallo['ok'] && isset($fallo['errores'][$campo]),
        'rechazar dato discordante: '.$campo);
}
$copia=$datos;$copia['perfil_evaluacion']='Licenciatura en contaduría A286';
$fallo=FormularioInscripcionValidacion::validar($copia,$bach);
exigirBach(!$fallo['ok'] && isset($fallo['errores']['perfil_evaluacion']),
    'rechazar inyección de perfil ajeno a Bachillerato');
$copia=$datos;
$fallo=FormularioInscripcionValidacion::validar($copia,'titulacion-ejecutivas');
exigirBach(!$fallo['ok'] && isset($fallo['errores']['perfil_evaluacion']),
    'Titulación conserva perfil obligatorio');

if (!defined('ROOT_PATH')) define('ROOT_PATH',$raiz);
if (!defined('BASE_URL')) define('BASE_URL','/');

$render = static function (string $slug) use ($raiz): string {
    $programaInscripcion = FormularioInscripcionProgramas::porSlug($slug);
    $estados=[['id'=>1,'nombre'=>'Morelos']];
    $inscripcionPublica = true;
    $inscripcionRecepcionLista = true;
    $inscripcionToken = 'TOKEN_SINTETICO';
    $inscripcionAction = '/ya-estas-inscrito/?accion=guardar';
    $inscripcionMunicipiosUrl = '/ya-estas-inscrito/?accion=municipios';
    $_SESSION['csrf_inscripcion_completa'] = 'CSRF_SINTETICO';
    ob_start();
    try {
        require $raiz.'/app/views/formularios/inscripcion_completa.php';
        return (string)ob_get_clean();
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    }
};
$markup = $render($bach);
exigirBach(strpos($markup,'name="perfil_evaluacion"') === false,
    'vista pública sin campo ni selector de perfil');
exigirBach(strpos($markup,'Perfil de evaluación') === false,
    'no mostrar etiqueta de perfil');
exigirBach(strpos($markup,'Información laboral</h4>') !== false,
    'sección laboral sin perfil');
exigirBach(strpos($markup,'name="apertura"') === false, 'sin Apertura');
exigirBach(strpos($markup,'Formulario en preparación') === false,
    'no mostrar preparación si los textos existen');
exigirBach(strpos($markup,'Pendiente de confirmar el documento') === false,
    'no mostrar texto legal pendiente');
exigirBach(strpos($markup,'type="submit" disabled') === false,
    'envío habilitado al existir esquema y contratos');
exigirBach(strpos($markup,'name="comprobante"') !== false &&
    strpos($markup,'name="fecha_inscripcion"') !== false,
    'comprobante y fecha de inscripción permanecen');
exigirBach(strpos($markup,'name="acepta_terminos"') !== false &&
    strpos($markup,'name="acepta_privacidad"') !== false,
    'consentimientos independientes intactos');
$markupEjecutivas=$render('titulacion-ejecutivas');
exigirBach(strpos($markupEjecutivas,'name="perfil_evaluacion"') !== false,
    'Titulación sigue mostrando perfil');
$markupIngles=$render('ingles');
exigirBach(strpos($markupIngles,'Formulario en preparación') !== false,
    'Inglés continúa sin habilitarse');
$hero=file_get_contents($raiz.'/app/views/formularios/publico_inscripcion_completa.php');
exigirBach(strpos($hero,'Examen Único · Acuerdo 286') !== false,
    'encabezado público identifica el examen único sin tocar imágenes');
exigirBach(strpos($hero,'Logotipos-blanco.png')!==false &&
    strpos($hero,'Logo-Sep-Blanco.png')!==false, 'imágenes del encabezado conservadas');
echo "PASS Bachillerato General: originales legales, sin perfil, CURP, PDF, consentimiento, otros programas intactos\n";
