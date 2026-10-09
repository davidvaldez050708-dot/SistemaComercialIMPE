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

<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Formulario de registro</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
        rel="stylesheet">
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        rel="stylesheet">
    <link
        href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">

    <link
        rel="stylesheet"
        href="<?= BASE_URL ?>public/css/global.css">
    <link
        rel="stylesheet"
        href="<?= BASE_URL ?>public/css/formularios_publico.css?v=<?= filemtime(ROOT_PATH . '/public/css/formularios_publico.css') ?>">
</head>

<body class="formulario-publico-body">
    <main class="formulario-publico-shell">
        <section class="formulario-publico-hero">
            <div class="formulario-publico-hero-copy">
                <h1>Formulario de registro</h1>
                <p>Completa la siguiente información.</p>
                <p>Los campos marcados con <strong>*</strong> son obligatorios.</p>
            </div>

            <div class="formulario-publico-hero-logo">
                <img
                    src="<?= BASE_URL ?>public/img/brand/porcayo-grupo8.png"
                    alt="Porcayo Learning Group"
                    loading="eager">
            </div>
        </section>

        <section class="formulario-publico-card">
            <form
                action="<?= $texto($registroAction) ?>"
                method="post"
                novalidate
                data-formulario-registro-publico>

                <section class="formulario-publico-section">
                    <div class="formulario-publico-section-heading">
                        <span class="formulario-publico-section-icon">
                            <i class="bi bi-person"></i>
                        </span>
                        <h2>Datos personales</h2>
                    </div>

                    <div class="formulario-publico-grid">
                        <div class="formulario-publico-field">
                            <label for="registro_publico_nombre">
                                Nombre <strong>*</strong>
                            </label>
                            <input
                                type="text"
                                id="registro_publico_nombre"
                                name="nombre"
                                maxlength="80"
                                autocomplete="given-name"
                                placeholder="Ej. María Fernanda"
                                required
                                data-publico-nombre>
                            <div class="invalid-feedback">
                                Ingresa un nombre válido.
                            </div>
                        </div>

                        <div class="formulario-publico-field">
                            <label for="registro_publico_apellido">
                                Apellido <strong>*</strong>
                            </label>
                            <input
                                type="text"
                                id="registro_publico_apellido"
                                name="apellido"
                                maxlength="120"
                                autocomplete="family-name"
                                placeholder="Ej. García López"
                                required
                                data-publico-apellido>
                            <div class="invalid-feedback">
                                Ingresa un apellido válido.
                            </div>
                        </div>

                        <div class="formulario-publico-field">
                            <label for="registro_publico_fecha">
                                Fecha de nacimiento <strong>*</strong>
                            </label>
                            <input
                                type="date"
                                id="registro_publico_fecha"
                                name="fecha_nacimiento"
                                min="1900-01-01"
                                max="<?= $texto($fechaMaximaNacimiento) ?>"
                                autocomplete="bday"
                                required
                                data-publico-fecha>
                            <div class="invalid-feedback">
                                Selecciona una fecha válida.
                            </div>
                        </div>
                    </div>
                </section>

                <section class="formulario-publico-section">
                    <div class="formulario-publico-section-heading">
                        <span class="formulario-publico-section-icon">
                            <i class="bi bi-telephone"></i>
                        </span>
                        <h2>Datos de contacto</h2>
                    </div>

                    <div class="formulario-publico-grid">
                        <div class="formulario-publico-field">
                            <label for="registro_publico_movil">
                                Móvil <strong>*</strong>
                            </label>
                            <input
                                type="tel"
                                id="registro_publico_movil"
                                name="movil"
                                maxlength="10"
                                inputmode="numeric"
                                autocomplete="tel"
                                placeholder="10 dígitos"
                                required
                                data-publico-movil>
                            <div class="invalid-feedback">
                                Ingresa un número móvil de 10 dígitos.
                            </div>
                        </div>

                        <div class="formulario-publico-field">
                            <label for="registro_publico_correo">
                                Correo electrónico <strong>*</strong>
                            </label>
                            <input
                                type="email"
                                id="registro_publico_correo"
                                name="correo"
                                maxlength="190"
                                autocomplete="email"
                                placeholder="Ej. correo@ejemplo.com"
                                required
                                data-publico-correo>
                            <div class="invalid-feedback">
                                Ingresa un correo electrónico válido.
                            </div>
                        </div>
                    </div>
                </section>

                <section class="formulario-publico-section">
                    <div class="formulario-publico-section-heading">
                        <span class="formulario-publico-section-icon">
                            <i class="bi bi-briefcase"></i>
                        </span>
                        <h2>Información laboral</h2>
                    </div>

                    <div class="formulario-publico-grid">
                        <div class="formulario-publico-field">
                            <label for="registro_publico_perfil">
                                Perfil de interés <strong>*</strong>
                            </label>
                            <select
                                id="registro_publico_perfil"
                                name="perfil_interes"
                                required
                                data-publico-perfil>
                                <option value="">Selecciona una opción</option>
                                <?php foreach ($perfilesInteres as $perfil): ?>
                                    <option value="<?= $texto($perfil) ?>">
                                        <?= $texto($perfil) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback">
                                Selecciona un perfil de interés.
                            </div>
                        </div>

                        <div class="formulario-publico-field">
                            <label for="registro_publico_lugar">
                                Lugar donde laboras
                            </label>
                            <input
                                type="text"
                                id="registro_publico_lugar"
                                name="lugar_laboras"
                                maxlength="180"
                                placeholder="Ej. Empresa o institución"
                                data-publico-lugar>
                        </div>

                        <div class="formulario-publico-field is-full">
                            <label for="registro_publico_cargo">
                                Cargo o Puesto
                            </label>
                            <input
                                type="text"
                                id="registro_publico_cargo"
                                name="cargo_puesto"
                                maxlength="160"
                                placeholder="Ej. Analista, Supervisor, Docente, etc."
                                data-publico-cargo>
                        </div>
                    </div>
                </section>

                <section class="formulario-publico-section">
                    <div class="formulario-publico-section-heading">
                        <span class="formulario-publico-section-icon">
                            <i class="bi bi-geo-alt"></i>
                        </span>
                        <h2>Ubicación</h2>
                    </div>

                    <div class="formulario-publico-grid">
                        <div class="formulario-publico-field formulario-publico-municipio">
                            <label for="registro_publico_municipio">
                                Municipio <strong>*</strong>
                            </label>
                            <select
                                id="registro_publico_municipio"
                                name="municipio_id"
                                required
                                disabled
                                data-publico-municipio>
                                <option value="">
                                    Primero selecciona un estado
                                </option>
                            </select>
                            <div class="invalid-feedback">
                                Selecciona un municipio.
                            </div>
                        </div>

                        <div class="formulario-publico-field formulario-publico-estado">
                            <label for="registro_publico_estado">
                                Estado <strong>*</strong>
                            </label>
                            <select
                                id="registro_publico_estado"
                                name="estado_id"
                                required
                                data-publico-estado
                                data-municipios-url="<?= $texto($municipiosUrl) ?>">
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
                    </div>
                </section>

                <button
                    type="submit"
                    class="formulario-publico-submit"
                    data-publico-submit>
                    <i class="bi bi-send"></i>
                    <span>¡Listo!</span>
                </button>
            </form>
        </section>
    </main>

    <script
        src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js">
    </script>
    <script
        src="<?= BASE_URL ?>public/javascript/formularios_registro_publico.js?v=<?= filemtime(ROOT_PATH . '/public/javascript/formularios_registro_publico.js') ?>">
    </script>
</body>
</html>
