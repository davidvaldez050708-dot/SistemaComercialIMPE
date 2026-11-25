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

    public static function resultadoVerificable(string $resultado): bool
    {
        return in_array(strtoupper(trim($resultado)),['CONTACTO_CORRECTO','CONTACTO_REFERIDO','SOLICITO_INFORMACION','SOLICITO_LLAMAR_DESPUES'],true);
    }

    /** La acreditación exige el par de datos de la misma interacción; guardar no lo exige. */
    public static function tienePar(array $detalles): bool
    {
        $campos=[];foreach($detalles as $dato)if(($dato['accion']??'')==='CONFIRMADO' && trim((string)($dato['valor']??''))!=='')$campos[(string)($dato['campo']??'')]=true;
        return isset($campos['telefono'],$campos['correo']);
    }

    public static function detalles(array $datos,array $actual): array
    {
        $telefono=(string)($datos['verificacion_telefono_confirmado']??'')==='1';
        $correo=(string)($datos['verificacion_correo_confirmado']??'')==='1';
        $contacto=(string)($datos['verificacion_contacto_confirmado']??'')==='1';
        $resultado=strtoupper(trim((string)($datos['resultado']??'')));
        if (($telefono || $correo || $contacto) && $resultado!=='' && !self::resultadoVerificable($resultado)) {
            throw new DomainException('El resultado seleccionado no permite confirmar datos. Guarda la llamada sin verificaciones.');
        }
        $out=[];
        foreach (['telefono'=>'Teléfono','correo'=>'Correo','contacto'=>'Contacto institucional'] as $campo=>$etiqueta) {
            if ((string)($datos['verificacion_'.$campo.'_confirmado']??'')!=='1') continue;
            $valor=self::datoConfirmable($datos,$actual,$campo);
            if ($valor==='') throw new DomainException('Captura '.$etiqueta.' antes de confirmarlo durante la llamada.');
            if ($campo==='correo' && (strlen($valor)>160 || !filter_var($valor,FILTER_VALIDATE_EMAIL))) throw new DomainException('El correo confirmado no es válido.');
            if ($campo==='telefono' && !preg_match('/^[+0-9()\- .]{7,40}$/D',$valor)) throw new DomainException('El teléfono confirmado no es válido.');
            $anterior=self::datoConfirmable([],$actual,$campo);
            $out[]=['campo'=>$campo,'anterior'=>$anterior,'valor'=>$valor,'accion'=>'CONFIRMADO','etiqueta'=>$etiqueta.' confirmado'];
        }
        return $out;
    }

    public static function mismoDato(string $campo, string $anterior, string $nuevo): bool
    {
        $normalizar = static function (string $valor) use ($campo): string {
            if ($campo === 'telefono') {
                $valor = preg_replace('/\D+/', '', $valor);
                if (strlen($valor) === 12 && str_starts_with($valor, '52')) $valor = substr($valor, 2);
                elseif (strlen($valor) === 13 && str_starts_with($valor, '521')) $valor = substr($valor, 3);
                return $valor;
            }
            return mb_strtolower(preg_replace('/\s+/u', ' ', trim($valor)), 'UTF-8');
        };
        return $normalizar($anterior) === $normalizar($nuevo);
    }

    public static function estadoAtencion(string $actual,bool $descartar): string
    {
        if ($descartar) return 'DESCARTADO';
        return $actual==='NUEVO' ? 'CONTACTANDO' : $actual;
    }
}
