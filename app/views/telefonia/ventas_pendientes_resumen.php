<?php
// Recibe el mismo corte autorizado del reporte o de Desempeño. No consulta personas.
$pendientes=$datosPendientes['organizacion']??[];
$personasPendientes=$datosPendientes['personas_resumen']??[];
$escPendientes=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
?>
<style>
[data-sales-pending-summary] .sales-pending-table{overflow-x:auto;border:1px solid var(--color-border,#dce4f0);border-radius:12px;margin:12px 0}
[data-sales-pending-summary] table{width:100%;min-width:680px;border-collapse:collapse;font-size:12px}
[data-sales-pending-summary] th,[data-sales-pending-summary] td{padding:12px;text-align:left;border-bottom:1px solid var(--color-border,#dce4f0)}
[data-sales-pending-summary] thead th{color:var(--color-primary,#273a8a);background:#edf2fa;font-weight:700}
[data-sales-pending-summary] tbody th{font-weight:600}
[data-sales-pending-summary] tbody tr:last-child th,[data-sales-pending-summary] tbody tr:last-child td{border-bottom:0}
</style>
<section class="dashboard-panel" data-sales-pending-summary>
    <div class="performance-panel-heading"><div><span class="performance-eyebrow">SEGUIMIENTO DE LLAMADAS</span><h2 class="panel-title">Pendientes y vencimientos actuales</h2><p class="page-subtitle">Estado al consultar<?= !empty($pendientes['consultado_at'])?' · '.$escPendientes($pendientes['consultado_at']).' · hora de México':'' ?>. Corresponde a los asesores seleccionados; no es el estado histórico del periodo de llamadas.</p></div></div>
    <?php if (!empty($pendientes['disponible'])): ?>
        <div class="performance-kpi-grid">
            <?php foreach ([['calendar-event','Programadas pendientes','programadas'],['calendar-x','Programadas vencidas','vencidas'],['telephone-forward','Devoluciones pendientes','devoluciones'],['exclamation-circle','Devoluciones vencidas','devoluciones_vencidas']] as [$iconoPendiente,$nombrePendiente,$clavePendiente]): ?>
            <article><span class="performance-kpi-icon"><i class="bi bi-<?= $iconoPendiente ?>" aria-hidden="true"></i></span><div><strong><?= (int)($pendientes[$clavePendiente]??0) ?></strong><span><?= $nombrePendiente ?></span></div></article>
            <?php endforeach; ?>
        </div>
        <div class="sales-pending-table" role="region" aria-label="Pendientes por asesor" tabindex="0"><table><thead><tr><th>Asesor</th><th>Programadas pendientes</th><th>De ellas, vencidas</th><th>Devoluciones pendientes</th><th>De ellas, vencidas</th></tr></thead><tbody>
            <?php foreach ($personasPendientes as $personaPendiente): $cortePendiente=$pendientes['personas'][(int)$personaPendiente['id']]??[]; ?>
            <tr><th scope="row"><?= $escPendientes($personaPendiente['nombre_completo']) ?></th><?php foreach (['programadas','vencidas','devoluciones','devoluciones_vencidas'] as $clavePendiente): ?><td><?= (int)($cortePendiente[$clavePendiente]??0) ?></td><?php endforeach; ?></tr>
            <?php endforeach; ?>
            <?php if (!$personasPendientes): ?><tr><td colspan="5">No hay asesores dentro del alcance seleccionado.</td></tr><?php endif; ?>
        </tbody></table></div>
        <p class="page-subtitle">Las programadas vencen en su fecha y hora. Las devoluciones, al finalizar el día de la solicitud. Las vencidas ya están incluidas en los pendientes. Leer un aviso no atiende la tarea; cerrar un recordatorio no registra una llamada ni cambia el ranking.</p>
        <?php if (!empty($pendientes['devoluciones_sin_fecha'])): ?><p role="status"><?= (int)$pendientes['devoluciones_sin_fecha'] ?> devoluciones no tienen fecha de solicitud confirmada; se muestran como pendientes sin atribuirles un vencimiento.</p><?php endif; ?>
    <?php else: ?><p role="status">No fue posible consultar los pendientes con la estructura instalada.</p><?php endif; ?>
</section>
