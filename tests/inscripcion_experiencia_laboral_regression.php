<?php
declare(strict_types=1);

/**
 * Regresión sin SQL ni personas reales.
 * La activación de Experiencia Laboral reutiliza el contrato confirmado A286
 * y el aviso existente, sin abrir los otros programas.
 */
require_once dirname(__DIR__) . '/app/services/FormularioInscripcionValidacion.php';

function verificarExperiencia(bool $condicion, string $mensaje): void
{
    if (!$condicion) {
        throw new RuntimeException('ERROR Experiencia Laboral: ' . $mensaje);
    }
}

$ejecutivas = FormularioInscripcionValidacion::legales('titulacion-ejecutivas');
$experiencia = FormularioInscripcionValidacion::legales('titulacion-experiencia-laboral');
verificarExperiencia($experiencia['terminos'] !== '', 'contrato no vacío');
verificarExperiencia($ejecutivas['terminos'] === $experiencia['terminos'],
    'mismo texto íntegro del contrato A286 confirmado');
verificarExperiencia($experiencia['privacidad'] !== '' &&
    $ejecutivas['privacidad'] === $experiencia['privacidad'],
    'mismo aviso de privacidad existente');
verificarExperiencia(hash('sha256', $experiencia['terminos']) ===
    hash_file('sha256', dirname(__DIR__) . '/app/resources/inscripcion/terminos-ejecutivas.txt'),
    'documento original sin ediciones');
verificarExperiencia(hash('sha256', $experiencia['privacidad']) ===
    hash_file('sha256', dirname(__DIR__) . '/app/resources/inscripcion/privacidad.txt'),
    'privacidad original sin ediciones');
foreach (['I. Declara la', 'III. Descripción del servicio', 'SÉPTIMA.-', '$5,000.00',
    'DÉCIMA.-', 'ONCEAVA.-'] as $texto) {
    // Se coteja contra el documento institucional íntegro, no se reconstruye.
    if ($texto === 'DÉCIMA.-') {
        // El original utiliza DECIMA, sin acento.
        $texto = 'DECIMA.-';
    }
    verificarExperiencia(strpos($experiencia['terminos'], $texto) !== false,
        'cláusula o monto del documento original: ' . $texto);
}

$perfiles = FormularioInscripcionValidacion::perfiles('titulacion-experiencia-laboral');
verificarExperiencia(count($perfiles) === 8 &&
    count(array_unique($perfiles)) === 8, 'exactamente ocho perfiles únicos');
verificarExperiencia(in_array('Licenciatura en contaduría A286', $perfiles, true),
    'Contaduría conserva su perfil existente');
verificarExperiencia(!in_array('Licenciatura en pedagogía A286', $perfiles, true),
    'no incorporar perfiles de Ejecutivas a Experiencia Laboral');

foreach (['bachillerato-en-2-anos', 'bachillerato-286', 'ingles'] as $slug) {
    $legal = FormularioInscripcionValidacion::legales($slug);
    verificarExperiencia($legal['terminos'] === '',
        'no habilitar términos de otro programa: ' . $slug);
    verificarExperiencia($legal['privacidad'] === $experiencia['privacidad'],
        'aviso de privacidad compartido: ' . $slug);
}

// Validación real de datos sintéticos: la ruta de guardado aplica las mismas
// comprobaciones a internos y públicos. No se consulta RENAPO ni escribe SQL.
$prefijo = 'GARR000101HDFRRS0';
$curp = $prefijo . FormularioEjecutivasValidacion::digitoVerificador($prefijo);
$datos = [
    'nombre'=>'Roberto','apellidos'=>'García Ruiz',
    'fecha_nacimiento'=>'2000-01-01','genero'=>'H','curp'=>$curp,
    'nombre_padres'=>'ANA GARCIA / CARLOS RUIZ',
    'correo'=>'test@example.net','correo_confirmacion'=>'test@example.net',
    'movil'=>'5512345678','movil_confirmacion'=>'5512345678',
    'calle'=>'Avenida Principal','numero'=>'S/N','ciudad'=>'Cuernavaca',
    'codigo_postal'=>'62350','municipio'=>'Cuernavaca',
    'entidad_federativa'=>'Morelos','empresa_labora'=>'Empresa de prueba',
    'cargo_puesto'=>'Analista','perfil_evaluacion'=>'Licenciatura en contaduría A286',
    'fecha_inscripcion'=>'2026-10-20',
    'confirmacion_identidad'=>'1','acepta_terminos'=>'1','acepta_privacidad'=>'1'
];
$resultado = FormularioInscripcionValidacion::validar($datos, 'titulacion-experiencia-laboral');
verificarExperiencia($resultado['ok'] === true, 'captura sintética completa aceptada');
foreach (['acepta_terminos', 'acepta_privacidad', 'confirmacion_identidad'] as $clave) {
    $fallido = $datos;
    $fallido[$clave] = '0';
    $resultado = FormularioInscripcionValidacion::validar($fallido,'titulacion-experiencia-laboral');
    verificarExperiencia($resultado['ok'] === false &&
        isset($resultado['errores'][$clave]), 'rechazar consentimiento ausente: ' . $clave);
}
$fallido = $datos;
$fallido['perfil_evaluacion'] = 'Licenciatura en pedagogía A286';
$resultado = FormularioInscripcionValidacion::validar($fallido,'titulacion-experiencia-laboral');
verificarExperiencia($resultado['ok'] === false &&
    isset($resultado['errores']['perfil_evaluacion']), 'rechazar perfil ajeno');

echo "PASS: Experiencia Laboral habilitada, ocho perfiles, contrato y privacidad originales, tres programas pendientes y validaciones intactas\n";
