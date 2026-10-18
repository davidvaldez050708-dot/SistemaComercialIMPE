<?php $esc=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); ?>
<main class="admin-content"><section class="card p-4 m-3">
 <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3"><div><h2 class="h4">Llamadas por devolver</h2><p class="text-muted mb-0">Contactos que recepción dejó pendientes para tu extensión.</p></div><a class="btn btn-outline-primary" href="<?= BASE_URL ?>index.php?controller=home&action=index">Volver al inicio</a></div>
 <?php if(!$devoluciones): ?><p class="text-muted mb-0">No tienes devoluciones pendientes.</p><?php else: ?>
 <div class="table-responsive"><table class="table align-middle"><thead><tr><th>Contacto</th><th>Teléfono</th><th>Institución y motivo</th><th>Recepción</th><th>Acción</th></tr></thead><tbody>
 <?php foreach($devoluciones as $item): ?><tr><td><?= $esc($item['contacto']) ?></td><td><?= $esc($item['telefono']) ?></td><td><?= nl2br($esc($item['nota'])) ?></td><td>Ext. <?= $esc($item['origen_extension']) ?></td><td><form method="post" action="<?= BASE_URL ?>index.php?controller=telefonia&action=devoluciones"><input type="hidden" name="csrf_token" value="<?= $esc($_SESSION['csrf_token']) ?>"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><input type="hidden" name="version" value="<?= (int)$item['version'] ?>"><button class="btn btn-outline-primary btn-sm" type="submit">Marcar atendida</button></form></td></tr><?php endforeach; ?>
 </tbody></table></div><p class="text-muted small mb-0">Marcar atendida sólo cierra este pendiente. El resultado y la verificación de datos se registran en la llamada correspondiente.</p>
 <?php endif; ?>
</section></main>

<?php $aviso=$_SESSION['telefonia_devolucion_aviso']??null;unset($_SESSION['telefonia_devolucion_aviso']);if($aviso): ?>
<script>document.addEventListener('DOMContentLoaded',()=>{const msg=<?= json_encode($aviso,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;window.IMPE_TELEPHONY_NOTIFY?.(msg.mensaje,msg.error);});</script>
<?php endif; ?>
