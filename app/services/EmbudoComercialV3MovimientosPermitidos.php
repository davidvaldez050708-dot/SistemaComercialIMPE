<?php
/**
 * Contrato operativo V3 de transición MANUAL gradual, compatible con V1.
 *
 * No permite crear etapas nuevas que los otros módulos aún no reconocen.
 * La calificación de Contactados requiere conversación y confirmación expresa.
 * Inscripción, Recuperación y Descartados conservan sus propias restricciones.
 */
final class EmbudoComercialV3MovimientosPermitidos
{
    public static function destinos(string $actual, bool $puedeCalificar = false): array
    {
        if($actual==='NUEVO') return ['CONTACTADO'];
        if($actual==='CONTACTADO') return $puedeCalificar ? ['SEGUIMIENTO','POTENCIAL'] : ['SEGUIMIENTO'];
        if($actual==='SEGUIMIENTO') return $puedeCalificar ? ['POTENCIAL'] : [];
        if(in_array($actual,['POTENCIAL','PROCESO_INSCRIPCION'],true)) return ['SEGUIMIENTO'];
        return [];
    }

    private const RESULTADOS_CONVERSACION = [
        'CONVERSACION_PERSONA','PROSPECTO_POTENCIAL',
        'INTERESADO','SOLICITA_INFORMACION',
    ];

    public static function destinoPorConversacion(string $actual,string $resultado): ?string
    {
        return self::requiereConversacion($actual,'CONTACTADO') &&
            in_array($resultado,self::RESULTADOS_CONVERSACION,true)
            ? 'CONTACTADO' : null;
    }

    public static function validar(string $actual,string $destino): void
    {
        if(!in_array($destino,self::destinos($actual, true),true)) {
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
        return isset(self::conversacionesAcreditadas($db, $usuarioId, [$asignacionId])[$asignacionId]);
    }

    public static function conversacionesAcreditadas(mysqli $db, int $usuarioId, array $ids): array
    {
        if (!$ids) return [];
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $resultados = "'".implode("','",self::RESULTADOS_CONVERSACION)."'";
        $sql="SELECT DISTINCT l.asignacion_id FROM prospecto_embudo_llamadas l
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
            WHERE l.usuario_id=? AND l.asignacion_id IN ($marcas)
              AND (r.resultado IN ($resultados) OR EXISTS (
                  SELECT 1 FROM telefonia_ventas_resultado_selecciones s
                  WHERE s.resultado_id=r.id
                    AND s.codigo IN ('CONVERSACION_PERSONA','PROSPECTO_POTENCIAL')
                ))";
        $st=$db->prepare($sql);
        $st->bind_param(str_repeat('i', count($ids)+1),$usuarioId,...$ids);
        try{
            $st->execute();
            return array_fill_keys(array_column($st->get_result()->fetch_all(MYSQLI_ASSOC),'asignacion_id'), true);
        }finally{$st->close();}
    }
}
