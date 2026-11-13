<?php
/** Configuración de asesores: programas múltiples, jornadas y horarios compactos. */
require_once __DIR__.'/../../../helpers/AvatarHelper.php';
$asesoresConfigurados=$asesoresConfigurados??[];
$programasAsesores=$programasAsesores??[];
$configuracionDisponible=$configuracionDisponible??false;
$puedeConfigurarAsesores=$puedeConfigurarAsesores??false;

$gruposProgramas=['Bachillerato'=>[], 'Titulación'=>[], 'Otros programas'=>[]];
foreach ($programasAsesores as $p) {
    $nombrePrograma=(string)$p['nombre'];
    if (preg_match('/bachiller|acuerdo\\s*286/ui',$nombrePrograma)) $grupo='Bachillerato';
    elseif (preg_match('/titulaci[oó]n|experiencia\\s+laboral|ejecutiv[ao]s/ui',$nombrePrograma)) $grupo='Titulación';
    else $grupo='Otros programas';
    $gruposProgramas[$grupo][]=$p;
}
$gruposProgramas=array_filter($gruposProgramas);
$nombresProgramas=array_column($programasAsesores,'nombre','codigo');
?>
<section class="central-panel prospectos-asesores">
    <div class="central-section-head asesores-heading">
        <div>
            <h3>Configuración de asesores</h3>
            <p>Selecciona todos los programas que puede atender cada asesor y define sus horarios. Las asignaciones actuales se conservan.</p>
        </div>
        <span class="central-badge"><i class="bi bi-megaphone" aria-hidden="true"></i> Reparto Meta equilibrado por jornada</span>
    </div>
    <?php if (!$configuracionDisponible): ?>
        <div class="asesores-notice" role="status">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            <div><strong>Actualización de programas pendiente</strong>
                <p>Se requiere instalar la tabla adicional de programas por asesor. No se modifican las configuraciones anteriores.</p>
            </div>
        </div>
    <?php endif; ?>
    <div class="asesores-notice asesores-notice--neutral">
        <i class="bi bi-people" aria-hidden="true"></i>
        <p><strong>Aliados:</strong> sin límite diario. Las jornadas y programas se utilizarán en la siguiente fase de distribución; guardar aquí no reparte prospectos.</p>
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
            $seleccionados=array_map('strval',$asesor['programas']??[]);
            $configurado=(bool)$seleccionados;
            $nombre=trim((string)$asesor['nombre'].' '.(string)$asesor['apellidos']);
            $etiquetas=array_values(array_map(static function ($codigo) use ($nombresProgramas) {
                return (string)($nombresProgramas[$codigo]??'Programa no vigente');
            },$seleccionados));
            $resumenProgramas=$etiquetas?implode(' · ',$etiquetas):'Programas sin configurar';
        ?>
            <details class="asesores-card" id="asesor-<?= $id ?>" <?= ((int)($_GET['asesor_id']??0)===$id)?'open':'' ?>>
                <summary class="asesores-card-head">
                    <?= renderAvatarUsuario(
                        $asesor['nombre']??'', $asesor['apellidos']??'',
                        'Asesor de Ventas', $asesor['foto_perfil']??'',
                        'sm', 'general', 'asesores-avatar-usuario', true
                    ) ?>
                    <span class="asesores-persona"><strong><?= $esc($nombre!==''?$nombre:'Asesor #'.$id) ?></strong>
                        <small><?= $esc($resumenProgramas) ?></small>
                    </span>
                    <span class="central-badge"><?= (int)$asesor['usuario_activo']!==1?'Usuario inactivo':($configurado?($activo?'Disponible':'Pausado'):'Por configurar') ?></span>
                    <span class="asesores-card-arrow"><i class="bi bi-chevron-down" aria-hidden="true"></i></span>
                </summary>
                <div class="asesores-card-body">
                    <?php if (!$puedeConfigurarAsesores): ?>
                        <p class="central-muted">No tienes permiso para modificar los programas ni las jornadas.</p>
                    <?php elseif (!$configuracionDisponible): ?>
                        <p class="central-muted">La actualización de estructura debe instalarse antes de guardar; las configuraciones anteriores permanecen intactas.</p>
                    <?php elseif ((int)$asesor['usuario_activo']!==1): ?>
                        <p class="central-muted">Este usuario está inactivo. Actívalo en Usuarios para poder modificar su configuración comercial.</p>
                    <?php else: ?>
                    <form class="asesores-form" action="<?= $esc(BASE_URL.'index.php?controller=prospecto&action=guardarConfiguracionAsesor') ?>" method="post" data-asesores-form>
                        <input type="hidden" name="csrf_asesores" value="<?= $esc($csrfAsesores) ?>">
                        <input type="hidden" name="usuario_id" value="<?= $id ?>">
                        <input type="hidden" name="version" value="<?= (int)$asesor['version'] ?>">
                        <fieldset class="asesores-programas">
                            <legend>Programas que atiende</legend>
                            <p>Selecciona modalidades, áreas completas o combínalas. Los nuevos programas se mostrarán aquí.</p>
                            <?php if (!$gruposProgramas): ?>
                                <p class="central-alert" role="alert">No hay programas educativos activos para configurar.</p>
                            <?php else: ?>
                            <div class="asesores-programas-grupos">
                            <?php foreach ($gruposProgramas as $grupo=>$programasGrupo):
                                $totalGrupo=count($programasGrupo);
                                $seleccionGrupo=count(array_filter($programasGrupo,static fn($p)=>in_array((string)$p['codigo'],$seleccionados,true)));
                            ?>
                                <div class="asesores-programa-grupo" data-asesores-grupo>
                                    <div class="asesores-programa-grupo-cabecera">
                                        <strong><?= $esc($grupo) ?></strong>
                                        <?php if ($totalGrupo>1): ?>
                                        <label class="asesores-seleccionar-grupo">
                                            <input type="checkbox" data-asesores-todos <?= $seleccionGrupo===$totalGrupo?'checked':'' ?>>
                                            <span class="asesores-check-ui" aria-hidden="true"></span>
                                            <span>Todos</span>
                                        </label>
                                        <?php endif; ?>
                                    </div>
                                    <div class="asesores-programa-opciones">
                                    <?php foreach ($programasGrupo as $p): ?>
                                        <label class="asesores-programa-opcion">
                                            <input type="checkbox" name="programas[]" value="<?= $esc($p['codigo']) ?>" data-asesores-programa <?= in_array((string)$p['codigo'],$seleccionados,true)?'checked':'' ?>>
                                            <span class="asesores-check-ui" aria-hidden="true"></span>
                                            <span class="asesores-programa-nombre"><?= $esc($p['nombre']) ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </fieldset>
                        <div class="asesores-form-grid">
                            <div class="asesores-jornada"><label for="asesor-jornada-<?= $id ?>">Tipo de jornada</label>
                                <select id="asesor-jornada-<?= $id ?>" name="jornada" required>
                                    <option value="MEDIO_TIEMPO" <?= $asesor['jornada']==='MEDIO_TIEMPO'?'selected':'' ?>>Medio tiempo</option>
                                    <option value="TIEMPO_COMPLETO" <?= $asesor['jornada']==='TIEMPO_COMPLETO'?'selected':'' ?>>Tiempo completo</option>
                                </select>
                            </div>
                            <div class="asesores-disponibilidad">
                                <span class="asesores-field-title">Estado del asesor</span>
                                <label class="asesores-toggle">
                                    <input type="checkbox" name="activo" value="1" <?= $activo?'checked':'' ?>>
                                    <span class="asesores-switch" aria-hidden="true"></span>
                                    <span>Disponible para futuras asignaciones</span>
                                </label>
                            </div>
                        </div>
                        <fieldset class="asesores-horarios">
                            <legend>Días y horarios laborales</legend>
                            <p>Marca los días trabajados y define entrada y salida (hora de México).</p>
                            <div class="asesores-dias">
                            <?php foreach (ProspectoAsesorConfiguracionService::DIAS as $dia=>$nombreDia):
                                $horas=$asesor['horarios'][$dia]??[];
                                $marcado=isset($asesor['horarios'][$dia]);
                            ?>
                                <div class="asesores-dia" data-asesores-dia>
                                    <label class="asesores-dia-nombre">
                                        <input type="checkbox" name="dias[<?= $dia ?>][activo]" value="1" <?= $marcado?'checked':'' ?> data-asesores-dia-check>
                                        <span class="asesores-check-ui" aria-hidden="true"></span>
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
                            <p><i class="bi bi-shield-check" aria-hidden="true"></i> Cambios auditados. Sin afectar llamadas o asignaciones previas.</p>
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
