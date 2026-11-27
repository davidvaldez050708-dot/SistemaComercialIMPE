<?php
declare(strict_types=1);

/** Catálogo delegable desde Roles y permisos; sólo el administrador recibe permisos nuevos. */
final class SupervisionPersonalPermisos
{
    public const CATALOGO=[
        'personal.supervision.ver'=>['Ver supervisión de personal','Consultar Cuentas Clave y sus colaboradores supervisados.'],
        'personal.supervision.bitacora'=>['Consultar bitácora de supervisión','Consultar responsables, fechas y movimientos de supervisión de personal. Requiere Ver supervisión de personal.'],
    ];
    /** El llamador controla la transacción. No restaura concesiones previamente revocadas. */
    public static function agregarNuevos(mysqli $db):array
    {
        $added=[];
        foreach(self::CATALOGO as $codigo=>[$nombre,$descripcion]){
            $s=$db->prepare('SELECT id FROM permisos WHERE codigo=?');$s->bind_param('s',$codigo);$s->execute();$exists=$s->get_result()->fetch_assoc();$s->close();
            if($exists)continue;
            $s=$db->prepare("INSERT INTO permisos(modulo,codigo,nombre,descripcion,estado) VALUES('Supervisión de personal',?,?,?,1)");
            $s->bind_param('sss',$codigo,$nombre,$descripcion);$s->execute();$id=$s->insert_id;$s->close();
            $s=$db->prepare('INSERT INTO rol_permisos(rol_id,permiso_id) VALUES(1,?)');$s->bind_param('i',$id);$s->execute();$s->close();$added[]=$codigo;
        }
        return $added;
    }
}
