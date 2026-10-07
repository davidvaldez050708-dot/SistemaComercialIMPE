<?php

require_once __DIR__ . '/../models/ConvocatoriaModel.php';
require_once __DIR__ . '/../models/TerritorioModel.php';
require_once __DIR__ . '/../helpers/PermissionHelper.php';

class ConvocatoriaController
{
    public function listadoFiltrado()
    {
        $this->validarPermiso('convocatorias.ver');

        $modelo = new ConvocatoriaModel();
        $buscar = trim((string)($_GET['buscar'] ?? ''));
        $estadoFiltro = (int)($_GET['territorio_id'] ?? ($_GET['estado_id'] ?? 0));
        $estatusFiltro = '';
        $fechaFiltroSolicitada = trim((string)($_GET['fecha'] ?? ''));
        $fechaFiltroObjeto = DateTime::createFromFormat('!Y-m-d', $fechaFiltroSolicitada);
        $fechaFiltro = (
            $fechaFiltroObjeto &&
            $fechaFiltroObjeto->format('Y-m-d') === $fechaFiltroSolicitada
        ) ? $fechaFiltroSolicitada : '';
        $categoriaSolicitada = (string)($_GET['categoria'] ?? '');
        $categoriaFiltro = in_array($categoriaSolicitada, ['', 'IMJUVE'], true)
            ? $categoriaSolicitada
            : '';
        $tipoConvocatoria = strtolower(trim((string)($_GET['tipo'] ?? '')));
        $subtipoConvocatoria = strtolower(trim((string)($_GET['subtipo'] ?? '')));
        $anioFiltro = (int)($_GET['anio'] ?? 0);
        $mesFiltro = (int)($_GET['mes'] ?? 0);
        $anioFiltro = $anioFiltro >= 2000 && $anioFiltro <= 2100 ? $anioFiltro : 0;
        $mesFiltro = $mesFiltro >= 1 && $mesFiltro <= 12 ? $mesFiltro : 0;
        $territorioPermitido = $this->usuarioPuedeConsultarTerritorio($estadoFiltro);
        $esChihuahua = $territorioPermitido
            ? $this->esTerritorioChihuahua($modelo, $estadoFiltro)
            : false;

        $convocatorias = (
            $territorioPermitido &&
            $this->esClasificacionValida(
                $tipoConvocatoria,
                $subtipoConvocatoria,
                $esChihuahua
            )
        )
            ? $modelo->obtenerListado(
                $buscar,
                $estadoFiltro,
                $estatusFiltro,
                $categoriaFiltro,
                $tipoConvocatoria,
                $subtipoConvocatoria,
                $anioFiltro,
                $mesFiltro,
                $fechaFiltro
            )
            : [];

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            [
                'ok' => true,
                'convocatorias' => $convocatorias
            ],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        exit;
    }

