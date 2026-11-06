<?php
/**
 * Vista previa controlada de Titulación - Ejecutivas.
 * Reutiliza el hero institucional, sus imágenes y CSS del Registro público.
 * Acceso restringido a Marketing. Este formulario NO envía ni guarda datos.
 */
$texto = static function ($dato): string {
    return htmlspecialchars((string)$dato, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
$perfilesEjecutivas = is_array($perfilesEjecutivas ?? null) ? $perfilesEjecutivas : [];
$csrfEjecutivas = (string)($csrfEjecutivas ?? '');
$hoyMexico = (new DateTimeImmutable('now', new DateTimeZone('America/Mexico_City')))->format('Y-m-d');
$secciones = [
    [
        'titulo' => 'Datos personales',
        'descripcion' => 'Captura los datos exactamente como aparecen en la constancia CURP y los documentos oficiales.',
        'icono' => 'bi-person-vcard',
        'campos' => [
            ['nombre','Nombre(s)','text','Ej. María Fernanda',80,'given-name',true],
            ['apellidos','Apellidos','text','Ej. García López',120,'family-name',true],
            ['fecha_nacimiento','Fecha de nacimiento','date','',10,'bday',true],
            ['genero','Sexo registrado en CURP','select','',1,'off',true],
            ['curp','CURP','text','18 caracteres',18,'off',true],
            ['nombre_padres','Nombre de los padres','text','Ej. SARA VAZQUEZ / CARLOS DIAZ',250,'off',true]
        ]
    ],
    [
        'titulo' => 'Datos de contacto',
        'descripcion' => 'El correo y el teléfono deben capturarse dos veces y coincidir.',
        'icono' => 'bi-telephone',
        'campos' => [
            ['correo','Correo electrónico','email','ejemplo@correo.com',190,'email',true],
            ['correo_confirmacion','Confirmar correo electrónico','email','Repite el correo',190,'off',true],
            ['movil','Número móvil','tel','10 dígitos',10,'tel-national',true],
            ['movil_confirmacion','Confirmar número móvil','tel','Repite el número',10,'off',true]
        ]
    ],
    [
        'titulo' => 'Domicilio',
        'descripcion' => 'Utiliza el domicilio que corresponde a tus documentos.',
        'icono' => 'bi-geo-alt',
        'campos' => [
            ['calle','Calle','text','Nombre completo de la calle',190,'address-line1',true],
            ['numero','Número','text','Número exterior o S/N',20,'address-line2',true],
            ['ciudad','Ciudad','text','Ciudad',120,'address-level2',true],
            ['codigo_postal','Código postal','text','5 dígitos',5,'postal-code',true],
            ['municipio','Municipio','text','Municipio o alcaldía',120,'off',true],
            ['entidad_federativa','Entidad federativa','text','Estado',90,'address-level1',true]
        ]
    ],
    [
        'titulo' => 'Información laboral y perfil',
        'descripcion' => 'Selecciona la licenciatura o ingeniería A286 para la evaluación.',
        'icono' => 'bi-mortarboard',
        'campos' => [
            ['empresa_labora','Empresa donde labora','text','Nombre de la empresa',180,'organization',true],
            ['cargo_puesto','Cargo o puesto','text','Opcional',160,'organization-title',false],
            ['perfil_evaluacion','Perfil de evaluación','select','',150,'off',true]
        ]
    ]
];
?>
<!doctype html>
<html lang="es">
<head>
    <?php require dirname(__DIR__) . '/layout/favicon.php'; ?>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Vista previa — Inscripción Titulación Ejecutivas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>public/css/global.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>public/css/formularios_publico.css?v=<?= filemtime(ROOT_PATH . '/public/css/formularios_publico.css') ?>">
    <link rel="stylesheet" href="<?= BASE_URL ?>public/css/formularios_hero_institucional.css?v=<?= filemtime(ROOT_PATH . '/public/css/formularios_hero_institucional.css') ?>">
    <link rel="stylesheet" href="<?= BASE_URL ?>public/css/formularios_ejecutivas_piloto.css?v=<?= filemtime(ROOT_PATH . '/public/css/formularios_ejecutivas_piloto.css') ?>">
</head>
<body class="formulario-publico-body formulario-publico-inscripcion formulario-ejecutivas-piloto">
    <main class="formulario-publico-shell">
        <div class="ejecutivas-piloto-superior">
            <a href="<?= BASE_URL ?>index.php?controller=formulario&amp;action=inscripcion" class="ejecutivas-piloto-volver">
                <i class="bi bi-arrow-left"></i> Volver a los cinco programas
            </a>
            <span class="ejecutivas-piloto-chip">
                <i class="bi bi-shield-lock"></i> Vista interna de prueba · No guarda información
            </span>
        </div>

        <section class="formulario-publico-hero">
            <div class="formulario-publico-hero-copy">
                <h1>Formulario de inscripción</h1>
                <p class="formulario-publico-hero-slogan">Titulación – Ejecutivas · Acuerdo 286</p>
                <p class="formulario-publico-hero-required">
                    Completa tu información tal como aparece en tus documentos oficiales.
                    Los campos con <strong>*</strong> son obligatorios.
                </p>
            </div>
            <div class="formulario-publico-hero-logos">
                <div class="formulario-publico-hero-logo formulario-publico-hero-logo-principal">
                    <img src="<?= BASE_URL ?>public/img/brand/Logotipos-blanco.png"
                         alt="Fundación Red Educativa México y Universidad MPE" loading="eager">
                </div>
                <span class="formulario-publico-hero-logo-separator" aria-hidden="true"></span>
                <div class="formulario-publico-hero-logo-sep">
                    <img src="<?= BASE_URL ?>public/img/brand/Logo-Sep-Blanco.png"
                         alt="Secretaría de Educación Pública" loading="eager">
                </div>
                <span class="formulario-publico-hero-logo-separator" aria-hidden="true"></span>
                <div class="formulario-publico-hero-acuerdo">Acuerdo<br>286</div>
                <span class="formulario-publico-hero-logo-separator" aria-hidden="true"></span>
                <div class="formulario-publico-hero-logo formulario-publico-hero-logo-hlc">
                    <img src="<?= BASE_URL ?>public/img/brand/logo-huston.png"
                         alt="HLC Handy Language Center" loading="eager">
                </div>
            </div>
        </section>

        <section class="formulario-publico-card">
            <div class="ejecutivas-piloto-intro">
                <span class="ejecutivas-piloto-icon"><i class="bi bi-patch-check"></i></span>
                <div>
                    <span class="ejecutivas-piloto-eyebrow">TITULACIÓN EJECUTIVAS A286</span>
                    <h2>Datos para tu inscripción</h2>
                    <p>Verifica especialmente tu nombre completo, fecha de nacimiento, CURP y perfil de evaluación.</p>
                </div>
            </div>

            <form
                action="<?= BASE_URL ?>index.php?controller=formulario&amp;action=validarEjecutivas"
                method="post"
                novalidate
                data-ejecutivas-piloto>
                <input type="hidden" name="csrf_piloto" value="<?= $texto($csrfEjecutivas) ?>">
                <input type="hidden" name="programa_slug" value="titulacion-ejecutivas">

                <?php foreach ($secciones as $seccion): ?>
                    <section class="formulario-publico-section">
                        <div class="formulario-publico-section-heading">
                            <span class="formulario-publico-section-icon" aria-hidden="true">
                                <i class="bi <?= $texto($seccion['icono']) ?>"></i>
                            </span>
                            <div>
                                <h2><?= $texto($seccion['titulo']) ?></h2>
                                <p class="ejecutivas-piloto-descripcion"><?= $texto($seccion['descripcion']) ?></p>
                            </div>
                        </div>
                        <div class="formulario-publico-grid">
                            <?php foreach ($seccion['campos'] as $campo): ?>
                                <?php
                                [$nombre,$etiqueta,$tipo,$placeholder,$max,$autocompletar,$obligatorio] = $campo;
                                $id = 'ejecutivas_' . $nombre;
                                ?>
                                <div class="formulario-publico-field<?= in_array($nombre, ['curp','nombre_padres','perfil_evaluacion'], true) ? ' is-full' : '' ?>">
                                    <label for="<?= $texto($id) ?>">
                                        <?= $texto($etiqueta) ?>
                                        <?php if ($obligatorio): ?><strong>*</strong><?php endif; ?>
                                    </label>
                                    <?php if ($tipo === 'select'): ?>
                                        <select id="<?= $texto($id) ?>" name="<?= $texto($nombre) ?>"
                                                data-ejecutivas-field
                                                <?= $obligatorio ? 'required' : '' ?>>
                                            <option value="">Selecciona una opción</option>
                                            <?php if ($nombre === 'genero'): ?>
                                                <option value="H">Hombre (H)</option>
                                                <option value="M">Mujer (M)</option>
                                            <?php elseif ($nombre === 'perfil_evaluacion'): ?>
                                                <?php foreach ($perfilesEjecutivas as $perfil): ?>
                                                    <option value="<?= $texto($perfil) ?>"><?= $texto($perfil) ?></option>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </select>
                                    <?php else: ?>
                                        <input type="<?= $texto($tipo) ?>"
                                               id="<?= $texto($id) ?>"
                                               name="<?= $texto($nombre) ?>"
                                               maxlength="<?= (int)$max ?>"
                                               autocomplete="<?= $texto($autocompletar) ?>"
                                               placeholder="<?= $texto($placeholder) ?>"
                                               <?= $tipo === 'date' ? 'max="' . $texto($hoyMexico) . '"' : '' ?>
                                               <?= $nombre === 'curp' ? 'minlength="18" autocapitalize="characters" spellcheck="false"' : '' ?>
                                               <?= $nombre === 'codigo_postal' ? 'inputmode="numeric" pattern="[0-9]{5}"' : '' ?>
                                               <?= in_array($nombre,['movil','movil_confirmacion'],true) ? 'inputmode="tel" pattern="[0-9]{10}"' : '' ?>
                                               data-ejecutivas-field
                                               <?= $obligatorio ? 'required' : '' ?>>
                                    <?php endif; ?>
                                    <?php if ($nombre === 'curp'): ?>
                                        <small class="formulario-publico-help">
                                            Verifica tu clave y tus datos en
                                            <a href="https://www.gob.mx/curp/" target="_blank" rel="noopener noreferrer">
                                                la consulta oficial de RENAPO
                                            </a>. El CRM no consulta RENAPO automáticamente.
                                        </small>
                                    <?php elseif ($nombre === 'nombre'): ?>
                                        <small class="formulario-publico-help">
                                            Copia tu nombre tal como aparece en la constancia oficial. No se modifican acentos ni apellidos.
                                        </small>
                                    <?php elseif ($nombre === 'perfil_evaluacion'): ?>
                                        <small class="formulario-publico-help">
                                            Diez carreras A286 disponibles para Titulación Ejecutivas.
                                        </small>
                                    <?php endif; ?>
                                    <div class="invalid-feedback" data-ejecutivas-error="<?= $texto($nombre) ?>">
                                        Revisa este dato.
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>

                <section class="formulario-publico-section">
                    <div class="formulario-publico-section-heading">
                        <span class="formulario-publico-section-icon" aria-hidden="true"><i class="bi bi-receipt"></i></span>
                        <div>
                            <h2>Inscripción y pago</h2>
                            <p class="ejecutivas-piloto-descripcion">Esta sección se habilitará al integrar almacenamiento privado y revisión de pagos.</p>
                        </div>
                    </div>
                    <div class="formulario-publico-grid">
                        <div class="formulario-publico-field">
                            <label for="ejecutivas_fecha_inscripcion">Fecha de inscripción <strong>*</strong></label>
                            <input id="ejecutivas_fecha_inscripcion" type="date" name="fecha_inscripcion"
                                   max="<?= $texto($hoyMexico) ?>" required data-ejecutivas-field>
                            <div class="invalid-feedback" data-ejecutivas-error="fecha_inscripcion">Revisa la fecha.</div>
                        </div>
                        <div class="formulario-publico-field">
                            <label for="ejecutivas_comprobante">Comprobante de pago <strong>*</strong></label>
                            <input id="ejecutivas_comprobante" type="file"
                                   accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" disabled>
                            <small class="formulario-publico-help">
                                Pendiente de habilitación. Ningún comprobante se recibe ni almacena en esta vista.
                            </small>
                        </div>
                    </div>
                </section>

                <section class="formulario-publico-section">
                    <div class="formulario-publico-section-heading">
                        <span class="formulario-publico-section-icon" aria-hidden="true"><i class="bi bi-file-earmark-lock"></i></span>
                        <div>
                            <h2>Términos y aviso de privacidad</h2>
                            <p class="ejecutivas-piloto-descripcion">Los textos deberán aprobarse antes de activar la aceptación pública.</p>
                        </div>
                    </div>
                    <div class="ejecutivas-piloto-legal">
                        <div class="ejecutivas-piloto-legal-card">
                            <i class="bi bi-file-earmark-text"></i>
                            <div><strong>Términos y condiciones A286</strong>
                                <small>Borrador recibido; pendiente de revisión de condiciones, domicilios y vigencia.</small>
                            </div>
                        </div>
                        <div class="ejecutivas-piloto-legal-card">
                            <i class="bi bi-shield-lock"></i>
                            <div><strong>Aviso de privacidad</strong>
                                <small>Texto anterior pendiente de cotejo con la versión vigente antes de publicarlo.</small>
                            </div>
                        </div>
                        <label class="ejecutivas-piloto-check">
                            <input type="checkbox" disabled>
                            He leído y acepto los términos y condiciones (pendiente de habilitar).
                        </label>
                        <label class="ejecutivas-piloto-check">
                            <input type="checkbox" disabled>
                            He leído el aviso de privacidad (pendiente de habilitar).
                        </label>
                    </div>
                </section>

                <div class="ejecutivas-piloto-validacion" data-ejecutivas-resultado role="status" aria-live="polite" hidden></div>
                <button class="formulario-publico-submit" type="submit" data-ejecutivas-validar>
                    <i class="bi bi-patch-check"></i>
                    <span>Validar datos de prueba (sin guardar)</span>
                </button>
                <p class="ejecutivas-piloto-pie">
                    Esta revisión no genera una inscripción ni comprueba la identidad con RENAPO.
                    El enlace público <strong>ya-estas-inscrito</strong> todavía no está habilitado.
                </p>
            </form>
        </section>
    </main>
    <script src="<?= BASE_URL ?>public/javascript/formularios_ejecutivas_piloto.js?v=<?= filemtime(ROOT_PATH . '/public/javascript/formularios_ejecutivas_piloto.js') ?>" defer></script>
</body>
</html>