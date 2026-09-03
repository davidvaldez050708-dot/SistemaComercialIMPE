<?php

require_once __DIR__ . '/../../helpers/AvatarHelper.php';

$seguimiento = $seguimiento ?? [];
$interacciones = $interacciones ?? [];
$oficios = $oficios ?? [];
$observaciones = $observaciones ?? [];
$modoSeguimiento = $modoSeguimiento ?? 'analista';
$puedeComentar = $puedeComentar ?? false;
$nuevasObservaciones = (int)($nuevasObservaciones ?? 0);
$mensajeError = $mensajeError ?? '';
$mensajeExito = $mensajeExito ?? '';

$texto = function ($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
};

$valor = function ($valor) use ($texto) {
    $valor = trim((string)$valor);

    return $valor !== '' ? $texto($valor) : '—';
};

$formatearFecha = function ($fecha) {
    $fecha = trim((string)$fecha);

    if ($fecha === '') {
        return '—';
    }

    try {
        $meses = [
            'Jan' => 'ene',
            'Feb' => 'feb',
            'Mar' => 'mar',
            'Apr' => 'abr',
            'May' => 'may',
            'Jun' => 'jun',
            'Jul' => 'jul',
            'Aug' => 'ago',
            'Sep' => 'sep',
            'Oct' => 'oct',
            'Nov' => 'nov',
            'Dec' => 'dic'
        ];
        $fechaObjeto = new DateTime($fecha);

        return strtr($fechaObjeto->format('d M Y · H:i'), $meses);
    } catch (Exception $error) {
        return '—';
    }
};

$etiqueta = function ($valor, $mapa) {
    $valor = (string)$valor;

    return $mapa[$valor] ?? '—';
};

$estados = [
    'NUEVO' => 'Nuevo',
    'CONTACTANDO' => 'Contactando',
    'DATOS_VERIFICADOS' => 'Datos verificados',
    'NO_LOCALIZADO' => 'No localizado',
    'DESCARTADO' => 'Descartado',
    'OFICIO_PREPARADO' => 'Oficio preparado',
    'ESPERANDO_RESPUESTA' => 'Esperando respuesta'
];

$tipos = [
    'EMPRESA' => 'Empresa',
    'ORGANIZACION' => 'Organización',
    'INSTITUCION' => 'Institución',
    'SECRETARIA' => 'Secretaría',
    'MUNICIPIO' => 'Municipio',
    'OTRO' => 'Otro'
];

$canales = [
    'LLAMADA_IP' => 'Llamada',
    'WHATSAPP' => 'WhatsApp',
    'CORREO' => 'Correo',
    'NOTA' => 'Nota',
    'SISTEMA' => 'Sistema'
];

$resultados = [
    'CONTACTADO' => 'Contactado',
    'NO_CONTESTO' => 'No contestó',
    'OCUPADO' => 'Ocupado',
    'NUMERO_INCORRECTO' => 'Número incorrecto',
    'SOLICITO_LLAMAR_DESPUES' => 'Solicitó llamar después',
    'MENSAJE_ENVIADO' => 'Mensaje enviado',
    'CORREO_ENVIADO' => 'Correo enviado',
    'SIN_RESPUESTA' => 'Sin respuesta',
    'OTRO' => 'Otro'
];

$nombreAnalista = trim(
    ($seguimiento['analista_nombre'] ?? '') . ' ' .
    ($seguimiento['analista_apellidos'] ?? '')
);

$datosEncontrados = array_filter([
    trim((string)($seguimiento['telefono_fuente'] ?? '')),
    trim((string)($seguimiento['correo_fuente'] ?? '')),
    trim((string)($seguimiento['sitio_web_fuente'] ?? ''))
]);
$datosEncontrados = implode(' · ', $datosEncontrados);
$tipoEntidad = $etiqueta($seguimiento['tipo_entidad'] ?? '', $tipos);
$municipioEntidad = trim((string)($seguimiento['municipio'] ?? '')) !== ''
    ? (string)$seguimiento['municipio']
    : 'Sin municipio';
$etapaActual = $etiqueta($seguimiento['estado_seguimiento'] ?? '', $estados);
$origenSeguimiento = trim((string)($seguimiento['origen'] ?? ''));
$proximaAccion = trim((string)($seguimiento['proxima_accion_at'] ?? '')) !== ''
    ? $formatearFecha($seguimiento['proxima_accion_at'])
    : ((string)($seguimiento['estado_seguimiento'] ?? '') === 'NUEVO'
        ? 'Completar investigación'
        : '—');

?>

<?php if (!empty($mensajeError)): ?>
    <div class="alert alert-danger login-alert mb-3" role="alert">
        <i class="bi bi-exclamation-circle"></i>
        <span><?= $texto($mensajeError) ?></span>
    </div>
<?php endif; ?>

<?php if (!empty($mensajeExito)): ?>
    <div class="alert alert-success login-alert mb-3" role="status">
        <i class="bi bi-check2-circle"></i>
        <span><?= $texto($mensajeExito) ?></span>
    </div>
