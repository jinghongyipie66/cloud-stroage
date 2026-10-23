/**
 * 账号功能：个人改密、用户创建、管理员改密与删除用户。
 * 从 index.php 内联脚本拆分，保持全局函数名不变以兼容现有 onclick 调用。
 */

function filterUserRows(query) {
  const keyword = String(query || '').trim().toLowerCase();
  document.querySelectorAll('[data-user-row]').forEach((row) => {
    row.classList.toggle('hidden', keyword !== '' && !row.dataset.username.includes(keyword));
  });
}


function toggleOwnPasswordVisibility(inputId, button) {
  const input = document.getElementById(inputId);
  const shouldShow = input.type === 'password';
  input.type = shouldShow ? 'text' : 'password';
  button.textContent = shouldShow ? '隐藏' : '显示';
}

function updateOwnPasswordStrength() {
  const password = document.getElementById('ownNewPassword').value;
  const confirmPassword = document.getElementById('ownNewPasswordConfirm').value;
  const strengthBar = document.getElementById('ownPasswordStrengthBar');
  const strengthText = document.getElementById('ownPasswordStrengthText');
  const lengthHint = document.getElementById('ownPasswordLengthHint');
  const matchHint = document.getElementById('ownPasswordMatchHint');

  let score = 0;
  if (password.length >= 6) score++;
  if (password.length >= 10) score++;
  if (/[A-Za-z]/.test(password) && /\d/.test(password)) score++;
  if (/[^A-Za-z0-9]/.test(password)) score++;

  const strengthMap = [
    ['0%', '未输入', 'bg-slate-300', 'text-slate-400'],
    ['25%', '较弱', 'bg-rose-500', 'text-rose-600'],
    ['50%', '一般', 'bg-amber-500', 'text-amber-600'],
    ['75%', '良好', 'bg-blue-500', 'text-blue-600'],
    ['100%', '较强', 'bg-emerald-500', 'text-emerald-600']
  ];
  const currentStrength = password ? strengthMap[score] : strengthMap[0];
  strengthBar.style.width = currentStrength[0];
  strengthBar.className = 'h-full rounded-full transition-all duration-300 ' + currentStrength[2];
  strengthText.textContent = currentStrength[1];
  strengthText.className = 'text-[11px] w-12 text-right ' + currentStrength[3];

  const lengthValid = password.length >= 6;
  lengthHint.textContent = (lengthValid ? '✓' : '○') + ' 至少 6 位';
  lengthHint.className = 'rounded-xl border px-3 py-2 ' + (lengthValid ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : 'bg-slate-50 border-slate-100 text-slate-500');

  const matchValid = confirmPassword !== '' && password === confirmPassword;
  matchHint.textContent = (matchValid ? '✓' : '○') + ' 两次输入一致';
  matchHint.className = 'rounded-xl border px-3 py-2 ' + (matchValid ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : 'bg-slate-50 border-slate-100 text-slate-500');
}

function openOwnPasswordModal() {
  ['ownCurrentPassword', 'ownNewPassword', 'ownNewPasswordConfirm'].forEach((id) => {
    const input = document.getElementById(id);
    input.value = '';
    input.type = 'password';
  });
  document.querySelectorAll('#ownPasswordModal button[onclick^="toggleOwnPasswordVisibility"]').forEach((button) => button.textContent = '显示');
  document.getElementById('ownPasswordErrorMsg').classList.add('hidden');
  updateOwnPasswordStrength();
  document.getElementById('ownPasswordModal').classList.remove('hidden');
  document.getElementById('ownCurrentPassword').focus();
}

function closeOwnPasswordModal() {
  if (ownPasswordSubmitting) return;
  document.getElementById('ownPasswordModal').classList.add('hidden');
}

let ownPasswordSubmitting = false;
async function submitOwnPassword(event) {
  event.preventDefault();
  if (ownPasswordSubmitting) return;

  const currentPassword = document.getElementById('ownCurrentPassword').value;
  const newPassword = document.getElementById('ownNewPassword').value;
  const newPasswordConfirm = document.getElementById('ownNewPasswordConfirm').value;
  const errorBox = document.getElementById('ownPasswordErrorMsg');
  const submitButton = document.getElementById('ownPasswordSubmitBtn');

  errorBox.classList.add('hidden');
  if (!currentPassword || !newPassword || !newPasswordConfirm) {
    errorBox.textContent = '请完整填写当前密码和新密码';
    errorBox.classList.remove('hidden');
    return;
  }
  if (newPassword.length < 6) {
    errorBox.textContent = '新密码长度至少为 6 位';
    errorBox.classList.remove('hidden');
    return;
  }
  if (newPassword !== newPasswordConfirm) {
    errorBox.textContent = '两次输入的新密码不一致';
    errorBox.classList.remove('hidden');
    return;
  }

  ownPasswordSubmitting = true;
  submitButton.disabled = true;
  submitButton.textContent = '正在安全更新...';
  const data = newCsrfFormData();
  data.append('account_action', 'change_own_password');
  data.append('current_password', currentPassword);
  data.append('new_password', newPassword);
  data.append('new_password_confirm', newPasswordConfirm);

  try {
    const response = await fetch('index.php', {method: 'POST', body: data, credentials: 'same-origin'});
    const result = (await response.text()).trim();
    if (result === 'Success') {
      await showAppAlert('密码修改成功，页面将刷新安全凭证。', {type: 'success', title: '密码修改成功'});
      window.location.reload();
      return;
    }
    errorBox.textContent = result.replace(/^Error:\s*/, '') || '修改失败，请稍后重试';
    errorBox.classList.remove('hidden');
  } catch (error) {
    errorBox.textContent = '请求失败，请检查网络连接';
    errorBox.classList.remove('hidden');
  } finally {
    ownPasswordSubmitting = false;
    submitButton.disabled = false;
    submitButton.textContent = '确认修改';
  }
}
async function createUser() {
  const username = document.getElementById('newUsername').value.trim();
  const password = document.getElementById('newPassword').value.trim();
  const role = document.getElementById('newRole').value;
  if (!username || !password) {
    await showAppAlert('请填写账号和密码', {type: 'warning', title: '信息不完整'});
    return;
  }

  const fd = newCsrfFormData();
  fd.append('admin_action', 'create_user');
  fd.append('username', username);
  fd.append('password', password);
  fd.append('role', role);
  try {
    const response = await fetch('index.php', { method: 'POST', body: fd });
    const result = (await response.text()).trim();
    if (result === 'Success') {
      await showAppAlert('用户创建成功', {type: 'success'});
      window.location.reload();
    } else if (result === 'User exists') {
      await showAppAlert('该账号已存在，请更换账号名', {type: 'warning', title: '账号已存在'});
    } else {
      await showAppAlert(result.replace(/^Error:\s*/, '') || '创建用户失败', {type: 'error'});
    }
  } catch (error) {
    await showAppAlert('请求失败，请检查网络连接', {type: 'error', title: '网络请求失败'});
  }
}
// 修改用户密码功能
let changePasswordUsername = '';

