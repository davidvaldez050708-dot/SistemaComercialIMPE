<?php
$esc = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
?>
<section class="dashboard-panel" style="padding:22px">
 <div class="d-flex justify-content-between flex-wrap gap-3 align-items-start mb-4">
  <div><span class="text-muted text-uppercase">Aliados · Internacionales</span><h2 class="panel-title mt-2">Instituciones externas</h2><p class="text-muted mb-0">Configuraciones de formularios sin territorio mexicano asignado. Consulta disponible únicamente para Administración.</p></div>
  <a class="btn btn-system-light" href="<?= BASE_URL ?>index.php?controller=aliado&amp;action=index"><i class="bi bi-arrow-left"></i> Volver a Aliados</a>
 </div>
 <?php if ($errorExternos !== ''): ?><div class="alert alert-warning" role="alert"><?= $esc($errorExternos) ?></div><?php endif; ?>
 <div class="row g-3">
 <?php foreach ($externos as $aliado): ?>
 <?php $enlaceRegistro=BASE_URL.'index.php?controller=formularioPublico&action=registro&aliado='.rawurlencode($aliado['token']);
       $enlaceInscripcion=BASE_URL.'index.php?controller=formularioPublico&action=inscripcion&aliado='.rawurlencode($aliado['token']); ?>
  <div class="col-12 col-xl-6"><article class="card h-100 border rounded-3"><div class="card-body p-4">
   <div class="d-flex justify-content-between gap-2 flex-wrap"><h3 class="h5 mb-1"><?= $esc($aliado['nombre']) ?></h3><span class="badge text-bg-<?= (int)$aliado['activo']===1?'success':'secondary' ?>"><?= (int)$aliado['activo']===1?'Activo':'Inactivo' ?></span></div>
   <p class="text-muted mb-2"><i class="bi bi-geo-alt"></i> <?= $esc($aliado['ubicacion']) ?></p>
   <?php if ($aliado['slug'] === 'handly-lenguaje-center'): ?><p class="mb-2"><strong>Giro:</strong> Educativo · <strong>Contacto:</strong> Adalid Kuribeña · <strong>Teléfono:</strong> 7775635090</p><?php endif; ?>
   <p class="mb-3"><strong>Programas autorizados:</strong> <?= $esc($aliado['programas'] ?: 'Sin programas') ?></p>
   <div class="d-flex flex-wrap gap-2">
   <?php if ((int)$aliado['activo'] === 1): ?>
    <a class="btn btn-system-save" href="<?= $esc($enlaceRegistro) ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-box-arrow-up-right"></i> Formulario de registro</a>
    <a class="btn btn-system-light" href="<?= $esc($enlaceInscripcion) ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-box-arrow-up-right"></i> Formulario de inscripción</a>
    <button type="button" class="btn btn-system-light" data-copy-ally-url="<?= $esc($enlaceRegistro) ?>">Copiar registro</button>
    <button type="button" class="btn btn-system-light" data-copy-ally-url="<?= $esc($enlaceInscripcion) ?>">Copiar inscripción</button>
    <button type="button" class="btn btn-system-light" data-external-ally-qr="<?= $esc($enlaceRegistro) ?>" data-external-ally-title="<?= $esc($aliado['nombre'].' · Registro') ?>"><i class="bi bi-qr-code"></i> QR registro</button>
    <button type="button" class="btn btn-system-light" data-external-ally-qr="<?= $esc($enlaceInscripcion) ?>" data-external-ally-title="<?= $esc($aliado['nombre'].' · Inscripción') ?>"><i class="bi bi-qr-code"></i> QR inscripción</button>
   <?php else: ?><span class="text-muted">Enlaces desactivados.</span><?php endif; ?>
   </div>
  </div></article></div>
 <?php endforeach; ?>
 <?php if (!$externos): ?><div class="col-12"><div class="alert alert-light border">Aún no hay aliados internacionales configurados.</div></div><?php endif; ?>
 </div>
