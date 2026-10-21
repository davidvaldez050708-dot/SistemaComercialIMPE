<?php
define('ROOT_PATH', dirname(__DIR__)); define('BASE_URL', 'https://crm.example.test/');
require ROOT_PATH . '/app/services/CorreoRecuperacionService.php';
function recoveryExpect($ok, $label) { if (!$ok) { throw new RuntimeException($label); } }
class RecoveryUsersQA {
    public $u, $writes=0, $fail=false;
    public function __construct($id) { $this->u=['id'=>$id,'correo'=>'registrado'.$id.'@example.test','nombre'=>'Persona','apellidos'=>'QA','estado'=>1,'rol_estado'=>1,'password'=>password_hash('ANTERIOR_QA', PASSWORD_BCRYPT, ['cost'=>4])]; }
    public function buscarPorUsuarioOCorreo($dato) { return $dato==='inexistente' ? null : $this->u; }
    public function establecerPasswordTemporalSiVigente($id,$old,$next,$expires) {
        if ($this->fail || $old!==$this->u['password']) { return false; }
        recoveryExpect($id===$this->u['id'] && password_get_info($next)['algo']!==null, 'sólo hash para el usuario encontrado');
        recoveryExpect(abs(strtotime($expires)-time()-1800)<4,'vigencia 30 minutos');
        $this->u['password']=$next; $this->u['requiere_cambio_password']=1; $this->writes++; return true;
    }
}
class RecoveryDeliveryQA extends MailService {
    public $calls=0,$password,$to,$ok=true,$unknown=false,$race;
    public function enviarPasswordTemporal($to,$name,$password) { $this->calls++; $this->to=$to; $this->password=$password; if ($this->race) { ($this->race)(); } return $this->ok; }
    public function ultimoResultado(): array { return ['estado_entrega'=>$this->unknown?'DESCONOCIDO':'RECHAZADO']; }
}
$work=sys_get_temp_dir().'/impe-recovery-flow-'.bin2hex(random_bytes(6)); mkdir($work,0700,true); putenv('IMPE_MAIL_STATE_DIR='.$work);
try {
    $u=new RecoveryUsersQA(1); $m=new RecoveryDeliveryQA(); $s=new CorreoRecuperacionService($u,$m);
    $s->solicitar('usuario','127.0.0.1');
    recoveryExpect($m->to===$u->u['correo'] && password_verify($m->password,$u->u['password']) && $u->u['requiere_cambio_password']===1,'correo registrado, login temporal y cambio obligatorio');
    recoveryExpect(!password_verify('ANTERIOR_QA',$u->u['password']) && strlen($m->password)>=10,'credencial anterior invalidada sólo tras aceptación');
    $previous=$m->password; $s->solicitar('usuario','127.0.0.1');
    recoveryExpect(!password_verify($previous,$u->u['password']) && password_verify($m->password,$u->u['password']),'temporal anterior invalidada al completar nueva solicitud');
    $s->solicitar('usuario','127.0.0.1'); $s->solicitar('usuario','127.0.0.1');
    recoveryExpect($m->calls===3,'frecuencia limitada');
    foreach (['reject','unknown','db','race','disabled','missing'] as $i=>$case) {
        $u=new RecoveryUsersQA($i+10); $m=new RecoveryDeliveryQA(); $old=$u->u['password'];
        if ($case==='reject'||$case==='unknown') { $m->ok=false; $m->unknown=$case==='unknown'; }
        if ($case==='db') { $u->fail=true; }
        if ($case==='race') { $m->race=static function()use($u){$u->u['password']='NUEVO_HASH_CONCURRENTE';}; }
        if ($case==='disabled') { $u->u['estado']=0; }
        $s=new CorreoRecuperacionService($u,$m); $input=$case==='missing'?'inexistente':$case;
        $s->solicitar($input,'127.0.0.'.($i+10));
        recoveryExpect($u->writes===0 && $u->u['password']===($case==='race'?'NUEVO_HASH_CONCURRENTE':$old),'conservar contraseña ante fallo '.$case);
        if ($case==='unknown') { $s->solicitar($input,'127.0.0.11'); recoveryExpect($m->calls===1,'no repetir incertidumbre mientras la temporal podría estar vigente'); }
        if ($case==='disabled'||$case==='missing') { recoveryExpect($m->calls===0,'sin correo para cuenta no apta'); }
    }
    foreach (glob($work.'/*.json') as $f) { $data=file_get_contents($f); recoveryExpect(!str_contains($data,'password') && !str_contains($data,'@example.test'),'sin contraseñas ni destinatarios en estado técnico'); }
    // La página y el modal de recuperación siguen intactos respecto a main.
    echo "OK: destinatario registrado, hash, vigencia/cambio obligatorio, invalidación, límites, rechazo/incertidumbre/BD/concurrencia sin perder contraseña. Sin red ni BD real.\n";
} finally { foreach(glob($work.'/*') as $p){unlink($p);} rmdir($work); }
