// Node/PHP built-ins only. Explicit public-source allowlist; no real DB/SMTP/credentials.
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import net from 'node:net';
import {spawn,spawnSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
const tests=path.dirname(fileURLToPath(import.meta.url)), root=path.resolve(tests,'../..');
const source=root;
const php=process.argv[2], ext=path.join(path.dirname(php),'ext');
const args=['-n','-d',`extension_dir=${ext}`,'-d','extension=mbstring','-d','upload_max_filesize=100M','-d','post_max_size=105M'];
const runtime=fs.mkdtempSync(path.join(tests,'.runtime-')), publicDir=path.join(runtime,'public');
for(const d of ['helpers','includes','views/layout','assets','data/fixture/custom','data/fixture/second','data/other/custom']) fs.mkdirSync(path.join(publicDir,d),{recursive:true});
fs.mkdirSync(path.join(runtime,'sessions'));
function copy(from,to) {fs.copyFileSync(from,path.join(publicDir,to));}
for(const p of ['upload.php','helpers/upload_helpers.php','helpers/file_list_helpers.php','includes/search_context.php','views/home.php','views/layout/footer.php','assets/files.js']) copy(path.join(root,p),p);
for(const p of ['security.php','user_helpers.php','helpers/search_helpers.php','views/modals.php','assets/app.js','assets/users.js','assets/shares.js','assets/app.css','views/layout/header.php','assets/cloud-theme.css','assets/cloud-theme.js']) copy(path.join(source,p),p);
for(const p of ['index.php','signin.php']) copy(path.join(tests,'fixtures',p),p);
copy(path.join(tests,'fixtures/account_session.php'),'helpers/account_session.php');
const sampleDir=path.join(publicDir,'data/fixture/custom');
const filenames=Array.from({length:75},(_,i)=>`sample-${String(i).padStart(3,'0')}.txt`);
for(const [i,name] of filenames.entries()) {fs.writeFileSync(path.join(sampleDir,name),'x'.repeat(i+1));fs.utimesSync(path.join(sampleDir,name),1700000000+i,1700000000+i);}
fs.mkdirSync(path.join(sampleDir,'folder-a')); fs.mkdirSync(path.join(sampleDir,'folder-b'));
let checks=0;
const pass=label=>{checks++;console.log('PASS: '+label);};
function sort(get={}, search=false, records=[]) {
 const input={get,search,records,base:path.join(publicDir,'data')+'/',directory:sampleDir+'/',folders:['folder-a','folder-b'],files:filenames};
 const r=spawnSync(php,['-n',path.join(tests,'sort-fixture.php'),JSON.stringify(input)],{encoding:'utf8'});
 assert.equal(r.status,0,r.stderr); assert.equal(r.stderr,''); return JSON.parse(r.stdout);
}
for(const order of ['mtime_asc','mtime_desc','size_asc','size_desc']) {
 for(const per of [30,50,100]) {
  const combined=[];
  for(let page=1;page<=Math.ceil(77/per);page++) {
   const r=sort({sort:order,per_page:per,page,q:'',dir:'fixture/custom'});
   if(page===1) assert.deepEqual(r.folders,['folder-a','folder-b']);
   combined.push(...r.files);
   assert.equal(new URL(r.next,'http://fixture').searchParams.get('sort'),order);
   assert.equal(new URL(r.resize,'http://fixture').searchParams.has('page'),false);
  }
  assert.deepEqual(combined,order.endsWith('desc')?[...filenames].reverse():filenames);
  assert.equal(new Set(combined).size,75); pass(`directory ${order}, ${per}/page: global sort, folder-first, no gaps`);
 }
}
assert.deepEqual(sort({sort:'default'}).files,filenames.slice(0,28)); pass('default directory order retained');
for(const bad of ['bad',[],{},'<script>']) {assert.equal(sort({sort:bad}).order,'default');} pass('invalid sort values safely fall back');
const records=filenames.map((name,i)=>({name,path:'fixture/custom/'+name,size:75-i,mtime:i+1}));
for(const order of ['default','mtime_asc','mtime_desc','size_asc','size_desc']) {
 let actual=[];
 for(let page=1;page<=3;page++) actual.push(...sort({sort:order,page,q:'sample',time:'week',size:'small',type:'文档'},true,records).results);
 const reversed=['default','mtime_desc','size_asc'].includes(order);
 assert.deepEqual(actual.map(x=>x.name),reversed?[...filenames].reverse():filenames); pass(`search ${order}: sorted before slicing`);
}
const ties=[{name:'same.txt',path:'fixture/z/same.txt',size:1,mtime:2},{name:'same.txt',path:'fixture/a/same.txt',size:1,mtime:2}];
assert.equal(sort({sort:'size_desc'},true,ties).results[0].path,'fixture/a/same.txt'); pass('stable tie-breaker across directories');
const empty=sort({sort:'size_asc'},true,[]); assert.equal(empty.total,0); assert.deepEqual(empty.results,[]); pass('empty search remains valid');

// Independent PHP processes exercise real competing hard-link publication.
for(let i=0;i<4;i++) {
 const staged=[path.join(runtime,`staged-${i}-a`),path.join(runtime,`staged-${i}-b`)];
 staged.forEach((p,j)=>fs.writeFileSync(p,`contender-${j}`));
 const dest=path.join(runtime,`published-${i}`), barrier=path.join(runtime,`barrier-${i}`);
 const jobs=staged.map(p=>new Promise((resolve,reject)=>{const c=spawn(php,['-n',path.join(tests,'publish-fixture.php'),p,dest,barrier]);let out='',err='';c.stdout.on('data',d=>out+=d);c.stderr.on('data',d=>err+=d);c.on('error',reject);c.on('exit',code=>code===0?resolve(out):reject(Error(err)));}));
 fs.writeFileSync(barrier,'start');
 const results=await Promise.all(jobs); assert.deepEqual([...results].sort(),['exists','stored']);
 assert.equal(fs.readFileSync(dest,'utf8'),`contender-${results.indexOf('stored')}`);
 pass(`concurrent duplicate publication ${i+1}: one winner, no overwrite`);
}
const port=await new Promise(resolve=>{const s=net.createServer();s.listen(0,'127.0.0.1',()=>{const p=s.address().port;s.close(()=>resolve(p));});});
const base=`http://127.0.0.1:${port}`;
let logs='';
const server=spawn(php,[...args,'-d',`session.save_path=${path.join(runtime,'sessions')}`,'-S',`127.0.0.1:${port}`,'-t',publicDir],{stdio:['ignore','ignore','pipe']});
server.stderr.on('data',d=>logs+=d);
function client(){let cookie='';return async(url,data)=>{const r=await fetch(base+url,{method:data?'POST':'GET',headers:{Cookie:cookie},body:data,redirect:'manual'});const c=r.headers.get('set-cookie');if(c)cookie=c.split(';')[0];return{status:r.status,text:await r.text()};};}
const form=(values)=>{const d=new FormData();for(const [k,v]of Object.entries(values))d.append(k,v);return d;};
try {
 for(let i=0;i<50;i++){try{await fetch(base+'/index.php');break;}catch{await new Promise(r=>setTimeout(r,100));}}
 const anon=client(); assert.equal((await anon('/upload.php',form({upload_check:'1',filename:'a.txt'}))).status,401);pass('unauthenticated check rejected');
 const c=client();const csrf=JSON.parse((await c('/signin.php')).text).csrf;
 const check=(filename,dir='fixture/custom',extra={})=>c('/upload.php',form({_csrf:csrf,upload_check:'1',filename,dir,...extra}));
 const upload=(filename,content,dir='fixture/custom',extra={})=>{const d=form({_csrf:csrf,dir,...extra});d.append('file',new Blob([content]),filename);return c('/upload.php',d);};
 assert.equal((await check('new.txt','fixture/custom',{_csrf:'wrong'})).status,403);pass('precheck CSRF enforced');
 assert.equal((await upload('new.txt','x','fixture/custom',{_csrf:'wrong'})).status,403);pass('actual upload CSRF enforced');
 assert.deepEqual(JSON.parse((await check('new.txt')).text),{ok:true}); assert.equal(fs.existsSync(path.join(sampleDir,'new.txt')),false);pass('available filename check does not upload data');
 assert.equal((await upload('new.txt','original','fixture/custom',{password:'Synthetic-only-123'})).status,200);
 assert.equal(fs.readFileSync(path.join(sampleDir,'new.txt'),'utf8'),'original'); pass('new file with download password succeeds');
 const ownersBefore=fs.readFileSync(path.join(publicDir,'.file_owners.json'),'utf8'),passwordsBefore=fs.readFileSync(path.join(publicDir,'.passwords.json'),'utf8');
 const duplicate=await check('new.txt');assert.equal(duplicate.status,409);assert.match(duplicate.text,/已存在同名/);pass('duplicate precheck explicitly returns 409');
 assert.equal((await upload('new.txt','replacement','fixture/custom',{password:'different-synthetic'})).status,409);
 assert.equal(fs.readFileSync(path.join(sampleDir,'new.txt'),'utf8'),'original');
 assert.equal(fs.readFileSync(path.join(publicDir,'.file_owners.json'),'utf8'),ownersBefore);assert.equal(fs.readFileSync(path.join(publicDir,'.passwords.json'),'utf8'),passwordsBefore);pass('bypassing precheck cannot overwrite file/owner/password');
 assert.equal(fs.readdirSync(sampleDir).filter(n=>n.startsWith('new')).length,1);pass('no silent timestamp-renamed copies');
 assert.equal((await upload('new.txt','second','fixture/second')).status,200);pass('same name in another directory is allowed');
 assert.equal((await upload('auto.txt','auto','fixture')).status,200);assert.equal(fs.existsSync(path.join(publicDir,'data/fixture/文档/auto.txt')),true);pass('root automatic categorization retained');
 const auto=await check('auto.txt','fixture/全部文件');assert.equal(auto.status,409);assert.match(auto.text,/fixture\/文档/);pass('duplicate check uses actual category destination');
 fs.mkdirSync(path.join(sampleDir,'directory.txt'));assert.equal((await upload('directory.txt','x')).status,409);pass('same-name directory also protected');
 assert.equal((await upload('clean@name.txt','one')).status,200);assert.equal((await check('clean#name.txt')).status,409);pass('normalized filename collision protected');
 for(const n of ['evil.php','noextension'])assert.equal((await check(n)).status,400);pass('blocked extension/no extension protection retained');
 assert.equal((await check('a.txt','fixture/../other')).status,400);pass('traversal path rejected');
 assert.equal((await check('a.txt','fixture\\other')).status,400);pass('backslash traversal rejected');
 assert.equal((await upload('cross.bin','own-only','other/custom')).status,200);assert.equal(fs.existsSync(path.join(publicDir,'data/other/custom/cross.bin')),false);assert.equal(fs.existsSync(path.join(publicDir,'data/fixture/cross.bin')),true);pass('ordinary-user target isolation retained');
 const a=client();const ac=JSON.parse((await a('/signin.php?role=admin')).text).csrf;const ad=form({_csrf:ac,dir:'other/custom'});ad.append('file',new Blob(['admin-fixture']),'admin.txt');assert.equal((await a('/upload.php',ad)).status,200);pass('administrator upload target retained');
 assert.equal(fs.readdirSync(sampleDir).filter(n=>n.startsWith('.upload-')).length,0);pass('completed uploads leave no staging files');
 const html=await c('/index.php?dir=fixture/custom&sort=size_desc&per_page=30');assert.equal(html.status,200);assert.match(html.text,/id="fileSortSelect"/);assert.match(html.text,/value="size_desc" selected/);assert.match(html.text,/assets\/files.js\?v=20261023-upload-sort/);assert.match(html.text,/单个文件最大 100MB/);pass('real view renders sort selector, limit hint and updated script');
 const searched=await c('/index.php?dir=fixture/custom&q=sample&sort=mtime_asc&per_page=50');assert.equal(searched.status,200);assert.match(searched.text,/搜索结果/);assert.match(searched.text,/value="mtime_asc" selected/);pass('real search integrates with selected sort');
 assert.doesNotMatch(logs,/Fatal error|Warning:|Parse error|Deprecated:/);pass('no PHP runtime warnings in exercised requests');
 console.log(`PASS: ${checks} integration checks. Synthetic runtime: ${runtime}`);
 if(process.argv.includes('--preview')) {console.log(`PREVIEW ${base}/signin.php (synthetic identities only)`); await new Promise(()=>{});}
} finally {server.kill();}
