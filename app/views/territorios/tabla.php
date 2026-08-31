<?php

require_once __DIR__ . '/../../helpers/AvatarHelper.php';

$estados = $estados ?? [];
$puedeAsignarTerritorio = tienePermiso('territorios.asignar');

$texto = function ($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
};

$nombreCortoVisible = function ($nombre, $nombreCorto) use ($texto) {
    $nombreCorto = trim((string)$nombreCorto);

    if ($nombreCorto === '') {
        return '';
    }

    $normalizar = function ($valor) {
        return strtolower(preg_replace('/\s+/', '', trim((string)$valor)));
    };

    if ($normalizar($nombre) === $normalizar($nombreCorto)) {
        return '';
    }

    return '<span>' . $texto($nombreCorto) . '</span>';
};

$resumenAsignados = function ($total, $personas, $tipo) use ($texto) {
    $total = (int)$total;

    if ($total === 0) {
        return '<span class="territory-muted">Sin asignar</span>';
    }

    $lista = array_values(array_filter(explode('||', (string)$personas)));
    $visibles = array_slice($lista, 0, 2);
    $html = '<div class="territory-assigned-list">';
    $nombresCompletos = [];

    foreach ($lista as $persona) {
        $partes = explode('~~', $persona);
        $nombre = $partes[0] ?? '';

        if ($nombre !== '') {
            $nombresCompletos[] = $nombre;
        }
    }

    foreach ($visibles as $persona) {
        $partes = explode('~~', $persona);
        $nombre = $partes[0] ?? '';
        $foto = $partes[1] ?? '';
        $rol = $partes[2] ?? '';
        $contexto = $tipo === 'ANALISTA_DATOS' ? 'analista' : 'cuenta-clave';

        $html .= '<div class="territory-person">' .
            renderAvatarUsuario(
                $nombre,
                '',
                $rol,
                $foto,
                'xs',
                $contexto
            ) .
            '<span class="territory-person-name">' . $texto($nombre) . '</span>' .
            '</div>';
    }

    if ($total > count($visibles)) {
        $html .= '<span class="territory-assigned-count" title="' .
            $texto(implode(', ', $nombresCompletos)) .
            '">+' .
            ($total - count($visibles)) .
            '</span>';
    }

    return $html . '</div>';
};

$cobertura = function ($cuentasClave, $analistas) {
    $cuentasClave = (int)$cuentasClave;
    $analistas = (int)$analistas;

    if ($cuentasClave === 0) {
        return '<span class="coverage-pill coverage-pill-muted">Sin Cuenta Clave</span>';
    }

    if ($analistas === 0) {
        return '<span class="coverage-pill coverage-pill-warning">Sin Analista</span>';
    }

    if ($cuentasClave > 1) {
        return '<span class="coverage-pill coverage-pill-shared">' .
            $cuentasClave .
            ' Cuenta Clave</span>';
    }

    return '<span class="coverage-pill coverage-pill-active">Equipo asignado</span>';
};

?>

<?php if (!empty($estados)): ?>

    <div class="table-responsive">
        <table class="table users-table territories-table align-middle">
            <thead>
                <tr>
                    <th>Estado</th>
                    <th>Cuenta Clave</th>
                    <th>Analistas</th>
                    <th>Cobertura</th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($estados as $estado): ?>

                    <tr
                        class="territory-row"
                        data-territory-row
                        data-territory-id="<?= (int)$estado['id'] ?>">
                        <td>
                            <strong><?= $texto($estado['nombre']) ?></strong>
                            <?= $nombreCortoVisible(
                                $estado['nombre'] ?? '',
                                $estado['nombre_corto'] ?? ''
                            ) ?>
                        </td>

                        <td>
                            <?= $resumenAsignados(
                                $estado['cuenta_clave_total'] ?? 0,
                                $estado['cuenta_clave_personas'] ??
                                    $estado['cuenta_clave_nombres'] ??
                                    '',
                                'CUENTA_CLAVE'
                            ) ?>
                        </td>

                        <td>
                            <?= $resumenAsignados(
                                $estado['analista_total'] ?? 0,
                                $estado['analista_personas'] ??
                                    $estado['analista_nombres'] ??
                                    '',
                                'ANALISTA_DATOS'
                            ) ?>
                        </td>

                        <td>
                            <?= $cobertura(
                                $estado['cuenta_clave_total'] ?? 0,
                                $estado['analista_total'] ?? 0
                            ) ?>
                        </td>

                        <td class="text-end">
                            <div class="table-actions">
                                <button
                                    type="button"
                                    class="table-action-button"
                                    aria-label="Ver detalle del territorio"
                                    title="Ver detalle del territorio"
                                    data-bs-toggle="tooltip"
                                    data-territory-detail
                                    data-id="<?= (int)$estado['id'] ?>">
                                    <i class="bi bi-eye"></i>
                                </button>

                                <?php if ($puedeAsignarTerritorio): ?>

                                    <button
                                        type="button"
                                        class="table-action-button table-action-primary"
                                        aria-label="Gestionar equipo territorial"
                                        title="Gestionar equipo territorial"
                                        data-bs-toggle="tooltip"
                                        data-open-team-manager
                                        data-estado-id="<?= (int)$estado['id'] ?>"
                                        data-estado-nombre="<?= $texto($estado['nombre'] ?? '') ?>">
                                        <i class="bi bi-people"></i>
                                    </button>

                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>

                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

<?php else: ?>

    <div class="empty-table-message">
        No se encontraron territorios con los criterios seleccionados.
    </div>

<?php endif; ?>
