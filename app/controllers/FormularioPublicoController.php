<?php

require_once __DIR__ . '/../models/FormularioRegistroModel.php';
require_once __DIR__ . '/../helpers/HorarioContacto.php';

class FormularioPublicoController
{
    public function registro()
    {
        $modelo = new FormularioRegistroModel();
        $estados = $modelo->obtenerEstadosActivos();
        $aliadoFormulario = $this->contextoAliado($modelo, 'registro');
        if (!empty($aliadoFormulario['registro_gestionado'])) { $_SESSION['registro_publico_csrf'] = $_SESSION['registro_publico_csrf'] ?? bin2hex(random_bytes(32)); }
        $perfilesInteres = $this->perfilesInteres($aliadoFormulario);

        /*
         * Vista pública: no requiere sesión y no muestra sidebar,
         * topbar ni controles internos de Marketing.
         */
        $esFormularioPublico = true;
        $registroAction = BASE_URL .
            'index.php?controller=formularioPublico&action=guardarRegistro' .
            ($aliadoFormulario ? '&aliado=' . rawurlencode($_GET['aliado']) : '');
        $municipiosUrl = BASE_URL .
            'index.php?controller=formularioPublico&action=municipios';

        require_once __DIR__ .
            '/../views/formularios/publico_registro.php';
    }

    public function gracias()
    {
        require_once __DIR__ .
            '/../views/formularios/publico_gracias.php';
    }

    public function inscripcion()
    {
        $modelo = new FormularioRegistroModel();
        $estados = $modelo->obtenerEstadosActivos();
        $aliadoFormulario = $this->contextoAliado($modelo);
        $perfilesInteres = $this->perfilesInteres($aliadoFormulario);

        $inscripcionAction = BASE_URL .
            'index.php?controller=formularioPublico&action=guardarInscripcion' .
            ($aliadoFormulario ? '&aliado=' . rawurlencode($_GET['aliado']) : '');
        $municipiosUrl = BASE_URL .
            'index.php?controller=formularioPublico&action=municipios';

        require_once __DIR__ .
            '/../views/formularios/publico_inscripcion.php';
    }

    public function graciasInscripcion()
    {
        require_once __DIR__ .
            '/../views/formularios/publico_gracias_inscripcion.php';
    }

