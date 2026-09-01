<?php

$estado = $estado ?? [];
$resumen = $resumen ?? [
    'en_seguimiento' => 0,
    'contactando' => 0,
    'datos_verificados' => 0,
    'esperando_respuesta' => 0
];
$seguimientos = $seguimientos ?? [];

$texto = function ($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
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
        $formato = $fechaObjeto->format('d M Y · H:i');

        return strtr($formato, $meses);
    } catch (Exception $error) {
        return '—';
    }
};

$etiquetaEstado = function ($estadoSeguimiento) {
    $estadoSeguimiento = (string)$estadoSeguimiento;
    $etiquetas = [
        'NUEVO' => 'Nuevo',
        'CONTACTANDO' => 'Contactando',
        'DATOS_VERIFICADOS' => 'Datos verificados',
        'NO_LOCALIZADO' => 'No localizado',
        'DESCARTADO' => 'Descartado',
        'OFICIO_PREPARADO' => 'Oficio preparado',
        'ESPERANDO_RESPUESTA' => 'Esperando respuesta'
    ];

    return $etiquetas[$estadoSeguimiento] ?? 'Sin estado';
};

$etiquetaTipo = function ($tipoEntidad) {
    $tipoEntidad = (string)$tipoEntidad;
    $etiquetas = [
        'EMPRESA' => 'Empresa',
        'ORGANIZACION' => 'Organización',
        'INSTITUCION' => 'Institución',
        'SECRETARIA' => 'Secretaría',
        'MUNICIPIO' => 'Municipio',
        'OTRO' => 'Otro'
    ];

    return $etiquetas[$tipoEntidad] ?? '—';
};

?>

<section class="dashboard-panel linkage-panel">
    <a
        class="linkage-back-link"
        href="<?= BASE_URL ?>index.php?controller=seguimientoVinculacion&action=index">
        <i class="bi bi-arrow-left"></i>
        Volver a mis territorios
    </a>

    <div class="linkage-heading">
        <div>
            <span>VINCULACIÓN</span>
            <h2>Seguimiento de vinculación</h2>
            <p>
                Gestiona las instituciones y organizaciones con las que estás
                trabajando en este territorio.
            </p>
        </div>
        <div class="linkage-state-pill">
            <?= $texto($estado['nombre'] ?? '') ?>
        </div>
    </div>
</section>

<section class="metric-grid linkage-summary-grid" aria-label="Resumen de seguimiento">
    <article class="metric-card linkage-summary-card">
        <div class="metric-icon">
            <i class="bi bi-kanban"></i>
        </div>
        <div>
            <p class="metric-value"><?= (int)$resumen['en_seguimiento'] ?></p>
            <p class="metric-label">En seguimiento</p>
        </div>
    </article>

    <article class="metric-card linkage-summary-card">
        <div class="metric-icon">
            <i class="bi bi-telephone"></i>
        </div>
        <div>
            <p class="metric-value"><?= (int)$resumen['contactando'] ?></p>
            <p class="metric-label">Contactando</p>
        </div>
    </article>

    <article class="metric-card linkage-summary-card">
        <div class="metric-icon">
            <i class="bi bi-patch-check"></i>
        </div>
        <div>
            <p class="metric-value"><?= (int)$resumen['datos_verificados'] ?></p>
            <p class="metric-label">Datos verificados</p>
        </div>
    </article>

    <article class="metric-card linkage-summary-card">
        <div class="metric-icon">
            <i class="bi bi-hourglass-split"></i>
        </div>
        <div>
            <p class="metric-value"><?= (int)$resumen['esperando_respuesta'] ?></p>
            <p class="metric-label">Esperando respuesta</p>
        </div>
    </article>
</section>

<section class="dashboard-panel users-list-panel linkage-table-panel">
    <div class="users-list-header">
        <div>
            <h2>Seguimientos</h2>
            <p><?= $texto($estado['nombre'] ?? '') ?></p>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table users-table align-middle linkage-table">
            <thead>
                <tr>
                    <th>Institución</th>
                    <th>Tipo</th>
                    <th>Municipio</th>
                    <th>Último contacto</th>
                    <th>Canal</th>
                    <th>Estado</th>
                    <th>Folio</th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($seguimientos as $seguimiento): ?>
                    <tr>
                        <td>
                            <strong><?= $texto($seguimiento['nombre_entidad'] ?? '') ?></strong>
                        </td>
                        <td><?= $texto($etiquetaTipo($seguimiento['tipo_entidad'] ?? '')) ?></td>
                        <td>
                            <?= trim((string)($seguimiento['municipio'] ?? '')) !== ''
                                ? $texto($seguimiento['municipio'])
                                : '—' ?>
                        </td>
                        <td><?= $texto($formatearFecha($seguimiento['ultima_interaccion_at'] ?? '')) ?></td>
                        <td>—</td>
                        <td>
                            <span class="status-pill status-pill-active">
                                <?= $texto($etiquetaEstado($seguimiento['estado_seguimiento'] ?? '')) ?>
                            </span>
                        </td>
                        <td>
                            <?= trim((string)($seguimiento['folio'] ?? '')) !== ''
                                ? $texto($seguimiento['folio'])
                                : '—' ?>
                        </td>
                        <td class="text-end">
                            <div class="table-actions justify-content-end">
                                <button
                                    type="button"
                                    class="table-action-button"
                                    title="Disponible próximamente"
                                    disabled>
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if (empty($seguimientos)): ?>
        <div class="empty-table-message linkage-empty-message">
            <strong>
                Aún no tienes seguimientos en <?= $texto($estado['nombre'] ?? '') ?>.
            </strong>
            <span>
                En el siguiente paso podrás buscar instituciones, organizaciones,
                secretarías y municipios para comenzar un seguimiento.
            </span>
        </div>
    <?php endif; ?>
</section>
