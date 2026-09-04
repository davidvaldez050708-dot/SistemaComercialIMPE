<?php
/**
 * Tabla de municipios.
 *
 * Mantiene el diseño original de la tabla y NO crea un segundo
 * bloque de priorización.
 *
 * El bloque de priorización ya existe en index.php. Al cargar esta
 * vista únicamente se actualizan sus textos para interpretar:
 *   ALTA  -> ATACAR
 *   MEDIA -> OFRECER
 *   BAJA  -> OBSERVAR
 */

$municipios = $municipios ?? [];
$paginaMunicipios = max(1, (int)($paginaMunicipios ?? 1));
$limiteMunicipios = max(1, (int)($limiteMunicipios ?? 10));
$totalMunicipios = max(0, (int)($totalMunicipios ?? count($municipios)));

/*
 * Cuando municipios_tabla.php se carga por AJAX mediante la acción
 * municipiosTabla, $priorizacionMunicipal puede no venir definida.
 * La inicializamos para evitar warnings sin alterar el resto de la vista.
 */
$priorizacionMunicipal = $priorizacionMunicipal ?? [
    'disponible' => false,
    'total_municipios_con_poblacion' => 0,
    'total_municipios_sin_poblacion' => 0,
    'poblacion_municipal_registrada' => 0,
    'conteos' => ['ALTA' => 0, 'MEDIA' => 0, 'BAJA' => 0],
    'recomendados' => [],
    'por_municipio' => []
];

if (!isset($puedeGestionarMunicipios)) {
    $puedeGestionarMunicipios = function_exists('tienePermiso')
        ? tienePermiso('data_territorial.gestionar_municipios')
        : false;
}

$escMunicipio = function ($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
};

$fotoMunicipioUrl = function ($municipio) {
    $fotografia = trim((string)($municipio['fotografia'] ?? ''));

    if ($fotografia === '') {
        return '';
    }

    if (function_exists('obtenerUrlArchivoPublico')) {
        $url = obtenerUrlArchivoPublico(
            $fotografia,
            [
                'public/uploads/territorios/municipios',
                'public/uploads/municipios'
            ]
        );

        if (trim((string)$url) !== '') {
            return $url;
        }
    }

    if (preg_match('~^https?://~i', $fotografia)) {
        return $fotografia;
    }

    if (defined('BASE_URL')) {
        return rtrim((string)BASE_URL, '/') . '/' . ltrim($fotografia, '/');
    }

    return $fotografia;
};


$estrategiasMunicipales = [
    'ALTA' => ['accion' => 'ATACAR', 'clase' => 'alta'],
    'MEDIA' => ['accion' => 'OFRECER', 'clase' => 'media'],
    'BAJA' => ['accion' => 'OBSERVAR', 'clase' => 'baja'],
];

$totalPaginasMunicipios = max(
    1,
    (int)ceil($totalMunicipios / max(1, $limiteMunicipios))
);

if ($paginaMunicipios > $totalPaginasMunicipios) {
    $paginaMunicipios = $totalPaginasMunicipios;
}

$inicioMunicipios = $totalMunicipios > 0
    ? (($paginaMunicipios - 1) * $limiteMunicipios) + 1
    : 0;

$finMunicipios = $totalMunicipios > 0
    ? min($paginaMunicipios * $limiteMunicipios, $totalMunicipios)
    : 0;

$paginasVisibles = [];

if ($totalPaginasMunicipios <= 7) {
    $paginasVisibles = range(1, $totalPaginasMunicipios);
} else {
    $candidatas = [
        1,
        max(2, $paginaMunicipios - 2),
        max(2, $paginaMunicipios - 1),
        $paginaMunicipios,
        min($totalPaginasMunicipios - 1, $paginaMunicipios + 1),
        min($totalPaginasMunicipios - 1, $paginaMunicipios + 2),
        $totalPaginasMunicipios
    ];

    $paginasVisibles = array_values(array_unique(array_filter(
        $candidatas,
        function ($pagina) use ($totalPaginasMunicipios) {
            return $pagina >= 1 && $pagina <= $totalPaginasMunicipios;
        }
    )));
    sort($paginasVisibles);
}
?>

