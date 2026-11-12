<?php
/**
 * Consulta transversal de la restricción expresa NO_CONTACTAR.
 *
 * No modifica consentimiento, asignaciones, etapas ni resultados. Revisa
 * todas las asignaciones históricas: perder vigencia NO revoca la exclusión.
 * La comparación del número es exacta después de normalizar (sin LIKE / sufijos).
 * En México, 52+10 y 521+10 representan variantes reconocidas de la misma
 * marcación móvil; no equiparar últimos diez dígitos de otros países.
 *
 * Una solicitud real de NO_CONTACTAR aún requiere su mecanismo auditado
 * de recepción, autorización y escritura; este servicio solo la hace cumplir
 * cuando el dato está ya registrado en el CRM.
 */
final class ProspectoNoContactarV3
{
    public static function formatos(string $numero): array
    {
        $numero=trim($numero);
        if ($numero==='' || !preg_match('/^\\+?[0-9 ()\\.\\-]+$/D',$numero)) return [];
        $digitos=preg_replace('/\\D+/','',$numero)??'';
        if (strlen($digitos)===10) {
            return ['+52'.$digitos,'+521'.$digitos];
        }
        if (strlen($digitos)===12 && substr($digitos,0,2)==='52') {
            $local=substr($digitos,2);
            return ['+52'.$local,'+521'.$local];
        }
        if (strlen($digitos)===13 && substr($digitos,0,3)==='521') {
            $local=substr($digitos,3);
            return ['+52'.$local,'+521'.$local];
        }
        if (strlen($digitos)<8 || strlen($digitos)>15) return [];
        return ['+'.$digitos];
    }

    public static function bloqueado(mysqli $db,string $numero): bool
    {
        $formatos=self::formatos($numero);
        // Ante número inválido, bloquear cualquier salida; la capa de
        // validación de destino debe presentar su propio mensaje.
        if (!$formatos) {
            throw new DomainException('No se puede verificar la autorización del destinatario.');
        }
        $marcas=implode(',',array_fill(0,count($formatos),'?'));
        $sql="SELECT 1
              FROM prospectos_comerciales p
              JOIN prospecto_asignaciones a ON a.prospecto_id=p.id
              JOIN prospecto_embudo_subetapas sub ON sub.asignacion_id=a.id
              WHERE sub.subetapa='NO_CONTACTAR'
                AND p.telefono_normalizado IN ($marcas)
              LIMIT 1";
        $st=$db->prepare($sql);
        try {
            $st->bind_param(str_repeat('s',count($formatos)),...$formatos);
            $st->execute();
            return (bool)$st->get_result()->fetch_assoc();
        } finally {$st->close();}
    }

    public static function exigirPermitido(mysqli $db,string $numero): void
    {
        if (self::bloqueado($db,$numero)) {
            throw new DomainException(
                'El destinatario solicitó No contactar. Se bloqueó la comunicación.');
        }
    }
}
