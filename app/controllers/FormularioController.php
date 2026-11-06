<?php

require_once __DIR__ . '/../models/FormularioRegistroModel.php';
require_once __DIR__ . '/../services/FormularioInscripcionProgramas.php';
require_once __DIR__ . '/../services/FormularioEjecutivasPerfiles.php';
require_once __DIR__ . '/../services/FormularioEjecutivasValidacion.php';

class FormularioController
{
    public function index()
    {
        $this->validarAccesoMarketing();

        $tituloPagina = 'Formularios';
        $subtituloPagina = 'Administra los formularios de registro e inscripción.';
        $opcionActiva = 'formularios';

        require_once __DIR__ . '/../views/layout/dashboard_head.php';
        require_once __DIR__ . '/../views/layout/sidebar.php';
        require_once __DIR__ . '/../views/layout/topbar.php';
        require_once __DIR__ . '/../views/formularios/index.php';
        require_once __DIR__ . '/../views/layout/dashboard_footer.php';
    }

    public function registro()
    {
        $this->validarAccesoMarketing();

        $modelo = new FormularioRegistroModel();
        $estados = $modelo->obtenerEstadosActivos();
        $perfilesInteres = $this->perfilesInteres();

        /*
         * El enlace público no depende de una tabla adicional.
         * Se genera cuando el usuario pulsa "Generar link".
         */
        $urlEnlaceRegistro = '';

        $tituloPagina = 'Formulario de Registro';
        $subtituloPagina = 'Gestiona la vista destinada al registro.';
        $opcionActiva = 'formularios';

        require_once __DIR__ . '/../views/layout/dashboard_head.php';
        require_once __DIR__ . '/../views/layout/sidebar.php';
        require_once __DIR__ . '/../views/layout/topbar.php';
        require_once __DIR__ . '/../views/formularios/registro.php';
        require_once __DIR__ . '/../views/layout/dashboard_footer.php';
    }