<?php if (!empty($municipios)): ?>
    <div class="table-responsive">
        <table class="table users-table data-table align-middle">
            <thead>
                <tr>
                    <th style="width: 58px;">#</th>
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
                        $idMunicipio = (int)($municipio['id'] ?? 0);
                        $nombreMunicipio = trim((string)($municipio['nombre'] ?? ''));
                        $claveInegi = trim((string)($municipio['clave_inegi'] ?? ''));
                        $poblacion = $municipio['poblacion'] ?? null;
                        $presidente = trim((string)($municipio['presidente_municipal'] ?? ''));
                        $partido = trim((string)($municipio['partido_politico'] ?? ''));
                        $redes = trim((string)($municipio['redes_sociales'] ?? ''));
                        $fotografia = trim((string)($municipio['fotografia'] ?? ''));
                        $fotografiaUrl = $fotoMunicipioUrl($municipio);
                        $prioridadMunicipio = strtoupper(trim((string)(
                            $priorizacionMunicipal['por_municipio'][$idMunicipio]['prioridad']
                            ?? 'BAJA'
                        )));
                        $accionMunicipio = strtoupper(trim((string)(
                            $priorizacionMunicipal['por_municipio'][$idMunicipio]['accion']
                            ?? 'OBSERVAR'
                        )));

                        if (!isset($estrategiasMunicipales[$prioridadMunicipio])) {
                            $prioridadMunicipio = 'BAJA';
                            $accionMunicipio = 'OBSERVAR';
                        }

                        $estrategiaMunicipio = $estrategiasMunicipales[$prioridadMunicipio];
                        $estrategiaMunicipio['accion'] = $accionMunicipio !== ''
                            ? $accionMunicipio
                            : $estrategiaMunicipio['accion'];
                        $puntajeMunicipio = (int)(
                            $priorizacionMunicipal['por_municipio'][$idMunicipio]['puntaje']
                            ?? 0
                        );
                        $coberturaMunicipio = (int)(
                            $priorizacionMunicipal['por_municipio'][$idMunicipio]['cobertura_datos']
                            ?? 0
                        );
                        $rankingMunicipio = (int)(
                            $priorizacionMunicipal['por_municipio'][$idMunicipio]['ranking']
                            ?? 0
                        );
                        $totalRankingMunicipio = (int)(
                            $priorizacionMunicipal['por_municipio'][$idMunicipio]['total_ranking']
                            ?? 0
                        );
                        $tooltipPrioridadMunicipio = $estrategiaMunicipio['accion']
                            . ' · ' . $puntajeMunicipio . '/100';

                        if ($rankingMunicipio > 0 && $totalRankingMunicipio > 0) {
                            $tooltipPrioridadMunicipio .=
                                ' · Ranking ' . $rankingMunicipio . ' de ' . $totalRankingMunicipio;
                        }

                        $tooltipPrioridadMunicipio .=
                            ' · Cobertura ' . $coberturaMunicipio . '%';
                        $numeroFila = (($paginaMunicipios - 1) * $limiteMunicipios) + $indiceMunicipio + 1;
                    ?>

                    <tr>
                        <td>
                            <strong><?= (int)$numeroFila ?></strong>
                        </td>

                        <td>
                            <strong>
                                <?= $escMunicipio(
                                    $nombreMunicipio !== ''
                                        ? $nombreMunicipio
                                        : 'Municipio sin nombre'
                                ) ?>
                            </strong>

                            <span class="data-table-muted">
                                <?= $claveInegi !== ''
                                    ? 'Clave INEGI: ' . $escMunicipio($claveInegi)
                                    : 'Clave INEGI no registrada' ?>
                            </span>
                        </td>

                        <td>
                            <?php if ($poblacion !== null && $poblacion !== '' && is_numeric($poblacion)): ?>
                                <?= number_format((float)$poblacion, 0, '.', ',') ?>
                            <?php else: ?>
                                <span class="detail-muted">No registrada</span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?= $presidente !== ''
                                ? $escMunicipio($presidente)
                                : '<span class="detail-muted">No registrado</span>' ?>
                        </td>

                        <td>
                            <?php if ($redes === ''): ?>
                                <span class="detail-muted">No registrado</span>
                            <?php elseif (filter_var($redes, FILTER_VALIDATE_URL)): ?>
                                <a
                                    class="data-external-link"
                                    href="<?= $escMunicipio($redes) ?>"
                                    target="_blank"
                                    rel="noopener noreferrer">
                                    <i class="bi bi-link-45deg"></i>
                                    Abrir red social
                                </a>
                            <?php else: ?>
                                <?= $escMunicipio($redes) ?>
                            <?php endif; ?>
                        </td>

                        <td>
                            <?php if ($fotografiaUrl !== ''): ?>
                                <a
                                    class="data-external-link"
                                    href="<?= $escMunicipio($fotografiaUrl) ?>"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    data-profile-photo
                                    data-photo-url="<?= $escMunicipio($fotografiaUrl) ?>"
                                    data-photo-name="<?= $escMunicipio($presidente !== '' ? $presidente : $nombreMunicipio) ?>"
                                    data-photo-role="Presidente municipal"
                                    aria-label="Ver fotografía de <?= $escMunicipio($presidente !== '' ? $presidente : $nombreMunicipio) ?>">
                                    <img
                                        class="data-municipality-president-photo"
                                        src="<?= $escMunicipio($fotografiaUrl) ?>"
                                        alt="Fotografía de <?= $escMunicipio($presidente !== '' ? $presidente : $nombreMunicipio) ?>">
                                </a>
                            <?php else: ?>
                                <span class="data-no-photo">Sin foto</span>
                            <?php endif; ?>
                        </td>

                        <?php if ($puedeGestionarMunicipios): ?>
                            <td class="text-end">
                                <div class="table-actions justify-content-end">
                                    <span
                                        class="data-municipality-priority-badge data-municipality-priority-badge-<?= $escMunicipio($estrategiaMunicipio['clase']) ?>"
                                        title="<?= $escMunicipio($tooltipPrioridadMunicipio) ?>">
                                        <?= $escMunicipio($estrategiaMunicipio['accion']) ?>
                                    </span>

                                    <button
                                        type="button"
                                        class="table-action-button"
                                        aria-label="Editar municipio"
                                        title="Editar municipio"
                                        data-municipio-edit
                                        data-bs-toggle="modal"
                                        data-bs-target="#modalMunicipio"
                                        data-id="<?= $idMunicipio ?>"
                                        data-nombre="<?= $escMunicipio($nombreMunicipio) ?>"
                                        data-clave-inegi="<?= $escMunicipio($claveInegi) ?>"
                                        data-poblacion="<?= $escMunicipio($poblacion) ?>"
                                        data-presidente-municipal="<?= $escMunicipio($presidente) ?>"
                                        data-partido-politico="<?= $escMunicipio($partido) ?>"
                                        data-redes-sociales="<?= $escMunicipio($redes) ?>"
                                        data-fotografia="<?= $escMunicipio($fotografia) ?>"
                                        data-fotografia-url="<?= $escMunicipio($fotografiaUrl) ?>">
                                        <i class="bi bi-pencil"></i>
                                    </button>
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
            Mostrando <?= (int)$inicioMunicipios ?> a <?= (int)$finMunicipios ?>
            de <?= (int)$totalMunicipios ?> municipios
        </span>

        <?php if ($totalPaginasMunicipios > 1): ?>
            <div>
                <?php if ($paginaMunicipios > 1): ?>
                    <a
                        href="#"
                        data-municipios-pagina="<?= (int)$paginaMunicipios - 1 ?>"
                        aria-label="Página anterior">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                <?php endif; ?>

                <?php $paginaAnterior = null; ?>

                <?php foreach ($paginasVisibles as $paginaVisible): ?>
                    <?php if (
                        $paginaAnterior !== null &&
                        $paginaVisible > $paginaAnterior + 1
                    ): ?>
                        <span aria-hidden="true">…</span>
                    <?php endif; ?>

                    <a
                        href="#"
                        data-municipios-pagina="<?= (int)$paginaVisible ?>"
                        class="<?= (int)$paginaVisible === (int)$paginaMunicipios ? 'active' : '' ?>"
                        <?= (int)$paginaVisible === (int)$paginaMunicipios
                            ? 'aria-current="page"'
                            : '' ?>>
                        <?= (int)$paginaVisible ?>
                    </a>

                    <?php $paginaAnterior = $paginaVisible; ?>
                <?php endforeach; ?>

                <?php if ($paginaMunicipios < $totalPaginasMunicipios): ?>
                    <a
                        href="#"
                        data-municipios-pagina="<?= (int)$paginaMunicipios + 1 ?>"
                        aria-label="Página siguiente">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="empty-table-message">
        No hay municipios para mostrar.
    </div>
