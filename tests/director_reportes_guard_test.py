import os,pathlib,shutil,subprocess
root=pathlib.Path(__file__).resolve().parents[1]
php=os.environ.get('PHP_BINARY') or shutil.which('php')
assert php,'PHP requerido'
for case in ['permitido','sin_exportar','sin_usuarios','sin_centro','otro_rol','lectura_revocada']:
    r=subprocess.run([php,str(root/'tests/director_reportes_guard.php'),case],capture_output=True)
    text=r.stdout.decode('utf8',errors='replace')
    assert r.returncode==0 and ('HTTP=200' if case=='permitido' else 'HTTP=403') in text,(case,text,r.stderr)
    assert (b'AUTORIZADO' in r.stdout)==(case=='permitido')
print('Reportes: acceso habilitado y cinco denegaciones reales tras revocar permisos o cambiar de rol OK')
