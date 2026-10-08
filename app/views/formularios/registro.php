<?php
$estados = is_array($estados ?? null) ? $estados : [];
$perfilesInteres = is_array($perfilesInteres ?? null)
    ? $perfilesInteres
    : [];

$texto = static fn($valor) => htmlspecialchars(
    (string)$valor,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);

$fechaMaximaNacimiento = date('Y-m-d');
?>

<div class="formularios-page formularios-detail-page">
    <div class="formularios-back-row">
        <a
            class="data-back-link"
            href="<?= BASE_URL ?>index.php?controller=formulario&action=index">
            <i class="bi bi-arrow-left"></i>
            Volver a formularios
        </a>
    </div>

    <section class="dashboard-panel formularios-detail-hero">
        <div class="formularios-detail-heading">
            <span class="formularios-detail-icon is-registro" aria-hidden="true">
                <i class="bi bi-person-plus"></i>
            </span>

            <div>
                <span class="formularios-kicker">FORMULARIO</span>
                <h2>Registro</h2>
                <p>
                    Captura los datos de la persona interesada y valida su información
                    antes de guardar el registro.
                </p>
            </div>
        </div>
    </section>

    <section class="dashboard-panel formularios-registration-panel">
        <div class="formularios-registration-heading">
            <div>
                <span class="formularios-kicker">DATOS DE REGISTRO</span>
                <h3>Información de la persona interesada</h3>
                <p>
                    Los campos marcados con <strong>*</strong> son obligatorios.
                    El correo y el móvil se solicitan dos veces para corroborar la captura.
                </p>
            </div>

            <span class="formularios-registration-badge">
                <i class="bi bi-shield-check"></i>
                Validación de datos
            </span>
        </div>

        <form
            class="formularios-registration-form"
            action="<?= BASE_URL ?>index.php?controller=formulario&action=guardarRegistro"
            method="post"
            novalidate
            data-formulario-registro>

            <section class="formularios-registration-section">
                <div class="formularios-registration-section-title">
                    <span><i class="bi bi-person"></i></span>
                    <div>
                        <h4>Datos personales</h4>
                        <p>Información básica para identificar a la persona.</p>
                    </div>
                </div>

                <div class="formularios-registration-grid">
                    <div class="formularios-field">
                        <label for="form_registro_nombre">
                            Nombre <strong>*</strong>
                        </label>
                        <input
                            type="text"
                            class="form-control"
                            id="form_registro_nombre"
                            name="nombre"
                            maxlength="80"
                            autocomplete="given-name"
                            placeholder="Ej. María Fernanda"
                            required
                            data-registro-nombre>
                        <small>Solo letras, espacios, apóstrofes y guiones.</small>
                        <div class="invalid-feedback">
                            Ingresa un nombre válido.
                        </div>
                    </div>

                    <div class="formularios-field">
                        <label for="form_registro_apellido">
                            Apellido <strong>*</strong>
                        </label>
                        <input
                            type="text"
                            class="form-control"
                            id="form_registro_apellido"
                            name="apellido"
                            maxlength="120"
                            autocomplete="family-name"
                            placeholder="Ej. García López"
                            required
                            data-registro-apellido>
                        <small>Captura uno o ambos apellidos.</small>
                        <div class="invalid-feedback">
                            Ingresa un apellido válido.
                        </div>
                    </div>

                    <div class="formularios-field">
                        <label for="form_registro_fecha_nacimiento">
                            Fecha de nacimiento <strong>*</strong>
                        </label>
                        <input
                            type="date"
                            class="form-control"
                            id="form_registro_fecha_nacimiento"
                            name="fecha_nacimiento"
                            min="1900-01-01"
                            max="<?= $texto($fechaMaximaNacimiento) ?>"
                            autocomplete="bday"
                            required
                            data-registro-fecha>
                        <small>No se permiten fechas futuras.</small>
                        <div class="invalid-feedback">
                            Selecciona una fecha de nacimiento válida.
                        </div>
                    </div>

                    <div class="formularios-field">
                        <label for="form_registro_perfil">
                            Perfil de interés <strong>*</strong>
                        </label>
                        <select
                            class="form-select"
                            id="form_registro_perfil"
                            name="perfil_interes"
                            required
                            data-registro-perfil>
                            <option value="">Selecciona una opción</option>
                            <?php foreach ($perfilesInteres as $perfil): ?>
                                <option value="<?= $texto($perfil) ?>">
                                    <?= $texto($perfil) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small>Selecciona el programa que le interesa.</small>
                        <div class="invalid-feedback">
                            Selecciona un perfil de interés.
                        </div>
                    </div>
                </div>
            </section>

            <section class="formularios-registration-section">
                <div class="formularios-registration-section-title">
                    <span><i class="bi bi-telephone"></i></span>
                    <div>
                        <h4>Datos de contacto</h4>
                        <p>
                            Corrobora correo y móvil antes de guardar el registro.
                        </p>
                    </div>
                </div>

                <div class="formularios-registration-grid">
                    <div class="formularios-field">
                        <label for="form_registro_movil">
                            Móvil <strong>*</strong>
                        </label>
                        <input
                            type="tel"
                            class="form-control"
                            id="form_registro_movil"
                            name="movil"
                            maxlength="10"
                            inputmode="numeric"
                            autocomplete="tel"
                            placeholder="10 dígitos"
                            required
                            data-registro-movil>
                        <small>Captura únicamente los 10 dígitos.</small>
                        <div class="invalid-feedback">
                            Ingresa un número móvil de 10 dígitos.
                        </div>
                    </div>

                    <div class="formularios-field">
                        <label for="form_registro_movil_confirmacion">
                            Confirmar móvil <strong>*</strong>
                        </label>
                        <input
                            type="tel"
                            class="form-control"
                            id="form_registro_movil_confirmacion"
                            name="movil_confirmacion"
                            maxlength="10"
                            inputmode="numeric"
                            autocomplete="off"
                            placeholder="Repite el número móvil"
                            required
                            data-registro-movil-confirmacion>
                        <small>Debe coincidir con el número anterior.</small>
                        <div class="invalid-feedback">
                            Los números móviles no coinciden.
                        </div>
                    </div>

                    <div class="formularios-field">
                        <label for="form_registro_correo">
                            Correo electrónico <strong>*</strong>
                        </label>
                        <input
                            type="email"
                            class="form-control"
                            id="form_registro_correo"
                            name="correo"
                            maxlength="190"
                            autocomplete="email"
                            placeholder="correo@dominio.com"
                            required
                            data-registro-correo>
                        <small>Utiliza un correo electrónico válido.</small>
                        <div class="invalid-feedback">
                            Ingresa un correo electrónico válido.
                        </div>
                    </div>

                    <div class="formularios-field">
                        <label for="form_registro_correo_confirmacion">
                            Confirmar correo electrónico <strong>*</strong>
                        </label>
                        <input
                            type="email"
                            class="form-control"
                            id="form_registro_correo_confirmacion"
                            name="correo_confirmacion"
                            maxlength="190"
                            autocomplete="off"
                            placeholder="Repite el correo electrónico"
                            required
                            data-registro-correo-confirmacion>
                        <small>Debe coincidir con el correo anterior.</small>
                        <div class="invalid-feedback">
                            Los correos electrónicos no coinciden.
                        </div>
                    </div>
                </div>
            </section>

            <section class="formularios-registration-section">
                <div class="formularios-registration-section-title">
                    <span><i class="bi bi-briefcase"></i></span>
                    <div>
                        <h4>Información laboral</h4>
                        <p>
                            Datos de referencia sobre la actividad laboral actual.
                        </p>
                    </div>
                </div>

                <div class="formularios-registration-grid">
                    <div class="formularios-field">
                        <label for="form_registro_lugar_laboras">
                            Lugar donde laboras <strong>*</strong>
                        </label>
                        <input
                            type="text"
                            class="form-control"
                            id="form_registro_lugar_laboras"
                            name="lugar_laboras"
                            maxlength="180"
                            placeholder="Empresa, institución u organización"
                            required
                            data-registro-lugar-laboras>
                        <div class="invalid-feedback">
                            Indica el lugar donde laboras.
                        </div>
                    </div>

                    <div class="formularios-field">
                        <label for="form_registro_cargo">
                            Cargo o puesto
                        </label>
                        <input
                            type="text"
                            class="form-control"
                            id="form_registro_cargo"
                            name="cargo_puesto"
                            maxlength="160"
                            placeholder="Ej. Coordinador administrativo">
                        <small>Este campo es opcional.</small>
                    </div>
                </div>
            </section>

            <section class="formularios-registration-section">
                <div class="formularios-registration-section-title">
                    <span><i class="bi bi-geo-alt"></i></span>
                    <div>
                        <h4>Ubicación</h4>
                        <p>
                            Primero selecciona el estado y después el municipio.
                        </p>
                    </div>
                </div>

                <div class="formularios-registration-grid">
                    <div class="formularios-field">
                        <label for="form_registro_estado">
                            Estado <strong>*</strong>
                        </label>
                        <select
                            class="form-select"
                            id="form_registro_estado"
                            name="estado_id"
                            required
                            data-registro-estado
                            data-municipios-url="<?= BASE_URL ?>index.php?controller=formulario&action=municipios">
                            <option value="">Selecciona un estado</option>
                            <?php foreach ($estados as $estado): ?>
                                <option value="<?= (int)($estado['id'] ?? 0) ?>">
                                    <?= $texto($estado['nombre'] ?? '') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="invalid-feedback">
                            Selecciona un estado.
                        </div>
                    </div>

                    <div class="formularios-field">
                        <label for="form_registro_municipio">
                            Municipio <strong>*</strong>
                        </label>
                        <select
                            class="form-select"
                            id="form_registro_municipio"
                            name="municipio_id"
                            required
                            disabled
                            data-registro-municipio>
                            <option value="">
                                Primero selecciona un estado
                            </option>
                        </select>
                        <small data-registro-municipio-ayuda>
                            Los municipios se cargarán según el estado seleccionado.
                        </small>
                        <div class="invalid-feedback">
                            Selecciona un municipio.
                        </div>
                    </div>
                </div>
            </section>

            <div class="formularios-registration-actions">
                <button
                    type="reset"
                    class="btn btn-system-cancel"
                    data-registro-limpiar>
                    Limpiar
                </button>

                <button
                    type="submit"
                    class="btn btn-system-save"
                    data-registro-submit>
                    <i class="bi bi-check2-circle"></i>
                    Guardar registro
                </button>
            </div>
        </form>
    </section>
</div>
