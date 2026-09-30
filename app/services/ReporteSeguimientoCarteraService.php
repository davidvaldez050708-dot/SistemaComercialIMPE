<?php

require_once __DIR__ . '/../../config/db_connection.php';

class ReporteSeguimientoCarteraService
{
    private $connection;

    private const ETAPAS = [
        'DATOS_CONTACTO' => 'Datos de contacto',
        'OFICIO_INSTITUCIONAL' => 'Oficio institucional',
        'RESPUESTA_INSTITUCION' => 'Respuesta de la institución',
        'REUNION' => 'Reunión',
        'CONVENIO_FORMALIZACION' => 'Convenio / formalización',
        'DESCARTADO' => 'Descartado'
    ];

    public function __construct()
    {
        $database = new Database();
        $this->connection = $database->connect();
    }

    public function enriquecer(array $seguimientos): array
    {
        if (empty($seguimientos)) {
            return [];
        }

        $ids = array_values(array_unique(array_filter(array_map(
            static function ($seguimiento) {
                return max(0, (int)($seguimiento['id'] ?? 0));
            },
            $seguimientos
        ))));

        if (empty($ids)) {
            return $seguimientos;
        }

        $metadatos = $this->obtenerMetadatos($ids);

        foreach ($seguimientos as &$seguimiento) {
            $id = (int)($seguimiento['id'] ?? 0);
            $meta = $metadatos[$id] ?? [];

            $ultimaHumana = trim((string)($meta['ultima_interaccion_humana_at'] ?? ''));
            $canalHumano = strtoupper(trim((string)($meta['ultimo_canal_humano'] ?? '')));

            $seguimiento['ultima_interaccion_humana_at'] = $ultimaHumana;
            $seguimiento['ultimo_canal_humano'] = $canalHumano;

            $etapa = $this->resolverEtapa($seguimiento, $meta);
            $seguimiento['etapa_operativa_codigo'] = $etapa['codigo'];
            $seguimiento['etapa_operativa_label'] = $etapa['label'];
            $seguimiento['etapa_operativa_paso'] = $etapa['paso'];
            $seguimiento['ruta_concluida'] = $etapa['codigo'] === 'CONVENIO_FORMALIZACION'
                && trim((string)($meta['convenio_formalizado_at'] ?? '')) !== '';
            $seguimiento['convenio_formalizado'] = $seguimiento['ruta_concluida'];

            $dias = $this->diasSinActividad($ultimaHumana);
            $seguimiento['dias_sin_actividad_humana'] = $dias;

            $atencion = $this->resolverAtencion($seguimiento, $etapa, $dias);
            $seguimiento['atencion_codigo'] = $atencion['codigo'];
            $seguimiento['atencion_label'] = $atencion['label'];
            $seguimiento['prioridad_orden'] = $atencion['orden'];
            $seguimiento['accion_vencida'] = $atencion['codigo'] === 'VENCIDA';

            $accionOperativa = $this->resolverAccionOperativa(
                $seguimiento,
                $meta,
                $etapa
            );
            $seguimiento['accion_operativa_label'] = $accionOperativa['label'];
            $seguimiento['accion_operativa_fuente'] = $accionOperativa['fuente'];

            if (trim((string)($meta['convenio_formalizado_at'] ?? '')) !== '') {
                $seguimiento['convenio_formalizado_at'] =
                    (string)$meta['convenio_formalizado_at'];
            }
        }
        unset($seguimiento);

        usort($seguimientos, static function ($a, $b) {
            $prioridad = (int)($a['prioridad_orden'] ?? 99)
                <=> (int)($b['prioridad_orden'] ?? 99);
            if ($prioridad !== 0) {
                return $prioridad;
            }

            $diasA = $a['dias_sin_actividad_humana'] ?? -1;
            $diasB = $b['dias_sin_actividad_humana'] ?? -1;
            if ($diasA !== $diasB) {
                return (int)$diasB <=> (int)$diasA;
            }

            return strcasecmp(
                (string)($a['nombre_entidad'] ?? ''),
                (string)($b['nombre_entidad'] ?? '')
            );
        });

        return $seguimientos;
    }

