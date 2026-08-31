<?php

require_once __DIR__ . '/../models/DataTerritorialModel.php';
require_once __DIR__ . '/../models/RolModel.php';
require_once __DIR__ . '/../services/InegiService.php';
require_once __DIR__ . '/../services/DenueService.php';
require_once __DIR__ . '/../services/PobrezaLaboralImportService.php';
require_once __DIR__ . '/../services/RezagoEducativoImportService.php';
require_once __DIR__ . '/../services/InegiGeoService.php';
require_once __DIR__ . '/../helpers/PermissionHelper.php';

class DataTerritorialController
{
    public function __construct()
    {
        $modeloRol = new RolModel();

        if (isset($_SESSION['usuario_id'])) {
            $_SESSION['permisos'] =
                $modeloRol->obtenerCodigosPermisosPorRol(
                    (int)($_SESSION['rol_id'] ?? 0)
                );
        }
    }

    public function index()
    {
        $this->validarPermiso('data_territorial.ver');

        $modelo = new DataTerritorialModel();
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $rolId = (int)($_SESSION['rol_id'] ?? 0);
        $buscarTerritorio = trim($_GET['buscar'] ?? '');
        $filtroInformacion = $this->normalizarFiltroInformacion(
            $_GET['filtro_informacion'] ?? 'todos'
        );
        $paginaTerritorios = max(1, (int)($_GET['pagina_territorios'] ?? 1));
        $limiteTerritorios = 12;
        $estadoId = (int)($_GET['estado_id'] ?? 0);

        $territoriosDisponibles = $modelo->obtenerTerritoriosUsuario(
            $usuarioId,
            $rolId,
            $buscarTerritorio
        );
        $territoriosActualizacionMasiva = [];

        if (tienePermiso('data_territorial.actualizar_oficial')) {
            $territoriosActualizacionMasiva =
                $modelo->obtenerEstadosActivosParaActualizacionOficial();
        }

        $territorios = $this->filtrarTerritoriosPorInformacion(
            $territoriosDisponibles,
            $filtroInformacion
        );
        $totalTerritoriosDisponibles = count($territoriosDisponibles);
        $totalTerritorios = count($territorios);
        $totalPaginasTerritorios = max(
            1,
            (int)ceil($totalTerritorios / $limiteTerritorios)
        );
        $paginaTerritorios = min($paginaTerritorios, $totalPaginasTerritorios);
        $inicioTerritorios = ($paginaTerritorios - 1) * $limiteTerritorios;
        $territoriosTarjetas = array_slice(
            $territorios,
            $inicioTerritorios,
            $limiteTerritorios
        );

        $estadoSeleccionado = null;
        $secretarias = [];
        $indicadores = [];
        $municipios = [];
        $actividadEconomicaOficial = [
            'total_establecimientos' => 0,
            'sectores' => []
        ];
        $comparacionEconomicaNacional = [
            'disponible' => false,
            'sectores' => []
        ];
        $poderAdquisitivoOficial = [
            'disponible' => false,
            'referencia_nacional' => null
        ];
        $rezagoEducativoOficial = [
            'disponible' => false,
            'referencia_nacional' => null,
            'historico' => []
        ];
        $totalMunicipios = 0;
        $municipiosCargados = 0;
        $fuentes = [];
        $paginaMunicipios = max(1, (int)($_GET['pagina_municipios'] ?? 1));
        $limiteMunicipios = $this->obtenerLimiteMunicipios();
        $buscarMunicipio = trim($_GET['buscar_municipio'] ?? '');

        if ($estadoId > 0) {
            if ($modelo->puedeAccederEstado($usuarioId, $rolId, $estadoId)) {
                $estadoSeleccionado = $modelo->obtenerEstado($estadoId);
            } else {
                $_SESSION['error_data_territorial'] =
                    'No tienes acceso a la información de ese territorio.';
                $this->redirigirAData();
            }
        }

        if ($estadoSeleccionado) {
            $secretarias = $modelo->obtenerSecretarias($estadoId);
            $indicadores = $modelo->obtenerIndicadoresEducativos($estadoId);
            $actividadEconomicaOficial =
                $modelo->obtenerActividadEconomicaEstado($estadoId);
            $comparacionEconomicaNacional =
                $modelo->obtenerComparacionEconomicaNacional($estadoId);
            $poderAdquisitivoOficial =
                $modelo->obtenerPoderAdquisitivoEstado($estadoId);
            $rezagoEducativoOficial =
                $modelo->obtenerRezagoEducativoOficialEstado($estadoId);
            $municipios = $modelo->obtenerMunicipios(
                $estadoId,
                ['buscar' => $buscarMunicipio],
                $paginaMunicipios,
                $limiteMunicipios
            );
            $totalMunicipios = $modelo->contarMunicipiosFiltrados(
                $estadoId,
                ['buscar' => $buscarMunicipio]
            );
            $municipiosCargados = $modelo->contarMunicipiosActivos($estadoId);
            $fuentes = $modelo->obtenerFuentesPorEstado($estadoId);
        }

        $mensajeExito = $_SESSION['mensaje_data_territorial'] ?? '';
        $mensajeError = $_SESSION['error_data_territorial'] ?? '';

        unset(
            $_SESSION['mensaje_data_territorial'],
            $_SESSION['error_data_territorial']
        );

        $tituloPagina = 'Información territorial';
        $subtituloPagina = $this->obtenerSubtituloPagina($rolId);
        $opcionActiva = 'data_territorial';

        require_once __DIR__ . '/../views/layout/dashboard_head.php';
        require_once __DIR__ . '/../views/layout/sidebar.php';
        require_once __DIR__ . '/../views/layout/topbar.php';
        require_once __DIR__ . '/../views/data_territorial/index.php';
        require_once __DIR__ . '/../views/layout/dashboard_footer.php';
    }

    public function municipiosTabla()
    {
        $this->validarPermiso('data_territorial.ver');

        $modelo = new DataTerritorialModel();
        $estadoId = (int)($_GET['estado_id'] ?? 0);
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $rolId = (int)($_SESSION['rol_id'] ?? 0);

        if (!$modelo->puedeAccederEstado($usuarioId, $rolId, $estadoId)) {
            http_response_code(403);
            echo 'No tienes acceso a este territorio.';
            return;
        }

        $buscarMunicipio = trim($_GET['buscar_municipio'] ?? '');
        $paginaMunicipios = max(1, (int)($_GET['pagina_municipios'] ?? 1));
        $limiteMunicipios = $this->obtenerLimiteMunicipios();
        $municipios = $modelo->obtenerMunicipios(
            $estadoId,
            ['buscar' => $buscarMunicipio],
            $paginaMunicipios,
            $limiteMunicipios
        );
        $totalMunicipios = $modelo->contarMunicipiosFiltrados(
            $estadoId,
            ['buscar' => $buscarMunicipio]
        );
        $estadoSeleccionado = $modelo->obtenerEstado($estadoId);

        require_once __DIR__ . '/../views/data_territorial/municipios_tabla.php';
    }

