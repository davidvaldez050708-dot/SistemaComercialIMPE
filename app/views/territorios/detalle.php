<?php

require_once __DIR__ . '/../../helpers/AvatarHelper.php';

$estado = $estado ?? [];
$equipoTerritorial = $equipoTerritorial ?? [];
$analistasSinCuentaClave = $analistasSinCuentaClave ?? [];
$asesoresTerritorio = $asesoresTerritorio ?? [];
$historialAsignaciones = $historialAsignaciones ?? [];
$movimientosTerritoriales = $movimientosTerritoriales ?? [];
$puedeAsignarTerritorio = tienePermiso('territorios.asignar');

$hayEquipoOperativo =
    !empty($equipoTerritorial) ||
    !empty($analistasSinCuentaClave);
$hayEquipoActual = $hayEquipoOperativo || !empty($asesoresTerritorio);

/*
 * Territorios puede ser una vista global, pero Información territorial conserva
 * su alcance por asignación. El enlace sólo se muestra si este usuario tendría
 * acceso real al Estado en ese módulo.
 */
$puedeVerDataTerritorial = false;

if (tienePermiso('data_territorial.ver')) {
    $usuarioActualId = (int)($_SESSION['usuario_id'] ?? 0);
    $rolActualId = (int)($_SESSION['rol_id'] ?? 0);
    $rolActual = trim((string)($_SESSION['rol'] ?? ''));
    $rolActualNormalizado = function_exists('mb_strtolower')
        ? mb_strtolower($rolActual, 'UTF-8')
        : strtolower($rolActual);

    if ($rolActualId === 1) {
        $puedeVerDataTerritorial = true;
    } elseif ($usuarioActualId > 0 && $rolActualNormalizado === 'cuenta clave') {
        foreach ($equipoTerritorial as $cuentaClaveAcceso) {
            if ((int)($cuentaClaveAcceso['usuario_id'] ?? 0) === $usuarioActualId) {
                $puedeVerDataTerritorial = true;
                break;
            }
        }
    } elseif ($usuarioActualId > 0 && $rolActualNormalizado === 'analista de datos') {
        foreach ($equipoTerritorial as $cuentaClaveAcceso) {
            foreach (($cuentaClaveAcceso['analistas'] ?? []) as $analistaAcceso) {
                if ((int)($analistaAcceso['usuario_id'] ?? 0) === $usuarioActualId) {
                    $puedeVerDataTerritorial = true;
                    break 2;
                }
            }
        }

        if (!$puedeVerDataTerritorial) {
            foreach ($analistasSinCuentaClave as $analistaAcceso) {
                if ((int)($analistaAcceso['usuario_id'] ?? 0) === $usuarioActualId) {
                    $puedeVerDataTerritorial = true;
                    break;
                }
            }
        }
    }
}

$texto = function ($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
};

$valor = function ($valor) use ($texto) {
    if ($valor === null || trim((string)$valor) === '') {
        return '<span class="detail-muted">No registrado</span>';
    }

    return $texto($valor);
};

$numero = function ($valor) {
    if ($valor === null || trim((string)$valor) === '') {
        return '<span class="detail-muted">No registrado</span>';
    }

    return number_format((float)$valor, 0, '.', ',');
};

$meses = [
    '01' => 'ene',
    '02' => 'feb',
    '03' => 'mar',
    '04' => 'abr',
    '05' => 'may',
    '06' => 'jun',
    '07' => 'jul',
    '08' => 'ago',
    '09' => 'sep',
    '10' => 'oct',
    '11' => 'nov',
    '12' => 'dic'
];

$fecha = function ($valorFecha) use ($texto, $meses) {
    if (!$valorFecha) {
        return '<span class="detail-muted">No registrado</span>';
    }

    try {
        $fechaObjeto = new DateTime($valorFecha);
    } catch (Exception $error) {
        return $texto($valorFecha);
    }

    return $fechaObjeto->format('d') . ' ' .
        $meses[$fechaObjeto->format('m')] . ' ' .
        $fechaObjeto->format('Y');
};

$fechaHora = function ($valorFecha) use ($texto, $meses) {
    if (!$valorFecha) {
        return '';
    }

    try {
        $fechaObjeto = new DateTime($valorFecha);
    } catch (Exception $error) {
        return $texto($valorFecha);
    }

    return $fechaObjeto->format('d') . ' ' .
        $meses[$fechaObjeto->format('m')] . ' ' .
        $fechaObjeto->format('Y · H:i');
};

