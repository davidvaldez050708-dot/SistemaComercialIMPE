<?php
require_once __DIR__.'/EmbudoComercialPoliticaPropuesta.php';
require_once __DIR__.'/EmbudoComercialIntentosPbxRepository.php';

/**
 * FASE 3 - SIMULADOR COMERCIAL DE SOLO LECTURA.
 *
 * Usa llamadas ya guardadas y vinculadas al prospecto, interpreta el V4
 * multiselección y genera un plan. NO se invoca desde el formulario,
 * controller, bridge, cron ni endpoint. No escribe ni actualiza etapas.
 *
 * Esta clase no autentica sesiones; deberá invocarla un servicio autorizado
 * de servidor, jamás recibir IDs de usuario/asignación desde GET público.
 */
final class EmbudoComercialPlanificadorV3
{
    private mysqli $db;

    public function __construct(mysqli $db) { $this->db=$db; }

    /**
     * Selecciones guardadas (no del navegador): el resultado humano principal
     * tiene prioridad sobre una TAREA o un complemento de comunicación.
     * REQUIERE_SEGUIMIENTO permanece como tarea transversal independiente.
     */
    public static function interpretar(string $resultado, array $selecciones,
        string $version, string $contactoCorte): array
    {
        $permitidos=[
            'BUZON_VOZ','SIN_RESPUESTA','OCUPADO','MENSAJE_AUTOMATICO','OTRO_SIN_CONTACTO',
            'NUMERO_INEXISTENTE','NO_INTERESADO','CONVERSACION_PERSONA',
            'PROSPECTO_POTENCIAL','REQUIERE_SEGUIMIENTO','FUERA_PRESUPUESTO',
            'INSCRITO','NO_CUMPLE_REQUISITOS','NO_SOLICITO_INFORMACION',
            'SIGUIENTE_APERTURA','COMUNICACION_INTERRUMPIDA','WHATSAPP','SMS'
        ];
        $v4=$version==='4';
        $reales=[];
        if ($v4) {
            foreach($selecciones as $codigo){
                if (!is_string($codigo) || !in_array($codigo,$permitidos,true)) {
                    return ['codigo'=>'','acciones'=>[],'seguimiento'=>false,
                        'requiere_revision'=>true,'motivo'=>'SELECCION_DESCONOCIDA'];
                }
                $reales[$codigo]=true;
            }
        }
        $acciones=[];
        foreach(['WHATSAPP','SMS'] as $envio){
            if(isset($reales[$envio]))$acciones[]=$envio;
        }
        $seguimiento=isset($reales['REQUIERE_SEGUIMIENTO']);
        $humanos=array_values(array_diff(array_keys($reales),[
            'WHATSAPP','SMS','REQUIERE_SEGUIMIENTO'
        ]));
        if (count($humanos)>1) {
            return ['codigo'=>'','acciones'=>$acciones,'seguimiento'=>$seguimiento,
                'requiere_revision'=>true,'motivo'=>'MULTIPLE_RESULTADO_PRINCIPAL'];
        }
        // El detalle V4 tiene hasta dos selecciones: si trae dos hechos
        // distintas facetas, la clasificación real no se pierde por un
        // REQUIERE_SEGUIMIENTO guardado como resultado principal.
        $codigo=$humanos[0]??($seguimiento?'REQUIERE_SEGUIMIENTO':$resultado);

        if ($codigo==='COMUNICACION_INTERRUMPIDA_PERSONA') {
            $codigo='COMUNICACION_INTERRUMPIDA';
            $contactoCorte='SI';
        }
        if ($codigo==='COMUNICACION_INTERRUMPIDA' && $contactoCorte!=='SI' &&
            $contactoCorte!=='NO') {
            return ['codigo'=>'','acciones'=>$acciones,'seguimiento'=>$seguimiento,
                'requiere_revision'=>true,'motivo'=>'ACLARAR_CORTE'];
        }
        // Sólo si V4 validó el contacto y guardó el resultado base como
        // CONVERSACION_PERSONA o ENVIO_... se permite interpretarlo.
        if ($v4 && $reales && !$humanos && !$seguimiento &&
            !array_diff(array_keys($reales),['WHATSAPP','SMS'])) {
            if ($resultado==='CONVERSACION_PERSONA') {
                $codigo='CONVERSACION_PERSONA';
            } elseif (in_array($resultado,[
                'ENVIO_WHATSAPP','ENVIO_SMS','ENVIO_WHATSAPP_SMS'
            ],true)) {
                $codigo='SIN_RESPUESTA';
            } else {
                return ['codigo'=>'','acciones'=>$acciones,'seguimiento'=>false,
                    'requiere_revision'=>true,'motivo'=>'CONTACTO_COMPLEMENTO_AMBIGUO'];
            }
        }
        if (in_array($codigo,['ENVIO_WHATSAPP','ENVIO_SMS','ENVIO_WHATSAPP_SMS'],true) &&
            !$v4) {
            return ['codigo'=>'','acciones'=>$acciones,'seguimiento'=>false,
                'requiere_revision'=>true,'motivo'=>'ENVIO_LEGACY_AMBIGUO'];
        }
        if (in_array($codigo,[
            'INTERESADO','SOLICITA_INFORMACION','ANSWERED','OTRO'
        ],true)) {
            return ['codigo'=>'','acciones'=>$acciones,'seguimiento'=>$seguimiento,
                'requiere_revision'=>true,'motivo'=>'RESULTADO_LEGACY_SIN_MAPEO'];
        }
        return ['codigo'=>$codigo,'acciones'=>$acciones,
            'seguimiento'=>$seguimiento,'requiere_revision'=>false,'motivo'=>''];
    }

