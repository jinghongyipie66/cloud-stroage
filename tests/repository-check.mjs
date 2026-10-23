// Read-only source/export checks; never descends into runtime or deployment data.
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import assert from 'node:assert/strict';
import {fileURLToPath} from 'node:url';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const files=[];
function walk(dir) {
 for(const e of fs.readdirSync(dir,{withFileTypes:true})) {
  if(e.name==='.git'||e.name.startsWith('.runtime-')||e.name.startsWith('.preflight-')) continue;
  assert.ok(!e.isSymbolicLink(),'Symlinks cannot be exported');
  const full=path.join(dir,e.name), rel=path.relative(root,full).replaceAll('\\','/');
  assert.ok(!/(^|\/)(data|backups|sessions|node_modules|db_config\.php|mail\.php|\.env(?:\..*)?|\.(?:passwords|shares|file_owners|users)\.json)(\/|$)/i.test(rel),'Forbidden export path: '+rel);
  assert.ok(!/\.(?:pem|key|p12|pfx|crt|cer|sqlite|sqlite3|db|zip|bak|log)$/i.test(rel),'Forbidden export extension: '+rel);
  if(e.isDirectory()) walk(full); else files.push(rel);
 }
}
walk(root);
const manifest=JSON.parse(fs.readFileSync(path.join(root,'docs/source-manifest.json'),'utf8'));
const portedTests=new Set(['tests/upload-sort/frontend.test.mjs','tests/upload-sort/integration.test.mjs','tests/upload-sort/sort-fixture.php','tests/upload-sort/publish-fixture.php']);
let preserved=0;
for(const f of manifest) {
 assert.ok(files.includes(f.path),'Source missing: '+f.path);
 if(portedTests.has(f.path)) continue;
 const sha=crypto.createHash('sha256').update(fs.readFileSync(path.join(root,f.path))).digest('hex');
 assert.equal(sha,f.original_sha256,'Unexpected modification: '+f.path); preserved++;
}
const findings=[];
for(const rel of files) {
 if(!/\.(?:php|js|mjs|css|md|json|sql)$/.test(rel)) continue;
 const text=fs.readFileSync(path.join(root,rel),'utf8');
 const patterns=[/\b(?:ghp|gho|ghu|ghs|ghr)_[A-Za-z0-9]{30,}\b/,/\bgithub_pat_[A-Za-z0-9_]{30,}\b/,/\bAKIA[0-9A-Z]{16}\b/,/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----\s+[A-Za-z0-9+/=]{40}/];
 if(patterns.some(p=>p.test(text))) findings.push(rel+': possible private key/token (value suppressed)');
 if(!rel.startsWith('tests/')&&!rel.startsWith('vendor/')) {
  if(/\$db_(?:pass|password)\s*=\s*(['"])[^'"]+\1/.test(text)||/\$[A-Za-z_]*(?:password|passwd|secret|token|api_key)[A-Za-z_]*\s*=\s*(['"])[^'"]+\1/i.test(text)||/['"](?:smtp_password|app_key)['"]\s*=>\s*(['"])[^'"]+\1/.test(text)) findings.push(rel+': non-empty literal credential (value suppressed)');
 }
 if(!rel.endsWith('.php')||rel.startsWith('tests/')||rel.startsWith('vendor/')||rel==='header.php') continue;
 const re=/(?:require|include)(?:_once)?\s+(?:\(\s*)?(__DIR__|CLOUD_STORAGE_ROOT|dirname\(__DIR__(?:,\s*(\d+))?\))\s*\.\s*(['"])([^'"]+)\3/g;
 for(const m of text.matchAll(re)) {
  let base=m[1]==='CLOUD_STORAGE_ROOT'?root:path.dirname(path.join(root,rel));
  if(m[1].startsWith('dirname')) for(let i=0;i<Number(m[2]||1);i++) base=path.dirname(base);
  const target=path.resolve(base,'.'+m[4]);
  if(path.relative(root,target).replaceAll('\\','/')==='db_config.php') continue;
  if(!fs.existsSync(target)) findings.push(rel+': missing static include '+m[4]);
 }
}
assert.deepEqual(findings,[],'Export review failed; no credential values are printed');
assert.ok(fs.readFileSync(path.join(root,'.gitignore'),'utf8').includes('db_config.php'));
assert.ok(fs.readFileSync(path.join(root,'config/mail.example.php'),'utf8').includes("'smtp_password' => ''"));
console.log(`PASS: ${files.length} export files, ${preserved} preserved source hashes, credential-path/literal checks and static includes.`);
console.log('PASS: configuration examples only; no production data, credentials, generated runtimes or archives in export.');
if(process.argv.includes('--list')) console.log(JSON.stringify(files));