$tipoTexto = function ($tipo) {
    $mapa = [
        'CUENTA_CLAVE' => 'Cuenta Clave',
        'ANALISTA_DATOS' => 'Analista de Datos',
        'ASESOR' => 'Asesor'
    ];

    return $mapa[$tipo] ?? $tipo;
};

$slugEstado = function ($nombreEstado) {
    $nombreEstado = trim((string)$nombreEstado);
    $slug = strtolower($nombreEstado);
    $transliterado = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug);

    if ($transliterado !== false) {
        $slug = $transliterado;
    }

    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim((string)$slug, '-');

    $ajustes = [
        'Michoacán' => 'michoacán',
        'Nuevo León' => 'nuevo-leon',
        'Querétaro' => 'queretaro',
        'San Luis Potosí' => 'san-luis-potosi',
        'Yucatán' => 'yucatan',
        'Ciudad de México' => 'ciudad-de-mexico',
        'Estado de México' => 'estado-de-mexico'
    ];

    return $ajustes[$nombreEstado] ?? $slug;
};

$imagenEstado = BASE_URL .
    'public/img/estados/' .
    $slugEstado($estado['nombre'] ?? '') .
    '.png';

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

    return $texto($nombreCorto);
};

$totalTexto = function ($total, $cargados) {
    if ($total === null || trim((string)$total) === '') {
        return (int)$cargados . ' cargados';
    }

    return (int)$cargados . ' de ' . (int)$total . ' cargados';
};

$eventosHistorial = [];

