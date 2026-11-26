<?php
/** Una misma llamada debe acreditar teléfono y correo; nunca basta el texto de notas. */
final class VerificacionTelefonoCorreoService
{
    private static array $tablas=[];

    public static function tablaDisponible(mysqli $db): bool
    {
        $id=spl_object_id($db);
        if (!array_key_exists($id,self::$tablas)) {
            $r=$db->query("SHOW TABLES LIKE 'telefonia_verificacion_evidencias'");
            self::$tablas[$id]=$r->num_rows>0;$r->free();
        }
        return self::$tablas[$id];
    }

    public static function condicionSql(string $alias): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D',$alias)) throw new InvalidArgumentException('Alias inválido.');
        $sql="$alias.canal='LLAMADA_IP' AND $alias.proveedor_externo='ZADARMA'
            AND COALESCE($alias.duracion_segundos,0)>0 AND TRIM(COALESCE($alias.id_externo,''))<>''
            AND $alias.fecha_fin IS NOT NULL AND $alias.fecha_fin>$alias.fecha_inicio";
        foreach (['telefono','correo'] as $campo) {
            $sql.=" AND EXISTS (SELECT 1 FROM telefonia_verificacion_evidencias ev_$campo
                WHERE ev_$campo.interaccion_id=$alias.id AND ev_$campo.usuario_id=$alias.usuario_id
                  AND ev_$campo.seguimiento_id=$alias.seguimiento_id AND ev_$campo.campo='$campo'
                  AND ev_$campo.estado='CONFIRMADA' AND ev_$campo.accion='CONFIRMADO'
                  AND TRIM(ev_$campo.valor_confirmado)<>'' AND ev_$campo.confirmed_at IS NOT NULL
                  AND ev_$campo.pbx_call_id=$alias.id_externo)";
        }
        return '('.$sql.')';
    }

    public static function tieneEfectiva(mysqli $db,int $seguimiento,int $usuario): bool
    {
        if (!self::tablaDisponible($db)) return false;
        $stmt=$db->prepare('SELECT i.id FROM interacciones_vinculacion i WHERE i.seguimiento_id=? AND i.usuario_id=? AND '.self::condicionSql('i').' LIMIT 1');
        $stmt->bind_param('ii',$seguimiento,$usuario);$stmt->execute();$ok=(bool)$stmt->get_result()->fetch_assoc();$stmt->close();return $ok;
    }
}