    private function filas(string $sql, array $args): array
    {
        $s=$this->db->prepare($sql);
        if($args){
            $types=implode('',array_map(static function($v){
                return is_int($v)?'i':'s';
            },$args));
            $s->bind_param($types,...$args);
        }
        try{
            $s->execute();
            return $s->get_result()->fetch_all(MYSQLI_ASSOC);
        }finally{$s->close();}
    }

    /**
     * Consultar la asignación y la llamada PBX ya vinculada por el Bridge
     * vigente. No infiere propiedad por teléfono, nombre ni último registro.
     * Nunca persiste el plan. No llama a aplicar ni moverManual.
     */
    public function planificar(int $usuarioId,int $asignacionId,
        string $extension,string $pbxCallId): array
    {
        if($usuarioId<1||$asignacionId<1||
            !preg_match('/^[0-9]{3,6}$/D',$extension)||
            !preg_match('/^[A-Za-z0-9_-]{6,100}$/D',$pbxCallId)){
            throw new InvalidArgumentException('Identidad comercial o PBX inválida.');
        }
        $rows=$this->filas(
            "SELECT l.asignacion_id,l.usuario_id,l.extension,l.pbx_call_id,
                    l.resultado,c.id llamada_id,c.direccion,c.fecha,
                    r.id resultado_id,r.proxima_accion_at,
                    COALESCE(e.etapa,'NUEVO') etapa,
                    d.catalogo_version,d.contacto_antes_corte
             FROM prospecto_embudo_llamadas l
             JOIN prospecto_asignaciones a ON a.id=l.asignacion_id
                 AND a.asesor_id=l.usuario_id AND a.vigente=1
             JOIN telefonia_ventas_llamadas c ON c.usuario_id=l.usuario_id
                 AND BINARY c.extension=BINARY l.extension
                 AND BINARY c.pbx_call_id=BINARY l.pbx_call_id
             JOIN telefonia_ventas_resultados r ON r.usuario_id=l.usuario_id
                 AND BINARY r.extension=BINARY l.extension
                 AND BINARY r.pbx_call_id=BINARY l.pbx_call_id
                 AND BINARY r.resultado=BINARY l.resultado
             LEFT JOIN telefonia_ventas_resultado_detalles d ON d.resultado_id=r.id
             LEFT JOIN prospecto_embudo_etapas e ON e.asignacion_id=l.asignacion_id
             WHERE l.usuario_id=? AND l.asignacion_id=?
                 AND BINARY l.extension=BINARY ?
                 AND BINARY l.pbx_call_id=BINARY ?
             LIMIT 2",[$usuarioId,$asignacionId,$extension,$pbxCallId]
        );
        if(count($rows)!==1){
            throw new DomainException('No existe un vínculo exacto de resultado PBX y asignación vigente.');
        }
        $r=$rows[0];
        $actual=(string)$r['etapa'];
        // El contenido de las etapas antiguas exige conciliación respaldada
        // por su historial real, no un renombrado automático.
        $catalogo=EmbudoComercialPoliticaPropuesta::ETAPAS_ACTIVAS+
                  EmbudoComercialPoliticaPropuesta::BANDEJAS;
        if(!isset($catalogo[$actual])){
            return ['aplicable'=>false,'requiere_revision'=>true,
                'motivo'=>'ETAPA_LEGACY_REQUIERE_CONCILIACION',
                'etapa_actual'=>$actual,'etapa_propuesta'=>null,
                'intentos'=>null,'acciones'=>[],'mover'=>false];
        }
        $version=(string)($r['catalogo_version']??'');
        $select=[];
        if($version==='4'){
            $s=$this->filas(
                'SELECT codigo FROM telefonia_ventas_resultado_selecciones
                 WHERE resultado_id=? ORDER BY posicion',
                [(int)$r['resultado_id']]);
            $select=array_column($s,'codigo');
            if(!$select){
                return ['aplicable'=>false,'requiere_revision'=>true,
                    'motivo'=>'DETALLE_V4_SIN_SELECCION',
                    'etapa_actual'=>$actual,'etapa_propuesta'=>null,
                    'intentos'=>null,'acciones'=>[],'mover'=>false];
            }
        }
        $interpretado=self::interpretar((string)$r['resultado'],$select,$version,
            (string)($r['contacto_antes_corte']??''));
        if($interpretado['requiere_revision']){
            return ['aplicable'=>false,'requiere_revision'=>true,
                'motivo'=>$interpretado['motivo'],
                'etapa_actual'=>$actual,'etapa_propuesta'=>null,
                'intentos'=>null,'acciones'=>$interpretado['acciones'],'mover'=>false];
        }
        $dia=substr((string)$r['fecha'],0,10);
        $intentos=(new EmbudoComercialIntentosPbxRepository($this->db))
            ->resumenJornada($usuarioId,$asignacionId,$dia,
                (string)$r['fecha'],(int)$r['llamada_id']);

        // Los siguientes flags requieren evidencias comerciales adicionales
        // aún inexistentes; jamás inventar intención, presupuesto o pago.
        $ctx=[
            'contactoAntesCorte'=>(string)($r['contacto_antes_corte']??''),
            'fechaAccionVerificada'=>(string)($r['proxima_accion_at']??'')!==''
        ];
        $plan=EmbudoComercialPoliticaPropuesta::evaluarLlamada(
            $actual,$interpretado['codigo'],$intentos['total'],$ctx
        );
        return [
            'aplicable'=>false, // Fase 3: sólo previsualizar; ninguna escritura
            'etapa_actual'=>$actual,
            'etapa_propuesta'=>$plan['etapa_propuesta'],
            'subetapa_propuesta'=>$plan['subetapa_propuesta'],
            'mover'=>$plan['mover'],
            'requiere_revision'=>$plan['requiere_revision'],
            'motivo'=>$plan['accion'],
            'resultado_interpretado'=>$interpretado['codigo'],
            'acciones'=>$interpretado['acciones'],
            'seguimiento_solicitado'=>$interpretado['seguimiento'],
            'proxima_accion_guardada'=>(string)($r['proxima_accion_at']??''),
            'intentos'=>$intentos,
            'referencia_pbx'=>$pbxCallId,
            'modo'=>'SIMULACION_SOLO_LECTURA'
        ];
    }
}
