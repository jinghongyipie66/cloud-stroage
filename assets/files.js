/**
 * 文件功能：上传、移动、加密、批量操作、删除与预览。
 * 从 index.php 内联脚本拆分，保持全局函数名不变以兼容现有 onclick 调用。
 */

// 密码输入框显示/隐藏
// 首页专用控件仅在首页存在。用户管理/分享管理页面不能直接绑定空元素，
// 否则脚本会在这里中断，导致后续管理功能的状态变量无法初始化。
const enablePasswordToggle = document.getElementById('enablePassword');
if (enablePasswordToggle) enablePasswordToggle.addEventListener('change', function() {
  const input = document.getElementById('passwordInput');
  input.classList.toggle('hidden', !this.checked);
  if(this.checked) input.focus();
});

// 禁用的文件后缀列表（与 upload.php 后端保持一致，只挡脚本/可执行文件，不挡压缩包等正常格式）
const prohibitedExts = ['php', 'php5', 'php4', 'php3', 'phtml', 'sh', 'pl', 'py', 'exe', 'jsp', 'asp', 'aspx', 'dll', 'cgi', 'bat'];

function updateFileNameHint() {
  const fileInput = document.getElementById('file');
  const hint = document.getElementById('fileNameHint');
  const errorDiv = document.getElementById('error-message');
  
  errorDiv.classList.add('hidden');
  
  if (fileInput.files.length > 0) {
    const fileName = fileInput.files[0].name;
    const fileExt = fileName.split('.').pop().toLowerCase();
    
    if (prohibitedExts.includes(fileExt)) {
      errorDiv.textContent = '❌ 禁止上传 .' + fileExt + ' 文件！';
      errorDiv.classList.remove('hidden');
      hint.textContent = '点击或拖拽文件到这里上传';
      hint.classList.remove('text-blue-600');
      fileInput.value = '';
    } else {
      hint.textContent = '已选择: ' + fileName;
      hint.classList.add('text-blue-600');
    }
  }
}

// AJAX 上传处理
// AJAX 上传表单只在首页存在。
const uploadForm = document.getElementById('uploadForm');
if (uploadForm) uploadForm.onsubmit = async function(e) {
  e.preventDefault();
  const btn = document.getElementById('submitBtn');
  const errorDiv = document.getElementById('error-message');
  const successDiv = document.getElementById('success-message');
  const progressContainer = document.getElementById('progressContainer');
  const progressBar = document.getElementById('progressBar');
  const progressPercent = document.getElementById('progressPercent');
  const fileInput = document.getElementById('file');
  
  errorDiv.classList.add('hidden');
  successDiv.classList.add('hidden');
  
  // 二次校验：确保文件后缀合法
  if (fileInput.files.length === 0) {
    errorDiv.textContent = '❌ 请先选择一个文件';
    errorDiv.classList.remove('hidden');
    return;
  }
  
  const fileName = fileInput.files[0].name;
  const fileExt = fileName.split('.').pop().toLowerCase();
  if (prohibitedExts.includes(fileExt)) {
    errorDiv.textContent = '❌ 禁止上传 .' + fileExt + ' 文件！';
    errorDiv.classList.remove('hidden');
    return;
  }
  
  if (fileInput.files[0].size > 100 * 1024 * 1024) {
    errorDiv.textContent = '❌ 超出100MB大小上限。';
    errorDiv.classList.remove('hidden');
    return;
  }
  if (btn.disabled) return;
  btn.disabled = true;
  btn.textContent = '⏳ 正在检查同名文件...';
  progressContainer.classList.add('hidden');
  progressBar.style.width = '0%';
  progressPercent.textContent = '0%';

  const formData = new FormData(this);
  formData.append('dir', document.getElementById('currentDirParam').value);

  function uploadFailure(status, response) {
    btn.disabled = false;
    btn.textContent = '🚀 立即上传';
    progressContainer.classList.add('hidden');
    // HTML error/login pages must not be shown as raw response text.
    let message = (response || '').trim();
    if (status === 401) message = '登录已失效，请重新登录后上传。';
    else if (status === 413) message = '文件超过服务器允许的上传大小，请缩小文件后重试。';
    else if (!message || message.startsWith('<')) message = '上传失败，请刷新页面后重试。';
    errorDiv.textContent = '❌ ' + message;
    errorDiv.classList.remove('hidden');
    if (status === 409 && typeof showAppAlert === 'function') {
      showAppAlert(message, {type: 'warning', title: '文件已存在', subtitle: '未覆盖原文件'});
    }
  }

  // Only filename/target/CSRF are sent here; do not transmit the file twice.
  const checkData = new FormData();
  checkData.append('_csrf', formData.get('_csrf') || '');
  checkData.append('upload_check', '1');
  checkData.append('filename', fileName);
  checkData.append('dir', formData.get('dir'));
  const checkController = new AbortController();
  const checkTimeout = setTimeout(() => checkController.abort(), 15000);
  try {
    const response = await fetch('upload.php', {method: 'POST', body: checkData, signal: checkController.signal});
    const text = await response.text();
    if (!response.ok) { uploadFailure(response.status, text); return; }
    let result;
    try { result = JSON.parse(text); } catch (_) { result = null; }
    if (!result || result.ok !== true) { uploadFailure(0, '上传检查未完成，请刷新页面；若仍出现，请确认上传功能更新文件已全部部署。'); return; }
  } catch (error) {
    uploadFailure(0, error.name === 'AbortError' ? '检查同名文件超时，请稍后重试。' : '网络连接错误，请稍后重试。');
    return;
  } finally {
    clearTimeout(checkTimeout);
  }
  btn.textContent = '⏳ 正在上传...';

  const xhr = new XMLHttpRequest();
  xhr.open('POST', 'upload.php', true);

  xhr.upload.onprogress = function(e) {
    if (e.lengthComputable) {
      progressContainer.classList.remove('hidden');
      const percent = Math.round((e.loaded / e.total) * 100);
      progressBar.style.width = percent + '%';
      progressPercent.textContent = percent + '%';
    }
  };

  xhr.onload = function() {
    btn.disabled = false;
    btn.textContent = '🚀 立即上传';
    if (xhr.status === 200) {
      successDiv.textContent = '🎉 上传成功！正在刷新...';
      successDiv.classList.remove('hidden');
      setTimeout(() => window.location.reload(), 1000);
    } else {
      uploadFailure(xhr.status, xhr.responseText);
    }
  };

  xhr.onerror = function() {
    uploadFailure(0, '网络连接错误，请稍后重试。');
  };
  xhr.onabort = function() { uploadFailure(0, '上传已取消。'); };

  xhr.send(formData);
};

