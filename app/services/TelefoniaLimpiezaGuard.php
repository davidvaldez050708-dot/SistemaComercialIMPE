<?php
declare(strict_types=1);

/** Exclusiones exactas del CRM. No llama al proveedor ni crea tablas al consultar. */
final class TelefoniaLimpiezaGuard
{
    public static function existe(mysqli $db): bool
    {
        return (bool)$db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='telefonia_crm_exclusiones'")->num_rows;
    }

    public static function bloqueada(mysqli $db,string $ref,string $ext): bool
    {
        if ($ref==='' || !self::existe($db)) return false;
        $s=$db->prepare("SELECT 1 FROM telefonia_crm_exclusiones WHERE pbx_call_id=BINARY ? AND (extension='' OR extension=BINARY ?) LIMIT 1");
        $s->bind_param('ss',$ref,$ext);$s->execute();$ok=(bool)$s->get_result()->num_rows;$s->close();return $ok;
    }

    /** Mismas exclusiones exactas, consultadas una vez para el lote de avisos. */
    public static function referenciasBloqueadas(mysqli $db,array $refs,string $ext): array
    {
        $refs=array_values(array_unique(array_map('strval',$refs)));
        if(!$refs || !self::existe($db))return [];
        $where=implode(' OR ',array_fill(0,count($refs),'pbx_call_id=BINARY ?'));
        $s=$db->prepare("SELECT pbx_call_id FROM telefonia_crm_exclusiones WHERE ($where) AND (extension='' OR extension=BINARY ?)");
        $args=array_merge($refs,[$ext]);$s->bind_param(str_repeat('s',count($args)),...$args);$s->execute();$result=[];
        foreach($s->get_result()->fetch_all(MYSQLI_ASSOC) as $row)$result[(string)$row['pbx_call_id']]=true;
        $s->close();return $result;
    }

    public static function condicion(mysqli $db,string $ref,string $ext): string
    {
        foreach ([$ref,$ext] as $expression) if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*\.[a-zA-Z_][a-zA-Z0-9_]*$/D',$expression)) throw new LogicException('Expresión de exclusión inválida.');
        if (!self::existe($db)) return '1=1';
        return "NOT EXISTS(SELECT 1 FROM telefonia_crm_exclusiones limpieza WHERE limpieza.pbx_call_id=BINARY $ref AND (limpieza.extension='' OR limpieza.extension=BINARY $ext))";
    }
}
