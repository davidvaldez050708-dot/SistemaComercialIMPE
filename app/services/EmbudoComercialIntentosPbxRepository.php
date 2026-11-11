<?php
/**
 * Lector de intentos comerciales PBX, exclusivamente de solo lectura.
 *
 * NO está conectado a controladores, puente de resultados, CRON ni vistas:
 * sirve para validar la política futura en una MySQL desechable antes de
 * modificar el CRM instalado. Nunca modifica Telefonía ni Gestión comercial.
 *
 * La fecha telefonia_ventas_llamadas.fecha proviene del evento Zadarma
 * normalizado a America/Mexico_City por ZadarmaWebhookEventStoreService.
 * NO utilizar prospecto_embudo_llamadas.creado_at para el día de llamada:
 * ese valor UTC representa cuando el ASESOR guardó el resultado.
 */
final class EmbudoComercialIntentosPbxRepository
{
    private mysqli $db;
    public function __construct(mysqli $db) { $this->db=$db; }

    /**
     * Devuelve fecha, total, pendientes y completo.
     * El usuario, la asignación y la fecha son restricciones de consulta,
     * NO equivalen por sí solos a autorización del controlador.
     * Una eventual integración debe exigir sesión del asesor y permisos.
     */
    public function resumenJornada(int $usuarioId, int $asignacionId, string $diaLocal,
        ?string $fechaLimiteLocal=null, ?int $llamadaIdLimite=null): array
    {
        if ($usuarioId<1 || $asignacionId<1) {
            throw new InvalidArgumentException('Usuario o asignación no válida.');
        }
        $tz=new DateTimeZone('America/Mexico_City');
        $inicio=DateTimeImmutable::createFromFormat('!Y-m-d', $diaLocal, $tz);
        if (!$inicio || $inicio->format('Y-m-d')!==$diaLocal) {
            throw new InvalidArgumentException('La jornada debe expresarse como fecha local YYYY-MM-DD.');
        }
        $desde=$inicio->format('Y-m-d H:i:s');
        $hasta=$inicio->modify('+1 day')->format('Y-m-d H:i:s');
        $restringir=$fechaLimiteLocal!==null || $llamadaIdLimite!==null;
        if ($restringir && (
            $fechaLimiteLocal===null || $llamadaIdLimite===null ||
            $llamadaIdLimite<1 ||
            !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D',
                $fechaLimiteLocal) ||
            substr($fechaLimiteLocal,0,10)!==$diaLocal ||
            $fechaLimiteLocal<$desde || $fechaLimiteLocal>=$hasta
        )) {
            throw new InvalidArgumentException('Límite PBX de la jornada inválido.');
        }

        // SQL estático y parametrizado: intersección de tres registros
        // independientes, identidad PBX exacta y asignación vigente del asesor.
        // Un resultado aislado o una llamada entrante nunca acredita intento.
        $sql="SELECT
                  l.extension, l.pbx_call_id, l.resultado,
                  c.fecha, c.direccion,
                  r.id resultado_id, r.resultado resultado_telefonico,
                  d.catalogo_version, d.contacto_antes_corte
                FROM prospecto_embudo_llamadas l
                JOIN prospecto_asignaciones a ON a.id=l.asignacion_id
                  AND a.asesor_id=l.usuario_id AND a.vigente=1
                JOIN telefonia_ventas_llamadas c ON c.usuario_id=l.usuario_id
                  AND BINARY c.extension=BINARY l.extension
                  AND BINARY c.pbx_call_id=BINARY l.pbx_call_id
                JOIN telefonia_ventas_resultados r ON r.usuario_id=l.usuario_id
                  AND BINARY r.extension=BINARY l.extension
                  AND BINARY r.pbx_call_id=BINARY l.pbx_call_id
                  AND BINARY r.resultado=BINARY l.resultado
                LEFT JOIN telefonia_ventas_resultado_detalles d
                  ON d.resultado_id=r.id
                WHERE l.asignacion_id=? AND l.usuario_id=?
                  AND c.direccion='Saliente'
                  AND c.fecha>=? AND c.fecha<?";
        if ($restringir) {
            // Para reconstruir la primera/segunda/tercera llamada de una
            // jornada con resultados ya guardados posteriormente.
            $sql.=" AND (c.fecha<? OR (c.fecha=? AND c.id<=?))";
        }
        $sql.=" ORDER BY c.fecha ASC,c.id ASC,l.id ASC";
        $stmt=$this->db->prepare($sql);
        if ($restringir) {
            $stmt->bind_param('iissssi',$asignacionId,$usuarioId,$desde,
                $hasta,$fechaLimiteLocal,$fechaLimiteLocal,$llamadaIdLimite);
        } else {
            $stmt->bind_param('iiss',$asignacionId,$usuarioId,$desde,$hasta);
        }
        try {
            $stmt->execute();
            $registros=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        } finally {
            $stmt->close();
        }

        $unicos=[];
        foreach ($registros as $r) {
            $ext=(string)$r['extension'];
            $ref=(string)$r['pbx_call_id'];
            $codigo=(string)$r['resultado'];
            if (!preg_match('/^[0-9]{3,6}$/D',$ext) ||
                !preg_match('/^[A-Za-z0-9_-]{6,100}$/D',$ref)) {
                continue;
            }
            $sinContacto=in_array($codigo,[
                'SIN_RESPUESTA','BUZON_VOZ','OCUPADO',
                'MENSAJE_AUTOMATICO','OTRO_SIN_CONTACTO'
            ],true);
            if ($codigo==='COMUNICACION_INTERRUMPIDA' &&
                $r['contacto_antes_corte']==='NO') {
                $sinContacto=true;
            }
            // En V4, cuando SOLO se marcó WhatsApp/SMS y el asesor
            // respondió NO a si hubo conversación, el resultado principal
            // se guarda como ENVIO_*; versiones anteriores son ambiguas.
            if (in_array($codigo,[
                'ENVIO_WHATSAPP','ENVIO_SMS','ENVIO_WHATSAPP_SMS'
            ],true) && (string)($r['catalogo_version']??'')==='4') {
                $sinContacto=true;
            }
            if (!$sinContacto) continue;
            $unicos[$ext.'|'.$ref]=true;
        }
        $total=count($unicos);
        return [
            'fecha'=>$diaLocal,
            'total'=>$total,
            'pendientes'=>max(0,3-$total),
            'completo'=>$total>=3
        ];
    }
}
