<?php
// 设置 HTTP 响应头
header('Content-Type: application/json; charset=utf-8');

// 设置一个过期的 Cookie 来清除它
// 设置 time() - 3600 使其立即失效，并设置一个旧的过期时间
setcookie(
    'token',
    '',
    [
        'expires' => time() - 3600, // 立即过期
        'path' => '/',
        'domain' => '',
        'secure' => false,
        'httponly' => false,
        'samesite' => 'Lax'
    ]
);

// 返回登出成功响应
http_response_code(200);
echo json_encode([
    "status" => "success",
    "message" => "登出成功。",
    "redirect" => "/teacher/login.html" // 告知前端跳转到登录页
]);
exit;
