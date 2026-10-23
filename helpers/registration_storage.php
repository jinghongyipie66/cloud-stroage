<?php
/** 固定的内部账号只由服务器生成，不使用用户邮箱、表单账号或角色作为目录名。 */
function registration_new_username($root) {
    for ($attempt = 0; $attempt < 8; $attempt++) {
        $name = 'u_' . bin2hex(random_bytes(12));
        $path = $root . '/data/' . $name;
        if (!file_exists($path) && !is_link($path)) return $name;
    }
    throw new RuntimeException('暂时无法分配个人空间。');
}

/** 数据库提交成功后调用原目录工具；只检查新账号，不删除或迁移任何旧目录。 */
function registration_provision_space($root, $username) {
    if (!is_string($username) || !preg_match('/\Au_[a-f0-9]{24}\z/', $username)) return false;
    $path = $root . '/data/' . $username;
    if (is_link($path) || (file_exists($path) && !is_dir($path))) return false;
    ensure_user_folders($username);
    if (!is_dir($path) || is_link($path)) return false;
    $parent = realpath($root . '/data');
    $actual = realpath($path);
    if ($parent === false || $actual === false || dirname($actual) !== $parent || !is_writable($actual)) return false;
    foreach (USER_CATEGORY_FOLDERS as $folder) {
        $child = $path . '/' . $folder;
        $child_actual = realpath($child);
        if (!is_dir($child) || is_link($child) || $child_actual === false || dirname($child_actual) !== $actual || !is_writable($child)) return false;
    }
    return true;
}