    public function actualizarFichaGeneral()
    {
        $this->validarPermisoEdicionGeneral();
        $this->validarMetodoPost();

        $modelo = new DataTerritorialModel();
        $estadoId = (int)($_POST['estado_id'] ?? 0);
        $this->validarAccesoEstado($modelo, $estadoId);
        $estadoActual = $modelo->obtenerEstado($estadoId);

        if (!$estadoActual) {
            $this->volverConError($estadoId, 'El territorio seleccionado no existe.');
        }

        $errores = [];
        $datos = $this->limpiarFichaGeneral($_POST, $estadoActual, $errores);
        $fotoTitular = $this->procesarImagen(
            'foto_titular',
            $estadoActual['foto_titular'] ?? '',
            'public/uploads/territorios/titulares'
        );
        $mapaEstado = $this->procesarImagen(
            'mapa_estado',
            $estadoActual['mapa_estado'] ?? '',
            'public/uploads/territorios/mapas'
        );

        foreach ([$fotoTitular, $mapaEstado] as $archivo) {
            if ($archivo['error'] !== '') {
                $errores[] = $archivo['error'];
            }
        }

        if (!empty($errores)) {
            $this->eliminarArchivoSiNuevo($fotoTitular);
            $this->eliminarArchivoSiNuevo($mapaEstado);
            $this->volverConError($estadoId, implode(' ', $errores));
        }

        $datos['foto_titular'] = $fotoTitular['ruta'];
        $datos['mapa_estado'] = $mapaEstado['ruta'];
        $datos['fecha_actualizacion'] = $this->fechaActualizacionSistema();

        if ($modelo->actualizarFichaGeneral($estadoId, $datos)) {
            $this->eliminarArchivoEstadoAnterior(
                $modelo,
                'foto_titular',
                $estadoActual['foto_titular'] ?? '',
                $datos['foto_titular'],
                'public/uploads/territorios/titulares'
            );
            $this->eliminarArchivoEstadoAnterior(
                $modelo,
                'mapa_estado',
                $estadoActual['mapa_estado'] ?? '',
                $datos['mapa_estado'],
                'public/uploads/territorios/mapas'
            );
            $_SESSION['mensaje_data_territorial'] =
                'Ficha territorial actualizada correctamente.';
        } else {
            $this->eliminarArchivoSiNuevo($fotoTitular);
            $this->eliminarArchivoSiNuevo($mapaEstado);
            $_SESSION['error_data_territorial'] =
                'No fue posible actualizar la ficha territorial.';
        }

        $this->redirigirAData($estadoId);
    }

    public function actualizarEconomia()
    {
        $this->validarPermiso('data_territorial.editar');
        $this->validarMetodoPost();

        $modelo = new DataTerritorialModel();
        $estadoId = (int)($_POST['estado_id'] ?? 0);
        $this->validarAccesoEstado($modelo, $estadoId);
        $datos = [
            'actividad_economica' => trim($_POST['actividad_economica'] ?? ''),
            'poder_adquisitivo' => trim($_POST['poder_adquisitivo'] ?? ''),
            'fecha_actualizacion' => $this->fechaActualizacionSistema()
        ];

        if ($modelo->actualizarEconomia($estadoId, $datos)) {
            $_SESSION['mensaje_data_territorial'] =
                'Información económica actualizada correctamente.';
        } else {
            $_SESSION['error_data_territorial'] =
                'No fue posible actualizar la información económica.';
        }

        $this->redirigirAData($estadoId, '#economia');
    }