if (!empty($movimientosTerritoriales)) {
    foreach ($movimientosTerritoriales as $movimiento) {
        $accion = strtoupper(trim((string)($movimiento['accion'] ?? '')));
        $nombrePersona = trim((string)($movimiento['usuario_afectado_nombre'] ?? ''));
        $rolPersona = $tipoTexto($movimiento['tipo_asignacion'] ?? '');
        $cuentaAnterior = trim((string)($movimiento['cuenta_clave_anterior_nombre'] ?? ''));
        $cuentaNueva = trim((string)($movimiento['cuenta_clave_nueva_nombre'] ?? ''));
        $actor = trim((string)($movimiento['usuario_accion_nombre'] ?? ''));
        $titulo = '';
        $detalle = '';
        $icono = 'bi-clock-history';
        $clase = 'is-assignment';

        if ($accion === 'ASIGNACION') {
            $titulo = 'Se asignó a ' . $nombrePersona;
            $detalle = 'Como ' . $rolPersona;
            if ($cuentaNueva !== '' && $rolPersona === 'Analista de Datos') {
                $detalle .= ' · con ' . $cuentaNueva;
            }
            $icono = 'bi-person-plus';
        } elseif ($accion === 'DESASIGNACION') {
            $titulo = 'Finalizó la asignación de ' . $nombrePersona;
            $detalle = 'Como ' . $rolPersona;
            $icono = 'bi-person-dash';
            $clase = 'is-unassignment';
        } elseif ($accion === 'DESVINCULACION_CUENTA_CLAVE') {
            $titulo = $nombrePersona . ' quedó sin Cuenta Clave';
            $detalle = $cuentaAnterior !== ''
                ? 'Antes vinculado con ' . $cuentaAnterior
                : 'El Analista permaneció activo en el territorio';
            $icono = 'bi-link-45deg';
            $clase = 'is-unassignment';
        } elseif ($accion === 'VINCULACION_CUENTA_CLAVE') {
            $titulo = 'Se vinculó a ' . $nombrePersona;
            $detalle = $cuentaNueva !== ''
                ? 'Cuenta Clave: ' . $cuentaNueva
                : 'Vinculado a una Cuenta Clave';
            $icono = 'bi-link-45deg';
        } elseif ($accion === 'CAMBIO_CUENTA_CLAVE') {
            $titulo = 'Se cambió la Cuenta Clave de ' . $nombrePersona;
            $detalle = trim($cuentaAnterior . ' → ' . $cuentaNueva, ' →');
            $icono = 'bi-arrow-repeat';
        } else {
            $titulo = $nombrePersona !== ''
                ? 'Movimiento de ' . $nombrePersona
                : 'Movimiento territorial';
            $detalle = trim((string)($movimiento['detalle'] ?? ''));
        }

        $eventosHistorial[] = [
            'titulo' => $titulo,
            'detalle' => $detalle,
            'icono' => $icono,
            'clase' => $clase,
            'fecha' => $movimiento['registrado_at'] ?? null,
            'fecha_efectiva' => $movimiento['fecha_efectiva'] ?? null,
            'actor' => $actor
        ];
    }
} else {
    /*
     * Compatibilidad con instalaciones que aún no tengan la bitácora creada:
     * se reconstruyen únicamente inicio y fin desde los periodos existentes.
     */
    $asignacionesParaHistorial = [];
    $agregarAsignacionHistorial = function ($asignacion) use (&$asignacionesParaHistorial) {
        $id = (int)($asignacion['id'] ?? 0);
        if ($id > 0) {
            $asignacionesParaHistorial[$id] = $asignacion;
        }
    };

    foreach ($historialAsignaciones as $asignacionHistorica) {
        $agregarAsignacionHistorial($asignacionHistorica);
    }
    foreach ($equipoTerritorial as $cuentaClaveActual) {
        $agregarAsignacionHistorial($cuentaClaveActual);
        foreach (($cuentaClaveActual['analistas'] ?? []) as $analistaActual) {
            $agregarAsignacionHistorial($analistaActual);
        }
    }
    foreach ($analistasSinCuentaClave as $analistaActual) {
        $agregarAsignacionHistorial($analistaActual);
    }
    foreach ($asesoresTerritorio as $asesorActual) {
        $agregarAsignacionHistorial($asesorActual);
    }

    foreach ($asignacionesParaHistorial as $asignacion) {
        $nombrePersona = trim(
            ($asignacion['nombre'] ?? '') . ' ' .
            ($asignacion['apellidos'] ?? '')
        );
        $rolPersona = $tipoTexto($asignacion['tipo_asignacion'] ?? '');
        $fechaInicio = trim((string)($asignacion['fecha_inicio'] ?? ''));
        $fechaFin = trim((string)($asignacion['fecha_fin'] ?? ''));

        if ($fechaInicio !== '') {
            $eventosHistorial[] = [
                'titulo' => 'Se asignó a ' . $nombrePersona,
                'detalle' => 'Como ' . $rolPersona,
                'icono' => 'bi-person-plus',
                'clase' => 'is-assignment',
                'fecha' => $fechaInicio,
                'fecha_efectiva' => $fechaInicio,
                'actor' => ''
            ];
        }

        if ($fechaFin !== '') {
            $eventosHistorial[] = [
                'titulo' => 'Finalizó la asignación de ' . $nombrePersona,
                'detalle' => 'Como ' . $rolPersona,
                'icono' => 'bi-person-dash',
                'clase' => 'is-unassignment',
                'fecha' => $fechaFin,
                'fecha_efectiva' => $fechaFin,
                'actor' => ''
            ];
        }
    }

    usort($eventosHistorial, function ($a, $b) {
        return strcmp((string)($b['fecha'] ?? ''), (string)($a['fecha'] ?? ''));
    });
}

?>

