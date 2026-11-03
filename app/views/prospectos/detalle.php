<?php
// Esta plantilla sirve tanto para la vista directa accesible como para el panel lateral.
$vistaPanel=$vistaPanel??false;
$esc=static fn($v)=>htmlspecialchars((string)($v??''),ENT_QUOTES,'UTF-8');
$valor=static fn($v)=>$v===null||$v===''?'Sin indicar':(string)$v;
$fecha=static fn($v)=>$valor(ProspectoFechaHoraHelper::aMexico($v));
$campo=static function($titulo,$v) use($esc,$valor) {echo '<div><dt>'.$esc($titulo).'</dt><dd>'.$esc($valor($v)).'</dd></div>';};
$referencia=static function($v) {return str_replace(['formulario_registros:','formulario_aliado_procedencias:'],['Solicitud original #','Procedencia del aliado #'],(string)$v);};
$volver=BASE_URL.'index.php?controller=prospecto&action=index&seccion='.(!empty($ficha['id'])?'todos':'aliados');
$paginaFicha=static function($key,$p) use($ficha) {
    return BASE_URL.'index.php?'.http_build_query([
        'controller'=>'prospecto','action'=>'detalle','id'=>$ficha['id'],
        'pagina_origenes'=>$ficha['origenes_pagina'],'pagina_intereses'=>$ficha['intereses_pagina'],$key=>$p
    ]);
};
$claveComercial=static fn($id)=>'CRM-PROS-'.str_pad((string)(int)$id,5,'0',STR_PAD_LEFT);
?>
<?php if (!$vistaPanel): ?><link rel="stylesheet" href="<?= BASE_URL ?>public/css/prospectos_central.css?v=<?= substr(hash_file('sha256',__DIR__.'/../../../public/css/prospectos_central.css'),0,16) ?>"><?php endif; ?>
<div class="prospectos-central prospectos-ficha<?= $vistaPanel?' is-panel':'' ?>" data-prospecto-ficha-contenido>
<?php if (!$vistaPanel): ?><a class="central-back" href="<?= $esc($volver) ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Volver a prospectos</a><?php endif; ?>
<?php if ($mensajeError!==''): ?><div class="central-alert" role="alert"><?= $esc($mensajeError) ?></div><?php endif; ?>
<?php if ($ficha): $original=$ficha['original']??[]; ?>
<header class="central-panel central-hero prospecto-ficha-identidad">
    <?php if (!$vistaPanel): ?><span class="central-eyebrow">FICHA DEL PROSPECTO</span><?php endif; ?>
    <h2><?= $esc($valor($ficha['nombre_completo'])) ?></h2>
    <p><?= !empty($ficha['id'])?'ID comercial #'.(int)$ficha['id']:'Registro original #'.(int)($original['id']??0).' · Pendiente de incorporación al almacén' ?></p>
</header>
<?php if (($ficha['coincidencias']??0)>0): ?>
<p class="central-note" role="status"><i class="bi bi-info-circle" aria-hidden="true"></i>
<?= (int)$ficha['coincidencias'] ?> <?= (int)$ficha['coincidencias']===1?'ficha comparte':'fichas comparten' ?> este teléfono normalizado. Son identidades separadas; la coincidencia queda pendiente de revisión.</p>
<?php endif; ?>
<section class="central-panel">
    <div class="central-section-head"><h3>Datos personales</h3><p>Información declarada, pendiente de verificación.</p></div>
    <dl class="central-details">
    <?php
        if (!empty($ficha['nombre'])) $campo('Nombre',$ficha['nombre']);
        if (!empty($ficha['apellidos'])) $campo('Apellidos',$ficha['apellidos']);
        $campo('Teléfono original',$ficha['telefono_original']??null);
        $campo('Teléfono normalizado',$ficha['telefono_normalizado']??null);
        if (!empty($ficha['correo'])) $campo('Correo',$ficha['correo']);
        $campo('Estado de residencia',$ficha['estado_nombre']??null);
        $campo('Municipio de residencia',$ficha['municipio_nombre']??null);
        if (!empty($ficha['pais_nombre'])) $campo('País',$ficha['pais_nombre']);
        if (!empty($ficha['estado_provincia'])) $campo('Estado o provincia',$ficha['estado_provincia']);
        if (!empty($ficha['ciudad'])) $campo('Ciudad',$ficha['ciudad']);
        $campo('Integridad',str_replace('_',' ',(string)($ficha['integridad_estado']??'SIN_VERIFICAR')));
        $campo('Verificación',str_replace('_',' ',(string)($ficha['verificacion_estado']??'SIN_VERIFICAR')));
    ?>
    </dl>
