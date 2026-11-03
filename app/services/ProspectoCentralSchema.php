<?php
final class ProspectoCentralSchema
{
    public const MIGRACION='2026_11_02_prospectos_almacen_central.sql';
    public static function sentencias(): array
    {
        $sql=file_get_contents(__DIR__.'/../../database/migrations/'.self::MIGRACION);
        preg_match_all('/CREATE TABLE IF NOT EXISTS ([a-z_]+) \((.*?)\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;/s',$sql,$matches,PREG_SET_ORDER);
        if (count($matches)!==6) throw new RuntimeException('Migración comercial inválida.');
        $out=[];foreach ($matches as $m) $out[$m[1]]=['sql'=>$m[0],'cuerpo'=>$m[2]];return $out;
    }

    public static function revisar(mysqli $db): array
    {
        $missing=[];$conflicts=[];
        foreach (self::sentencias() as $table=>$definition) {
            $s=$db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$s->bind_param('s',$table);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();
            if (!$row) {$missing[]=$table;continue;}
            if (strtoupper($row['ENGINE'])!=='INNODB') $conflicts[]=$table.': motor';
            $fields=array_column($db->query('SHOW FULL COLUMNS FROM `'.$table.'`')->fetch_all(MYSQLI_ASSOC),null,'Field');
            preg_match_all('/^ ([a-z_]+) (BIGINT UNSIGNED|TINYINT UNSIGNED|INT|VARCHAR\([0-9]+\)|CHAR\([0-9]+\)|TEXT|DATETIME|DATE|TIME)(?: COLLATE ([a-z0-9_]+))? (NOT NULL|NULL)/m',$definition['cuerpo'],$cols,PREG_SET_ORDER);
            foreach ($cols as $c) {
                $actual=$fields[$c[1]]??null;
                $normalize=static fn($v)=>preg_replace('/\b(bigint|tinyint|int)\([0-9]+\)/','$1',strtolower($v));
                if (!$actual || $normalize($actual['Type'])!==$normalize($c[2]) || $actual['Null']!==($c[4]==='NULL'?'YES':'NO') || (!empty($c[3])&&$actual['Collation']!==$c[3])) $conflicts[]=$table.'.'.$c[1];
            }
            $indexes=[];foreach ($db->query('SHOW INDEX FROM `'.$table.'`')->fetch_all(MYSQLI_ASSOC) as $i) {$indexes[$i['Key_name']]['cols'][]=$i['Column_name'];$indexes[$i['Key_name']]['unique']=!(bool)$i['Non_unique'];}
            preg_match_all('/^ (PRIMARY KEY|UNIQUE KEY ([a-z_]+)|KEY ([a-z_]+)) \(([^)]+)\)/m',$definition['cuerpo'],$keys,PREG_SET_ORDER);
            foreach ($keys as $key) {
                $name=$key[1]==='PRIMARY KEY'?'PRIMARY':($key[2]?:$key[3]);
                if (!isset($indexes[$name]) || $indexes[$name]['cols']!==explode(',',$key[4]) || $indexes[$name]['unique']!==!str_starts_with($key[1],'KEY ')) $conflicts[]=$table.': índice '.$name;
            }
            $foreign=[];
            foreach ($db->query("SELECT CONSTRAINT_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='$table' AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY CONSTRAINT_NAME,ORDINAL_POSITION")->fetch_all(MYSQLI_ASSOC) as $fk) {
                $foreign[$fk['CONSTRAINT_NAME']]['cols'][]=$fk['COLUMN_NAME'];$foreign[$fk['CONSTRAINT_NAME']]['table']=$fk['REFERENCED_TABLE_NAME'];$foreign[$fk['CONSTRAINT_NAME']]['ref'][]=$fk['REFERENCED_COLUMN_NAME'];
            }
            preg_match_all('/CONSTRAINT ([a-z_]+) FOREIGN KEY \(([^)]+)\) REFERENCES ([a-z_]+)\(([^)]+)\)/',$definition['cuerpo'],$fks,PREG_SET_ORDER);
            foreach ($fks as $fk) if (($foreign[$fk[1]]??null)!==['cols'=>explode(',',$fk[2]),'table'=>$fk[3],'ref'=>explode(',',$fk[4])]) $conflicts[]=$table.': relación '.$fk[1];
        }
        return ['disponible'=>!$missing&&!$conflicts,'faltantes'=>$missing,'conflictos'=>$conflicts];
    }

    public static function aplicar(mysqli $db): array
    {
        $before=self::revisar($db);
        if ($before['conflictos']) throw new RuntimeException('Estructura comercial diferente; no se aplicaron cambios.');
        foreach (self::sentencias() as $table=>$d) if (in_array($table,$before['faltantes'],true)) $db->query($d['sql']);
        $after=self::revisar($db);if (!$after['disponible']) throw new RuntimeException('Migración incompleta; revisar estructura antes de continuar.');
        return ['creadas'=>$before['faltantes'],'estructura'=>$after];
    }
}
