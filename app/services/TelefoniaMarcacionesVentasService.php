<?php
require_once __DIR__ . '/TelefoniaResultadoVentasService.php';

/**
 * Identidad de las llamadas que nacen en Inicio del Asesor de Ventas.
 * La extensión por sí sola no identifica al propietario histórico: puede
 * reasignarse a otro usuario o utilizarse en el expediente de Vinculación.
 */
class TelefoniaMarcacionesVentasService
{
    private $db;

    public function __construct()
    {
        $this->db = (new Database())->connect();
        $this->asegurarEstructura();
    }

    public function asegurarEstructura(): void
    {
        $ok = $this->db->query(
            "CREATE TABLE IF NOT EXISTS telefonia_ventas_marcaciones (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                usuario_id INT NOT NULL,
                extension VARCHAR(12) NOT NULL,
                destino VARCHAR(20) NOT NULL,
                token CHAR(32) NOT NULL,
                pbx_call_id VARCHAR(100) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                vinculada_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uk_ventas_marcacion_token (token),
                UNIQUE KEY uk_ventas_marcacion_pbx (pbx_call_id),
                KEY idx_ventas_marcacion_usuario (usuario_id, extension, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
        if (!$ok) {
            throw new RuntimeException('No fue posible preparar el registro de marcaciones comerciales.');
        }
    }

    public function iniciar(int $usuarioId, string $telefono, string $token): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            throw new InvalidArgumentException('Identificador comercial no válido.');
        }
        $extension = TelefoniaResultadoVentasService::extensionPropia($usuarioId);
        $destino = TelefoniaContactosService::normalizarTelefono($telefono);
        $stmt = $this->db->prepare(
            "INSERT INTO telefonia_ventas_marcaciones (usuario_id, extension, destino, token)
             VALUES (?, ?, ?, ?)"
        );
        if (!$stmt) throw new RuntimeException('No se pudo registrar el inicio de llamada.');
        $stmt->bind_param('isss', $usuarioId, $extension, $destino, $token);
        $stmt->execute();
        $stmt->close();
        return $token;
    }

    private static function mismoDestino(string $guardado, string $proveedor): bool
    {
        $a = preg_replace('/\D+/', '', $guardado) ?: '';
        $b = preg_replace('/\D+/', '', $proveedor) ?: '';
        if ($a === '' || $b === '') return false;
        if (hash_equals($a, $b)) return true;
        // Zadarma puede notificar el número mexicano con o sin el prefijo 52.
        return strlen($a) >= 10 && strlen($b) >= 10 &&
            hash_equals(substr($a, -10), substr($b, -10)) &&
            (
                (strlen($a) === 12 && strpos($a, '52') === 0 &&
                    strlen($b) === 10) ||
                (strlen($b) === 12 && strpos($b, '52') === 0 &&
                    strlen($a) === 10)
            );
    }

    public function vincular(int $usuarioId, string $token, string $pbxCallId): void
    {
        if (
            !preg_match('/^[a-f0-9]{32}$/', $token) ||
            !preg_match('/^out_[a-fA-F0-9]{32,64}$/', $pbxCallId)
        ) {
            throw new InvalidArgumentException('Identificador de marcación inválido.');
        }

        $extension = TelefoniaResultadoVentasService::extensionPropia($usuarioId);
        $stmt = $this->db->prepare(
            "SELECT destino, created_at, pbx_call_id
             FROM telefonia_ventas_marcaciones
             WHERE token = ? AND usuario_id = ? AND extension = ?
             LIMIT 1"
        );
        if (!$stmt) throw new RuntimeException('No se pudo verificar la marcación.');
        $stmt->bind_param('sis', $token, $usuarioId, $extension);
        $stmt->execute();
        $intent = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$intent) throw new DomainException('La marcación no pertenece a este asesor.');
        $previous = trim((string)($intent['pbx_call_id'] ?? ''));
        if ($previous !== '') {
            if (!hash_equals($previous, $pbxCallId)) {
                throw new DomainException('La marcación ya está asociada a otra llamada.');
            }
            return;
        }