// 移动文件功能
let moveFilePath = '';

function openMoveModal(path) {
  moveFilePath = path;
  document.getElementById('moveModalFileName').textContent = path;
  document.getElementById('moveErrorMsg').classList.add('hidden');
  document.getElementById('moveDestSelect').value = '';
  document.getElementById('moveModal').classList.remove('hidden');
}

function closeMoveModal() {
  document.getElementById('moveModal').classList.add('hidden');
}

function submitMoveFile() {
  const dest = document.getElementById('moveDestSelect').value;
  const form = createPostForm();
  form.action = 'index.php?dir=' + encodeURIComponent(document.getElementById('currentDirParam').value);

  const inputFile = document.createElement('input');
  inputFile.type = 'hidden';
  inputFile.name = 'move_file';
  inputFile.value = moveFilePath;
  form.appendChild(inputFile);

  const inputDest = document.createElement('input');
  inputDest.type = 'hidden';
  inputDest.name = 'move_dest';
  inputDest.value = dest;
  form.appendChild(inputDest);

  document.body.appendChild(form);
  form.submit();
}

// 文件加密/改密功能
let encryptFilePath = '';

function openEncryptModal(path, hasPassword) {
  encryptFilePath = path;
  document.getElementById('encryptModalFileName').textContent = path;
  document.getElementById('encryptPasswordInput').value = '';
  document.getElementById('encryptErrorMsg').classList.add('hidden');
  document.getElementById('removePasswordBtn').classList.toggle('hidden', !hasPassword);
  document.getElementById('encryptModal').classList.remove('hidden');
}

function closeEncryptModal() {
  document.getElementById('encryptModal').classList.add('hidden');
}

function submitEncryptFile() {
  const pwd = document.getElementById('encryptPasswordInput').value.trim();
  const errorBox = document.getElementById('encryptErrorMsg');
  if (!pwd) {
    errorBox.textContent = '请输入密码';
    errorBox.classList.remove('hidden');
    return;
  }
  const form = createPostForm();
  form.action = 'index.php?dir=' + encodeURIComponent(document.getElementById('currentDirParam').value);
  form.appendChild(hiddenInput('encrypt_file', encryptFilePath));
  form.appendChild(hiddenInput('new_file_password', pwd));
  document.body.appendChild(form);
  form.submit();
}

