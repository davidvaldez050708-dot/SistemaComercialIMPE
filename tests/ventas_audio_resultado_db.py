"""Audio comercial, métricas y accesos en MariaDB local, sin proveedor real."""
import io,os,pathlib,shutil,subprocess,tarfile,tempfile
R=pathlib.Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='impe-audio-resultado-') as folder:
 P=pathlib.Path(folder);(P/'config').mkdir();(P/'tests').mkdir()
 with tarfile.open(fileobj=io.BytesIO(subprocess.check_output(['git','archive','08451cb7','app'],cwd=R))) as snapshot:snapshot.extractall(P,filter='data')
 names=['TelefoniaResultadoVentasService.php','TelefoniaResultadoVentasCatalogo.php','TelefoniaDevolucionVentasService.php','TelefoniaVentasLlamadasService.php','VentasActividadRepository.php','VentasActividadService.php','VentasActividadMetricas.php','VentasActividadPdfView.php','VentasDesempenoPdfView.php','SupervisionLlamadasRepository.php','SupervisionLlamadasAudioService.php']
 for name in names:shutil.copyfile(R/'app/services'/name,P/'app/services'/name)
 for name,rev in {'app/helpers/VentasPendientesPolicy.php':'77d57ada','app/helpers/VentasActividadAccess.php':'77d57ada','app/services/TelefoniaLimpiezaGuard.php':'6ba06aa6','app/services/TelefoniaMarcacionesVentasService.php':'6ba06aa6'}.items():(P/name).write_bytes(subprocess.check_output(['git','show',rev+':'+name],cwd=R))
 fixture=(R/'tests/telefonia_ventas_db.php').read_text(encoding='utf8').replace("SET time_zone='-06:00'","SET time_zone='+00:00'")
 cases=['ventas_whatsapp_casos.php','ventas_todos_cierres_casos.php','ventas_catalogo_envios_casos.php','ventas_resultados_dobles_casos.php','ventas_consulta_doble_casos.php','ventas_reporte_horarios_casos.php','ventas_audio_resultado_casos.php']
 needle=' // Reasignación: el tramo congelado conserva su propietario histórico.'
 assert fixture.count(needle)==1
 fixture=fixture.replace(needle,''.join(" require __DIR__.'/"+name+"';\n" for name in cases)+needle)
 (P/'tests/telefonia_ventas_db.php').write_text(fixture,encoding='utf8')
 for name in cases+['ventas_audio_resultado_api.php']:shutil.copyfile(R/'tests'/name,P/'tests'/name)
 (P/'prueba_telefonia/api').mkdir(parents=True);shutil.copyfile(R/'prueba_telefonia/api/grabacion_ventas.php',P/'prueba_telefonia/api/grabacion_ventas.php')
 shutil.copyfile(R/'app/views/telefonia/ventas_llamadas.php',P/'app/views/telefonia/ventas_llamadas.php')
 shutil.copyfile(R/'app/views/telefonia/supervision_llamadas.php',P/'app/views/telefonia/supervision_llamadas.php')
 (P/'app/helpers/AvatarHelper.php').write_text("<?php function renderAvatarUsuario(...$args){return '<span aria-hidden=true>QA</span>';}",encoding='utf8')
 (P/'app/views/telefonia/ventas_paginacion.php').write_bytes(subprocess.check_output(['git','show','8dcab048:app/views/telefonia/ventas_paginacion.php'],cwd=R))
 shutil.copytree(R/'public/css',P/'public/css');shutil.copytree(R/'public/javascript',P/'public/javascript')
 (P/'config/db_connection.php').write_text("<?php class Database {public function connect($persistente=true){$name=getenv('IMPE_TEST_DATABASE');if(!str_starts_with($name,'impe_ventas_test_'))throw new RuntimeException('Solo test local');$db=new mysqli('127.0.0.1','root','',$name,33319);$db->set_charset('utf8mb4');$db->query('SET time_zone='.chr(39).'+00:00'.chr(39));return $db;}}",encoding='utf8')
 shutil.copyfile(R/'tests/ventas_audio_resultado_proveedor.php',P/'app/services/ZadarmaRecordingService.php')
 (P/'app/models/RolModel.php').write_text("<?php class RolModel {public function inicializarPermisosSistema(){} public function obtenerCodigosPermisosPorRol($role){$db=(new Database())->connect(false);$s=$db->prepare('SELECT p.codigo FROM permisos p JOIN rol_permisos r ON r.permiso_id=p.id WHERE r.rol_id=? AND p.estado=1');$s->bind_param('i',$role);$s->execute();return array_column($s->get_result()->fetch_all(MYSQLI_ASSOC),'codigo');}}",encoding='utf8')
 (P/'app/helpers/PermissionHelper.php').write_text('<?php function tienePermiso($code){global $db;if(!$db)$db=(new Database())->connect(false);$role=(int)($_SESSION["rol_id"]??0);$s=$db->prepare("SELECT p.id FROM permisos p JOIN rol_permisos r ON r.permiso_id=p.id WHERE r.rol_id=? AND p.codigo=? AND p.estado=1");$s->bind_param("is",$role,$code);$s->execute();return (bool)$s->get_result()->fetch_assoc();}',encoding='utf8')
 env=dict(os.environ,IMPE_TEST_MYSQL_PORT='33319',IMPE_TEST_MYSQL_USER='root',IMPE_TEST_MYSQL_PASSWORD='')
 subprocess.run([os.environ.get('PHP_BINARY','php'),str(P/'tests/telefonia_ventas_db.php')],env=env,check=True)
