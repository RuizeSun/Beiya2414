<?php
// 设置 HTTP 响应头
header('Content-Type: application/json; charset=utf-8');

// 引入资料库连线配置和认证函数
require_once 'database.php'; // 确保路径正确

// 执行认证检查
$user_info = check_screen_auth();

if (!$user_info) {
    // 认证失败，Token 无效或过期
    http_response_code(401);
    echo json_encode([
        "status" => "error",
        "message" => "未授权访问。请重新登录。",
        "redirect" => "/screen/login.html" // 提示前端跳转到登录页
    ]);
    exit;
}

// 认证成功，返回教师信息
http_response_code(200);
echo json_encode([
    "status" => "success",
]);
exit;
