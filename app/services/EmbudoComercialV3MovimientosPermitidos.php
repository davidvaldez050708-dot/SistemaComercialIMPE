<?php
/**
 * Contrato operativo V3 de transición MANUAL gradual, compatible con V1.
 *
 * No permite crear etapas nuevas que los otros módulos aún no reconocen.
 * No permite decidir Potencial, Proceso Inscripción, Recuperación o Descartados
 * sin el flujo integral respaldado. El asesor sí conserva las llamadas V4
 * y las tareas/etapas manuales seguras que ya reconoce el backend antiguo.
 */
final class EmbudoComercialV3MovimientosPermitidos
{
    public static function destinos(string $actual): array
    {
        if($actual==='NUEVO') return ['CONTACTADO'];
        if($actual==='CONTACTADO') return ['SEGUIMIENTO'];
        if($actual==='SEGUIMIENTO') return ['CONTACTADO'];
        return [];
    }

    public static function validar(string $actual,string $destino): void
    {
        if(!in_array($destino,self::destinos($actual),true)) {
            throw new DomainException(
                'Movimiento no disponible en este embudo. '.
                'Potenciales, Recuperación e Inscripción requieren evidencia '.
                'y validación del proceso comercial.'
            );
        }
    }

    /**
     * Si un asesor intenta declarar el primer contacto manual desde V3,
     * requiere evidencia PBX de conversación vinculada a SU asignación.
     * Conexión PBX sola, WhatsApp y marcador no prueban contacto humano.
     */
    public static function requiereConversacion(string $actual,string $destino): bool
    {
        return $actual==='NUEVO' && $destino==='CONTACTADO';
    }

    public static function conversacionAcreditada(
        mysqli $db,int $usuarioId,int $asignacionId
    ): bool {
        $sql="SELECT 1 FROM prospecto_embudo_llamadas l
            JOIN prospecto_asignaciones a ON a.id=l.asignacion_id
                AND a.asesor_id=l.usuario_id AND a.vigente=1
            JOIN telefonia_ventas_llamadas c ON c.usuario_id=l.usuario_id
                AND BINARY c.extension=BINARY l.extension
                AND BINARY c.pbx_call_id=BINARY l.pbx_call_id
                AND c.direccion='Saliente'
            JOIN telefonia_ventas_resultados r ON r.usuario_id=l.usuario_id
                AND BINARY r.extension=BINARY l.extension
                AND BINARY r.pbx_call_id=BINARY l.pbx_call_id
                AND BINARY r.resultado=BINARY l.resultado
            WHERE l.usuario_id=? AND l.asignacion_id=?
              AND (r.resultado IN (
                  'CONVERSACION_PERSONA','PROSPECTO_POTENCIAL',
                  'INTERESADO','SOLICITA_INFORMACION'
                ) OR EXISTS (
                  SELECT 1 FROM telefonia_ventas_resultado_selecciones s
                  WHERE s.resultado_id=r.id
                    AND s.codigo IN ('CONVERSACION_PERSONA','PROSPECTO_POTENCIAL')
                ))
            LIMIT 1";
        $st=$db->prepare($sql);
        $st->bind_param('ii',$usuarioId,$asignacionId);
        try{
            $st->execute();
            return $st->get_result()->fetch_assoc()!==null;
        }finally{$st->close();}
    }
}
