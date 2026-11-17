<?php
header('Content-Type: application/octet-stream');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store, no-cache, must-revalidate');
echo str_repeat('A', 524288);
