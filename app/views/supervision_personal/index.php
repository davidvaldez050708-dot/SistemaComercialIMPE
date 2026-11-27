<?php
require_once __DIR__.'/../../helpers/AvatarHelper.php';
$esc=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
?>
<link rel="stylesheet" href="<?= BASE_URL ?>public/css/supervision_personal.css?v=<?= filemtime(ROOT_PATH.'/public/css/supervision_personal.css') ?>">
<section class="dashboard-panel personal-supervision" data-personal-supervision
    data-endpoint="<?= $esc(BASE_URL.'index.php?controller=supervisionPersonal') ?>"
    data-can-manage="<?= !empty($datos['permisos']['gestionar'])?'1':'0' ?>"
    data-csrf="<?= $esc($_SESSION['csrf_token']??'') ?>">
 <header class="personal-heading"><div><span class="personal-eyebrow">GESTIÓN</span><h2>Supervisión de personal</h2>
 <p>Asigna los analistas y asesores que puede supervisar cada Cuenta Clave.</p></div>
 <button class="btn btn-system-cancel<?= empty($datos['permisos']['bitacora'])?' d-none':'' ?>" type="button" data-personal-history><i class="bi bi-clock-history" aria-hidden="true"></i> Bitácora</button></header>
 <?php if(!$datos['estado']['activo']): ?>
 <div class="personal-status" role="status"><span class="personal-status-icon"><i class="bi bi-info-circle" aria-hidden="true"></i></span><div><strong>Supervisión en modo consulta</strong><p>Puedes revisar los equipos actuales. Los cambios se habilitarán al activar la supervisión de personal.</p></div><span class="personal-status-badge">Pendiente de activación</span></div>
 <?php elseif(empty($datos['permisos']['gestionar'])): ?><div class="personal-status" role="status"><span class="personal-status-icon"><i class="bi bi-eye" aria-hidden="true"></i></span><div><strong>Acceso de consulta</strong><p>Tu permiso permite revisar los equipos de supervisión.</p></div></div><?php endif; ?>
 <div class="personal-toolbar"><label for="personalKamSearch">Buscar Cuenta Clave</label><div class="personal-search-control"><i class="bi bi-search" aria-hidden="true"></i><input type="search" id="personalKamSearch" placeholder="Buscar por nombre o apellido…" autocomplete="off" data-personal-kam-search></div></div>
 <div class="personal-kam-grid" data-personal-cards>
 <?php foreach($datos['cuentas_clave'] as $kam): $nombre=trim($kam['nombre'].' '.$kam['apellidos']); ?>
 <button class="personal-kam-card" type="button" data-kam-card="<?= (int)$kam['id'] ?>" data-kam-name="<?= $esc($nombre) ?>" aria-label="Administrar supervisión de <?= $esc($nombre) ?>">
 <?= renderAvatarUsuario($kam['nombre'],$kam['apellidos'],'Cuenta Clave',$kam['foto_perfil'],'md','cuenta-clave','',false) ?>
 <span class="personal-card-copy"><strong><?= $esc($nombre) ?></strong><span>Cuenta Clave</span><small><b data-personal-count><?= (int)$kam['colaboradores'] ?></b> colaboradores supervisados</small></span><i class="bi bi-chevron-right" aria-hidden="true"></i>
 </button><?php endforeach; ?>
 </div>
 <p class="personal-empty d-none" role="status" data-personal-no-kams>No hay Cuentas Clave que coincidan con la búsqueda.</p>
 <?php if(!$datos['cuentas_clave']): ?><p class="personal-empty">No hay Cuentas Clave activas para administrar.</p><?php endif; ?>
