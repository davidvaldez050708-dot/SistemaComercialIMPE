'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(__dirname+'/../../../public/javascript/telefonia_recepcion_marketing.js','utf8');
const from=source.indexOf('        function formatDuration(seconds) {'),to=source.indexOf('        function normalizeNumber',from);assert(from>=0&&to>from);
const box={};vm.createContext(box);vm.runInContext(source.slice(from,to)+'\nformat=formatDuration;',box);
for(const value of [null,undefined,'',NaN,Infinity,-1,'invalid'])assert.equal(box.format(value),'—');
assert.equal(box.format(0),'00:00');assert.equal(box.format('0'),'00:00');assert.equal(box.format(37),'00:37');assert.equal(box.format(3661),'1:01:01');
console.log('PASS 11 actual Reception duration formatter checks: unknown/invalid shown as dash, zero and existing minute/hour layout retained.');
