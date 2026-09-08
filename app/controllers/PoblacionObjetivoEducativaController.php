<?php

require_once __DIR__ . '/../models/PoblacionObjetivoEducativaModel.php';
require_once __DIR__ . '/../models/DataTerritorialModel.php';
require_once __DIR__ . '/../services/InegiEducacionService.php';
require_once __DIR__ . '/../services/InegiEducacionObjetivoService.php';
require_once __DIR__ . '/../helpers/PermissionHelper.php';

class PoblacionObjetivoEducativaController
{
    private const MAX_ARCHIVO_BYTES = 16777216;

    public function obtener()
    {
        $this->validarSesion();

        if (!tienePermiso('data_territorial.ver')) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No tienes permiso para consultar información territorial.'
            ], 403);
        }

        $estadoId = (int)($_GET['estado_id'] ?? 0);
        $modeloTerritorial = new DataTerritorialModel();
        $this->validarAccesoEstado($modeloTerritorial, $estadoId);
        $modelo = new PoblacionObjetivoEducativaModel();

        if (!$modelo->tablaDisponible()) {
            $this->responderJson([
                'ok' => true,
                'datos' => [
                    'disponible' => false,
                    'migracion_pendiente' => true,
                    'codigo_indicador' => InegiEducacionService::CODIGO_INDICADOR,
                    'historico' => []
                ]
            ]);
        }

        $this->responderJson([
            'ok' => true,
            'datos' => $modelo->obtenerIndicadorEstado($estadoId)
        ]);
    }

    public function actualizar()
    {
        $this->validarSesion();
        $this->validarPeticionActualizacion();

        $estadoIdPost = trim((string)($_POST['estado_id'] ?? ''));

        if (
            $estadoIdPost === '' ||
            !ctype_digit($estadoIdPost) ||
            (int)$estadoIdPost <= 0
        ) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'El territorio seleccionado no es válido.'
            ], 422);
        }

        $estadoId = (int)$estadoIdPost;
        $modeloTerritorial = new DataTerritorialModel();
        $this->validarAccesoEstado($modeloTerritorial, $estadoId);
        $estado = $modeloTerritorial->obtenerEstado($estadoId);

        if (!$estado) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'El territorio seleccionado no existe o no está activo.'
            ], 404);
        }

        $claveInegi = $this->normalizarClaveEstado($estado['clave_inegi'] ?? '');

        if ($claveInegi === null) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'El territorio no tiene una clave INEGI válida.'
            ], 422);
        }

        $modelo = new PoblacionObjetivoEducativaModel();

        if (!$modelo->tablaDisponible()) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'Falta aplicar la migración de población objetivo educativa en la base de datos.'
            ], 503);
        }

        $archivo = $_FILES['archivo_educacion_objetivo'] ?? null;

        if (!is_array($archivo)) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'Selecciona el archivo XLSX oficial de INEGI/ITER.'
            ], 422);
        }

        $errorCarga = (int)($archivo['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($errorCarga !== UPLOAD_ERR_OK) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => $this->mensajeErrorCarga($errorCarga)
            ], 422);
        }

        $nombreOriginal = trim(basename((string)($archivo['name'] ?? '')));
        $rutaTemporal = (string)($archivo['tmp_name'] ?? '');
        $tamano = (int)($archivo['size'] ?? 0);

        if (
            $nombreOriginal === '' ||
            strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION)) !== 'xlsx'
        ) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'El archivo debe estar en formato XLSX.'
            ], 422);
        }

        if ($tamano <= 0 || $tamano > self::MAX_ARCHIVO_BYTES) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'El archivo XLSX supera el tamaño permitido o está vacío.'
            ], 422);
        }

        if (!is_uploaded_file($rutaTemporal)) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No fue posible validar el archivo cargado.'
            ], 422);
        }

        $servicio = new InegiEducacionService();
        $resultado = $servicio->leerArchivo(
            $rutaTemporal,
            $claveInegi,
            $nombreOriginal
        );

        if (($resultado['ok'] ?? false) !== true) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => $resultado['mensaje'] ??
                    'No fue posible reconocer el indicador educativo oficial de INEGI.'
            ], 422);
        }

        $guardado = $modelo->guardarIndicadorEstado(
            $estadoId,
            $resultado,
            (int)$_SESSION['usuario_id']
        );

        if (!$guardado) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No fue posible guardar la población objetivo educativa.'
            ], 500);
        }

        $this->responderJson([
            'ok' => true,
            'mensaje' =>
                'La población objetivo educativa se importó o actualizó correctamente desde el XLSX oficial de INEGI.',
            'datos' => $modelo->obtenerIndicadorEstado($estadoId)
        ]);
    }

    public function actualizarMasivo()
    {
        $this->validarSesion();
        $this->validarPeticionActualizacion();

        @set_time_limit(0);

        $modelo = new PoblacionObjetivoEducativaModel();

        if (!$modelo->tablaDisponible()) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'Falta aplicar la migración de población objetivo educativa en la base de datos.'
            ], 503);
        }

        $modeloTerritorial = new DataTerritorialModel();
        $estadosBase = $modeloTerritorial->obtenerEstadosActivosParaActualizacionOficial();

        if (empty($estadosBase)) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No hay Estados activos disponibles para realizar la actualización general.'
            ], 422);
        }

        $servicio = new InegiEducacionObjetivoService();
        $preparados = [];
        $errores = [];

        foreach ($estadosBase as $estadoBase) {
            $estadoId = (int)($estadoBase['id'] ?? 0);
            $estado = $modeloTerritorial->obtenerEstado($estadoId);

            if (!$estado) {
                $errores[] = 'No fue posible consultar uno de los Estados activos.';
                continue;
            }

            $clave = $this->normalizarClaveEstado($estado['clave_inegi'] ?? '');
            $nombre = (string)($estado['nombre'] ?? ('Estado ' . $estadoId));

            if ($clave === null) {
                $errores[] = $nombre . ': clave INEGI no válida.';
                continue;
            }

            $resultado = $servicio->obtenerPorEstado($clave);

            if (($resultado['ok'] ?? false) !== true) {
                $errores[] = $nombre . ': ' .
                    ($resultado['mensaje'] ?? 'no fue posible consultar INEGI.');
                continue;
            }

            $metricas = $resultado['estado']['metricas'] ?? [];
            $poblacionBase = $metricas['poblacion_15_mas'] ?? null;
            $secundaria = $metricas['secundaria_completa'] ?? null;
            $porcentaje = $metricas['secundaria_completa_pct'] ?? null;

            if (
                !is_numeric($poblacionBase) ||
                !is_numeric($secundaria) ||
                !is_numeric($porcentaje)
            ) {
                $errores[] = $nombre . ': INEGI no devolvió las métricas educativas esperadas.';
                continue;
            }

            $preparados[] = [
                'estado_id' => $estadoId,
                'nombre_estado' => $nombre,
                'datos' => [
                    'clave_geografica' => $clave,
                    'codigo_indicador' => InegiEducacionService::CODIGO_INDICADOR,
                    'nombre_indicador' =>
                        'Población de 15 años y más cuya máxima escolaridad es secundaria completa',
                    'grupo_edad' => '15 años y más',
                    'anio' => (int)($resultado['periodo'] ?? 2020),
                    'cantidad_personas' => (int)$secundaria,
                    'poblacion_base' => (int)$poblacionBase,
                    'porcentaje' => (float)$porcentaje,
                    'fuente' => (string)($resultado['fuente'] ??
                        'INEGI - Censo de Población y Vivienda 2020 (ITER)'),
                    'archivo_origen' => 'iter_' . $clave . '_cpv2020_csv.zip',
                    'tipo_actualizacion' => 'AUTOMATICA'
                ]
            ];
        }

        if (!empty($errores)) {
            $muestra = array_slice($errores, 0, 4);
            $mensaje = implode(' ', $muestra);

            if (count($errores) > count($muestra)) {
                $mensaje .= ' Hay ' . (count($errores) - count($muestra)) . ' error(es) adicional(es).';
            }

            $this->responderJson([
                'ok' => false,
                'mensaje' =>
                    'No se guardó la actualización porque no fue posible validar todos los Estados. ' .
                    $mensaje
            ], 502);
        }

        $usuarioId = (int)$_SESSION['usuario_id'];
        $guardados = 0;

        foreach ($preparados as $preparado) {
            if (!$modelo->guardarIndicadorEstado(
                (int)$preparado['estado_id'],
                $preparado['datos'],
                $usuarioId
            )) {
                $this->responderJson([
                    'ok' => false,
                    'mensaje' =>
                        'La consulta a INEGI fue correcta, pero no fue posible guardar ' .
                        $preparado['nombre_estado'] . '. Se actualizaron ' . $guardados .
                        ' Estados antes del error.'
                ], 500);
            }

            $guardados++;
        }

        $this->responderJson([
            'ok' => true,
            'mensaje' =>
                'Perfil educativo actualizado automáticamente para ' . $guardados .
                ' Estados desde INEGI. No fue necesario cargar archivos.',
            'datos' => [
                'total_estados' => $guardados,
                'periodos' => [2020],
                'codigo_indicador' => InegiEducacionService::CODIGO_INDICADOR,
                'tipo_actualizacion' => 'AUTOMATICA'
            ]
        ]);
    }

    private function validarPeticionActualizacion(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'Método no permitido.'
            ], 405);
        }

        if (!tienePermiso('data_territorial.actualizar_oficial')) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No tienes permiso para actualizar información oficial.'
            ], 403);
        }
    }

    private function normalizarClaveEstado($valor): ?string
    {
        $valor = trim((string)$valor);

        if ($valor === '' || !preg_match('/^\d+(?:\.0+)?$/', $valor)) {
            return null;
        }

        $clave = str_pad((string)(int)$valor, 2, '0', STR_PAD_LEFT);

        return preg_match('/^(0[1-9]|[12][0-9]|3[0-2])$/', $clave)
            ? $clave
            : null;
    }

    private function mensajeErrorCarga(int $codigo): string
    {
        $mensajes = [
            UPLOAD_ERR_INI_SIZE => 'El archivo supera el tamaño permitido por el servidor.',
            UPLOAD_ERR_FORM_SIZE => 'El archivo supera el tamaño permitido.',
            UPLOAD_ERR_PARTIAL => 'El archivo se cargó de forma incompleta.',
            UPLOAD_ERR_NO_FILE => 'Selecciona el archivo XLSX oficial de INEGI/ITER.',
            UPLOAD_ERR_NO_TMP_DIR => 'El servidor no tiene disponible el directorio temporal.',
            UPLOAD_ERR_CANT_WRITE => 'El servidor no pudo recibir el archivo.',
            UPLOAD_ERR_EXTENSION => 'La carga del archivo fue detenida por el servidor.'
        ];

        return $mensajes[$codigo] ?? 'No fue posible cargar el archivo XLSX.';
    }

    private function validarSesion(): void
    {
        if (!isset($_SESSION['usuario_id'])) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'La sesión no está activa.'
            ], 401);
        }
    }

    private function validarAccesoEstado(
        DataTerritorialModel $modelo,
        int $estadoId
    ): void {
        if ($estadoId <= 0) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'El territorio seleccionado no es válido.'
            ], 422);
        }

        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $rolId = (int)($_SESSION['rol_id'] ?? 0);

        if (!$modelo->puedeAccederEstado($usuarioId, $rolId, $estadoId)) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No tienes acceso a la información de ese territorio.'
            ], 403);
        }
    }

    private function responderJson(array $datos, int $codigo = 200): void
    {
        http_response_code($codigo);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            $datos,
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        exit;
    }
}
