<?php
/**
 * Pendientes vivos de convocatoria por aliado/programa.
 *
 * No almacena una segunda verdad ni marca tareas como atendidas al leer.
 * La publicación ligada al mismo origen y programa es la condición de cierre;
 * se incluye una publicación futura ya creada (estado=0).
 *
 * Cuenta Clave: sólo sus aliados institucionales asignados y los manuales de
 * territorios vigentes. Marketing: cobertura nacional, sin cambiar permisos.
 */
final class AliadoConvocatoriaPendienteService
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    private function tabla(string $nombre): bool
    {
        static $permitidas = [
            'aliados_asignaciones', 'aliados_manuales',
            'seguimientos_vinculacion', 'seguimientos_vinculacion_post_envio',
            'formulario_aliado_registro', 'formulario_aliado_programas',
            'formulario_programas', 'marketing_aliado_convocatorias_origen',
            'marketing_aliado_manual_convocatorias',
            'marketing_convenio_convocatorias', 'formulario_aliado_convocatorias',
            'convocatorias', 'asignaciones_territorio'
        ];
        if (!in_array($nombre, $permitidas, true)) {
            throw new InvalidArgumentException('Tabla no autorizada.');
        }
        $s = $this->db->prepare(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1'
        );
        $s->bind_param('s', $nombre);
        $s->execute();
        $ok = $s->get_result()->num_rows > 0;
        $s->close();
        return $ok;
    }

    /** Devuelve avisos de trabajo, no nuevos mensajes individuales leídos/no leídos. */
    public function obtener(int $usuarioId, bool $marketing, int $limite = 40): array
    {
        if ($usuarioId <= 0) return [];
        $limite = max(1, min(100, $limite));
        foreach ([
            'aliados_asignaciones', 'aliados_manuales', 'seguimientos_vinculacion',
            'seguimientos_vinculacion_post_envio', 'formulario_aliado_registro',
            'formulario_aliado_programas', 'formulario_programas',
            'marketing_aliado_convocatorias_origen', 'convocatorias'
        ] as $tabla) {
            // En instalaciones anteriores a V5 no inventar pendientes de tareas.
            if (!$this->tabla($tabla)) return [];
        }
        if (!$marketing && !$this->tabla('asignaciones_territorio')) return [];

        $asignacion = "asig.usuario_id=? AND asig.estado_id=%s
            AND asig.tipo_asignacion='CUENTA_CLAVE' AND asig.activo=1
            AND (asig.fecha_inicio IS NULL OR asig.fecha_inicio<=CURDATE())
            AND (asig.fecha_fin IS NULL OR asig.fecha_fin>=CURDATE())";
        $condicionInstitucional = '';
        $condicionManual = '';
        $parametros = [];
        if (!$marketing) {
            $condicionInstitucional = " AND aa.cuenta_clave_usuario_id=?
                AND EXISTS (SELECT 1 FROM asignaciones_territorio asig WHERE "
                    .sprintf($asignacion, 's.estado_id').")";
            $condicionManual = " AND EXISTS (SELECT 1 FROM asignaciones_territorio asig WHERE "
                    .sprintf($asignacion, 'm.estado_id').")";
            $parametros = [$usuarioId, $usuarioId, $usuarioId];
        }
        $historicoManual = '';
        if ($this->tabla('marketing_aliado_manual_convocatorias')) {
            $historicoManual = " AND NOT EXISTS (
                SELECT 1 FROM marketing_aliado_manual_convocatorias anterior
                INNER JOIN convocatorias c ON c.id=anterior.convocatoria_id
                WHERE origen.origen_tipo='manual' AND anterior.manual_id=origen.origen_id
                  AND anterior.programa_codigo=programas.programa_codigo
            )";
        }
        $historicoInstitucional = '';
        if ($this->tabla('marketing_convenio_convocatorias')) {
            $clasificaciones = [
                'bachillerato|bachillerato-general' => 'bachillerato-general',
                'bachillerato|bachillerato-2-anos' => 'bachillerato-2-anos',
                'bachillerato|ingles' => 'ingles',
                'titulacion|ejecutivas' => 'ejecutivas',
                'titulacion|experiencia-laboral' => 'titulacion-experiencia-laboral'
            ];
            $casos = [];
            foreach ($clasificaciones as $clase => $codigo) {
                $casos[] = "WHEN '".$clase."' THEN '".$codigo."'";
            }
            // inscripciones-abiertas tiene dos clasificaciones válidas.
            $casos[] = "WHEN 'bachillerato|inscripciones-abiertas' THEN 'inscripciones-abiertas'";
            $casos[] = "WHEN 'titulacion|inscripciones-abiertas' THEN 'inscripciones-abiertas'";
            $historicoInstitucional = " AND NOT EXISTS (
                SELECT 1 FROM marketing_convenio_convocatorias anterior
                INNER JOIN convocatorias c ON c.id=anterior.convocatoria_id
                WHERE origen.origen_tipo='institucional'
                  AND anterior.seguimiento_id=origen.origen_id
                  AND (CASE CONCAT(c.tipo_convocatoria,'|',c.subtipo_convocatoria) "
                    .implode(' ', $casos)." END)=programas.programa_codigo
            )";
        }
        $historicoFormulario = '';
        if ($this->tabla('formulario_aliado_convocatorias')) {
            $historicoFormulario = " AND NOT EXISTS (
                SELECT 1 FROM formulario_aliado_convocatorias anterior
                INNER JOIN convocatorias c ON c.id=anterior.convocatoria_id
                WHERE anterior.aliado_id=perfil.aliado_id AND anterior.formulario='registro'
                  AND anterior.programa_codigo=programas.programa_codigo
            )";
        }
        $sql = "
            SELECT origen.origen_tipo, origen.origen_id, origen.estado_id,
                   origen.nombre_entidad, origen.fecha_alta,
                   programas.programa_codigo, catalogo.nombre AS programa_nombre
            FROM (
                SELECT 'institucional' AS origen_tipo, s.id AS origen_id,
                       s.estado_id, s.nombre_entidad,
                       COALESCE(post.convenio_formalizado_at,aa.asignado_at) AS fecha_alta
                FROM aliados_asignaciones aa
                INNER JOIN seguimientos_vinculacion s ON s.id=aa.seguimiento_id
                INNER JOIN seguimientos_vinculacion_post_envio post ON post.seguimiento_id=s.id
                WHERE aa.activo=1 AND s.activo=1
                  AND post.convenio_formalizado_at IS NOT NULL
                  $condicionInstitucional
                UNION ALL
                SELECT 'manual' AS origen_tipo, m.id AS origen_id,
                       m.estado_id, m.nombre_entidad, m.created_at AS fecha_alta
                FROM aliados_manuales m
                WHERE m.activo=1 $condicionManual
            ) origen
            LEFT JOIN formulario_aliado_registro perfil ON
                (origen.origen_tipo='institucional' AND perfil.seguimiento_id=origen.origen_id)
                OR (origen.origen_tipo='manual' AND perfil.manual_id=origen.origen_id)
            LEFT JOIN formulario_aliado_programas programas ON programas.aliado_id=perfil.aliado_id
            LEFT JOIN formulario_programas catalogo ON catalogo.codigo=programas.programa_codigo
            WHERE (
                (programas.programa_codigo IS NULL AND origen.origen_tipo='institucional' AND ?=0)
                OR (
                    catalogo.activo=1
                    AND NOT EXISTS (
                        SELECT 1 FROM marketing_aliado_convocatorias_origen publicado
                        INNER JOIN convocatorias c ON c.id=publicado.convocatoria_id
                        WHERE publicado.origen_tipo=origen.origen_tipo
                          AND publicado.origen_id=origen.origen_id
                          AND publicado.programa_codigo=programas.programa_codigo
                    )
                    $historicoManual
                    $historicoInstitucional
                    $historicoFormulario
                )
            )
            ORDER BY origen.fecha_alta DESC, origen.origen_id DESC,
                     programas.programa_codigo ASC
            LIMIT ?";
        $parametros[] = $marketing ? 1 : 0;
        $parametros[] = $limite;
        $s = $this->db->prepare($sql);
        if (!$s) throw new RuntimeException('No se pudo consultar pendientes de convocatorias.');
        $tipos = str_repeat('i', count($parametros));
        $s->bind_param($tipos, ...$parametros);
        $s->execute();
        $filas = $s->get_result()->fetch_all(MYSQLI_ASSOC);
        $s->close();

        $items = [];
        foreach ($filas as $fila) {
            $tipo = (string)$fila['origen_tipo'];
            $id = (int)$fila['origen_id'];
            $estado = (int)$fila['estado_id'];
            $programa = trim((string)($fila['programa_codigo'] ?? ''));
            if ($id < 1 || $estado < 1) continue;
            $nombre = (string)$fila['nombre_entidad'];
            if ($programa === '') {
                if ($marketing) continue;
                $titulo = 'Aliado nuevo: completar programas';
                $mensaje = $nombre.' · Vinculación. Verifica y autoriza los programas para preparar sus convocatorias.';
                $url = 'index.php?controller=aliado&action=estado&estado_id='.$estado;
                $clave = $tipo.':'.$id.':programas';
            } else {
                $programaNombre = trim((string)($fila['programa_nombre'] ?? $programa));
                $titulo = 'Convocatoria pendiente: '.$programaNombre;
                $mensaje = $nombre.' · Publicación pendiente para el programa '.$programaNombre.'.';
                $url = 'index.php?'.http_build_query([
                    'controller'=>'convocatoria', 'action'=>'index',
                    'territorio_id'=>$estado, 'desde_convenio'=>1,
                    'abrir_nueva_convocatoria'=>1, 'aliado_tipo'=>$tipo,
                    'aliado_id'=>$id, 'programa_codigo'=>$programa
                ], '', '&', PHP_QUERY_RFC3986);
                $clave = $tipo.':'.$id.':'.$programa;
            }
            $items[] = [
                'clave'=>'aliado_convocatoria:'.$clave,
                'id'=>0, 'tipo_evento'=>'aliado_convocatoria_pendiente',
                'tipo'=>'ALIADO_CONVOCATORIA_PENDIENTE', 'leida'=>1,
                'pendiente_operativo'=>true, 'prioridad'=>2, 'estado'=>'proxima',
                'titulo'=>$titulo, 'mensaje'=>$mensaje,
                'nombre_entidad'=>$nombre, 'accion'=>$titulo,
                'icono'=>$programa===''?'bi-building-add':'bi-file-earmark-plus',
                'etiqueta'=>$programa===''?'Configurar programas':'Por publicar',
                'created_at'=>(string)$fila['fecha_alta'],
                'fecha'=>(string)$fila['fecha_alta'],
                'url'=>$url
            ];
        }
        return $items;
    }
}