function submitRemovePassword() {
  const form = createPostForm();
  form.action = 'index.php?dir=' + encodeURIComponent(document.getElementById('currentDirParam').value);
  form.appendChild(hiddenInput('encrypt_file', encryptFilePath));
  form.appendChild(hiddenInput('remove_password', '1'));
  document.body.appendChild(form);
  form.submit();
}


// 批量操作功能
function toggleSelectAll(checkbox) {
  document.querySelectorAll('.batch-checkbox:not(:disabled)').forEach(cb => cb.checked = checkbox.checked);
}

function getSelectedBatchItems() {
  return Array.from(document.querySelectorAll('.batch-checkbox:checked')).map(cb => cb.value);
}

async function batchDelete() {
  const items = getSelectedBatchItems();
  if (items.length === 0) {
    await showAppAlert('请先勾选要删除的文件', {type: 'warning', title: '尚未选择文件'});
    return;
  }
  const confirmed = await showAppConfirm('确定要删除选中的 ' + items.length + ' 个文件吗？', {
    type: 'danger',
    confirmText: '删除 ' + items.length + ' 项',
    notice: '删除后无法恢复，请确认所选文件无误。'
  });
  if (!confirmed) return;

  const form = createPostForm();
  form.action = 'index.php?' + window.location.search.replace(/^\?/, '');
  form.appendChild(hiddenInput('batch_action', 'delete'));
  items.forEach(path => form.appendChild(hiddenInput('items[]', path)));
  document.body.appendChild(form);
  form.submit();
}

async function batchMove() {
  const items = getSelectedBatchItems();
  if (items.length === 0) {
    await showAppAlert('请先勾选要移动的文件', {type: 'warning', title: '尚未选择文件'});
    return;
  }
  const dest = document.getElementById('batchMoveDestSelect').value;
  const confirmed = await showAppConfirm('确定要将选中的 ' + items.length + ' 个文件移动到「' + (dest || '根目录') + '」吗？', {
    type: 'info',
    title: '确认移动文件',
    confirmText: '确认移动'
  });
  if (!confirmed) return;

  const form = createPostForm();
  form.action = 'index.php?' + window.location.search.replace(/^\?/, '');
  form.appendChild(hiddenInput('batch_action', 'move'));
  form.appendChild(hiddenInput('dest', dest));
  items.forEach(path => form.appendChild(hiddenInput('items[]', path)));
  document.body.appendChild(form);
  form.submit();
}

function submitDeleteItem(path, recursive = false) {
  const form = createPostForm();
  form.action = 'index.php?dir=' + encodeURIComponent(document.getElementById('currentDirParam').value);
  form.appendChild(hiddenInput('delete_item', path));
  if (recursive) form.appendChild(hiddenInput('delete_recursive', '1'));
  document.body.appendChild(form);
  form.submit();
}

async function deleteFile(path) {
  const confirmed = await showAppConfirm('确定要删除文件「' + path.split('/').pop() + '」吗？', {
    type: 'danger',
    confirmText: '永久删除',
    notice: '此操作不可恢复。'
  });
  if (confirmed) submitDeleteItem(path, false);
}

async function deleteFolder(path, hasContents) {
  const folderName = path.split('/').pop();
  const message = hasContents
    ? '目录「' + folderName + '」中包含文件或子目录，是否删除全部内容？'
    : '确定要删除空目录「' + folderName + '」吗？';
  const confirmed = await showAppConfirm(message, {
    type: 'danger',
    confirmText: hasContents ? '删除全部内容' : '删除目录',
    notice: '删除后无法撤回，请谨慎操作。'
  });
  if (confirmed) submitDeleteItem(path, hasContents);
}

