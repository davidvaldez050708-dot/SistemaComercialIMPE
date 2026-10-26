<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/SeguimientoAtencionOperativaService.php';

/** Inicio nacional de consulta. No crea estructuras ni actualiza la operación. */
final class DirectorOperacionesDashboardService
{
    private mysqli $db;
    private array $tablas = [];

    public function __construct(?mysqli $db = null)
    {
        $this->db = $db ?? (new Database())->connect();
    }

    private function filas(string $sql, array $params = []): array
    {
        $stmt = $this->db->prepare($sql);
        if ($params) $stmt->bind_param(str_repeat('s', count($params)), ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    private function tabla(string $name): bool
    {
        return $this->tablas[$name] ??= (bool)$this->filas(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$name]
        );
    }

    public function obtener(int $uid): array
    {
        if (!$this->filas('SELECT u.id FROM usuarios u JOIN roles r ON r.id=u.rol_id AND r.estado=1 WHERE u.id=? AND u.rol_id=2 AND u.estado=1', [$uid])) {
            throw new DomainException('No tienes acceso al Inicio de Dirección de Operaciones.');
        }
        // No confiar en un permiso conservado en una pestaña o en parámetros GET.
        $codes = array_column($this->filas('SELECT p.codigo FROM permisos p JOIN rol_permisos rp ON rp.permiso_id=p.id WHERE rp.rol_id=2 AND p.estado=1'), 'codigo');
        $tiene = static fn(string $code): bool => in_array($code, $codes, true);
        $reportes = $tiene('reportes.ver') && (
            $tiene('reportes.usuarios') || $tiene('reportes.convocatorias') ||
            ($tiene('reportes.territorial') && $tiene('data_territorial.ver')) ||
            ($tiene('reportes.aliados.panorama') && $tiene('aliados.ver')) ||
            ($tiene('seguimientos_vinculacion.ver') && (bool)array_intersect($codes, ['reportes.seguimiento.cartera','reportes.seguimiento.actividad','reportes.seguimiento.institucion'])) ||
            ($tiene('reportes.ventas') && $tiene('reportes.ventas.global'))
        );
        $permisos = [
            'equipo' => $tiene('usuarios.ver'), 'territorios' => $tiene('territorios.ver'),
            'seguimientos' => $tiene('seguimientos_vinculacion.ver'), 'agenda' => $tiene('reuniones.ver'),
            'convenios' => $tiene('convenios.ver') || $tiene('convenios.gestionar'),
            'aliados' => $tiene('aliados.ver'), 'convocatorias' => $tiene('convocatorias.ver'),
            'prospectos' => $tiene('prospectos.ver_todos'), 'reportes' => $reportes,
            'desempeno' => $tiene('desempeno.ver') && $tiene('desempeno.ver_global'),
            'llamadas' => $tiene('llamadas.supervision.ver'),
            'ventas' => $tiene('reportes.ver') && $tiene('reportes.ventas') && $tiene('reportes.ventas.global'),
        ];
        $out = ['permisos'=>$permisos, 'resumen'=>[], 'atenciones'=>null, 'agenda'=>null,
            'cobertura'=>null, 'ventas'=>null, 'avisos'=>[], 'generado_at'=>date('Y-m-d H:i:s')];
        $seccion = static function (string $name, callable $load) use (&$out): void {
            try { $load(); }
            catch (Throwable $error) {
                error_log('[inicio_direccion] '.$name.' '.get_class($error));
                $out['avisos'][] = 'No fue posible consultar '.$name.'. Vuelve a cargar el Inicio.';
            }
        };
        if ($permisos['equipo']) $seccion('el resumen del equipo', function () use (&$out) {
            $rows = $this->filas('SELECT rol_id,COUNT(*) total FROM usuarios WHERE estado=1 AND rol_id IN (3,4,6) GROUP BY rol_id');
            $out['resumen']['equipo'] = [3=>0,4=>0,6=>0];
            foreach ($rows as $r) $out['resumen']['equipo'][(int)$r['rol_id']] = (int)$r['total'];
        });
        if ($permisos['seguimientos']) $seccion('las prioridades de vinculación', function () use (&$out, $permisos) {
            $post = $this->tabla('seguimientos_vinculacion_post_envio');
            $cartera = $this->filas('SELECT s.id,s.estado_id,s.analista_id FROM seguimientos_vinculacion s '.
                ($post?'LEFT JOIN seguimientos_vinculacion_post_envio p ON p.seguimiento_id=s.id ':'').
                "WHERE s.activo=1 AND s.estado_seguimiento<>'DESCARTADO'".($post?' AND p.convenio_formalizado_at IS NULL':''));
            // Mismos criterios de atención que Inicio de Analista y Cuenta Clave.
            $atenciones = (new SeguimientoAtencionOperativaService())->obtenerPorIds(array_column($cartera, 'id'));
            $nombres = [];
            foreach ($this->filas("SELECT id,CONCAT_WS(' ',nombre,apellidos) nombre FROM usuarios WHERE rol_id=4") as $u) $nombres[(int)$u['id']]=$u['nombre'];
            foreach ($atenciones as &$row) $row['analista_nombre']=$nombres[(int)$row['analista_id']]??'Analista';
            unset($row);
            // Sin reuniones.ver, la prioridad sigue siendo visible pero no revela
            // datos de agenda del expediente (misma restricción del módulo).
            if (!$permisos['agenda']) foreach ($atenciones as &$row) {
                if (($row['tipo_atencion']??'')==='reunion' || isset($row['reunion_estado']) && $row['reunion_estado']!=='') {
                    $row['motivo_atencion']='Revisar seguimiento'; $row['fecha_referencia']=null;
                }
                unset($row['reunion_fecha'],$row['reunion_estado'],$row['reunion_duracion_minutos'],$row['reunion_cambio_solicitado_at']);
            }
            unset($row);
            $out['resumen']['seguimientos']=count($cartera);
            $out['resumen']['atenciones']=count($atenciones);
            $out['atenciones']=array_slice($atenciones,0,6);
            $out['cartera_por_estado']=array_count_values(array_column($cartera,'estado_id'));
        });
        if ($permisos['convenios']) $seccion('los convenios formalizados', function () use (&$out) {
            if (!$this->tabla('seguimientos_vinculacion_post_envio')) throw new RuntimeException('Estructura no disponible.');
            $row=$this->filas('SELECT COUNT(DISTINCT s.id) total FROM seguimientos_vinculacion s JOIN seguimientos_vinculacion_post_envio p ON p.seguimiento_id=s.id WHERE s.activo=1 AND p.convenio_formalizado_at IS NOT NULL')[0];
            $out['resumen']['convenios']=(int)$row['total'];
        });
        if ($permisos['convocatorias']) $seccion('las convocatorias', function () use (&$out) {
            $out['resumen']['convocatorias']=(int)$this->filas('SELECT COUNT(*) total FROM convocatorias WHERE estado=1 AND CURDATE() BETWEEN fecha_inicio AND fecha_termino')[0]['total'];
        });
        if ($permisos['agenda']) $seccion('la agenda nacional', function () use (&$out) {
            $now=new DateTimeImmutable('now',new DateTimeZone('America/Mexico_City'));
            $rows=$this->filas("SELECT r.id,r.fecha_propuesta,r.estado,s.nombre_entidad,
                CONCAT_WS(' ',u.nombre,u.apellidos) analista_nombre,e.nombre estado_nombre
                FROM reuniones_vinculacion r JOIN seguimientos_vinculacion s ON s.id=r.seguimiento_id AND s.activo=1 AND s.analista_id=r.analista_id
                LEFT JOIN usuarios u ON u.id=r.analista_id LEFT JOIN estados e ON e.id=s.estado_id
                WHERE r.estado IN ('SOLICITADA','CONFIRMADA','CORREO_ENVIADO') AND r.fecha_propuesta>=? AND r.fecha_propuesta<?
                ORDER BY r.fecha_propuesta,r.id LIMIT 6", [$now->format('Y-m-d H:i:s'),$now->modify('+7 days')->format('Y-m-d H:i:s')]);
            $out['agenda']=$rows;
        });
        if ($permisos['territorios']) $seccion('la cobertura territorial', function () use (&$out) {
            $states=$this->filas('SELECT id,nombre FROM estados WHERE estado=1 ORDER BY nombre');
            $assign=$this->filas("SELECT a.estado_id,
                COUNT(DISTINCT CASE WHEN a.tipo_asignacion='CUENTA_CLAVE' AND u.rol_id=6 THEN u.id END) cuentas,
                COUNT(DISTINCT CASE WHEN a.tipo_asignacion='ANALISTA_DATOS' AND u.rol_id=4 THEN u.id END) analistas,
                COUNT(DISTINCT CASE WHEN a.tipo_asignacion='ASESOR' AND u.rol_id=3 THEN u.id END) asesores
                FROM asignaciones_territorio a JOIN usuarios u ON u.id=a.usuario_id AND u.estado=1
                WHERE a.activo=1 AND (a.fecha_inicio IS NULL OR a.fecha_inicio<=CURDATE()) AND (a.fecha_fin IS NULL OR a.fecha_fin>=CURDATE()) GROUP BY a.estado_id");
            $by=[];foreach($assign as $r)$by[(int)$r['estado_id']]=$r;
            foreach($states as &$s){$s+=($by[(int)$s['id']]??['cuentas'=>0,'analistas'=>0,'asesores'=>0]);$s['seguimientos']=$out['cartera_por_estado'][(int)$s['id']]??null;}unset($s);
            $out['resumen']['territorios']=count($states);
            $out['cobertura']=$states;
        });
        if ($permisos['ventas']) $seccion('la actividad telefónica de asesores', function () use (&$out,$codes) {
            require_once __DIR__ . '/VentasActividadService.php';
            // El servicio compartido valida su propio alcance. Los permisos son
            // la lectura vigente de la BD, y se restauran al terminar el cálculo.
            $previous=$_SESSION['permisos']??[];
            try { $_SESSION['permisos']=$codes; $out['ventas']=(new VentasActividadService($this->db))->preparar(['periodo'=>'hoy'],false); }
            finally { $_SESSION['permisos']=$previous; }
            unset($out['ventas']['llamadas']);
        });
        return $out;
    }
}