<?php endif; ?>

<section class="dashboard-panel linkage-panel">
    <a
        class="linkage-back-link"
        href="<?= BASE_URL ?>index.php?controller=seguimientoVinculacion&action=estado&estado_id=<?= (int)($seguimiento['estado_id'] ?? 0) ?>">
        <i class="bi bi-arrow-left"></i>
        Volver al seguimiento
    </a>

    <div class="linkage-heading">
        <div>
            <span>EXPEDIENTE COMPLETO</span>
            <h2><?= $valor($seguimiento['nombre_entidad'] ?? '') ?></h2>
            <p><?= $texto($tipoEntidad) ?> · <?= $texto($municipioEntidad) ?></p>
        </div>
        <div class="linkage-state-pill">
            <?= $texto($etapaActual) ?>
        </div>
    </div>
</section>

<nav class="linkage-detail-tabs" aria-label="Secciones del seguimiento">
    <span class="active">Resumen</span>
    <span>Contacto y validación</span>
    <span>Interacciones</span>
    <span>Oficio y correos</span>
    <span>Agenda</span>
    <span>Reunión</span>
    <span>Convenio</span>
</nav>

<?php if ($modoSeguimiento === 'analista' && $nuevasObservaciones > 0): ?>
    <div class="alert alert-info login-alert mb-3" role="status">
        <i class="bi bi-chat-left-text"></i>
        <span>Nuevas observaciones</span>
    </div>
<?php endif; ?>

<section class="dashboard-panel linkage-detail-panel">
    <div class="linkage-detail-header">
        <?= renderAvatarUsuario(
            $seguimiento['analista_nombre'] ?? '',
            $seguimiento['analista_apellidos'] ?? '',
            'Analista de Datos',
            $seguimiento['analista_foto'] ?? '',
            'md',
            'analista'
        ) ?>

        <div>
            <span>Analista responsable</span>
            <strong><?= $texto($nombreAnalista) ?></strong>
            <p><?= $valor($seguimiento['analista_correo'] ?? '') ?></p>
        </div>
    </div>

    <div class="linkage-detail-grid linkage-detail-operations-grid">
        <div class="detail-row">
            <span>Etapa actual</span>
            <strong><?= $texto($etapaActual) ?></strong>
        </div>
        <div class="detail-row">
            <span>Analista</span>
            <strong><?= $texto($nombreAnalista) ?></strong>
        </div>
        <div class="detail-row">
            <span>Municipio</span>
            <strong><?= $texto($municipioEntidad) ?></strong>
        </div>
        <div class="detail-row">
            <span>Próxima acción</span>
            <strong><?= $texto($proximaAccion) ?></strong>
        </div>
    </div>
</section>

<section class="dashboard-panel linkage-detail-panel">
    <div class="users-list-header">
        <div>
            <h2>Información encontrada</h2>
            <p>Datos fuente capturados al iniciar el seguimiento.</p>
        </div>
    </div>

    <div class="linkage-detail-grid">
        <div class="detail-row">
            <span>Nombre</span>
            <strong><?= $valor($seguimiento['nombre_entidad'] ?? '') ?></strong>
        </div>
        <div class="detail-row">
            <span>Actividad / giro</span>
            <strong><?= $valor($seguimiento['actividad_giro'] ?? '') ?></strong>
        </div>
        <div class="detail-row">
            <span>Dirección fuente</span>
            <strong><?= $valor($seguimiento['direccion_fuente'] ?? '') ?></strong>
        </div>
        <div class="detail-row">
            <span>Teléfono fuente</span>
            <strong><?= $valor($seguimiento['telefono_fuente'] ?? '') ?></strong>
        </div>
        <div class="detail-row">
            <span>Correo fuente</span>
            <strong><?= $valor($seguimiento['correo_fuente'] ?? '') ?></strong>
        </div>
        <div class="detail-row">
            <span>Sitio web fuente</span>
            <strong><?= $valor($seguimiento['sitio_web_fuente'] ?? '') ?></strong>
        </div>
        <div class="detail-row">
            <span>Municipio</span>
            <strong><?= $texto($municipioEntidad) ?></strong>
        </div>
        <div class="detail-row">
            <span>Origen</span>
            <strong><?= $valor($origenSeguimiento) ?></strong>
        </div>
    </div>
</section>

