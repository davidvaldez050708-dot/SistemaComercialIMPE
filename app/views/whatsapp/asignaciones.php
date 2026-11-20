<?php
$esc=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$colaUrl=BASE_URL.'index.php?controller=whatsapp&action=asignaciones';
?>
<div class="dashboard-panel p-4" data-wa-assignment-root>
    <div class="d-flex justify-content-between align-items-center gap-3 mb-3 flex-wrap">
        <div><h2 class="h5 mb-1">Conversaciones sin asignar</h2><p class="mb-0 text-muted"><span data-wa-pending-count><?= (int)$pendientes['total'] ?></span> pendientes de atención</p></div>
        <a class="btn btn-system-light" href="<?= BASE_URL ?>index.php?controller=whatsapp">Volver a conversaciones</a>
    </div>
    <p>Los contactos con un expediente reconocido se dirigen a su responsable. Si un teléfono corresponde a varios expedientes, revisa cuál debe atenderlo antes de asignar. Asignar un chat no modifica la cartera comercial.</p>
    <form method="GET" action="<?= BASE_URL ?>index.php" class="d-flex gap-2 mb-3">
        <input type="hidden" name="controller" value="whatsapp"><input type="hidden" name="action" value="asignaciones">
        <input class="form-control" type="search" name="buscar" value="<?= $esc($busqueda) ?>" placeholder="Buscar nombre o teléfono" aria-label="Buscar conversación" maxlength="100">
        <button class="btn btn-system-save" type="submit">Buscar</button>
    </form>
    <div role="status" aria-live="polite" data-wa-assignment-feedback></div>
    <?php if (!$candidatos): ?><div class="alert alert-warning">No hay usuarios activos con permisos para consultar y enviar mensajes de WhatsApp.</div><?php endif; ?>
    <div class="table-responsive">
        <table class="table users-table align-middle"><thead><tr><th>Contacto</th><th>Canal</th><th>Último mensaje</th><th>Responsable</th></tr></thead><tbody>
        <?php foreach($pendientes['filas'] as $chat): ?>
            <tr data-wa-assignment-row>
                <td><a href="<?= BASE_URL ?>index.php?controller=whatsapp&amp;conversacion_id=<?= (int)$chat['id'] ?>"><?= $esc($chat['nombre_contacto']?:'Sin nombre') ?></a><br><small><?= $esc($chat['telefono_normalizado']) ?></small></td>
                <td><?= $esc($chat['canal']) ?></td>
                <td><?= $esc($chat['ultimo_mensaje_preview']) ?><br><small><?= $esc($chat['ultimo_mensaje_at']) ?></small></td>
                <td>
                    <form action="<?= BASE_URL ?>index.php?controller=whatsapp&amp;action=asignarResponsable" method="POST" data-wa-assignment-form class="d-flex gap-2 align-items-center">
                        <input type="hidden" name="csrf_token" value="<?= $esc($_SESSION['csrf_token']??'') ?>">
                        <input type="hidden" name="conversacion_id" value="<?= (int)$chat['id'] ?>">
                        <select name="responsable_id" class="form-select" required aria-label="Responsable para <?= $esc($chat['nombre_contacto']?:'contacto sin nombre') ?>" <?= !$candidatos?'disabled':'' ?>>
                            <option value="">Seleccionar responsable</option>
                            <?php foreach($candidatos as $usuario): ?><option value="<?= (int)$usuario['id'] ?>"><?= $esc($usuario['nombre'].' · '.$usuario['rol']) ?></option><?php endforeach; ?>
                        </select>
                        <button class="btn btn-system-save" type="submit" <?= !$candidatos?'disabled':'' ?>>Asignar</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
    </div>
    <p class="text-muted" <?= $pendientes['filas']?'hidden':'' ?> data-wa-assignment-empty>No hay conversaciones pendientes en esta búsqueda.</p>
    <nav aria-label="Páginas de conversaciones" class="d-flex align-items-center gap-3 mt-3">
        <?php if($pendientes['pagina']>1): ?><a href="<?= $esc($colaUrl.'&buscar='.urlencode($busqueda).'&pagina='.($pendientes['pagina']-1)) ?>">Anterior</a><?php endif; ?>
        <span>Página <?= (int)$pendientes['pagina'] ?> de <?= (int)$pendientes['paginas'] ?></span>
        <?php if($pendientes['pagina']<$pendientes['paginas']): ?><a href="<?= $esc($colaUrl.'&buscar='.urlencode($busqueda).'&pagina='.($pendientes['pagina']+1)) ?>">Siguiente</a><?php endif; ?>
    </nav>
</div>
<script src="<?= BASE_URL ?>public/javascript/whatsapp_asignacion.js?v=<?= (int)filemtime(__DIR__.'/../../../public/javascript/whatsapp_asignacion.js') ?>"></script>
