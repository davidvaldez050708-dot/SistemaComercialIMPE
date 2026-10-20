<div
    class="modal fade"
    id="modalCrearConvocatoria"
    <?= !empty($aliadoConvocatoria) ? 'data-convocatoria-auto-open' : '' ?>
    tabindex="-1"
    aria-labelledby="modalCrearConvocatoriaTitulo"
    aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg system-form-dialog">
        <div class="modal-content system-form-modal">
            <div class="modal-header system-form-modal-header">
                <div>
                    <h5 class="system-form-modal-title" id="modalCrearConvocatoriaTitulo">Nueva convocatoria</h5>
                    <p class="system-form-modal-subtitle">Registra una publicación y su cobertura territorial</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>

            <form
                class="convocatoria-create-form"
                action="<?= BASE_URL ?>index.php?controller=convocatoria&action=guardar"
                method="POST"
                enctype="multipart/form-data"
                novalidate>
                <?php if (!empty($aliadoConvocatoria)): ?>
                <input type="hidden" name="aliado_internacional_id" value="<?= (int)$aliadoConvocatoria['id'] ?>">
                <input type="hidden" name="csrf_internacional" value="<?= $texto($_SESSION['csrf_aliados_internacionales']) ?>">
                <input type="hidden" name="referencia_internacional" value="<?= $texto($referenciaInternacional) ?>">
                <div class="alert alert-light m-3 mb-0"><strong><?= $texto($aliadoConvocatoria['nombre']) ?></strong><br><?= $texto($aliadoConvocatoria['ubicacion']) ?></div>
                <?php endif; ?>
                <input type="hidden" name="territorio_id" value="<?= (int)$territorioSeleccionado['id'] ?>">
                <input
                    type="hidden"
                    name="seguimiento_origen_id"
                    value="<?= !empty($desdeConvenio) ? (int)$seguimientoOrigenId : 0 ?>">
        <input type="hidden" name="tipo" value="<?= $texto($tipoConvocatoria) ?>">
                <input
                    type="hidden"
                    name="retorno_query"
                    value="<?= $texto(http_build_query($_GET)) ?>">
                <input
                    type="hidden"
                    name="vista"
                    value="<?= $texto($vistaConvocatoriasInicial) ?>"
                    data-convocatoria-vista>

                <div class="modal-body">
                    <div class="system-form-grid">
                        <div class="system-form-full">
                            <?php
                            $tipoCrearActual = strtolower(
                                trim((string)($datosCrear['tipo'] ?? ''))
                            );
                            $subtipoCrearActual = strtolower(
                                trim((string)($datosCrear['subtipo'] ?? ''))
                            );
                            $clasificacionCrear = (
                                $tipoCrearActual !== '' &&
                                $subtipoCrearActual !== ''
                            )
                                ? $tipoCrearActual . '|' . $subtipoCrearActual
                                : '';
                            $clasificacionCrearLabel = 'Selecciona una opción';

                            foreach ($clasificacionesDisponibles as $clasificacion) {
                                if ($clasificacionCrear === $clasificacion['valor']) {
                                    $clasificacionCrearLabel = (string)$clasificacion['etiqueta'];
                                    break;
                                }
                            }
                            ?>

                            <label class="form-label login-label" for="crear_convocatoria_clasificacion_boton">
                                Tipo de convocatoria
                            </label>

                            <div
                                class="convocatoria-form-type-dropdown"
                                data-convocatoria-classification-dropdown>
                                <input
                                    type="hidden"
                                    id="crear_convocatoria_clasificacion"
                                    name="clasificacion"
                                    value="<?= $texto($clasificacionCrear) ?>"
                                    data-convocatoria-context-classification
                                    data-convocatoria-classification-value
                                    required>

                                <button
                                    type="button"
                                    class="convocatoria-form-type-trigger"
                                    id="crear_convocatoria_clasificacion_boton"
                                    aria-haspopup="listbox"
                                    aria-expanded="false"
                                    data-convocatoria-classification-trigger>
                                    <span data-convocatoria-classification-label>
                                        <?= $texto($clasificacionCrearLabel) ?>
                                    </span>
                                    <i class="bi bi-chevron-down" aria-hidden="true"></i>
                                </button>

                                <div
                                    class="convocatoria-form-type-menu"
                                    role="listbox"
                                    aria-label="Seleccionar tipo de convocatoria"
                                    data-convocatoria-classification-menu>
                                    <button
                                        type="button"
                                        class="convocatoria-form-type-option <?= $clasificacionCrear === '' ? 'is-selected' : '' ?>"
                                        role="option"
                                        aria-selected="<?= $clasificacionCrear === '' ? 'true' : 'false' ?>"
                                        data-convocatoria-classification-option="">
                                        Selecciona una opción
                                    </button>

                                    <?php foreach ($clasificacionesDisponibles as $clasificacion): ?>
                                        <button
                                            type="button"
                                            class="convocatoria-form-type-option <?= $clasificacionCrear === $clasificacion['valor'] ? 'is-selected' : '' ?>"
                                            role="option"
                                            aria-selected="<?= $clasificacionCrear === $clasificacion['valor'] ? 'true' : 'false' ?>"
                                            data-convocatoria-classification-option="<?= $texto($clasificacion['valor']) ?>">
                                            <?= $texto($clasificacion['etiqueta']) ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <small class="form-text">
                                Selecciona la opción a la que pertenece esta convocatoria.
                            </small>
                        </div>