<?php endif; ?>

<script>
(function () {
    const bloque = document.querySelector('#municipios .data-municipality-priority');

    if (!bloque || bloque.dataset.strategyUnified === '1') {
        return;
    }

    bloque.dataset.strategyUnified = '1';

    const eyebrow = bloque.querySelector(
        '.data-municipality-priority-header > div:first-child > span'
    );
    const titulo = bloque.querySelector(
        '.data-municipality-priority-header h4'
    );
    const descripcion = bloque.querySelector(
        '.data-municipality-priority-header > div:first-child > p'
    );
    const ayuda = bloque.querySelector(
        '.data-municipality-priority-help span'
    );

    if (eyebrow) {
        eyebrow.textContent = 'PRIORIZACIÓN DE VINCULACIÓN';
    }

    if (titulo) {
        titulo.textContent = 'Municipios prioritarios para vinculación';
    }

    if (descripcion) {
        descripcion.textContent =
            'La priorización combina el Índice de Oportunidad Municipal con la posición de cada municipio dentro de su propio Estado.';
    }

    if (ayuda) {
        ayuda.innerHTML =
            '<strong>Priorización orientativa</strong><br>' +
            'ATACAR: mayor prioridad · OFRECER: prioridad media · OBSERVAR: seguimiento<br>' +
            'Educación y economía corresponden al contexto estatal.';
    }

    const etiquetasResumen = [
        'Atacar',
        'Ofrecer',
        'Observar',
        'Con población disponible'
    ];

    bloque
        .querySelectorAll('.data-municipality-priority-summary > div')
        .forEach(function (item, indice) {
            const etiqueta = item.querySelector('span');

            if (etiqueta && etiquetasResumen[indice]) {
                etiqueta.textContent = etiquetasResumen[indice];
            }
        });

    bloque
        .querySelectorAll('.data-municipality-priority-badge')
        .forEach(function (badge) {
            if (badge.classList.contains('data-municipality-priority-badge-alta')) {
                badge.textContent = 'ATACAR';
                return;
            }

            if (badge.classList.contains('data-municipality-priority-badge-media')) {
                badge.textContent = 'OFRECER';
                return;
            }

            if (badge.classList.contains('data-municipality-priority-badge-baja')) {
                badge.textContent = 'OBSERVAR';
            }
        });
})();
</script>