function openChangePasswordModal(username) {
  changePasswordUsername = username;
  document.getElementById('changePasswordUsername').textContent = '账号：' + username;
  document.getElementById('changePasswordInput').value = '';
  document.getElementById('changePasswordErrorMsg').classList.add('hidden');
  document.getElementById('changePasswordModal').classList.remove('hidden');
}

function closeChangePasswordModal() {
  document.getElementById('changePasswordModal').classList.add('hidden');
}

async function submitChangePassword() {
  const newPassword = document.getElementById('changePasswordInput').value.trim();
  const errorBox = document.getElementById('changePasswordErrorMsg');
  if (!newPassword) {
    errorBox.textContent = '请输入新密码';
    errorBox.classList.remove('hidden');
    return;
  }
  const fd = newCsrfFormData();
  fd.append('admin_action', 'change_password');
  fd.append('username', changePasswordUsername);
  fd.append('password', newPassword);
  try {
    const response = await fetch('index.php', { method: 'POST', body: fd });
    const result = await response.text();
    if (result === 'Success') {
      closeChangePasswordModal();
      const toast = document.getElementById('toast');
      const toastInner = toast.querySelector('div');
      const originalText = toastInner.textContent;
      toastInner.textContent = '✅ 密码修改成功';
      toast.classList.remove('hidden');
      setTimeout(() => {
        toast.classList.add('hidden');
        toastInner.textContent = originalText;
      }, 2000);
    } else {
      errorBox.textContent = result;
      errorBox.classList.remove('hidden');
    }
  } catch (e) {
    errorBox.textContent = '请求失败，请检查网络连接';
    errorBox.classList.remove('hidden');
  }
}

let pendingDeleteUsername = '';

function openDeleteUserModal(username) {
  pendingDeleteUsername = username;
  document.getElementById('deleteUserName').textContent = username;
  document.getElementById('deleteUserModal').classList.remove('hidden');
}

function closeDeleteUserModal() {
  document.getElementById('deleteUserModal').classList.add('hidden');
  pendingDeleteUsername = '';
}

async function submitDeleteUser(deleteData) {
  if (!pendingDeleteUsername) return;
  if (deleteData) {
    const confirmed = await showAppConfirm('即将永久删除账号「' + pendingDeleteUsername + '」及其名下的所有目录和文件。', {
      type: 'danger',
      title: '最后确认',
      confirmText: '永久删除全部数据',
      notice: '包括系统分类目录在内的全部内容都会被删除，且无法恢复。'
    });
    if (!confirmed) return;
  }

  const fd = newCsrfFormData();
  fd.append('admin_action', 'delete_user');
  fd.append('username', pendingDeleteUsername);
  fd.append('delete_data', deleteData ? '1' : '0');
  try {
    const response = await fetch('index.php', { method: 'POST', body: fd });
    const result = (await response.text()).trim();
    if (result === 'Success') {
      await showAppAlert('用户账号及其全部文件已删除', {type: 'success', title: '删除完成'});
      window.location.reload();
    } else if (result.startsWith('SuccessRetained:')) {
      const archiveName = result.substring('SuccessRetained:'.length);
      await showAppAlert('用户账号已删除，文件已归档保留到：\nupload_backup_retained_users/' + archiveName, {
        type: 'success',
        title: '账号已删除，文件已保留'
      });
      window.location.reload();
    } else if (result.startsWith('SuccessWithWarning')) {
      await showAppAlert(result.replace('SuccessWithWarning', '用户已删除'), {type: 'warning', title: '删除完成但需检查'});
      window.location.reload();
    } else {
      await showAppAlert(result.replace(/^Error:\s*/, '') || '删除用户失败', {type: 'error'});
    }
  } catch (error) {
    await showAppAlert('请求失败，请检查网络连接', {type: 'error', title: '网络请求失败'});
  }
}
