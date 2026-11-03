<?php
// El controlador autoriza la importación mediante Roles y permisos.
$metaEsc=static fn($v)=>htmlspecialchars((string)($v??''),ENT_QUOTES,'UTF-8');
$metaHayPrevia=is_array($metaResumen??null);
$metaAbierto=$metaHayPrevia||!empty($metaAviso);
$metaRuta=BASE_URL.'index.php?controller=prospecto&action=';
$metaSeleccion=$metaResumen['programas_seleccionados']??null;
$metaBloqueada=!empty($metaResumen['clasificacion_bloqueada']);
$metaAnterior=$metaBloqueada&&($metaSeleccion['version']??null)!==2;
?>
<div class="meta-import-overlay" data-meta-overlay data-meta-autoabrir="<?= $metaAbierto?'1':'0' ?>" hidden>
    <button type="button" class="meta-import-backdrop" data-meta-cerrar tabindex="-1" aria-label="Cerrar ventana"></button>
    <section class="meta-import-dialog" role="dialog" aria-modal="true" aria-labelledby="metaImportTitulo" tabindex="-1">
        <header class="meta-import-head">
            <div><span class="central-eyebrow">PROSPECTOS · META ADS</span><h2 id="metaImportTitulo">Importar prospectos</h2>
                <p>Revisa los registros antes de integrarlos al almacén central.</p></div>
            <button class="meta-import-close" type="button" data-meta-cerrar aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </header>
        <div class="meta-import-body">
            <?php if ($metaAviso!==''): ?>
            <p class="central-alert" role="alert"><?= $metaEsc($metaAviso) ?></p>
            <?php endif; ?>
            <?php if (!$metaHayPrevia): ?>
            <form action="<?= $metaEsc($metaRuta.'metaPrevisualizar') ?>" method="post" enctype="multipart/form-data" class="meta-import-upload">
                <input type="hidden" name="csrf_meta" value="<?= $metaEsc($metaCsrf) ?>">
                <label for="metaImportArchivo" class="meta-import-drop">
                    <i class="bi bi-file-earmark-arrow-up" aria-hidden="true"></i>
                    <strong>Seleccionar archivo CSV o Excel</strong>
                    <span>CSV, TSV y Excel .xlsx · Hasta 8 MB y 5,000 registros.</span>
                    <input type="file" id="metaImportArchivo" name="archivo_meta" required data-meta-file>
                    <small data-meta-file-nombre>Selecciona el archivo descargado de los formularios de Meta.</small>
                </label>
                <div class="meta-import-hint"><i class="bi bi-info-circle" aria-hidden="true"></i>
                    Se admite el archivo de Meta en UTF-16. La vista previa no guarda registros ni asigna asesores.
                </div>
                <div class="meta-import-actions"><button class="central-link" type="button" data-meta-cerrar>Cancelar</button>
                    <button class="meta-import-primary" type="submit"><i class="bi bi-search"></i> Revisar archivo</button></div>
            </form>
            <?php else: ?>
            <div class="meta-import-file"><i class="bi bi-file-earmark-spreadsheet" aria-hidden="true"></i>
                <div><strong><?= $metaEsc($metaResumen['archivo']) ?></strong><span><?= $metaResumen['avance']>0?'Importación en curso · Los lotes anteriores ya fueron guardados.':'Vista previa temporal · Ningún registro guardado todavía.' ?></span></div>
            </div>
            <div class="meta-import-kpis">
                <div><small>Filas leídas</small><strong><?= (int)$metaResumen['total_filas'] ?></strong></div>
                <div><small>Importables</small><strong><?= (int)$metaResumen['validas'] ?></strong></div>
                <div><small>Ya en CRM</small><strong><?= (int)$metaResumen['ya_existentes'] ?></strong></div>
                <div><small>Descartadas</small><strong><?= (int)$metaResumen['rechazadas']+(int)$metaResumen['repetidas_archivo'] ?></strong></div>
            </div>
            <p class="meta-import-hint">Los repetidos se identifican por ID del lead de Meta; no se fusionan personas por teléfono. La cantidad «Ya en CRM» es una estimación previa y se volverá a verificar al guardar.</p>
            <?php if (!empty($metaResumen['errores'])): ?>
            <details class="meta-import-issues"><summary>Consultar observaciones de filas (<?= (int)$metaResumen['rechazadas'] ?>)</summary>
                <ul><?php foreach ($metaResumen['errores'] as $e): ?><li><?= $metaEsc($e) ?></li><?php endforeach; ?></ul>
                <?php if ($metaResumen['rechazadas']>count($metaResumen['errores'])): ?><p>Se muestran las primeras 20 observaciones.</p><?php endif; ?>
            </details>
            <?php endif; ?>
            <?php if ($metaResumen['repetidas_archivo']>0): ?><p class="meta-import-hint"><?= (int)$metaResumen['repetidas_archivo'] ?> filas repiten un ID dentro del mismo archivo y no se procesarán.</p><?php endif; ?>
            <form id="metaImportConfirmacion" data-meta-confirmacion action="<?= $metaEsc($metaRuta.'metaConfirmar') ?>" method="post"
                  data-meta-volver="<?= $metaEsc(BASE_URL.'index.php?controller=prospecto&action=index&seccion=meta') ?>">
                <input type="hidden" name="csrf_meta" value="<?= $metaEsc($metaCsrf) ?>">
                <input type="hidden" name="token" value="<?= $metaEsc($metaResumen['token']) ?>">
                <div class="meta-import-subhead"><h3>Clasificación por formulario</h3><p>Elige una vez para todos sus registros. Puedes indicar excepciones por campaña.</p></div>
                <?php if ($metaAnterior): ?>
                <p class="meta-import-hint">Esta carga conserva la clasificación por campaña con la que se inició.</p>
                <?php endif; ?>
                <div class="meta-import-campaigns">
                    <?php foreach ($metaResumen['formularios'] as $f):
                        $seleccion=$metaSeleccion['formularios'][$f['clave']]['codigo']??'';
                    ?>
                    <div class="meta-import-campaign">
                        <div><strong>Formulario: <?= $metaEsc($f['nombre']) ?></strong><small><?= (int)$f['total'] ?> prospectos · <?= $f['id']!==''?$metaEsc($f['id']):'Sin ID de formulario' ?></small></div>
                        <div><label for="metaFormulario<?= $metaEsc($f['clave']) ?>">Programa educativo</label>
                        <select id="metaFormulario<?= $metaEsc($f['clave']) ?>" name="programa_formulario[<?= $metaEsc($f['clave']) ?>]" data-meta-formulario="<?= $metaEsc($f['clave']) ?>" <?= $metaBloqueada?'disabled':'' ?>>
                            <option value="">Pendiente de clasificar</option>
                            <?php foreach ($metaProgramas as $p): ?>
                            <option value="<?= $metaEsc($p['codigo']) ?>" <?= $seleccion===$p['codigo']?'selected':'' ?>><?= $metaEsc($p['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <details class="meta-import-exceptions" <?= $metaAnterior?'open':'' ?>>
                <summary>Campañas detectadas (<?= count($metaResumen['campanias']) ?>) · Revisar excepciones</summary>
                <p class="meta-import-hint">Conservamos cada campaña. Una excepción se aplica a sus registros, incluso si utiliza varios formularios.</p>
                <div class="meta-import-campaigns">
                    <?php foreach ($metaResumen['campanias'] as $c):
                        $excepcion=$metaAnterior||isset($metaSeleccion['campanias'][$c['clave']]);
                        $seleccion=$metaAnterior?($metaSeleccion[$c['clave']]['codigo']??''):($metaSeleccion['campanias'][$c['clave']]['codigo']??'');
                    ?>
                    <div class="meta-import-campaign" data-meta-campania data-meta-formularios="<?= $metaEsc(implode(' ',array_keys($c['formularios']))) ?>">
                        <div><strong><?= $metaEsc($c['nombre']) ?></strong><small><?= (int)$c['total'] ?> prospectos · <?= $c['id']!==''?'Campaña #'.$metaEsc($c['id']):'Sin ID de campaña' ?></small>
                            <small>Formulario: <?= $metaEsc(implode(' · ',$c['formularios'])) ?></small>
                            <?php foreach ($c['advertencias'] as $advertencia): ?><p class="meta-import-warning"><i class="bi bi-info-circle" aria-hidden="true"></i> <?= $metaEsc($advertencia) ?></p><?php endforeach; ?>
                        </div>
                        <div><label class="meta-import-override"><input type="checkbox" name="excepcion_campania[<?= $metaEsc($c['clave']) ?>]" value="1" data-meta-excepcion <?= $excepcion?'checked':'' ?> <?= $metaBloqueada?'disabled':'' ?>> Cambiar sólo esta campaña</label>
                        <label for="metaCampania<?= $metaEsc($c['clave']) ?>" class="meta-import-sr">Programa para <?= $metaEsc($c['nombre']) ?></label>
                        <select id="metaCampania<?= $metaEsc($c['clave']) ?>" name="programa[<?= $metaEsc($c['clave']) ?>]" data-meta-programa <?= $metaBloqueada||!$excepcion?'disabled':'' ?> <?= !$excepcion?'hidden':'' ?>>
                            <option value="">Pendiente de clasificar</option>
                            <?php foreach ($metaProgramas as $p): ?>
                            <option value="<?= $metaEsc($p['codigo']) ?>" <?= $seleccion===$p['codigo']?'selected':'' ?>><?= $metaEsc($p['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select><small data-meta-herencia><?= $excepcion?'Excepción de campaña':'Usa el programa de su formulario' ?></small></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                </details>
                <?php foreach ($metaResumen['campanias'] as $c): foreach ($c['advertencias'] as $advertencia): ?>
                <p class="meta-import-warning"><?= $metaEsc($c['nombre']) ?> · <?= $metaEsc($advertencia) ?></p>
                <?php endforeach; endforeach; ?>
                <div class="meta-import-subhead"><h3>Ejemplo de registros detectados</h3><p>Primeros <?= count($metaResumen['muestra']) ?> registros de esta importación.</p></div>
                <div class="central-table-wrap meta-import-table-wrap" tabindex="0" aria-label="Vista previa de contactos de Meta">
                    <table class="central-table"><thead><tr><th>Fecha de Meta</th><th>Nombre</th><th>Teléfono</th><th>Campaña</th><th>Estado declarado</th></tr></thead>
                    <tbody><?php foreach ($metaResumen['muestra'] as $r): ?><tr>
                        <td><?= $metaEsc($r['created_time']) ?></td><td><?= $metaEsc($r['full_name']) ?></td>
                        <td><?= $metaEsc($r['phone_number']) ?></td><td><?= $metaEsc($r['campaign_name']) ?></td>
                        <td><?= $metaEsc($r['estado']?:'Sin indicar') ?></td>
                    </tr><?php endforeach; ?></tbody></table>
                </div>
                <div class="meta-import-status" data-meta-estado role="status" aria-live="polite" <?= (int)$metaResumen['avance']===0?'hidden':'' ?>>
                    <div class="meta-import-progress"><span data-meta-barra style="width:<?= (int)round(100*$metaResumen['avance']/max(1,$metaResumen['validas'])) ?>%"></span></div>
                    <p data-meta-progreso><?= (int)$metaResumen['avance'] ?> de <?= (int)$metaResumen['validas'] ?> procesados.</p>
                </div>
                <p class="central-alert" role="alert" data-meta-error hidden></p>
                <div class="meta-import-actions">
                    <?php if ($metaResumen['avance']===0): ?>
                    <button class="central-link" type="submit" form="metaCancelarPrevia" formnovalidate>Descartar vista previa</button>
                    <?php else: ?><span class="meta-import-hint">Hay lotes guardados. Reanuda para completar esta carga.</span><?php endif; ?>
                    <button class="meta-import-primary" type="submit" data-meta-confirmar>
                        <i class="bi bi-check2-circle" aria-hidden="true"></i>
                        <?= $metaResumen['avance']>0?'Reanudar importación':'Confirmar importación' ?>
                    </button>
                </div>
            </form>
            <?php if ($metaResumen['avance']===0): ?>
            <form id="metaCancelarPrevia" action="<?= $metaEsc($metaRuta.'metaCancelar') ?>" method="post">
                <input type="hidden" name="csrf_meta" value="<?= $metaEsc($metaCsrf) ?>">
            </form>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </section>
</div>
