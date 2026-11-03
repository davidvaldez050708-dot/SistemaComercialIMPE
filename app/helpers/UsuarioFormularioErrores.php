<?php
/** Presentación de errores; admite también las listas de la versión anterior. */
final class UsuarioFormularioErrores
{
    public static function preparar(array $errores): array
    {
        $campos=[];$general='';
        $legacy=[
            'El nombre es obligatorio.'=>'nombre','Los apellidos son obligatorios.'=>'apellidos',
            'El correo electrónico es obligatorio.'=>'correo','El formato del correo electrónico no es válido.'=>'correo',
            'El correo electrónico ya está registrado.'=>'correo','El usuario es obligatorio.'=>'usuario',
            'El usuario ya está registrado.'=>'usuario','El rol seleccionado no es válido.'=>'rol_id',
            'El estado seleccionado no es válido.'=>'estado','La contraseña debe tener al menos 8 caracteres.'=>'password',
            'La contraseña y la confirmación no coinciden.'=>'confirmar_password'
        ];
        foreach ($errores as $key=>$mensaje) {
            if (!is_string($mensaje)||$mensaje==='') continue;
            $campo=is_string($key)?$key:($legacy[$mensaje]??'general');
            if (in_array($campo,['nombre','apellidos','correo','usuario','rol_id','estado','password','confirmar_password','foto_perfil'],true)) $campos[$campo]=$mensaje;
            elseif ($general==='') $general=$mensaje;
        }
        return ['campos'=>$campos,'resumen'=>$general!==''?$general:($campos?'Revisa los campos señalados.':'')];
    }
}
