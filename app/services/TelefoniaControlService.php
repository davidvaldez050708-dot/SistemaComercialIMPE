<?php
require_once __DIR__ . '/ZadarmaWebhookEventStoreService.php';
require_once __DIR__ . '/TelefoniaMarcacionesVentasService.php';
require_once __DIR__ . '/TelefoniaResultadoVentasService.php';
require_once __DIR__ . '/TelefoniaExtensionService.php';
require_once __DIR__.'/TelefoniaLimpiezaGuard.php';

/**
 * Estadísticas administrativas: fuente técnica = webhooks PBX.
 * Atribución individual SOLO con identidad de proceso comprobable
 * (Vinculación/Ventas) o recepción entrante con asignación vigente.
 * Una extensión sola nunca demuestra quién realizó llamadas antiguas.
 */
class TelefoniaControlService
{
    private $db;

    public function __construct()
    {
        $this->db = (new Database())->connect();
        (new ZadarmaWebhookEventStoreService())->asegurarEstructura();
        (new TelefoniaExtensionService())->asegurarEstructura();
        (new TelefoniaMarcacionesVentasService())->asegurarEstructura();
        (new TelefoniaResultadoVentasService())->asegurarEstructura();
    }

    /**
     * Estado técnico del propio tramo PBX. Los intentos sin ANSWER nunca
     * se convierten en conversaciones; el cierre define el motivo.
     * Se usa exclusivamente la disposición terminal del mismo internal.
     */
    public static function estadoFinalPbx(bool $saliente, bool $conectada, bool $finalizada, string $disposicion): string
    {
        if ($conectada) return 'Conectada';
        if (!$finalizada) return 'Sin cierre confirmado';
        if (!$saliente) return 'Sin conexión confirmada';
        $disposicion = strtolower(trim($disposicion));
        return [
            'busy' => 'Ocupada',
            'cancel' => 'Cancelada',
            'no answer' => 'Sin respuesta',
            'no_answer' => 'Sin respuesta',
            'failed' => 'Fallida'
        ][$disposicion] ?? 'Sin conexión confirmada';
    }

