<?php
/**
 * Bandeja personal de solo lectura.
 * Primera etapa: reusa la conciliación PBX ya existente, pero solo expone
 * tramos con atribución verificada al usuario autenticado.
 * No reemplaza Marketing, Seguimiento, Ventas ni expedientes.
 */
require_once __DIR__ . '/TelefoniaControlService.php';

final class TelefoniaMisLlamadasService
{
    /**
     * Siempre filtrar en servidor, incluso si el servicio de control cambia
     * el significado de su parámetro usuario_id.
     */
    public static function filtrarPropias(array $filas, int $usuarioId): array
    {
        if ($usuarioId <= 0) return [];
        return array_values(array_filter($filas, static function ($item) use ($usuarioId): bool {
            return is_array($item)
                && (int)($item['usuario_id'] ?? 0) === $usuarioId
                && trim((string)($item['pbx_call_id'] ?? '')) !== '';
        }));
    }

    /**
     * Adjunta resultados de devoluciones sólo a tramos que ya pertenecen al
     * usuario y tienen el mismo PBX ID. No crea filas telefónicas duplicadas.
     */
    public static function enriquecerDevoluciones(array $filas, array $intentos, int $usuarioId): array
    {
        if ($usuarioId <= 0) return [];
        $porPbx = [];
        foreach ($intentos as $intento) {
            if (!is_array($intento) || (int)($intento['usuario_id'] ?? 0) !== $usuarioId) continue;
            $pbx = trim((string)($intento['pbx_call_id'] ?? ''));
            if ($pbx === '' || (string)($intento['resultado'] ?? '') === '') continue;
            // La consulta llega de más reciente a más antiguo; conservar el último resultado.
            if (isset($porPbx[$pbx])) continue;
            $porPbx[$pbx] = [
                'resultado_devolucion' => (string)$intento['resultado'],
                'intento_devolucion' => (int)($intento['id'] ?? 0),
                'seguimiento_id' => max(0, (int)($intento['seguimiento_id'] ?? 0)),
                'extension' => trim((string)($intento['extension'] ?? ''))
            ];
        }
        foreach ($filas as &$fila) {
            if (!is_array($fila) || (int)($fila['usuario_id'] ?? 0) !== $usuarioId) continue;
            $pbx = trim((string)($fila['pbx_call_id'] ?? ''));
            if ($pbx !== '' && isset($porPbx[$pbx])
                && ($fila['direccion'] ?? '') === 'Saliente'
                && $porPbx[$pbx]['extension'] !== ''
                && $porPbx[$pbx]['extension'] === trim((string)($fila['extension'] ?? ''))) {
                $fila = array_merge($fila, $porPbx[$pbx]);
            }
        }
        unset($fila);
        return $filas;
    }

    /**
     * Fuente propia de Marketing, basada en inicios PBX firmados de su extensión.
     * La bandeja de recepción limita su historial a los últimos 30 días.
     */
    public static function normalizarMarketing(array $llamadas, int $uid, string $extension): array
    {
        if ($uid <= 0 || $extension === '') return [];
        $filas = [];
        foreach ($llamadas as $call) {
            if (!is_array($call)) continue;
            $id = trim((string)($call['pbx_call_id'] ?? ''));
            $tipo = (string)($call['tipo'] ?? '');
            if ($id === '' || !in_array($tipo, ['Entrante', 'Saliente'], true)) continue;
            $filas[] = [
                'pbx_call_id' => $id, 'usuario_id' => $uid, 'extension' => $extension,
                'proceso' => 'Recepción', 'direccion' => $tipo,
                'numero' => (string)($call['numero'] ?? ''),
                'fecha' => (string)($call['fecha'] ?? ''),
                'segundos' => max(0, (int)($call['segundos'] ?? 0)),
                'conectada' => !empty($call['contestada']),
                'resultado' => (string)($call['resultado_etiqueta'] ?? ''),
                'estado_recepcion' => (string)($call['estado'] ?? ''),
                'transferida' => (string)($call['estado'] ?? '') === 'Transferida'
            ];
        }
        return $filas;
    }

