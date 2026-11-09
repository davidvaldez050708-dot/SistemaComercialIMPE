<?php

$escapar = static fn ($valor): string => htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
$descargaUrl = static fn (string $nombre): string => $urlRespaldo . '&descargar=' . rawurlencode($nombre);
$csrf = $escapar($_SESSION['csrf_token']);
$avisoError = !empty($aviso['error']);
$clasesBoton = 'd-inline-flex align-items-center justify-content-center text-center';

?>
<section aria-label="Respaldos de base de datos">
    <?php if (!empty($aviso['mensaje'])): ?>
        <div class="toast-container position-fixed top-0 end-0 p-3">
            <div
                id="respaldo-toast"
                class="toast system-toast<?= $avisoError ? ' system-toast-error' : '' ?>"
                role="<?= $avisoError ? 'alert' : 'status' ?>"
                aria-live="<?= $avisoError ? 'assertive' : 'polite' ?>"
                aria-atomic="true"
                data-bs-delay="<?= $avisoError ? 4200 : 3200 ?>">
                <div class="toast-body">
                    <i class="bi <?= $avisoError ? 'bi-exclamation-circle' : 'bi-check2-circle' ?>" aria-hidden="true"></i>
                    <span>
                        <?= $escapar($aviso['mensaje']) ?>
                        <?php if (!empty($aviso['descarga'])): ?>
                            <a href="<?= $escapar($descargaUrl($aviso['descarga'])) ?>">Descargar archivo</a>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            new bootstrap.Toast(document.getElementById('respaldo-toast')).show();
        });
        </script>
    <?php endif; ?>

    <?php if (!empty($aviso['descarga'])): ?>
        <iframe hidden title="Descarga del nuevo respaldo" src="<?= $escapar($descargaUrl($aviso['descarga'])) ?>"></iframe>
    <?php endif; ?>

    <div class="dashboard-panel p-3 p-md-4 mb-3">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="d-flex align-items-center gap-3">
                <span class="metric-icon"><i class="bi bi-database-check" aria-hidden="true"></i></span>
                <div>
                    <div class="page-subtitle mb-1">Último respaldo guardado en el servidor</div>
                    <strong><?= $historial ? date('d/m/Y H:i:s', $historial[0]['fecha']) : 'Todavía no hay respaldos' ?></strong>
                </div>
            </div>
            <form method="post" action="<?= $escapar($urlRespaldo) ?>">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <button type="submit" class="btn btn-system-save <?= $clasesBoton ?>" name="operacion" value="generar">
                    <i class="bi bi-cloud-arrow-down me-1" aria-hidden="true"></i> Generar y descargar nuevo respaldo
                </button>
            </form>
        </div>
    </div>

    <div class="dashboard-panel p-3 p-md-4 mb-3">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <h2 class="panel-title mb-0"><i class="bi bi-clock-history me-2" aria-hidden="true"></i>Historial de respaldos</h2>
            <small class="page-subtitle mb-0">Se conservan los últimos <?= RespaldoBaseDatos::LIMITE_HISTORIAL ?> respaldos.</small>
        </div>
        <div class="table-responsive">
            <table class="table users-table align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col">Nombre del archivo</th>
                        <th scope="col">Fecha de creación</th>
                        <th scope="col">Tamaño</th>
                        <th scope="col">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($historial as $respaldo): ?>
                        <tr>
                            <td><?= $escapar($respaldo['nombre']) ?></td>
                            <td class="text-nowrap"><?= date('d/m/Y H:i:s', $respaldo['fecha']) ?></td>
                            <td class="text-nowrap"><?= number_format($respaldo['bytes'] / 1024, 2) ?> KB</td>
                            <td>
                                <div class="d-flex flex-wrap align-items-center gap-2">
                                    <a class="btn btn-sm btn-system-cancel <?= $clasesBoton ?>" href="<?= $escapar($descargaUrl($respaldo['nombre'])) ?>">
                                        <i class="bi bi-download me-1" aria-hidden="true"></i> Descargar
                                    </a>
                                    <form class="d-flex flex-wrap align-items-center gap-2" method="post"
                                        action="<?= $escapar($urlRespaldo) ?>" data-respaldo-confirmar>
                                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                        <input type="hidden" name="nombre" value="<?= $escapar($respaldo['nombre']) ?>">
                                        <button type="submit" class="btn btn-sm btn-system-cancel <?= $clasesBoton ?>" name="operacion" value="restaurar">
                                            <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i> Restaurar
                                        </button>
                                        <button type="submit" class="btn btn-sm btn-system-danger <?= $clasesBoton ?>" name="operacion" value="eliminar">
                                            <i class="bi bi-trash me-1" aria-hidden="true"></i> Eliminar
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$historial): ?>
                        <tr><td colspan="4" class="text-center py-4">Aún no hay archivos en el historial.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="dashboard-panel p-3 p-md-4">
        <h2 class="panel-title mb-2"><i class="bi bi-cloud-arrow-up me-2" aria-hidden="true"></i>Restaurar desde un archivo</h2>
        <p class="page-subtitle mb-3" id="respaldo-ayuda">Selecciona un archivo SQL de confianza para restaurar la información del sistema.</p>
        <form method="post" action="<?= $escapar($urlRespaldo) ?>" enctype="multipart/form-data"
            class="d-flex flex-wrap align-items-end gap-3"
            data-respaldo-confirmar>
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <div>
                <label class="form-label login-label" for="respaldo-archivo">Archivo de respaldo (.sql)</label>
                <input class="form-control system-form-control" type="file" id="respaldo-archivo"
                    name="archivo_sql" accept=".sql" aria-describedby="respaldo-ayuda" required>
            </div>
            <button type="submit" class="btn btn-system-save <?= $clasesBoton ?>" name="operacion" value="subir">
                <i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i> Restaurar archivo
            </button>
        </form>
    </div>
