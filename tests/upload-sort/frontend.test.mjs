import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const read=p=>fs.readFileSync(new URL(p.startsWith('baselines/')?'./'+p:'../../'+p.replace(/^upload\//,''),import.meta.url),'utf8').replaceAll('\r\n','\n');
const source=read('upload/assets/files.js');
const uploadSection=source.slice(source.indexOf('// AJAX 上传处理'),source.indexOf('// 移动文件功能'));
let checks=0;
async function check(name,fn){await fn();checks++;console.log('PASS: '+name);}
function fixture({status=200,response='{"ok":true}',xhrStatus=200,xhrText='',networkError=null,size=10,name='test.txt',noFile=false}={}) {
 const elements={};
 for(const id of ['uploadForm','submitBtn','error-message','success-message','progressContainer','progressBar','progressPercent','file','currentDirParam']) {
  const classes=new Set(['hidden']);elements[id]={disabled:false,value:id==='currentDirParam'?'fixture/custom':'',textContent:'',style:{},classList:{add:x=>classes.add(x),remove:x=>classes.delete(x),contains:x=>classes.has(x)}};
 }
 elements.file.files=noFile?[]:[{name,size}];
 const requests=[],alerts=[],timers=[],xhr=[];
 class FD {constructor(form){this.entries=[];if(form){this.append('_csrf','synthetic-token');this.append('file',elements.file.files[0]);this.append('password','synthetic-only');}}append(k,v){this.entries.push([k,v]);}get(k){return this.entries.find(x=>x[0]===k)?.[1]??null;}}
 class XHR {constructor(){this.upload={};xhr.push(this);}open(method,url){assert.equal(method,'POST');assert.equal(url,'upload.php');}send(body){this.body=body;this.status=xhrStatus;this.responseText=xhrText;this.onload();}}
 const context={document:{getElementById:id=>elements[id]},FormData:FD,AbortController,XMLHttpRequest:XHR,prohibitedExts:['php','exe'],window:{location:{reload(){throw Error('unexpected immediate reload');}}},setTimeout:(fn,delay)=>{timers.push({fn,delay});return timers.length;},clearTimeout:()=>{},showAppAlert:(message,options)=>{alerts.push({message,options});return Promise.resolve();},fetch:async(url,options)=>{requests.push({url,...options});if(networkError)throw networkError;return{ok:status===200,status,text:async()=>response};}};
 vm.runInNewContext(uploadSection,context);
 return{elements,requests,alerts,timers,xhr,run:()=>elements.uploadForm.onsubmit.call(elements.uploadForm,{preventDefault(){}})};
}
await check('duplicate precheck prevents file transfer and shows themed warning',async()=>{
 const f=fixture({status:409,response:'当前目录中已存在同名文件。'});await f.run();assert.equal(f.xhr.length,0);assert.equal(f.alerts[0].options.title,'文件已存在');assert.equal(f.alerts[0].options.type,'warning');assert.match(f.elements['error-message'].textContent,/同名/);assert.equal(f.elements.submitBtn.disabled,false);
});
await check('precheck carries CSRF/dir/name but never file bytes or password',async()=>{
 const f=fixture();await f.run();const d=f.requests[0].body;assert.equal(d.get('_csrf'),'synthetic-token');assert.equal(d.get('dir'),'fixture/custom');assert.equal(d.get('filename'),'test.txt');assert.equal(d.get('file'),null);assert.equal(d.get('password'),null);assert.equal(f.xhr[0].body.get('password'),'synthetic-only');
});
await check('successful check uploads once and schedules existing success refresh',async()=>{const f=fixture();await f.run();assert.equal(f.xhr.length,1);assert.match(f.elements['success-message'].textContent,/上传成功/);assert.ok(f.timers.some(t=>t.delay===1000));});
await check('late conflict between precheck and upload is also visible',async()=>{const f=fixture({xhrStatus:409,xhrText:'同名文件已存在'});await f.run();assert.equal(f.alerts.length,1);assert.equal(f.elements['success-message'].classList.contains('hidden'),true);assert.equal(f.timers.some(t=>t.delay===1000),false);});
await check('authentication and CSRF failures stop the upload',async()=>{for(const status of [401,403]){const f=fixture({status,response:'安全验证失败'});await f.run();assert.equal(f.xhr.length,0);assert.equal(f.elements.submitBtn.disabled,false);}});
await check('HTML/login responses do not masquerade as a successful check',async()=>{const f=fixture({response:'<html>login</html>'});await f.run();assert.equal(f.xhr.length,0);assert.doesNotMatch(f.elements['error-message'].textContent,/<html>/);});
await check('network errors/timeouts restore the upload button',async()=>{for(const name of ['TypeError','AbortError']){const e=new Error('test');e.name=name;const f=fixture({networkError:e});await f.run();assert.equal(f.xhr.length,0);assert.equal(f.elements.submitBtn.disabled,false);}});
await check('empty/prohibited/oversized files do not start requests',async()=>{for(const options of [{noFile:true},{name:'bad.php'},{size:100*1024*1024+1}]){const f=fixture(options);await f.run();assert.equal(f.requests.length,0);assert.equal(f.xhr.length,0);}});
await check('server body-size rejection has an explicit message',async()=>{const f=fixture({xhrStatus:413,xhrText:'<html>too large</html>'});await f.run();assert.match(f.elements['error-message'].textContent,/服务器允许/);});
await check('move/encrypt/delete/preview/batch code remains byte-identical',()=>{assert.equal(source.slice(source.indexOf('// 移动文件功能')),read('baselines/assets__files.js').slice(read('baselines/assets__files.js').indexOf('// 移动文件功能')));});
await check('footer only cache-busts the changed script',()=>{assert.equal(read('upload/views/layout/footer.php').replace('files.js?v=20261023-upload-sort','files.js?v=20260730'),read('baselines/views__layout__footer.php'));});
await check('sort form preserves filters and per-page but resets page',()=>{
 const html=read('upload/views/home.php'); const form=html.match(/<form[^>]*id="fileSortForm"[\s\S]*?<\/form>/)[0];
 for(const name of ['dir','per_page','q','time','size','type','sort'])assert.ok(form.includes(`name="${name}"`));assert.ok(!form.includes('name="page"'));assert.match(form,/onchange="this.form.submit\(\)"/);assert.ok(html.includes('id="uploadForm"'));assert.ok(html.includes('id="perPageSelect"'));
});
console.log(`Frontend regression checks passed: ${checks}`);
