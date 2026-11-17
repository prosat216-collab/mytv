<?php
header('Content-Type: text/plain');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');
echo str_repeat('0123456789ABCDEF', 32768);