async function previewFile(path, pwd = '') {
  const modal = document.getElementById('previewModal');
  const content = document.getElementById('previewContent');
  const title = document.getElementById('previewTitle');
  
  title.textContent = '预览: ' + path.split('/').pop();
  content.innerHTML = '<div class="flex flex-col items-center justify-center py-20 gap-3"><div class="animate-spin text-3xl">⏳</div><p class="text-sm text-slate-500">正在加载预览...</p></div>';
  modal.classList.remove('hidden');
  
  // 基础 URL
  const baseUrl = 'index.php?preview=1&file=' + encodeURIComponent(path);
  
  try {
    // 如果提供了密码，先进行一次“静默校验”并建立 Session 授权
    if (pwd) {
      const checkData = newCsrfFormData();
      checkData.append('preview', '1');
      checkData.append('file', path);
      checkData.append('pwd', pwd);
      checkData.append('check_only', '1');
      const checkResp = await fetch('index.php', { method: 'POST', body: checkData });
      if (!checkResp.ok) {
        throw new Error('PASSWORD_REQUIRED');
      }
    }

    // 尝试获取内容（此时如果需要密码且 Session 未授权，后端会返回 401）
    const response = await fetch(baseUrl);
    
    if (response.status === 401) {
      content.innerHTML = `
        <div class="max-w-sm mx-auto py-10 text-center space-y-4">
          <div class="text-4xl">🔐</div>
          <h4 class="font-bold text-slate-800">此文件已受保护</h4>
          <p class="text-xs text-slate-500">请输入下载密码以预览内容</p>
          <div class="flex gap-2">
            <input type="password" id="previewPwdInput" placeholder="输入密码" class="flex-1 bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-blue-500">
            <button onclick="previewFile('${path.replace(/'/g, "\\'")}', document.getElementById('previewPwdInput').value)" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-semibold hover:bg-blue-700 transition-all">确认</button>
          </div>
          ${pwd ? '<p class="text-xs text-rose-500">❌ 密码错误，请重试</p>' : ''}
        </div>
      `;
      return;
    }
    
    if (!response.ok) throw new Error('无法读取文件');

    const ext = path.split('.').pop().toLowerCase();
    // 使用时间戳防止浏览器缓存
    const finalUrl = baseUrl + '&t=' + Date.now();

    // 仅这些格式适合当作纯文本直接预览，其余格式（尤其是 doc/docx/xls/xlsx/ppt/pptx/zip 等二进制格式）
    // 一律不读取原始内容做文本渲染，否则浏览器会卡死（之前的 bug 就是这里导致的）
    const textPreviewExts = ['txt', 'md', 'log', 'csv', 'json', 'xml', 'html', 'htm', 'css', 'js', 'ts', 'ini', 'conf', 'yml', 'yaml', 'sh', 'py', 'java', 'c', 'cpp'];

    if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'].includes(ext)) {
      content.innerHTML = `<div class="flex justify-center"><img src="${finalUrl}" class="max-w-full h-auto rounded-lg shadow-sm border border-slate-100"></div>`;
    } else if (['pdf'].includes(ext)) {
      content.innerHTML = `<iframe src="${finalUrl}" class="w-full h-[70vh] border-0 rounded-lg"></iframe>`;
    } else if (['mp4', 'webm'].includes(ext)) {
      content.innerHTML = `<video src="${finalUrl}" controls class="w-full max-h-[70vh] rounded-lg bg-black"></video>`;
    } else if (['mp3', 'wav'].includes(ext)) {
      content.innerHTML = `<div class="py-20 px-10"><audio src="${finalUrl}" controls class="w-full"></audio></div>`;
    } else if (textPreviewExts.includes(ext)) {
      const text = await response.text();
      // 文本内容也做个长度保护，避免超大文本文件同样卡死
      const truncated = text.length > 300000;
      const displayText = truncated ? text.slice(0, 300000) : text;
      const safeText = displayText.replace(/[&<>"']/g, function(m) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
      });
      content.innerHTML = `<pre class="text-xs font-mono whitespace-pre-wrap break-all bg-slate-50 p-4 rounded-lg border border-slate-100 leading-relaxed text-slate-700">${safeText}</pre>` +
        (truncated ? '<p class="text-xs text-slate-400 text-center mt-2">⚠️ 文件过大，仅显示前 300000 字符</p>' : '');
    } else {
      content.innerHTML = `
        <div class="text-center py-16 space-y-3">
          <div class="text-4xl">📄</div>
          <p class="text-sm font-medium text-slate-600">该文件类型不支持在线预览</p>
          <p class="text-xs text-slate-400">（如 Word / Excel / PPT / 压缩包等格式，请下载后用对应软件打开）</p>
          <a href="download.php?file=${encodeURIComponent(path)}" class="inline-block bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold px-5 py-2 rounded-lg transition-all">⬇️ 下载查看</a>
        </div>
      `;
    }
  } catch (err) {
    content.innerHTML = `<div class="text-center py-20 text-rose-500"><p class="text-3xl mb-2">⚠️</p><p class="text-sm font-medium">预览加载失败: ${err.message}</p></div>`;
  }
}

function closePreviewModal() {
  document.getElementById('previewModal').classList.add('hidden');
  document.getElementById('previewContent').innerHTML = '';
}
