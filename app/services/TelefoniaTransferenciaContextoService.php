<?php

require_once __DIR__ . '/../helpers/SeguimientoTerritorialScope.php';
/** Identificación de un expediente realizada por Recepción antes de transferir.
 * No equivale a identidad certificada ni confirma que la PBX completó el traspaso.
 */
require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/TelefoniaEnrutamientoService.php';

final class TelefoniaTransferenciaContextoService
{
    private mysqli $db;

    public function __construct(?mysqli $db = null)
    {
        $this->db = $db ?? (new Database())->connect();
    }

    private static function telefono10(string $numero): string
    {
        $n = preg_replace('/\D+/', '', $numero) ?: '';
        return strlen($n) >= 10 && strlen($n) <= 15 ? substr($n, -10) : '';
    }

    private function tabla(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS telefonia_transferencia_contextos_v2 (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            origen_usuario_id INT NOT NULL,
            origen_extension VARCHAR(12) NOT NULL,
            destino_usuario_id INT NOT NULL,
            destino_extension VARCHAR(12) NOT NULL,
            referencia_llamada VARCHAR(100) NOT NULL,
            telefono10 CHAR(10) NOT NULL,
            seguimiento_id INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uk_tr_usu_llamada (origen_usuario_id, referencia_llamada),
            KEY idx_tr_destino (destino_usuario_id,destino_extension,telefono10,expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
        if (!$this->db->query($sql)) {
            throw new RuntimeException('No se pudo preparar el contexto institucional.');
        }
    }

    public function registrar(
        int $origenId, string $rol, string $origenExt, int $destinoId,
        string $destinoExt, int $estadoId, int $seguimientoId,
        string $referencia, string $numeroOrigen
    ): bool {
        if (TelefoniaEnrutamientoService::categoriaRol($rol) !== 'RECEPCION' ||
            $origenId <= 0 || $destinoId <= 0 || $estadoId <= 0 ||
            $seguimientoId <= 0 ||
            !preg_match('/^[0-9]{3,6}$/D', $origenExt) ||
            !preg_match('/^[0-9]{3,6}$/D', $destinoExt) ||
            !preg_match('/^[a-zA-Z0-9_-]{6,100}$/D', $referencia)) return false;
        $telefono = self::telefono10($numeroOrigen);
        if ($telefono === '') return false;

        // La referencia y el número que llegan del navegador no son prueba
        // de la llamada. Sólo el evento recibido desde el webhook Zadarma
        // para esta llamada y la extensión de Recepción puede acreditarlos.
        // NOTIFY_ANSWER no se exige porque puede llegar más tarde.
        // El webhook registra received_at en America/Mexico_City; NOW()
        // depende de la zona horaria de la sesion MySQL de Hostinger.
        // Comparar contra limites PHP en la MISMA zona del evento firmado.
        // Mantener vigencia maxima de 60 minutos y evitar fechas futuras.
        $ahoraMx = new DateTimeImmutable('now', new DateTimeZone('America/Mexico_City'));
        $desdeMx = $ahoraMx->modify('-60 minutes')->format('Y-m-d H:i:s');
        $hastaMx = $ahoraMx->modify('+30 seconds')->format('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            "SELECT caller_id,destination,evento FROM telefonia_zadarma_eventos
             WHERE pbx_call_id = ? AND internal = ?
               AND evento IN ('NOTIFY_INTERNAL','NOTIFY_OUT_START')
               AND received_at >= ? AND received_at <= ?
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->bind_param('ssss', $referencia, $origenExt, $desdeMx, $hastaMx);
        $stmt->execute();
        $evento = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$evento || self::telefono10((string)($evento['evento']==='NOTIFY_OUT_START'?$evento['destination']:$evento['caller_id'])) !== $telefono) {
            return false;
        }

        // Ningún identificador del navegador puede seleccionar una cartera
        // ajena al destino validado por la autorización de transferencia.
        $stmt = $this->db->prepare(
            'SELECT id FROM seguimientos_vinculacion
             WHERE id = ? AND ' . SeguimientoTerritorialScope::analista('seguimientos_vinculacion') . ' AND estado_id = ?
               AND activo = 1 AND estado_seguimiento <> \'DESCARTADO\' LIMIT 1'
        );
        $stmt->bind_param('iii', $seguimientoId, $destinoId, $estadoId);
        $stmt->execute();
        $pertenece = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$pertenece) return false;

        $this->tabla();
        // Vigencia limitada: no vincular una segunda llamada posterior sólo
        // por tener el mismo teléfono. La selección es informativa.
        $stmt = $this->db->prepare(
            "INSERT INTO telefonia_transferencia_contextos_v2
             (origen_usuario_id, origen_extension, destino_usuario_id,
              destino_extension, referencia_llamada, telefono10, seguimiento_id, expires_at)
             VALUES (?,?,?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 90 SECOND))
             ON DUPLICATE KEY UPDATE
              destino_usuario_id=VALUES(destino_usuario_id),
              destino_extension=VALUES(destino_extension),
              telefono10=VALUES(telefono10),
              seguimiento_id=VALUES(seguimiento_id),
              created_at=NOW(), expires_at=VALUES(expires_at)"
        );
        $stmt->bind_param('isisssi', $origenId, $origenExt, $destinoId,
            $destinoExt, $referencia, $telefono, $seguimientoId);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool)$ok;
    }

    public function obtenerParaDestinatario(int $destinoId, string $extension, string $numero, string $referencia = ''): ?array
    {
        $telefono = self::telefono10($numero);
        if ($destinoId <= 0 || $telefono === '' ||
            !preg_match('/^[0-9]{3,6}$/D', $extension) ||
            !preg_match('/^[a-zA-Z0-9_-]{6,100}$/D', $referencia)) return null;
        // La disponibilidad conserva también «No corresponde» y el nombre
        // manual. Nunca buscar el último expediente del mismo teléfono.
        $tabla = $this->db->query("SHOW TABLES LIKE 'telefonia_disponibilidad'");
        if (!$tabla || $tabla->num_rows === 0) return null;
        $stmt = $this->db->prepare(
            "SELECT d.referencia, d.numero, d.ruta_json,
                    TRIM(CONCAT(u.nombre, ' ', COALESCE(u.apellidos, ''))) AS confirmado_por
             FROM telefonia_disponibilidad d
             JOIN usuarios u ON u.id = d.origen_usuario
             WHERE d.destino_usuario = ? AND d.destino_extension = ?
              AND d.referencia = ?
              AND d.estado IN ('ENVIANDO','ENVIADA','TRANSFERIDA','SIN_CONFIRMAR')
             LIMIT 2"
        );
        $stmt->bind_param('iss', $destinoId, $extension, $referencia);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if (count($rows) !== 1) return null;
        $row = $rows[0];
        $ruta = json_decode((string)$row['ruta_json'], true);
        if (!is_array($ruta) || ($ruta['tipo'] ?? '') !== 'INSTITUCION' ||
            (string)($ruta['referencia_llamada'] ?? '') !== $referencia ||
            (int)($ruta['responsable_id'] ?? 0) !== $destinoId ||
            (string)($ruta['extension'] ?? '') !== $extension ||
            self::telefono10((string)$row['numero']) !== $telefono) return null;
        $seguimientoId = (int)($ruta['seguimiento_id'] ?? 0);
        $estadoId = (int)($ruta['estado_id'] ?? 0);
        $institucion = trim((string)($ruta['institucion_nombre'] ?? ''));
        if ($seguimientoId < 0 || $estadoId <= 0 || $institucion === '') return null;
        if ($seguimientoId > 0) {
            $stmt = $this->db->prepare('SELECT nombre_entidad FROM seguimientos_vinculacion
                WHERE id = ? AND ' . SeguimientoTerritorialScope::analista('seguimientos_vinculacion') . ' AND estado_id = ? AND activo = 1
                 AND estado_seguimiento <> \'DESCARTADO\' LIMIT 1');
            $stmt->bind_param('iii', $seguimientoId, $destinoId, $estadoId);
            $stmt->execute();$expediente = $stmt->get_result()->fetch_assoc();$stmt->close();
            if (!$expediente) return null;
            $institucion = (string)$expediente['nombre_entidad'];
        }
        $stmt = $this->db->prepare('SELECT nombre FROM estados WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $estadoId);$stmt->execute();
        $estado = $stmt->get_result()->fetch_assoc();$stmt->close();
        if (!$estado) return null;
        return [
            'referencia_llamada' => $referencia,
            'seguimiento_id' => $seguimientoId,
            'institucion' => $institucion,
            'estado' => (string)$estado['nombre'],
            'confirmado_por' => (string)$row['confirmado_por'],
            'etiqueta' => 'Institución identificada por Recepción; confirma los datos durante la llamada.'
        ];
    }
}
