<?php
require_once __DIR__ . '/TelefoniaExtensionService.php';
require_once __DIR__ . '/TelefoniaContactosService.php';
require_once __DIR__ . '/../../config/db_connection.php';

/**
 * Clasificación humana de llamadas salientes de Ventas.
 * El evento de Zadarma es la fuente de identidad; NO se deduce conversación
 * real por el estado ANSWERED (que también incluye buzón y locuciones).
 */
class TelefoniaResultadoVentasService
{
    public const CONVERSACION = 'CONVERSACION_PERSONA';
    public const BUZON = 'BUZON_VOZ';
    public const FUERA_HORARIO = 'FUERA_HORARIO';
    public const NUMERO_INEXISTENTE = 'NUMERO_INEXISTENTE';
    public const SIN_RESPUESTA = 'SIN_RESPUESTA';
    public const OCUPADO = 'OCUPADO';
    public const MENSAJE_AUTOMATICO = 'MENSAJE_AUTOMATICO';
    public const OTRO_SIN_CONTACTO = 'OTRO_SIN_CONTACTO';

    public static function opciones(): array
    {
        return [
            self::CONVERSACION => 'Hablé con una persona',
            self::BUZON => 'Buzón de voz',
            self::FUERA_HORARIO => 'Fuera de horario de servicio',
            self::NUMERO_INEXISTENTE => 'Número inexistente o incorrecto',
            self::SIN_RESPUESTA => 'No contestó',
            self::OCUPADO => 'Línea ocupada',
            self::MENSAJE_AUTOMATICO => 'Mensaje de operadora o grabadora',
            self::OTRO_SIN_CONTACTO => 'Otra situación sin contacto'
        ];
    }

    public static function esConversacion(?string $resultado): bool
    {
        return $resultado === self::CONVERSACION;
    }

    private $db;

    public function __construct()
    {
        $this->db = (new Database())->connect();
        $this->asegurarEstructura();
    }

    public function asegurarEstructura(): void
    {
        $ok = $this->db->query(
            "CREATE TABLE IF NOT EXISTS telefonia_ventas_resultados (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                usuario_id INT NOT NULL,
                extension VARCHAR(20) NOT NULL,
                pbx_call_id VARCHAR(100) NOT NULL,
                resultado VARCHAR(36) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uk_ventas_llamada_extension (pbx_call_id, extension),
                KEY idx_ventas_usuario_fecha (usuario_id, updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
        if (!$ok) {
            throw new RuntimeException('No fue posible preparar los resultados de llamadas de Ventas.');
        }
    }

    public static function extensionPropia(int $usuarioId): string
    {
        if ($usuarioId <= 0) {
            throw new InvalidArgumentException('Sesión no válida.');
        }

        $asignacion = (new TelefoniaExtensionService())->resolverParaUsuario($usuarioId);
        $extension = trim((string)($asignacion['extension'] ?? ''));
        if (
            !$asignacion ||
            empty($asignacion['permite_salientes']) ||
            !preg_match('/^\d{3,6}$/', $extension)
        ) {
            throw new RuntimeException('No tienes una extensión activa para clasificar llamadas.');
        }
        return $extension;
    }

    /**
     * Rechaza IDs arbitrarios: solo llamadas salientes de la extensión propia,
     * dentro del historial y cuyo fin confirmó el webhook.
     */
    public function guardar(int $usuarioId, string $pbxCallId, string $resultado): array
    {
        $pbxCallId = trim($pbxCallId);
        if (!preg_match('/^out_[a-fA-F0-9]{32,64}$/', $pbxCallId)) {
            throw new InvalidArgumentException('Identificador de llamada inválido.');
        }
        if (!array_key_exists($resultado, self::opciones())) {
            throw new InvalidArgumentException('Selecciona un resultado válido de la llamada.');
        }
        $extension = self::extensionPropia($usuarioId);
        require_once __DIR__ . '/TelefoniaMarcacionesVentasService.php';
        if (!(new TelefoniaMarcacionesVentasService())->pertenece(
            $usuarioId, $extension, $pbxCallId
        )) {
            throw new DomainException(
                'La llamada no fue iniciada desde Inicio de Ventas o aún no está vinculada.'
            );
        }
        $stmt = $this->db->prepare(
            "SELECT 1 FROM telefonia_zadarma_eventos
             WHERE pbx_call_id = ? AND internal = ?
               AND evento = 'NOTIFY_OUT_END'
               AND received_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             LIMIT 1"
        );
        if (!$stmt) throw new RuntimeException('No se pudo verificar la llamada.');
        $stmt->bind_param('ss', $pbxCallId, $extension);
        $stmt->execute();
        $existe = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$existe) {
            throw new DomainException(
                'La llamada aún no aparece como finalizada en Zadarma. Espera unos segundos y vuelve a intentar.'
            );
        }

        $stmt = $this->db->prepare(
            "INSERT INTO telefonia_ventas_resultados
                 (usuario_id, extension, pbx_call_id, resultado)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 usuario_id = VALUES(usuario_id),
                 resultado = VALUES(resultado),
                 updated_at = CURRENT_TIMESTAMP"
        );
        if (!$stmt) throw new RuntimeException('No se pudo preparar el registro del resultado.');
        $stmt->bind_param('isss', $usuarioId, $extension, $pbxCallId, $resultado);
        $stmt->execute();
        $stmt->close();
        return [
            'pbx_call_id' => $pbxCallId,
            'resultado' => $resultado,
            'etiqueta' => self::opciones()[$resultado],
            'contacto_real' => self::esConversacion($resultado)
        ];
    }

    /**
     * El servicio de actividad consulta resultados de una extensión, sin
     * confiar en IDs enviados desde un navegador ni exponer otros usuarios.
     */
    public function consultarExtension(int $usuarioId, string $extension): array
    {
        if ($extension !== self::extensionPropia($usuarioId)) {
            return [];
        }
        $stmt = $this->db->prepare(
            "SELECT pbx_call_id, resultado
             FROM telefonia_ventas_resultados
             WHERE usuario_id = ? AND extension = ?
             AND updated_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)"
        );
        if (!$stmt) throw new RuntimeException('No se pudieron consultar los resultados.');
        $stmt->bind_param('is', $usuarioId, $extension);
        $stmt->execute();
        $rs = $stmt->get_result();
        $map = [];
        while ($fila = $rs->fetch_assoc()) {
            $map[(string)$fila['pbx_call_id']] = (string)$fila['resultado'];
        }
        $stmt->close();
        return $map;
    }
}
