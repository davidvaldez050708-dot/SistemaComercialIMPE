<?php
// Flujos OAuth y SMTP AUTH simulados: no crea apps, no contacta proveedores ni envía mensajes.
require dirname(__DIR__) . '/app/services/CorreoCuentaConectadaService.php';
require dirname(__DIR__) . '/app/controllers/CorreoConexionController.php';
function oauthExpect($ok, $label) { if (!$ok) { throw new RuntimeException($label); } }
function oauthError(callable $f, string $expected) {
    try { $f(); throw new LogicException('No se rechazó ' . $expected); }
    catch (RuntimeException $e) { oauthExpect($e->getMessage() === $expected, $expected . ' / ' . $e->getMessage()); }
}
$work = sys_get_temp_dir() . '/impe-connect-' . bin2hex(random_bytes(6)); mkdir($work, 0700, true);
putenv('IMPE_PRIVATE_CONFIG_DIR=' . $work); putenv('IMPE_MAIL_STATE_DIR=' . $work . '/state');
$app = ['enabled' => true, 'client_id' => 'CLIENTE_QA', 'client_secret' => 'SECRETO_QA',
    'redirect_uri' => 'https://crm.example.test/index.php?controller=correoConexion&action=callback',
    'authorize_url' => 'https://NO_AUTORIZADO.test/', 'token_url' => 'https://NO_AUTORIZADO.test/'];
$write = static function ($name, $array) use ($work) { file_put_contents($work . '/' . $name, '<?php return ' . var_export($array, true) . ';'); };
$write('mail_oauth.local.php', ['providers' => ['google' => $app, 'microsoft' => $app + ['tenant' => 'common']]]);
$authorizedEmail = 'prueba@gmail.com';
$calls = 0; $probes = 0; $okProbe = true; $refresh = true; $returnedScope = null;
$service = new CorreoConexionOAuthService(
    static function ($url, $fields) use (&$calls, &$refresh, &$returnedScope) {
        $calls++;
        oauthExpect(in_array($url, ['https://oauth2.googleapis.com/token', 'https://login.microsoftonline.com/common/oauth2/v2.0/token'], true), 'token endpoint fijado');
        oauthExpect($fields['grant_type'] === 'authorization_code' && isset($fields['code_verifier']) && !isset($fields['password']), 'intercambio servidor con PKCE');
        $response = ['access_token' => 'ACCESS_QA', 'refresh_token' => $refresh ? 'REFRESH_QA' : '', 'expires_in' => 3600];
        if ($returnedScope !== null) { $response['scope'] = $returnedScope; }
        return $response;
    },
    static function ($a, $token) use (&$probes, &$okProbe) {
        $probes++;
        oauthExpect($a['smtp_username'] === $a['correo'] && $a['auth_method'] === 'oauth2' && $a['smtp_encryption'] === 'tls' && $token === 'ACCESS_QA', 'prueba de identidad exacta con SMTP AUTH');
        return $okProbe;
    }, static function ($id) use (&$authorizedEmail) { return ['correo' => $authorizedEmail, 'estado' => 1]; });
