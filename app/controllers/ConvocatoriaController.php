<?php

require_once __DIR__ . '/../models/ConvocatoriaModel.php';
require_once __DIR__ . '/../helpers/PermissionHelper.php';

class ConvocatoriaController
{
    public function listadoFiltrado()
    {
        $this->validarPermiso('convocatorias.ver');

        $modelo = new ConvocatoriaModel();
        $buscar = trim((string)($_GET['buscar'] ?? ''));
        $estadoFiltro = (int)($_GET['territorio_id'] ?? ($_GET['estado_id'] ?? 0));
        $estatusFiltro = in_array((string)($_GET['estatus'] ?? ''), ['0', '1'], true)
            ? (string)$_GET['estatus']
            : '';

        $convocatorias = $modelo->obtenerListado(
            $buscar,
            $estadoFiltro,
            $estatusFiltro
        );

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
        $estados = $modelo->obtenerEstados();

        $territorioId = (int)($_GET['territorio_id'] ?? 0);
        $territorioSeleccionado = $this->obtenerTerritorioSeleccionado(
            $estados,
            $territorioId
        );

        $buscar = trim((string)($_GET['buscar'] ?? ''));
        $estatusFiltro = in_array((string)($_GET['estatus'] ?? ''), ['0', '1'], true)
            ? (string)$_GET['estatus']
            : '';

        $estadoFiltro = $territorioSeleccionado
            ? (int)$territorioSeleccionado['id']
            : 0;

        $convocatorias = $territorioSeleccionado
            ? $modelo->obtenerListado(
                $buscar,
                $estadoFiltro,
                $estatusFiltro
            )
            : [];

        $mensajeExito = $_SESSION['mensaje_convocatoria'] ?? '';
        $mensajeError = $_SESSION['error_convocatoria'] ?? '';
        $erroresFormulario = $_SESSION['errores_convocatoria'] ?? [];
        $datosFormulario = $_SESSION['datos_convocatoria'] ?? [];
        $modalAbierto = $_SESSION['modal_convocatoria'] ?? '';

        unset(
            $_SESSION['mensaje_convocatoria'],
            $_SESSION['error_convocatoria'],
            $_SESSION['errores_convocatoria'],
            $_SESSION['datos_convocatoria'],
            $_SESSION['modal_convocatoria']
        );

        $tituloPagina = 'Gestión de Convocatorias';
        $subtituloPagina = 'Administra publicaciones y su vigencia territorial.';
        $opcionActiva = 'convocatorias';

        require_once __DIR__ . '/../views/layout/dashboard_head.php';
        require_once __DIR__ . '/../views/layout/sidebar.php';
        require_once __DIR__ . '/../views/layout/topbar.php';
        require_once __DIR__ . '/../views/convocatorias/index.php';
        require_once __DIR__ . '/../views/layout/dashboard_footer.php';
    }

    public function guardar()
    {
        $this->validarPermiso('convocatorias.crear');
        $this->validarMetodoPost();

        $modelo = new ConvocatoriaModel();
        $territorioId = (int)($_POST['territorio_id'] ?? 0);
        $datos = $this->limpiarDatos($_POST);
        $estadosIds = $this->limpiarEstados($_POST['estados'] ?? []);

        if ($territorioId > 0 && !in_array($territorioId, $estadosIds, true)) {
            $estadosIds[] = $territorioId;
        }

        $errores = $this->validarDatos($datos, $estadosIds, true);

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
            $this->volverConErrores('crear', $errores, $datos);
        }

        $datos['imagen'] = $imagen['ruta'];
        $datos['usuario_id'] = (int)$_SESSION['usuario_id'];

        if ($modelo->crear($datos, $estadosIds)) {
            $_SESSION['mensaje_convocatoria'] = 'Convocatoria registrada correctamente.';
        } else {
            if (!empty($imagen['nueva'])) {
                $this->eliminarImagen($imagen['ruta']);
            }

            $_SESSION['error_convocatoria'] = 'No fue posible registrar la convocatoria.';
        }

        $this->redirigir($territorioId);
    }

    public function actualizar()
    {
        $this->validarPermiso('convocatorias.editar');
        $this->validarMetodoPost();

        $modelo = new ConvocatoriaModel();
        $territorioId = (int)($_POST['territorio_id'] ?? 0);
        $id = (int)($_POST['id'] ?? 0);
        $convocatoriaOriginal = $modelo->buscarPorId($id);

        if (!$convocatoriaOriginal) {
            $_SESSION['error_convocatoria'] = 'La convocatoria seleccionada no existe.';
            $this->redirigir();
        }

        $datos = $this->limpiarDatos($_POST);
        $estadosIds = $this->limpiarEstados($_POST['estados'] ?? []);
        $errores = $this->validarDatos($datos, $estadosIds, false);
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
            $this->volverConErrores('editar', $errores, $datos);
        }

        $datos['imagen'] = $imagen['ruta'];
        $datos['usuario_id'] = (int)$_SESSION['usuario_id'];

        if ($modelo->actualizar($id, $datos, $estadosIds)) {
            $_SESSION['mensaje_convocatoria'] = 'Convocatoria actualizada correctamente.';

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

        $this->redirigir($territorioId);
    }

    public function cambiarEstado()
    {
        $this->validarPermiso('convocatorias.cambiar_estado');
        $this->validarMetodoPost();

        $modelo = new ConvocatoriaModel();
        $territorioId = (int)($_POST['territorio_id'] ?? 0);
        $id = (int)($_POST['id'] ?? 0);
        $estado = in_array((string)($_POST['estado'] ?? ''), ['0', '1'], true)
            ? (int)$_POST['estado']
            : null;

        if ($id <= 0 || $estado === null || !$modelo->buscarPorId($id)) {
            $_SESSION['error_convocatoria'] = 'La acción seleccionada no es válida.';
            $this->redirigir();
        }

        if ($modelo->cambiarEstado($id, $estado, (int)$_SESSION['usuario_id'])) {
            $_SESSION['mensaje_convocatoria'] = $estado === 1
                ? 'Convocatoria activada correctamente.'
                : 'Convocatoria desactivada correctamente.';
        } else {
            $_SESSION['error_convocatoria'] = 'No fue posible actualizar el estado de la convocatoria.';
        }

        $this->redirigir($territorioId);
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

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            ['ok' => true, 'convocatoria' => $convocatoria],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        exit;
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

    private function validarDatos($datos, $estadosIds, $requiereImagen)
    {
        $errores = [];

        if ($datos['titulo'] === '') {
            $errores[] = 'El título es obligatorio.';
        }

        if (!$this->fechaValida($datos['fecha_inicio'])) {
            $errores[] = 'La fecha de inicio es obligatoria y debe ser válida.';
        }

        if (!$this->fechaValida($datos['fecha_termino'])) {
            $errores[] = 'La fecha de término es obligatoria y debe ser válida.';
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

        $this->redirigir();
    }

    private function redirigir($territorioId = 0)
    {
        $url = BASE_URL . 'index.php?controller=convocatoria&action=index';

        if ((int)$territorioId > 0) {
            $url .= '&territorio_id=' . (int)$territorioId;
        }

        header('Location: ' . $url);
        exit;
    }
}
