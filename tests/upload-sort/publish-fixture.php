<?php
require dirname(__DIR__, 2).'/helpers/upload_helpers.php';
$deadline = microtime(true) + 8;
while (!file_exists($argv[3]) && microtime(true) < $deadline) usleep(1000);
echo upload_publish_exclusive($argv[1], $argv[2]);
