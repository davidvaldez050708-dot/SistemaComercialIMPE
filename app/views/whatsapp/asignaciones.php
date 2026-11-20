<?php
$esc=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$colaUrl=BASE_URL.'index.php?controller=whatsapp&action=asignaciones';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>public/css/whatsapp_institucional.css?v=<?= substr(hash_file('sha256',__DIR__.'/../../../public/css/whatsapp_institucional.css'),0,12) ?>">
<section class="wa-admin" data-wa-assignment-root>
    <header class="wa-admin-header">
        <div class="wa-admin-heading"><span class="wa-admin-icon"><i class="bi bi-person-plus" aria-hidden="true"></i></span><div><span class="wa-eyebrow">WhatsApp Business</span><h2>Conversaciones sin asignar</h2><p class="wa-intro"><span data-wa-pending-count><?= (int)$pendientes['total'] ?></span> conversaciones pendientes de responsable.</p></div></div>
        <a class="wa-button" href="<?= BASE_URL ?>index.php?controller=whatsapp&amp;action=index"><i class="bi bi-arrow-left" aria-hidden="true"></i>Conversaciones</a>
    </header>
    <div class="wa-admin-note"><i class="bi bi-info-circle" aria-hidden="true"></i><p>Revisa aquí los mensajes que aún no tienen responsable. Si un teléfono coincide con varios expedientes, confirma a cuál corresponde. Para Ventas, el acceso al chat depende del prospecto asignado en el embudo; asignar aquí no cambia esa cartera.</p></div>
    <div class="wa-admin-card">
    <form method="GET" action="<?= BASE_URL ?>index.php" class="wa-admin-search">
        <input type="hidden" name="controller" value="whatsapp"><input type="hidden" name="action" value="asignaciones">
        <input type="search" name="buscar" value="<?= $esc($busqueda) ?>" placeholder="Buscar nombre o teléfono" aria-label="Buscar conversación" maxlength="100">
        <button class="wa-button wa-button-primary" type="submit"><i class="bi bi-search" aria-hidden="true"></i>Buscar</button>
    </form>
    <div role="status" aria-live="polite" data-wa-assignment-feedback></div>
    <?php if (!$candidatos): ?><div class="alert alert-warning">No hay usuarios activos con permisos para consultar y enviar mensajes de WhatsApp.</div><?php endif; ?>
    <div class="wa-admin-table-wrap">
        <table><thead><tr><th>Contacto</th><th>Canal</th><th>Último mensaje</th><th>Responsable</th></tr></thead><tbody>
        <?php foreach($pendientes['filas'] as $chat): ?>
            <tr data-wa-assignment-row>
                <td><a href="<?= BASE_URL ?>index.php?controller=whatsapp&amp;action=index&amp;conversacion_id=<?= (int)$chat['id'] ?>"><?= $esc($chat['nombre_contacto']?:'Sin nombre') ?></a><small><?= $esc($chat['telefono_normalizado']) ?></small></td>
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
                        <button class="wa-button wa-button-primary" type="submit" <?= !$candidatos?'disabled':'' ?>>Asignar</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
    </div>
    <div class="wa-admin-empty" <?= $pendientes['filas']?'hidden':'' ?> data-wa-assignment-empty><i class="bi bi-check2-circle" aria-hidden="true"></i><strong>No hay conversaciones pendientes</strong><span>Los nuevos chats sin responsable aparecerán aquí.</span></div>
    <nav aria-label="Páginas de conversaciones" class="wa-admin-pages">
        <?php if($pendientes['pagina']>1): ?><a href="<?= $esc($colaUrl.'&buscar='.urlencode($busqueda).'&pagina='.($pendientes['pagina']-1)) ?>">Anterior</a><?php endif; ?>
        <span>Página <?= (int)$pendientes['pagina'] ?> de <?= (int)$pendientes['paginas'] ?></span>
        <?php if($pendientes['pagina']<$pendientes['paginas']): ?><a href="<?= $esc($colaUrl.'&buscar='.urlencode($busqueda).'&pagina='.($pendientes['pagina']+1)) ?>">Siguiente</a><?php endif; ?>
    </nav>
    </div>
</section>
<script src="<?= BASE_URL ?>public/javascript/whatsapp_asignacion.js?v=<?= is_file(__DIR__.'/../../../public/javascript/whatsapp_asignacion.js')?(int)filemtime(__DIR__.'/../../../public/javascript/whatsapp_asignacion.js'):0 ?>"></script>