<section class="dashboard-panel linkage-detail-panel">
    <div class="users-list-header">
        <div>
            <h2>Datos verificados</h2>
            <p>Base para completar contacto antes de operar llamadas, mensajes o agenda.</p>
        </div>
    </div>

    <div class="linkage-detail-grid">
        <div class="detail-row">
            <span>Teléfono verificado</span>
            <strong><?= $valor($seguimiento['telefono_verificado'] ?? '') ?></strong>
        </div>
        <div class="detail-row">
            <span>WhatsApp</span>
            <strong><?= $valor($seguimiento['whatsapp_verificado'] ?? '') ?></strong>
        </div>
        <div class="detail-row">
            <span>Correo verificado</span>
            <strong><?= $valor($seguimiento['correo_verificado'] ?? '') ?></strong>
        </div>
        <div class="detail-row">
            <span>Persona de contacto</span>
            <strong><?= $valor($seguimiento['contacto_nombre'] ?? '') ?></strong>
        </div>
        <div class="detail-row">
            <span>Cargo</span>
            <strong><?= $valor($seguimiento['contacto_cargo'] ?? '') ?></strong>
        </div>
        <div class="detail-row">
            <span>Datos verificados</span>
            <strong><?= (int)($seguimiento['datos_verificados'] ?? 0) === 1 ? 'Sí' : 'No' ?></strong>
        </div>
    </div>

    <div class="linkage-detail-notes">
        <span>Observaciones generales</span>
        <p><?= nl2br($valor($seguimiento['observaciones'] ?? '')) ?></p>
    </div>
</section>

<section class="dashboard-panel linkage-detail-panel">
    <h3 class="panel-title">Historial de interacciones</h3>

    <?php if (!empty($interacciones)): ?>
        <div class="linkage-history-list">
            <?php foreach ($interacciones as $interaccion): ?>
                <article class="linkage-history-item">
                    <div>
                        <strong><?= $texto($etiqueta($interaccion['canal'] ?? '', $canales)) ?></strong>
                        <span>
                            <?= $texto($formatearFecha($interaccion['fecha_inicio'] ?? '')) ?>
                            ·
                            <?= $texto($etiqueta($interaccion['resultado'] ?? '', $resultados)) ?>
                        </span>
                    </div>
                    <p><?= nl2br($valor($interaccion['notas'] ?? '')) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="empty-table-message linkage-empty-message">
            Sin interacciones registradas.
        </div>
    <?php endif; ?>
</section>

<section class="dashboard-panel linkage-detail-panel">
    <h3 class="panel-title">Oficios</h3>

    <?php if (!empty($oficios)): ?>
        <div class="table-responsive">
            <table class="table users-table align-middle linkage-table">
                <thead>
                    <tr>
                        <th>Folio</th>
                        <th>Destinatario</th>
                        <th>Estado</th>
                        <th>Generación</th>
                        <th>Envío</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($oficios as $oficio): ?>
                        <tr>
                            <td><?= $valor($oficio['folio'] ?? '') ?></td>
                            <td><?= $valor($oficio['destinatario_nombre'] ?? '') ?></td>
                            <td><?= $valor($oficio['estado_oficio'] ?? '') ?></td>
                            <td><?= $texto($formatearFecha($oficio['fecha_generacion'] ?? '')) ?></td>
                            <td><?= $texto($formatearFecha($oficio['fecha_envio'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="empty-table-message linkage-empty-message">
            Sin oficios registrados.
        </div>
    <?php endif; ?>
</section>

<section class="dashboard-panel linkage-detail-panel">
    <div class="users-list-header">
        <div>
            <h2>Observaciones del Cuenta Clave</h2>
            <p>Notas internas para seguimiento del Analista.</p>
        </div>
    </div>

    <?php if ($puedeComentar): ?>
        <form
            class="linkage-comment-form"
            action="<?= BASE_URL ?>index.php?controller=seguimientoVinculacion&action=guardarObservacion"
            method="POST">
            <input type="hidden" name="seguimiento_id" value="<?= (int)($seguimiento['id'] ?? 0) ?>">
            <label class="form-label" for="observacion">Observación</label>
            <textarea
                class="form-control"
                id="observacion"
                name="observacion"
                rows="3"
                maxlength="2000"
                placeholder="Escribe una observación para el Analista..."
                required></textarea>
            <div class="linkage-comment-actions">
                <button type="submit" class="btn btn-system-save">
                    <i class="bi bi-send me-2"></i>
                    Enviar observación
                </button>
            </div>
        </form>
    <?php endif; ?>

    <?php if (!empty($observaciones)): ?>
        <div class="linkage-observation-list">
            <?php foreach ($observaciones as $observacion): ?>
                <?php
                $nombreAutor = trim(
                    ($observacion['nombre'] ?? '') . ' ' .
                    ($observacion['apellidos'] ?? '')
                );
                ?>
                <article class="linkage-observation-item">
                    <?= renderAvatarUsuario(
                        $observacion['nombre'] ?? '',
                        $observacion['apellidos'] ?? '',
                        $observacion['rol'] ?? '',
                        $observacion['foto_perfil'] ?? '',
                        'sm',
                        'general'
                    ) ?>
                    <div>
                        <strong><?= $texto($nombreAutor) ?></strong>
                        <span><?= $texto($formatearFecha($observacion['created_at'] ?? '')) ?></span>
                        <p><?= nl2br($texto($observacion['observacion'] ?? '')) ?></p>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="empty-table-message linkage-empty-message">
            Sin observaciones registradas.
        </div>
    <?php endif; ?>
</section>