    public function index()
    {
        $this->validarPermiso('convocatorias.ver');

        $modelo = new ConvocatoriaModel();
        $modelo->desactivarConvocatoriasVencidas();
        $estados = $this->obtenerEstadosDisponibles($modelo->obtenerEstados());

        $territorioId = (int)($_GET['territorio_id'] ?? 0);
        $territorioSeleccionado = $this->obtenerTerritorioSeleccionado(
            $estados,
            $territorioId
        );
        $esChihuahua = $territorioSeleccionado &&
            strcasecmp(trim((string)($territorioSeleccionado['nombre'] ?? '')), 'Chihuahua') === 0;

        $tipoConvocatoriaSolicitado = strtolower(trim((string)($_GET['tipo'] ?? '')));
        $tiposPermitidos = ['titulacion', 'bachillerato'];

        if ($esChihuahua) {
            $tiposPermitidos[] = 'sindicatos';
        }

        $tipoConvocatoria = in_array(
            $tipoConvocatoriaSolicitado,
            $tiposPermitidos,
            true
        ) ? $tipoConvocatoriaSolicitado : '';

        $subtiposPermitidos = [
            'titulacion' => [
                'ejecutivas',
                'experiencia-laboral',
                'inscripciones-abiertas'
            ],
            'bachillerato' => [
                'bachillerato-2-anos',
                'bachillerato-286',
                'ingles',
                'inscripciones-abiertas'
            ],
            'sindicatos' => [
                'sindicatos'
            ]
        ];
        $subtipoConvocatoriaSolicitado = strtolower(trim((string)($_GET['subtipo'] ?? '')));

        if ($tipoConvocatoria === 'sindicatos' && $esChihuahua && $subtipoConvocatoriaSolicitado === '') {
            $subtipoConvocatoriaSolicitado = 'sindicatos';
        }

        $subtipoConvocatoria = (
            $tipoConvocatoria !== '' &&
            in_array(
                $subtipoConvocatoriaSolicitado,
                $subtiposPermitidos[$tipoConvocatoria] ?? [],
                true
            )
        ) ? $subtipoConvocatoriaSolicitado : '';

        $buscar = trim((string)($_GET['buscar'] ?? ''));
        $estatusFiltro = '';
        $fechaFiltroSolicitada = trim((string)($_GET['fecha'] ?? ''));
        $fechaFiltroObjeto = DateTime::createFromFormat('!Y-m-d', $fechaFiltroSolicitada);
        $fechaFiltro = (
            $fechaFiltroObjeto &&
            $fechaFiltroObjeto->format('Y-m-d') === $fechaFiltroSolicitada
        ) ? $fechaFiltroSolicitada : '';
        $anioSolicitado = (int)($_GET['anio'] ?? 0);
        $mesSolicitado = (int)($_GET['mes'] ?? 0);

        $mesActualCalendario = (int)date('n');
        $anioCicloActual = $mesActualCalendario >= 9
            ? (int)date('Y')
            : (int)date('Y') - 1;

        $anioSeleccionado = (
            $anioSolicitado >= 2000 &&
            $anioSolicitado <= 2100
        ) ? $anioSolicitado : $anioCicloActual;

        $mesSeleccionado = (
            $mesSolicitado >= 1 &&
            $mesSolicitado <= 12
        ) ? $mesSolicitado : 0;
        $categoriaSolicitada = (string)($_GET['categoria'] ?? '');
        $categoriaFiltro = in_array($categoriaSolicitada, ['', 'IMJUVE'], true)
            ? $categoriaSolicitada
            : '';

        $estadoFiltro = $territorioSeleccionado
            ? (int)$territorioSeleccionado['id']
            : 0;

        $convocatoriasRecientes = $territorioSeleccionado
            ? $modelo->obtenerRecientesPorTerritorio($estadoFiltro, 12)
            : [];

        $aniosConvocatorias = [];
        $resumenMensualConvocatorias = [];
        $mesesVisiblesConvocatorias = [];
        $mostrarSelectorMes = false;

        if (
            $territorioSeleccionado &&
            $tipoConvocatoria !== '' &&
            $subtipoConvocatoria !== ''
        ) {
            $aniosConvocatorias = $modelo->obtenerAniosDisponiblesPorClasificacion(
                $estadoFiltro,
                $tipoConvocatoria,
                $subtipoConvocatoria
            );

            if (!in_array($anioCicloActual, $aniosConvocatorias, true)) {
                $aniosConvocatorias[] = $anioCicloActual;
            }

            rsort($aniosConvocatorias, SORT_NUMERIC);

            if (!in_array($anioSeleccionado, $aniosConvocatorias, true)) {
                $anioSeleccionado = $anioCicloActual;
            }

            $mostrarSelectorMes =
                $mesSeleccionado === 0 &&
                $buscar === '';

            if ($mostrarSelectorMes) {
                /*
                 * Ventana móvil de 4 meses.
                 * De septiembre a noviembre se conserva Septiembre-Diciembre.
                 * En diciembre avanza a Octubre-Enero y a partir de ahí
                 * continúa desplazándose un mes conforme termina cada periodo.
                 */
                $mesesDesdeSeptiembre = $mesActualCalendario >= 9
                    ? $mesActualCalendario - 9
                    : $mesActualCalendario + 3;
                $desplazamientoVentana = max(0, $mesesDesdeSeptiembre - 2);

                $inicioVentana = (new DateTimeImmutable(
                    sprintf('%04d-09-01', $anioSeleccionado)
                ))->modify('+' . $desplazamientoVentana . ' months');

                $resumenesPorAnio = [];

                for ($indiceMes = 0; $indiceMes < 4; $indiceMes++) {
                    $fechaVentana = $inicioVentana->modify(
                        '+' . $indiceMes . ' months'
                    );
                    $anioVentana = (int)$fechaVentana->format('Y');
                    $mesVentana = (int)$fechaVentana->format('n');

                    if (!isset($resumenesPorAnio[$anioVentana])) {
                        $resumenesPorAnio[$anioVentana] =
                            $modelo->obtenerResumenMensual(
                                $estadoFiltro,
                                $tipoConvocatoria,
                                $subtipoConvocatoria,
                                $anioVentana
                            );
                    }

                    $mesesVisiblesConvocatorias[] = [
                        'anio' => $anioVentana,
                        'mes' => $mesVentana,
                        'datos' => $resumenesPorAnio[$anioVentana][$mesVentana] ?? [
                            'total' => 0,
                            'convocatorias' => []
                        ]
                    ];
                }

                $resumenMensualConvocatorias = $resumenesPorAnio;
            }
        }

        $convocatorias = (
            $territorioSeleccionado &&
            $tipoConvocatoria !== '' &&
            $subtipoConvocatoria !== '' &&
            (!$mostrarSelectorMes)
        )
            ? $modelo->obtenerListado(
                $buscar,
                $estadoFiltro,
                $estatusFiltro,
                $categoriaFiltro,
                $tipoConvocatoria,
                $subtipoConvocatoria,
                $mesSeleccionado > 0 ? $anioSeleccionado : 0,
                $mesSeleccionado,
                $fechaFiltro
            )
            : [];

        $mensajeExito = $_SESSION['mensaje_convocatoria'] ?? '';
        $mensajeError = $_SESSION['error_convocatoria'] ?? '';
        $erroresFormulario = $_SESSION['errores_convocatoria'] ?? [];
        $datosFormulario = $_SESSION['datos_convocatoria'] ?? [];
        $modalAbierto = $_SESSION['modal_convocatoria'] ?? '';

        if ($mensajeError === '' && !empty($erroresFormulario)) {
            $mensajeError =
                'Completa los campos obligatorios y revisa los datos antes de guardar la convocatoria.';
        }

        unset(
            $_SESSION['mensaje_convocatoria'],
            $_SESSION['error_convocatoria'],
            $_SESSION['errores_convocatoria'],
            $_SESSION['datos_convocatoria'],
            $_SESSION['modal_convocatoria']
        );

        $puedeGestionarConvocatorias =
            tienePermiso('convocatorias.gestionar');
        $tituloPagina = $puedeGestionarConvocatorias
            ? 'Gestión de Convocatorias'
            : 'Convocatorias';
        $subtituloPagina = $puedeGestionarConvocatorias
            ? 'Administra publicaciones y su vigencia territorial.'
            : 'Consulta publicaciones y su vigencia territorial.';
        $opcionActiva = 'convocatorias';

        require_once __DIR__ . '/../views/layout/dashboard_head.php';
        require_once __DIR__ . '/../views/layout/sidebar.php';
        require_once __DIR__ . '/../views/layout/topbar.php';
        require_once __DIR__ . '/../views/convocatorias/index.php';
        require_once __DIR__ . '/../views/layout/dashboard_footer.php';
    }

