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

    public static function detalles(array $datos,array $actual): array
    {
        $mapa=[
            'telefono'=>['verificacion_telefono_confirmado','nuevo_telefono_contacto',
                ($actual['telefono_verificado'] ?? '') ?: ($actual['telefono_fuente'] ?? ''),'Teléfono'],
            'correo'=>['verificacion_correo_confirmado','nuevo_correo_contacto',
                ($actual['correo_verificado'] ?? '') ?: ($actual['correo_fuente'] ?? ''),'Correo'],
            'contacto'=>['verificacion_contacto_confirmado','nuevo_contacto_nombre',$actual['contacto_nombre'] ?? '','Contacto institucional'],
            'cargo'=>['','nuevo_contacto_cargo',$actual['contacto_cargo'] ?? '','Cargo o área']
        ];
        $out=[];
        foreach ($mapa as $campo=>[$check,$nuevo,$anterior,$etiqueta]) {
            $valor=($datos['resultado'] ?? '')==='CONTACTO_REFERIDO' ? trim((string)($datos[$nuevo] ?? '')) : '';
            // Repetir datos vigentes no genera otra verificación, aunque llegue un POST antiguo.
            $vigente = (int)($actual['datos_verificados'] ?? 0) === 1;
            if ($vigente && ($valor === '' || self::mismoDato($campo, (string)$anterior, $valor))) continue;
            if ($valor!=='') {
                $out[]=['campo'=>$campo,'anterior'=>(string)$anterior,'valor'=>$valor,'accion'=>'REFERIDO','etiqueta'=>$etiqueta.' proporcionado'];
            } elseif ($check!=='' && (int)($datos[$check] ?? 0)===1) {
                if (trim((string)$anterior)==='') throw new DomainException('No hay '.$etiqueta.' registrado para confirmar.');
                $out[]=['campo'=>$campo,'anterior'=>(string)$anterior,'valor'=>(string)$anterior,'accion'=>'CONFIRMADO','etiqueta'=>$etiqueta.' confirmado'];
            }
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
