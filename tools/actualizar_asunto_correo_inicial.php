<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$rootPath = dirname(__DIR__);
require_once $rootPath . '/config/db_connection.php';

const NOMBRE_PLANTILLA_CORREO_INICIAL = 'Correo Programa de Profesionalización REDMEX 2026';
const ASUNTO_CORREO_INICIAL = 'Oferta Educativa y Becas Red Educativa México';

$linea = function ($texto = '') {
    echo $texto . PHP_EOL;
};

$db = new Database();
$conexion = $db->connect();

$linea('Actualización del asunto del primer correo REDMEX');
$linea(str_repeat('=', 48));
$linea('Asunto objetivo: ' . ASUNTO_CORREO_INICIAL);
$linea();

$conexion->begin_transaction();

try {
    $nombre = NOMBRE_PLANTILLA_CORREO_INICIAL;
    $asunto = ASUNTO_CORREO_INICIAL;

    $stmtBuscar = $conexion->prepare(
        "SELECT id
         FROM plantillas_vinculacion
         WHERE tipo = 'CORREO'
           AND nombre = ?
           AND activo = 1
         ORDER BY id DESC
         LIMIT 1"
    );
    $stmtBuscar->bind_param('s', $nombre);
    $stmtBuscar->execute();
    $plantilla = $stmtBuscar->get_result()->fetch_assoc() ?: null;

    if ($plantilla) {
        $plantillaId = (int)$plantilla['id'];
        $stmtPlantilla = $conexion->prepare(
            "UPDATE plantillas_vinculacion
             SET asunto = ?
             WHERE id = ?"
        );
        $stmtPlantilla->bind_param('si', $asunto, $plantillaId);
        $stmtPlantilla->execute();
        $accionPlantilla = 'actualizada';
    } else {
        $descripcion =
            'Correo institucional para el primer acercamiento y solicitud de reunión del programa de profesionalización.';
        $contenido = <<<'TEXTO'
{{DESTINATARIO_NOMBRE}}

{{DESTINATARIO_CARGO}}

{{INSTITUCION}}

{{ESTADO}}

P R E S E N T E

Esperando se encuentre muy bien, reciba un cordial saludo. Por medio del presente, me permito dirigirme a usted en representación de Fundación Red Educativa México, con la finalidad de solicitar una reunión virtual para presentar nuestra Oferta Educativa, dirigida al personal adscrito y a la ciudadanía en general que desee concluir o acreditar sus estudios de Nivel Medio Superior y Superior.

Nuestros programas se encuentran regulados por la Secretaría de Educación Pública Federal, bajo el marco del Acuerdo 286, mediante el cual contamos con la autorización como Sede Autorizada, permitiendo la obtención del certificado de bachillerato y/o título profesional. En el marco de este acercamiento, ponemos a disposición la asignación de becas, con el objetivo de facilitar el acceso a nuestros programas educativos y generar un beneficio directo para la población que usted representa.

Quedo atento para coordinar una reunión vía Zoom y definir el mejor esquema de colaboración.

Agradeciendo su atención, le envío un cordial saludo.

Atentamente
TEXTO;

        $stmtInsertar = $conexion->prepare(
            "INSERT INTO plantillas_vinculacion (
                nombre,
                tipo,
                descripcion,
                asunto,
                contenido,
                activo,
                creado_por
             ) VALUES (?, 'CORREO', ?, ?, ?, 1, NULL)"
        );
        $stmtInsertar->bind_param(
            'ssss',
            $nombre,
            $descripcion,
            $asunto,
            $contenido
        );
        $stmtInsertar->execute();
        $accionPlantilla = 'creada';
    }

    $stmtBorradores = $conexion->prepare(
        "UPDATE oficios_vinculacion
         SET asunto_correo = ?
         WHERE estado_oficio <> 'ENVIADO'
           AND fecha_envio IS NULL
           AND asunto_correo IS NOT NULL
           AND asunto_correo LIKE 'Programa de Profesionalización%'"
    );
    $stmtBorradores->bind_param('s', $asunto);
    $stmtBorradores->execute();
    $borradoresActualizados = $stmtBorradores->affected_rows;

    $conexion->commit();

    $linea('[OK] Plantilla ' . $accionPlantilla . ' correctamente.');
    $linea('[OK] Borradores pendientes actualizados: ' . $borradoresActualizados);
    $linea('[OK] El primer correo utilizará: ' . ASUNTO_CORREO_INICIAL);
} catch (Throwable $error) {
    $conexion->rollback();
    $linea('[ERROR] No fue posible actualizar el asunto.');
    $linea('Detalle: ' . $error->getMessage());
    exit(1);
}