    public function publicacionesDelDia()
    {
        $this->validarPermiso('convocatorias.ver');

        if (
            strcasecmp(
                trim((string)($_SESSION['rol'] ?? '')),
                'Marketing'
            ) !== 0
        ) {
            header('Location: ' . BASE_URL . 'index.php?controller=home&action=index');
            exit;
        }

        $fechaSolicitada = trim((string)($_GET['fecha'] ?? date('Y-m-d')));
        $fechaObjeto = DateTime::createFromFormat('!Y-m-d', $fechaSolicitada);
        $fechaValida = $fechaObjeto &&
            $fechaObjeto->format('Y-m-d') === $fechaSolicitada;

        $fechaPublicaciones = $fechaValida
            ? $fechaSolicitada
            : date('Y-m-d');

        $modelo = new ConvocatoriaModel();
        $modelo->desactivarConvocatoriasVencidas();

        $publicacionesDelDia = $modelo
            ->obtenerPublicacionesPorFechaDashboard($fechaPublicaciones);

        $fechaPublicacionesTexto = date(
            'd/m/Y',
            strtotime($fechaPublicaciones)
        );

        $tituloPagina = 'Publicaciones del día';
        $subtituloPagina =
            'Convocatorias publicadas el ' . $fechaPublicacionesTexto . '.';
        $opcionActiva = 'inicio';

        require_once __DIR__ . '/../views/layout/dashboard_head.php';
        require_once __DIR__ . '/../views/layout/sidebar.php';
        require_once __DIR__ . '/../views/layout/topbar.php';
        require_once __DIR__ . '/../views/convocatorias/publicaciones_dia.php';
        require_once __DIR__ . '/../views/layout/dashboard_footer.php';
    }

    public function reportes()
    {
        $this->validarPermiso('convocatorias.ver');
        $this->validarPermiso('reportes.ver');
        $this->validarPermiso('reportes.convocatorias');

        header(
            'Location: ' .
            BASE_URL .
            'index.php?controller=reporte&action=index'
        );
        exit;
    }

    public function guardar()
    {
        $this->validarPermiso('convocatorias.crear');
        $this->validarMetodoPost();

        $modelo = new ConvocatoriaModel();
        $territorioId = (int)($_POST['territorio_id'] ?? 0);
        $tipoConvocatoria = strtolower(trim((string)($_POST['tipo'] ?? '')));
        $subtipoConvocatoria = strtolower(trim((string)($_POST['subtipo'] ?? '')));
        $esChihuahua = $this->esTerritorioChihuahua($modelo, $territorioId);
        $datos = $this->limpiarDatos($_POST);
        $estadosIds = $this->limpiarEstados($_POST['estados'] ?? []);

        if ($territorioId > 0 && !in_array($territorioId, $estadosIds, true)) {
            $estadosIds[] = $territorioId;
        }

        $errores = $this->validarDatos(
            $datos,
            $estadosIds,
            true,
            $subtipoConvocatoria === 'inscripciones-abiertas'
        );

        if (!$this->esClasificacionValida($tipoConvocatoria, $subtipoConvocatoria, $esChihuahua)) {
            $errores[] = 'La opción de convocatoria seleccionada no es válida.';
        }

        $imagen = $this->procesarImagen('');

        if ($imagen['error'] !== '') {
            $errores[] = $imagen['error'];
        }

        if (!empty($errores)) {
            if (!empty($imagen['nueva'])) {
                $this->eliminarImagen($imagen['ruta']);
            }

            $datos['estados_ids'] = $estadosIds;
            $datos['territorio_id'] = $territorioId;
            $datos['tipo'] = $tipoConvocatoria;
            $datos['subtipo'] = $subtipoConvocatoria;
            $this->volverConErrores('crear', $errores, $datos);
        }

        $datos['imagen'] = $imagen['ruta'];
        $datos['usuario_id'] = (int)$_SESSION['usuario_id'];
        $datos['tipo_convocatoria'] = $tipoConvocatoria;
        $datos['subtipo_convocatoria'] = $subtipoConvocatoria;

        if ($modelo->crear($datos, $estadosIds)) {
            $_SESSION['mensaje_convocatoria'] = 'Convocatoria registrada correctamente.';
        } else {
            if (!empty($imagen['nueva'])) {
                $this->eliminarImagen($imagen['ruta']);
            }

            $_SESSION['error_convocatoria'] = 'No fue posible registrar la convocatoria.';
        }

        $this->redirigir($territorioId, $tipoConvocatoria, $subtipoConvocatoria);
    }