</section>
<section class="dashboard-panel mt-3" style="padding:22px">
 <h3 class="panel-title mb-2">Respuestas recibidas</h3><p class="text-muted">Últimas 150 respuestas de aliados externos. Registro e Inscripción se consultan sin mezclarse con los formularios generales.</p>
 <div class="table-responsive"><table class="table table-hover align-middle">
 <thead><tr><th>Fecha</th><th>Aliado</th><th>Tipo</th><th>Interesado</th><th>Programa</th><th>Correo</th><th>Teléfono</th></tr></thead>
 <tbody>
 <?php foreach($respuestas as $fila): ?><tr>
 <td><?= $esc($fila['created_at']) ?></td><td><?= $esc($fila['aliado']) ?></td>
 <td><?= $esc(ucfirst($fila['tipo'])) ?></td>
 <td><?= $esc(trim((string)$fila['nombre'].' '.(string)$fila['apellido'])) ?></td>
 <td><?= $esc($fila['programa']) ?></td><td><?= $esc($fila['correo']) ?></td><td><?= $esc($fila['movil']) ?></td>
 </tr><?php endforeach; ?>
 <?php if (!$respuestas): ?><tr><td colspan="7" class="text-muted text-center py-4">Todavía no hay respuestas recibidas por enlaces de aliados externos.</td></tr><?php endif; ?>
 </tbody></table></div>
</section>
<div class="modal fade" id="modalQrAliadoExterno" tabindex="-1" aria-labelledby="modalQrAliadoTitulo" aria-hidden="true">
 <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title" id="modalQrAliadoTitulo">Código QR</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
  <div class="modal-body text-center">
   <p class="text-muted">El QR abre el enlace exclusivo del aliado.</p>
   <img data-aliado-qr-image alt="Código QR para abrir formulario del aliado" width="280" height="280" style="max-width:100%;height:auto" hidden>
   <p data-aliado-qr-status role="status">Selecciona una liga para generar su QR.</p>
   <a data-aliado-qr-link href="#" target="_blank" rel="noopener noreferrer" class="small d-block text-break mt-2"></a>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-system-light" data-bs-dismiss="modal">Cerrar</button><a class="btn btn-system-save" data-aliado-qr-download href="#" download="aliado-qr.png">Guardar QR</a></div>
 </div></div>
</div>
<script>
(function(){
const modal=document.getElementById('modalQrAliadoExterno');
const img=modal?.querySelector('[data-aliado-qr-image]');
const status=modal?.querySelector('[data-aliado-qr-status]');
const link=modal?.querySelector('[data-aliado-qr-link]');
const download=modal?.querySelector('[data-aliado-qr-download]');
document.querySelectorAll('[data-external-ally-qr]').forEach(button=>button.addEventListener('click',()=>{
 const url=button.dataset.externalAllyQr||'';
 if(!url.startsWith(window.location.origin+'/') || !window.bootstrap?.Modal) return;
 const qr='https://quickchart.io/qr?'+new URLSearchParams({text:url,size:'360',margin:'3',ecLevel:'M'}).toString();
 modal.querySelector('#modalQrAliadoTitulo').textContent=button.dataset.externalAllyTitle||'Código QR';
 img.hidden=true;status.textContent='Generando código QR…';link.href=url;link.textContent=url;download.href=qr;
 img.onload=()=>{img.hidden=false;status.textContent='Código QR listo.';};
 img.onerror=()=>{status.textContent='No fue posible generar el QR. Puedes copiar el enlace directamente.';};
 img.src=qr;
 window.bootstrap.Modal.getOrCreateInstance(modal).show();
}));
})();
</script>
<script>document.querySelectorAll('[data-copy-ally-url]').forEach(function(button){button.addEventListener('click',async function(){try{await navigator.clipboard.writeText(button.dataset.copyAllyUrl);const previous=button.textContent;button.textContent='¡Enlace copiado!';setTimeout(()=>button.textContent=previous,1800);}catch(e){window.prompt('Copia el enlace:',button.dataset.copyAllyUrl);}});});</script>
