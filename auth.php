<?php
// auth.php

$allowed_ips = [
    '137.226.140.241',
    '137.226.141.200',
    '137.226.141.203',
    '137.226.141.204',
    '134.130.0.99'
];

$client_ip = $_SERVER['REMOTE_ADDR'];

if (!in_array($client_ip, $allowed_ips)) {
    http_response_code(404);
    exit;
}
