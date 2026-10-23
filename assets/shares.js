/**
 * 分享功能：创建、取消、复制链接与列表筛选。
 * 从 index.php 内联脚本拆分，保持全局函数名不变以兼容现有 onclick 调用。
 */

// 分享功能
function openShareModal(path) {
  document.getElementById('modalFilePath').value = path;
  document.getElementById('modalFileName').textContent = path.split('/').pop();
  document.getElementById('shareModal').classList.remove('hidden');
}

function closeShareModal() {
  document.getElementById('shareModal').classList.add('hidden');
}

async function createShare() {
  const file = document.getElementById('modalFilePath').value;
  const expiry = document.getElementById('expirySelect').value;

  const fd = newCsrfFormData();
  fd.append('share_action', 'create');
  fd.append('share_file', file);
  fd.append('share_expiry', expiry);

  try {
    const resp = await fetch('index.php', { method: 'POST', body: fd });
    const token = (await resp.text()).trim();

    if (resp.ok && /^[a-f0-9]{32}$/.test(token)) {
      const url = window.location.origin + window.location.pathname.replace('index.php', 'share.php') + '?id=' + token;
      navigator.clipboard.writeText(url).then(() => {
        closeShareModal();
        const toast = document.getElementById('toast');
        toast.classList.remove('hidden');
        setTimeout(() => {
          toast.classList.add('hidden');
          window.location.reload();
        }, 2000);
      });
    } else {
      const message = token.startsWith('Error:') ? token.slice(6).trim() : '生成失败，请检查登录状态';
      await showAppAlert(message, {type: 'error', title: '生成分享失败'});
    }
  } catch (error) {
    await showAppAlert('请求失败，请检查网络连接', {type: 'error', title: '网络请求失败'});
  }
}

async function deleteShare(token) {
  const confirmed = await showAppConfirm('确定要取消此分享吗？取消后原分享链接将立即失效。', {
    type: 'warning',
    title: '取消分享',
    confirmText: '确认取消'
  });
  if (!confirmed) return;

  const fd = newCsrfFormData();
  fd.append('share_action', 'delete');
  fd.append('share_token', token);
  try {
    const response = await fetch('index.php', { method: 'POST', body: fd });
    const result = (await response.text()).trim();
    const normalizedResult = result.toLowerCase();
    if (!response.ok || !['ok', 'success'].includes(normalizedResult)) {
      await showAppAlert(result || '取消分享失败', {type: 'error'});
      return;
    }
    window.location.reload();
  } catch (error) {
    await showAppAlert('请求失败，请检查网络连接', {type: 'error', title: '网络请求失败'});
  }
}

function copyShareLink(token) {
  const url = window.location.origin + window.location.pathname.replace('index.php', 'share.php') + '?id=' + token;
  navigator.clipboard.writeText(url).then(() => {
    const toast = document.getElementById('toast');
    toast.classList.remove('hidden');
    setTimeout(() => toast.classList.add('hidden'), 2000);
  });
}

// 预览功能

function activateShareSearch(input) {
  if (!input) return;
  input.readOnly = false;
  if (input.dataset.userActivated !== '1') {
    input.dataset.userActivated = '1';
    input.value = '';
    filterShareRows('');
  }
}

function clearUnexpectedShareSearchAutofill() {
  const input = document.getElementById('shareFileSearch');
  if (!input || input.dataset.userActivated === '1') return;
  input.value = '';
  filterShareRows('');
}

function filterShareRows(query) {
  const keyword = String(query || '').trim().toLowerCase();
  document.querySelectorAll('[data-share-row]').forEach((row) => {
    row.classList.toggle('hidden', keyword !== '' && !row.dataset.shareName.includes(keyword));
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => {
    clearUnexpectedShareSearchAutofill();
    setTimeout(clearUnexpectedShareSearchAutofill, 250);
    setTimeout(clearUnexpectedShareSearchAutofill, 1000);
  });
} else {
  clearUnexpectedShareSearchAutofill();
  setTimeout(clearUnexpectedShareSearchAutofill, 250);
  setTimeout(clearUnexpectedShareSearchAutofill, 1000);
}




