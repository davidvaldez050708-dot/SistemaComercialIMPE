<?php

require_once __DIR__ . '/../models/FormularioRegistroModel.php';

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

    public function inscripcion()
    {
        $this->validarAccesoMarketing();

        $tituloPagina = 'Formulario de Inscripción';
        $subtituloPagina = 'Gestiona la vista destinada a la inscripción.';
        $opcionActiva = 'formularios';

        require_once __DIR__ . '/../views/layout/dashboard_head.php';
        require_once __DIR__ . '/../views/layout/sidebar.php';
        require_once __DIR__ . '/../views/layout/topbar.php';
        require_once __DIR__ . '/../views/formularios/inscripcion.php';
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

        return [
            'nombre' => $limpiar($entrada['nombre'] ?? ''),
            'apellido' => $limpiar($entrada['apellido'] ?? ''),
            'fecha_nacimiento' =>
                trim((string)($entrada['fecha_nacimiento'] ?? '')),
            'movil' => $movil !== null ? $movil : '',
            'movil_confirmacion' =>
                $movilConfirmacion !== null ? $movilConfirmacion : '',
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

        if (
            mb_strlen($datos['lugar_laboras']) < 2 ||
            mb_strlen($datos['lugar_laboras']) > 180
        ) {
            $errores[] =
                'Indica el lugar donde laboras.';
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
