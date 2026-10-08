<?php
$esc = static function ($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
};
$controlTelefonia = $controlTelefonia ?? [];
$stats = $controlTelefonia['stats'] ?? [];
$filtros = $controlTelefonia['filtros'] ?? [];
$roles = $controlTelefonia['roles'] ?? [];
$usuarios = $controlTelefonia['usuarios'] ?? [];
$numero = static function ($valor) { return number_format((int)$valor, 0, '.', ','); };
$tiempo = static function ($valor) {
    $total = max(0, (int)$valor);
    return sprintf('%d h %02d min %02d s',
        intdiv($total, 3600), intdiv($total % 3600, 60), $total % 60);
};
$porcentaje = static function ($a, $b) {
    return $b > 0 ? number_format(($a / $b) * 100, 1) . '%' : '—';
};
$baseTel = BASE_URL . 'index.php?controller=telefonia&action=';
$hoy = date('Y-m-d');
$etiquetasResultado = [
    'CONVERSACION_PERSONA' => 'Hablé con una persona',
    'BUZON_VOZ' => 'Buzón de voz',
    'FUERA_HORARIO' => 'Fuera de horario',
    'FUERA_SERVICIO' => 'Fuera de servicio',
    'NUMERO_INEXISTENTE' => 'Número inexistente',
    'NUMERO_INCORRECTO' => 'Número incorrecto',
    'SIN_RESPUESTA' => 'Sin respuesta',
    'NO_CONTESTO' => 'No contestó',
    'OCUPADO' => 'Línea ocupada',
    'MENSAJE_AUTOMATICO' => 'Mensaje automático',
    'OTRO_SIN_CONTACTO' => 'Sin contacto',
    'CONTACTADO' => 'Contactado',
    'SOLICITO_INFORMACION' => 'Solicitó información',
    'SOLICITO_LLAMAR_DESPUES' => 'Solicitó llamar después',
    'NO_INTERESADO' => 'No interesado',
    'MENSAJE_ENVIADO' => 'Mensaje enviado'
];
?>

