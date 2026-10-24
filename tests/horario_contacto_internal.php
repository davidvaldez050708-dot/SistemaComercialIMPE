<?php if(getenv('APP_ENV')!=='testing')exit(2); ?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= BASE_URL ?>public/css/global.css" rel="stylesheet"><link href="<?= BASE_URL ?>public/css/formularios.css" rel="stylesheet">
<title>Registro interno · Prueba</title></head><body>
<?php require __DIR__.'/../app/views/formularios/registro.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= BASE_URL ?>public/javascript/formularios_registro.js"></script>
</body></html>