<div class="system-form-full convocatoria-public-form-share"
                            data-referencia-internacional="<?= $texto($referenciaInternacional ?? '') ?>"
                            data-convocatoria-form-share
                            data-registro-url="<?= BASE_URL ?>index.php?controller=formularioPublico&action=registro<?= !empty($aliadoConvocatoria) ? '&amp;aliado=' . $texto($aliadoConvocatoria['token']) : '' ?>"
                            data-inscripcion-url="<?= BASE_URL ?>index.php?controller=formularioPublico&action=inscripcion<?= !empty($aliadoConvocatoria) ? '&amp;aliado=' . $texto($aliadoConvocatoria['token']) : '' ?>"
                            data-aliado-id="<?= (int)($seguimientoOrigenId ?? 0) ?>"
                            data-convenio-nombre="<?= $texto($convenioNombreOrigen ?? '') ?>"
                            data-estado-id="<?= (int)($territorioSeleccionado['id'] ?? 0) ?>"
                            data-estado-nombre="<?= $texto($territorioSeleccionado['nombre'] ?? '') ?>"
                            data-quickchart-url="https://quickchart.io/qr">

                            <div class="convocatoria-public-form-heading">
                                <div>
                                    <span class="convocatoria-public-form-kicker">FORMULARIO PÚBLICO</span>
                                    <strong>Enlace y código QR</strong>
                                    <small>
                                        Selecciona el formulario que se utilizará para esta convocatoria.
                                    </small>
                                </div>
                            </div>

                            <div class="convocatoria-public-form-grid">
                                <div>
                                    <label
                                        class="form-label login-label"
                                        for="crear_convocatoria_formulario_publico">
                                        Formulario
                                    </label>
                                    <div
                                        class="convocatoria-form-type-dropdown"
                                        data-convocatoria-form-type-dropdown>
                                        <input
                                            type="hidden"
                                            name="formulario_internacional"
                                            id="crear_convocatoria_formulario_publico"
                                            value=""
                                            data-convocatoria-form-type>

                                        <button
                                            type="button"
                                            class="convocatoria-form-type-trigger"
                                            aria-haspopup="listbox"
                                            aria-expanded="false"
                                            data-convocatoria-form-type-trigger>
                                            <span data-convocatoria-form-type-label>
                                                Selecciona una opción
                                            </span>
                                            <i class="bi bi-chevron-down" aria-hidden="true"></i>
                                        </button>

                                        <div
                                            class="convocatoria-form-type-menu"
                                            role="listbox"
                                            aria-label="Seleccionar formulario"
                                            data-convocatoria-form-type-menu>
                                            <button
                                                type="button"
                                                class="convocatoria-form-type-option is-selected"
                                                role="option"
                                                aria-selected="true"
                                                data-convocatoria-form-type-value="">
                                                Selecciona una opción
                                            </button>
                                            <button
                                                type="button"
                                                class="convocatoria-form-type-option"
                                                role="option"
                                                aria-selected="false"
                                                data-convocatoria-form-type-value="registro">
                                                Registro
                                            </button>
                                            <button
                                                type="button"
                                                class="convocatoria-form-type-option"
                                                role="option"
                                                aria-selected="false"
                                                data-convocatoria-form-type-value="inscripcion">
                                                Inscripción
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <div class="convocatoria-public-form-actions">
                                    <button
                                        type="button"
                                        class="btn btn-system-save"
                                        data-convocatoria-generate-link>
                                        <i class="bi bi-link-45deg"></i>
                                        Generar link
                                    </button>

                                    <button
                                        type="button"
                                        class="btn btn-system-save"
                                        data-convocatoria-generate-qr
                                        disabled>
                                        <i class="bi bi-qr-code"></i>
                                        Generar QR
                                    </button>
                                </div>
                            </div>

                            <div
                                class="convocatoria-public-form-result d-none"
                                data-convocatoria-form-result>
                                <div class="convocatoria-public-form-url">
                                    <i class="bi bi-link-45deg"></i>
                                    <input
                                        type="text"
                                        readonly
                                        data-convocatoria-form-url>
                                    <button
                                        type="button"
                                        class="btn"
                                        title="Copiar enlace"
                                        aria-label="Copiar enlace"
                                        data-convocatoria-copy-link>
                                        <i class="bi bi-copy"></i>
                                    </button>
                                </div>

                            </div>
                        </div>

                        <div class="system-form-full">
                            <label class="form-label login-label" for="crear_convocatoria_enlace_registro">
                                Enlace de registro
                            </label>
                            <input
                                type="url"
                                class="form-control system-form-control"
                                id="crear_convocatoria_enlace_registro"
                                name="enlace_registro"
                                data-convocatoria-link-input
                                maxlength="1000"
                                placeholder="https://..."
                                value="<?= $texto($datosCrear['enlace_registro'] ?? '') ?>">
                            <small class="form-text">
                                Opcional. Cuenta Clave podrá copiar este enlace al preparar la convocatoria para WhatsApp.
                            </small>
                        </div>

