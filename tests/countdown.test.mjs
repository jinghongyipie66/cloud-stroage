// Deterministic test of the actual browser asset; no browser, network or production data.
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const source = fs.readFileSync(new URL('../assets/account-email.js', import.meta.url), 'utf8');
function fixture(seconds) {
  let now = 100000;
  let tick;
  let cleared = false;
  const attrs = { 'data-resend-seconds': String(seconds), 'data-ready-label': '重新发送验证码' };
  const button = { disabled: false, textContent: '发送验证码', getAttribute: n => attrs[n], setAttribute: (n, v) => attrs[n] = v, removeAttribute: n => delete attrs[n] };
  vm.runInNewContext(source, {
    document: { querySelectorAll: () => [button] },
    Date: { now: () => now },
    window: { setInterval: fn => { tick = fn; return 1; }, clearInterval: () => { cleared = true; } },
  });
  return { button, attrs, advance(ms) { now += ms; tick?.(); }, cleared: () => cleared };
}
const state = fixture(60);
assert.equal(state.button.disabled, true); assert.equal(state.button.textContent, '重新发送验证码（60秒）');
console.log('PASS: starts at 60 seconds and disables the button');
state.advance(1000); assert.equal(state.button.textContent, '重新发送验证码（59秒）');
console.log('PASS: countdown decreases with elapsed time');
state.advance(58000); assert.equal(state.button.disabled, true); assert.equal(state.button.textContent, '重新发送验证码（1秒）');
state.advance(1000); assert.equal(state.button.disabled, false); assert.equal(state.button.textContent, '重新发送验证码'); assert.equal(state.attrs['aria-disabled'], undefined); assert.equal(state.cleared(), true);
console.log('PASS: button becomes available at zero and timer stops');
const background = fixture(45); background.advance(120000); assert.equal(background.button.disabled, false);
console.log('PASS: suspended/background tab catches up to elapsed time');
const longWait = fixture(3661); assert.equal(longWait.button.textContent, '重新发送验证码（1小时2分钟）');
longWait.advance(61000); assert.equal(longWait.button.textContent, '重新发送验证码（1小时）');
longWait.advance(3600000); assert.equal(longWait.button.disabled, false);
console.log('PASS: long limit wait uses compact hour/minute labels and unlocks at deadline');
const fresh = fixture(0); assert.equal(fresh.button.disabled, false); assert.equal(fresh.button.textContent, '发送验证码');
console.log('PASS: first-time send button stays available');
