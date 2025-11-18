<?php
// 設置 HTTP 響應頭
header('Content-Type: application/json; charset=utf-8');

// 引入資料庫連線配置和認證函數
require_once 'database.php'; // 確保路徑正確

/**
 * 檢查教師是否登錄並返回完整的教師資訊
 * 與 database.php 中的 check_teacher_auth 相似，但返回更多信息
 * @return array|bool 返回教師信息的關聯陣列或 false
 */
function check_full_teacher_auth()
{
    global $db;
    if (!isset($_COOKIE['token'])) {
        return false;
    }

    $token = $_COOKIE['token'];
    $currentTime = time();

    // 查詢所有需要的字段，用於顯示在主頁
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

// 執行認證檢查
$user_info = check_full_teacher_auth();

if (!$user_info) {
    // 認證失敗，Token 無效或過期
    http_response_code(401);
    echo json_encode([
        "status" => "error",
        "message" => "未授權訪問。請重新登錄。",
        "redirect" => "/teacher/login.html" // 提示前端跳轉到登錄頁
    ]);
    exit;
}

// 認證成功，返回教師信息
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