</section>
<div class="modal fade" id="modalSupervisionPersonal" tabindex="-1" aria-labelledby="tituloSupervisionPersonal" aria-hidden="true">
 <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><div class="modal-content system-form-modal">
  <div class="modal-header system-form-modal-header"><div><h5 class="system-form-modal-title" id="tituloSupervisionPersonal">Administrar colaboradores</h5><p class="system-form-modal-subtitle" data-personal-kam-label></p></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
  <div class="modal-body system-form-modal-body" data-personal-body>
   <div class="personal-loading" role="status" data-personal-loading><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Cargando colaboradores…</div>
   <div class="d-none" data-personal-editor>
    <div class="personal-filter-grid"><div><label for="personalSearch">Buscar colaborador</label><input id="personalSearch" type="search" class="form-control" placeholder="Nombre o apellido" autocomplete="off" data-personal-search></div>
     <div><label for="personalRole">Perfil</label><select id="personalRole" class="form-select" data-native-select data-personal-role><option value="">Todos los perfiles</option><option value="4">Analistas de datos</option><option value="3">Asesores de ventas</option></select></div>
     <div><label for="personalSelection">Selección</label><select id="personalSelection" class="form-select" data-native-select data-personal-filter><option value="">Todos</option><option value="selected">Seleccionados</option><option value="available">Disponibles</option></select></div></div>
    <div class="personal-selection-toolbar"><span data-personal-selection-count>0 seleccionados</span><button type="button" class="btn btn-system-cancel btn-sm" data-personal-select-visible><i class="bi bi-check2-square" aria-hidden="true"></i> Seleccionar visibles</button></div>
    <div class="personal-staff-list" data-personal-list></div><p class="personal-empty d-none" data-personal-empty>No hay colaboradores que coincidan con estos filtros.</p>
   </div>
   <div class="d-none" data-personal-review><h6>Revisa los cambios</h6><p class="small text-muted">Se actualizarán los accesos de supervisión en los módulos autorizados del rol.</p>
    <div class="personal-review-grid"><section><h6><i class="bi bi-person-plus" aria-hidden="true"></i> Se agregarán <span data-personal-added-count></span></h6><ul data-personal-added></ul></section>
     <section><h6><i class="bi bi-person-dash" aria-hidden="true"></i> Se retirarán <span data-personal-removed-count></span></h6><ul data-personal-removed></ul></section></div></div>
   <p class="personal-empty d-none" role="alert" data-personal-load-error>No se pudo cargar el equipo. Cierra esta ventana y vuelve a intentarlo.</p>
  </div>
  <div class="modal-footer system-form-modal-footer"><button type="button" class="btn btn-system-cancel" data-bs-dismiss="modal" data-personal-cancel>Cancelar</button><button type="button" class="btn btn-system-cancel d-none" data-personal-back>Volver a selección</button>
   <button type="button" class="btn btn-system-save" data-personal-preview disabled><i class="bi bi-check2-square" aria-hidden="true"></i> Revisar cambios</button>
   <button type="button" class="btn btn-system-save d-none" data-personal-save><i class="bi bi-check2-circle" aria-hidden="true"></i> Confirmar y guardar</button></div>
 </div></div>
</div>
<div class="modal fade" id="modalBitacoraSupervision" tabindex="-1" aria-labelledby="tituloBitacoraSupervision" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><div class="modal-content system-form-modal">
 <div class="modal-header system-form-modal-header"><div><h5 id="tituloBitacoraSupervision" class="system-form-modal-title">Bitácora de supervisión</h5><p class="system-form-modal-subtitle">Responsable, fecha y cambios realizados.</p></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
 <div class="modal-body system-form-modal-body" data-personal-history-list></div><div class="modal-footer system-form-modal-footer"><span class="personal-history-pagination"><i class="bi bi-journal-text" aria-hidden="true"></i><span data-personal-history-page>Página 1</span></span><button type="button" class="btn btn-system-cancel" data-personal-history-prev><i class="bi bi-chevron-left" aria-hidden="true"></i> Anterior</button><button type="button" class="btn btn-system-cancel" data-personal-history-next>Siguiente <i class="bi bi-chevron-right" aria-hidden="true"></i></button></div>
</div></div></div>
<script src="<?= BASE_URL ?>public/javascript/supervision_personal.js?v=<?= filemtime(ROOT_PATH.'/public/javascript/supervision_personal.js') ?>"></script>
