<?php

/** Datos revisados al cerrar una llamada. No acredita verificaciones telefónicas. */
final class DatosContactoLlamadaService
{
    public static function extraer(array $datos, string $canal, string $resultado): array
    {
        if (($datos['actualizar_datos_llamada'] ?? '') !== '1' || $canal !== 'LLAMADA' ||
            !in_array($resultado, ['CONTACTO_CORRECTO', 'CONTACTO_REFERIDO', 'SOLICITO_INFORMACION',
                'SOLICITO_LLAMAR_DESPUES', 'NO_INTERESADO'], true)) return [];
        $campos = [
            'nombre_entidad_actualizado'=>['nombre_entidad',180],
            'nuevo_telefono_contacto'=>['telefono_verificado',40],
            'nuevo_correo_contacto'=>['correo_verificado',160],
            'nuevo_contacto_nombre'=>['contacto_nombre',140],
            'nuevo_contacto_cargo'=>['contacto_cargo',150]
        ];
        $out = [];
        foreach ($campos as $input=>[$columna,$max]) {
            if (!isset($datos[$input])) continue;
            if (!is_string($datos[$input])) throw new InvalidArgumentException('Los datos de contacto no son válidos.');
            $valor = VerificacionInstitucionalService::limpiarObservacion($datos[$input]);
            if (mb_strlen($valor,'UTF-8') > $max) throw new InvalidArgumentException('Uno de los datos de contacto es demasiado largo.');
            if ($valor === '') continue; // Un campo vacío nunca borra un dato del expediente.
            if ($columna === 'correo_verificado' && !filter_var($valor,FILTER_VALIDATE_EMAIL))
                throw new InvalidArgumentException('El correo de contacto no es válido.');
            if ($columna === 'telefono_verificado' && !preg_match('/^[+0-9()\- .]{7,40}$/D',$valor))
                throw new InvalidArgumentException('El teléfono de contacto no es válido.');
            $out[$columna] = $valor;
        }
        return $out;
    }

    public static function nota(array $datos): string
    {
        $labels=['nombre_entidad'=>'Institución','telefono_verificado'=>'Teléfono',
            'correo_verificado'=>'Correo','contacto_nombre'=>'Contacto institucional','contacto_cargo'=>'Cargo o área'];
        $partes=[];
        foreach ($labels as $campo=>$label) if (isset($datos[$campo])) $partes[]=$label.': '.$datos[$campo];
        return $partes ? 'Datos de contacto guardados en la ficha: '.implode(' · ',$partes) : '';
    }

    /** El llamador mantiene la misma transacción de la interacción. */
    public static function guardar(mysqli $db, int $seguimiento, int $usuario, array $datos): void
    {
        if (!$datos) return;
        $permitidos=['nombre_entidad','telefono_verificado','correo_verificado','contacto_nombre','contacto_cargo'];
        if (array_diff(array_keys($datos),$permitidos)) throw new InvalidArgumentException('Campos de contacto no permitidos.');
        $scope=SeguimientoTerritorialScope::analista('seguimientos_vinculacion');
        $lock=$db->prepare('SELECT id FROM seguimientos_vinculacion WHERE id=? AND '.$scope.' AND activo=1 FOR UPDATE');
        $lock->bind_param('ii',$seguimiento,$usuario);$lock->execute();
        $actual=$lock->get_result()->fetch_assoc();$lock->close();
        if (!$actual) throw new DomainException('El expediente ya no pertenece al Analista o no está activo.');
        $asignaciones=[];$valores=[];
        foreach ($datos as $campo=>$valor) {$asignaciones[]=$campo.'=?';$valores[]=$valor;}
        $tipos=str_repeat('s',count($valores)).'ii';$valores[]=$seguimiento;$valores[]=$usuario;
        $stmt=$db->prepare('UPDATE seguimientos_vinculacion SET '.implode(',',$asignaciones).' WHERE id=? AND '.$scope.' AND activo=1');
        $stmt->bind_param($tipos,...$valores);$stmt->execute();$stmt->close();
    }
}
