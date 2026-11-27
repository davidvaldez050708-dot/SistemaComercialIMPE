<?php
require_once __DIR__.'/VerificacionInstitucionalService.php';
/** La captura humana nunca puede convertir una saliente sin respuesta en contacto. */
final class TelefoniaResultadoAnalistaService
{
    private const CON_PERSONA = ['CONTACTO_CORRECTO','CONTACTO_REFERIDO','CONTACTO_INCORRECTO',
        'SOLICITO_INFORMACION','SOLICITO_LLAMAR_DESPUES','NO_INTERESADO'];

    public static function validarLlamada(int $usuario, int $seguimiento, array $datos, ?mysqli $db = null): ?array
    {
        $ref = trim((string)($datos['llamada_pbx_id'] ?? ''));
        $origen = strtoupper(trim((string)($datos['origen_llamada'] ?? '')));
        if ($ref === '' && $origen !== 'ZADARMA') return null; // Registros manuales y otros canales.
        if (strtoupper((string)($datos['canal'] ?? '')) !== 'LLAMADA') {
            throw new DomainException('El resultado de esta llamada debe registrarse en el canal Llamada.');
        }
        $reglas=self::consultar($usuario, $seguimiento, $ref, $db);
        self::validarCaptura($reglas,$datos);
        return $reglas;
    }

    public static function validarCaptura(array $reglas, array $datos): void
    {
        $resultado = strtoupper(trim((string)($datos['resultado'] ?? '')));
        if (!in_array($resultado, $reglas['opciones'], true)) {
            throw new DomainException('La centralita indica «'.$reglas['etiqueta'].'». Selecciona un resultado compatible; no se guardó la interacción.');
        }
        $pideVerificacion = false;
        foreach (['telefono','correo','contacto'] as $campo) {
            if ((int)($datos['verificacion_'.$campo.'_confirmado'] ?? 0) === 1) $pideVerificacion = true;
        }
        if ($pideVerificacion && (!$reglas['permite_verificar'] || $resultado === 'CONTACTO_INCORRECTO' || !VerificacionInstitucionalService::resultadoVerificable($resultado))) {
            throw new DomainException('Esta llamada no permite confirmar datos. Registra su resultado sin verificaciones.');
        }
    }

    public static function consultar(int $usuario, int $seguimiento, string $ref, ?mysqli $db = null): array
    {
        if (!preg_match('/^out_[a-fA-F0-9]{32,64}$/D', $ref)) {
            throw new DomainException('El identificador de la llamada está pendiente. Espera la confirmación de telefonía y vuelve a comprobar.');
        }
        require_once __DIR__.'/../../config/db_connection.php';
        require_once __DIR__.'/TelefoniaExtensionService.php';
        require_once __DIR__.'/ZadarmaWebhookEventStoreService.php';
        $db = $db ?? (method_exists(Database::class,'compartida') ? Database::compartida() : (new Database())->connect());
        $stmt = $db->prepare('SELECT telefono_verificado,telefono_fuente FROM seguimientos_vinculacion WHERE id=? AND analista_id=? AND activo=1 LIMIT 1');
        $stmt->bind_param('ii', $seguimiento, $usuario);
        $stmt->execute(); $expediente = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$expediente) throw new DomainException('No tienes permiso para operar este seguimiento.');
        $asignacion = (new TelefoniaExtensionService($db))->resolverParaUsuario($usuario);
        $extension = trim((string)($asignacion['extension'] ?? ''));
        if (!preg_match('/^[0-9]{3,6}$/D', $extension) || empty($asignacion['permite_salientes'])) {
            throw new DomainException('Tu extensión saliente no está habilitada.');
        }
        $eventos = (new ZadarmaWebhookEventStoreService($db))->obtenerPorPbxCallId($ref);
        require_once __DIR__.'/../helpers/TelefoniaAsignacionFechaHelper.php';
        $desde=TelefoniaAsignacionFechaHelper::aMexico((string)($asignacion['asignacion_desde']??''));
        $eventos=array_values(array_filter($eventos,static fn(array $e):bool=>(string)($e['received_at']??'')>=$desde));
        return self::reglasEventos($eventos, $ref, $extension, array_values($expediente));
    }

    /** Sólo eventos persistidos del proveedor; nunca status/duración enviados por el navegador. */
    public static function reglasEventos(array $eventos, string $ref, string $extension, array $telefonos): array
    {
        $inicio = null; $fin = null; $respuesta = false;
        foreach ($eventos as $evento) {
            if ((string)($evento['pbx_call_id'] ?? '') !== $ref) continue;
            $ext = trim((string)($evento['internal'] ?? ''));
            if ($ext !== '' && $ext !== $extension) continue;
            switch ($evento['event'] ?? '') {
                case 'NOTIFY_OUT_START': if ($ext === $extension) $inicio = $evento; break;
                case 'NOTIFY_OUT_END': $fin = $evento; break;
                case 'NOTIFY_ANSWER': if ($ext === $extension) $respuesta = true; break;
            }
        }
        if (!$inicio || !$fin) throw new DomainException('La centralita todavía no confirma el cierre de esta llamada. Espera y vuelve a comprobar.');
        $destino = self::telefono((string)($inicio['destination'] ?? ''));
        $coincide = false;
        foreach ($telefonos as $telefono) {
            if ($destino !== '' && $destino === self::telefono((string)$telefono)) $coincide = true;
        }
        if (!$coincide) throw new DomainException('La llamada no corresponde al teléfono de este seguimiento.');
        $estado = strtolower(trim((string)($fin['disposition'] ?? '')));
        $estado = str_replace(['_', '-'], ' ', $estado);
        $contestada = in_array($estado, ['answered','answer','connected','success'], true);
        if ($estado === 'busy') {
            $label = 'Línea ocupada'; $opciones = ['OCUPADO'];
        } elseif (in_array($estado, ['no answer','noanswer'], true)) {
            $label = 'Sin respuesta'; $opciones = ['SIN_RESPUESTA'];
        } elseif (in_array($estado, ['cancel','canceled','cancelled'], true)) {
            $label = 'Llamada cancelada'; $opciones = ['OTRO'];
        } elseif ($contestada || ($estado === '' && $respuesta)) {
            $contestada = true;
            // ANSWERED incluye buzón/locuciones: la persona aún clasifica lo que escuchó.
            $label = 'Conexión confirmada';
            $opciones = array_merge(self::CON_PERSONA, ['BUZON_VOZ','FUERA_SERVICIO','NUMERO_INCORRECTO','OTRO']);
        } else {
            $label = 'Sin conexión confirmada';
            $opciones = ['SIN_RESPUESTA','FUERA_SERVICIO','NUMERO_INCORRECTO','OTRO'];
        }
        return ['pbx_call_id'=>$ref,'etiqueta'=>$label,'opciones'=>$opciones,'permite_verificar'=>$contestada,
            'inicio_evento'=>(int)($inicio['id']??0),'extension'=>$extension];
    }

    private static function telefono(string $valor): string
    {
        $digits = preg_replace('/\D/', '', $valor);
        // Formatos mexicanos usados por la centralita y por el expediente.
        if (strlen($digits) === 10) return '52'.$digits;
        if (strlen($digits) === 13 && substr($digits,0,3) === '521') return '52'.substr($digits,3);
        return $digits;
    }
}
