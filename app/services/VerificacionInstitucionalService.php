<?php
/** Reglas comunes de verificación: los datos de negocio no prueban telefonía. */
final class VerificacionInstitucionalService
{
    public static function limpiarObservacion(string $texto): string
    {
        return trim((string)preg_replace('/\[(?:VERIFICACION_EFECTIVA|VERIFICACION_PENDIENTE_TELEFONIA|VERIFICACION_PENDIENTE_PBX)\]/iu', '', $texto));
    }

    public static function referido(array $datos): array
    {
        $campos = ['nuevo_telefono_contacto'=>40,'nuevo_correo_contacto'=>160,
            'nuevo_contacto_nombre'=>140,'nuevo_contacto_cargo'=>150];
        $out=[];
        foreach ($campos as $campo=>$limite) {
            if (isset($datos[$campo]) && !is_string($datos[$campo])) {
                throw new InvalidArgumentException('Los datos del contacto no son válidos.');
            }
            $out[$campo]=trim((string)($datos[$campo] ?? ''));
            if (mb_strlen($out[$campo],'UTF-8')>$limite) {
                throw new InvalidArgumentException('Uno de los datos del contacto es demasiado largo.');
            }
        }
        if (!array_filter($out)) throw new InvalidArgumentException('Captura algún dato de la persona o área referida.');
        if ($out['nuevo_telefono_contacto']!=='' && !preg_match('/^[+0-9()\- .]{7,40}$/D',$out['nuevo_telefono_contacto'])) {
            throw new InvalidArgumentException('El teléfono referido no es válido.');
        }
        if ($out['nuevo_correo_contacto']!=='' && !filter_var($out['nuevo_correo_contacto'],FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('El correo referido no es válido.');
        }
        return $out;
    }

    public static function datoConfirmable(array $datos,array $actual,string $campo): string
    {
        $actuales=[
            'telefono'=>($actual['telefono_verificado']??'')?:($actual['telefono_fuente']??''),
            'correo'=>($actual['correo_verificado']??'')?:($actual['correo_fuente']??''),
            'contacto'=>$actual['contacto_nombre']??'', 'cargo'=>$actual['contacto_cargo']??''
        ];
        $inputs=['telefono'=>'nuevo_telefono_contacto','correo'=>'nuevo_correo_contacto','contacto'=>'nuevo_contacto_nombre','cargo'=>'nuevo_contacto_cargo'];
        $nuevo=$datos[$inputs[$campo]]??'';
        if (!is_string($nuevo)) throw new InvalidArgumentException('El dato de contacto no es válido.');
        return trim($nuevo)!=='' ? trim($nuevo) : trim((string)$actuales[$campo]);
    }

    public static function detalles(array $datos,array $actual): array
    {
        $telefono=(string)($datos['verificacion_telefono_confirmado']??'')==='1';
        $correo=(string)($datos['verificacion_correo_confirmado']??'')==='1';
        $contacto=(string)($datos['verificacion_contacto_confirmado']??'')==='1';
        $resultado=strtoupper(trim((string)($datos['resultado']??'')));
        if (($resultado==='CONTACTO_CORRECTO' || $telefono || $correo || $contacto) && !($telefono && $correo)) {
            throw new DomainException('Para registrar Contacto correcto o acreditar una verificación debes confirmar teléfono y correo durante esta misma llamada.');
        }
        if (!$telefono || !$correo) return [];
        $out=[];
        foreach (['telefono'=>'Teléfono','correo'=>'Correo','contacto'=>'Contacto institucional'] as $campo=>$etiqueta) {
            if ($campo==='contacto' && !$contacto) continue;
            $valor=self::datoConfirmable($datos,$actual,$campo);
            if ($valor==='') throw new DomainException('Captura '.$etiqueta.' antes de confirmarlo durante la llamada.');
            if ($campo==='correo' && (strlen($valor)>160 || !filter_var($valor,FILTER_VALIDATE_EMAIL))) throw new DomainException('El correo confirmado no es válido.');
            if ($campo==='telefono' && !preg_match('/^[+0-9()\- .]{7,40}$/D',$valor)) throw new DomainException('El teléfono confirmado no es válido.');
            $anterior=self::datoConfirmable([],$actual,$campo);
            $out[]=['campo'=>$campo,'anterior'=>$anterior,'valor'=>$valor,'accion'=>'CONFIRMADO','etiqueta'=>$etiqueta.' confirmado'];
        }
        return $out;
    }

    public static function estadoAtencion(string $actual,bool $descartar): string
    {
        if ($descartar) return 'DESCARTADO';
        return $actual==='NUEVO' ? 'CONTACTANDO' : $actual;
    }
}