</section>

<div class="modal fade" id="modalConfirmarRespaldo" tabindex="-1"
    aria-labelledby="modalConfirmarRespaldoTitulo" aria-describedby="modalConfirmarRespaldoTexto" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content logout-confirm-modal">
            <div class="modal-body text-center">
                <div class="logout-confirm-icon">
                    <i class="bi" aria-hidden="true" data-respaldo-icono></i>
                </div>
                <h5 class="logout-confirm-title" id="modalConfirmarRespaldoTitulo"></h5>
                <p class="logout-confirm-text" id="modalConfirmarRespaldoTexto"></p>
                <div class="logout-confirm-actions">
                    <button type="button" class="btn logout-cancel-button" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn logout-confirm-button" data-respaldo-aceptar></button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const ventana = document.getElementById('modalConfirmarRespaldo');
    const modal = new bootstrap.Modal(ventana);
    const titulo = document.getElementById('modalConfirmarRespaldoTitulo');
    const texto = document.getElementById('modalConfirmarRespaldoTexto');
    const icono = ventana.querySelector('[data-respaldo-icono]');
    const aceptar = ventana.querySelector('[data-respaldo-aceptar]');
    const cancelar = ventana.querySelector('[data-bs-dismiss="modal"]');
    const restaurar = {
        titulo: '¿Restaurar la base de datos?',
        texto: 'La restauración puede sobrescribir los datos actuales. Se guardará una copia previa.',
        icono: 'bi-arrow-counterclockwise',
        aceptar: 'Sí, restaurar'
    };
    const eliminar = {
        titulo: '¿Eliminar respaldo?',
        texto: '¿Estás seguro de que deseas eliminar este respaldo del historial?',
        icono: 'bi-trash',
        aceptar: 'Sí, eliminar'
    };
    let botonPendiente;
    let confirmado = false;

    document.querySelectorAll('[data-respaldo-confirmar]').forEach(function (formulario) {
        formulario.addEventListener('submit', function (evento) {
            if (confirmado) {
                return;
            }
            evento.preventDefault();
            botonPendiente = evento.submitter || formulario.querySelector('button[name="operacion"]');
            const accion = botonPendiente.value === 'eliminar' ? eliminar : restaurar;
            titulo.textContent = accion.titulo;
            texto.textContent = accion.texto;
            icono.className = 'bi ' + accion.icono;
            aceptar.textContent = accion.aceptar;
            modal.show();
        });
    });

    ventana.addEventListener('shown.bs.modal', function () {
        cancelar.focus();
    });

    ventana.addEventListener('hidden.bs.modal', function () {
        botonPendiente?.focus();
        botonPendiente = null;
        aceptar.disabled = false;
    });

    aceptar.addEventListener('click', function () {
        if (!botonPendiente) {
            return;
        }
        aceptar.disabled = true;
        confirmado = true;
        try {
            botonPendiente.form.requestSubmit(botonPendiente);
        } finally {
            confirmado = false;
            modal.hide();
        }
    });
});
</script>