$session = ['usuario_id' => 7]; $email = 'prueba@gmail.com';
try {
    $url = $service->iniciar(7, $email, 'google', $session);
    oauthExpect(str_starts_with($url, 'https://accounts.google.com/o/oauth2/v2/auth?') && !str_contains($url, 'SECRETO_QA'), 'consentimiento fijado sin secretos');
    parse_str(parse_url($url, PHP_URL_QUERY), $q);
    $attempt = $session['mail_oauth_attempts'][$q['state']];
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $attempt['verifier'], true)), '+/', '-_'), '=');
    oauthExpect($q['code_challenge'] === $challenge && $q['code_challenge_method'] === 'S256' && $q['access_type'] === 'offline', 'PKCE y acceso offline');
    oauthExpect(str_contains($q['scope'], 'gmail.send') && !str_contains($q['scope'], 'https://mail.google.com/'), 'Google sólo envío e identidad');
    oauthExpect($service->obtener(7, $email) === null, 'no conectar antes del consentimiento');
    oauthError(static function () use ($service, &$session) { $service->completar(7, ['state' => str_repeat('0', 64), 'code' => 'FORJADO'], $session); }, 'MAIL_OAUTH_STATE');
    oauthExpect($calls === 0 && $probes === 0, 'estado forjado sin red');
    $returned = $service->completar(7, ['state' => $q['state'], 'code' => 'CODE_QA'], $session);
    oauthExpect($returned === $email && $calls === 1 && $probes === 1, 'conectar tras prueba de identidad');
    oauthError(static function () use ($service, &$session, $q) { $service->completar(7, ['state' => $q['state'], 'code' => 'REPETIDO'], $session); }, 'MAIL_OAUTH_STATE');
    $a = $service->obtener(7, $email);
    oauthExpect($a['refresh_token'] === 'REFRESH_QA' && $a['transport'] === 'gmail_api', 'Gmail OAuth por HTTPS, credenciales sólo en servidor');
    $token = new CorreoOAuthTokenProvider($a, static function () { throw new LogicException('No debe renovar inmediatamente'); });
    oauthExpect(str_contains(base64_decode($token->getOauth64()), 'ACCESS_QA'), 'caché inicial lista sin repetir intercambio');
    $resolver = new CorreoCuentaConectadaService(static function () { return ['correo' => 'prueba@gmail.com', 'estado' => 1]; });
    oauthExpect($resolver->resolver($email, 7)['account']['correo'] === $email && !$resolver->resolver($email, 8)['ok'], 'conexión por propietario');
    oauthError(static function () use ($service, &$session) { $service->iniciar(7, 'ajeno@gmail.com', 'google', $session); }, 'MAIL_OAUTH_FORBIDDEN');
    $service->desconectar(7, $email, $session);
    oauthExpect($service->obtener(7, $email) === null && CorreoEstadoPrivado::leer('oauth', CorreoOAuthTokenProvider::claveCuenta($a)) === [], 'desconectar borra tokens locales');
    foreach (['google'] as $provider) {
        $session = ['usuario_id' => 17]; $from = $provider . '@gmail.com'; $authorizedEmail = $from;
        $url = $service->iniciar(17, $from, $provider, $session); parse_str(parse_url($url, PHP_URL_QUERY), $p);
        $returnedScope = 'openid email'; $beforeProbe = $probes;
        oauthError(static function () use ($service, $p, &$session) { $service->completar(17, ['state' => $p['state'], 'code' => 'PARTIAL_QA'], $session); }, 'MAIL_OAUTH_SCOPE');
        oauthExpect($probes === $beforeProbe && $service->obtener(17, $from) === null, 'consentimiento parcial no conecta ' . $provider);
        $returnedScope = null;
    }
    foreach (['expired', 'wrong-user', 'denied', 'no-refresh', 'wrong-mailbox'] as $case) {
        $session = ['usuario_id' => 20]; $from = $case . '@gmail.com'; $authorizedEmail = $from;
        $url = $service->iniciar(20, $from, 'google', $session); parse_str(parse_url($url, PHP_URL_QUERY), $p);
        $query = ['state' => $p['state'], 'code' => 'CODE_MS_QA']; $user = 20; $expected = 'MAIL_OAUTH_STATE';
        if ($case === 'expired') { $session['mail_oauth_attempts'][$p['state']]['expires'] = time() - 1; }
        if ($case === 'wrong-user') { $user = 21; }
        if ($case === 'denied') { $query['error'] = 'access_denied'; $expected = 'MAIL_OAUTH_DENIED'; }
        if ($case === 'no-refresh') { $refresh = false; $expected = 'MAIL_OAUTH_TOKEN'; }
        if ($case === 'wrong-mailbox') { $okProbe = false; $expected = 'MAIL_OAUTH_MAILBOX'; }
        oauthError(static function () use ($service, $user, $query, &$session) { $service->completar($user, $query, $session); }, $expected);
        oauthExpect($service->obtener(20, $from) === null, 'fallo no autoriza ' . $case);
        $refresh = true; $okProbe = true;
    }
    $authorizedEmail = $email;
    $write('mail_accounts.local.php', ['accounts' => [$email => ['activo' => true, 'usuarios' => [99], 'transport' => 'smtp', 'auth_method' => 'oauth2']]]);
    $session = ['usuario_id' => 7];
    oauthError(static function () use ($service, $email, &$session) { $service->iniciar(7, $email, 'google', $session); }, 'MAIL_OAUTH_FORBIDDEN');
    $app['redirect_uri'] = 'http://crm.example.test/index.php?controller=correoConexion&action=callback';
    $write('mail_oauth.local.php', ['providers' => ['google' => $app]]);
    oauthExpect(!$service->disponibles()['google'], 'redirect sin HTTPS bloqueado');
    foreach ([['Marketing', 4, [], true], ['Analista', 2, ['seguimientos_vinculacion.operar_propios'], true],
        ['Cuenta Clave', 3, ['reuniones.gestionar'], true], ['Administrador', 1, ['reuniones.gestionar'], false], ['Otro', 8, [], false]] as [$role, $roleId, $permissions, $allowed]) {
        $_SESSION = ['usuario_id' => 7, 'rol' => $role, 'rol_id' => $roleId, 'permisos' => $permissions];
        oauthExpect(CorreoConexionController::puedeAcceder() === $allowed, 'permiso ' . $role);
    }
    $_SESSION = [];
    oauthExpect(CorreoLimiteService::tomar('qa', 70, 1, 3600) && !CorreoLimiteService::tomar('qa', 70, 1, 3600), 'límite por usuario');
    echo "OK: autorización Google simulada, estado y PKCE, sesión/propiedad, caducidad/replay/cancelación, buzón rechazado, secretos privados, caché, desconexión y permisos/límites. Sin proveedores ni envíos reales.\n";
} finally {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $p) { $p->isDir() ? rmdir($p->getPathname()) : unlink($p->getPathname()); } rmdir($work);
}
