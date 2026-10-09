<?php

require_once __DIR__ . '/../models/FormularioRegistroModel.php';

class FormularioPublicoController
{
    public function registro()
    {
        $modelo = new FormularioRegistroModel();
        $estados = $modelo->obtenerEstadosActivos();
        $perfilesInteres = $this->perfilesInteres();

        /*
         * Vista pública: no requiere sesión y no muestra sidebar,
         * topbar ni controles internos de Marketing.
         */
        $esFormularioPublico = true;
        $registroAction = BASE_URL .
            'index.php?controller=formularioPublico&action=guardarRegistro';
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
        $movilSecundario = preg_replace(
            '/\D+/',
            '',
            (string)($entrada['movil_secundario'] ?? '')
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
