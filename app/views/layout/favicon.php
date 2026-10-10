<?php
/**
 * Un solo favicon APG para todo el sistema.
 * Incluir este parcial dentro del <head> de cualquier vista HTML independiente.
 */
$impeFaviconBase = defined('BASE_URL')
    ? BASE_URL
    : rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/') . '/';

$impeFaviconRuta = dirname(__DIR__, 3) . '/public/favicon.ico';
$impeFaviconVersion = is_file($impeFaviconRuta)
    ? (string)filemtime($impeFaviconRuta)
    : '1';
$impeFaviconHref = rtrim($impeFaviconBase, '/') . '/public/favicon.ico?v=' . $impeFaviconVersion;
?>
<link rel="icon" type="image/x-icon" href="<?= htmlspecialchars($impeFaviconHref, ENT_QUOTES, 'UTF-8') ?>">