    public function etapas(): array
    {
        return self::ETAPAS;
    }

    private function obtenerMetadatos(array $ids): array
    {
        $lista = implode(',', array_map('intval', $ids));
        if ($lista === '') {
            return [];
        }

        $postDisponible = $this->tablaDisponible('seguimientos_vinculacion_post_envio');
        $reunionesDisponibles = $this->tablaDisponible('reuniones_vinculacion');

        $camposPost = '';
        foreach ([
            'respuesta_at',
            'seguimiento_correo_at',
            'coordinacion_reunion_habilitada_at',
            'reunion_agendada_at',
            'reunion_realizada_at',
            'convenio_formalizado_at'
        ] as $columnaPost) {
            $disponible = $postDisponible &&
                $this->columnaDisponible(
                    'seguimientos_vinculacion_post_envio',
                    $columnaPost
                );

            $camposPost .= $disponible
                ? 'post.' . $columnaPost . ' AS ' . $columnaPost . ','
                : 'NULL AS ' . $columnaPost . ',';
        }

        $joinPost = $postDisponible
            ? "LEFT JOIN seguimientos_vinculacion_post_envio post
                   ON post.seguimiento_id = s.id"
            : "";

        $reunionId = "NULL AS reunion_actual_id,";
        $reunionEstado = "NULL AS reunion_actual_estado,";
        $reunionRealizada = "NULL AS reunion_actual_realizada_at,";

        if ($reunionesDisponibles) {
            $reunionId = "(
                    SELECT r.id
                    FROM reuniones_vinculacion r
                    WHERE r.seguimiento_id = s.id
                      AND UPPER(TRIM(COALESCE(r.estado, ''))) <> 'CANCELADA'
                    ORDER BY r.id DESC
                    LIMIT 1
                ) AS reunion_actual_id,";

            if ($this->columnaDisponible('reuniones_vinculacion', 'estado')) {
                $reunionEstado = "(
                    SELECT r.estado
                    FROM reuniones_vinculacion r
                    WHERE r.seguimiento_id = s.id
                      AND UPPER(TRIM(COALESCE(r.estado, ''))) <> 'CANCELADA'
                    ORDER BY r.id DESC
                    LIMIT 1
                ) AS reunion_actual_estado,";
            }

