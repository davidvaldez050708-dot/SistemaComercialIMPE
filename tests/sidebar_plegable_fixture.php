<?php
// Renderiza los layouts reales con datos ficticios. Sin servidor ni SQL.
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing') exit(2);
parse_str($argv[1] ?? '', $_GET);
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../app/helpers/PermissionHelper.php';

// Sólo sustituye la consulta PBX para presentar el botón de Recepción en la prueba.
function marketingTieneRecepcionAsignada(): bool { return true; }
$perfiles = [
    'admin'=>[1,'Administrador'], 'director'=>[2,'Director de Operaciones'],
    'clave'=>[6,'Cuenta Clave'], 'analista'=>[4,'Analista de Datos'],
    'asesor'=>[3,'Asesor de Ventas'], 'marketing'=>[5,'Marketing'],
];
$perfil = $_GET['perfil'] ?? 'admin';
if (!isset($perfiles[$perfil])) exit(2);
[$rolId, $rolNombre] = $perfiles[$perfil];
$_SESSION = [
    'usuario_id'=>max(1, (int)($_GET['usuario'] ?? (100 + $rolId))),
    'nombre'=>'Persona', 'apellidos'=>'Ficticia', 'usuario'=>'prueba-layout',
    'rol_id'=>$rolId, 'rol'=>$rolNombre, 'foto_perfil'=>'',
    'permisos'=>['usuarios.ver','usuarios.crear','usuarios.editar','usuarios.cambiar_estado',
        'roles.ver','roles.crear','roles.editar','roles.cambiar_estado','territorios.ver',
        'data_territorial.ver','seguimientos_vinculacion.ver','aliados.ver','convocatorias.ver',
        'convenios.ver','prospectos.ver_todos','reportes.ver','reportes.usuarios','desempeno.ver',
        'reuniones.ver','telefonia.usar','telefonia.recibir','telefonia.salientes'],
];
if ($perfil === 'analista') $_SESSION['permisos'][] = 'seguimientos_vinculacion.operar_propios';
if ($perfil === 'marketing') $_SESSION['permisos'][] = 'convocatorias.gestionar';
$nombreCompleto='Persona Ficticia'; $fotoPerfil=''; $fotoPerfilUrl=''; $iniciales='PF';
$tituloPagina = 'Espacio de trabajo';
$subtituloPagina = 'Consulta de información y actividades del equipo.';
$opcionActiva = 'inicio';
$modulo = $_GET['modulo'] ?? 'resumen';
if (!in_array($modulo, ['resumen','usuarios','roles','tabla'], true)) exit(2);
$_GET['controller'] = ['usuarios'=>'usuario','roles'=>'rol','tabla'=>'reporte'][$modulo] ?? 'home';
// Usar todas las hojas que carga el head real (avisos, perfil, telefonía,
// etc.), sin inicializar sus SDK ni solicitar datos operativos en esta prueba.
ob_start();
require __DIR__ . '/../app/views/layout/dashboard_head.php';
$head = ob_get_clean();
preg_match_all('~<link\b[^>]*>|<style\b[^>]*>.*?</style>~s', $head, $estilosHead);
?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Layout de pruebas</title>
<link rel="stylesheet" href="<?= BASE_URL ?>fixture/bootstrap.css">
<link rel="stylesheet" href="<?= BASE_URL ?>fixture/icons.css">
<link rel="stylesheet" href="<?= BASE_URL ?>fixture/fonts.css">
<?= implode("\n", $estilosHead[0]) ?>
</head><body><div class="admin-shell">
<?php
require __DIR__ . '/../app/views/layout/sidebar.php';
require __DIR__ . '/../app/views/layout/topbar.php';
if ($modulo === 'usuarios') {
    $roles = [['id'=>1,'nombre'=>'Administrador','estado'=>1],['id'=>3,'nombre'=>'Asesor de Ventas','estado'=>1]];
    $usuarios = [['id'=>9,'nombre'=>'Usuario','apellidos'=>'Ficticio','usuario'=>'usuario-prueba',
        'correo'=>'persona@example.test','telefono'=>'','foto_perfil'=>'','rol_id'=>3,
        'rol'=>'Asesor de Ventas','estado'=>1,'ultimo_acceso'=>null]];
    $administradoresActivos=2;
    require __DIR__ . '/../app/views/usuarios/index.php';
} elseif ($modulo === 'roles') {
    $roles = [['id'=>1,'nombre'=>'Administrador','descripcion'=>'Sistema','estado'=>1],
        ['id'=>3,'nombre'=>'Asesor de Ventas','descripcion'=>'Equipo de pruebas','estado'=>1]];
    require __DIR__ . '/../app/views/roles/index.php';
} else {
?>
<div class="metric-grid">
<?php foreach (['Llamadas','Seguimientos','Reuniones','Pendientes'] as $etiqueta): ?>
<article class="metric-card"><span class="metric-icon"><i class="bi bi-check2-circle"></i></span><div><strong>12</strong><p><?= $etiqueta ?></p></div></article>
<?php endforeach; ?>
</div>
<section class="dashboard-panel mt-3 p-3">
    <h2 class="panel-title">Información del equipo</h2>
    <div class="module-search mb-3"><label for="notaLayout">Nota en edición</label><input id="notaLayout" class="form-control" value="Contenido conservado"></div>
    <div class="table-responsive"><table class="table"><thead><tr>
    <?php for ($i=1; $i<=9; $i++): ?><th>Información <?= $i ?></th><?php endfor; ?>
    </tr></thead><tbody><tr><?php for ($i=1; $i<=9; $i++): ?><td>Dato de prueba <?= $i ?></td><?php endfor; ?></tr></tbody></table></div>
</section>
<?php } ?>
<button class="btn btn-system-save mt-3" type="button" data-bs-toggle="modal" data-bs-target="#modalLayoutPrueba">Abrir diálogo de prueba</button>
<div class="modal fade" id="modalLayoutPrueba" tabindex="-1" aria-labelledby="tituloLayoutPrueba" aria-hidden="true">
<div class="modal-dialog modal-dialog-centered system-form-dialog"><div class="modal-content system-form-modal">
<div class="modal-header"><h2 class="modal-title" id="tituloLayoutPrueba">Información de prueba</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
<div class="modal-body">El diálogo conserva el tamaño del sistema.</div><div class="modal-footer"><button type="button" class="btn btn-system-cancel" data-bs-dismiss="modal">Cerrar</button></div>
</div></div></div>
</section></main></div>
<script src="<?= BASE_URL ?>fixture/bootstrap.js"></script>
<script src="<?= BASE_URL ?>public/javascript/sidebar_scroll_state.js"></script>
</body></html>
