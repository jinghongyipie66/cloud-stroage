(function () {
  'use strict';

  function waitLabel(seconds) {
    if (seconds <= 60) return seconds + '秒';
    var minutes = Math.ceil(seconds / 60);
    if (minutes < 60) return minutes + '分钟';
    return Math.floor(minutes / 60) + '小时' + (minutes % 60 ? (minutes % 60) + '分钟' : '');
  }

  var buttons = document.querySelectorAll('[data-resend-button]');
  buttons.forEach(function (button) {
    var remaining = parseInt(button.getAttribute('data-resend-seconds') || '0', 10);
    var readyLabel = button.getAttribute('data-ready-label') || '重新发送验证码';
    if (!Number.isFinite(remaining) || remaining <= 0) return;

    var deadline = Date.now() + remaining * 1000;
    var render = function () {
      var seconds = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
      if (seconds <= 0) {
        button.disabled = false;
        button.removeAttribute('aria-disabled');
        button.textContent = readyLabel;
        return false;
      }
      button.disabled = true;
      button.setAttribute('aria-disabled', 'true');
      button.textContent = readyLabel + '（' + waitLabel(seconds) + '）';
      return true;
    };

    render();
    var timer = window.setInterval(function () {
      if (!render()) window.clearInterval(timer);
    }, 250);
  });
}());
