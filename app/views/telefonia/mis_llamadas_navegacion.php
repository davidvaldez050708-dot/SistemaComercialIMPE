<?php
// Pestañas del historial personal: cada vista reusa la operación ya autorizada.
// No son enlaces a páginas duplicadas ni cambian el propietario de una llamada.
$tabsMisLlamadas = [];
if (!empty($puedeRecepcionMarketing)) {
    $tabsMisLlamadas[] = ['vista'=>'recepcion', 'titulo'=>'Recepción y resultados', 'icono'=>'bi-headset'];
}
$tabsMisLlamadas[] = ['vista'=>'historial', 'titulo'=>'Historial', 'icono'=>'bi-clock-history'];
if (!empty($puedeGestionAnalista)) {
    $tabsMisLlamadas[] = ['vista' => 'recibidas', 'titulo' => 'Recibidas y resultados', 'icono' => 'bi-telephone-inbound'];
    $tabsMisLlamadas[] = ['vista' => 'devoluciones', 'titulo' => 'Por devolver', 'icono' => 'bi-telephone-forward'];
}
?>
<style><?= file_get_contents(ROOT_PATH.'/public/css/telefonia_mis_llamadas.css') ?></style>
<nav class="impe-calls-hub-navigation" aria-label="Secciones de Mis llamadas">
    <?php foreach ($tabsMisLlamadas as $tab):
        $activo = $tab['vista'] === ($vistaActual ?? 'historial');
        $enlace = BASE_URL.'index.php?controller=telefonia&amp;action=misLlamadas&amp;vista='.$tab['vista'];
    ?>
        <a href="<?= $enlace ?>" class="<?= $activo ? 'is-active' : '' ?>" <?= $activo ? 'aria-current="page"' : '' ?>>
            <i class="bi <?= htmlspecialchars($tab['icono'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i>
            <?= htmlspecialchars($tab['titulo'], ENT_QUOTES, 'UTF-8') ?>
        </a>
    <?php endforeach; ?>
</nav>
