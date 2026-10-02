<?php

require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../models/UsuarioModel.php';
require_once __DIR__ . '/CorreoSalidaInstitucionalService.php';

class CorreoMarketingService
{
    private $connection;
    private $sender;

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
        $this->sender = new CorreoSalidaInstitucionalService();
    }

    public function resumen($usuarioId)
    {
        $usuarioId = (int)$usuarioId;

        if ($usuarioId <= 0 || !$this->tablaDisponible()) {
            return [
                'total' => 0,
                'enviados' => 0,
                'pendientes' => 0,
                'borradores' => 0
            ];
        }

        $sql = "SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN estado = 'ENVIADO' THEN 1 ELSE 0 END) AS enviados,
                    SUM(CASE WHEN estado = 'PENDIENTE' THEN 1 ELSE 0 END) AS pendientes,
                    SUM(CASE WHEN estado = 'BORRADOR' THEN 1 ELSE 0 END) AS borradores
                FROM correos_marketing
                WHERE usuario_id = ?";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('i', $usuarioId);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc() ?: [];

        return [
            'total' => (int)($fila['total'] ?? 0),
            'enviados' => (int)($fila['enviados'] ?? 0),
            'pendientes' => (int)($fila['pendientes'] ?? 0),
            'borradores' => (int)($fila['borradores'] ?? 0)
        ];
    }

    public function listar($usuarioId)
    {
        $usuarioId = (int)$usuarioId;

        if ($usuarioId <= 0 || !$this->tablaDisponible()) {
            return [];
        }

        $sql = "SELECT
                    id,
                    destinatario,
                    destinatario_nombre,
                    asunto,
                    tipo,
                    estado,
                    proveedor,
                    adjuntos_count,
                    adjuntos_nombres,
                    fecha_envio,
                    created_at
                FROM correos_marketing
                WHERE usuario_id = ?
                ORDER BY COALESCE(fecha_envio, created_at) DESC, id DESC
                LIMIT 250";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('i', $usuarioId);
        $stmt->execute();

        $salida = [];
        $resultado = $stmt->get_result();

        while ($fila = $resultado->fetch_assoc()) {
            $fecha = trim((string)($fila['fecha_envio'] ?? ''));
            if ($fecha === '') {
                $fecha = trim((string)($fila['created_at'] ?? ''));
            }

            $timestamp = $fecha !== '' ? strtotime($fecha) : false;

            $salida[] = [
                'id' => (int)($fila['id'] ?? 0),
                'destinatario' => trim((string)($fila['destinatario_nombre'] ?? '')) !== ''
                    ? (string)$fila['destinatario_nombre']
                    : (string)($fila['destinatario'] ?? ''),
                'correo' => (string)($fila['destinatario'] ?? ''),
                'asunto' => (string)($fila['asunto'] ?? ''),
                'tipo' => $this->etiquetaTipo($fila['tipo'] ?? ''),
                'tipo_codigo' => strtolower((string)($fila['tipo'] ?? 'general')),
                'fecha' => $timestamp !== false ? date('d/m/Y H:i', $timestamp) : '—',
                'estado' => $this->etiquetaEstado($fila['estado'] ?? ''),
                'estado_codigo' => strtolower((string)($fila['estado'] ?? 'pendiente')),
                'adjuntos_count' => (int)($fila['adjuntos_count'] ?? 0)
            ];
        }

        return $salida;
    }

    public function enviar($usuarioId, $datos, $archivos = null)
    {
        $usuarioId = (int)$usuarioId;
        $destinatario = strtolower(trim((string)($datos['destinatario'] ?? '')));
        $destinatarioNombre = trim((string)($datos['destinatario_nombre'] ?? ''));
        $asunto = trim((string)($datos['asunto'] ?? ''));
        $cuerpo = trim((string)($datos['cuerpo'] ?? ''));

        if ($usuarioId <= 0) {
            return $this->error('La sesión no está activa.', 401);
        }

        if ($destinatario === '' || !filter_var($destinatario, FILTER_VALIDATE_EMAIL)) {
            return $this->error('Ingresa un correo destinatario válido.', 422);
        }

        if ($asunto === '' || $cuerpo === '') {
            return $this->error('El asunto y el mensaje son obligatorios.', 422);
        }

        if (mb_strlen($asunto) > 255 || mb_strlen($cuerpo) > 20000) {
            return $this->error('El asunto o el mensaje supera el tamaño permitido.', 422);
        }

        $modeloUsuario = new UsuarioModel();
        $usuario = $modeloUsuario->buscarPorId($usuarioId);

        if (!$usuario) {
            return $this->error('No fue posible identificar al remitente.', 404);
        }

        $remitente = strtolower(trim((string)($usuario['correo'] ?? '')));
        if ($remitente === '' || !filter_var($remitente, FILTER_VALIDATE_EMAIL)) {
            return $this->error(
                'Tu usuario no tiene un correo institucional válido para realizar el envío.',
                422
            );
        }

        $preparacion = $this->prepararAdjuntos($archivos);
        if (!($preparacion['ok'] ?? false)) {
            return $preparacion;
        }

        $adjuntos = $preparacion['adjuntos'] ?? [];
        $rutasTemporales = $preparacion['rutas_temporales'] ?? [];
        $nombresAdjuntos = array_map(
            static fn($adjunto) => (string)($adjunto['nombre'] ?? ''),
            $adjuntos
        );

        $registroId = 0;

        try {
            if ($this->tablaDisponible()) {
                $registroId = $this->crearRegistroPendiente(
                    $usuarioId,
                    $destinatario,
                    $destinatarioNombre,
                    $asunto,
                    $cuerpo,
                    $nombresAdjuntos
                );
            }

            $envio = $this->sender->enviar([
                'remitente' => $remitente,
                'nombre_remitente' => trim(
                    (string)($usuario['nombre'] ?? '') . ' ' .
                    (string)($usuario['apellidos'] ?? '')
                ),
                'destinatario' => $destinatario,
                'nombre_destinatario' => $destinatarioNombre,
                'asunto' => $asunto,
                'cuerpo' => $cuerpo,
                'adjuntos' => $adjuntos
            ]);

            if (!($envio['ok'] ?? false)) {
                if ($registroId > 0) {
                    $this->actualizarRegistroError(
                        $registroId,
                        (string)($envio['mensaje'] ?? 'No fue posible realizar el envío.')
                    );
                }

                return $envio;
            }

            if ($registroId > 0) {
                $this->marcarEnviado(
                    $registroId,
                    (string)($envio['proveedor'] ?? ''),
                    !empty($envio['firma_incluida'])
                );
            }

            return [
                'ok' => true,
                'mensaje' => 'Correo enviado correctamente.',
                'correo_id' => $registroId,
                'proveedor' => (string)($envio['proveedor'] ?? ''),
                'firma_incluida' => (bool)($envio['firma_incluida'] ?? false),
                'adjuntos' => count($adjuntos)
            ];
        } finally {
            foreach ($rutasTemporales as $ruta) {
                if (is_string($ruta) && $ruta !== '' && is_file($ruta)) {
                    @unlink($ruta);
                }
            }
        }
    }

    private function prepararAdjuntos($archivos)
    {
        if (
            !is_array($archivos) ||
            !isset($archivos['name']) ||
            !is_array($archivos['name'])
        ) {
            return [
                'ok' => true,
                'adjuntos' => [],
                'rutas_temporales' => []
            ];
        }

        $totalArchivos = count($archivos['name']);
        if ($totalArchivos > 8) {
            return $this->error('Puedes adjuntar como máximo 8 archivos.', 422);
        }

        $directorio = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR .
            'storage' . DIRECTORY_SEPARATOR . 'mail' . DIRECTORY_SEPARATOR .
            'marketing' . DIRECTORY_SEPARATOR . 'tmp';

        if (
            !is_dir($directorio) &&
            !mkdir($directorio, 0775, true) &&
            !is_dir($directorio)
        ) {
            return $this->error('No fue posible preparar los archivos adjuntos.', 500);
        }

        $permitidas = [
            'pdf', 'doc', 'docx', 'xls', 'xlsx',
            'ppt', 'pptx', 'txt', 'csv', 'png', 'jpg', 'jpeg'
        ];

        $mimes = [
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'txt' => 'text/plain',
            'csv' => 'text/csv',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg'
        ];

        $adjuntos = [];
        $temporales = [];
        $tamanoTotal = 0;

        for ($i = 0; $i < $totalArchivos; $i++) {
            $error = (int)($archivos['error'][$i] ?? UPLOAD_ERR_NO_FILE);

            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            if ($error !== UPLOAD_ERR_OK) {
                $this->limpiarTemporales($temporales);
                return $this->error('No fue posible recibir uno de los archivos adjuntos.', 422);
            }

            $nombre = basename((string)($archivos['name'][$i] ?? 'archivo'));
            $tmp = (string)($archivos['tmp_name'][$i] ?? '');
            $tamano = (int)($archivos['size'][$i] ?? 0);
            $extension = strtolower((string)pathinfo($nombre, PATHINFO_EXTENSION));

            if (!in_array($extension, $permitidas, true)) {
                $this->limpiarTemporales($temporales);
                return $this->error('Uno de los archivos adjuntos tiene un formato no permitido.', 422);
            }

            if ($tamano <= 0 || $tamano > 12 * 1024 * 1024) {
                $this->limpiarTemporales($temporales);
                return $this->error('Cada archivo debe pesar como máximo 12 MB.', 422);
            }

            $tamanoTotal += $tamano;
            if ($tamanoTotal > 20 * 1024 * 1024) {
                $this->limpiarTemporales($temporales);
                return $this->error('Los archivos adjuntos no pueden superar 20 MB en total.', 422);
            }

            if ($tmp === '' || !is_uploaded_file($tmp)) {
                $this->limpiarTemporales($temporales);
                return $this->error('Uno de los archivos adjuntos no está disponible.', 422);
            }

            $destino = $directorio . DIRECTORY_SEPARATOR .
                date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '_' .
                preg_replace('/[^a-zA-Z0-9._-]+/', '_', $nombre);

            if (!move_uploaded_file($tmp, $destino)) {
                $this->limpiarTemporales($temporales);
                return $this->error('No fue posible preparar uno de los archivos adjuntos.', 500);
            }

            $temporales[] = $destino;
            $adjuntos[] = [
                'ruta' => $destino,
                'nombre' => $nombre,
                'mime' => $mimes[$extension] ?? 'application/octet-stream'
            ];
        }

        return [
            'ok' => true,
            'adjuntos' => $adjuntos,
            'rutas_temporales' => $temporales
        ];
    }

    private function crearRegistroPendiente(
        $usuarioId,
        $destinatario,
        $destinatarioNombre,
        $asunto,
        $cuerpo,
        $nombresAdjuntos
    ) {
        $adjuntosCount = count($nombresAdjuntos);
        $adjuntosNombres = json_encode(
            array_values($nombresAdjuntos),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        $tipo = 'GENERAL';
        $estado = 'PENDIENTE';

        $sql = "INSERT INTO correos_marketing (
                    usuario_id,
                    destinatario,
                    destinatario_nombre,
                    asunto,
                    cuerpo,
                    tipo,
                    estado,
                    adjuntos_count,
                    adjuntos_nombres,
                    created_at
                ) VALUES (?, ?, NULLIF(?, ''), ?, ?, ?, ?, ?, ?, NOW())";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param(
            'issssssiss',
            $usuarioId,
            $destinatario,
            $destinatarioNombre,
            $asunto,
            $cuerpo,
            $tipo,
            $estado,
            $adjuntosCount,
            $adjuntosNombres
        );
        $stmt->execute();

        return (int)$this->connection->insert_id;
    }

    private function marcarEnviado($registroId, $proveedor, $firmaIncluida)
    {
        $estado = 'ENVIADO';
        $firma = $firmaIncluida ? 1 : 0;

        $sql = "UPDATE correos_marketing
                SET estado = ?,
                    proveedor = NULLIF(?, ''),
                    firma_incluida = ?,
                    error_envio = NULL,
                    fecha_envio = NOW()
                WHERE id = ?";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('ssii', $estado, $proveedor, $firma, $registroId);
        $stmt->execute();
    }

    private function actualizarRegistroError($registroId, $mensaje)
    {
        $estado = 'PENDIENTE';
        $sql = "UPDATE correos_marketing
                SET estado = ?,
                    error_envio = ?
                WHERE id = ?";
        $stmt = $this->connection->prepare($sql);
        $stmt->bind_param('ssi', $estado, $mensaje, $registroId);
        $stmt->execute();
    }

    private function tablaDisponible()
    {
        $resultado = $this->connection->query(
            "SHOW TABLES LIKE 'correos_marketing'"
        );

        return $resultado && $resultado->num_rows > 0;
    }

    private function limpiarTemporales($rutas)
    {
        foreach ($rutas as $ruta) {
            if (is_string($ruta) && $ruta !== '' && is_file($ruta)) {
                @unlink($ruta);
            }
        }
    }

    private function etiquetaEstado($estado)
    {
        $mapa = [
            'ENVIADO' => 'Enviado',
            'PENDIENTE' => 'Pendiente',
            'BORRADOR' => 'Borrador'
        ];

        $codigo = strtoupper(trim((string)$estado));
        return $mapa[$codigo] ?? ($codigo !== '' ? ucfirst(strtolower($codigo)) : 'Pendiente');
    }

    private function etiquetaTipo($tipo)
    {
        $mapa = [
            'GENERAL' => 'General',
            'CONVOCATORIA' => 'Convocatoria'
        ];

        $codigo = strtoupper(trim((string)$tipo));
        return $mapa[$codigo] ?? ($codigo !== '' ? ucfirst(strtolower($codigo)) : 'General');
    }

    private function error($mensaje, $codigoHttp)
    {
        return [
            'ok' => false,
            'mensaje' => (string)$mensaje,
            'codigo_http' => (int)$codigoHttp
        ];
    }
}
