<?php
/** Agrupación y selección explícitas. No consulta BD ni deduce programas. */
final class ProspectoMetaClasificacion
{
    public static function agrupar(array $data): array
    {
        $forms=[];$campaigns=[];
        foreach ($data['filas'] as &$row) {
            $id=(string)($row['form_id']??'');$name=(string)($row['form_name']??'');
            $key=hash('sha256',$id!==''?'id:'.$id:($name!==''?'nombre:'.$name:'sin-formulario'));
            $row['formulario_clave']=$key;
            if (!isset($forms[$key])) $forms[$key]=['clave'=>$key,'id'=>$id,
                'nombre'=>$name!==''?$name:'Formulario no informado','total'=>0];
            $forms[$key]['total']++;
            $campaign=$row['campania_clave'];
            if (!isset($campaigns[$campaign])) $campaigns[$campaign]=['clave'=>$campaign,
                'id'=>$row['campaign_id'],'nombre'=>$row['campaign_name']?:'Campaña no especificada',
                'total'=>0,'formularios'=>[],'advertencias'=>[]];
            $campaigns[$campaign]['total']++;
            $campaigns[$campaign]['formularios'][$key]=$forms[$key]['nombre'];
            $warning=self::advertencia($row['campaign_name'],$name);
            if ($warning!==null) $campaigns[$campaign]['advertencias'][$warning]=$warning;
        }
        unset($row);
        foreach ($campaigns as &$campaign) $campaign['advertencias']=array_values($campaign['advertencias']);
        unset($campaign);
        $data['formularios']=array_values($forms);
        $data['campanias']=array_values($campaigns);
        $data['muestra']=array_slice($data['filas'],0,12);
        return $data;
    }

    private static function referenciaAcademica(string $value): ?string
    {
        $value=strtr(mb_strtolower($value,'UTF-8'),['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u']);
        $bach=(bool)preg_match('/\bbachillerato\b/u',$value);
        $title=(bool)preg_match('/\btitulacion\b/u',$value);
        return $bach!==$title?($bach?'Bachillerato':'Titulación'):null;
    }

    public static function advertencia(string $campaign,string $form): ?string
    {
        $a=self::referenciaAcademica($campaign);$b=self::referenciaAcademica($form);
        return $a!==null && $b!==null && $a!==$b
            ?'Esta campaña contiene una referencia a '.$a.', pero utiliza el formulario '.$form.'. Verifica su clasificación.'
            :null;
    }

    public static function resolver(array $data,array $campaigns,?array $forms,array $overrides,array $catalog): array
    {
        $available=[];
        foreach ($catalog as $program) $available[(string)$program['codigo']]=(string)$program['nombre'];
        $program=static function ($code) use ($available): array {
            if (!is_string($code) || ($code!==''&&!isset($available[$code])))
                throw new DomainException('Selecciona un programa educativo del catálogo.');
            return ['codigo'=>$code!==''?$code:null,'nombre'=>$code!==''?$available[$code]:null];
        };
        // Peticiones del formulario anterior: conservan su selección por campaña.
        if ($forms===null) {
            $result=[];
            foreach ($data['campanias'] as $c) $result[$c['clave']]=$program($campaigns[$c['clave']]??'');
            return $result;
        }
        $formKeys=array_column($data['formularios'],'clave');
        $campaignKeys=array_column($data['campanias'],'clave');
        if (array_diff(array_keys($forms),$formKeys) || array_diff(array_keys($campaigns),$campaignKeys) ||
            array_diff(array_keys($overrides),$campaignKeys)) throw new DomainException('La clasificación no corresponde al archivo seleccionado.');
        $result=['version'=>2,'formularios'=>[],'campanias'=>[]];
        foreach ($formKeys as $key) $result['formularios'][$key]=$program($forms[$key]??'');
        foreach ($campaignKeys as $key) {
            $override=$overrides[$key]??'0';
            if (!in_array($override,['0','1',0,1],true)) throw new DomainException('La excepción de campaña no es válida.');
            $result['campanias'][$key]=(string)$override==='1'?$program($campaigns[$key]??''):null;
        }
        return $result;
    }

    public static function programaFila(array $classification,array $row): ?array
    {
        if (($classification['version']??null)!==2) return $classification[$row['campania_clave']]??null;
        return $classification['campanias'][$row['campania_clave']]??
            $classification['formularios'][$row['formulario_clave']]??null;
    }
}
