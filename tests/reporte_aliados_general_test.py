"""Modelo y reporte reales con SQLite en memoria; cero conexiones al CRM."""
from pathlib import Path
import json
import os
import shutil
import subprocess
import tempfile

ROOT=Path(__file__).resolve().parents[1]
FILES=['app/models/AliadoModel.php','app/services/ReporteAliadosManualService.php',
       'app/services/ReporteAliadosPanoramaService.php','app/services/ReporteAliadosPdfService.php',
       'app/services/ReporteNombreArchivoService.php','app/views/reportes/aliados.php',
       'app/views/reportes/partials/aliados_panorama_filtros.php']

def main():
    php=os.environ.get('PHP_BIN') or shutil.which('php') or 'C:/xampp/php/php.exe'
    flags=['-n']
    if os.name=='nt':flags+=['-d','extension_dir='+str(Path(php).parent/'ext')]
    has_pdo=subprocess.run([php,'-n','-r',"echo class_exists('PDO') ? 'yes' : 'no';"],capture_output=True,text=True,check=True).stdout
    if has_pdo!='yes':flags+=['-d','extension=pdo']
    flags+=['-d','extension=pdo_sqlite','-d','extension=mbstring']
    with tempfile.TemporaryDirectory(prefix='impe-report-test-') as tmp:
        site=Path(tmp)
        for name in FILES:
            dest=site/name;dest.parent.mkdir(parents=True,exist_ok=True);shutil.copyfile(ROOT/name,dest)
        (site/'config').mkdir()
        (site/'config/db_connection.php').write_text("<?php class Database { public static int $opens=0; function connect(){self::$opens++;throw new RuntimeException('No network allowed');} }",encoding='utf-8')
        shutil.copyfile(ROOT/'tests/fixtures/reporte_aliados_manuales.php',site/'test.php')
        result=subprocess.run([php,*flags,str(site/'test.php'),'after'],text=True,capture_output=True)
        assert result.returncode==0,result.stdout+result.stderr
        report=json.loads(result.stdout)
        assert report['status']=='PASS'
        print(json.dumps(report))

if __name__=='__main__':main()
