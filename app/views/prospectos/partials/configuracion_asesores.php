<?php
/** Vista exclusiva de Asignaciones > Configuración de asesores. Datos preparados por el controlador. */
$asesoresConfigurados=$asesoresConfigurados??[];
$programasAsesores=$programasAsesores??[];
$configuracionDisponible=$configuracionDisponible??false;
$puedeConfigurarAsesores=$puedeConfigurarAsesores??false;
?>
<section class="central-panel prospectos-asesores">
    <div class="central-section-head asesores-heading">
        <div>
            <h3>Configuración de asesores</h3>
            <p>Define un solo programa por asesor, su jornada y los horarios de contacto. Las asignaciones actuales no se modifican.</p>
        </div>
        <span class="central-badge"><i class="bi bi-megaphone" aria-hidden="true"></i> 80 nuevos de Meta por asesor y día</span>
    </div>
    <?php if (!$configuracionDisponible): ?>
        <div class="asesores-notice" role="status">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            <div><strong>Configuración pendiente de habilitar</strong>
                <p>La estructura adicional todavía no está disponible. Se conservan los datos y las asignaciones existentes. No se realizó ningún cambio.</p>
            </div>
        </div>
    <?php endif; ?>
    <div class="asesores-notice asesores-notice--neutral">
        <i class="bi bi-people" aria-hidden="true"></i>
        <p><strong>Aliados:</strong> sin límite diario. Los horarios configurados se utilizarán en la próxima fase de distribución; guardar aquí no reparte prospectos.</p>
    </div>
    <?php if (!$asesoresConfigurados): ?>
        <div class="central-empty"><i class="bi bi-person-x" aria-hidden="true"></i>
            <h3>No hay usuarios con rol Asesor de Ventas</h3>
            <p>Los asesores se administran desde Usuarios. Aquí no se crean cuentas nuevas.</p>
        </div>
    <?php else: ?>
        <div class="asesores-lista">
        <?php foreach ($asesoresConfigurados as $asesor):
            $id=(int)$asesor['id'];
            $activo=(int)($asesor['configuracion_activa']??1)===1;
            $configurado=$asesor['programa_codigo']!==null;
            $nombre=trim((string)$asesor['nombre'].' '.(string)$asesor['apellidos']);
            $programaActual='';
            foreach ($programasAsesores as $programa) {
                if ((string)$programa['codigo']===(string)$asesor['programa_codigo']) $programaActual=(string)$programa['nombre'];
            }
        ?>
            <details class="asesores-card" id="asesor-<?= $id ?>" <?= ((int)($_GET['asesor_id']??0)===$id)?'open':'' ?>>
                <summary class="asesores-card-head">
                    <span class="asesores-avatar"><i class="bi bi-person" aria-hidden="true"></i></span>
                    <span class="asesores-persona"><strong><?= $esc($nombre!==''?$nombre:'Asesor #'.$id) ?></strong>
                        <small><?= $esc($programaActual!==''?$programaActual:($configurado?'Programa inactivo o no disponible':'Programa sin configurar')) ?></small>
                    </span>
                    <span class="central-badge"><?= (int)$asesor['usuario_activo']!==1?'Usuario inactivo':($configurado?($activo?'Disponible':'Pausado'):'Por configurar') ?></span>
                    <span class="asesores-card-arrow"><i class="bi bi-chevron-down" aria-hidden="true"></i></span>
                </summary>
                <div class="asesores-card-body">
                    <?php if (!$puedeConfigurarAsesores): ?>
                        <p class="central-muted">No tienes permiso para modificar las jornadas.</p>
                    <?php elseif (!$configuracionDisponible): ?>
                        <p class="central-muted">El administrador debe completar la instalación controlada antes de guardar configuraciones.</p>
                    <?php elseif ((int)$asesor['usuario_activo']!==1): ?>
                        <p class="central-muted">Este usuario está inactivo. Actívalo en Usuarios para poder modificar su configuración comercial.</p>
                    <?php else: ?>
                    <form class="asesores-form" action="<?= $esc(BASE_URL.'index.php?controller=prospecto&action=guardarConfiguracionAsesor') ?>" method="post">
                        <input type="hidden" name="csrf_asesores" value="<?= $esc($csrfAsesores) ?>">
                        <input type="hidden" name="usuario_id" value="<?= $id ?>">
                        <input type="hidden" name="version" value="<?= (int)$asesor['version'] ?>">
                        <div class="asesores-form-grid">
                            <div><label for="asesor-programa-<?= $id ?>">Programa educativo</label>
                                <select id="asesor-programa-<?= $id ?>" name="programa_codigo" required>
                                    <option value="">Seleccionar programa</option>
                                    <?php foreach ($programasAsesores as $p): ?>
                                    <option value="<?= $esc($p['codigo']) ?>" <?= (string)$asesor['programa_codigo']===(string)$p['codigo']?'selected':'' ?>><?= $esc($p['nombre']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div><label for="asesor-jornada-<?= $id ?>">Tipo de jornada</label>
                                <select id="asesor-jornada-<?= $id ?>" name="jornada" required>
                                    <option value="MEDIO_TIEMPO" <?= $asesor['jornada']==='MEDIO_TIEMPO'?'selected':'' ?>>Medio tiempo</option>
                                    <option value="TIEMPO_COMPLETO" <?= $asesor['jornada']==='TIEMPO_COMPLETO'?'selected':'' ?>>Tiempo completo</option>
                                </select>
                            </div>
                        </div>
                        <label class="asesores-toggle">
                            <input type="checkbox" name="activo" value="1" <?= $activo?'checked':'' ?>>
                            <span>Asesor disponible para futuras asignaciones</span>
                        </label>
                        <fieldset class="asesores-horarios">
                            <legend>Días y horarios laborales</legend>
                            <p>Activa únicamente los días trabajados. La hora de entrada debe ser anterior a la salida (hora local de México).</p>
                            <div class="asesores-dias">
                            <?php foreach (ProspectoAsesorConfiguracionService::DIAS as $dia=>$nombreDia):
                                $horas=$asesor['horarios'][$dia]??[];
                                $marcado=isset($asesor['horarios'][$dia]);
                            ?>
                                <div class="asesores-dia">
                                    <label class="asesores-dia-nombre" for="asesor-dia-<?= $id ?>-<?= $dia ?>">
                                        <input type="checkbox" id="asesor-dia-<?= $id ?>-<?= $dia ?>" name="dias[<?= $dia ?>][activo]" value="1" <?= $marcado?'checked':'' ?>>
                                        <span><?= $esc($nombreDia) ?></span>
                                    </label>
                                    <label class="asesores-hora"><span>Entrada</span>
                                        <input type="time" name="dias[<?= $dia ?>][desde]" value="<?= $esc($horas['desde']??'09:00') ?>" aria-label="Entrada <?= $esc($nombreDia) ?>">
                                    </label>
                                    <label class="asesores-hora"><span>Salida</span>
                                        <input type="time" name="dias[<?= $dia ?>][hasta]" value="<?= $esc($horas['hasta']??'18:00') ?>" aria-label="Salida <?= $esc($nombreDia) ?>">
                                    </label>
                                </div>
                            <?php endforeach; ?>
                            </div>
                        </fieldset>
                        <div class="asesores-form-foot">
                            <p><i class="bi bi-shield-check" aria-hidden="true"></i> Cambios auditados. No afectan llamadas ni prospectos ya asignados.</p>
                            <button type="submit" class="asesores-guardar"><i class="bi bi-check2-circle" aria-hidden="true"></i> Guardar configuración</button>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>
            </details>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
