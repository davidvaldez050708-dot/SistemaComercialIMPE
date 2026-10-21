const assert=require('node:assert/strict'),cp=require('node:child_process');
const render=options=>cp.execFileSync('php',['tests/fixtures/telefonia_host_observador.php',JSON.stringify(options)],{encoding:'utf8'});
const config=html=>JSON.parse(html.match(/window\.IMPE_TELEPHONY_HOST = (\{[^;]+\});/)[1]);
const cases=[
 [{role:'Asesor de Ventas',permissions:['telefonia.usar','telefonia.recibir']},true],
 [{role:'Asesor',permissions:['telefonia.usar','telefonia.recibir']},true],
 [{role:'Analista de Datos'},true],
 [{role:'Marketing'},false],
 [{role:'Otro'},false],
 [{permissions:['telefonia.usar']},false],
 [{permissions:['telefonia.recibir']},false],
 [{incoming:false},false],
 [{extension:''},false],
 [{display:'tab'},false]
];
for(const [options,expected] of cases){const html=render(options),cfg=config(html);
 assert.equal(cfg.analystSdkIncoming,expected,JSON.stringify(options));
 assert.equal(html.includes('src="'+(options.role==='Analista de Datos'?'http://llamadas.test/':'http://ventas.test/')+'public/javascript/telefonia_sdk_entrantes_observador.js?'),expected,'Carga real del observador debe concordar con el flag');
 assert.equal(cfg.permiteSalientes,true,'No alterar llamadas salientes normales');
}
console.log('Host PHP real: Ventas/Asesor y Analista comparten observador; permisos, asignación, Marketing y modo pestaña conservados: OK');
