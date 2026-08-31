<?php

$resumenCuentaClave = $resumenCuentaClave ?? [];

?>

<section class="dashboard-metrics-grid">
    <article class="metric-card">
        <span class="metric-icon">
            <i class="bi bi-geo-alt"></i>
        </span>

        <div>
            <p class="metric-value">
                <?= (int)($resumenCuentaClave['territorios_asignados'] ?? 0) ?>
            </p>
            <p class="metric-label">Territorios asignados</p>
        </div>
    </article>

    <article class="metric-card">
        <span class="metric-icon">
            <i class="bi bi-person-badge"></i>
        </span>

        <div>
            <p class="metric-value">
                <?= (int)($resumenCuentaClave['cuentas_clave_activas'] ?? 0) ?>
            </p>
            <p class="metric-label">Asignaciones activas</p>
        </div>
    </article>

    <article class="metric-card">
        <span class="metric-icon">
            <i class="bi bi-person-check"></i>
        </span>

        <div>
            <p class="metric-value">
                <?= (int)($resumenCuentaClave['analistas_vinculados'] ?? 0) ?>
            </p>
            <p class="metric-label">Analistas vinculados</p>
        </div>
    </article>
</section>

<section class="dashboard-panel mt-4">
    <div class="table-panel-header">
        <div>
            <h2 class="panel-title mb-0">Actividad territorial</h2>
            <p class="panel-subtitle mb-0">
                Información disponible según las asignaciones activas de tu cuenta.
            </p>
        </div>
    </div>

    <div class="empty-table-message">
        Los módulos operativos de Cuenta Clave se habilitarán conforme avance el flujo territorial.
    </div>
</section>
