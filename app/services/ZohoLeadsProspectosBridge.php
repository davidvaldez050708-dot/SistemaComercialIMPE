<?php
/** Importación unidireccional de Leads a Prospectos. No asigna ni altera registros existentes. */
require_once __DIR__.'/../../config/private_config.php';
require_once __DIR__.'/ProspectoCentralRepository.php';

final class ZohoLeadsProspectosBridge
{
    private mysqli $db;
    private ProspectoCentralRepository $repo;
    private array $cfg;
    private $http;

    public function __construct(mysqli $db, array $cfg, ?callable $http = null)
    {
        if (($cfg['enabled'] ?? false) !== true ||
            !preg_match('/^[0-9]{10,25}$/D', (string)($cfg['expected_org_id'] ?? ''))) {
            throw new RuntimeException('La importación de Zoho no está configurada.');
        }
        $this->db = $db;
        $this->repo = new ProspectoCentralRepository($db);
        $this->cfg = $cfg;
        $this->http = $http;
    }

    public static function configuracion(): array
    {
        return impeLeerConfigPrivada('zoho_leads.local.php');
    }

    private function solicitar(string $url, ?array $form = null, ?string $token = null): array
    {
        if ($this->http !== null) return ($this->http)($url, $form, $token);
        if (!function_exists('curl_init')) throw new RuntimeException('cURL no está disponible.');
        $ch = curl_init($url);
        if ($ch === false) throw new RuntimeException('No se pudo iniciar la consulta a Zoho.');
        $headers = ['Accept: application/json'];
        if ($token !== null) $headers[] = 'Authorization: Zoho-oauthtoken '.$token;
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>20, CURLOPT_HTTPHEADER=>$headers]);
        if ($form !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form, '', '&', PHP_QUERY_RFC3986));
        }
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_errno($ch);
        curl_close($ch);
        if ($body === false || $error !== 0) throw new RuntimeException('Zoho no respondió.');
        if ($status === 204) return [204, []];
        $json = json_decode($body, true);
        if (!is_array($json)) throw new RuntimeException('Respuesta inválida de Zoho.');
        return [$status, $json];
    }

    private function accessToken(): string
    {
        $home = (string)(getenv('HOME') ?: '');
        if ($home === '' || str_contains($home, 'public_html')) throw new RuntimeException('Directorio privado no disponible.');
        $file = rtrim($home, '/\\').'/.config/sistema_comercial_impe/zoho_oauth.json';
        if (!is_file($file) || !is_readable($file)) throw new RuntimeException('Autorización Zoho no disponible.');
        $oauth = json_decode((string)file_get_contents($file), true);
        if (!is_array($oauth)) throw new RuntimeException('Autorización Zoho inválida.');
        foreach (['client_id','client_secret','refresh_token'] as $key) {
            if (!is_string($oauth[$key] ?? null) || $oauth[$key] === '') throw new RuntimeException('Autorización Zoho incompleta.');
        }
        [$status,$response] = $this->solicitar('https://accounts.zoho.com/oauth/v2/token',
            ['grant_type'=>'refresh_token','client_id'=>$oauth['client_id'],
             'client_secret'=>$oauth['client_secret'],'refresh_token'=>$oauth['refresh_token']]);
        if ($status !== 200 || !is_string($response['access_token'] ?? null) || $response['access_token'] === '') {
            throw new RuntimeException('No se pudo renovar el acceso de lectura a Zoho.');
        }
        return $response['access_token'];
    }

    public function leer(): array
    {
        $token = $this->accessToken();
        $base = 'https://developer.zohoapis.com/crm/v8';
        [$status,$org] = $this->solicitar($base.'/org', null, $token);
        if ($status !== 200 || count($org['org'] ?? []) !== 1 ||
            (string)($org['org'][0]['id'] ?? '') !== (string)$this->cfg['expected_org_id']) {
            throw new RuntimeException('La organización Zoho no coincide.');
        }
        $leads = [];
        for ($page = 1; $page <= 10; $page++) {
            $query = http_build_query(['fields'=>'First_Name,Last_Name,Email,Phone,Mobile,Created_Time,Company',
                'per_page'=>200,'page'=>$page], '', '&', PHP_QUERY_RFC3986);
            [$status,$response] = $this->solicitar($base.'/Leads?'.$query, null, $token);
            if ($status === 204) break;
            if ($status !== 200 || !is_array($response['data'] ?? null)) {
                throw new RuntimeException('No se pudieron leer los posibles clientes de Zoho.');
            }
            foreach ($response['data'] as $lead) {
                if (!is_array($lead)) throw new RuntimeException('Registro Zoho inválido.');
                $leads[] = $lead;
            }
            if (($response['info']['more_records'] ?? false) !== true) break;
            if ($page === 10) throw new RuntimeException('Zoho tiene más de 2000 registros; se requiere paginación ampliada.');
        }
        return $leads;
    }

    public static function convertir(array $lead): ?array
    {
        $id = (string)($lead['id'] ?? '');
        if (!preg_match('/^[0-9]{1,25}$/D', $id)) throw new RuntimeException('ID de Zoho inválido.');
        $nombre = trim((string)($lead['First_Name'] ?? ''));
        $apellido = trim((string)($lead['Last_Name'] ?? ''));
        $completo = trim($nombre.' '.$apellido);
        if ($completo === '') return null;
        $telefono = trim((string)(($lead['Mobile'] ?? null) ?: ($lead['Phone'] ?? '')));
        $correo = trim((string)($lead['Email'] ?? ''));
        $fecha = null;
        if (is_string($lead['Created_Time'] ?? null) && $lead['Created_Time'] !== '') {
            try {$fecha = (new DateTimeImmutable($lead['Created_Time']))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');}
            catch (Throwable $e) {throw new RuntimeException('Fecha de Zoho inválida.');}
        }
        return ['id'=>$id,'nombre'=>$nombre ?: null,'apellidos'=>$apellido ?: null,
            'nombre_completo'=>$completo,'telefono_original'=>$telefono ?: null,
            'correo'=>$correo ?: null,'fecha'=>$fecha];
    }

    public function incorporar(array $lead): bool
    {
        $datos = self::convertir($lead);
        if ($datos === null) return false;
        if ($this->repo->origen('ZOHO_CRM', 'LEAD', $datos['id'])) return false;
        $this->db->begin_transaction();
        try {
            // La clave única uq_po_evento impide dos copias del mismo Lead.
            if ($this->repo->origen('ZOHO_CRM', 'LEAD', $datos['id'])) {
                $this->db->rollback();
                return false;
            }
            $persona = $this->repo->crearPersona($datos);
            $this->repo->guardarOrigen($persona, ['tipo_origen'=>'FORMULARIO_GENERAL',
                'sistema_origen'=>'ZOHO_CRM','tipo_registro'=>'LEAD',
                'identificador_origen'=>$datos['id'],'formulario_nombre'=>'Zoho CRM',
                'datos_originales_ref'=>'zoho:Leads:'.$datos['id'],
                'fecha_registro_original'=>$datos['fecha'],'zona_horaria_origen'=>'UTC']);
            $this->repo->ejecutar("UPDATE prospecto_integraciones SET externo_id=?,modulo_destino='Leads',estado='SINCRONIZADO',sincronizado_at=? WHERE prospecto_id=? AND sistema='ZOHO'",
                [$datos['id'],gmdate('Y-m-d H:i:s'),$persona]);
            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function ejecutar(bool $aplicar): array
    {
        if (!$this->repo->estructura()['disponible']) throw new RuntimeException('Almacén central de prospectos no disponible.');
        $leads = $this->leer();
        $nuevos = 0;
        $existentes = 0;
        $sinNombre = 0;
        foreach ($leads as $lead) {
            $datos = self::convertir($lead);
            if ($datos === null) {$sinNombre++;continue;}
            if ($this->repo->origen('ZOHO_CRM','LEAD',$datos['id'])) {$existentes++;continue;}
            if ($aplicar) {
                if ($this->incorporar($lead)) $nuevos++;
                else $existentes++;
            } else $nuevos++;
        }
        return ['consultados'=>count($leads),'nuevos'=>$nuevos,
            'existentes'=>$existentes,'sin_nombre'=>$sinNombre,'aplicado'=>$aplicar];
    }
}