<div class="system-form-full">
                            <label class="form-label login-label" for="crear_convocatoria_titulo">Título</label>
                            <input
                                type="text"
                                class="form-control system-form-control"
                                id="crear_convocatoria_titulo"
                                name="titulo"
                                data-convocatoria-context-title
                                value="<?= $texto($datosCrear['titulo'] ?? '') ?>"
                                required>
                        </div>

                        

                        <div>
                            <label class="form-label login-label" for="crear_convocatoria_fecha_inicio">Fecha de inicio</label>
                            <input
                                type="date"
                                class="form-control system-form-control"
                                id="crear_convocatoria_fecha_inicio"
                                name="fecha_inicio"
                                value="<?= $texto($datosCrear['fecha_inicio'] ?? '') ?>"
                                >
                        </div>

                        <div>
                            <label class="form-label login-label" for="crear_convocatoria_fecha_termino">Fecha de término</label>
                            <input
                                type="date"
                                class="form-control system-form-control"
                                id="crear_convocatoria_fecha_termino"
                                name="fecha_termino"
                                value="<?= $texto($datosCrear['fecha_termino'] ?? '') ?>"
                                <?= $fechasOpcionalesConvocatoria ? '' : 'required' ?>>
                        </div>

                        <div class="system-form-full">
                            <small class="form-text">
                                Define la vigencia de la convocatoria. En Inscripciones Abiertas las fechas pueden ser opcionales.
                            </small>
                        </div>

                        <div>
                            <label class="form-label login-label" for="crear_convocatoria_estado_boton">
                                Disponibilidad
                            </label>

                            <div
                                class="convocatoria-form-type-dropdown"
                                data-convocatoria-availability-dropdown>
                                <input
                                    type="hidden"
                                    id="crear_convocatoria_estado"
                                    name="estado"
                                    value="<?= $texto($datosCrear['estado'] ?? '1') ?>"
                                    data-convocatoria-availability-value>

                                <button
                                    type="button"
                                    class="convocatoria-form-type-trigger"
                                    id="crear_convocatoria_estado_boton"
                                    aria-haspopup="listbox"
                                    aria-expanded="false"
                                    data-convocatoria-availability-trigger>
                                    <span data-convocatoria-availability-label>
                                        <?= (string)($datosCrear['estado'] ?? '1') === '0' ? 'Inactiva' : 'Activa' ?>
                                    </span>
                                    <i class="bi bi-chevron-down" aria-hidden="true"></i>
                                </button>

                                <div
                                    class="convocatoria-form-type-menu"
                                    role="listbox"
                                    aria-label="Seleccionar disponibilidad"
                                    data-convocatoria-availability-menu>
                                    <button
                                        type="button"
                                        class="convocatoria-form-type-option <?= (string)($datosCrear['estado'] ?? '1') !== '0' ? 'is-selected' : '' ?>"
                                        role="option"
                                        aria-selected="<?= (string)($datosCrear['estado'] ?? '1') !== '0' ? 'true' : 'false' ?>"
                                        data-convocatoria-availability-option="1">
                                        Activa
                                    </button>

                                    <button
                                        type="button"
                                        class="convocatoria-form-type-option <?= (string)($datosCrear['estado'] ?? '1') === '0' ? 'is-selected' : '' ?>"
                                        role="option"
                                        aria-selected="<?= (string)($datosCrear['estado'] ?? '1') === '0' ? 'true' : 'false' ?>"
                                        data-convocatoria-availability-option="0">
                                        Inactiva
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div>
                            <label class="form-label login-label" for="crear_convocatoria_imagen">Imagen</label>
                            <input
                                type="file"
                                class="form-control system-form-control"
                                id="crear_convocatoria_imagen"
                                name="imagen"
                                accept="image/jpeg,image/png,image/webp"
                                required>
                        </div>

                        <input
                            type="hidden"
                            name="estados[]"
                            value="<?= $territorioActualId ?>">

                        
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-system-cancel" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-system-save">
                        <i class="bi bi-check2-circle me-2"></i>
                        Guardar convocatoria
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div
    class="modal fade"
    id="modalConvocatoriaQr"
    tabindex="-1"
    aria-labelledby="modalConvocatoriaQrTitulo"
    aria-hidden="true"
    data-convocatoria-qr-modal>
    <div class="modal-dialog modal-dialog-centered modal-xl convocatoria-qr-dialog">
        <div class="modal-content system-form-modal convocatoria-qr-modal">
            <div class="modal-header system-form-modal-header">
                <div>
                    <span class="convocatoria-public-form-kicker">CÓDIGO QR</span>
                    <h5 class="system-form-modal-title" id="modalConvocatoriaQrTitulo">
                        Personalizar código QR
                    </h5>
                    <p class="system-form-modal-subtitle">
                        Los cambios se reflejan automáticamente en la vista previa.
                    </p>
                </div>
                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                    aria-label="Cerrar"></button>
            </div>

            <div class="modal-body">
                <div class="convocatoria-qr-workspace">
                    <div class="convocatoria-qr-preview-column">
                        <div class="convocatoria-qr-preview">
                            <div
                                class="spinner-border text-primary d-none"
                                role="status"
                                data-convocatoria-qr-loader>
                                <span class="visually-hidden">Cargando...</span>
                            </div>
                            <img
                                src=""
                                alt="Código QR del formulario seleccionado"
                                data-convocatoria-qr-preview>
                        </div>

                        <div class="convocatoria-qr-link" data-convocatoria-qr-link></div>

                        <div class="convocatoria-qr-output-actions">
                            <button
                                type="button"
                                class="btn btn-system-cancel"
                                data-convocatoria-copy-qr>
                                <i class="bi bi-copy"></i>
                                Copiar QR
                            </button>
                            <button
                                type="button"
                                class="btn btn-system-save"
                                data-convocatoria-download-qr>
                                <i class="bi bi-download"></i>
                                Descargar PNG
                            </button>
                        </div>
                    </div>

                    <div class="convocatoria-qr-customizer">
                        <div class="convocatoria-qr-controls">
                            <label class="convocatoria-qr-control">
                                <span>Color del QR</span>
                                <input type="color" value="#223a84" data-convocatoria-qr-dark>
                            </label>

                            <label class="convocatoria-qr-control">
                                <span>Color de fondo</span>
                                <input type="color" value="#ffffff" data-convocatoria-qr-light>
                            </label>

                            <label class="convocatoria-qr-control">
                                <span>Color de esquinas</span>
                                <input type="color" value="#223a84" data-convocatoria-qr-finder-color>
                            </label>

                            <label class="convocatoria-qr-control">
                                <span>Estilo de puntos</span>
                                <select data-convocatoria-qr-dot-style>
                                    <option value="square">Cuadrado</option>
                                    <option value="dots">Circular</option>
                                    <option value="rounded">Redondeado</option>
                                    <option value="extra-rounded">Muy redondeado</option>
                                    <option value="classy">Clásico</option>
                                    <option value="classy-rounded">Clásico redondeado</option>
                                </select>
                            </label>

                            <label class="convocatoria-qr-control">
                                <span>Estilo de esquinas</span>
                                <select data-convocatoria-qr-finder-style>
                                    <option value="square">Cuadrado</option>
                                    <option value="dot">Circular</option>
                                    <option value="extra-rounded">Redondeado</option>
                                </select>
                            </label>

                            <label class="convocatoria-qr-control">
                                <span>Centro de esquinas</span>
                                <select data-convocatoria-qr-finder-dot-style>
                                    <option value="square">Cuadrado</option>
                                    <option value="dot">Circular</option>
                                </select>
                            </label>

                            <label class="convocatoria-qr-control">
                                <span>Tamaño de imagen</span>
                                <select data-convocatoria-qr-size>
                                    <option value="400">400 px</option>
                                    <option value="600" selected>600 px</option>
                                    <option value="800">800 px</option>
                                    <option value="1000">1000 px</option>
                                </select>
                            </label>

                            <label class="convocatoria-qr-control">
                                <span>Margen</span>
                                <select data-convocatoria-qr-margin>
                                    <option value="0">Sin margen</option>
                                    <option value="1">1 módulo</option>
                                    <option value="2" selected>2 módulos</option>
                                    <option value="4">4 módulos</option>
                                </select>
                            </label>

                            <label class="convocatoria-qr-control">
                                <span>Corrección de error</span>
                                <select data-convocatoria-qr-ec-level>
                                    <option value="L">Baja (L)</option>
                                    <option value="M" selected>Media (M)</option>
                                    <option value="Q">Alta (Q)</option>
                                    <option value="H">Máxima (H)</option>
                                </select>
                            </label>
                        </div>

                        <label class="convocatoria-qr-transparent">
                            <input type="checkbox" data-convocatoria-qr-transparent>
                            <span>Fondo transparente</span>
                        </label>

                        <div class="convocatoria-qr-image-options">
                            <div class="convocatoria-qr-image-heading">
                                <div>
                                    <strong>Imagen en el QR</strong>
                                    <small>
                                        Agrega un logotipo o imagen al centro del código QR.
                                    </small>
                                </div>
                                <label class="convocatoria-qr-image-switch">
                                    <input
                                        type="checkbox"
                                        class="form-check-input"
                                        data-convocatoria-qr-image-enabled>
                                    <span>Usar imagen</span>
                                </label>
                            </div>

                            <div class="convocatoria-qr-image-config d-none" data-convocatoria-qr-image-config>
                                <div class="convocatoria-qr-image-source">
                                    <label>
                                        <input
                                            type="radio"
                                            name="convocatoria_qr_image_source"
                                            value="upload"
                                            checked
                                            data-convocatoria-qr-image-source>
                                        Desde mi equipo
                                    </label>
                                    <label>
                                        <input
                                            type="radio"
                                            name="convocatoria_qr_image_source"
                                            value="url"
                                            data-convocatoria-qr-image-source>
                                        URL pública
                                    </label>
                                </div>

                                <div data-convocatoria-qr-upload-container>
                                    <label class="convocatoria-qr-control convocatoria-qr-control-full">
                                        <span>Seleccionar imagen</span>
                                        <input
                                            type="file"
                                            accept="image/png,image/jpeg,image/webp"
                                            data-convocatoria-qr-upload>
                                    </label>
                                    <div class="convocatoria-qr-upload-preview d-none" data-convocatoria-qr-upload-preview>
                                        <img src="" alt="Vista previa de imagen" data-convocatoria-qr-upload-preview-image>
                                        <div>
                                            <strong data-convocatoria-qr-upload-name></strong>
                                            <small>Imagen seleccionada para el centro del QR.</small>
                                        </div>
                                    </div>
                                </div>

                                <div class="d-none" data-convocatoria-qr-url-container>
                                    <label class="convocatoria-qr-control convocatoria-qr-control-full">
                                        <span>URL pública de la imagen</span>
                                        <input
                                            type="url"
                                            placeholder="https://..."
                                            data-convocatoria-qr-image-url>
                                    </label>
                                </div>

                                <div class="convocatoria-qr-image-size">
                                    <div>
                                        <span>Tamaño de la imagen central</span>
                                        <strong data-convocatoria-qr-image-size-value>20%</strong>
                                    </div>
                                    <input
                                        type="range"
                                        min="10"
                                        max="30"
                                        step="1"
                                        value="20"
                                        data-convocatoria-qr-image-size>
                                </div>
                            </div>
                        </div>

                        <div class="convocatoria-qr-customizer-actions">
                            <button
                                type="button"
                                class="btn btn-system-cancel"
                                data-convocatoria-reset-qr>
                                <i class="bi bi-arrow-counterclockwise"></i>
                                Restablecer
                            </button>
                        </div>
                    </div>
                </div>

                <p class="convocatoria-qr-help">
                    Verifica que el código continúe escaneándose correctamente antes de compartirlo.
                </p>
            </div>
        </div>
    </div>
</div>

