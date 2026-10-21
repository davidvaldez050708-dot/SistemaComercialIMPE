<?php
require_once __DIR__ . '/TelefoniaExtensionService.php';
require_once __DIR__ . '/ZadarmaWebhookEventStoreService.php';

/**
 * Recepción telefónica de Marketing, independiente de Ventas y Vinculación.
 * Muestra entrantes y salientes de la extensión asignada al usuario,
 * desde la fecha en que adquirió esa extensión. Nunca mezcla datos
 * de una extensión antes de que pertenezca a ese usuario.
 */
class TelefoniaRecepcionMarketingService
{
    private $db;

    public function __construct(?mysqli $connection=null)
    {
        $this->db = $connection??(new Database())->connect();
        (new ZadarmaWebhookEventStoreService($this->db))->asegurarEstructura();
    }

    public static function esMarketing(): bool
    {
        return strcasecmp(trim((string)($_SESSION['rol'] ?? '')), 'Marketing') === 0;
    }

    public static function tienePermisos(): bool
    {
        return self::esMarketing() &&
            tienePermiso('telefonia.usar') &&
            tienePermiso('telefonia.recibir');
    }

    public function asignacion(int $usuarioId): ?array
    {
        if ($usuarioId <= 0 || !self::tienePermisos()) {
            return null;
        }
        $asignacion = (new TelefoniaExtensionService($this->db))->resolverParaUsuario($usuarioId);
        $extension = trim((string)($asignacion['extension'] ?? ''));
        if (
            !$asignacion || empty($asignacion['permite_entrantes']) ||
            !preg_match('/^\d{3,6}$/', $extension)
        ) {
            return null;
        }

        // Sin fallback de extensiones compartidas ni historial de otro dueño.
        $stmt = $this->db->prepare(
            "SELECT GREATEST(created_at, updated_at) AS asignada_desde,
                    permite_salientes FROM telefonia_extensiones
             WHERE usuario_id = ? AND extension = ? AND proveedor = 'ZADARMA'
               AND activo = 1 AND permite_entrantes = 1 LIMIT 1"
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudo verificar la extensión de recepción.');
        }
        $stmt->bind_param('is', $usuarioId, $extension);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$fila) {
            return null;
        }
        return [
            'extension' => $extension,
            'asignada_desde' => (string)$fila['asignada_desde'],
            'permite_salientes' =>
                !empty($asignacion['permite_salientes']) &&
                !empty($fila['permite_salientes']) &&
                tienePermiso('telefonia.salientes')
        ];
    }

    protected function operacionesDelUsuario(int $usuarioId): array
    {
        require_once __DIR__.'/TelefoniaTransferenciaOperacionService.php';
        return (new TelefoniaTransferenciaOperacionService($this->db))->delUsuario($usuarioId);
    }

    public function resumen(int $usuarioId): array
    {
        $data = [
            'configurada' => false,
            'extension' => '',
            'periodo' => 'Últimos 30 días',
            'total' => 0,
            'atendidas' => 0,
            'perdidas' => 0,
            'salientes' => 0,
            'transferidas' => 0,
            'en_curso' => 0,
            'sin_contacto' => 0,
            'llamadas' => []
        ];

        $asignacion = $this->asignacion($usuarioId);
        if (!$asignacion) return $data;

        $ext = $asignacion['extension'];
        $data['configurada'] = true;
        $data['extension'] = $ext;
        $limite = date('Y-m-d H:i:s', strtotime('-30 days'));
        if (strcmp($asignacion['asignada_desde'], $limite) > 0) {
            $limite = $asignacion['asignada_desde'];
        }

        // Inicios PBX firmados de esta extensión dentro del período:
        // NOTIFY_INTERNAL (entrante) o NOTIFY_OUT_START (saliente).
        // Ni números ni ID provienen del navegador; el origen es el webhook.
        $permitirSalientes = !empty($asignacion['permite_salientes']) ? 1 : 0;
        $stmt = $this->db->prepare(
            "SELECT e.evento, e.pbx_call_id, e.internal, e.destination,
                    e.caller_id, e.received_at, e.duration, e.disposition,
                    e.is_recorded, e.call_id_with_rec, e.payload_json
             FROM telefonia_zadarma_eventos e
             WHERE e.received_at >= ?
               AND e.evento IN ('NOTIFY_INTERNAL', 'NOTIFY_ANSWER',
                    'NOTIFY_END', 'NOTIFY_RECORD', 'NOTIFY_OUT_START',
                    'NOTIFY_OUT_END')
               AND e.pbx_call_id IN (
                   SELECT inicial.pbx_call_id
                   FROM telefonia_zadarma_eventos inicial
                   WHERE inicial.internal = ?
                     AND (
                        inicial.evento = 'NOTIFY_INTERNAL'
                        OR (inicial.evento = 'NOTIFY_OUT_START' AND ? = 1)
                     )
                     AND inicial.received_at >= ?
               )
             ORDER BY e.id ASC"
        );
        if (!$stmt) {
            throw new RuntimeException('No se pudo consultar el historial telefónico.');
        }
        $stmt->bind_param('ssis', $limite, $ext, $permitirSalientes, $limite);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $eventos = [];
        while ($fila = $resultado->fetch_assoc()) {
            $eventos[] = $fila;
        }
        $stmt->close();

        $llamadas = self::agruparEventos($eventos, $ext, $permitirSalientes === 1);
        $operaciones=$this->operacionesDelUsuario($usuarioId);
        foreach($operaciones as $op)foreach($llamadas as &$fila){
            if((int)$op['origen_usuario']!==$usuarioId || $fila['pbx_call_id']!==$op['referencia'])continue;
            if($op['estado']==='ENTREGADA'){
                $fila['estado']='Transferida';$fila['destino_transferencia']=$op['destino_extension'];$fila['finalizada']=true;
            }elseif($op['modo']==='CONSULTADA' && in_array($op['estado'],['SOLICITADA','TIMBRANDO','CONSULTANDO','ENTREGANDO'],true)){
                $fila['estado']='Consultando';$fila['finalizada']=false;$fila['destino_transferencia']='';
            }elseif($op['estado']==='DEVUELTA'){
                $fila['estado']=$fila['finalizada']?'Atendida':'En curso';$fila['destino_transferencia']='';
            }
        }
        unset($fila);
        $resultados = $this->obtenerResultados($usuarioId, $ext);
        foreach ($llamadas as $call) {
            $observado = $resultados[$call['pbx_call_id']] ?? [];
            $call['resultado'] = (string)($observado['resultado'] ?? '');
            $call['resultado_etiqueta'] = self::resultadosPermitidos()[$call['resultado']] ?? '';
            $call['nota'] = (string)($observado['nota'] ?? '');
            $call['requiere_resultado'] = self::requiereResultado($call);
            $call = self::aplicarPoliticaGrabacion($call);
            if ($call['estado'] === 'Transferida') $data['transferidas']++;
            if ($call['tipo'] === 'Saliente') {
                $data['salientes']++;
                $sinContactoPbx = in_array($call['estado_cierre'],
                    ['Sin respuesta','Ocupada','Cancelada','Fallida','Número inexistente',
                     'No disponible','Sin saldo','Límite excedido'], true);
                $sinContactoRegistrado = in_array($call['resultado'],
                    ['BUZON','NO_DISPONIBLE','NUMERO_INCORRECTO'], true);
                if ($call['finalizada'] && $call['resultado'] !== 'CONTACTO' &&
                    ($sinContactoPbx || $sinContactoRegistrado)) $data['sin_contacto']++;
            } else {
                // Los indicadores existentes conservan su significado:
                // solamente cuentan llamadas de recepción.
                $data['total']++;
                if (in_array($call['estado'], ['Atendida', 'En curso'], true)) {
                    $data['atendidas']++;
                }
                if ($call['estado'] === 'Perdida') $data['perdidas']++;
                if ($call['estado'] === 'Transferida') {
                    if (!empty($call['fue_contestada'])) $data['atendidas']++;
                }
                if ($call['estado'] === 'Sonando') $data['en_curso']++;
            }
            unset($call['fue_contestada']);
            $data['llamadas'][] = $call;
        }

        usort($data['llamadas'], static function (array $a, array $b): int {
            return strcmp($b['fecha'], $a['fecha']);
        });
        return $data;
    }
    /**
     * Agrupador puro para eventos PBX, verificable sin conexión a Zadarma.
     * La pertenencia se prueba mediante un evento inicial de la propia
     * extensión; los eventos finales globales no crean llamadas ajenas.
     */
    public static function agruparEventos(
        array $eventos,
        string $extension,
        bool $permitirSalientes
    ): array {
        $registros = [];
        // Primero identificar los inicios, incluso si NOTIFY_ANSWER llegó
        // antes a la base por una demora en la entrega del webhook.
        foreach ($eventos as $row) {
            $id = trim((string)($row['pbx_call_id'] ?? ''));
            $tipo = strtoupper(trim((string)($row['evento'] ?? '')));
            $interno = trim((string)($row['internal'] ?? ''));
            if ($id === '' || $interno !== $extension) continue;

            $direction = $tipo === 'NOTIFY_INTERNAL' ? 'Entrante' :
                ($tipo === 'NOTIFY_OUT_START' && $permitirSalientes
                    ? 'Saliente' : '');
            if ($direction === '' || isset($registros[$id])) continue;
            $registros[$id] = [
                'pbx_call_id' => $id,
                'tipo' => $direction,
                'fecha' => (string)($row['received_at'] ?? ''),
                'numero' => trim((string)($direction === 'Entrante'
                    ? ($row['caller_id'] ?? '') : ($row['destination'] ?? ''))),
                'contestada' => false,
                'finalizada' => false,
                'fecha_fin' => '',
                'transferida' => false,
                'destino_transferencia' => '',
                'disposition' => '',
                'segundos' => 0,
                'audio_reportado' => false,
                'saliente_transferida' => false
            ];
        }

        foreach ($eventos as $row) {
            $id = trim((string)($row['pbx_call_id'] ?? ''));
            if (!isset($registros[$id])) continue;
            $call = &$registros[$id];
            $tipo = strtoupper(trim((string)($row['evento'] ?? '')));
            $interno = trim((string)($row['internal'] ?? ''));
            $esSaliente = $call['tipo'] === 'Saliente';
            $p=json_decode((string)($row['payload_json'] ?? ''),true) ?: [];
            if ($tipo==='NOTIFY_INTERNAL' && $interno===$extension && $call['transferida'] &&
                (string)($p['transfer_from'] ?? '')===$call['destino_transferencia']) {
                $call['transferida']=false;$call['destino_transferencia']='';
                $call['saliente_transferida']=false;$call['finalizada']=false;$call['fecha_fin']='';
            }

            // La centralita identifica el origen también en NOTIFY_ANSWER.
            // Aplicar la misma evidencia a salientes y entrantes; nunca inferir
            // transferencias por coincidencia de teléfono o por una orden UI.
            if (in_array($tipo, ['NOTIFY_INTERNAL','NOTIFY_ANSWER'], true) &&
                $interno !== $extension && preg_match('/^\d{3,6}$/D', $interno)) {
                $payloadTransferencia = json_decode((string)($row['payload_json'] ?? ''), true);
                if (is_array($payloadTransferencia) &&
                    trim((string)($payloadTransferencia['transfer_from'] ?? '')) === $extension) {
                    $call['transferida'] = true;
                    $call['destino_transferencia'] = $interno;
                    if ($esSaliente) $call['saliente_transferida'] = true;
                }
            }

            // En salientes Zadarma puede confirmar el último tramo sólo en
            // OUT_END: no necesariamente emite INTERNAL/transfer_from.
            // El inicio propio y el mismo PBX ID ya se comprobaron arriba.
            $ultimoInterno=trim((string)($p['last_internal'] ?? ''));
            $salienteEntregada=$esSaliente && $tipo==='NOTIFY_OUT_END' &&
                preg_match('/^\d{3,6}$/D',$ultimoInterno) && $ultimoInterno!==$extension &&
                in_array(strtolower(trim((string)($row['disposition'] ?? ''))),['answered','completed'],true);
            if($salienteEntregada){
                $call['transferida']=true;$call['saliente_transferida']=true;
                $call['destino_transferencia']=$ultimoInterno;
            }

            if ($esSaliente) {
                if ($tipo === 'NOTIFY_ANSWER' &&
                    ($interno === $extension || $interno === '')) {
                    $call['contestada'] = true;
                }
                if ($tipo === 'NOTIFY_OUT_END' &&
                    ($interno === $extension || $interno === '' || $salienteEntregada)) {
                    $call['finalizada'] = true;
                    $call['fecha_fin'] = (string)($row['received_at'] ?? '');
                    $call['disposition'] = strtolower(trim(
                        (string)($row['disposition'] ?? '')
                    ));
                    if (in_array($call['disposition'], ['answered','completed'], true)) {
                        $call['contestada'] = true;
                    }
                    $call['segundos'] = max(
                        $call['segundos'], (int)($row['duration'] ?? 0)
                    );
                }
            } else {
                $digits = preg_replace('/\D+/', '', (string)($row['destination'] ?? '')) ?: '';
                $respuestaPropia = $interno === $extension ||
                    ($interno === '' && $digits !== '' &&
                        substr($digits, -strlen($extension)) === $extension);
                if ($tipo === 'NOTIFY_ANSWER' && $respuestaPropia) {
                    $call['contestada'] = true;
                }
                if ($tipo === 'NOTIFY_END' &&
                    ($interno === $extension || $interno === '')) {
                    $call['finalizada'] = true;
                    $call['fecha_fin'] = (string)($row['received_at'] ?? '');
                    $call['segundos'] = max(
                        $call['segundos'], (int)($row['duration'] ?? 0)
                    );
                }
            }
            if ($tipo === 'NOTIFY_RECORD' ||
                (int)($row['is_recorded'] ?? 0) === 1 ||
                trim((string)($row['call_id_with_rec'] ?? '')) !== '') {
                $call['audio_reportado'] = true;
            }
            unset($call);
        }

        $resultado = [];
        foreach ($registros as $call) {
            if ($call['tipo'] === 'Saliente') {
                $disposition = $call['disposition'];
                if (!$call['finalizada']) {
                    $call['estado'] = $call['contestada'] ? 'En curso' : 'Marcando';
                } elseif (in_array($disposition, ['answered', 'completed'], true) ||
                    ($disposition === '' && $call['contestada'])) {
                    $call['estado'] = 'Completada';
                } elseif ($disposition === 'busy') {
                    $call['estado'] = 'Ocupada';
                } elseif (in_array($disposition, ['no answer', 'no-answer', 'no_answer'], true)) {
                    $call['estado'] = 'Sin respuesta';
                } elseif (in_array($disposition, ['canceled', 'cancelled', 'cancel'], true)) {
                    $call['estado'] = 'Cancelada';
                } elseif ($disposition === 'unallocated number') {
                    $call['estado'] = 'Número inexistente';
                } elseif (in_array($disposition, ['unavailable','not available'], true)) {
                    $call['estado'] = 'No disponible';
                } elseif ($disposition === 'no money') {
                    $call['estado'] = 'Sin saldo';
                } elseif (in_array($disposition,
                    ['no limit','no day limit','line limit','no money, no limit'], true)) {
                    $call['estado'] = 'Límite excedido';
                } else {
                    $call['estado'] = 'Fallida';
                }
            } elseif ($call['contestada']) {
                $call['estado'] = $call['finalizada'] ? 'Atendida' : 'En curso';
            } elseif ($call['finalizada']) {
                $call['estado'] = 'Perdida';
            } else {
                $call['estado'] = 'Sonando';
            }
            $call['estado_cierre'] = $call['estado'];
            if ($call['transferida']) $call['estado'] = 'Transferida';

            // Una grabación saliente completada se ofrece para consulta
            // bajo demanda. El endpoint vuelve a comprobar la propiedad.
            $salienteReproducible = $call['tipo'] === 'Saliente' &&
                $call['finalizada'] && $call['estado'] === 'Completada' &&
                !$call['saliente_transferida'] && $call['audio_reportado'];
            $call['tiene_grabacion'] = $salienteReproducible ||
                ($call['transferida'] && $call['contestada'] &&
                    $call['finalizada'] && $call['audio_reportado']) ||
                ($call['tipo'] === 'Entrante' &&
                $call['contestada'] &&
                $call['finalizada'] && $call['audio_reportado']);
            $call['fue_contestada'] = $call['contestada'];
            $call['grabacion_transferida'] = $call['transferida'];
            $call['grabacion_salida_pendiente'] =
                $call['tipo'] === 'Saliente' && $call['audio_reportado'] &&
                !$salienteReproducible;
            $call['atendida'] = $call['contestada'];
            unset($call['audio_reportado'], $call['contestada'],
                $call['transferida'], $call['disposition'],
                $call['saliente_transferida']);
            $resultado[] = self::aplicarPoliticaGrabacion($call);
        }
        return $resultado;
    }

    /** Los intentos sin contacto se conservan sólo como historial. */
    public static function aplicarPoliticaGrabacion(array $call): array
    {
        $sinContacto = ['Perdida','Sin respuesta','Ocupada','Cancelada','Fallida',
            'Número inexistente','No disponible','Sin saldo','Límite excedido'];
        $call['excluir_grabacion'] =
            in_array((string)($call['resultado'] ?? ''), ['BUZON','NO_DISPONIBLE','NUMERO_INCORRECTO'], true) ||
            in_array((string)($call['estado'] ?? ''), $sinContacto, true) ||
            in_array((string)($call['estado_cierre'] ?? ''), $sinContacto, true);
        if ($call['excluir_grabacion']) {
            $call['tiene_grabacion'] = false;
            $call['grabacion_transferida'] = false;
            $call['grabacion_salida_pendiente'] = false;
        }
        return $call;
    }

    public static function requiereResultado(array $call): bool
    {
        return !empty($call['finalizada']) && empty($call['resultado']) &&
            ($call['estado'] ?? '') !== 'Transferida' &&
            (!empty($call['atendida']) || in_array($call['estado_cierre'] ?? '', ['Atendida','Completada'], true));
    }

    public static function resultadosPermitidos(): array
    {
        return ['CONTACTO'=>'Persona atendió', 'BUZON'=>'Buzón de voz',
            'NO_DISPONIBLE'=>'No disponible', 'NUMERO_INCORRECTO'=>'Número incorrecto',
            'OTRO'=>'Otro resultado', 'SIN_CLASIFICAR'=>'Sin clasificar'];
    }

    public static function validarResultado(string $resultado, string $nota): void
    {
        if (!isset(self::resultadosPermitidos()[$resultado])) {
            throw new InvalidArgumentException('Selecciona un resultado válido.');
        }
        if (!preg_match('//u', $nota) ||
            (function_exists('mb_strlen') ? mb_strlen($nota, 'UTF-8') : strlen($nota)) > 500) {
            throw new InvalidArgumentException('La nota admite hasta 500 caracteres.');
        }
    }

    private function obtenerResultados(int $usuarioId, string $extension): array
    {
        try {
            $stmt = $this->db->prepare('SELECT pbx_call_id, resultado, nota
                FROM telefonia_recepcion_resultados WHERE usuario_id=? AND extension=?');
            if (!$stmt) {
                if ((int)$this->db->errno === 1146) return [];
                throw new RuntimeException('No se pudieron consultar los resultados de recepción.');
            }
            $stmt->bind_param('is', $usuarioId, $extension);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            return array_column($rows, null, 'pbx_call_id');
        } catch (mysqli_sql_exception $error) {
            // Antes del primer registro, el historial funciona sin crear tablas.
            if ($error->getCode() === 1146) return [];
            throw $error;
        }
    }

    public function guardarResultado(int $usuarioId, string $pbxCallId,
        string $resultado, string $nota): array
    {
        self::validarResultado($resultado, $nota);
        if (!preg_match('/^[a-zA-Z0-9_-]{6,100}$/D', $pbxCallId)) {
            throw new InvalidArgumentException('Identificador de llamada inválido.');
        }
        // Reutiliza la verificación de rol, asignación, antigüedad, extensión
        // y permiso saliente. Conocer un ID no permite clasificar otra llamada.
        $recepcion = $this->resumen($usuarioId);
        $propia = null;
        foreach ($recepcion['llamadas'] as $call) {
            if ($call['pbx_call_id'] === $pbxCallId) { $propia = $call; break; }
        }
        if (!$propia || empty($propia['finalizada'])) {
            throw new DomainException('Sólo puedes registrar llamadas finalizadas de tu extensión.');
        }
        if (!empty($propia['resultado'])) {
            throw new DomainException('El resultado ya fue registrado y no se puede modificar.',409);
        }
        if (!self::requiereResultado($propia)) {
            throw new DomainException('Esta llamada ya tiene un cierre automático: transferida o sin respuesta.');
        }
        if (!$this->db->query("CREATE TABLE IF NOT EXISTS telefonia_recepcion_resultados (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NOT NULL, extension VARCHAR(12) NOT NULL,
            pbx_call_id VARCHAR(100) NOT NULL, resultado VARCHAR(30) NOT NULL,
            nota VARCHAR(500) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_recepcion_resultado (usuario_id,extension,pbx_call_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci")) {
            throw new RuntimeException('No se pudo preparar el registro de resultados.');
        }
        $ext = $recepcion['extension'];
        $stmt = $this->db->prepare('INSERT INTO telefonia_recepcion_resultados
            (usuario_id,extension,pbx_call_id,resultado,nota) VALUES (?,?,?,?,?)');
        $stmt->bind_param('issss', $usuarioId, $ext, $pbxCallId, $resultado, $nota);
        // La clave única protege también dos envíos simultáneos: nunca sobrescribir.
        try {
            if (!$stmt->execute()) {
                if ((int)$stmt->errno === 1062) {
                    throw new DomainException('El resultado ya fue registrado y no se puede modificar.',409);
                }
                throw new RuntimeException('No se pudo guardar el resultado.');
            }
        } catch (mysqli_sql_exception $error) {
            if ($error->getCode() === 1062) {
                throw new DomainException('El resultado ya fue registrado y no se puede modificar.',409);
            }
            throw $error;
        } finally {
            $stmt->close();
        }
        $guardada = self::aplicarPoliticaGrabacion(array_merge($propia,['resultado'=>$resultado]));
        return ['resultado'=>$resultado,'resultado_etiqueta'=>self::resultadosPermitidos()[$resultado],
            'nota'=>$nota, 'excluir_grabacion'=>$guardada['excluir_grabacion'],
            'tiene_grabacion'=>$guardada['tiene_grabacion'],
            'grabacion_transferida'=>$guardada['grabacion_transferida'],
            'grabacion_salida_pendiente'=>$guardada['grabacion_salida_pendiente']];
    }

}
