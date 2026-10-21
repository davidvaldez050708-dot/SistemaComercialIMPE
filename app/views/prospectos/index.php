<?php
$escaparProspecto = static function ($valor) {
    return htmlspecialchars((string)($valor ?? ''), ENT_QUOTES, 'UTF-8');
};
$registrosProspectos = $listado['registros'] ?? [];
$paginaProspectos = (int)($listado['pagina'] ?? 1);
$paginasProspectos = (int)($listado['paginas'] ?? 1);
?>
<style>
.prospectos-page{font-family:Manrope,sans-serif;color:#17233e}
.prospectos-page .prospectos-panel{background:#fff;border:1px solid #e1e8f0;border-radius:16px;padding:24px;box-shadow:0 8px 24px rgba(24,43,72,.04)}
.prospectos-page .prospectos-heading{display:flex;justify-content:space-between;align-items:center;gap:18px;flex-wrap:wrap;margin-bottom:22px}
.prospectos-page .prospectos-icon{display:inline-flex;align-items:center;justify-content:center;width:52px;height:52px;border-radius:14px;color:#123b86;background:#e9f6f3;font-size:24px}
.prospectos-page .prospectos-heading-main{display:flex;align-items:center;gap:16px}
.prospectos-page h2{font-size:18px;font-weight:800;margin:0 0 4px}
.prospectos-page p{font-size:13px;color:#617189;margin:0}
.prospectos-page .prospectos-total{border:1px solid #e1e8f0;border-radius:14px;padding:10px 20px;text-align:center;min-width:118px}
.prospectos-page .prospectos-total strong{font-size:24px;display:block}
.prospectos-page .prospectos-total span{font-size:12px;color:#617189}
.prospectos-page .prospectos-table-wrap{width:100%;overflow-x:auto}
.prospectos-page table{width:100%;border-collapse:collapse;font-size:12px;white-space:nowrap}
.prospectos-page .prospectos-table-wrap table,
.prospectos-page .prospectos-table-wrap table th,
.prospectos-page .prospectos-table-wrap table td,
.prospectos-page .prospectos-table-wrap table span,
.prospectos-page .prospectos-table-wrap table a{font-family:'Manrope',sans-serif !important;font-size:12px !important;letter-spacing:normal !important;line-height:1.5 !important}
.prospectos-page .prospectos-table-wrap table th{font-weight:800 !important}
.prospectos-page .prospectos-table-wrap table td{font-weight:600 !important}
.prospectos-page .prospectos-table-wrap table td.prospectos-name{font-weight:700 !important}
.prospectos-page th{background:#eef4fa;color:#173a65;font-weight:800;text-align:left;padding:12px 14px;border-bottom:1px solid #d9e4ef}
.prospectos-page td{padding:12px 14px;border-bottom:1px solid #e9eef5;color:#42516a}
.prospectos-page tbody tr:hover{background:#f7fafc}
.prospectos-page .prospectos-name{font-weight:750;color:#17233e}
.prospectos-page a{color:#174b8a;text-decoration:none}
.prospectos-page .prospectos-empty{text-align:center;padding:35px;color:#617189}
.prospectos-page .prospectos-pagination{display:flex;align-items:center;justify-content:flex-end;gap:12px;margin-top:18px;font-size:13px}
.prospectos-page .prospectos-pagination a{border:1px solid #dce5ef;border-radius:9px;padding:8px 12px}
@media(max-width:767px){.prospectos-page .prospectos-panel{padding:16px}}
</style>
<div class="prospectos-page">
    <?php if ($mensajeError !== ''): ?>
        <div class="alert alert-warning" role="alert"><?= $escaparProspecto($mensajeError) ?></div>
    <?php endif; ?>
    <section class="prospectos-panel">
        <div class="prospectos-heading">
            <div class="prospectos-heading-main">
                <span class="prospectos-icon"><i class="bi bi-people"></i></span>
                <div>
                    <h2>Prospectos registrados</h2>
                    <p>Información recibida desde el formulario de registro. Consulta de solo lectura.</p>
                </div>
            </div>
            <div class="prospectos-total"><strong><?= (int)($listado['total'] ?? 0) ?></strong><span>prospectos</span></div>
        </div>
        <form method="get" class="row g-3 mb-3" data-prospectos-filtros>
          <input type="hidden" name="controller" value="prospecto"><input type="hidden" name="action" value="index">
          <div class="col-md-6"><label for="prospectoBuscar" class="form-label">Nombre o teléfono</label><input class="form-control" type="search" id="prospectoBuscar" name="buscar" maxlength="180" value="<?= $escaparProspecto($_GET['buscar']??'') ?>"></div>
          <div class="col-md-6"><label for="prospectoAliado" class="form-label">Aliado de origen</label><select class="form-select" id="prospectoAliado" name="aliado_id"><option value="">Todos los orígenes</option><option value="general" <?= ($_GET['aliado_id']??'')==='general'?'selected':'' ?>>Registro general</option><?php foreach (($listado['aliados']??[]) as $origenProspecto): ?><option value="<?= (int)$origenProspecto['id'] ?>" <?= (string)($_GET['aliado_id']??'')===(string)$origenProspecto['id']?'selected':'' ?>><?= $escaparProspecto($origenProspecto['nombre']) ?></option><?php endforeach; ?></select></div>
          <div class="col-12"><button class="btn btn-system-light" type="submit">Buscar</button></div>
        </form>
        <div class="prospectos-table-wrap">
            <table>
                <thead><tr>
                    <th>Fecha de registro</th><th>Nombre completo</th><th>Correo electrónico</th>
                    <th>Teléfono</th><th>Tel. secundario</th><th>Tel. recomendado</th>
                    <th>Aliado de origen</th><th>Perfil de interés</th><th>Estado</th><th>Municipio</th>
                    <th>Lugar de trabajo</th><th>Cargo</th><th>Fecha de nacimiento</th><th>Origen</th>
                </tr></thead>
                <tbody>
                <?php foreach ($registrosProspectos as $prospecto): ?>
                    <tr>
                        <td><?= $escaparProspecto($prospecto['created_at']) ?></td>
                        <td class="prospectos-name"><?= $escaparProspecto(trim($prospecto['nombre'] . ' ' . $prospecto['apellido'])) ?></td>
                        <td><?= $escaparProspecto($prospecto['correo']) ?></td>
                        <td><?= $escaparProspecto($prospecto['movil']) ?></td>
                        <td><?= $escaparProspecto($prospecto['movil_secundario'] ?: '—') ?></td>
                        <td><?= $escaparProspecto($prospecto['tel_recomendado'] ?: '—') ?></td>
                        <td><?= $escaparProspecto($prospecto['aliado_nombre'] ?? 'Registro general') ?></td>
                        <td><?= $escaparProspecto($prospecto['perfil_interes']) ?></td>
                        <td><?= $escaparProspecto($prospecto['estado'] ?: '—') ?></td>
                        <td><?= $escaparProspecto($prospecto['municipio'] ?: '—') ?></td>
                        <td><?= $escaparProspecto($prospecto['lugar_laboras']) ?></td>
                        <td><?= $escaparProspecto($prospecto['cargo_puesto'] ?: '—') ?></td>
                        <td><?= $escaparProspecto($prospecto['fecha_nacimiento']) ?></td>
                        <td><?= $escaparProspecto($prospecto['origen']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$registrosProspectos): ?>
                    <tr><td colspan="14" class="prospectos-empty">No hay registros disponibles.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($paginasProspectos > 1): ?>
        <nav class="prospectos-pagination" aria-label="Páginas de prospectos">
            <?php if ($paginaProspectos > 1): ?>
                <a href="<?= BASE_URL ?>index.php?controller=prospecto&amp;action=index&amp;<?= $escaparProspecto(http_build_query(['pagina'=>$paginaProspectos-1,'buscar'=>$_GET['buscar']??'','aliado_id'=>$_GET['aliado_id']??''])) ?>">Anterior</a>
            <?php endif; ?>
            <span>Página <?= $paginaProspectos ?> de <?= $paginasProspectos ?></span>
            <?php if ($paginaProspectos < $paginasProspectos): ?>
                <a href="<?= BASE_URL ?>index.php?controller=prospecto&amp;action=index&amp;<?= $escaparProspecto(http_build_query(['pagina'=>$paginaProspectos+1,'buscar'=>$_GET['buscar']??'','aliado_id'=>$_GET['aliado_id']??''])) ?>">Siguiente</a>
            <?php endif; ?>
        </nav>
        <?php endif; ?>
    </section>
</div>