            if ($this->columnaDisponible('reuniones_vinculacion', 'realizada_at')) {
                $reunionRealizada = "(
                    SELECT r.realizada_at
                    FROM reuniones_vinculacion r
                    WHERE r.seguimiento_id = s.id
                      AND UPPER(TRIM(COALESCE(r.estado, ''))) <> 'CANCELADA'
                    ORDER BY r.id DESC
                    LIMIT 1
                ) AS reunion_actual_realizada_at,";
            }
        }

        $sql = "SELECT
                    s.id,
                    s.estado_seguimiento,
                    s.proxima_accion_at,
                    $camposPost
                    $reunionId
                    $reunionEstado
                    $reunionRealizada
                    (
                        SELECT i.fecha_inicio
                        FROM interacciones_vinculacion i
                        WHERE i.seguimiento_id = s.id
                          AND UPPER(TRIM(COALESCE(i.canal, ''))) <> 'SISTEMA'
                        ORDER BY i.fecha_inicio DESC, i.id DESC
                        LIMIT 1
                    ) AS ultima_interaccion_humana_at,
                    (
                        SELECT i.canal
                        FROM interacciones_vinculacion i
                        WHERE i.seguimiento_id = s.id
                          AND UPPER(TRIM(COALESCE(i.canal, ''))) <> 'SISTEMA'
                        ORDER BY i.fecha_inicio DESC, i.id DESC
                        LIMIT 1
                    ) AS ultimo_canal_humano,
                    (
                        SELECT o.folio
                        FROM oficios_vinculacion o
                        WHERE o.seguimiento_id = s.id
                        ORDER BY o.id DESC
                        LIMIT 1
                    ) AS ultimo_folio,
                    (
                        SELECT o.estado_oficio
                        FROM oficios_vinculacion o
                        WHERE o.seguimiento_id = s.id
                        ORDER BY o.id DESC
                        LIMIT 1
                    ) AS ultimo_estado_oficio,
                    (
                        SELECT o.fecha_envio
                        FROM oficios_vinculacion o
                        WHERE o.seguimiento_id = s.id
                        ORDER BY o.id DESC
                        LIMIT 1
                    ) AS ultimo_envio_at
                FROM seguimientos_vinculacion s
                $joinPost
                WHERE s.id IN ($lista)";

        $resultado = $this->connection->query($sql);
        if (!$resultado) {
            return [];
        }

        $mapa = [];
        while ($fila = $resultado->fetch_assoc()) {
            $mapa[(int)($fila['id'] ?? 0)] = $fila;
        }

        return $mapa;
    }

    private function resolverEtapa(array $seguimiento, array $meta): array
    {
        $estado = strtoupper(trim((string)($seguimiento['estado_seguimiento'] ?? '')));
        if ($estado === 'DESCARTADO') {
            return $this->etapa('DESCARTADO', 0);
        }

        $convenio = trim((string)($meta['convenio_formalizado_at'] ?? ''));
        if ($convenio !== '') {
            return $this->etapa('CONVENIO_FORMALIZACION', 13);
        }

        $reunionRealizada = trim((string)($meta['reunion_actual_realizada_at'] ?? ''));
        if ($reunionRealizada === '') {
            $reunionRealizada = trim((string)($meta['reunion_realizada_at'] ?? ''));
        }
        if (
            $reunionRealizada !== '' ||
            strtoupper(trim((string)($meta['reunion_actual_estado'] ?? ''))) === 'REALIZADA'
        ) {
            return $this->etapa('CONVENIO_FORMALIZACION', 13);
        }

        if (
            (int)($meta['reunion_actual_id'] ?? 0) > 0 ||
            trim((string)($meta['reunion_agendada_at'] ?? '')) !== '' ||
            trim((string)($meta['coordinacion_reunion_habilitada_at'] ?? '')) !== ''
        ) {
            return $this->etapa('REUNION', 11);
        }

        if (
            trim((string)($meta['respuesta_at'] ?? '')) !== '' ||
            trim((string)($meta['seguimiento_correo_at'] ?? '')) !== '' ||
            trim((string)($meta['ultimo_envio_at'] ?? '')) !== '' ||
            strtoupper(trim((string)($meta['ultimo_estado_oficio'] ?? ''))) === 'ENVIADO' ||
            $estado === 'ESPERANDO_RESPUESTA'
        ) {
            return $this->etapa('RESPUESTA_INSTITUCION', 8);
        }

        if (
            trim((string)($meta['ultimo_folio'] ?? '')) !== '' ||
            in_array($estado, ['DATOS_VERIFICADOS', 'OFICIO_PREPARADO'], true)
        ) {
            return $this->etapa('OFICIO_INSTITUCIONAL', 5);
        }

        return $this->etapa('DATOS_CONTACTO', 1);
    }

    private function etapa(string $codigo, int $paso): array
    {
        return [
            'codigo' => $codigo,
            'label' => self::ETAPAS[$codigo] ?? $codigo,
            'paso' => $paso
        ];
    }

    private function resolverAccionOperativa(
        array $seguimiento,
        array $meta,
        array $etapa
    ): array {
        $textoProgramado = trim((string)($seguimiento['proxima_accion_texto'] ?? ''));
        if ($textoProgramado !== '' && $textoProgramado !== '—') {
            return [
                'label' => $textoProgramado,
                'fuente' => 'PROGRAMADA'
            ];
        }

        $fechaProgramada = trim((string)($seguimiento['proxima_accion_at'] ?? ''));
        if ($fechaProgramada !== '') {
            return [
                'label' => 'Seguimiento programado',
                'fuente' => 'PROGRAMADA'
            ];
        }

        if (!empty($seguimiento['ruta_concluida'])) {
            return [
                'label' => 'Ruta concluida',
                'fuente' => 'CIERRE'
            ];
        }

        $codigo = (string)($etapa['codigo'] ?? '');

        if ($codigo === 'DESCARTADO') {
            return [
                'label' => 'Sin acciones pendientes',
                'fuente' => 'CIERRE'
            ];
        }

        if ($codigo === 'CONVENIO_FORMALIZACION') {
            return [
                'label' => 'Formalizar convenio',
                'fuente' => 'ETAPA'
            ];
        }

        if ($codigo === 'REUNION') {
            return [
                'label' => 'Continuar coordinación de reunión',
                'fuente' => 'ETAPA'
            ];
        }

        if ($codigo === 'RESPUESTA_INSTITUCION') {
            $respuestaRegistrada = trim((string)($meta['respuesta_at'] ?? '')) !== '';
            return [
                'label' => $respuestaRegistrada
                    ? 'Continuar seguimiento a la respuesta'
                    : 'Esperar / registrar respuesta',
                'fuente' => 'ETAPA'
            ];
        }

        if ($codigo === 'OFICIO_INSTITUCIONAL') {
            return [
                'label' => 'Preparar / enviar oficio',
                'fuente' => 'ETAPA'
            ];
        }

        if ($codigo === 'DATOS_CONTACTO') {
            return [
                'label' => 'Continuar contacto y validación',
                'fuente' => 'ETAPA'
            ];
        }

        return [
            'label' => 'Sin acción programada',
            'fuente' => 'NINGUNA'
        ];
    }

    private function resolverAtencion(array $seguimiento, array $etapa, $dias): array
    {
        if ($etapa['codigo'] === 'DESCARTADO') {
            return ['codigo' => 'DESCARTADO', 'label' => 'Descartado', 'orden' => 6];
        }

        if (!empty($seguimiento['ruta_concluida'])) {
            return ['codigo' => 'FORMALIZADO', 'label' => 'Convenio formalizado', 'orden' => 5];
        }

        $proxima = trim((string)($seguimiento['proxima_accion_at'] ?? ''));
        if ($proxima !== '') {
            try {
                if (new DateTimeImmutable($proxima) < new DateTimeImmutable()) {
                    return ['codigo' => 'VENCIDA', 'label' => 'Acción vencida', 'orden' => 0];
                }
            } catch (Throwable $error) {
            }
        }

        if ($dias === null) {
            return ['codigo' => 'SIN_ACTIVIDAD', 'label' => 'Sin actividad registrada', 'orden' => 1];
        }

        if ((int)$dias > 7) {
            return ['codigo' => 'INACTIVA', 'label' => 'Más de 7 días sin actividad', 'orden' => 2];
        }

        return ['codigo' => 'EN_SEGUIMIENTO', 'label' => 'En seguimiento', 'orden' => 3];
    }

    private function diasSinActividad($fecha)
    {
        $fecha = trim((string)$fecha);
        if ($fecha === '') {
            return null;
        }

        try {
            $ultima = (new DateTimeImmutable($fecha))->setTime(0, 0, 0);
            $hoy = (new DateTimeImmutable('today'))->setTime(0, 0, 0);
            if ($ultima >= $hoy) {
                return 0;
            }
            return (int)$ultima->diff($hoy)->days;
        } catch (Throwable $error) {
            return null;
        }
    }

    private function tablaDisponible(string $tabla): bool
    {
        $tabla = preg_replace('/[^a-zA-Z0-9_]+/', '', $tabla);
        if ($tabla === '') {
            return false;
        }

        $resultado = $this->connection->query("SHOW TABLES LIKE '" . $tabla . "'");
        return $resultado && $resultado->num_rows > 0;
    }

    private function columnaDisponible(string $tabla, string $columna): bool
    {
        $tabla = preg_replace('/[^a-zA-Z0-9_]+/', '', $tabla);
        $columna = preg_replace('/[^a-zA-Z0-9_]+/', '', $columna);
        if ($tabla === '' || $columna === '') {
            return false;
        }

        $resultado = $this->connection->query(
            "SHOW COLUMNS FROM " . $tabla . " LIKE '" . $columna . "'"
        );
        return $resultado && $resultado->num_rows > 0;
    }
}