    public function actualizar()
    {
        $this->validarPermiso('convocatorias.editar');
        $this->validarMetodoPost();

        $modelo = new ConvocatoriaModel();
        $territorioId = (int)($_POST['territorio_id'] ?? 0);
        $tipoConvocatoria = strtolower(trim((string)($_POST['tipo'] ?? '')));
        $subtipoConvocatoria = strtolower(trim((string)($_POST['subtipo'] ?? '')));
        $esChihuahua = $this->esTerritorioChihuahua($modelo, $territorioId);
        $id = (int)($_POST['id'] ?? 0);
        $convocatoriaOriginal = $modelo->buscarPorId($id);

        if (!$convocatoriaOriginal) {
            $_SESSION['error_convocatoria'] = 'La convocatoria seleccionada no existe.';
            $this->redirigir();
        }

        if (
            !$this->esClasificacionValida($tipoConvocatoria, $subtipoConvocatoria, $esChihuahua) ||
            (string)($convocatoriaOriginal['tipo_convocatoria'] ?? '') !== $tipoConvocatoria ||
            (string)($convocatoriaOriginal['subtipo_convocatoria'] ?? '') !== $subtipoConvocatoria
        ) {
            $_SESSION['error_convocatoria'] = 'La convocatoria no pertenece a la opción seleccionada.';
            $this->redirigir($territorioId, $tipoConvocatoria, $subtipoConvocatoria);
        }

        $datos = $this->limpiarDatos($_POST);
        $estadosIds = $this->limpiarEstados($_POST['estados'] ?? []);
        $errores = $this->validarDatos(
            $datos,
            $estadosIds,
            false,
            $subtipoConvocatoria === 'inscripciones-abiertas'
        );
        $imagen = $this->procesarImagen((string)$convocatoriaOriginal['imagen']);

        if ($imagen['error'] !== '') {
            $errores[] = $imagen['error'];
        }

        if (!empty($errores)) {
            if (!empty($imagen['nueva'])) {
                $this->eliminarImagen($imagen['ruta']);
            }

            $datos['id'] = $id;
            $datos['imagen'] = $convocatoriaOriginal['imagen'];
            $datos['estados_ids'] = $estadosIds;
            $datos['territorio_id'] = $territorioId;
            $datos['tipo'] = $tipoConvocatoria;
            $datos['subtipo'] = $subtipoConvocatoria;
            $this->volverConErrores('editar', $errores, $datos);
        }

        $datos['imagen'] = $imagen['ruta'];
        $datos['usuario_id'] = (int)$_SESSION['usuario_id'];
        $datos['tipo_convocatoria'] = $tipoConvocatoria;
        $datos['subtipo_convocatoria'] = $subtipoConvocatoria;

        $fechaInicioOriginal = trim((string)($convocatoriaOriginal['fecha_inicio'] ?? ''));
        $fechaTerminoOriginal = trim((string)($convocatoriaOriginal['fecha_termino'] ?? ''));
        $fechaInicioNueva = trim((string)($datos['fecha_inicio'] ?? ''));
        $fechaTerminoNueva = trim((string)($datos['fecha_termino'] ?? ''));
        $estadoNuevo = (int)($datos['estado'] ?? 1);

        $cambioFechas =
            $fechaInicioOriginal !== $fechaInicioNueva ||
            $fechaTerminoOriginal !== $fechaTerminoNueva;

        if ($modelo->actualizar($id, $datos, $estadosIds)) {
            $_SESSION['mensaje_convocatoria'] = 'Convocatoria actualizada correctamente.';

            /*
             * Cuando se entra desde una notificación o desde el dashboard,
             * la convocatoria se abre mediante un filtro por su título.
             * Si el título cambia, conservar el filtro anterior provoca que
             * la convocatoria "desaparezca" hasta abandonar y volver a entrar.
             * Actualizamos únicamente ese filtro automático para mantener
             * visible la misma convocatoria después de guardar.
             */
            $retornoQuery = trim((string)($_POST['retorno_query'] ?? ''));

            if ($retornoQuery !== '') {
                $retornoContexto = [];
                parse_str($retornoQuery, $retornoContexto);

                $buscarRetorno = trim((string)($retornoContexto['buscar'] ?? ''));
                $tituloOriginal = trim((string)($convocatoriaOriginal['titulo'] ?? ''));
                $tituloNuevo = trim((string)($datos['titulo'] ?? ''));

                if (
                    $buscarRetorno !== '' &&
                    $tituloOriginal !== '' &&
                    mb_strtolower($buscarRetorno, 'UTF-8') ===
                        mb_strtolower($tituloOriginal, 'UTF-8')
                ) {
                    $retornoContexto['buscar'] = $tituloNuevo;
                }

                /*
                 * Si existía un filtro de fecha y la nueva vigencia ya no
                 * contiene esa fecha, quitamos solamente ese filtro para
                 * evitar un listado vacío después de editar.
                 */
                $fechaRetorno = trim((string)($retornoContexto['fecha'] ?? ''));

                if ($cambioFechas && $fechaRetorno !== '') {
                    $fueraDeNuevaVigencia =
                        ($fechaInicioNueva !== '' && $fechaRetorno < $fechaInicioNueva) ||
                        ($fechaTerminoNueva !== '' && $fechaRetorno > $fechaTerminoNueva);

                    if ($fueraDeNuevaVigencia) {
                        unset($retornoContexto['fecha']);
                    }
                }

                $_POST['retorno_query'] = http_build_query($retornoContexto);
            }

            if ($cambioFechas || $estadoNuevo === 0) {
                $modelo->eliminarNotificacionesVencimiento($id);
            }

            if (
                !empty($imagen['nueva']) &&
                !empty($convocatoriaOriginal['imagen']) &&
                $convocatoriaOriginal['imagen'] !== $imagen['ruta']
            ) {
                $this->eliminarImagen((string)$convocatoriaOriginal['imagen']);
            }
        } else {
            if (!empty($imagen['nueva'])) {
                $this->eliminarImagen($imagen['ruta']);
            }

            $_SESSION['error_convocatoria'] = 'No fue posible actualizar la convocatoria.';
        }

        $this->redirigir($territorioId, $tipoConvocatoria, $subtipoConvocatoria);
    }