</section>
<section class="central-panel">
    <div class="central-section-head"><h3>Preferencia de horario de contacto</h3><p>Disponibilidad declarada; no es una cita confirmada.</p></div>
    <?php if ($original): ?>
    <div class="central-preference"><?= $esc(array_key_exists('horario_contacto',$original)?$valor($original['horario_contacto']):'Sin indicar') ?></div>
    <?php endif; ?>
    <?php $hayPreferencia=false;foreach ($ficha['origenes'] as $o):
        if (!array_key_exists('valor_original',$o)||(!isset($o['interpretacion_estado'])&&!empty($o['origen_padre_id']))) continue;
        $hayPreferencia=true; ?>
    <article class="central-origin">
        <strong><?= $esc($referencia($o['datos_originales_ref'])) ?></strong>
        <?php if (!$original || ($o['valor_original']??null)!==($original['horario_contacto']??null)): ?>
        <p class="central-preference"><?= $esc($o['valor_original']===null?'Sin indicar':($o['valor_original']===''?'Valor histórico vacío':$o['valor_original'])) ?></p>
        <?php endif; ?>
        <dl class="central-details">
            <?php
                $campo('Zona horaria',$o['zona_horaria']??'Pendiente de confirmar');
                if (!empty($o['dias_semana'])) {
                    $dias=[1=>'Lunes',2=>'Martes',3=>'Miércoles',4=>'Jueves',5=>'Viernes',6=>'Sábado',7=>'Domingo'];
                    $d=json_decode($o['dias_semana'],true)?:[];
                    $campo('Días',implode(', ',array_map(static fn($n)=>$dias[$n]??'',$d)));
                    $campo('Hora inicial',$o['hora_desde']);
                    $campo('Hora final',$o['hora_hasta']);
                }
            ?>
        </dl>
    </article>
    <?php endforeach; ?>
    <?php if (!$original&&!$hayPreferencia): ?><p class="central-muted">Sin preferencia de contacto informada.</p><?php endif; ?>
</section>
<section class="central-panel">
    <div class="central-section-head"><h3>Procedencia e historial</h3><p>Las solicitudes y las relaciones de aliados se conservan por separado.</p></div>
    <?php foreach ($ficha['origenes'] as $o): ?>
    <article class="central-origin">
        <div class="central-origin-heading">
            <strong><?= $esc($o['aliado_nombre']??$o['formulario_nombre']??str_replace('_',' ',$o['tipo_origen'])) ?></strong>
            <span class="central-badge"><?= $esc(str_replace('_',' ',$o['tipo_origen'])) ?></span>
        </div>
        <dl class="central-details">
        <?php
            $campo('Registro original',$referencia($o['datos_originales_ref']));
            $campo('Fecha de origen'.(($o['zona_horaria_origen']??'')==='UTC'?' · México':' · Zona sin confirmar'),
                ($o['zona_horaria_origen']??'')==='UTC'?$fecha($o['fecha_registro_original']):
                ($o['fecha_registro_texto']??$o['fecha_registro_original']));
            foreach (['formulario_nombre'=>'Formulario','formulario_id'=>'ID del formulario',
                'campania_nombre'=>'Campaña','campania_id'=>'ID de campaña',
                'conjunto_nombre'=>'Conjunto de anuncios','conjunto_id'=>'ID del conjunto',
                'anuncio_nombre'=>'Anuncio','anuncio_id'=>'ID del anuncio',
                'meta_lead_id'=>'ID del lead','plataforma'=>'Plataforma',
                'lote_importacion_ref'=>'Lote de importación'] as $key=>$label) {
                if (isset($o[$key])&&$o[$key]!=='') $campo($label,$o[$key]);
            }
            if (isset($o['incorporado_at'])) $campo('Incorporación · México',$fecha($o['incorporado_at']));
            if (($o['registro_tabla']??'')==='formulario_registros'&&empty($o['origen_padre_id'])) {
                $link=BASE_URL.'index.php?'.http_build_query([
                    'controller'=>'prospecto','action'=>'detalle','id'=>(int)($ficha['id']??0),
                    'registro_id'=>(int)$o['registro_id']]).'#informacion-formulario';
                if (!empty($ficha['id'])) {
                    echo '<div><dt>Formulario original</dt><dd><a class="central-link" href="'.$esc($link).'">Consultar datos originales</a></dd></div>';
                }
            }
        ?>
        </dl>
    </article>
    <?php endforeach; ?>
    <?php if (($ficha['origenes_paginas']??1)>1): ?>
    <nav class="central-pagination" aria-label="Historial de procedencias">
        <?php if ($ficha['origenes_pagina']>1): ?><a href="<?= $esc($paginaFicha('pagina_origenes',$ficha['origenes_pagina']-1)) ?>">Anterior</a><?php endif; ?>
        <span>Página <?= (int)$ficha['origenes_pagina'] ?> de <?= (int)$ficha['origenes_paginas'] ?></span>
        <?php if ($ficha['origenes_pagina']<$ficha['origenes_paginas']): ?><a href="<?= $esc($paginaFicha('pagina_origenes',$ficha['origenes_pagina']+1)) ?>">Siguiente</a><?php endif; ?>
    </nav>
    <?php endif; ?>
