<?php
// 設置 HTTP 響應頭
header('Content-Type: application/json; charset=utf-8');

// 設置一個過期的 Cookie 來清除它
// 設置 time() - 3600 使其立即失效，並設置一個舊的過期時間
setcookie(
    'token',
    '',
    [
        'expires' => time() - 3600, // 立即過期
        'path' => '/',
        'domain' => '',
        'secure' => false,
        'httponly' => false,
        'samesite' => 'Lax'
    ]
);

// 返回登出成功響應
http_response_code(200);
echo json_encode([
    "status" => "success",
    "message" => "登出成功。",
    "redirect" => "/teacher/login.html" // 告知前端跳轉到登錄頁
]);
exit;
