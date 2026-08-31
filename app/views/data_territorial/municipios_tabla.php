<?php

require_once __DIR__ . '/../../helpers/AvatarHelper.php';

$municipios = $municipios ?? [];
$estadoSeleccionado = $estadoSeleccionado ?? [];
$paginaMunicipios = max(1, (int)($paginaMunicipios ?? 1));
$limiteMunicipios = in_array((int)($limiteMunicipios ?? 10), [10, 15, 20], true)
    ? (int)$limiteMunicipios
    : 10;
$totalMunicipios = (int)($totalMunicipios ?? 0);
$totalPaginas = max(1, (int)ceil($totalMunicipios / $limiteMunicipios));
$puedeGestionarMunicipios =
    $puedeGestionarMunicipios ?? tienePermiso('data_territorial.gestionar_municipios');

$texto = function ($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
};

$valor = function ($valor) use ($texto) {
    if ($valor === null || trim((string)$valor) === '') {
        return '<span class="detail-muted">No registrado</span>';
    }

    return nl2br($texto($valor));
};

$numero = function ($valor) {
    if ($valor === null || trim((string)$valor) === '') {
        return '<span class="detail-muted">No registrado</span>';
    }

    return number_format((float)$valor, 0, '.', ',');
};

$redesSociales = function ($valor) use ($texto) {
    $valor = trim((string)$valor);

    if ($valor === '') {
        return '<span class="detail-muted">No registrado</span>';
    }

    if (filter_var($valor, FILTER_VALIDATE_URL)) {
        return '<a class="data-external-link" href="' .
            $texto($valor) .
            '" target="_blank" rel="noopener noreferrer"><i class="bi bi-link-45deg"></i> Abrir red social</a>';
    }

    return nl2br($texto($valor));
};

$fechaInput = function ($valorFecha) {
    if (!$valorFecha) {
        return '';
    }

    try {
        return (new DateTime($valorFecha))->format('Y-m-d\TH:i');
    } catch (Exception $error) {
        return '';
    }
};

?>

<?php if (!empty($municipios)): ?>

    <div class="table-responsive">
        <table class="table users-table data-table align-middle">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Municipio</th>
                    <th>Población</th>
                    <th>Presidente municipal</th>
                    <th>Redes sociales</th>
                    <th>Foto del presidente</th>
                    <?php if ($puedeGestionarMunicipios): ?>
                        <th class="text-end">Acciones</th>
                    <?php endif; ?>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($municipios as $indiceMunicipio => $municipio): ?>
                    <?php
                    $fotoUrl = obtenerUrlArchivoPublico(
                        $municipio['fotografia'] ?? '',
                        ['public/uploads/municipios']
                    );
                    $nombreVisor = trim(
                        ($municipio['presidente_municipal'] ?? '') .
                        ' / ' .
                        ($municipio['nombre'] ?? '')
                    );
                    ?>

                    <tr>
                        <td><?= (($paginaMunicipios - 1) * $limiteMunicipios) + (int)$indiceMunicipio + 1 ?></td>
                        <td>
                            <strong><?= $texto($municipio['nombre']) ?></strong>
                            <?php if (!empty($municipio['clave_inegi'])): ?>
                                <small class="data-table-muted">Clave INEGI: <?= $texto($municipio['clave_inegi']) ?></small>
                            <?php endif; ?>
                            <?= (int)$municipio['estado'] === 1
                                ? ''
                                : '<span class="status-pill status-pill-inactive ms-2">Inactivo</span>' ?>
                        </td>
                        <td><?= $numero($municipio['poblacion']) ?></td>
                        <td>
                            <?= $valor($municipio['presidente_municipal']) ?>
                            <?php if (!empty($municipio['partido_politico'])): ?>
                                <small class="data-table-muted">
                                    <?= $texto($municipio['partido_politico']) ?>
                                </small>
                            <?php endif; ?>
                        </td>
                        <td><?= $redesSociales($municipio['redes_sociales']) ?></td>
                        <td>
                            <?php if ($fotoUrl !== ''): ?>
                                <button
                                    type="button"
                                    class="system-avatar system-avatar-xs system-avatar-general system-avatar-photo-button"
                                    data-profile-photo
                                    data-photo-url="<?= $texto($fotoUrl) ?>"
                                    data-photo-name="<?= $texto($nombreVisor) ?>"
                                    data-photo-role="Fotografía del presidente municipal"
                                    aria-label="Ver foto de <?= $texto($nombreVisor) ?>">
                                    <img
                                        class="system-avatar-image"
                                        src="<?= $texto($fotoUrl) ?>"
                                        alt="Foto de <?= $texto($nombreVisor) ?>"
                                        data-avatar-initials="<?= $texto(obtenerInicialesUsuario($municipio['nombre'])) ?>">
                                </button>
                            <?php else: ?>
                                <span class="data-no-photo">Sin foto</span>
                            <?php endif; ?>
                        </td>

                        <?php if ($puedeGestionarMunicipios): ?>
                            <td class="text-end">
                                <div class="table-actions">
                                    <button
                                        type="button"
                                        class="table-action-button"
                                        aria-label="Editar municipio"
                                        data-municipio-edit
                                        data-bs-toggle="modal"
                                        data-bs-target="#modalMunicipio"
                                        data-id="<?= (int)$municipio['id'] ?>"
                                        data-nombre="<?= $texto($municipio['nombre']) ?>"
                                        data-clave-inegi="<?= $texto($municipio['clave_inegi']) ?>"
                                        data-poblacion="<?= $texto($municipio['poblacion']) ?>"
                                        data-presidente-municipal="<?= $texto($municipio['presidente_municipal']) ?>"
                                        data-partido-politico="<?= $texto($municipio['partido_politico']) ?>"
                                        data-redes-sociales="<?= $texto($municipio['redes_sociales']) ?>"
                                        data-fotografia="<?= $texto($municipio['fotografia']) ?>"
                                        data-fotografia-url="<?= $texto($fotoUrl) ?>">
                                        <i class="bi bi-pencil"></i>
                                    </button>

                                    <form
                                        action="<?= BASE_URL ?>index.php?controller=dataTerritorial&action=cambiarEstadoMunicipio"
                                        method="POST">
                                        <input type="hidden" name="estado_id" value="<?= (int)$estadoSeleccionado['id'] ?>">
                                        <input type="hidden" name="id" value="<?= (int)$municipio['id'] ?>">
                                        <input type="hidden" name="estado" value="<?= (int)$municipio['estado'] === 1 ? 0 : 1 ?>">
                                        <button
                                            type="submit"
                                            class="table-action-button"
                                            aria-label="<?= (int)$municipio['estado'] === 1 ? 'Desactivar municipio' : 'Activar municipio' ?>">
                                            <i class="bi <?= (int)$municipio['estado'] === 1 ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="data-pagination">
        <span>
            Página <?= (int)$paginaMunicipios ?> de <?= (int)$totalPaginas ?>
        </span>

        <div>
            <?php for ($pagina = 1; $pagina <= $totalPaginas; $pagina++): ?>
                <a
                    href="#municipios"
                    class="<?= $pagina === $paginaMunicipios ? 'active' : '' ?>"
                    data-municipios-pagina="<?= $pagina ?>">
                    <?= $pagina ?>
                </a>
            <?php endfor; ?>
        </div>
    </div>

<?php else: ?>

    <div class="empty-table-message">
        No hay municipios cargados.
    </div>

<?php endif; ?>
