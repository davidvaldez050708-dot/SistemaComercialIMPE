<?php
/**
 * Motor de cola extraído del ejecutable CLI para permitir pruebas integrales.
 * Sin rutas web, sin migraciones ni credenciales en el repositorio.
 */
require_once __DIR__ . '/ZohoDeveloperRegistroSync.php';

final class ZohoDeveloperRegistroWorker
{
    public static function procesar(mysqli $db, array $cfg): array
    {
        if (PHP_SAPI !== 'cli' || $cfg === []) {
            throw new RuntimeException('WORKER_SOLO_CLI_CON_CONFIG');
        }
        $enviados = 0;
        $fallidos = 0;
        for ($i = 0; $i < 5; ++$i) {
            $row = null;
            try {
                $db->begin_transaction();
                // Recupera trabajos bloqueados por una interrupción previa.
                $db->query(
                    "UPDATE zoho_dev_registros_pendientes
                     SET estado = 'reintentar', bloqueado_hasta = NULL, updated_at = NOW()
                     WHERE estado = 'en_proceso' AND bloqueado_hasta < NOW()"
                );
        
                $res = $db->query(
                    "SELECT id, registro_id, intentos
                     FROM zoho_dev_registros_pendientes
                     WHERE estado IN ('pendiente', 'reintentar')
                       AND (proximo_intento IS NULL OR proximo_intento <= NOW())
                       AND intentos < 6
                     ORDER BY id ASC LIMIT 1 FOR UPDATE"
                );
                $row = $res->fetch_assoc();
                if ($row !== null) {
                    $stmt = $db->prepare(
                        "UPDATE zoho_dev_registros_pendientes
                         SET estado='en_proceso', intentos=intentos+1,
                             bloqueado_hasta=DATE_ADD(NOW(), INTERVAL 5 MINUTE),
                             updated_at=NOW()
                         WHERE id=?"
                    );
                    $id = (int)$row['id'];
                    $stmt->bind_param('i', $id);
                    $stmt->execute();
                    $stmt->close();
                }
                $db->commit();
            } catch (Throwable $error) {
                $db->rollback();
                fwrite(STDERR, "ERROR: no fue posible reservar la cola.\n");
                throw new RuntimeException('ERROR_RESERVA_COLA');
            }
        
            if ($row === null) {
                break;
            }
        
            $id = (int)$row['id'];
            $registroId = (int)$row['registro_id'];
            try {
                $stmt = $db->prepare(
                    'SELECT nombre, apellido, correo, movil FROM formulario_registros WHERE id=? LIMIT 1'
                );
                $stmt->bind_param('i', $registroId);
                $stmt->execute();
                $registro = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!is_array($registro)) {
                    throw new RuntimeException('REGISTRO_NO_EXISTE');
                }
                if (!ZohoDeveloperRegistroSync::autorizado($cfg, (string)$registro['correo'])) {
                    throw new RuntimeException('EMAIL_FUERA_DE_PRUEBA');
                }
        
                // La validación de ID de organización se realiza antes de cualquier envío.
                $token = ZohoDeveloperRegistroSync::tokenVerificado($cfg);
                $zohoId = ZohoDeveloperRegistroSync::crearOActualizarLead(
                    $registro, $registroId, $cfg, $token
                );
                $stmt = $db->prepare(
                    "UPDATE zoho_dev_registros_pendientes
                     SET estado='enviado', zoho_id=?, bloqueado_hasta=NULL,
                         proximo_intento=NULL, ultimo_error=NULL, updated_at=NOW()
                     WHERE id=? AND estado='en_proceso'"
                );
                $stmt->bind_param('si', $zohoId, $id);
                $stmt->execute();
                $stmt->close();
                ++$enviados;
                echo "OK: registro de prueba #$registroId sincronizado (sin mostrar datos).\n";
            } catch (Throwable $error) {
                $msg = (string)$error->getMessage();
                $codigo = preg_match('/^[A-Z0-9_]{1,90}$/D', $msg)
                    ? $msg : 'ERROR_TECNICO';
                $intentos = (int)$row['intentos'] + 1;
                $requiereRevision = $intentos >= 6 ||
                    in_array($codigo, [
                        'EMAIL_FUERA_DE_PRUEBA', 'REGISTRO_NO_EXISTE',
                        'ORGANIZACION_DEVELOPER_NO_COINCIDE'
                    ], true);
                $estado = $requiereRevision ? 'revision' : 'reintentar';
                $espera = min(240, 4 ** $intentos);
                $stmt = $db->prepare(
                    "UPDATE zoho_dev_registros_pendientes
                     SET estado=?, ultimo_error=?, bloqueado_hasta=NULL,
                         proximo_intento=IF(? = 'revision', NULL,
                                            DATE_ADD(NOW(), INTERVAL ? MINUTE)),
                         updated_at=NOW()
                     WHERE id=? AND estado='en_proceso'"
                );
                $stmt->bind_param('sssii', $estado, $codigo, $estado, $espera, $id);
                $stmt->execute();
                $stmt->close();
                ++$fallidos;
                echo "PENDIENTE: registro de prueba #$registroId; estado=$estado; código=$codigo\n";
            }
        }
        
        echo "Resumen: enviados=$enviados; pendientes/revisión procesados=$fallidos.\n";

        return [$enviados, $fallidos];
    }
}