    public function actualizarPoblacionOficial()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'Método no permitido.'
            ], 405);
        }

        if (!isset($_SESSION['usuario_id'])) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'La sesión no está activa.'
            ], 401);
        }

        if (!tienePermiso('data_territorial.actualizar_oficial')) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No tienes permiso para actualizar información oficial.'
            ], 403);
        }

        $estadoIdPost = trim((string)($_POST['estado_id'] ?? ''));

        if ($estadoIdPost === '' || !ctype_digit($estadoIdPost) || (int)$estadoIdPost <= 0) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'El territorio seleccionado no es válido.'
            ], 422);
        }

        $modelo = new DataTerritorialModel();
        $estadoId = (int)$estadoIdPost;
        $estado = $modelo->obtenerEstado($estadoId);

        if (!$estado) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'El territorio seleccionado no existe o no está activo.'
            ], 404);
        }

        $claveInegi = trim((string)($estado['clave_inegi'] ?? ''));

        if ($claveInegi === '' || !ctype_digit($claveInegi)) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No se encontró una clave INEGI válida para el territorio.'
            ], 422);
        }

        $inegi = new InegiService();
        $resultado = $inegi->obtenerPoblacionEstado($claveInegi);

        if (($resultado['ok'] ?? false) !== true) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => $resultado['mensaje'] ?? 'No fue posible obtener la información oficial de INEGI.'
            ], 502);
        }

        if (
            !array_key_exists('valor', $resultado) ||
            !array_key_exists('periodo', $resultado) ||
            !array_key_exists('area_geografica', $resultado)
        ) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'La información oficial de INEGI está incompleta.'
            ], 502);
        }

        if ((string)$resultado['area_geografica'] !== $claveInegi) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'La información de INEGI no corresponde al territorio seleccionado.'
            ], 502);
        }

        $valor = $resultado['valor'];
        $periodo = trim((string)$resultado['periodo']);

        if (is_int($valor)) {
            $poblacion = $valor;
        } elseif (is_string($valor) && ctype_digit($valor)) {
            $poblacion = (int)$valor;
        } else {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'La población oficial recibida no es válida.'
            ], 502);
        }

        if ($poblacion <= 0 || $periodo === '') {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'La información oficial de INEGI está incompleta.'
            ], 502);
        }

        try {
            $actualizado = $modelo->actualizarPoblacionOficial(
                $estadoId,
                $poblacion,
                $periodo
            );
        } catch (Throwable $error) {
            error_log($error->getMessage());
            $actualizado = false;
        }

        if (!$actualizado) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No fue posible guardar la información oficial.'
            ], 500);
        }

        $this->responderJson([
            'ok' => true,
            'mensaje' => 'La población oficial se actualizó correctamente.',
            'datos' => [
                'estado_id' => $estadoId,
                'estado' => $estado['nombre'],
                'poblacion' => $poblacion,
                'periodo' => $periodo,
                'fuente' => 'INEGI - Banco de Indicadores'
            ]
        ]);
    }

    public function actualizarActividadEconomicaOficial()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'Método no permitido.'
            ], 405);
        }

        if (!isset($_SESSION['usuario_id'])) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'La sesión no está activa.'
            ], 401);
        }

        if (!tienePermiso('data_territorial.actualizar_oficial')) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No tienes permiso para actualizar información oficial.'
            ], 403);
        }

        $estadoIdPost = trim((string)($_POST['estado_id'] ?? ''));

        if ($estadoIdPost === '' || !ctype_digit($estadoIdPost) || (int)$estadoIdPost <= 0) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'El territorio seleccionado no es válido.'
            ], 422);
        }

        $modelo = new DataTerritorialModel();
        $estadoId = (int)$estadoIdPost;
        $estado = $modelo->obtenerEstado($estadoId);

        if (!$estado) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'El territorio seleccionado no existe o no está activo.'
            ], 404);
        }

        $claveInegi = trim((string)($estado['clave_inegi'] ?? ''));

        if ($claveInegi === '' || !ctype_digit($claveInegi) || strlen($claveInegi) !== 2) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'El territorio no tiene una clave INEGI válida.'
            ], 422);
        }

        $denue = new DenueService();
        $resultado = $denue->obtenerSectoresEstado($claveInegi);

        if (($resultado['ok'] ?? false) !== true) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => $resultado['mensaje'] ?? 'No fue posible obtener la información oficial de DENUE.'
            ], 502);
        }

        if (
            !array_key_exists('area_geografica', $resultado) ||
            !array_key_exists('total_establecimientos', $resultado) ||
            !array_key_exists('sectores', $resultado)
        ) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'La información oficial de DENUE está incompleta.'
            ], 502);
        }

        if ((string)$resultado['area_geografica'] !== $claveInegi) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'La información recibida no corresponde al territorio solicitado.'
            ], 502);
        }

        $totalRecibido = $resultado['total_establecimientos'];

        if (is_int($totalRecibido)) {
            $totalEstablecimientos = $totalRecibido;
        } elseif (is_string($totalRecibido) && ctype_digit($totalRecibido)) {
            $totalEstablecimientos = (int)$totalRecibido;
        } else {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'El total de establecimientos recibido no es válido.'
            ], 502);
        }

        if ($totalEstablecimientos <= 0) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'El total de establecimientos recibido no es válido.'
            ], 502);
        }

        $sectores = $resultado['sectores'];

        if (!is_array($sectores) || empty($sectores)) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'La información oficial de DENUE está incompleta.'
            ], 502);
        }

        try {
            $actualizado = $modelo->actualizarActividadEconomicaOficial(
                $estadoId,
                $totalEstablecimientos,
                $sectores
            );
        } catch (Throwable $error) {
            error_log($error->getMessage());
            $actualizado = false;
        }

        if (!$actualizado) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No fue posible guardar la actividad económica oficial.'
            ], 500);
        }

        $this->responderJson([
            'ok' => true,
            'mensaje' => 'La actividad económica oficial se actualizó correctamente.',
            'datos' => [
                'estado_id' => $estadoId,
                'estado' => $estado['nombre'],
                'total_establecimientos' => $totalEstablecimientos,
                'sectores_registrados' => count($sectores),
                'fuente' => 'INEGI - DENUE'
            ]
        ]);
    }

    public function actualizarMunicipiosOficiales()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'Método no permitido.'
            ], 405);
        }

        $this->validarPermisoActualizacionOficialJson();
        $estadoIdPost = trim((string)($_POST['estado_id'] ?? ''));

        if ($estadoIdPost === '' || !ctype_digit($estadoIdPost) || (int)$estadoIdPost <= 0) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'El territorio seleccionado no es válido.'
            ], 422);
        }

        $modelo = new DataTerritorialModel();
        $estadoId = (int)$estadoIdPost;
        $estado = $modelo->obtenerEstado($estadoId);

        if (!$estado) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'El territorio seleccionado no existe o no está activo.'
            ], 404);
        }

        $claveInegi = str_pad(trim((string)($estado['clave_inegi'] ?? '')), 2, '0', STR_PAD_LEFT);

        if (!preg_match('/^\d{2}$/', $claveInegi)) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'El territorio no tiene una clave INEGI válida.'
            ], 422);
        }

        $servicio = new InegiGeoService();
        $resultado = $servicio->obtenerMunicipiosEstado($claveInegi);

        if (($resultado['ok'] ?? false) !== true) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => $resultado['mensaje'] ?? 'No fue posible obtener el catálogo municipal de INEGI.'
            ], 502);
        }

        $municipios = $resultado['municipios'] ?? [];
        $totalMunicipios = (int)($resultado['total_municipios'] ?? 0);
        $claveRespuesta = str_pad((string)($resultado['clave_estado'] ?? ''), 2, '0', STR_PAD_LEFT);

        if (
            $claveRespuesta !== $claveInegi ||
            !is_array($municipios) ||
            $totalMunicipios <= 0 ||
            count($municipios) !== $totalMunicipios
        ) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'La información municipal recibida de INEGI está incompleta.'
            ], 502);
        }

        try {
            $guardado = $modelo->actualizarMunicipiosOficiales(
                $estadoId,
                $municipios
            );
        } catch (Throwable $error) {
            error_log($error->getMessage());
            $guardado = [
                'ok' => false,
                'procesados' => 0,
                'mensaje' => 'No fue posible guardar la información municipal.'
            ];
        }

        if (($guardado['ok'] ?? false) !== true) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => $guardado['mensaje'] ?? 'No fue posible guardar la información municipal.'
            ], 500);
        }

        $this->responderJson([
            'ok' => true,
            'mensaje' => 'Los municipios oficiales se actualizaron correctamente.',
            'datos' => [
                'estado_id' => $estadoId,
                'estado' => (string)($estado['nombre'] ?? ''),
                'municipios_procesados' => (int)($guardado['procesados'] ?? 0),
                'total_municipios' => $totalMunicipios,
                'fuente' => 'INEGI - Catálogo Único de Claves Geoestadísticas'
            ]
        ]);
    }

    public function previsualizarPoderAdquisitivo()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'Método no permitido.'
            ], 405);
        }

        $this->validarPermisoActualizacionOficialJson();
        $archivo = $_FILES['archivo_poder_adquisitivo'] ?? null;

        if (!is_array($archivo)) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'Selecciona el archivo XLSX oficial de Pobreza Laboral.'
            ], 422);
        }

        $errorCarga = (int)($archivo['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($errorCarga !== UPLOAD_ERR_OK) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => $this->mensajeErrorCargaXlsx($errorCarga)
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

        if ($tamano <= 0 || $tamano > 8 * 1024 * 1024) {
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

        $this->limpiarImportacionesPoderAdquisitivoPendientes();
        $directorio = $this->directorioImportacionesPoderAdquisitivo();

        if (!is_dir($directorio) && !mkdir($directorio, 0775, true) && !is_dir($directorio)) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No fue posible preparar el archivo para su validación.'
            ], 500);
        }

        try {
            $token = bin2hex(random_bytes(20));
        } catch (Throwable $error) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No fue posible preparar la importación.'
            ], 500);
        }

        $rutaGuardada = $directorio . DIRECTORY_SEPARATOR . $token . '.xlsx';

        if (!move_uploaded_file($rutaTemporal, $rutaGuardada)) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No fue posible guardar temporalmente el archivo.'
            ], 500);
        }

        $servicio = new PobrezaLaboralImportService();
        $lectura = $servicio->leerArchivo($rutaGuardada);

        if (($lectura['ok'] ?? false) !== true) {
            @unlink($rutaGuardada);
            $this->responderJson([
                'ok' => false,
                'mensaje' => $lectura['mensaje'] ?? 'El archivo no tiene la estructura esperada.'
            ], 422);
        }

        $hash = hash_file('sha256', $rutaGuardada);

        if ($hash === false) {
            @unlink($rutaGuardada);
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No fue posible verificar la integridad del archivo.'
            ], 500);
        }

        $_SESSION['importaciones_poder_adquisitivo'] = [
            $token => [
                'ruta' => $rutaGuardada,
                'archivo_original' => $nombreOriginal,
                'hash' => $hash,
                'creado' => time()
            ]
        ];

        $periodo = $lectura['periodo'] ?? [];
        $anio = (int)($periodo['anio'] ?? 0);
        $trimestre = (int)($periodo['trimestre'] ?? 0);
        $modelo = new DataTerritorialModel();
        $registrosExistentes = $modelo->contarRegistrosPoderAdquisitivoPeriodo(
            $anio,
            $trimestre
        );
        $nacional = null;

        foreach (($lectura['datos'] ?? []) as $registro) {
            if (($registro['clave_geografica'] ?? '') === '00') {
                $nacional = $registro;
                break;
            }
        }

        $this->responderJson([
            'ok' => true,
            'mensaje' => 'El archivo fue validado correctamente.',
            'datos' => [
                'token' => $token,
                'archivo' => $nombreOriginal,
                'periodo' => [
                    'anio' => $anio,
                    'trimestre' => $trimestre,
                    'trimestre_romano' => (string)($periodo['trimestre_romano'] ?? '')
                ],
                'total_geografias' => (int)($lectura['total_geografias'] ?? 0),
                'estados' => 32,
                'referencia_nacional' => $nacional,
                'periodo_existente' => $registrosExistentes > 0,
                'registros_existentes' => $registrosExistentes
            ]
        ]);
    }

    public function importarPoderAdquisitivo()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'Método no permitido.'
            ], 405);
        }

        $this->validarPermisoActualizacionOficialJson();
        $token = trim((string)($_POST['token'] ?? ''));

        if (!preg_match('/^[a-f0-9]{40}$/', $token)) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'La validación del archivo ya no es válida. Selecciona nuevamente el XLSX.'
            ], 422);
        }

        $pendientes = $_SESSION['importaciones_poder_adquisitivo'] ?? [];
        $pendiente = $pendientes[$token] ?? null;

        if (!is_array($pendiente)) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'La validación del archivo expiró. Selecciona nuevamente el XLSX.'
            ], 422);
        }

        $ruta = (string)($pendiente['ruta'] ?? '');
        $archivoOriginal = trim((string)($pendiente['archivo_original'] ?? ''));
        $creado = (int)($pendiente['creado'] ?? 0);
        $hashEsperado = (string)($pendiente['hash'] ?? '');

        if (
            $creado <= 0 ||
            time() - $creado > 7200 ||
            !is_file($ruta) ||
            !is_readable($ruta)
        ) {
            $this->eliminarImportacionPoderAdquisitivoPendiente($token);
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'La validación del archivo expiró. Selecciona nuevamente el XLSX.'
            ], 422);
        }

        $hashActual = hash_file('sha256', $ruta);

        if ($hashActual === false || !hash_equals($hashEsperado, $hashActual)) {
            $this->eliminarImportacionPoderAdquisitivoPendiente($token);
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'El archivo temporal cambió y debe validarse nuevamente.'
            ], 422);
        }

        $servicio = new PobrezaLaboralImportService();
        $lectura = $servicio->leerArchivo($ruta);

        if (($lectura['ok'] ?? false) !== true) {
            $this->eliminarImportacionPoderAdquisitivoPendiente($token);
            $this->responderJson([
                'ok' => false,
                'mensaje' => $lectura['mensaje'] ?? 'El archivo ya no pudo validarse.'
            ], 422);
        }

        $modelo = new DataTerritorialModel();

        try {
            $resultado = $modelo->importarPoderAdquisitivoOficial(
                $lectura,
                $archivoOriginal,
                (int)$_SESSION['usuario_id']
            );
        } catch (Throwable $error) {
            error_log($error->getMessage());
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No fue posible guardar los indicadores de poder adquisitivo. Intenta nuevamente.'
            ], 500);
        }

        $this->eliminarImportacionPoderAdquisitivoPendiente($token);
        $periodo = $lectura['periodo'] ?? [];

        $this->responderJson([
            'ok' => true,
            'mensaje' => !empty($resultado['periodo_ya_existia'])
                ? 'El periodo ya existía y sus indicadores fueron actualizados correctamente.'
                : 'Los indicadores de poder adquisitivo fueron importados correctamente.',
            'datos' => [
                'anio' => (int)($periodo['anio'] ?? 0),
                'trimestre' => (int)($periodo['trimestre'] ?? 0),
                'trimestre_romano' => (string)($periodo['trimestre_romano'] ?? ''),
                'estados_importados' => (int)($resultado['estados_importados'] ?? 0),
                'referencia_nacional_importada' => !empty($resultado['referencia_nacional_importada']),
                'registros_procesados' => (int)($resultado['registros_procesados'] ?? 0),
                'periodo_ya_existia' => !empty($resultado['periodo_ya_existia']),
                'archivo' => $archivoOriginal,
                'fuente' => 'INEGI - Pobreza Laboral (PL)'
            ]
        ]);
    }

    public function previsualizarRezagoEducativo()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'Método no permitido.'
            ], 405);
        }

        $this->validarPermisoActualizacionOficialJson();
        $archivo = $_FILES['archivo_rezago_educativo'] ?? null;

        if (!is_array($archivo)) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'Selecciona el archivo XLSX oficial de Pobreza Multidimensional.'
            ], 422);
        }

        $errorCarga = (int)($archivo['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($errorCarga !== UPLOAD_ERR_OK) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => $this->mensajeErrorCargaXlsxRezago($errorCarga)
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

        if ($tamano <= 0 || $tamano > 16 * 1024 * 1024) {
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

        $this->limpiarImportacionesRezagoEducativoPendientes();
        $directorio = $this->directorioImportacionesRezagoEducativo();

        if (!is_dir($directorio) && !mkdir($directorio, 0775, true) && !is_dir($directorio)) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No fue posible preparar el archivo para su validación.'
            ], 500);
        }

        try {
            $token = bin2hex(random_bytes(20));
        } catch (Throwable $error) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No fue posible preparar la importación.'
            ], 500);
        }

        $rutaGuardada = $directorio . DIRECTORY_SEPARATOR . $token . '.xlsx';

        if (!move_uploaded_file($rutaTemporal, $rutaGuardada)) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No fue posible guardar temporalmente el archivo.'
            ], 500);
        }

        $servicio = new RezagoEducativoImportService();
        $lectura = $servicio->leerArchivo($rutaGuardada);

        if (($lectura['ok'] ?? false) !== true) {
            @unlink($rutaGuardada);
            $this->responderJson([
                'ok' => false,
                'mensaje' => $lectura['mensaje'] ?? 'El archivo no tiene la estructura esperada.'
            ], 422);
        }

        $hash = hash_file('sha256', $rutaGuardada);

        if ($hash === false) {
            @unlink($rutaGuardada);
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No fue posible verificar la integridad del archivo.'
            ], 500);
        }

        $_SESSION['importaciones_rezago_educativo'] = [
            $token => [
                'ruta' => $rutaGuardada,
                'archivo_original' => $nombreOriginal,
                'hash' => $hash,
                'creado' => time()
            ]
        ];

        $periodos = array_values(array_map('intval', $lectura['periodos'] ?? []));
        sort($periodos, SORT_NUMERIC);
        $modelo = new DataTerritorialModel();
        $periodosExistentes = [];
        $periodosNuevos = [];

        foreach ($periodos as $anio) {
            if ($modelo->contarRegistrosRezagoEducativoOficialPeriodo($anio) > 0) {
                $periodosExistentes[] = $anio;
            } else {
                $periodosNuevos[] = $anio;
            }
        }

        $ultimoPeriodo = (int)($lectura['ultimo_periodo'] ?? 0);
        $nacionalUltimo = null;

        foreach (($lectura['datos'] ?? []) as $registro) {
            if (
                ($registro['clave_geografica'] ?? '') === '00' &&
                (int)($registro['anio'] ?? 0) === $ultimoPeriodo
            ) {
                $nacionalUltimo = $registro;
                break;
            }
        }

        $this->responderJson([
            'ok' => true,
            'mensaje' => 'El archivo fue validado correctamente.',
            'datos' => [
                'token' => $token,
                'archivo' => $nombreOriginal,
                'periodos' => $periodos,
                'ultimo_periodo' => $ultimoPeriodo,
                'total_periodos' => count($periodos),
                'total_geografias' => (int)($lectura['total_geografias'] ?? 0),
                'total_registros' => (int)($lectura['total_registros'] ?? 0),
                'estados' => 32,
                'periodos_nuevos' => $periodosNuevos,
                'periodos_existentes' => $periodosExistentes,
                'referencia_nacional_ultimo_periodo' => $nacionalUltimo
            ]
        ]);
    }

    public function importarRezagoEducativo()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'Método no permitido.'
            ], 405);
        }

        $this->validarPermisoActualizacionOficialJson();
        $token = trim((string)($_POST['token'] ?? ''));

        if (!preg_match('/^[a-f0-9]{40}$/', $token)) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'La validación del archivo ya no es válida. Selecciona nuevamente el XLSX.'
            ], 422);
        }

        $pendientes = $_SESSION['importaciones_rezago_educativo'] ?? [];
        $pendiente = $pendientes[$token] ?? null;

        if (!is_array($pendiente)) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'La validación del archivo expiró. Selecciona nuevamente el XLSX.'
            ], 422);
        }

        $ruta = (string)($pendiente['ruta'] ?? '');
        $archivoOriginal = trim((string)($pendiente['archivo_original'] ?? ''));
        $creado = (int)($pendiente['creado'] ?? 0);
        $hashEsperado = (string)($pendiente['hash'] ?? '');

        if (
            $creado <= 0 ||
            time() - $creado > 7200 ||
            !is_file($ruta) ||
            !is_readable($ruta)
        ) {
            $this->eliminarImportacionRezagoEducativoPendiente($token);
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'La validación del archivo expiró. Selecciona nuevamente el XLSX.'
            ], 422);
        }

        $hashActual = hash_file('sha256', $ruta);

        if ($hashActual === false || !hash_equals($hashEsperado, $hashActual)) {
            $this->eliminarImportacionRezagoEducativoPendiente($token);
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'El archivo temporal cambió y debe validarse nuevamente.'
            ], 422);
        }

        $servicio = new RezagoEducativoImportService();
        $lectura = $servicio->leerArchivo($ruta);

        if (($lectura['ok'] ?? false) !== true) {
            $this->eliminarImportacionRezagoEducativoPendiente($token);
            $this->responderJson([
                'ok' => false,
                'mensaje' => $lectura['mensaje'] ?? 'El archivo ya no pudo validarse.'
            ], 422);
        }

        $modelo = new DataTerritorialModel();

        try {
            $resultado = $modelo->importarRezagoEducativoOficial(
                $lectura,
                $archivoOriginal,
                (int)$_SESSION['usuario_id']
            );
        } catch (Throwable $error) {
            error_log($error->getMessage());
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No fue posible guardar los indicadores oficiales de rezago educativo. Intenta nuevamente.'
            ], 500);
        }

        $this->eliminarImportacionRezagoEducativoPendiente($token);

        $this->responderJson([
            'ok' => true,
            'mensaje' => empty($resultado['periodos_nuevos'])
                ? 'Los periodos ya existían y sus indicadores fueron actualizados correctamente.'
                : 'Los indicadores oficiales de rezago educativo fueron importados correctamente.',
            'datos' => [
                'periodos' => $resultado['periodos'] ?? [],
                'ultimo_periodo' => (int)($resultado['ultimo_periodo'] ?? 0),
                'periodos_nuevos' => $resultado['periodos_nuevos'] ?? [],
                'periodos_actualizados' => $resultado['periodos_actualizados'] ?? [],
                'estados_importados' => (int)($resultado['estados_importados'] ?? 0),
                'referencia_nacional_importada' => !empty($resultado['referencia_nacional_importada']),
                'registros_procesados' => (int)($resultado['registros_procesados'] ?? 0),
                'archivo' => $archivoOriginal,
                'fuente' => 'INEGI - Pobreza Multidimensional'
            ]
        ]);
    }

    public function guardarSecretaria()
    {
        $this->guardarOActualizarSecretaria();
    }

    public function actualizarSecretaria()
    {
        $this->guardarOActualizarSecretaria(true);
    }

    public function cambiarEstadoSecretaria()
    {
        $this->validarPermiso('data_territorial.gestionar_secretarias');
        $this->validarMetodoPost();

        $modelo = new DataTerritorialModel();
        $estadoId = (int)($_POST['estado_id'] ?? 0);
        $id = (int)($_POST['id'] ?? 0);
        $estado = $this->normalizarEstado($_POST['estado'] ?? '');
        $this->validarAccesoEstado($modelo, $estadoId);

        if ($id <= 0 || $estado === null) {
            $this->volverConError($estadoId, 'La secretaría seleccionada no es válida.');
        }

        if ($modelo->cambiarEstadoSecretaria($id, $estadoId, $estado)) {
            $_SESSION['mensaje_data_territorial'] =
                $estado === 1
                    ? 'Secretaría activada correctamente.'
                    : 'Secretaría desactivada correctamente.';
        } else {
            $_SESSION['error_data_territorial'] =
                'No fue posible actualizar la secretaría.';
        }

        $this->redirigirAData($estadoId, '#secretarias');
    }

    public function guardarIndicador()
    {
        $this->guardarOActualizarIndicador();
    }

    public function actualizarIndicador()
    {
        $this->guardarOActualizarIndicador(true);
    }

    public function cambiarEstadoIndicador()
    {
        $this->validarPermiso('data_territorial.gestionar_indicadores');
        $this->validarMetodoPost();

        $modelo = new DataTerritorialModel();
        $estadoId = (int)($_POST['estado_id'] ?? 0);
        $id = (int)($_POST['id'] ?? 0);
        $estado = $this->normalizarEstado($_POST['estado'] ?? '');
        $this->validarAccesoEstado($modelo, $estadoId);

        if ($id <= 0 || $estado === null) {
            $this->volverConError($estadoId, 'El indicador seleccionado no es válido.');
        }

        if ($modelo->cambiarEstadoIndicador($id, $estadoId, $estado)) {
            $_SESSION['mensaje_data_territorial'] =
                $estado === 1
                    ? 'Indicador activado correctamente.'
                    : 'Indicador desactivado correctamente.';
        } else {
            $_SESSION['error_data_territorial'] =
                'No fue posible actualizar el indicador.';
        }

        $this->redirigirAData($estadoId, '#educacion');
    }

    public function eliminarIndicador()
    {
        $this->validarPermiso('data_territorial.gestionar_indicadores');
        $this->validarMetodoPost();

        $modelo = new DataTerritorialModel();
        $estadoId = (int)($_POST['estado_id'] ?? 0);
        $id = (int)($_POST['id'] ?? 0);
        $this->validarAccesoEstado($modelo, $estadoId);

        if ($id <= 0) {
            $this->volverConError($estadoId, 'El indicador seleccionado no es válido.', '#educacion');
        }

        if ($modelo->cambiarEstadoIndicador($id, $estadoId, 0)) {
            $_SESSION['mensaje_data_territorial'] =
                'Indicador educativo complementario eliminado correctamente.';
        } else {
            $_SESSION['error_data_territorial'] =
                'No fue posible eliminar el indicador educativo complementario.';
        }

        $this->redirigirAData($estadoId, '#educacion');
    }

    public function guardarMunicipio()
    {
        $this->guardarOActualizarMunicipio();
    }

    public function actualizarMunicipio()
    {
        $this->guardarOActualizarMunicipio(true);
    }

    public function cambiarEstadoMunicipio()
    {
        $this->validarPermiso('data_territorial.gestionar_municipios');
        $this->validarMetodoPost();

        $modelo = new DataTerritorialModel();
        $estadoId = (int)($_POST['estado_id'] ?? 0);
        $id = (int)($_POST['id'] ?? 0);
        $estado = $this->normalizarEstado($_POST['estado'] ?? '');
        $this->validarAccesoEstado($modelo, $estadoId);

        if ($id <= 0 || $estado === null) {
            $this->volverConError($estadoId, 'El municipio seleccionado no es válido.');
        }

        if ($modelo->cambiarEstadoMunicipio($id, $estadoId, $estado)) {
            $_SESSION['mensaje_data_territorial'] =
                $estado === 1
                    ? 'Municipio activado correctamente.'
                    : 'Municipio desactivado correctamente.';
        } else {
            $_SESSION['error_data_territorial'] =
                'No fue posible actualizar el municipio.';
        }

        $this->redirigirAData($estadoId, '#municipios');
    }

    private function guardarOActualizarSecretaria($editar = false)
    {
        $this->validarPermiso('data_territorial.gestionar_secretarias');
        $this->validarMetodoPost();

        $modelo = new DataTerritorialModel();
        $estadoId = (int)($_POST['estado_id'] ?? 0);
        $id = (int)($_POST['id'] ?? 0);
        $this->validarAccesoEstado($modelo, $estadoId);

        $datos = [
            'estado_id' => $estadoId,
            'nombre' => trim($_POST['nombre'] ?? ''),
            'titular' => trim($_POST['titular'] ?? ''),
            'cargo_titular' => trim($_POST['cargo_titular'] ?? ''),
            'correo' => trim($_POST['correo'] ?? ''),
            'telefono' => trim($_POST['telefono'] ?? ''),
            'sitio_web' => trim($_POST['sitio_web'] ?? '')
        ];
        $errores = [];

        if ($datos['nombre'] === '') {
            $errores[] = 'El nombre de la secretaría es obligatorio.';
        }

        if (
            $datos['correo'] !== '' &&
            !filter_var($datos['correo'], FILTER_VALIDATE_EMAIL)
        ) {
            $errores[] = 'El correo de la secretaría no es válido.';
        }

        if (
            $datos['sitio_web'] !== '' &&
            !filter_var($datos['sitio_web'], FILTER_VALIDATE_URL)
        ) {
            $errores[] = 'El sitio web de la secretaría no es válido.';
        }

        if (!empty($errores)) {
            $this->volverConError($estadoId, implode(' ', $errores), '#secretarias');
        }

        $resultado = $editar
            ? $modelo->actualizarSecretaria($id, $datos)
            : $modelo->crearSecretaria($datos);

        $_SESSION[$resultado
            ? 'mensaje_data_territorial'
            : 'error_data_territorial'] = $resultado
                ? 'Secretaría guardada correctamente.'
                : 'No fue posible guardar la secretaría.';

        $this->redirigirAData($estadoId, '#secretarias');
    }

    private function guardarOActualizarIndicador($editar = false)
    {
        $this->validarPermiso('data_territorial.gestionar_indicadores');
        $this->validarMetodoPost();

        $modelo = new DataTerritorialModel();
        $estadoId = (int)($_POST['estado_id'] ?? 0);
        $id = (int)($_POST['id'] ?? 0);
        $this->validarAccesoEstado($modelo, $estadoId);

        $valor = trim($_POST['valor'] ?? '');
        $unidad = trim($_POST['unidad'] ?? '');
        $cantidad = trim($_POST['cantidad_aproximada'] ?? '');
        $valorNormalizado = $valor === '' ? null : (float)$valor;
        $porcentajeCompatibilidad = (
            $valorNormalizado !== null && $unidad === '%'
        ) ? $valorNormalizado : null;

        $datos = [
            'estado_id' => $estadoId,
            'situacion' => trim($_POST['situacion'] ?? ''),
            'valor' => $valorNormalizado,
            'unidad' => $unidad === '' ? null : $unidad,
            'porcentaje' => $porcentajeCompatibilidad,
            'cantidad_aproximada' => $cantidad === '' ? null : (int)$cantidad,
            'fuente' => trim($_POST['fuente'] ?? ''),
            'periodo' => trim($_POST['periodo'] ?? '')
        ];
        $errores = [];

        if ($datos['situacion'] === '') {
            $errores[] = 'El indicador educativo es obligatorio.';
        }

        if ($valor !== '' && (!is_numeric($valor) || (float)$valor < 0)) {
            $errores[] = 'El valor del indicador debe ser un número mayor o igual a 0.';
        }

        if ($valor !== '' && $unidad === '') {
            $errores[] = 'Selecciona o escribe la unidad del valor registrado.';
        }

        if ($unidad === '%' && $valor !== '' && (float)$valor > 100) {
            $errores[] = 'Cuando la unidad es porcentaje, el valor debe estar entre 0 y 100.';
        }

        if (strlen($unidad) > 30) {
            $errores[] = 'La unidad no puede exceder 30 caracteres.';
        }

        if ($cantidad !== '' && (!ctype_digit($cantidad) || (int)$cantidad < 0)) {
            $errores[] = 'La cantidad aproximada debe ser un entero mayor o igual a 0.';
        }

        if ($valor === '' && $cantidad === '') {
            $errores[] = 'Registra al menos un valor o una cantidad aproximada.';
        }

        if ($datos['fuente'] === '') {
            $errores[] = 'La fuente es obligatoria.';
        }

        if ($datos['periodo'] === '') {
            $errores[] = 'El periodo es obligatorio.';
        }

        if (!empty($errores)) {
            $this->volverConError($estadoId, implode(' ', $errores), '#educacion');
        }

        $resultado = $editar
            ? $modelo->actualizarIndicador($id, $datos)
            : $modelo->crearIndicador($datos);

        $_SESSION[$resultado
            ? 'mensaje_data_territorial'
            : 'error_data_territorial'] = $resultado
                ? 'Indicador educativo complementario guardado correctamente.'
                : 'No fue posible guardar el indicador educativo complementario.';

        $this->redirigirAData($estadoId, '#educacion');
    }

    private function guardarOActualizarMunicipio($editar = false)
    {
        $this->validarPermiso('data_territorial.gestionar_municipios');
        $this->validarMetodoPost();

        $modelo = new DataTerritorialModel();
        $estadoId = (int)($_POST['estado_id'] ?? 0);
        $id = (int)($_POST['id'] ?? 0);
        $this->validarAccesoEstado($modelo, $estadoId);
        $municipioActual = $editar ? $modelo->buscarMunicipioPorId($id) : null;

        if ($editar && (!$municipioActual || (int)$municipioActual['estado_id'] !== $estadoId)) {
            $this->volverConError($estadoId, 'El municipio seleccionado no es válido.', '#municipios');
        }

        $poblacion = trim($_POST['poblacion'] ?? '');
        $datos = [
            'estado_id' => $estadoId,
            'clave_inegi' => trim($_POST['clave_inegi'] ?? ''),
            'numero_excel' => null,
            'nombre' => trim($_POST['nombre'] ?? ''),
            'poblacion' => $poblacion === '' ? null : (int)$poblacion,
            'presidente_municipal' => trim($_POST['presidente_municipal'] ?? ''),
            'partido_politico' => trim($_POST['partido_politico'] ?? ''),
            'redes_sociales' => trim($_POST['redes_sociales'] ?? ''),
            'fotografia' => $municipioActual['fotografia'] ?? '',
            'fecha_actualizacion' => $this->fechaActualizacionSistema()
        ];
        $errores = [];

        if ($datos['nombre'] === '') {
            $errores[] = 'El nombre del municipio es obligatorio.';
        } elseif ($modelo->existeMunicipioPorNombre(
            $estadoId,
            $datos['nombre'],
            $editar ? $id : null
        )) {
            $errores[] = 'Ya existe un municipio con ese nombre en el territorio.';
        }

        if ($poblacion !== '' && (!ctype_digit($poblacion) || (int)$poblacion < 0)) {
            $errores[] = 'La población debe ser un número válido.';
        }

        $quitarFoto = isset($_POST['quitar_fotografia']) &&
            (string)$_POST['quitar_fotografia'] === '1';
        $hayFotoNueva =
            isset($_FILES['fotografia']) &&
            $_FILES['fotografia']['error'] !== UPLOAD_ERR_NO_FILE;

        if ($quitarFoto && $hayFotoNueva) {
            $errores[] = 'No puedes quitar y reemplazar la fotografía al mismo tiempo.';
        }

        if (!empty($errores)) {
            $this->volverConError($estadoId, implode(' ', $errores), '#municipios');
        }

        $fotoAnterior = $municipioActual['fotografia'] ?? '';
        $foto = $quitarFoto
            ? ['ruta' => null, 'error' => '', 'nueva' => false]
            : $this->procesarImagen(
                'fotografia',
                $fotoAnterior,
                'public/uploads/municipios'
            );

        if ($foto['error'] !== '') {
            $this->volverConError($estadoId, $foto['error'], '#municipios');
        }

        $datos['fotografia'] = $foto['ruta'];

        $resultado = $editar
            ? $modelo->actualizarMunicipio($id, $datos)
            : $modelo->crearMunicipio($datos);

        if ($resultado) {
            if (
                ($quitarFoto || !empty($foto['nueva'])) &&
                $fotoAnterior !== '' &&
                $fotoAnterior !== (string)($datos['fotografia'] ?? '') &&
                $modelo->contarMunicipiosConFotografia($fotoAnterior) === 0
            ) {
                $this->eliminarArchivoSeguro($fotoAnterior, 'public/uploads/municipios');
            }

            $_SESSION['mensaje_data_territorial'] =
                'Municipio guardado correctamente.';
        } else {
            $this->eliminarArchivoSiNuevo($foto);
            $_SESSION['error_data_territorial'] =
                'No fue posible guardar el municipio.';
        }

        $this->redirigirAData($estadoId, '#municipios');
    }

    private function validarPermisoActualizacionOficialJson(): void
    {
        if (!isset($_SESSION['usuario_id'])) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'La sesión no está activa.'
            ], 401);
        }

        if (!tienePermiso('data_territorial.actualizar_oficial')) {
            $this->responderJson([
                'ok' => false,
                'mensaje' => 'No tienes permiso para actualizar información oficial.'
            ], 403);
        }
    }

    private function directorioImportacionesPoderAdquisitivo(): string
    {
        return ROOT_PATH . '/storage/importaciones/pobreza_laboral/pendientes';
    }

    private function limpiarImportacionesPoderAdquisitivoPendientes(): void
    {
        $pendientes = $_SESSION['importaciones_poder_adquisitivo'] ?? [];

        foreach ($pendientes as $token => $pendiente) {
            $creado = (int)($pendiente['creado'] ?? 0);

            if ($creado <= 0 || time() - $creado > 7200) {
                $this->eliminarImportacionPoderAdquisitivoPendiente((string)$token);
            }
        }

        $directorio = $this->directorioImportacionesPoderAdquisitivo();

        if (!is_dir($directorio)) {
            return;
        }

        foreach (glob($directorio . '/*.xlsx') ?: [] as $archivo) {
            if (is_file($archivo) && filemtime($archivo) < time() - 7200) {
                @unlink($archivo);
            }
        }
    }

    private function eliminarImportacionPoderAdquisitivoPendiente(string $token): void
    {
        $pendientes = $_SESSION['importaciones_poder_adquisitivo'] ?? [];
        $pendiente = $pendientes[$token] ?? null;

        if (is_array($pendiente)) {
            $ruta = (string)($pendiente['ruta'] ?? '');
            $directorioBase = realpath($this->directorioImportacionesPoderAdquisitivo());
            $rutaReal = $ruta !== '' ? realpath($ruta) : false;

            if ($directorioBase !== false && $rutaReal !== false && is_file($rutaReal)) {
                $directorioBase = rtrim(str_replace('\\', '/', $directorioBase), '/') . '/';
                $rutaNormalizada = str_replace('\\', '/', $rutaReal);

                if (str_starts_with($rutaNormalizada, $directorioBase)) {
                    @unlink($rutaReal);
                }
            }
        }

        unset($_SESSION['importaciones_poder_adquisitivo'][$token]);

        if (empty($_SESSION['importaciones_poder_adquisitivo'])) {
            unset($_SESSION['importaciones_poder_adquisitivo']);
        }
    }

    private function directorioImportacionesRezagoEducativo(): string
    {
        return ROOT_PATH . '/storage/importaciones/rezago_educativo/pendientes';
    }

    private function limpiarImportacionesRezagoEducativoPendientes(): void
    {
        $pendientes = $_SESSION['importaciones_rezago_educativo'] ?? [];

        foreach ($pendientes as $token => $pendiente) {
            $creado = (int)($pendiente['creado'] ?? 0);

            if ($creado <= 0 || time() - $creado > 7200) {
                $this->eliminarImportacionRezagoEducativoPendiente((string)$token);
            }
        }

        $directorio = $this->directorioImportacionesRezagoEducativo();

        if (!is_dir($directorio)) {
            return;
        }

        foreach (glob($directorio . '/*.xlsx') ?: [] as $archivo) {
            if (is_file($archivo) && filemtime($archivo) < time() - 7200) {
                @unlink($archivo);
            }
        }
    }

    private function eliminarImportacionRezagoEducativoPendiente(string $token): void
    {
        $pendientes = $_SESSION['importaciones_rezago_educativo'] ?? [];
        $pendiente = $pendientes[$token] ?? null;

        if (is_array($pendiente)) {
            $ruta = (string)($pendiente['ruta'] ?? '');
            $directorioBase = realpath($this->directorioImportacionesRezagoEducativo());
            $rutaReal = $ruta !== '' ? realpath($ruta) : false;

            if ($directorioBase !== false && $rutaReal !== false && is_file($rutaReal)) {
                $directorioBase = rtrim(str_replace('\\', '/', $directorioBase), '/') . '/';
                $rutaNormalizada = str_replace('\\', '/', $rutaReal);

                if (str_starts_with($rutaNormalizada, $directorioBase)) {
                    @unlink($rutaReal);
                }
            }
        }

        unset($_SESSION['importaciones_rezago_educativo'][$token]);

        if (empty($_SESSION['importaciones_rezago_educativo'])) {
            unset($_SESSION['importaciones_rezago_educativo']);
        }
    }

    private function mensajeErrorCargaXlsxRezago(int $codigo): string
    {
        return match ($codigo) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El archivo XLSX supera el tamaño permitido.',
            UPLOAD_ERR_PARTIAL => 'El archivo no se cargó completamente. Intenta nuevamente.',
            UPLOAD_ERR_NO_FILE => 'Selecciona el archivo XLSX oficial de Pobreza Multidimensional.',
            default => 'No fue posible cargar el archivo XLSX.'
        };
    }

    private function mensajeErrorCargaXlsx(int $codigo): string
    {
        return match ($codigo) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El archivo XLSX supera el tamaño permitido.',
            UPLOAD_ERR_PARTIAL => 'El archivo no se cargó completamente. Intenta nuevamente.',
            UPLOAD_ERR_NO_FILE => 'Selecciona el archivo XLSX oficial de Pobreza Laboral.',
            default => 'No fue posible cargar el archivo XLSX.'
        };
    }

    private function validarPermiso($codigo)
    {
        if (!isset($_SESSION['usuario_id'])) {
            header(
                'Location: ' .
                BASE_URL .
                'index.php?controller=login&action=mostrarLogin'
            );
            exit;
        }

        if (!tienePermiso($codigo)) {
            header(
                'Location: ' .
                BASE_URL .
                'index.php?controller=home&action=index'
            );
            exit;
        }
    }

    private function validarPermisoEdicionGeneral()
    {
        if (
            !tienePermiso('data_territorial.editar') &&
            !tienePermiso('territorios.actualizar_ficha')
        ) {
            header(
                'Location: ' .
                BASE_URL .
                'index.php?controller=home&action=index'
            );
            exit;
        }
    }

    private function validarAccesoEstado($modelo, $estadoId)
    {
        if (
            $estadoId <= 0 ||
            !$modelo->puedeAccederEstado(
                (int)($_SESSION['usuario_id'] ?? 0),
                (int)($_SESSION['rol_id'] ?? 0),
                $estadoId
            )
        ) {
            $_SESSION['error_data_territorial'] =
                'No tienes acceso a la información de ese territorio.';
            $this->redirigirAData();
        }
    }

    private function validarMetodoPost()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirigirAData();
        }
    }

    private function responderJson($datos, $codigoHttp = 200)
    {
        http_response_code($codigoHttp);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($datos, JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function limpiarFichaGeneral($origen, $estadoActual, &$errores)
    {
        return [
            'capital' => trim($origen['capital'] ?? ''),
            'titular_gobierno' => trim($origen['titular_gobierno'] ?? ''),
            'foto_titular' => $estadoActual['foto_titular'] ?? '',
            'mapa_estado' => $estadoActual['mapa_estado'] ?? '',
            'cargo_titular' => trim($origen['cargo_titular'] ?? ''),
            'partido_politico' => trim($origen['partido_politico'] ?? ''),
            'poblacion' => $this->enteroOpcionalValidado(
                $origen['poblacion'] ?? '',
                $errores,
                'La población debe ser un número válido.'
            ),
            'total_municipios' => $this->enteroOpcionalValidado(
                $origen['total_municipios'] ?? '',
                $errores,
                'El total de municipios debe ser un número válido.'
            ),
            'total_secretarias' => $this->enteroOpcionalValidado(
                $origen['total_secretarias'] ?? '',
                $errores,
                'El total de secretarías debe ser un número válido.'
            ),
            'periodo_gobierno' => trim($origen['periodo_gobierno'] ?? ''),
            'telefono' => trim($origen['telefono'] ?? ''),
            'redes_sociales' => trim($origen['redes_sociales'] ?? ''),
            'actividad_economica' => $estadoActual['actividad_economica'] ?? '',
            'poder_adquisitivo' => $estadoActual['poder_adquisitivo'] ?? ''
        ];
    }

    private function enteroOpcionalValidado($valor, &$errores, $mensajeError)
    {
        $valor = trim((string)$valor);

        if ($valor === '') {
            return null;
        }

        if (!ctype_digit($valor)) {
            $errores[] = $mensajeError;
            return null;
        }

        return (int)$valor;
    }

    private function fechaActualizacionSistema()
    {
        return date('Y-m-d H:i:s');
    }

    private function obtenerLimiteMunicipios()
    {
        $limite = (int)($_GET['limite_municipios'] ?? 10);

        return in_array($limite, [10, 15, 20], true) ? $limite : 10;
    }

    private function obtenerSubtituloPagina($rolId)
    {
        $rol = $_SESSION['rol'] ?? '';

        if ((int)$rolId === 1) {
            return 'Consulta y administra la información de los territorios registrados.';
        }

        if ($rol === 'Analista de Datos') {
            return 'Consulta y actualiza la información de tus territorios asignados.';
        }

        return 'Consulta la información de tus territorios asignados.';
    }

    private function normalizarFiltroInformacion($filtro)
    {
        return in_array($filtro, ['sin', 'con'], true)
            ? $filtro
            : 'todos';
    }

    private function filtrarTerritoriosPorInformacion($territorios, $filtro)
    {
        if ($filtro === 'todos') {
            return $territorios;
        }

        return array_values(array_filter(
            $territorios,
            function ($territorio) use ($filtro) {
                $tieneInformacion =
                    (int)($territorio['tiene_informacion_territorial'] ?? 0) === 1;

                return $filtro === 'con'
                    ? $tieneInformacion
                    : !$tieneInformacion;
            }
        ));
    }

    private function normalizarEstado($estado)
    {
        return in_array((string)$estado, ['0', '1'], true)
            ? (int)$estado
            : null;
    }

    private function normalizarFecha($fecha)
    {
        $fecha = trim((string)$fecha);

        if ($fecha === '') {
            return null;
        }

        try {
            return (new DateTime($fecha))->format('Y-m-d H:i:s');
        } catch (Exception $error) {
            return null;
        }
    }

    private function procesarImagen($campo, $rutaActual, $directorioRelativo)
    {
        if (
            !isset($_FILES[$campo]) ||
            $_FILES[$campo]['error'] === UPLOAD_ERR_NO_FILE
        ) {
            return [
                'ruta' => $rutaActual,
                'error' => '',
                'nueva' => false,
                'directorio' => $directorioRelativo
            ];
        }

        if ($_FILES[$campo]['error'] !== UPLOAD_ERR_OK) {
            return [
                'ruta' => $rutaActual,
                'error' => 'No fue posible cargar la imagen.',
                'nueva' => false,
                'directorio' => $directorioRelativo
            ];
        }

        if ($_FILES[$campo]['size'] > 2 * 1024 * 1024) {
            return [
                'ruta' => $rutaActual,
                'error' => 'La imagen no debe superar 2 MB.',
                'nueva' => false,
                'directorio' => $directorioRelativo
            ];
        }

        $temporal = $_FILES[$campo]['tmp_name'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $tipoImagen = $finfo->file($temporal);
        $extensiones = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp'
        ];

        if (!isset($extensiones[$tipoImagen])) {
            return [
                'ruta' => $rutaActual,
                'error' => 'La imagen debe ser JPG, PNG o WEBP.',
                'nueva' => false,
                'directorio' => $directorioRelativo
            ];
        }

        $directorioRelativo = trim(str_replace('\\', '/', $directorioRelativo), '/');
        $directorioFisico = ROOT_PATH . '/' . $directorioRelativo;

        if (!is_dir($directorioFisico)) {
            mkdir($directorioFisico, 0775, true);
        }

        $nombreArchivo =
            'data_' .
            date('YmdHis') .
            '_' .
            bin2hex(random_bytes(6)) .
            '.' .
            $extensiones[$tipoImagen];
        $destino = $directorioFisico . '/' . $nombreArchivo;
        $rutaPublica = $directorioRelativo . '/' . $nombreArchivo;

        if (!move_uploaded_file($temporal, $destino)) {
            return [
                'ruta' => $rutaActual,
                'error' => 'No fue posible guardar la imagen.',
                'nueva' => false,
                'directorio' => $directorioRelativo
            ];
        }

        return [
            'ruta' => $rutaPublica,
            'error' => '',
            'nueva' => true,
            'directorio' => $directorioRelativo
        ];
    }

    private function eliminarArchivoEstadoAnterior(
        $modelo,
        $campo,
        $rutaAnterior,
        $rutaNueva,
        $directorioRelativo
    ) {
        if (
            $rutaAnterior === '' ||
            $rutaAnterior === (string)$rutaNueva ||
            $modelo->contarEstadosConArchivo($campo, $rutaAnterior) > 0
        ) {
            return false;
        }

        return $this->eliminarArchivoSeguro($rutaAnterior, $directorioRelativo);
    }

    private function eliminarArchivoSiNuevo($archivo)
    {
        if (!empty($archivo['nueva'])) {
            $this->eliminarArchivoSeguro(
                $archivo['ruta'],
                $archivo['directorio']
            );
        }
    }

    private function eliminarArchivoSeguro($ruta, $directorioRelativo)
    {
        $ruta = trim(str_replace('\\', '/', (string)$ruta));
        $directorioRelativo = trim(str_replace('\\', '/', (string)$directorioRelativo), '/');

        if ($ruta === '' || $directorioRelativo === '' || strpos($ruta, '..') !== false) {
            return false;
        }

        $ruta = ltrim($ruta, '/');
        $directorioPermitido = rtrim($directorioRelativo, '/') . '/';

        if (strpos($ruta, $directorioPermitido) !== 0) {
            return false;
        }

        $extension = strtolower(pathinfo($ruta, PATHINFO_EXTENSION));

        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return false;
        }

        $directorioBase = realpath(ROOT_PATH . '/' . $directorioRelativo);
        $rutaArchivo = realpath(ROOT_PATH . '/' . $ruta);

        if ($directorioBase === false || $rutaArchivo === false || !is_file($rutaArchivo)) {
            return false;
        }

        $directorioBase = rtrim(str_replace('\\', '/', $directorioBase), '/') . '/';
        $rutaArchivoNormalizada = str_replace('\\', '/', $rutaArchivo);

        if (strpos($rutaArchivoNormalizada, $directorioBase) !== 0) {
            return false;
        }

        return @unlink($rutaArchivo);
    }

    private function volverConError($estadoId, $mensaje, $ancla = '')
    {
        $_SESSION['error_data_territorial'] = $mensaje;
        $this->redirigirAData($estadoId, $ancla);
    }

    private function redirigirAData($estadoId = 0, $ancla = '')
    {
        $url = BASE_URL . 'index.php?controller=dataTerritorial&action=index';

        if ((int)$estadoId > 0) {
            $url .= '&estado_id=' . (int)$estadoId;
        }

        header('Location: ' . $url . $ancla);
        exit;
    }
}