<div class="telephony-admin telephony-control-center" data-telephony-control>
    <nav class="telephony-admin-tabs" aria-label="Secciones de Telefonía">
        <a href="<?= $esc($baseTel . 'index') ?>" class="is-active" aria-current="page">
            <i class="bi bi-bar-chart-line" aria-hidden="true"></i> Control de llamadas
        </a>
        <a href="<?= $esc($baseTel . 'extensiones') ?>">
            <i class="bi bi-diagram-3" aria-hidden="true"></i> Extensiones
        </a>
    </nav>
    <section class="dashboard-panel telephony-control-filter-panel">
        <div class="telephony-control-title-row">
            <div>
                <span class="telephony-eyebrow">SUPERVISIÓN TELEFÓNICA</span>
                <h2>Actividad y desempeño del equipo</h2>
                <p>Consulta las llamadas verificadas por Zadarma, el tiempo observado y las conversaciones registradas. Los indicadores se ajustan a los filtros.</p>
            </div>
            <a href="<?= $esc($baseTel . 'extensiones') ?>" class="btn btn-system-light telephony-control-config-link">
                <i class="bi bi-sliders" aria-hidden="true"></i> Administrar extensiones
            </a>
        </div>
        <?php if (!empty($actividadTelefonicaError)): ?>
            <div class="alert alert-warning mb-2" role="alert"><?= $esc($actividadTelefonicaError) ?></div>
        <?php endif; ?>
        <form action="<?= $esc(BASE_URL . 'index.php') ?>" method="get" class="telephony-control-filters">
            <input type="hidden" name="controller" value="telefonia">
            <input type="hidden" name="action" value="index">
            <label>Desde
                <input type="date" class="form-control" name="desde" required
                    value="<?= $esc($filtros['desde'] ?? date('Y-m-d', strtotime('-29 days'))) ?>"
                    max="<?= $esc($hoy) ?>">
            </label>
            <label>Hasta
                <input type="date" class="form-control" name="hasta" required
                    value="<?= $esc($filtros['hasta'] ?? $hoy) ?>"
                    max="<?= $esc($hoy) ?>">
            </label>
            <label>Rol
                <select class="form-select" name="rol">
                    <option value="">Todos los roles</option>
                    <?php foreach ($roles as $rol): ?>
                        <option value="<?= $esc($rol) ?>" <?= ($filtros['rol'] ?? '') === $rol ? 'selected' : '' ?>>
                            <?= $esc($rol) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Usuario
                <select class="form-select" name="usuario_id">
                    <option value="0">Todos los usuarios</option>
                    <?php foreach ($usuarios as $usuario): ?>
                        <option value="<?= (int)$usuario['id'] ?>"
                            <?= (int)($filtros['usuario_id'] ?? 0) === (int)$usuario['id'] ? 'selected' : '' ?>>
                            <?= $esc($usuario['nombre'] . ' · ' . $usuario['rol']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Dirección
                <select class="form-select" name="direccion">
                    <option value="">Todas</option>
                    <option value="Saliente" <?= ($filtros['direccion'] ?? '') === 'Saliente' ? 'selected' : '' ?>>Salientes</option>
                    <option value="Entrante" <?= ($filtros['direccion'] ?? '') === 'Entrante' ? 'selected' : '' ?>>Entrantes</option>
                </select>
            </label>
            <label>Ordenar ranking
                <select class="form-select" name="orden">
                    <option value="atenciones">Más atenciones</option>
                    <option value="segundos" <?= ($filtros['orden'] ?? '') === 'segundos' ? 'selected' : '' ?>>Más tiempo</option>
                    <option value="efectivas" <?= ($filtros['orden'] ?? '') === 'efectivas' ? 'selected' : '' ?>>Más conversaciones</option>
                </select>
            </label>
            <div class="telephony-control-filter-actions">
                <button type="submit" class="btn btn-system-primary"><i class="bi bi-funnel" aria-hidden="true"></i> Aplicar</button>
                <a class="btn btn-system-light" href="<?= $esc($baseTel . 'index') ?>">Restablecer</a>
            </div>
        </form>
        <p class="telephony-control-help">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            Periodo de hasta 90 días recientes. El tiempo corresponde a duraciones observadas por los webhooks de Zadarma, no al consumo facturado.
        </p>
    </section>

    <section class="telephony-control-kpi-grid" aria-label="Indicadores generales de telefonía">
        <?php
        $kpis = [
            ['label'=>'Llamadas únicas', 'valor'=>$numero($stats['llamadas_unicas'] ?? 0), 'icon'=>'bi-telephone', 'help'=>'Identificadores distintos de llamadas en la PBX'],
            ['label'=>'Atenciones', 'valor'=>$numero($stats['atenciones'] ?? 0), 'icon'=>'bi-telephone-outbound', 'help'=>'Registros por extensión, incluidas transferencias'],
            ['label'=>'Conectadas (PBX)', 'valor'=>$numero($stats['conectadas'] ?? 0), 'icon'=>'bi-telephone-check', 'help'=>'Una conexión puede ser buzón de voz'],
            ['label'=>'Conversaciones verificadas', 'valor'=>$numero($stats['efectivas'] ?? 0), 'icon'=>'bi-person-check', 'help'=>'Resultados con contacto humano registrado en Ventas y Vinculación'],
            ['label'=>'Tiempo observado', 'valor'=>$tiempo($stats['segundos'] ?? 0), 'icon'=>'bi-clock-history', 'help'=>'Duración acumulada por atención; no es facturación'],
            ['label'=>'Sin atribución', 'valor'=>$numero($stats['sin_atribucion'] ?? 0), 'icon'=>'bi-person-exclamation', 'help'=>'Sin evidencia suficiente para asignarlas a una persona']
        ];
        foreach ($kpis as $kpi):
        ?>
            <article class="telephony-control-kpi" title="<?= $esc($kpi['help']) ?>">
                <span class="telephony-control-kpi-icon"><i class="bi <?= $esc($kpi['icon']) ?>" aria-hidden="true"></i></span>
                <div>
                    <span class="telephony-control-kpi-label"><?= $esc($kpi['label']) ?></span>
                    <strong><?= $esc($kpi['valor']) ?></strong>
                </div>
            </article>
        <?php endforeach; ?>
    </section>

    <div class="telephony-control-two-col">
        <section class="dashboard-panel telephony-control-panel">
            <div class="telephony-control-panel-heading">
                <div><span class="telephony-eyebrow">TENDENCIA</span>
                    <h2>Actividad diaria</h2>
                    <p>Hasta los últimos 14 días del intervalo seleccionado.</p>
                </div>
            </div>
            <?php
            $maxTendencia = 1;
            foreach (($stats['tendencia'] ?? []) as $dia) {
                $maxTendencia = max($maxTendencia, (int)$dia['atenciones']);
            }
            ?>
            <div class="telephony-control-trend" role="img" aria-label="Gráfica de atenciones y conversaciones verificadas por día">
                <?php foreach (($stats['tendencia'] ?? []) as $dia):
                    $vAtenciones = (int)$dia['atenciones'];
                    $vEfectivas = (int)$dia['efectivas'];
                    $h1 = $vAtenciones > 0 ? max(7, round(100 * $vAtenciones / $maxTendencia)) : 0;
                    $h2 = $vEfectivas > 0 ? max(7, round(100 * $vEfectivas / $maxTendencia)) : 0;
                ?>
                    <div class="telephony-control-day"
                        title="<?= $esc($dia['fecha'] . ' · ' . $vAtenciones . ' atenciones · ' . $vEfectivas . ' conversaciones') ?>">
                        <div class="telephony-control-day-bars">
                            <span class="telephony-control-day-total" style="height:<?= (int)$h1 ?>%"></span>
                            <span class="telephony-control-day-effective" style="height:<?= (int)$h2 ?>%"></span>
                        </div>
                        <small><?= $esc(substr($dia['fecha'], 8, 2) . '/' . substr($dia['fecha'], 5, 2)) ?></small>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="telephony-control-legend">
                <span><i class="is-all"></i> Atenciones</span>
                <span><i class="is-effective"></i> Conversaciones verificadas</span>
            </div>
        </section>

        <section class="dashboard-panel telephony-control-panel">
            <div class="telephony-control-panel-heading">
                <div><span class="telephony-eyebrow">DISTRIBUCIÓN</span>
                    <h2>Actividad por perfil</h2>
                    <p>Atenciones y duración agrupadas por rol.</p>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table telephony-control-table align-middle mb-0">
                    <thead><tr><th>Rol</th><th>Atenciones</th><th>Tiempo</th></tr></thead>
                    <tbody>
                        <?php foreach (($stats['por_rol'] ?? []) as $rol): ?>
                            <tr>
                                <td><strong><?= $esc($rol['rol']) ?></strong></td>
                                <td><?= $numero($rol['atenciones']) ?></td>
                                <td><?= $esc($tiempo($rol['segundos'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($stats['por_rol'])): ?>
                            <tr><td colspan="3" class="text-center text-muted py-4">Sin registros en este periodo.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <section class="dashboard-panel telephony-control-panel">
        <div class="telephony-control-panel-heading">
            <div>
                <span class="telephony-eyebrow">DESEMPEÑO POR PERSONA</span>
                <h2>Ranking de actividad telefónica</h2>
                <p>Identidad verificada mediante el registro del proceso telefónico. No atribuye llamadas antiguas al usuario actual de una extensión.</p>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table telephony-control-table align-middle mb-0">
                <thead><tr><th>#</th><th>Usuario</th><th>Rol</th><th>Atenciones</th><th>Conectadas</th><th>Conversaciones</th><th>Salientes</th><th>Entrantes</th><th>Tiempo</th></tr></thead>
                <tbody>
                <?php foreach (($stats['por_usuario'] ?? []) as $i => $user): ?>
                    <tr>
                        <td class="telephony-rank-number"><?= (int)$i + 1 ?></td>
                        <td><strong><?= $esc($user['usuario']) ?></strong></td>
                        <td><span class="telephony-process-badge"><?= $esc($user['rol']) ?></span></td>
                        <td><?= $numero($user['atenciones']) ?></td>
                        <td><?= $numero($user['conectadas']) ?></td>
                        <td><?= $numero($user['efectivas']) ?></td>
                        <td><?= $numero($user['salientes']) ?></td>
                        <td><?= $numero($user['entrantes']) ?></td>
                        <td class="telephony-control-time"><?= $esc($tiempo($user['segundos'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($stats['por_usuario'])): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">No hay usuarios con llamadas atribuibles en este intervalo.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if (($stats['sin_atribucion'] ?? 0) > 0): ?>
            <p class="telephony-control-note">
                <i class="bi bi-shield-exclamation" aria-hidden="true"></i>
                <?= $numero($stats['sin_atribucion']) ?> atenciones no se incluyen en el ranking individual porque su propietario no está verificado.
            </p>
        <?php endif; ?>
    </section>

    <section class="dashboard-panel telephony-control-panel">
        <div class="telephony-control-panel-heading">
            <div>
                <span class="telephony-eyebrow">CENTRALITA</span>
                <h2>Actividad por extensión</h2>
                <p>Vista técnica: una extensión puede tener historial anterior a su asignación actual.</p>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table telephony-control-table align-middle mb-0">
                <thead><tr><th>Extensión</th><th>Atenciones</th><th>Conectadas</th><th>Salientes</th><th>Entrantes</th><th>Tiempo</th></tr></thead>
                <tbody>
                <?php foreach (($stats['por_extension'] ?? []) as $ext): ?>
                    <tr><td><strong><?= $esc($ext['extension']) ?></strong></td>
                        <td><?= $numero($ext['atenciones']) ?></td>
                        <td><?= $numero($ext['conectadas']) ?></td>
                        <td><?= $numero($ext['salientes']) ?></td>
                        <td><?= $numero($ext['entrantes']) ?></td>
                        <td><?= $esc($tiempo($ext['segundos'])) ?></td></tr>
                <?php endforeach; ?>
                <?php if (empty($stats['por_extension'])): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">Sin actividad por extensión en el intervalo.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="dashboard-panel telephony-control-panel" data-telephony-control-history>
        <div class="telephony-control-panel-heading telephony-history-heading">
            <div>
                <span class="telephony-eyebrow">REGISTRO AUDITABLE</span>
                <h2>Historial general de la centralita</h2>
                <p>Se muestran hasta 500 atenciones recientes del intervalo; el buscador y la paginación no alteran los totales.</p>
            </div>
            <label class="telephony-control-history-search">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input type="search" class="form-control" placeholder="Buscar persona, número, extensión…"
                    aria-label="Buscar llamadas por persona, número o extensión"
                    data-telephony-control-search autocomplete="off">
            </label>
        </div>
        <div class="table-responsive">
            <table class="table telephony-control-table align-middle mb-0">
                <thead><tr><th>Fecha</th><th>Usuario</th><th>Origen</th><th>Ext.</th><th>Dirección</th><th>Número</th><th>Estado PBX</th><th>Resultado</th><th>Duración</th></tr></thead>
                <tbody data-telephony-control-history-rows>
                <?php foreach (($stats['historial'] ?? []) as $call): ?>
                    <tr data-telephony-control-history-row>
                        <td><?= $esc($call['fecha']) ?></td>
                        <td><?= $esc($call['usuario']) ?></td>
                        <td><span class="telephony-process-badge"><?= $esc($call['proceso']) ?></span></td>
                        <td><?= $esc($call['extension']) ?></td>
                        <td><?= $esc($call['direccion']) ?></td>
                        <td><?= $esc($call['numero'] ?: 'No disponible') ?></td>
                        <td><?= $call['conectada'] ? 'Conectada' : 'Sin conexión confirmada' ?></td>
                        <td>
                            <?php
                            $resultadoLlamada = (string)($call['resultado'] ?? '');
                            $textoResultado = $call['efectiva']
                                ? 'Conversación verificada'
                                : ($resultadoLlamada !== ''
                                    ? ($etiquetasResultado[$resultadoLlamada] ??
                                        ucfirst(strtolower(str_replace('_', ' ', $resultadoLlamada))))
                                    : ($call['proceso'] === 'Recepción'
                                        ? ($call['conectada'] ? 'Atendida' : 'Sin respuesta confirmada')
                                        : 'Sin clasificar'));
                            ?>
                            <?= $esc($textoResultado) ?>
                        </td>
                        <td class="telephony-control-time"><?= $esc($tiempo($call['segundos'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($stats['historial'])): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">No hay llamadas en el intervalo seleccionado.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="telephony-control-history-footer">
            <span data-telephony-control-count role="status" aria-live="polite">
                <?= $numero($stats['historial_total'] ?? 0) ?> atenciones en el intervalo.
            </span>
            <nav data-telephony-control-pages aria-label="Páginas del historial" class="telephony-control-pages" hidden></nav>
        </div>
    </section>
</div>
