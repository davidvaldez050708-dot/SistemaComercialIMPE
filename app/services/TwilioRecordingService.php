<?php

class TwilioRecordingService
{
    private $accountSid;
    private $authToken;
    private $baseUrl;

    public function __construct()
    {
        $configPath = __DIR__ . '/../../config/voip_config.php';

        if (!is_file($configPath)) {
            throw new RuntimeException('La telefonía no está configurada en este servidor.');
        }

        $config = require $configPath;
        $this->accountSid = trim((string)($config['account_sid'] ?? ''));
        $this->authToken = trim((string)($config['auth_token'] ?? ''));

        if (
            !preg_match('/^AC[a-fA-F0-9]{32}$/', $this->accountSid) ||
            $this->authToken === '' ||
            $this->authToken === 'TU_AUTH_TOKEN_PRIVADO'
        ) {
            throw new RuntimeException('La configuración privada de Twilio está incompleta.');
        }

        $this->baseUrl = 'https://api.twilio.com/2010-04-01/Accounts/' .
            rawurlencode($this->accountSid);
    }

    public function obtenerGrabacionParaLlamada($callSid)
    {
        $callSid = trim((string)$callSid);

        if (!preg_match('/^CA[a-fA-F0-9]{32}$/', $callSid)) {
            return null;
        }

        $llamada = $this->obtenerJson(
            $this->baseUrl . '/Calls/' . rawurlencode($callSid) . '.json'
        );
        $parentSid = trim((string)($llamada['parent_call_sid'] ?? ''));
        $sidsConsulta = [];

        if (preg_match('/^CA[a-fA-F0-9]{32}$/', $parentSid)) {
            $sidsConsulta[] = $parentSid;
        }

        $sidsConsulta[] = $callSid;
        $sidsConsulta = array_values(array_unique($sidsConsulta));

        foreach ($sidsConsulta as $sidConsulta) {
            $resultado = $this->obtenerJson(
                $this->baseUrl . '/Calls/' . rawurlencode($sidConsulta) .
                '/Recordings.json?PageSize=10'
            );
            $grabaciones = is_array($resultado['recordings'] ?? null)
                ? $resultado['recordings']
                : [];

            usort($grabaciones, function ($a, $b) {
                return strcmp(
                    (string)($b['date_created'] ?? $b['start_time'] ?? ''),
                    (string)($a['date_created'] ?? $a['start_time'] ?? '')
                );
            });

            foreach ($grabaciones as $grabacion) {
                $recordingSid = trim((string)($grabacion['sid'] ?? ''));

                if (!preg_match('/^RE[a-fA-F0-9]{32}$/', $recordingSid)) {
                    continue;
                }

                if (strtolower((string)($grabacion['status'] ?? '')) === 'failed') {
                    continue;
                }

                return [
                    'sid' => $recordingSid,
                    'call_sid' => trim((string)($grabacion['call_sid'] ?? $sidConsulta)),
                    'status' => (string)($grabacion['status'] ?? ''),
                    'duration' => max(0, (int)($grabacion['duration'] ?? 0)),
                    'channels' => isset($grabacion['channels'])
                        ? (int)$grabacion['channels']
                        : null,
                    'start_time' => $grabacion['start_time'] ??
                        $grabacion['date_created'] ??
                        null
                ];
            }
        }

        return null;
    }

    public function descargarGrabacion($recordingSid)
    {
        $recordingSid = trim((string)$recordingSid);

        if (!preg_match('/^RE[a-fA-F0-9]{32}$/', $recordingSid)) {
            throw new InvalidArgumentException('La grabación solicitada no es válida.');
        }

        return $this->solicitar(
            $this->baseUrl . '/Recordings/' . rawurlencode($recordingSid) . '.mp3',
            false
        );
    }

    private function obtenerJson($url)
    {
        $respuesta = $this->solicitar($url, true);
        $datos = json_decode($respuesta['body'], true);

        if (!is_array($datos)) {
            throw new RuntimeException('Twilio devolvió una respuesta no válida.');
        }

        return $datos;
    }

    private function solicitar($url, $esperarJson)
    {
        $ch = curl_init($url);
        $headers = $esperarJson ? ['Accept: application/json'] : [];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 7,
            CURLOPT_TIMEOUT => $esperarJson ? 18 : 35,
            CURLOPT_USERPWD => $this->accountSid . ':' . $this->authToken,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_HTTPHEADER => $headers
        ]);

        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $status < 200 || $status >= 300) {
            throw new RuntimeException(
                $error !== '' ? $error : 'Twilio respondió HTTP ' . $status . '.'
            );
        }

        return [
            'body' => $body,
            'content_type' => $contentType
        ];
    }
}
