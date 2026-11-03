<?php if (($listado['paginas']??1)>1): ?>
<nav class="central-pagination" aria-label="Páginas del listado">
<?php if ($listado['pagina']>1): ?><a href="<?= $esc($urlPagina($listado['pagina']-1)) ?>" rel="prev">Anterior</a><?php endif; ?>
<span>Página <?= (int)$listado['pagina'] ?> de <?= (int)$listado['paginas'] ?></span>
<?php if ($listado['pagina']<$listado['paginas']): ?><a href="<?= $esc($urlPagina($listado['pagina']+1)) ?>" rel="next">Siguiente</a><?php endif; ?>
</nav>
<?php endif; ?>