    public function municipios()
    {
        $this->validarAccesoMarketing();

        $estadoId = (int)($_GET['estado_id'] ?? 0);

        if ($estadoId <= 0) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'Selecciona un estado válido.',
                'municipios' => []
            ], 422);
        }

        try {
            $modelo = new FormularioRegistroModel();
            $municipios = $modelo->obtenerMunicipiosActivos($estadoId);

            $this->responderJson([
                'ok' => true,
                'municipios' => array_map(
                    static function ($municipio) {
                        return [
                            'id' => (int)($municipio['id'] ?? 0),
                            'nombre' => (string)($municipio['nombre'] ?? '')
                        ];
                    },
                    $municipios
                )
            ]);
        } catch (Throwable $error) {
            error_log(
                '[formulario_registro_municipios] ' .
                $error->getMessage()
            );

            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No fue posible cargar los municipios.',
                'municipios' => []
            ], 500);
        }
    }

    public function generarEnlaceRegistro()
    {
        $this->validarAccesoMarketing();

        if (
            strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST'
        ) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'Método no permitido.'
            ], 405);
        }

        /*
         * El mismo formulario de Registro tiene una ruta pública estable.
         * No se requiere guardar tokens ni aplicar una migración adicional.
         */
        $url = BASE_URL .
            'index.php?controller=formularioPublico&action=registro';

        $this->responderJson([
            'ok' => true,
            'mensaje' => 'Enlace del formulario generado correctamente.',
            'url' => $url
        ]);
    }


    public function guardarRegistro()
    {
        $this->validarAccesoMarketing();

        if (
            strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST'
        ) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'Método no permitido.'
            ], 405);
        }

        $datos = $this->normalizarRegistro($_POST);
        $errores = $this->validarRegistro($datos);

        if (!empty($errores)) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => $errores[0],
                'errores' => $errores
            ], 422);
        }

        try {
            $modelo = new FormularioRegistroModel();

            if (
                !$modelo->municipioPerteneceAEstado(
                    (int)$datos['municipio_id'],
                    (int)$datos['estado_id']
                )
            ) {
                $this->responderJson([
                    'ok' => false,
                    'mensaje' =>
                        'El municipio seleccionado no corresponde al estado elegido.'
                ], 422);
            }

            $datos['creado_por'] = (int)($_SESSION['usuario_id'] ?? 0);
            $registroId = $modelo->guardar($datos);

            $this->responderJson([
                'ok' => true,
                'mensaje' => 'Registro guardado correctamente.',
                'registro_id' => $registroId
            ]);
        } catch (Throwable $error) {
            error_log(
                '[formulario_registro_guardar] ' .
                $error->getMessage()
            );

            $this->responderJson([
                'ok' => false,
                'mensaje' =>
                    'No fue posible guardar el registro. Verifica que la migración del formulario esté aplicada.'
            ], 500);
        }
    }

    /**
     * VISTA DE PRUEBA INTERNA: misma imagen, logos y CSS del Registro público.
     * Sin enlace público, envío, subida de recibos ni escrituras en la BD.
     */
    public function previsualizarEjecutivas()
    {
        $this->validarAccesoMarketing();
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
            http_response_code(405);
            echo 'Método no permitido.';
            return;
        }

        header('Cache-Control: private, no-store, max-age=0');
        header('X-Robots-Tag: noindex, nofollow');
        header('Referrer-Policy: same-origin');
        if (empty($_SESSION['csrf_ejecutivas_piloto'])) {
            $_SESSION['csrf_ejecutivas_piloto'] = bin2hex(random_bytes(32));
        }
        $csrfEjecutivas = (string)$_SESSION['csrf_ejecutivas_piloto'];
        $perfilesEjecutivas = FormularioEjecutivasPerfiles::todos();

        require __DIR__ . '/../views/formularios/inscripcion_ejecutivas_previa.php';
    }

    /**
     * Valida datos solamente en sesión Marketing; no guarda ni consulta RENAPO.
     * No se aceptan archivos para evitar capturar comprobantes prematuramente.
     */
    public function validarEjecutivas()
    {
        $this->validarAccesoMarketing();
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            $this->responderJson(['ok'=>false,'mensaje'=>'Método no permitido.'],405);
        }
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Robots-Tag: noindex, nofollow');

        $enviado = trim((string)($_POST['csrf_piloto'] ?? ''));
        $esperado = (string)($_SESSION['csrf_ejecutivas_piloto'] ?? '');
        if ($esperado === '' || $enviado === '' || !hash_equals($esperado, $enviado)) {
            $this->responderJson(['ok'=>false,'mensaje'=>'Validación de seguridad no disponible. Recarga la vista.'],403);
        }
        if (!empty($_FILES)) {
            $this->responderJson(['ok'=>false,'mensaje'=>'El piloto no recibe archivos ni comprobantes.'],422);
        }
        if (($_POST['programa_slug'] ?? '') !== 'titulacion-ejecutivas') {
            $this->responderJson(['ok'=>false,'mensaje'=>'Programa de inscripción no válido.'],422);
        }
        $resultado = FormularioEjecutivasValidacion::validar($_POST);
        $resultado['mensaje'] = $resultado['ok']
            ? 'La captura supera las verificaciones locales; confirma CURP y documentos antes de inscribir.'
            : 'Hay datos por revisar. No se guardó información.';
        $this->responderJson($resultado, $resultado['ok'] ? 200 : 422);
    }

    public function generarEnlaceInscripcion()
    {
        $this->validarAccesoMarketing();

        if (
            strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST'
        ) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'Método no permitido.'
            ], 405);
        }

        $url = BASE_URL .
            'index.php?controller=formularioPublico&action=inscripcion';

        $this->responderJson([
            'ok' => true,
            'mensaje' => 'Enlace del formulario de inscripción generado correctamente.',
            'url' => $url
        ]);
    }

    public function guardarInscripcion()
    {
        $this->validarAccesoMarketing();

        if (
            strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST'
        ) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'Método no permitido.'
            ], 405);
        }

        $datos = $this->normalizarRegistro($_POST);
        $errores = $this->validarRegistro($datos);

        // La selección del programa debe coincidir con el perfil recibido.
        // El formulario heredado sin slug conserva compatibilidad.
        $slugInscripcion = trim((string)($_POST['programa_slug'] ?? ''));
        if ($slugInscripcion !== '') {
            $programa = FormularioInscripcionProgramas::porSlug($slugInscripcion);
            if ($programa === null ||
                $datos['perfil_interes'] !== $programa['nombre']) {
                $errores[] = 'El programa de inscripción seleccionado no es válido.';
            }
        }

        if (!empty($errores)) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => $errores[0],
                'errores' => $errores
            ], 422);
        }

        try {
            $modelo = new FormularioRegistroModel();

            if (
                !$modelo->municipioPerteneceAEstado(
                    (int)$datos['municipio_id'],
                    (int)$datos['estado_id']
                )
            ) {
                $this->responderJson([
                    'ok' => false,
                    'mensaje' =>
                        'El municipio seleccionado no corresponde al estado elegido.'
                ], 422);
            }

            $datos['creado_por'] = (int)($_SESSION['usuario_id'] ?? 0);
            $datos['origen'] = 'MARKETING_INSCRIPCION';
            $inscripcionId = $modelo->guardar($datos);

            $this->responderJson([
                'ok' => true,
                'mensaje' => 'Inscripción guardada correctamente.',
                'registro_id' => $inscripcionId
            ]);
        } catch (Throwable $error) {
            error_log(
                '[formulario_inscripcion_guardar] ' .
                $error->getMessage()
            );

            $this->responderJson([
                'ok' => false,
                'mensaje' =>
                    'No fue posible guardar la inscripción. Intenta nuevamente.'
            ], 500);
        }
    }

    public function inscripcion()
    {
        $this->validarAccesoMarketing();

        $slug = trim((string)($_GET['programa'] ?? ''));
        $programasInscripcion = FormularioInscripcionProgramas::todos();
        $programaInscripcion = $slug !== ''
            ? FormularioInscripcionProgramas::porSlug($slug)
            : null;

        if ($slug !== '' && $programaInscripcion === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Programa de inscripción no disponible.';
            return;
        }

        $tituloPagina = $programaInscripcion
            ? 'Inscripción · ' . $programaInscripcion['nombre']
            : 'Formularios de Inscripción';
        $subtituloPagina = $programaInscripcion
            ? 'Captura interna del programa seleccionado.'
            : 'Elige el programa de inscripción que deseas gestionar.';
        $opcionActiva = 'formularios';

        // El catálogo se muestra sin consultar ni alterar la base de datos.
        // La captura ya existente sigue disponible mediante ?programa=slug.
        if ($programaInscripcion !== null) {
            $modelo = new FormularioRegistroModel();
            $estados = $modelo->obtenerEstadosActivos();
            $perfilesInteres = [$programaInscripcion['nombre']];
            $urlEnlaceInscripcion = '';
        }

        require_once __DIR__ . '/../views/layout/dashboard_head.php';
        require_once __DIR__ . '/../views/layout/sidebar.php';
        require_once __DIR__ . '/../views/layout/topbar.php';

        if ($programaInscripcion !== null) {
            require_once __DIR__ . '/../views/formularios/inscripcion.php';
        } else {
            require_once __DIR__ . '/../views/formularios/inscripcion_programas.php';
        }

        require_once __DIR__ . '/../views/layout/dashboard_footer.php';
    }

    private function perfilesInteres()
    {
        return [
            'Bachillerato en 2 años',
            'Bachillerato 286',
            'Inglés',
            'Titulación - Ejecutivas',
            'Titulación por experiencia laboral',
            'Inscripciones Abiertas'
        ];
    }

    private function normalizarRegistro(array $entrada)
    {
        $limpiar = static function ($valor) {
            $valor = trim((string)$valor);
            $valor = preg_replace('/\s+/u', ' ', $valor);

            return $valor !== null ? $valor : '';
        };

        $movil = preg_replace(
            '/\D+/',
            '',
            (string)($entrada['movil'] ?? '')
        );
        $movilConfirmacion = preg_replace(
            '/\D+/',
            '',
            (string)($entrada['movil_confirmacion'] ?? '')
        );
        $telRecomendado = preg_replace(
            '/\D+/',
            '',
            (string)($entrada['tel_recomendado'] ?? '')
        );

        return [
            'nombre' => $limpiar($entrada['nombre'] ?? ''),
            'apellido' => $limpiar($entrada['apellido'] ?? ''),
            'fecha_nacimiento' =>
                trim((string)($entrada['fecha_nacimiento'] ?? '')),
            'movil' => $movil !== null ? $movil : '',
            'movil_confirmacion' =>
                $movilConfirmacion !== null ? $movilConfirmacion : '',
            'tel_recomendado' =>
                $telRecomendado !== null ? $telRecomendado : '',
            'correo' => strtolower(
                trim((string)($entrada['correo'] ?? ''))
            ),
            'correo_confirmacion' => strtolower(
                trim((string)($entrada['correo_confirmacion'] ?? ''))
            ),
            'perfil_interes' =>
                $limpiar($entrada['perfil_interes'] ?? ''),
            'lugar_laboras' =>
                $limpiar($entrada['lugar_laboras'] ?? ''),
            'cargo_puesto' =>
                $limpiar($entrada['cargo_puesto'] ?? ''),
            'estado_id' => (int)($entrada['estado_id'] ?? 0),
            'municipio_id' => (int)($entrada['municipio_id'] ?? 0)
        ];
    }

    private function validarRegistro(array $datos)
    {
        $errores = [];

        if (
            mb_strlen($datos['nombre']) < 2 ||
            mb_strlen($datos['nombre']) > 80 ||
            !preg_match(
                "/^[\p{L}\p{M}][\p{L}\p{M} .'’-]*$/u",
                $datos['nombre']
            )
        ) {
            $errores[] =
                'Ingresa un nombre válido usando únicamente letras y espacios.';
        }

        if (
            mb_strlen($datos['apellido']) < 2 ||
            mb_strlen($datos['apellido']) > 120 ||
            !preg_match(
                "/^[\p{L}\p{M}][\p{L}\p{M} .'’-]*$/u",
                $datos['apellido']
            )
        ) {
            $errores[] =
                'Ingresa apellidos válidos usando únicamente letras y espacios.';
        }

        $fechaNacimiento = $this->fechaValida(
            $datos['fecha_nacimiento']
        );

        if (!$fechaNacimiento) {
            $errores[] = 'Selecciona una fecha de nacimiento válida.';
        } else {
            $hoy = new DateTimeImmutable('today');
            $fechaMinima = new DateTimeImmutable('1900-01-01');

            if (
                $fechaNacimiento > $hoy ||
                $fechaNacimiento < $fechaMinima
            ) {
                $errores[] =
                    'La fecha de nacimiento debe ser anterior o igual al día de hoy.';
            }
        }

        if (!preg_match('/^\d{10}$/', $datos['movil'])) {
            $errores[] =
                'El número móvil debe contener exactamente 10 dígitos.';
        }

        if ($datos['movil'] !== $datos['movil_confirmacion']) {
            $errores[] =
                'La confirmación del número móvil no coincide.';
        }

        if (
            $datos['tel_recomendado'] !== '' &&
            !preg_match('/^\d{10}$/', $datos['tel_recomendado'])
        ) {
            $errores[] =
                'El teléfono recomendado debe contener exactamente 10 dígitos.';
        }

        if (!filter_var($datos['correo'], FILTER_VALIDATE_EMAIL)) {
            $errores[] = 'Ingresa un correo electrónico válido.';
        }

        if ($datos['correo'] !== $datos['correo_confirmacion']) {
            $errores[] =
                'La confirmación del correo electrónico no coincide.';
        }

        if (
            !in_array(
                $datos['perfil_interes'],
                $this->perfilesInteres(),
                true
            )
        ) {
            $errores[] = 'Selecciona un perfil de interés válido.';
        }

        if (mb_strlen($datos['lugar_laboras']) > 180) {
            $errores[] =
                'El lugar donde laboras no puede superar 180 caracteres.';
        }

        if (mb_strlen($datos['cargo_puesto']) > 160) {
            $errores[] =
                'El cargo o puesto no puede superar 160 caracteres.';
        }

        if ((int)$datos['estado_id'] <= 0) {
            $errores[] = 'Selecciona un estado.';
        }

        if ((int)$datos['municipio_id'] <= 0) {
            $errores[] = 'Selecciona un municipio.';
        }

        return $errores;
    }

    private function fechaValida($valor)
    {
        $valor = trim((string)$valor);

        if ($valor === '') {
            return null;
        }

        $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);

        if (!$fecha || $fecha->format('Y-m-d') !== $valor) {
            return null;
        }

        return $fecha;
    }

    private function responderJson($datos, $codigoHttp = 200)
    {
        http_response_code((int)$codigoHttp);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode(
            $datos,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_INVALID_UTF8_SUBSTITUTE
        );

        exit;
    }

    private function validarAccesoMarketing()
    {
        if (!isset($_SESSION['usuario_id'])) {
            header(
                'Location: ' .
                BASE_URL .
                'index.php?controller=login&action=mostrarLogin'
            );
            exit;
        }

        $rol = trim((string)($_SESSION['rol'] ?? ''));

        if (strcasecmp($rol, 'Marketing') !== 0) {
            http_response_code(403);
            die('No tienes permiso para acceder al módulo de Formularios.');
        }
    }
}