</section>
<section class="central-panel">
    <div class="central-section-head"><h3>Programas de interés</h3></div>
    <?php if (!$ficha['intereses']): ?><p class="central-muted">Sin programa clasificado.</p>
    <?php else: ?><ul class="central-interest-list">
    <?php foreach ($ficha['intereses'] as $i): ?>
        <li><strong><?= $esc($i['nombre_original']) ?></strong>
        <small><?= $esc(str_replace('_',' ',$i['clasificacion_estado'])) ?><?= isset($i['origen_id'])?' · Procedencia #'.(int)$i['origen_id']:'' ?></small></li>
    <?php endforeach; ?></ul><?php endif; ?>
    <?php if (($ficha['intereses_paginas']??1)>1): ?>
    <nav class="central-pagination" aria-label="Programas de interés">
        <?php if ($ficha['intereses_pagina']>1): ?><a href="<?= $esc($paginaFicha('pagina_intereses',$ficha['intereses_pagina']-1)) ?>">Anterior</a><?php endif; ?>
        <span>Página <?= (int)$ficha['intereses_pagina'] ?> de <?= (int)$ficha['intereses_paginas'] ?></span>
        <?php if ($ficha['intereses_pagina']<$ficha['intereses_paginas']): ?><a href="<?= $esc($paginaFicha('pagina_intereses',$ficha['intereses_pagina']+1)) ?>">Siguiente</a><?php endif; ?>
    </nav>
    <?php endif; ?>
</section>
<section class="central-panel" id="informacion-formulario">
    <div class="central-section-head"><h3>Información adicional del formulario</h3><p>La residencia del prospecto y el estado del aliado pueden ser distintos.</p></div>
    <?php if (!$original): ?><p class="central-muted">No hay información adicional de un formulario local para esta procedencia.</p>
    <?php else: ?><dl class="central-details">
    <?php
        foreach (['id'=>'ID original','nombre'=>'Nombre original','apellido'=>'Apellidos originales',
            'fecha_nacimiento'=>'Fecha de nacimiento','correo'=>'Correo original',
            'movil'=>'Teléfono original','movil_secundario'=>'Teléfono secundario',
            'tel_recomendado'=>'Teléfono recomendado','perfil_interes'=>'Perfil de interés declarado',
            'lugar_laboras'=>'Lugar de trabajo','cargo_puesto'=>'Cargo o puesto',
            'estado_nombre'=>'Estado de residencia declarado','municipio_nombre'=>'Municipio de residencia declarado',
            'pais_nombre'=>'País declarado','estado_provincia'=>'Estado o provincia declarado',
            'ciudad'=>'Ciudad declarada','origen'=>'Canal del formulario',
            'creado_por'=>'ID del usuario que capturó'] as $key=>$label) $campo($label,$original[$key]??null);
        $campo('Fecha del registro · México',$fecha($original['created_at']??null));
        $campo('Última actualización · México',$fecha($original['updated_at']??null));
    ?>
    </dl><?php endif; ?>
</section>
<section class="central-panel">
    <div class="central-section-head"><h3>Información comercial disponible</h3></div>
    <?php if (!$ficha['asignaciones']): ?><p class="central-muted">Sin asignación comercial.</p>
    <?php else: ?><div class="central-table-wrap"><table class="central-table">
        <thead><tr><th>Asesor</th><th>Asignación · México</th><th>Fecha de trabajo</th><th>Próxima acción · México</th><th>Estado</th></tr></thead>
        <tbody><?php foreach ($ficha['asignaciones'] as $a): ?>
        <tr><td><?= $esc($valor($a['asesor'])) ?></td><td><?= $esc($fecha($a['asignado_at'])) ?></td>
        <td><?= $esc($valor($a['fecha_trabajo'])) ?></td><td><?= $esc($fecha($a['proxima_accion_at'])) ?></td><td><?= $esc($a['estado']) ?></td></tr>
        <?php endforeach; ?></tbody></table></div><p class="central-muted">Últimas 25 asignaciones.</p><?php endif; ?>
    <?php foreach ($ficha['integraciones'] as $x): ?>
    <div class="prospecto-ficha-integracion">
        <strong><?= $x['sistema']==='ZOHO'?'Integración futura con Zoho CRM':'Integración externa' ?></strong>
        <dl class="central-details">
        <?php
            $estado=(string)$x['estado'];
            $campo('Estado',$estado==='NO_CONFIGURADO'?'No configurada':str_replace('_',' ',$estado));
            // La clave pública es neutra. La referencia histórica de interoperabilidad no se expone.
            if (!empty($ficha['id'])) $campo('Referencia comercial',$claveComercial($ficha['id']));
            if ($x['externo_id']!==null) $campo('ID externo',$x['externo_id']);
            if ($x['modulo_destino']!==null) $campo('Módulo externo',$x['modulo_destino']);
        ?>
        </dl>
    </div>
    <?php endforeach; ?>
</section>
<?php endif; ?>
</div>
