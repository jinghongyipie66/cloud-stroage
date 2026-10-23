<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// 只由管理员在自己的终端运行。助手不会执行此脚本、读取或保存输出。
echo bin2hex(random_bytes(32)) . PHP_EOL;