    public function consultar(array $filtros = []): array
    {
        $hoy = date('Y-m-d');
        $inicio = (string)($filtros['desde'] ?? date('Y-m-d', strtotime('-29 days')));
        $fin = (string)($filtros['hasta'] ?? $hoy);
        $fechaValida = static function (string $v): bool {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
            return $date !== false && $date->format('Y-m-d') === $v;
        };
        if (!$fechaValida($inicio) || !$fechaValida($fin) ||
            $inicio > $fin || $fin > $hoy) {
            throw new InvalidArgumentException('Selecciona un intervalo de fechas válido que no sea futuro.');
        }
        $limiteInferior = date('Y-m-d', strtotime('-89 days'));
        if ($inicio < $limiteInferior || (strtotime($fin) - strtotime($inicio)) > 89 * 86400) {
            throw new InvalidArgumentException('Puedes consultar como máximo 90 días recientes por intervalo.');
        }

        $limpieza=TelefoniaLimpiezaGuard::condicion($this->db,'e.pbx_call_id','e.internal');
        $sql = "SELECT
                e.pbx_call_id, e.internal, MIN(e.received_at) AS fecha,
                MAX(CASE WHEN e.evento IN ('NOTIFY_OUT_START','NOTIFY_OUT_END')
                    THEN 1 ELSE 0 END) AS saliente,
                MAX(CASE WHEN e.evento = 'NOTIFY_INTERNAL'
                    THEN 1 ELSE 0 END) AS entrante_confirmada,
                MAX(CASE WHEN e.evento = 'NOTIFY_ANSWER' OR
                    LOWER(COALESCE(e.disposition, '')) = 'answered'
                    THEN 1 ELSE 0 END) AS conectada,
                MAX(CASE WHEN e.evento IN ('NOTIFY_END','NOTIFY_OUT_END')
                    THEN 1 ELSE 0 END) AS finalizada,
                SUBSTRING_INDEX(GROUP_CONCAT(
                    CASE WHEN e.evento IN ('NOTIFY_END','NOTIFY_OUT_END')
                          AND TRIM(COALESCE(e.disposition,'')) <> ''
                        THEN LOWER(TRIM(e.disposition)) END
                    ORDER BY e.id DESC SEPARATOR ','
                ), ',', 1) AS disposicion_final,
                MAX(CASE WHEN e.evento IN ('NOTIFY_END','NOTIFY_OUT_END')
                    THEN GREATEST(e.duration, 0) ELSE 0 END) AS segundos,
                MAX(CASE WHEN e.evento = 'NOTIFY_OUT_START'
                    THEN e.destination ELSE NULL END) AS destino,
                MAX(CASE WHEN e.evento = 'NOTIFY_INTERNAL'
                    THEN e.caller_id ELSE NULL END) AS origen,
                MIN(vm.usuario_id) AS usuario_ventas_min,
                MAX(vm.usuario_id) AS usuario_ventas_max,
                MIN(vc.usuario_id) AS usuario_tramo_min,
                MAX(vc.usuario_id) AS usuario_tramo_max,
                MAX(vr.resultado) AS resultado_ventas,
                MIN(iv.usuario_id) AS usuario_vinculacion_min,
                MAX(iv.usuario_id) AS usuario_vinculacion_max,
                MAX(iv.resultado) AS resultado_vinculacion,
                MAX(iv.notas) AS notas_vinculacion,
                MAX(te.usuario_id) AS usuario_extension,
                MAX(te.permite_entrantes) AS permiso_entrada,
                MAX(GREATEST(te.created_at, te.updated_at)) AS asignada_desde
            FROM telefonia_zadarma_eventos e
            LEFT JOIN telefonia_ventas_marcaciones vm
                ON vm.pbx_call_id = e.pbx_call_id AND vm.extension = e.internal
            LEFT JOIN telefonia_ventas_llamadas vc
                ON vc.pbx_call_id = BINARY e.pbx_call_id
                AND vc.extension = BINARY e.internal
            LEFT JOIN telefonia_ventas_resultados vr
                ON vr.pbx_call_id = BINARY e.pbx_call_id
                AND vr.extension = BINARY e.internal
                AND vr.usuario_id = COALESCE(vc.usuario_id,vm.usuario_id)
            LEFT JOIN interacciones_vinculacion iv
                ON iv.id_externo = e.pbx_call_id
                AND iv.proveedor_externo = 'ZADARMA'
                AND iv.canal = 'LLAMADA_IP'
            LEFT JOIN telefonia_extensiones te
                ON te.extension = e.internal AND te.proveedor = 'ZADARMA'
                AND te.activo = 1
            WHERE $limpieza AND e.received_at >= ? AND e.received_at < ?
                AND e.internal IS NOT NULL AND e.internal <> ''
                AND e.pbx_call_id <> ''
                AND e.evento IN ('NOTIFY_INTERNAL', 'NOTIFY_ANSWER',
                    'NOTIFY_END', 'NOTIFY_OUT_START', 'NOTIFY_OUT_END')
            /*MY_CALLS_CANDIDATE*/
            GROUP BY e.pbx_call_id, e.internal ORDER BY fecha DESC";
        // En historial personal, reducir candidatos en SQL antes de agregar.
        // Conservar todos los eventos de cada tramo candidato (incluidos finales)
        // para no distorsionar la duración ni el estado.
        $personalUid = !empty($filtros['historial_personal_completo'])
            ? max(0, (int)($filtros['usuario_id'] ?? 0)) : 0;
        if ($personalUid > 0) {
            $sql = str_replace('/*MY_CALLS_CANDIDATE*/', "
                AND (
                    EXISTS (SELECT 1 FROM telefonia_ventas_marcaciones pvm
                        WHERE pvm.pbx_call_id=e.pbx_call_id AND pvm.extension=e.internal
                        AND pvm.usuario_id=$personalUid)
                    OR EXISTS (SELECT 1 FROM interacciones_vinculacion piv
                        WHERE piv.id_externo=e.pbx_call_id AND piv.proveedor_externo='ZADARMA'
                        AND piv.canal='LLAMADA_IP' AND piv.usuario_id=$personalUid)
                    OR EXISTS (SELECT 1 FROM telefonia_extensiones pte
                        WHERE pte.extension=e.internal AND pte.proveedor='ZADARMA'
                        AND pte.usuario_id=$personalUid AND pte.activo=1
                        AND pte.permite_entrantes=1
                        AND EXISTS (
                            SELECT 1 FROM telefonia_zadarma_eventos pe
                            WHERE pe.pbx_call_id=e.pbx_call_id AND pe.internal=e.internal
                            AND pe.evento='NOTIFY_INTERNAL'
                            AND pe.received_at >= GREATEST(pte.created_at, pte.updated_at)
                        ))
                )
            ", $sql);
        } else {
            $sql = str_replace('/*MY_CALLS_CANDIDATE*/', '', $sql);
        }
        $stmt = $this->db->prepare($sql);
        if (!$stmt) throw new RuntimeException('No se pudieron preparar las estadísticas de llamadas.');
        $desdeSql = $inicio . ' 00:00:00';
        $hastaSql = date('Y-m-d', strtotime($fin . ' +1 day')) . ' 00:00:00';
        $stmt->bind_param('ss', $desdeSql, $hastaSql);
        $stmt->execute();
        $rs = $stmt->get_result();
        $raw = [];
        $userIds = [];
        while ($row = $rs->fetch_assoc()) {
            $raw[] = $row;
            foreach (['usuario_ventas_max','usuario_tramo_max','usuario_vinculacion_max','usuario_extension'] as $campo) {
                $id = (int)($row[$campo] ?? 0);
                if ($id > 0) $userIds[$id] = $id;
            }
        }
        $stmt->close();

        $usuarios = [];
        if ($userIds) {
            $ids = implode(',', array_map('intval', array_values($userIds)));
            $query = $this->db->query(
                "SELECT u.id, u.nombre, u.apellidos, r.nombre AS rol
                 FROM usuarios u INNER JOIN roles r ON r.id = u.rol_id
                 WHERE u.id IN ($ids)"
            );
            if (!$query) throw new RuntimeException('No se pudieron consultar los responsables telefónicos.');
            while ($user = $query->fetch_assoc()) {
                $usuarios[(int)$user['id']] = [
                    'nombre' => trim((string)$user['nombre'] . ' ' . (string)$user['apellidos']),
                    'rol' => (string)$user['rol']
                ];
            }
        }

        $all = [];
        $missing = 0;
        $seenPbx = [];
        foreach ($raw as $row) {
            $saliente = (int)$row['saliente'] === 1;
            $idMarcacion = (int)($row['usuario_ventas_max'] ?? 0);
            $idTramo = (int)($row['usuario_tramo_max'] ?? 0);
            $idVentas = $idTramo > 0 ? $idTramo : $idMarcacion;
            $idVinculo = (int)($row['usuario_vinculacion_max'] ?? 0);
            $idExtension = (int)($row['usuario_extension'] ?? 0);
            $ambigua =
                ($idVentas > 0 && $idVinculo > 0 && $idVentas !== $idVinculo) ||
                ($idMarcacion > 0 && $idMarcacion !== (int)($row['usuario_ventas_min'] ?? 0)) ||
                ($idTramo > 0 && $idTramo !== (int)($row['usuario_tramo_min'] ?? 0)) ||
                ($idTramo > 0 && $idMarcacion > 0 && $idTramo !== $idMarcacion) ||
                ($idVinculo > 0 && $idVinculo !== (int)($row['usuario_vinculacion_min'] ?? 0));
            $usuarioId = 0;
            $proceso = 'Sin atribución verificada';
            if (!$ambigua && $idVinculo > 0 && isset($usuarios[$idVinculo])) {
                $usuarioId = $idVinculo;
                $proceso = 'Vinculación';
            } elseif (!$ambigua && $idVentas > 0 && isset($usuarios[$idVentas]) && $saliente) {
                $usuarioId = $idVentas;
                $proceso = 'Ventas';
            } elseif (
                !$ambigua && !$saliente && (int)$row['entrante_confirmada'] === 1 &&
                $idExtension > 0 &&
                isset($usuarios[$idExtension]) &&
                (int)$row['permiso_entrada'] === 1 &&
                (string)$row['fecha'] >= (string)$row['asignada_desde']
            ) {
                $usuarioId = $idExtension;
                $proceso = 'Recepción';
            }

            // En la vista PERSONAL una interacción vinculada al PBX global
            // no acredita que cada extensión de una transferencia sea del
            // mismo usuario. Exigir además propiedad de la extensión del tramo.
            if ($personalUid > 0 && $proceso === 'Vinculación'
                && $idExtension !== $idVinculo) {
                $usuarioId = 0;
                $proceso = 'Sin atribución verificada';
            }
            $segundos = max(0, (int)$row['segundos']);
            $conectada = (int)$row['conectada'] === 1;
            $finalizada = (int)($row['finalizada'] ?? 0) === 1;
            $estadoPbx = self::estadoFinalPbx($saliente, $conectada, $finalizada, (string)($row['disposicion_final'] ?? ''));
            $resultado = $proceso === 'Ventas' ?
                strtoupper(trim((string)($row['resultado_ventas'] ?? ''))) :
                ($proceso === 'Vinculación' ?
                    strtoupper(trim((string)($row['resultado_vinculacion'] ?? ''))) : '');
            $notas = (string)($row['notas_vinculacion'] ?? '');
            $contactoNoEfectivo = strpos($notas, '[SIN_CONTACTO_EFECTIVO]') !== false ||
                strpos($notas, '[BUZON_VOZ]') !== false ||
                strpos($notas, '[FUERA_SERVICIO]') !== false;
            $efectiva = ($proceso === 'Ventas' && $resultado === 'CONVERSACION_PERSONA') ||
                ($proceso === 'Vinculación' && !$contactoNoEfectivo &&
                    (strpos($notas, '[CONTACTO_EFECTIVO]') !== false ||
                    in_array($resultado, [
                        'CONTACTADO', 'SOLICITO_INFORMACION',
                        'SOLICITO_LLAMAR_DESPUES', 'NO_INTERESADO'
                    ], true)));
            $pbx = (string)$row['pbx_call_id'];
            $uniqueKey = $pbx !== '' ? $pbx : ((string)$row['fecha'] . '_' . (string)$row['internal']);
            $seenPbx[$uniqueKey] = true;
            if (!$usuarioId) $missing++;
            $all[] = [
                'pbx_call_id' => $pbx,
                'fecha' => (string)$row['fecha'],
                'extension' => (string)$row['internal'],
                'usuario_id' => $usuarioId,
                'usuario' => $usuarioId ? $usuarios[$usuarioId]['nombre'] : 'Sin atribución verificada',
                'rol' => $usuarioId ? $usuarios[$usuarioId]['rol'] : 'Sin atribución',
                'proceso' => $proceso,
                'direccion' => $saliente ? 'Saliente' : 'Entrante',
                'numero' => $saliente ?
                    (string)($row['destino'] ?? '') : (string)($row['origen'] ?? ''),
                'conectada' => $conectada,
                'finalizada' => $finalizada,
                'estado_pbx' => $estadoPbx,
                'efectiva' => $efectiva,
                'resultado' => $resultado,
                'segundos' => $segundos
            ];
        }

        $roles = [];
        $gente = [];
        foreach ($usuarios as $id => $user) {
            $roles[(string)$user['rol']] = (string)$user['rol'];
            $gente[$id] = ['id'=>$id, 'nombre'=>$user['nombre'], 'rol'=>$user['rol']];
        }
        asort($roles);
        uasort($gente, static function ($a, $b) { return strcmp($a['nombre'], $b['nombre']); });

        $rolFiltro = trim((string)($filtros['rol'] ?? ''));
        $usuarioFiltro = (int)($filtros['usuario_id'] ?? 0);
        $direccion = (string)($filtros['direccion'] ?? '');
        if (!in_array($direccion, ['','Entrante','Saliente'], true)) $direccion = '';
        $filtered = array_values(array_filter($all, static function (array $call) use (
            $rolFiltro, $usuarioFiltro, $direccion
        ): bool {
            return ($rolFiltro === '' || $call['rol'] === $rolFiltro) &&
                (!$usuarioFiltro || $call['usuario_id'] === $usuarioFiltro) &&
                ($direccion === '' || $call['direccion'] === $direccion);
        }));

        $stats = [
            'atenciones' => 0, 'llamadas_unicas' => 0, 'conectadas' => 0,
            'efectivas' => 0, 'salientes' => 0, 'entrantes' => 0,
            'segundos' => 0, 'sin_atribucion' => 0,
            'por_usuario' => [], 'por_extension' => [], 'por_rol' => [],
            'tendencia' => [], 'historial' => []
        ];
        $uniqueCalls = [];
        $days = [];
        foreach ($filtered as $call) {
            $stats['atenciones']++;
            $uniqueCalls[$call['pbx_call_id']] = true;
            if ($call['conectada']) $stats['conectadas']++;
            if ($call['efectiva']) $stats['efectivas']++;
            $stats[$call['direccion'] === 'Saliente' ? 'salientes' : 'entrantes']++;
            $stats['segundos'] += $call['segundos'];
            if (!$call['usuario_id']) $stats['sin_atribucion']++;

            $date = substr($call['fecha'], 0, 10);
            if (!isset($days[$date])) $days[$date] = ['fecha'=>$date,'atenciones'=>0,'efectivas'=>0];
            $days[$date]['atenciones']++;
            if ($call['efectiva']) $days[$date]['efectivas']++;

            $ext = $call['extension'];
            if (!isset($stats['por_extension'][$ext])) {
                $stats['por_extension'][$ext] = [
                    'extension'=>$ext,'atenciones'=>0,'segundos'=>0,
                    'conectadas'=>0,'salientes'=>0,'entrantes'=>0
                ];
            }
            $stats['por_extension'][$ext]['atenciones']++;
            $stats['por_extension'][$ext]['segundos'] += $call['segundos'];
            if ($call['conectada']) $stats['por_extension'][$ext]['conectadas']++;
            $stats['por_extension'][$ext][
                $call['direccion'] === 'Saliente' ? 'salientes' : 'entrantes'
            ]++;

            if ($call['usuario_id']) {
                $uid = $call['usuario_id'];
                if (!isset($stats['por_usuario'][$uid])) {
                    $stats['por_usuario'][$uid] = [
                        'usuario_id' => $uid,'usuario'=>$call['usuario'],
                        'rol'=>$call['rol'],'atenciones'=>0,'segundos'=>0,
                        'conectadas'=>0,'efectivas'=>0,'salientes'=>0,'entrantes'=>0
                    ];
                }
                $ref = &$stats['por_usuario'][$uid];
                $ref['atenciones']++;
                $ref['segundos'] += $call['segundos'];
                if ($call['conectada']) $ref['conectadas']++;
                if ($call['efectiva']) $ref['efectivas']++;
                $ref[$call['direccion'] === 'Saliente' ? 'salientes' : 'entrantes']++;
                unset($ref);
            }
            $rol = $call['rol'];
            if (!isset($stats['por_rol'][$rol])) {
                $stats['por_rol'][$rol] = ['rol'=>$rol,'atenciones'=>0,'segundos'=>0,'efectivas'=>0];
            }
            $stats['por_rol'][$rol]['atenciones']++;
            $stats['por_rol'][$rol]['segundos'] += $call['segundos'];
            if ($call['efectiva']) $stats['por_rol'][$rol]['efectivas']++;
        }
        $stats['llamadas_unicas'] = count($uniqueCalls);
        $sortKey = in_array((string)($filtros['orden'] ?? ''), ['segundos','efectivas'], true)
            ? (string)$filtros['orden'] : 'atenciones';
        uasort($stats['por_usuario'], static function ($a, $b) use ($sortKey) {
            return ($b[$sortKey] <=> $a[$sortKey]) ?:
                ($b['atenciones'] <=> $a['atenciones']) ?:
                strcmp($a['usuario'], $b['usuario']);
        });
        uasort($stats['por_extension'], static function ($a, $b) {
            return ($b['atenciones'] <=> $a['atenciones']);
        });
        uasort($stats['por_rol'], static function ($a, $b) {
            return $b['atenciones'] <=> $a['atenciones'];
        });
        $stats['por_usuario'] = array_values($stats['por_usuario']);
        $stats['por_extension'] = array_values($stats['por_extension']);
        $stats['por_rol'] = array_values($stats['por_rol']);

        // La gráfica muestra hasta 14 días consecutivos del intervalo
        // seleccionado, sin inventar datos ni confundir atenciones con llamadas únicas.
        $finTendencia = $fin;
        $inicioTendencia = max($inicio, date('Y-m-d', strtotime($fin . ' -13 days')));
        for ($cursor = $inicioTendencia; $cursor <= $finTendencia;
             $cursor = date('Y-m-d', strtotime($cursor . ' +1 day'))) {
            $stats['tendencia'][] = $days[$cursor] ??
                ['fecha'=>$cursor,'atenciones'=>0,'efectivas'=>0];
        }

        $stats['historial'] = !empty($filtros['historial_personal_completo']) && $usuarioFiltro > 0
            ? $filtered : array_slice($filtered, 0, 500);
        $stats['historial_total'] = count($filtered);
        return [
            'filtros' => [
                'desde'=>$inicio, 'hasta'=>$fin, 'rol'=>$rolFiltro,
                'usuario_id'=>$usuarioFiltro,'direccion'=>$direccion,'orden'=>$sortKey
            ],
            'roles'=>$roles,'usuarios'=>$gente,'stats'=>$stats,
            'observacion' =>
                'Duraciones observadas por webhooks, no minutos facturados. ' .
                'Las atenciones pueden repetirse entre extensiones cuando existe una transferencia.'
        ];
    }
}