    public function municipios()
    {
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
            $municipios = $modelo->obtenerMunicipiosActivos(
                $estadoId
            );

            $this->responderJson([
                'ok' => true,
                'municipios' => array_map(
                    static function ($municipio) {
                        return [
                            'id' => (int)($municipio['id'] ?? 0),
                            'nombre' => (string)(
                                $municipio['nombre'] ?? ''
                            )
                        ];
                    },
                    $municipios
                )
            ]);
        } catch (Throwable $error) {
            error_log(
                '[formulario_publico_municipios] ' .
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
        if (
            strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !==
            'POST'
        ) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'Método no permitido.'
            ], 405);
        }

        $aliadoFormulario = $this->contextoAliado(null, 'registro');
        if (!empty($aliadoFormulario['registro_gestionado'])) {
            $csrf = (string)($_SESSION['registro_publico_csrf'] ?? '');
            if (!$csrf || !hash_equals($csrf, (string)($_POST['registro_csrf'] ?? '')) || !empty($_POST['sitio_web'])) {
                $this->responderJson(['ok'=>false,'mensaje'=>'Recarga el formulario y vuelve a enviarlo.'], 419);
            }
            $envios = array_filter($_SESSION['registro_envios'] ?? [], fn($t) => $t > time()-600);
            if (count($envios)>=20) { $this->responderJson(['ok'=>false,'mensaje'=>'Espera unos minutos antes de volver a enviar.'],429); }
            $envios[]=time(); $_SESSION['registro_envios']=$envios;
        }
        $datos = $this->normalizarRegistro($_POST);
        try {
            $datos['horario_contacto'] = HorarioContacto::desdeEntrada($_POST);
        } catch (DomainException $error) {
            $this->responderJson(['ok'=>false, 'mensaje'=>$error->getMessage()], 422);
        }
        if ($aliadoFormulario) { $datos['aliado_token'] = $_GET['aliado']; }
        $errores = $this->validarRegistro($datos, $aliadoFormulario);

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

            $datos['creado_por'] = null;
            $datos['origen'] = 'PUBLICO';
            $registroId = $modelo->guardar($datos);

            $this->responderJson([
                'ok' => true,
                'mensaje' =>
                    'Tu registro fue enviado correctamente.',
                'registro_id' => $registroId,
                'redirect_url' => BASE_URL .
                    'index.php?controller=formularioPublico&action=gracias'
            ]);
        } catch (DomainException $error) {
            $this->responderJson(['ok' => false, 'mensaje' => $error->getMessage()], 422);
        } catch (Throwable $error) {
            $mensajeTecnico = (string)$error->getMessage();

            error_log(
                '[formulario_publico_guardar] ' .
                $mensajeTecnico
            );

            $requiereMigracion =
                stripos($mensajeTecnico, 'migración') !== false ||
                stripos($mensajeTecnico, 'movil_secundario') !== false ||
                stripos($mensajeTecnico, 'unknown column') !== false;

            $this->responderJson([
                'ok' => false,
                'mensaje' => $requiereMigracion
                    ? 'La base de datos necesita la actualización del segundo número de contacto antes de poder guardar registros.'
                    : 'No fue posible enviar el registro. Intenta nuevamente.'
            ], 500);
        }
    }

    public function guardarInscripcion()
    {
        if (
            strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !==
            'POST'
        ) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'Método no permitido.'
            ], 405);
        }

        $aliadoFormulario = $this->contextoAliado();
        $datos = $this->normalizarRegistro($_POST);
        if ($aliadoFormulario) { $datos['aliado_token'] = $_GET['aliado']; }
        $errores = $this->validarRegistro($datos, $aliadoFormulario);

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

            $datos['creado_por'] = null;
            $datos['origen'] = 'PUBLICO_INSCRIPCION';
            $inscripcionId = $modelo->guardar($datos);

            $this->responderJson([
                'ok' => true,
                'mensaje' => 'Tu inscripción fue enviada correctamente.',
                'registro_id' => $inscripcionId,
                'redirect_url' => BASE_URL .
                    'index.php?controller=formularioPublico&action=graciasInscripcion'
            ]);
        } catch (DomainException $error) {
            $this->responderJson(['ok' => false, 'mensaje' => $error->getMessage()], 422);
        } catch (Throwable $error) {
            $mensajeTecnico = (string)$error->getMessage();

            error_log(
                '[formulario_publico_inscripcion_guardar] ' .
                $mensajeTecnico
            );

            $requiereMigracion =
                stripos($mensajeTecnico, 'migración') !== false ||
                stripos($mensajeTecnico, 'movil_secundario') !== false ||
                stripos($mensajeTecnico, 'unknown column') !== false;

            $this->responderJson([
                'ok' => false,
                'mensaje' => $requiereMigracion
                    ? 'La base de datos necesita la actualización del segundo número de contacto antes de poder guardar inscripciones.'
                    : 'No fue posible enviar la inscripción. Intenta nuevamente.'
            ], 500);
        }
    }

    private function contextoAliado($modelo = null, string $tipo = "inscripcion")
    {
        if (!array_key_exists('aliado', $_GET)) { return null; }
        try {
            $modelo = $modelo ?? new FormularioRegistroModel();
            $aliado = $modelo->aliadoExclusivo($_GET['aliado'], $tipo);
            if (!$aliado['programas']) { throw new DomainException('Sin programas autorizados.'); }
            header('Cache-Control: no-store');
            header('Referrer-Policy: same-origin');
            return $aliado;
        } catch (Throwable $error) {
            if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
                $this->responderJson(['ok' => false, 'mensaje' => 'El enlace del aliado no está disponible.'], 422);
            }
            http_response_code(404);
            require __DIR__ . '/../views/formularios/publico_no_disponible.php';
            exit;
        }
    }

    private function perfilesInteres($aliado = null)
    {
        if ($aliado !== null) { return array_column($aliado['programas'], 'nombre'); }
        return [
            'Bachillerato en 2 años',
            'Bachillerato General',
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
        $movilSecundario = preg_replace(
            '/\D+/',
            '',
            (string)($entrada['movil_secundario'] ?? '')
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
            'movil_secundario' =>
                $movilSecundario !== null ? $movilSecundario : '',
            'tel_recomendado' =>
                $telRecomendado !== null ? $telRecomendado : '',
            'correo' => strtolower(
                trim((string)($entrada['correo'] ?? ''))
            ),
            'correo_confirmacion' => strtolower(
                trim((string)($entrada['correo_confirmacion'] ?? ''))
            ),
            'perfil_interes' =>
                ProgramaEducativo::nombre($limpiar($entrada['perfil_interes'] ?? '')),
            'lugar_laboras' =>
                $limpiar($entrada['lugar_laboras'] ?? ''),
            'cargo_puesto' =>
                $limpiar($entrada['cargo_puesto'] ?? ''),
            'estado_id' => (int)($entrada['estado_id'] ?? 0),
            'municipio_id' => (int)($entrada['municipio_id'] ?? 0)
        ];
    }

    private function validarRegistro(array $datos, $aliado = null)
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
            $errores[] = 'Ingresa un nombre válido.';
        }

        if (
            mb_strlen($datos['apellido']) < 2 ||
            mb_strlen($datos['apellido']) > 120 ||
            !preg_match(
                "/^[\p{L}\p{M}][\p{L}\p{M} .'’-]*$/u",
                $datos['apellido']
            )
        ) {
            $errores[] = 'Ingresa apellidos válidos.';
        }

        $fechaNacimiento = $this->fechaValida(
            $datos['fecha_nacimiento']
        );

        if (!$fechaNacimiento) {
            $errores[] = 'Selecciona una fecha de nacimiento válida.';
        } else {
            $hoy = new DateTimeImmutable('today');
            $minima = new DateTimeImmutable('1900-01-01');

            if (
                $fechaNacimiento > $hoy ||
                $fechaNacimiento < $minima
            ) {
                $errores[] =
                    'La fecha de nacimiento seleccionada no es válida.';
            }
        }

        if (!preg_match('/^\d{10}$/', $datos['movil'])) {
            $errores[] =
                'El número móvil debe contener exactamente 10 dígitos.';
        }

        if (
            !preg_match('/^\d{10}$/', $datos['movil_confirmacion']) ||
            $datos['movil'] !== $datos['movil_confirmacion']
        ) {
            $errores[] =
                'La confirmación del número móvil no coincide.';
        }

        if (
            $datos['movil_secundario'] !== '' &&
            !preg_match('/^\d{10}$/', $datos['movil_secundario'])
        ) {
            $errores[] =
                'El segundo número de contacto debe contener exactamente 10 dígitos.';
        }

        if (
            $datos['movil_secundario'] !== '' &&
            $datos['movil_secundario'] === $datos['movil']
        ) {
            $errores[] =
                'El segundo número de contacto debe ser diferente al móvil principal.';
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

        if (
            !filter_var(
                $datos['correo_confirmacion'],
                FILTER_VALIDATE_EMAIL
            ) ||
            $datos['correo'] !== $datos['correo_confirmacion']
        ) {
            $errores[] =
                'La confirmación del correo electrónico no coincide.';
        }

        /*
         * El Perfil de interés es obligatorio para el usuario externo.
         * Los demás campos de Información laboral continúan opcionales.
         */
        if (
            $datos['perfil_interes'] === '' ||
            !in_array(
                $datos['perfil_interes'],
                $this->perfilesInteres($aliado),
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

        $fecha = DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $valor
        );

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
}