    /**
     * Cruza la vista técnica con la bandeja propia ya autorizada del Analista.
     * El PBX se identifica por referencia exacta y extensión; las observaciones
     * SDK mantienen su origen distinto y nunca acreditan verificación efectiva.
     */
    public static function integrarAnalista(
        array $pbx, array $operativas, int $usuarioId, string $extension,
        string $desde, string $hasta, string $direccion = ''
    ): array {
        if ($usuarioId <= 0 || !preg_match('/^[0-9]{3,6}$/', $extension)) return $pbx;
        $index = [];
        foreach ($pbx as $i => $fila) {
            if ((int)($fila['usuario_id'] ?? 0) !== $usuarioId ||
                (string)($fila['extension'] ?? '') !== $extension) continue;
            $id = (string)($fila['pbx_call_id'] ?? '');
            if ($id !== '') $index[$id] = $i;
        }
        foreach ($operativas as $item) {
            if (!is_array($item)) continue;
            $id = (string)($item['pbx_call_id'] ?? '');
            $fuente = (string)($item['fuente'] ?? '');
            $sentido = (string)($item['tipo_llamada'] ?? '');
            if ($id === '' || !preg_match('/^[a-zA-Z0-9_-]{3,100}$/D', $id) ||
                !in_array($fuente, ['PBX', 'SDK_WEBRTC'], true) ||
                !in_array($sentido, ['SALIENTE', 'ENTRANTE', 'INTERNA'], true)) continue;
            $tipo = $sentido === 'SALIENTE' ? 'Saliente' : 'Entrante';
            if ($direccion !== '' && $direccion !== $tipo) continue;
            $fecha = (string)($item['fecha'] ?? '');
            $dia = substr($fecha, 0, 10);
            if ($dia < $desde || $dia > $hasta ||
                !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $fecha)) continue;
            $clasificacion = (string)($item['clasificacion'] ?? 'PENDIENTE');
            if (!in_array($clasificacion, ['PENDIENTE','SIN_IDENTIFICAR','VINCULADA','INTERNA'], true))
                $clasificacion = 'PENDIENTE';
            $confirmada = $fuente === 'PBX' && !empty($item['pbx_conversacion_confirmada']);
            if (isset($index[$id])) {
                $i = $index[$id];
                if ((string)($pbx[$i]['resultado'] ?? '') === '' &&
                    $clasificacion !== 'PENDIENTE') {
                    $pbx[$i]['resultado'] = $clasificacion === 'VINCULADA'
                        ? 'REGISTRADA' : $clasificacion;
                }
                $pbx[$i]['detalle_operativo'] = true;
                continue;
            }
            $pbx[] = [
                'pbx_call_id'=>$id,
                'fecha'=>$fecha,
                'extension'=>$extension,
                'usuario_id'=>$usuarioId,
                'proceso'=>$fuente === 'PBX' ? 'Vinculación' : 'WebRTC observado',
                'direccion'=>$tipo,
                'numero'=>(string)($item['numero'] ?? ''),
                'conectada'=>$confirmada,
                'observada'=>$fuente === 'SDK_WEBRTC' && !empty($item['contestada']),
                'efectiva'=>false,
                'resultado'=>$clasificacion === 'VINCULADA' ? 'REGISTRADA' :
                    ($clasificacion === 'PENDIENTE' ? '' : $clasificacion),
                'segundos'=>max(0, (int)($item['duracion'] ?? 0)),
                'fuente_operativa'=>$fuente,
                'detalle_operativo'=>true
            ];
            $index[$id] = count($pbx) - 1;
        }
        usort($pbx, static function (array $a, array $b): int {
            return strcmp((string)($b['fecha'] ?? ''), (string)($a['fecha'] ?? ''));
        });
        return $pbx;
    }

    private function consultarDevoluciones(int $usuarioId, string $desde, string $hasta): array
    {
        // No instanciar TelefoniaDevolucionService: su constructor prepara
        // tablas y esta consulta debe ser estrictamente de solo lectura.
        $db = (new Database())->connect();
        $table = $db->query("SHOW TABLES LIKE 'telefonia_devolucion_intentos'");
        if (!$table || $table->num_rows === 0) return [];
        $sql = "SELECT id, usuario_id, extension, pbx_call_id, resultado, seguimiento_id
                FROM telefonia_devolucion_intentos
                WHERE usuario_id = ? AND created_at >= ? AND created_at < ?
                AND pbx_call_id IS NOT NULL AND pbx_call_id <> ''
                AND resultado <> ''
                ORDER BY id DESC";
        $stmt = $db->prepare($sql);
        $inicio = $desde . ' 00:00:00';
        $fin = date('Y-m-d', strtotime($hasta . ' +1 day')) . ' 00:00:00';
        $stmt->bind_param('iss', $usuarioId, $inicio, $fin);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    /** Paginar después del filtro de propiedad, nunca antes. */
    public static function paginar(array $filas, int $pagina, int $porPagina = 20): array
    {
        $porPagina = min(100, max(1, $porPagina));
        $total = count($filas);
        $paginas = max(1, (int)ceil($total / $porPagina));
        $pagina = min($paginas, max(1, $pagina));
        return [
            'filas' => array_slice($filas, ($pagina - 1) * $porPagina, $porPagina),
            'pagina' => $pagina,
            'paginas' => $paginas,
            'por_pagina' => $porPagina
        ];
    }

    public function consultar(int $usuarioId, array $filtros = []): array
    {
        if ($usuarioId <= 0) {
            throw new DomainException('Inicia sesión para consultar tus llamadas.');
        }
        // Fechas validadas por el servicio de Control; Marketing usa esta
        // validación antes de leer la recepción (solo 30 días disponibles).
        $control = (new TelefoniaControlService())->consultar([
            'desde' => (string)($filtros['desde'] ?? date('Y-m-d', strtotime('-29 days'))),
            'hasta' => (string)($filtros['hasta'] ?? date('Y-m-d')),
            'usuario_id' => $usuarioId,
            'historial_personal_completo' => true,
            'direccion' => in_array(($filtros['direccion'] ?? ''), ['Entrante','Saliente'], true)
                ? $filtros['direccion'] : ''
        ]);

        $esMarketing = strcasecmp(trim((string)($_SESSION['rol'] ?? '')), 'Marketing') === 0;
        if ($esMarketing) {
            require_once __DIR__ . '/TelefoniaRecepcionMarketingService.php';
            if (!TelefoniaRecepcionMarketingService::tienePermisos()) {
                throw new DomainException('No tienes acceso a la recepción telefónica.');
            }
            $recepcion = (new TelefoniaRecepcionMarketingService())->resumen($usuarioId);
            $rows = self::normalizarMarketing(
                $recepcion['llamadas'] ?? [], $usuarioId, (string)($recepcion['extension'] ?? '')
            );
            $desde = (string)$control['filtros']['desde'];
            $hasta = (string)$control['filtros']['hasta'];
            $direccion = (string)($control['filtros']['direccion'] ?? '');
            $rows = array_values(array_filter($rows, static function (array $r) use ($desde, $hasta, $direccion): bool {
                $dia = substr((string)$r['fecha'], 0, 10);
                return $dia >= $desde && $dia <= $hasta
                    && ($direccion === '' || $direccion === $r['direccion']);
            }));
        } else {
            $rows = self::filtrarPropias($control['stats']['historial'] ?? [], $usuarioId);
            $rows = self::enriquecerDevoluciones($rows, $this->consultarDevoluciones(
                $usuarioId, (string)$control['filtros']['desde'], (string)$control['filtros']['hasta']
            ), $usuarioId);
            if (strcasecmp(trim((string)($_SESSION['rol'] ?? '')), 'Analista de Datos') === 0) {
                try {
                    require_once __DIR__ . '/TelefoniaEntrantesAnalistaService.php';
                    $bandeja = (new TelefoniaEntrantesAnalistaService())->listar($usuarioId);
                    $rows = self::integrarAnalista(
                        $rows, $bandeja['llamadas'] ?? [], $usuarioId,
                        (string)($bandeja['extension'] ?? ''),
                        (string)$control['filtros']['desde'],
                        (string)$control['filtros']['hasta'],
                        (string)($control['filtros']['direccion'] ?? '')
                    );
                } catch (Throwable $error) {
                    // La bandeja operativa no debe impedir consultar PBX si
                    // temporalmente falta un componente ajeno a esa lectura.
                    error_log('[mis_llamadas_analista_operativas] '.get_class($error));
                }
            }
        }
        // Un identificador PBX puede tener varias extensiones: en esta vista,
        // cada fila es un tramo atribuido, no una nueva llamada externa.
        $total = count($rows);
        $conectadas = count(array_filter($rows, static fn(array $r): bool => !empty($r['conectada'])));
        $paginado = self::paginar($rows, (int)($filtros['pagina'] ?? 1));
        return [
            'filas' => $paginado['filas'],
            'pagina' => $paginado['pagina'],
            'paginas' => $paginado['paginas'],
            'por_pagina' => $paginado['por_pagina'],
            'total' => $total,
            'conectadas' => $conectadas,
            'entrantes' => count(array_filter($rows, static fn(array $r): bool => ($r['direccion'] ?? '') === 'Entrante')),
            'salientes' => count(array_filter($rows, static fn(array $r): bool => ($r['direccion'] ?? '') === 'Saliente')),
            'filtros' => $control['filtros'],
            'limitacion' => 'Vista inicial de solo lectura: muestra tramos del intervalo consultado, con atribución verificada y paginación de 20 registros. No incluye devoluciones sin PBX confirmado ni registros ambiguos. Marketing conserva una ventana de 30 días; las grabaciones se consultan en sus módulos de origen.'
        ];
    }
}
