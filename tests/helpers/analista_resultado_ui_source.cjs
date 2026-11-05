const fs=require('fs'),zlib=require('zlib'),path=require('path');
let release;
module.exports=function read(file){
 if(process.env.IMPE_ANALISTA_UI_VARIANT==='hostinger'){
  release||=JSON.parse(fs.readFileSync(path.resolve(__dirname,'../../tools/analista_resultado_obligatorio_ui_release.json'),'utf8'));
  const row=release.files.find(row=>row.path===file);
  if(row)return zlib.gunzipSync(Buffer.from(row.variants[Math.min(1,row.variants.length-1)].target,'base64')).toString('utf8');
 }
 return fs.readFileSync(file,'utf8');
};
