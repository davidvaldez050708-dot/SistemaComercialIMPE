<?php
require_once __DIR__ . '/../../helpers/MapaEstadoHelper.php';
$mw = is_array($widgetsMarketing ?? null) ? $widgetsMarketing : [];
$mwResumen = $mw['resumen'] ?? null;
$mwTerritorios = $mw['territorios'] ?? null;
$mwRecientes = array_slice($publicacionesRecientesMarketing ?? [], 0, 4);
$mwEsc = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$mwMeses = [1=>'Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$mwPeriodo = $mwResumen ? explode('-', $mwResumen['periodo']) : [];
$mwPeriodoTexto = count($mwPeriodo) === 2 ? ($mwMeses[(int)$mwPeriodo[1]] ?? '') . ' ' . $mwPeriodo[0] : 'Periodo no disponible';
$mwUrl = BASE_URL . 'index.php?controller=convocatoria&action=index';
$mwPuedeVer = tienePermiso('convocatorias.ver');
$mwEstados = ['activa'=>'Activa','proxima'=>'Próxima a vencer','inactiva'=>'Inactiva','finalizada'=>'Finalizada'];
?>
<section class="marketing-operations-grid mt-4" aria-label="Actividad operativa de Marketing">
    <article class="marketing-operation-card marketing-monthly">
        <div class="marketing-operation-heading">
            <span class="marketing-operation-kicker">PUBLICACIONES DEL MES</span>
            <span class="marketing-operation-period"><?php if ($mwResumen): ?><strong><?= (int)$mwResumen['total'] ?></strong> publicaciones · <?php endif; ?><?= $mwEsc($mwPeriodoTexto) ?></span>
        </div>
        <h2>Resumen de publicaciones</h2>
        <p class="marketing-operation-description">Actividad de difusión de convocatorias en el periodo actual.</p>
        <?php if ($mwResumen): ?>
        <div class="marketing-monthly-metrics">
            <?php foreach ([['total','Publicaciones totales','Registradas este mes.'],['publicadas','Convocatorias publicadas','Vigentes en el periodo.'],['inactivas','Convocatorias inactivas','No vigentes.'],['proximas','Próximas a vencer','En los próximos 7 días.']] as [$clave,$etiqueta,$ayuda]): ?>
            <div>
                <strong><?= (int)$mwResumen[$clave] ?></strong>
                <h3><?= $mwEsc($etiqueta) ?></h3>
                <p><?= $mwEsc($ayuda) ?></p>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?><p class="marketing-operation-empty">No se pudo consultar el resumen de publicaciones.</p><?php endif; ?>
    </article>
    <article class="marketing-operation-card marketing-activity">
        <div class="marketing-operation-heading">
            <span class="marketing-operation-kicker">ACTIVIDAD RECIENTE</span>
            <?php if ($mwPuedeVer): ?><a href="<?= $mwEsc($mwUrl) ?>">Ver todas <i class="bi bi-arrow-right" aria-hidden="true"></i></a><?php endif; ?>
        </div>
        <h2>Últimas convocatorias publicadas</h2>
        <div class="marketing-operation-list">
            <?php foreach ($mwRecientes as $registro):
                $estado = (string)($registro['estado_proceso'] ?? 'inactiva');
                $estado = isset($mwEstados[$estado]) ? $estado : 'inactiva';
                $params = ['territorio_id'=>(int)($registro['territorio_id'] ?? 0), 'tipo'=>(string)($registro['tipo_convocatoria'] ?? ''), 'subtipo'=>(string)($registro['subtipo_convocatoria'] ?? ''), 'buscar'=>(string)($registro['titulo'] ?? '')];
                $inicio = strtotime((string)($registro['fecha_inicio'] ?? ''));
                if ($inicio !== false) { $params['anio'] = date('Y', $inicio); $params['mes'] = date('n', $inicio); }
                $url = $mwUrl . '&' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
                $publicacion = trim((string)($registro['created_at'] ?? ''));
                $instante = $publicacion !== '' ? strtotime($publicacion) : false;
                $fechaTexto = 'Fecha no disponible';
                if ($instante !== false) {
                    $mesCorto = mb_strtolower(mb_substr($mwMeses[(int)date('n',$instante)],0,3,'UTF-8'),'UTF-8');
                    $fechaTexto = date('j',$instante).' '.$mesCorto.' · '.date('H:i',$instante);
                }
            ?>
            <div class="marketing-operation-row marketing-activity-row">
                <span class="marketing-operation-icon" aria-hidden="true"><i class="bi bi-megaphone"></i></span>
                <div class="marketing-operation-copy">
                    <?php if ($mwPuedeVer): ?><a href="<?= $mwEsc($url) ?>"><?= $mwEsc($registro['titulo'] ?? '') ?></a><?php else: ?><strong><?= $mwEsc($registro['titulo'] ?? '') ?></strong><?php endif; ?>
                    <span class="marketing-operation-badge is-<?= $mwEsc($estado) ?>"><?= $mwEsc($mwEstados[$estado]) ?></span>
                </div>
                <time<?php if ($instante !== false): ?> datetime="<?= $mwEsc(date('Y-m-d\TH:i:s',$instante)) ?>" title="<?= $mwEsc(date('d/m/Y H:i',$instante)) ?>"<?php endif; ?>><?= $mwEsc($fechaTexto) ?></time>
            </div>
            <?php endforeach; ?>
            <?php if (!$mwRecientes): ?><p class="marketing-operation-empty">Aún no hay convocatorias publicadas.</p><?php endif; ?>
        </div>
    </article>
    <article class="marketing-operation-card marketing-territory-top">
        <div class="marketing-operation-heading">
            <span class="marketing-operation-kicker">COBERTURA TERRITORIAL</span>
            <?php if ($mwPuedeVer): ?><a href="<?= $mwEsc($mwUrl) ?>">Ver mapa <i class="bi bi-arrow-right" aria-hidden="true"></i></a><?php endif; ?>
        </div>
        <h2>Convocatorias activas por estado</h2>
        <div class="marketing-operation-list">
            <?php foreach ($mwTerritorios ?? [] as $territorio):
                $mapa = impeRutaMapaEstado(strtr((string)$territorio['nombre'], ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U']));
                $url = $mwUrl . '&territorio_id=' . (int)$territorio['id'];
            ?>
            <div class="marketing-operation-row marketing-territory-row">
                <span class="marketing-operation-icon" aria-hidden="true"><?php if ($mapa !== ''): ?><img src="<?= $mwEsc(BASE_URL.$mapa) ?>" alt=""><?php else: ?><i class="bi bi-geo-alt"></i><?php endif; ?></span>
                <div class="marketing-operation-copy">
                    <?php if ($mwPuedeVer): ?><a href="<?= $mwEsc($url) ?>"><?= $mwEsc($territorio['nombre']) ?></a><?php else: ?><strong><?= $mwEsc($territorio['nombre']) ?></strong><?php endif; ?>
                    <span><?= (int)$territorio['convocatorias_activas'] ?> <?= (int)$territorio['convocatorias_activas'] === 1 ? 'convocatoria activa' : 'convocatorias activas' ?></span>
                </div>
                <?php if ($mwPuedeVer): ?><a class="marketing-territory-arrow" href="<?= $mwEsc($url) ?>" aria-label="Ver convocatorias de <?= $mwEsc($territorio['nombre']) ?>"><i class="bi bi-chevron-right" aria-hidden="true"></i></a><?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php if ($mwTerritorios === null): ?><p class="marketing-operation-empty">No se pudo consultar la cobertura territorial.</p><?php elseif (!$mwTerritorios): ?><p class="marketing-operation-empty">Aún no hay estados con convocatorias activas vigentes.</p><?php endif; ?>
        </div>
    </article>
</section>
