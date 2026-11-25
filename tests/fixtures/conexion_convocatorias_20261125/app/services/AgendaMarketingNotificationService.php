<?php

/** Adapta los comentarios ya autorizados a la campana existente de Marketing. */
class AgendaMarketingNotificationService
{
    private $comentarios;

    public function __construct($comentarios = null) { $this->comentarios=$comentarios; }

    public function obtener(int $usuario): array
    {
        $result=['agenda_ok'=>true,'recordatorios'=>[],'avisos'=>[],'horarios'=>[],'notificaciones'=>[]];
        try {
            $service=$this->comentarios;
            if ($service===null) {
                require_once __DIR__.'/AgendaComentarioService.php';
                $service=new AgendaComentarioService();
            }
            $agenda=$service->notificaciones($usuario);
            $result['recordatorios']=array_values($agenda['recordatorios']??[]);
            $result['avisos']=array_values($agenda['avisos']??[]);
            $result['horarios']=array_values($agenda['horarios']??[]);
            foreach ($result['recordatorios'] as $item) {
                $result['notificaciones'][]=array_merge($item,[
                    'id'=>0,'tipo_evento'=>'agenda_comentario','leida'=>0,'pendiente_operativo'=>true,
                    'titulo'=>(string)($item['titulo']??$item['nombre_entidad']??'Comentario de agenda'),
                    'mensaje'=>(string)($item['mensaje']??$item['accion']??''),
                    'created_at'=>(string)($item['fecha']??''),
                ]);
            }
            $service->registrarEntregados($usuario,$result['recordatorios']);
        } catch (Throwable $error) {
            error_log('[agenda_marketing] '.get_class($error));
            $result=['agenda_ok'=>false,'recordatorios'=>[],'avisos'=>[],'horarios'=>[],'notificaciones'=>[]];
        }
        return $result;
    }
}