    public function cambiarEstado()
    {
        $this->validarPermiso('convocatorias.cambiar_estado');
        $this->validarMetodoPost();

        $modelo = new ConvocatoriaModel();
        $territorioId = (int)($_POST['territorio_id'] ?? 0);
        $tipoConvocatoria = strtolower(trim((string)($_POST['tipo'] ?? '')));
        $subtipoConvocatoria = strtolower(trim((string)($_POST['subtipo'] ?? '')));
        $esChihuahua = $this->esTerritorioChihuahua($modelo, $territorioId);
        $id = (int)($_POST['id'] ?? 0);
        $estado = in_array((string)($_POST['estado'] ?? ''), ['0', '1'], true)
            ? (int)$_POST['estado']
            : null;

        $convocatoria = $id > 0 ? $modelo->buscarPorId($id) : null;

        if ($id <= 0 || $estado === null || !$convocatoria) {
            $_SESSION['error_convocatoria'] = 'La acción seleccionada no es válida.';
            $this->redirigir();
        }

        if (
            !$this->esClasificacionValida($tipoConvocatoria, $subtipoConvocatoria, $esChihuahua) ||
            (string)($convocatoria['tipo_convocatoria'] ?? '') !== $tipoConvocatoria ||
            (string)($convocatoria['subtipo_convocatoria'] ?? '') !== $subtipoConvocatoria
        ) {
            $_SESSION['error_convocatoria'] = 'La convocatoria no pertenece a la opción seleccionada.';
            $this->redirigir($territorioId, $tipoConvocatoria, $subtipoConvocatoria);
        }

        if (
            $estado === 1 &&
            !empty($convocatoria['fecha_inicio']) &&
            $convocatoria['fecha_inicio'] > date('Y-m-d')
        ) {
            $_SESSION['error_convocatoria'] =
                'La convocatoria está programada y se activará automáticamente el ' .
                date('d/m/Y', strtotime((string)$convocatoria['fecha_inicio'])) . '.';
            $this->redirigir($territorioId, $tipoConvocatoria, $subtipoConvocatoria);
        }

        if (
            $estado === 1 &&
            !empty($convocatoria['fecha_termino']) &&
            $convocatoria['fecha_termino'] < date('Y-m-d')
        ) {
            $_SESSION['error_convocatoria'] = 'No se puede activar la convocatoria porque su fecha ya expiró. Necesitas cambiar la fecha de término antes de activarla.';
            $this->redirigir($territorioId, $tipoConvocatoria, $subtipoConvocatoria);
        }

        if ($modelo->cambiarEstado($id, $estado, (int)$_SESSION['usuario_id'])) {
            if ($estado === 0) {
                $modelo->eliminarNotificacionesVencimiento($id);
            }

            $_SESSION['mensaje_convocatoria'] = $estado === 1
                ? 'Convocatoria activada correctamente.'
                : 'Convocatoria desactivada correctamente.';
        } else {
            $_SESSION['error_convocatoria'] = 'No fue posible actualizar el estado de la convocatoria.';
        }

        $this->redirigir($territorioId, $tipoConvocatoria, $subtipoConvocatoria);
    }