        // Debe existir un inicio real emitido por la PBX y coincidir con el
        // destino y la extensión del intento registrado ANTES de llamar.
        $stmt = $this->db->prepare(
            "SELECT destination
             FROM telefonia_zadarma_eventos
             WHERE pbx_call_id = ?
               AND internal = ?
               AND evento = 'NOTIFY_OUT_START'
               AND received_at BETWEEN
                   DATE_SUB(?, INTERVAL 2 MINUTE) AND
                   DATE_ADD(?, INTERVAL 15 MINUTE)
             ORDER BY id ASC LIMIT 1"
        );
        if (!$stmt) throw new RuntimeException('No se pudo verificar el inicio en Zadarma.');
        $createdAt = (string)$intent['created_at'];
        $stmt->bind_param('ssss', $pbxCallId, $extension, $createdAt, $createdAt);
        $stmt->execute();
        $evento = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$evento) {
            throw new DomainException('Esperando la confirmación de la llamada en Zadarma.');
        }
        if (!self::mismoDestino((string)$intent['destino'], (string)$evento['destination'])) {
            throw new DomainException('El destino no coincide con la marcación de Ventas.');
        }

        // No atribuir una llamada de expediente institucional al asesor.
        $stmt = $this->db->prepare(
            "SELECT 1 FROM interacciones_vinculacion
             WHERE proveedor_externo = 'ZADARMA'
               AND canal = 'LLAMADA_IP'
               AND id_externo = ?
             LIMIT 1"
        );
        if (!$stmt) throw new RuntimeException('No se pudo verificar la vinculación institucional.');
        $stmt->bind_param('s', $pbxCallId);
        $stmt->execute();
        $esInstitucional = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($esInstitucional) {
            throw new DomainException('Esta llamada pertenece a un expediente de Vinculación.');
        }

        $stmt = $this->db->prepare(
            "UPDATE telefonia_ventas_marcaciones
             SET pbx_call_id = ?, vinculada_at = NOW()
             WHERE token = ? AND usuario_id = ? AND extension = ?
               AND pbx_call_id IS NULL"
        );
        if (!$stmt) throw new RuntimeException('No se pudo vincular la llamada comercial.');
        $stmt->bind_param('ssis', $pbxCallId, $token, $usuarioId, $extension);
        $stmt->execute();
        $actualizadas = $stmt->affected_rows;
        $stmt->close();
        if ($actualizadas !== 1) {
            throw new DomainException('La llamada comercial no pudo vincularse.');
        }
    }

    /**
     * Recupera enlaces de llamadas cuando el asesor cerró o recargó la
     * página antes de recibir la respuesta tardía del webhook de Zadarma.
     * Nunca atribuye llamadas de Vinculación: vincular() lo comprueba.
     */
    public function reconciliarPendientes(int $usuarioId, string $extension): void
    {
        if ($extension !== TelefoniaResultadoVentasService::extensionPropia($usuarioId)) {
            return;
        }
        $stmt = $this->db->prepare(
            "SELECT token, destino, created_at
             FROM telefonia_ventas_marcaciones
             WHERE usuario_id = ? AND extension = ?
               AND pbx_call_id IS NULL
               AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             ORDER BY created_at DESC LIMIT 20"
        );
        if (!$stmt) throw new RuntimeException('No se pudieron verificar marcaciones recientes.');
        $stmt->bind_param('is', $usuarioId, $extension);
        $stmt->execute();
        $rs = $stmt->get_result();
        $pendientes = [];
        while ($r = $rs->fetch_assoc()) $pendientes[] = $r;
        $stmt->close();

        $eventos = $this->db->prepare(
            "SELECT pbx_call_id, destination
             FROM telefonia_zadarma_eventos
             WHERE internal = ? AND evento = 'NOTIFY_OUT_START'
               AND received_at BETWEEN
                   DATE_SUB(?, INTERVAL 2 MINUTE) AND
                   DATE_ADD(?, INTERVAL 15 MINUTE)
             ORDER BY received_at ASC LIMIT 25"
        );
        if (!$eventos) throw new RuntimeException('No se pudo reconciliar el historial telefónico.');

        foreach ($pendientes as $intent) {
            $fecha = (string)$intent['created_at'];
            $eventos->bind_param('sss', $extension, $fecha, $fecha);
            $eventos->execute();
            $rs = $eventos->get_result();
            while ($ev = $rs->fetch_assoc()) {
                if (
                    !self::mismoDestino(
                        (string)$intent['destino'],
                        (string)($ev['destination'] ?? '')
                    )
                ) {
                    continue;
                }
                try {
                    $this->vincular(
                        $usuarioId,
                        (string)$intent['token'],
                        (string)$ev['pbx_call_id']
                    );
                    break;
                } catch (Throwable $e) {
                    // No atribuir llamadas ambiguas o ya vinculadas.
                    continue;
                }
            }
        }
        $eventos->close();
    }

    public function pertenece(int $usuarioId, string $extension, string $pbxCallId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM telefonia_ventas_marcaciones
             WHERE usuario_id = ? AND extension = ? AND pbx_call_id = ?
             LIMIT 1"
        );
        if (!$stmt) throw new RuntimeException('No se pudo verificar el origen de la llamada.');
        $stmt->bind_param('iss', $usuarioId, $extension, $pbxCallId);
        $stmt->execute();
        $found = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $found;
    }
}
