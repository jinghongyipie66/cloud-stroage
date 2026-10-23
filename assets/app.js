/**
 * 通用前端能力：CSRF 表单、主题弹窗、通用隐藏字段。
 * 从 index.php 内联脚本拆分，保持全局函数名不变以兼容现有 onclick 调用。
 */

const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').content;

function newCsrfFormData() {
  const data = new FormData();
  data.append('_csrf', CSRF_TOKEN);
  return data;
}

function createPostForm() {
  const form = document.createElement('form');
  form.method = 'POST';
  form.appendChild(hiddenInput('_csrf', CSRF_TOKEN));
  return form;
}

let appDialogResolver = null;

function resolveAppDialog(result) {
  const modal = document.getElementById('appDialogModal');
  modal.classList.add('hidden');
  const resolver = appDialogResolver;
  appDialogResolver = null;
  if (resolver) resolver(Boolean(result));
}

function showAppDialog(options = {}) {
  if (appDialogResolver) resolveAppDialog(false);

  const type = options.type || 'info';
  const styles = {
    info: {
      icon: 'ℹ',
      title: '提示',
      subtitle: '瑞锦云存储消息',
      header: 'dialog-theme-info',
      button: 'dialog-button-info'
    },
    success: {
      icon: '✓',
      title: '操作成功',
      subtitle: '本次操作已安全完成',
      header: 'dialog-theme-success',
      button: 'dialog-button-success'
    },
    warning: {
      icon: '!',
      title: '请注意',
      subtitle: '请确认后再继续操作',
      header: 'dialog-theme-warning',
      button: 'dialog-button-warning'
    },
    danger: {
      icon: '🗑',
      title: '危险操作确认',
      subtitle: '该操作可能无法撤回',
      header: 'dialog-theme-danger',
      button: 'dialog-button-danger'
    },
    error: {
      icon: '×',
      title: '操作失败',
      subtitle: '请检查信息后重试',
      header: 'dialog-theme-danger',
      button: 'dialog-button-danger'
    }
  };
  const style = styles[type] || styles.info;
  const modal = document.getElementById('appDialogModal');
  const header = document.getElementById('appDialogHeader');
  const confirmButton = document.getElementById('appDialogConfirmBtn');
  const cancelButton = document.getElementById('appDialogCancelBtn');
  const notice = document.getElementById('appDialogNotice');

  document.getElementById('appDialogIcon').textContent = style.icon;
  document.getElementById('appDialogTitle').textContent = options.title || style.title;
  document.getElementById('appDialogSubtitle').textContent = options.subtitle || style.subtitle;
  document.getElementById('appDialogMessage').textContent = String(options.message || '');
  header.className = 'relative overflow-hidden px-6 py-5 text-white ' + style.header;
  confirmButton.className = 'flex-1 rounded-xl px-4 py-3 text-sm font-semibold text-white shadow-lg transition-all ' + style.button;
  confirmButton.textContent = options.confirmText || '确定';
  cancelButton.textContent = options.cancelText || '取消';
  cancelButton.classList.toggle('hidden', !options.showCancel);

  if (options.notice) {
    notice.textContent = options.notice;
    notice.className = 'mt-4 rounded-xl border px-4 py-3 text-xs leading-5 ' + (type === 'danger' || type === 'error'
      ? 'border-rose-200 bg-rose-50 text-rose-700'
      : 'border-amber-200 bg-amber-50 text-amber-700');
  } else {
    notice.textContent = '';
    notice.classList.add('hidden');
  }

  modal.classList.remove('hidden');
  setTimeout(() => (type === 'danger' && options.showCancel ? cancelButton : confirmButton).focus(), 0);
  return new Promise((resolve) => { appDialogResolver = resolve; });
}

function showAppAlert(message, options = {}) {
  return showAppDialog({...options, message, showCancel: false});
}

function showAppConfirm(message, options = {}) {
  return showAppDialog({...options, message, showCancel: true});
}

document.addEventListener('keydown', (event) => {
  const modal = document.getElementById('appDialogModal');
  if (!modal || modal.classList.contains('hidden')) return;
  if (event.key === 'Escape') resolveAppDialog(false);
});

function hiddenInput(name, value) {
  const input = document.createElement('input');
  input.type = 'hidden';
  input.name = name;
  input.value = value;
  return input;
}
