<?php
// 设置 HTTP 响应头
header('Content-Type: application/json; charset=utf-8');

// 引入资料库连线配置和认证函数
require_once 'database.php'; // 确保路径正确

/**
 * 检查教师是否登录并返回完整的教师资讯
 * 与 database.php 中的 check_teacher_auth 相似，但返回更多信息
 * @return array|bool 返回教师信息的关联阵列或 false
 */
function check_full_teacher_auth()
{
    global $db;
    if (!isset($_COOKIE['token'])) {
        return false;
    }

    $token = $_COOKIE['token'];
    $currentTime = time();

    // 查询所有需要的字段，用于显示在主页
    $stmt = $db->prepare("SELECT Id, firstname, lastname, subject, isAdmin FROM teachers WHERE token = ? AND tokenExpire > ?");
    $stmt->execute([$token, $currentTime]);
    $user = $stmt->fetch();

    if ($user) {
        // 格式化姓名
        $user['fullName'] = $user['lastname'] . $user['firstname'];
        return $user;
    }
    return false;
}

// 执行认证检查
$user_info = check_full_teacher_auth();

if (!$user_info) {
    // 认证失败，Token 无效或过期
    http_response_code(401);
    echo json_encode([
        "status" => "error",
        "message" => "未授权访问。请重新登录。",
        "redirect" => "/teacher/login.html" // 提示前端跳转到登录页
    ]);
    exit;
}

// 认证成功，返回教师信息
http_response_code(200);
echo json_encode([
    "status" => "success",
    "data" => [
        "fullName" => $user_info['fullName'],
        "subject" => $user_info['subject'],
        "isAdmin" => (bool)$user_info['isAdmin']
    ]
]);
exit;
