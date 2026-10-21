const assert=require('node:assert/strict'),n=require('../public/javascript/telefonia_numeros.js');
for(const value of ['7777885173','+527777885173','527777885173','+5217777885173']){
 assert.equal(n.display(value),'+527777885173');assert.equal(n.key(value),'527777885173');assert.equal(n.dial(value),'7777885173');
}
for(const value of ['+12025550123','+34612345678']){
 assert.equal(n.display(value),value);assert.equal(n.dial(value),value.slice(1));
 assert.notEqual(n.key(value),n.key('7777885173'));
}
assert.equal(n.display('100'),'100');assert.equal(n.dial('100'),'100');
assert.equal(n.dial('No disponible'),'');assert.equal(n.dial('123<script>'),'');
assert.equal(n.key('+52 777 788 5173'),n.key('7777885173'));
console.log('Números: presentación, búsqueda, devolución MX, prefijo legado, internacional y extensión: OK');