    public function descargarImagen()
    {
        $this->validarPermiso('convocatorias.descargar');

        $modelo = new ConvocatoriaModel();
        $id = (int)($_GET['id'] ?? 0);
        $convocatoria = $modelo->buscarPorId($id);

        if (!$convocatoria || empty($convocatoria['imagen'])) {
            http_response_code(404);
            echo 'Imagen no encontrada.';
            return;
        }

        $rutaRelativa = ltrim(str_replace('\\', '/', (string)$convocatoria['imagen']), '/');

        if (
            strpos($rutaRelativa, 'public/uploads/convocatorias/') !== 0 ||
            strpos($rutaRelativa, '..') !== false
        ) {
            http_response_code(400);
            echo 'Ruta no válida.';
            return;
        }

        $ruta = ROOT_PATH . '/' . $rutaRelativa;

        if (!is_file($ruta)) {
            http_response_code(404);
            echo 'Imagen no encontrada.';
            return;
        }

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($ruta) . '"');
        header('Content-Length: ' . filesize($ruta));
        readfile($ruta);
        exit;
    }

    public function detalle()
    {
        $this->validarPermiso('convocatorias.ver');

        $modelo = new ConvocatoriaModel();
        $id = (int)($_GET['id'] ?? 0);
        $convocatoria = $modelo->buscarPorId($id);

        if (!$convocatoria) {
            http_response_code(404);
            echo 'Convocatoria no encontrada.';
            return;
        }

        if (!$this->convocatoriaVisibleParaUsuario($convocatoria)) {
            http_response_code(403);
            echo 'No tienes acceso a esta convocatoria.';
            return;
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            ['ok' => true, 'convocatoria' => $convocatoria],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        exit;
    }

    private function tipoAsignacionTerritorialUsuario()
    {
        $rolId = (int)($_SESSION['rol_id'] ?? 0);

        /*
         * Administrador y perfiles con gestión de convocatorias trabajan con
         * cobertura global. Los perfiles de consulta heredan únicamente el
         * alcance territorial que corresponde a su función operativa.
         */
        $esMarketingSistema = strcasecmp(
            trim((string)($_SESSION['rol'] ?? '')),
            'Marketing'
        ) === 0;

        if (
            $rolId === 1 ||
            $esMarketingSistema ||
            tienePermiso('convocatorias.gestionar')
        ) {
            return null;
        }

        if ($rolId === 6) {
            return 'CUENTA_CLAVE';
        }

        if ($rolId === 4) {
            return 'ANALISTA_DATOS';
        }

        if ($rolId === 3) {
            return 'ASESOR';
        }

        return 'SIN_ALCANCE';
    }

    private function obtenerEstadosDisponibles($estadosGenerales)
    {
        $tipoAsignacion = $this->tipoAsignacionTerritorialUsuario();

        if ($tipoAsignacion === null) {
            return $estadosGenerales;
        }

        if ($tipoAsignacion === 'SIN_ALCANCE') {
            return [];
        }

        $modeloTerritorio = new TerritorioModel();

        return $modeloTerritorio->obtenerEstadosAsignadosUsuario(
            (int)($_SESSION['usuario_id'] ?? 0),
            $tipoAsignacion
        );
    }

    private function obtenerIdsTerritoriosPermitidos()
    {
        $tipoAsignacion = $this->tipoAsignacionTerritorialUsuario();

        if ($tipoAsignacion === null) {
            return null;
        }

        if ($tipoAsignacion === 'SIN_ALCANCE') {
            return [];
        }

        $modeloTerritorio = new TerritorioModel();
        $estados = $modeloTerritorio->obtenerEstadosAsignadosUsuario(
            (int)($_SESSION['usuario_id'] ?? 0),
            $tipoAsignacion
        );

        return array_map(
            static function ($estado) {
                return (int)($estado['id'] ?? 0);
            },
            $estados
        );
    }

    private function usuarioPuedeConsultarTerritorio($territorioId)
    {
        $territorioId = (int)$territorioId;

        if ($territorioId <= 0) {
            return false;
        }

        $permitidos = $this->obtenerIdsTerritoriosPermitidos();

        if ($permitidos === null) {
            return true;
        }

        return in_array($territorioId, $permitidos, true);
    }

    private function convocatoriaVisibleParaUsuario($convocatoria)
    {
        $permitidos = $this->obtenerIdsTerritoriosPermitidos();

        if ($permitidos === null) {
            return true;
        }

        $estadosConvocatoria = array_map(
            'intval',
            is_array($convocatoria['estados_ids'] ?? null)
                ? $convocatoria['estados_ids']
                : []
        );

        return !empty(array_intersect($permitidos, $estadosConvocatoria));
    }

    private function esClasificacionValida($tipo, $subtipo, $esChihuahua = false)
    {
        $subtiposPermitidos = [
            'titulacion' => [
                'ejecutivas',
                'experiencia-laboral',
                'inscripciones-abiertas'
            ],
            'bachillerato' => [
                'bachillerato-2-anos',
                'bachillerato-286',
                'ingles',
                'inscripciones-abiertas'
            ]
        ];

        if ($esChihuahua) {
            $subtiposPermitidos['sindicatos'] = ['sindicatos'];
        }

        return isset($subtiposPermitidos[$tipo]) &&
            in_array($subtipo, $subtiposPermitidos[$tipo], true);
    }

    private function esTerritorioChihuahua($modelo, $territorioId)
    {
        if ((int)$territorioId <= 0) {
            return false;
        }

        $territorio = $this->obtenerTerritorioSeleccionado(
            $modelo->obtenerEstados(),
            (int)$territorioId
        );

        return $territorio &&
            strcasecmp(trim((string)($territorio['nombre'] ?? '')), 'Chihuahua') === 0;
    }

    private function obtenerTerritorioSeleccionado($estados, $territorioId)
    {
        if ($territorioId <= 0) {
            return null;
        }

        foreach ($estados as $estado) {
            if ((int)($estado['id'] ?? 0) === $territorioId) {
                return $estado;
            }
        }

        return null;
    }

    private function limpiarDatos($origen)
    {
        return [
            'titulo' => trim((string)($origen['titulo'] ?? '')),
            'enlace_registro' => trim((string)($origen['enlace_registro'] ?? '')),
            'fecha_inicio' => trim((string)($origen['fecha_inicio'] ?? '')),
            'fecha_termino' => trim((string)($origen['fecha_termino'] ?? '')),
            'estado' => in_array((string)($origen['estado'] ?? ''), ['0', '1'], true)
                ? (int)$origen['estado']
                : 1
        ];
    }

    private function limpiarEstados($estados)
    {
        if (!is_array($estados)) {
            return [];
        }

        $ids = array_map('intval', $estados);
        $ids = array_filter($ids, static function ($id) {
            return $id > 0;
        });

        return array_values(array_unique($ids));
    }

    private function validarDatos(
        $datos,
        $estadosIds,
        $requiereImagen,
        $fechasOpcionales = false
    ) {
        $errores = [];

        if ($datos['titulo'] === '') {
            $errores[] = 'El título es obligatorio.';
        }

        if (
            $datos['enlace_registro'] !== '' &&
            (
                filter_var($datos['enlace_registro'], FILTER_VALIDATE_URL) === false ||
                !in_array(
                    strtolower((string)parse_url($datos['enlace_registro'], PHP_URL_SCHEME)),
                    ['http', 'https'],
                    true
                )
            )
        ) {
            $errores[] = 'El enlace de registro debe ser una URL válida con http o https.';
        }

        if ($fechasOpcionales) {
            if (
                $datos['fecha_inicio'] !== '' &&
                !$this->fechaValida($datos['fecha_inicio'])
            ) {
                $errores[] = 'La fecha de inicio debe ser válida.';
            }

            if (
                $datos['fecha_termino'] !== '' &&
                !$this->fechaValida($datos['fecha_termino'])
            ) {
                $errores[] = 'La fecha de término debe ser válida.';
            }

            if (
                $datos['fecha_inicio'] === '' &&
                $datos['fecha_termino'] !== ''
            ) {
                $errores[] =
                    'Para registrar una fecha de término debes indicar una fecha de inicio.';
            }
        } else {
            if (!$this->fechaValida($datos['fecha_inicio'])) {
                $errores[] = 'La fecha de inicio es obligatoria y debe ser válida.';
            }

            if (!$this->fechaValida($datos['fecha_termino'])) {
                $errores[] = 'La fecha de término es obligatoria y debe ser válida.';
            }
        }

        if (
            $this->fechaValida($datos['fecha_inicio']) &&
            $this->fechaValida($datos['fecha_termino']) &&
            $datos['fecha_termino'] < $datos['fecha_inicio']
        ) {
            $errores[] = 'La fecha de término debe ser igual o posterior a la fecha de inicio.';
        }

        if (empty($estadosIds)) {
            $errores[] = 'Selecciona al menos un estado.';
        }

        if (
            $requiereImagen &&
            (
                !isset($_FILES['imagen']) ||
                $_FILES['imagen']['error'] === UPLOAD_ERR_NO_FILE
            )
        ) {
            $errores[] = 'La imagen es obligatoria al crear una convocatoria.';
        }

        return $errores;
    }

    private function fechaValida($fecha)
    {
        if ($fecha === '') {
            return false;
        }

        $objeto = DateTime::createFromFormat('Y-m-d', $fecha);

        return $objeto && $objeto->format('Y-m-d') === $fecha;
    }

    private function procesarImagen($imagenActual)
    {
        if (
            !isset($_FILES['imagen']) ||
            $_FILES['imagen']['error'] === UPLOAD_ERR_NO_FILE
        ) {
            return [
                'ruta' => $imagenActual,
                'error' => '',
                'nueva' => false
            ];
        }

        if ($_FILES['imagen']['error'] !== UPLOAD_ERR_OK) {
            return [
                'ruta' => $imagenActual,
                'error' => 'No fue posible cargar la imagen.',
                'nueva' => false
            ];
        }

        if ($_FILES['imagen']['size'] > 5 * 1024 * 1024) {
            return [
                'ruta' => $imagenActual,
                'error' => 'La imagen no debe superar 5 MB.',
                'nueva' => false
            ];
        }

        $rutaTemporal = $_FILES['imagen']['tmp_name'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $tipo = $finfo->file($rutaTemporal);
        $extensiones = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp'
        ];

        if (!isset($extensiones[$tipo])) {
            return [
                'ruta' => $imagenActual,
                'error' => 'La imagen debe ser JPG, PNG o WEBP.',
                'nueva' => false
            ];
        }

        $carpetaDestino = ROOT_PATH . '/public/uploads/convocatorias';

        if (!is_dir($carpetaDestino)) {
            mkdir($carpetaDestino, 0775, true);
        }

        $nombreArchivo =
            'convocatoria_' .
            date('YmdHis') .
            '_' .
            bin2hex(random_bytes(6)) .
            '.' .
            $extensiones[$tipo];

        $rutaDestino = $carpetaDestino . '/' . $nombreArchivo;
        $rutaPublica = 'public/uploads/convocatorias/' . $nombreArchivo;

        if (!move_uploaded_file($rutaTemporal, $rutaDestino)) {
            return [
                'ruta' => $imagenActual,
                'error' => 'No fue posible guardar la imagen.',
                'nueva' => false
            ];
        }

        return [
            'ruta' => $rutaPublica,
            'error' => '',
            'nueva' => true
        ];
    }

    private function eliminarImagen($ruta)
    {
        $ruta = ltrim(str_replace('\\', '/', trim((string)$ruta)), '/');

        if (
            $ruta === '' ||
            strpos($ruta, '..') !== false ||
            strpos($ruta, 'public/uploads/convocatorias/') !== 0
        ) {
            return false;
        }

        $archivo = ROOT_PATH . '/' . $ruta;

        return is_file($archivo) ? @unlink($archivo) : false;
    }

    private function validarPermiso($codigo)
    {
        if (!isset($_SESSION['usuario_id'])) {
            header('Location: ' . BASE_URL . 'index.php?controller=login&action=mostrarLogin');
            exit;
        }

        if (!tienePermiso($codigo)) {
            http_response_code(403);
            echo 'No tienes permiso para realizar esta operación.';
            exit;
        }
    }

    private function validarMetodoPost()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo 'Método no permitido.';
            exit;
        }
    }

    private function volverConErrores($modal, $errores, $datos)
    {
        $_SESSION['errores_convocatoria'] = $errores;
        $_SESSION['datos_convocatoria'] = $datos;
        $_SESSION['modal_convocatoria'] = $modal;

        $this->redirigir(
            (int)($datos['territorio_id'] ?? 0),
            (string)($datos['tipo'] ?? ''),
            (string)($datos['subtipo'] ?? '')
        );
    }

    private function redirigir($territorioId = 0, $tipo = '', $subtipo = '')
    {
        $retornoContexto = [];
        $retornoQuery = trim((string)($_POST['retorno_query'] ?? ''));

        if ($retornoQuery !== '') {
            parse_str($retornoQuery, $retornoContexto);

            if (
                (string)($retornoContexto['controller'] ?? '') !== 'convocatoria' ||
                (string)($retornoContexto['action'] ?? '') !== 'index'
            ) {
                $retornoContexto = [];
            }
        }

        if ((int)$territorioId <= 0) {
            $territorioId = (int)($retornoContexto['territorio_id'] ?? 0);
        }

        if ($tipo === '') {
            $tipo = strtolower(trim((string)($retornoContexto['tipo'] ?? '')));
        }

        if ($subtipo === '') {
            $subtipo = strtolower(trim((string)($retornoContexto['subtipo'] ?? '')));
        }

        $url = BASE_URL . 'index.php?controller=convocatoria&action=index';

        if ((int)$territorioId > 0) {
            $url .= '&territorio_id=' . (int)$territorioId;
        }

        if ($tipo !== '') {
            $url .= '&tipo=' . rawurlencode($tipo);
        }

        if ($subtipo !== '') {
            $url .= '&subtipo=' . rawurlencode($subtipo);
        }

        $anioContexto = (int)(
            $_POST['anio'] ??
            ($retornoContexto['anio'] ?? ($_GET['anio'] ?? 0))
        );
        $mesContexto = (int)(
            $_POST['mes'] ??
            ($retornoContexto['mes'] ?? ($_GET['mes'] ?? 0))
        );
        $vistaContexto = (string)(
            $_POST['vista'] ??
            ($retornoContexto['vista'] ?? ($_GET['vista'] ?? ''))
        );
        $vistaContexto = in_array($vistaContexto, ['activas', 'historial'], true)
            ? $vistaContexto
            : '';

        $buscarContexto = trim((string)(
            $retornoContexto['buscar'] ??
            ($_POST['buscar'] ?? ($_GET['buscar'] ?? ''))
        ));
        $fechaContexto = trim((string)(
            $retornoContexto['fecha'] ??
            ($_POST['fecha'] ?? ($_GET['fecha'] ?? ''))
        ));
        $categoriaContexto = trim((string)(
            $retornoContexto['categoria'] ??
            ($_POST['categoria'] ?? ($_GET['categoria'] ?? ''))
        ));

        if ($anioContexto >= 2000 && $anioContexto <= 2100) {
            $url .= '&anio=' . $anioContexto;
        }

        if ($mesContexto >= 1 && $mesContexto <= 12) {
            $url .= '&mes=' . $mesContexto;
        }

        if ($vistaContexto !== '') {
            $url .= '&vista=' . rawurlencode($vistaContexto);
        }

        if ($buscarContexto !== '') {
            $url .= '&buscar=' . rawurlencode($buscarContexto);
        }

        if ($fechaContexto !== '') {
            $url .= '&fecha=' . rawurlencode($fechaContexto);
        }

        if ($categoriaContexto !== '') {
            $url .= '&categoria=' . rawurlencode($categoriaContexto);
        }

        header('Location: ' . $url);
        exit;
    }
}