<div class="territory-detail-content">
    <div class="territory-detail-scroll">
        <div class="territory-detail-heading">
            <span class="territory-detail-state-image">
                <img
                    src="<?= $texto($imagenEstado) ?>"
                    alt="Mapa de <?= $texto($estado['nombre'] ?? '') ?>">
            </span>

            <div>
                <h3><?= $texto($estado['nombre'] ?? '') ?></h3>
                <p>
                    <?= $nombreCortoVisible(
                        $estado['nombre'] ?? '',
                        $estado['nombre_corto'] ?? ''
                    ) ?: 'Territorio registrado' ?>
                </p>
            </div>
        </div>

        <section class="territory-detail-section">
            <h4>Equipo territorial actual</h4>

            <?php if ($hayEquipoActual): ?>
                <div class="territory-detail-team-list">
                    <?php foreach ($equipoTerritorial as $cuentaClave): ?>
                        <?php
                        $nombreCuenta = trim(
                            ($cuentaClave['nombre'] ?? '') . ' ' .
                            ($cuentaClave['apellidos'] ?? '')
                        );
                        $analistas = $cuentaClave['analistas'] ?? [];
                        ?>

                        <article class="territory-detail-team-card">
                            <div class="territory-detail-team-header">
                                <div class="territory-team-person">
                                    <?= renderAvatarUsuario(
                                        $cuentaClave['nombre'] ?? '',
                                        $cuentaClave['apellidos'] ?? '',
                                        $cuentaClave['rol'] ?? 'Cuenta Clave',
                                        $cuentaClave['foto_perfil'] ?? '',
                                        'md',
                                        'cuenta-clave'
                                    ) ?>

                                    <div>
                                        <span class="assignment-role assignment-role-account">
                                            Cuenta Clave
                                        </span>
                                        <strong><?= $texto($nombreCuenta) ?></strong>
                                        <small>Desde <?= $fecha($cuentaClave['fecha_inicio'] ?? '') ?></small>
                                    </div>
                                </div>
                            </div>

                            <div class="territory-detail-analysts border-0 pt-0 mt-3">
                                <?php if (!empty($analistas)): ?>
                                    <?php foreach ($analistas as $analista): ?>
                                        <?php
                                        $nombreAnalista = trim(
                                            ($analista['nombre'] ?? '') . ' ' .
                                            ($analista['apellidos'] ?? '')
                                        );
                                        ?>

                                        <div class="territory-person">
                                            <?= renderAvatarUsuario(
                                                $analista['nombre'] ?? '',
                                                $analista['apellidos'] ?? '',
                                                $analista['rol'] ?? 'Analista de Datos',
                                                $analista['foto_perfil'] ?? '',
                                                'xs',
                                                'analista'
                                            ) ?>
                                            <div class="d-flex flex-column align-items-start gap-1">
                                                <span class="assignment-role">Analista de Datos</span>
                                                <span class="territory-person-name">
                                                    <?= $texto($nombreAnalista) ?>
                                                </span>
                                                <small>Desde <?= $fecha($analista['fecha_inicio'] ?? '') ?></small>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span class="detail-muted">Sin analistas asignados</span>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>

                    <?php if (!empty($analistasSinCuentaClave)): ?>
                        <article class="territory-detail-team-card">
                            <div class="territory-detail-team-header">
                                <div>
                                    <span class="assignment-role">Analistas sin Cuenta Clave</span>
                                    <strong>Asignados directamente al territorio</strong>
                                </div>
                            </div>

                            <div class="territory-detail-analysts border-0 pt-0 mt-3">
                                <?php foreach ($analistasSinCuentaClave as $analista): ?>
                                    <?php
                                    $nombreAnalista = trim(
                                        ($analista['nombre'] ?? '') . ' ' .
                                        ($analista['apellidos'] ?? '')
                                    );
                                    ?>

                                    <div class="territory-person">
                                        <?= renderAvatarUsuario(
                                            $analista['nombre'] ?? '',
                                            $analista['apellidos'] ?? '',
                                            $analista['rol'] ?? 'Analista de Datos',
                                            $analista['foto_perfil'] ?? '',
                                            'xs',
                                            'analista'
                                        ) ?>
                                        <div class="d-flex flex-column align-items-start gap-1">
                                            <span class="territory-person-name">
                                                <?= $texto($nombreAnalista) ?>
                                            </span>
                                            <small>Desde <?= $fecha($analista['fecha_inicio'] ?? '') ?></small>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </article>
                    <?php endif; ?>

                    <?php if (!empty($asesoresTerritorio)): ?>
                        <div class="<?= $hayEquipoOperativo ? 'border-top pt-3 mt-1' : '' ?>">
                            <div class="mb-2">
                                <span class="assignment-role">Asesores</span>
                            </div>

                            <div class="territory-detail-analysts border-0 pt-0 mt-0">
                                <?php foreach ($asesoresTerritorio as $asesor): ?>
                                    <?php
                                    $nombreAsesor = trim(
                                        ($asesor['nombre'] ?? '') . ' ' .
                                        ($asesor['apellidos'] ?? '')
                                    );
                                    ?>

                                    <div class="territory-person py-1">
                                        <?= renderAvatarUsuario(
                                            $asesor['nombre'] ?? '',
                                            $asesor['apellidos'] ?? '',
                                            $asesor['rol'] ?? 'Asesor',
                                            $asesor['foto_perfil'] ?? '',
                                            'xs',
                                            'general'
                                        ) ?>
                                        <div class="d-flex flex-column align-items-start gap-1">
                                            <span class="assignment-role">Asesor</span>
                                            <span class="territory-person-name">
                                                <?= $texto($nombreAsesor) ?>
                                            </span>
                                            <small>Desde <?= $fecha($asesor['fecha_inicio'] ?? '') ?></small>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <p class="territory-empty-text">
                    Este territorio aún no tiene equipo territorial activo.
                </p>
            <?php endif; ?>
        </section>

        <section class="territory-detail-section">
            <h4>Información territorial</h4>

            <div class="detail-table">
                <div class="detail-row">
                    <span>Capital</span>
                    <strong><?= $valor($estado['capital'] ?? null) ?></strong>
                </div>
                <div class="detail-row">
                    <span>Población</span>
                    <strong><?= $numero($estado['poblacion'] ?? null) ?></strong>
                </div>
                <div class="detail-row">
                    <span>Municipios</span>
                    <strong>
                        <?= $texto($totalTexto(
                            $estado['total_municipios'] ?? null,
                            $estado['municipios_registrados'] ?? 0
                        )) ?>
                    </strong>
                </div>
                <div class="detail-row">
                    <span>Secretarías</span>
                    <strong>
                        <?= $texto($totalTexto(
                            $estado['total_secretarias'] ?? null,
                            $estado['secretarias_registradas'] ?? 0
                        )) ?>
                    </strong>
                </div>
                <div class="detail-row">
                    <span>Titular gobierno</span>
                    <strong><?= $valor($estado['titular_gobierno'] ?? null) ?></strong>
                </div>
                <div class="detail-row">
                    <span>Cargo</span>
                    <strong><?= $valor($estado['cargo_titular'] ?? null) ?></strong>
                </div>
                <div class="detail-row">
                    <span>Partido político</span>
                    <strong><?= $valor($estado['partido_politico'] ?? null) ?></strong>
                </div>
                <div class="detail-row">
                    <span>Periodo de gobierno</span>
                    <strong><?= $valor($estado['periodo_gobierno'] ?? null) ?></strong>
                </div>
                <div class="detail-row">
                    <span>Teléfono</span>
                    <strong><?= $valor($estado['telefono'] ?? null) ?></strong>
                </div>
                <div class="detail-row">
                    <span>Redes sociales</span>
                    <strong><?= $valor($estado['redes_sociales'] ?? null) ?></strong>
                </div>
                <div class="detail-row">
                    <span>Fecha actualización</span>
                    <strong><?= $fecha($estado['fecha_actualizacion'] ?? null) ?></strong>
                </div>
            </div>
        </section>

        <section class="territory-detail-section">
            <h4>Historial de asignaciones</h4>

            <?php if (!empty($eventosHistorial)): ?>
                <div class="territory-activity-list">
                    <?php foreach ($eventosHistorial as $evento): ?>
                        <div class="territory-activity-item">
                            <span class="territory-activity-icon <?= $texto($evento['clase']) ?>">
                                <i class="bi <?= $texto($evento['icono']) ?>"></i>
                            </span>

                            <div class="territory-activity-copy">
                                <strong><?= $texto($evento['titulo']) ?></strong>
                                <span><?= $texto($evento['detalle']) ?></span>
                                <small>
                                    <?= $texto($fechaHora($evento['fecha'])) ?>
                                    <?php if (!empty($evento['actor'])): ?>
                                        · por <?= $texto($evento['actor']) ?>
                                    <?php else: ?>
                                        · registro histórico
                                    <?php endif; ?>
                                </small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="territory-empty-text">
                    No hay movimientos de asignación registrados.
                </p>
            <?php endif; ?>
        </section>
    </div>

    <div class="territory-detail-footer">
        <button
            type="button"
            class="btn btn-system-cancel"
            data-bs-dismiss="offcanvas">
            Cerrar
        </button>

        <?php if ($puedeVerDataTerritorial): ?>
            <a
                class="btn btn-system-light"
                href="<?= BASE_URL ?>index.php?controller=dataTerritorial&action=index&estado_id=<?= (int)$estado['id'] ?>">
                <i class="bi bi-database me-2"></i>
                Ver información territorial
            </a>
        <?php endif; ?>

        <?php if ($puedeAsignarTerritorio): ?>
            <button
                type="button"
                class="btn btn-system-save"
                data-open-team-manager
                data-estado-id="<?= (int)$estado['id'] ?>"
                data-estado-nombre="<?= $texto($estado['nombre'] ?? '') ?>">
                <i class="bi bi-people me-2"></i>
                Gestionar equipo
            </button>
        <?php endif; ?>
    </div>
</div>