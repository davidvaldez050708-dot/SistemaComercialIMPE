<?php
$publicacionesDelDia = is_array($publicacionesDelDia ?? null)
    ? $publicacionesDelDia
    : [];

$fechaPublicaciones = (string)($fechaPublicaciones ?? date('Y-m-d'));
$fechaPublicacionesTexto = (string)($fechaPublicacionesTexto ?? date('d/m/Y'));

$formatearFechaPublicacionDia = static function ($fecha) {
    $fecha = trim((string)$fecha);

    if ($fecha === '') {
        return '—';
    }

    $timestamp = strtotime($fecha);

    return $timestamp !== false ? date('d/m/Y', $timestamp) : $fecha;
};

$estadoEtiquetasPublicacionDia = [
    'activa' => 'Activa',
    'proxima' => 'Próxima a vencer',
    'finalizada' => 'Finalizada',
    'inactiva' => 'Inactiva'
];

$convocatoriasBaseUrl = BASE_URL . 'index.php?controller=convocatoria&action=index';
?>

<div class="marketing-daily-publications">
    <div class="marketing-daily-publications-toolbar">
        <a
            class="marketing-daily-publications-back"
            href="<?= BASE_URL ?>index.php?controller=home&action=index">
            <i class="bi bi-arrow-left"></i>
            Volver al dashboard
        </a>

        <span class="marketing-daily-publications-date">
            <i class="bi bi-calendar3"></i>
            <?= htmlspecialchars($fechaPublicacionesTexto, ENT_QUOTES, 'UTF-8') ?>
        </span>
    </div>

    <section class="dashboard-panel marketing-daily-publications-summary">
        <div class="marketing-daily-publications-summary-copy">
            <span class="marketing-daily-publications-summary-icon">
                <i class="bi bi-files"></i>
            </span>

            <div>
                <span class="analyst-section-kicker">PUBLICACIONES DEL DÍA</span>
                <h2>Todas las convocatorias publicadas hoy</h2>
                <p>
                    Consulta el concentrado de publicaciones correspondientes al
                    <?= htmlspecialchars($fechaPublicacionesTexto, ENT_QUOTES, 'UTF-8') ?>.
                </p>
            </div>
        </div>

        <div class="marketing-daily-publications-count">
            <strong><?= count($publicacionesDelDia) ?></strong>
            <span><?= count($publicacionesDelDia) === 1 ? 'publicación' : 'publicaciones' ?></span>
        </div>
    </section>

    <section class="dashboard-panel marketing-daily-publications-panel">
        <div class="marketing-daily-publications-heading">
            <div>
                <h2>Publicaciones registradas</h2>
                <p>
                    Se muestran todas las convocatorias cuya fecha de publicación corresponde a este día.
                </p>
            </div>
        </div>

        <?php if (!empty($publicacionesDelDia)): ?>
            <div class="marketing-daily-publications-table-wrap">
                <table class="marketing-daily-publications-table">
                    <thead>
                        <tr>
                            <th>Nombre de la convocatoria</th>
                            <th>Tipo</th>
                            <th>Fecha de publicación</th>
                            <th>Vigencia</th>
                            <th>Territorio</th>
                            <th>Estado</th>
                            <th class="text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($publicacionesDelDia as $publicacion): ?>
                            <?php
                            $tipoPublicacion = (string)($publicacion['tipo_convocatoria'] ?? '');
                            $estadoProceso = (string)($publicacion['estado_proceso'] ?? 'inactiva');

                            if ($tipoPublicacion === 'titulacion') {
                                $tipoEtiqueta = 'Titulación';
                                $tipoIcono = 'bi-mortarboard';
                            } elseif ($tipoPublicacion === 'bachillerato') {
                                $tipoEtiqueta = 'Bachillerato';
                                $tipoIcono = 'bi-book';
                            } else {
                                $tipoEtiqueta = 'Sindicatos';
                                $tipoIcono = 'bi-people';
                            }

                            $territorioId = (int)($publicacion['territorio_id'] ?? 0);
                            $subtipoPublicacion = (string)($publicacion['subtipo_convocatoria'] ?? '');
                            $tituloPublicacion = trim((string)($publicacion['titulo'] ?? ''));

                            $urlPublicacion = $convocatoriasBaseUrl;

                            if ($territorioId > 0) {
                                $urlPublicacion .= '&territorio_id=' . $territorioId;
                            }

                            if ($tipoPublicacion !== '') {
                                $urlPublicacion .= '&tipo=' . rawurlencode($tipoPublicacion);
                            }

                            if ($subtipoPublicacion !== '') {
                                $urlPublicacion .= '&subtipo=' . rawurlencode($subtipoPublicacion);
                            }

                            if ($tituloPublicacion !== '') {
                                $urlPublicacion .= '&buscar=' . rawurlencode($tituloPublicacion);
                            }
                            ?>
                            <tr>
                                <td>
                                    <div class="marketing-daily-publications-name">
                                        <span>
                                            <i class="bi <?= htmlspecialchars($tipoIcono, ENT_QUOTES, 'UTF-8') ?>"></i>
                                        </span>
                                        <strong>
                                            <?= htmlspecialchars($tituloPublicacion, ENT_QUOTES, 'UTF-8') ?>
                                        </strong>
                                    </div>
                                </td>
                                <td><?= htmlspecialchars($tipoEtiqueta, ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <?= htmlspecialchars(
                                        $formatearFechaPublicacionDia($publicacion['fecha_inicio'] ?? ''),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </td>
                                <td>
                                    <?= htmlspecialchars(
                                        $formatearFechaPublicacionDia($publicacion['fecha_inicio'] ?? ''),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                    -
                                    <?= htmlspecialchars(
                                        $formatearFechaPublicacionDia($publicacion['fecha_termino'] ?? ''),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </td>
                                <td>
                                    <?= htmlspecialchars(
                                        (string)($publicacion['estados'] ?? '—'),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </td>
                                <td>
                                    <span class="convocatoria-process-badge convocatoria-process-<?= htmlspecialchars(
                                        $estadoProceso,
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>">
                                        <?= htmlspecialchars(
                                            $estadoEtiquetasPublicacionDia[$estadoProceso] ?? 'Inactiva',
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <a
                                        class="marketing-daily-publications-action"
                                        href="<?= htmlspecialchars($urlPublicacion, ENT_QUOTES, 'UTF-8') ?>"
                                        aria-label="Abrir <?= htmlspecialchars(
                                            $tituloPublicacion !== '' ? $tituloPublicacion : 'convocatoria',
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>">
                                        <i class="bi bi-three-dots-vertical"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="marketing-daily-publications-empty">
                <span>
                    <i class="bi bi-inbox"></i>
                </span>
                <strong>No hay publicaciones registradas para este día</strong>
                <p>
                    Cuando existan convocatorias con fecha de publicación
                    <?= htmlspecialchars($fechaPublicacionesTexto, ENT_QUOTES, 'UTF-8') ?>,
                    aparecerán en esta vista.
                </p>
            </div>
        <?php endif; ?>
    </section>
</div>
