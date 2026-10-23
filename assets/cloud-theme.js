/* Appearance only. No authentication, network, form submission, or database changes. */
(function () {
  'use strict';
  var root = document.documentElement;
  if (root.hasAttribute('data-cloud-theme-initialized')) return;
  root.setAttribute('data-cloud-theme-initialized', 'true');
  var scope = root.getAttribute('data-cloud-theme-scope') || '';
  var locked = root.getAttribute('data-cloud-theme-lock') === 'azure' || !/^[a-f0-9]{64}$/.test(scope);
  var key = locked ? null : 'ruijin-cloud.theme.v1.' + scope;
  var current = 'azure';

  function normalize(value) { return value === 'graphite' ? 'graphite' : 'azure'; }
  function read(fallback) {
    if (!key) return 'azure';
    try {
      var saved = window.localStorage.getItem(key);
      return saved === null ? fallback : normalize(saved);
    } catch (error) { return fallback; }
  }
  function apply(value) {
    current = locked ? 'azure' : normalize(value);
    root.setAttribute('data-cloud-theme', current);
    document.querySelectorAll('[data-cloud-theme-picker]').forEach(function (picker) {
      picker.value = current;
      picker.disabled = locked;
    });
  }
  function announce(message) {
    document.querySelectorAll('[data-cloud-theme-status]').forEach(function (status) {
      status.textContent = message;
    });
  }
  function bind() {
    document.querySelectorAll('[data-cloud-theme-picker]').forEach(function (picker) {
      if (picker.hasAttribute('data-cloud-theme-bound')) return;
      picker.setAttribute('data-cloud-theme-bound', 'true');
      picker.addEventListener('change', function () {
        if (locked) { apply('azure'); return; }
        apply(picker.value);
        var saved = false;
        try { window.localStorage.setItem(key, current); saved = true; } catch (error) { /* Usable without storage. */ }
        announce('已切换为' + (current === 'graphite' ? '深色石墨' : '清爽云蓝') + (saved ? '，已记住当前账号在此浏览器的选择。' : '。浏览器未允许保存偏好，本次页面内有效。'));
      });
    });
    apply(current);
  }

  // Runs in the head before the body is painted; only a whitelisted theme is applied.
  apply(read('azure'));
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bind, { once: true });
  else bind();
  window.addEventListener('pageshow', function () { apply(read(current)); });
  window.addEventListener('storage', function (event) {
    if (key && event.key === key) { apply(normalize(event.newValue)); announce('已同步此账号的外观设置。'); }
  });
}());
