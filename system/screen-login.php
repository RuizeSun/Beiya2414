<?php
// 确保 database.php 包含在内，以便访问 $db 连线
require_once 'database.php';

// 设定内容类型为 JSON
header('Content-Type: application/json; charset=utf-8');

// 只处理 POST 请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(["status" => "error", "message" => "仅接受 POST 请求。"]);
    exit;
}

// 读取 JSON 请求体
$input = file_get_contents("php://input");
$data = json_decode($input, true);

$screenId = $data['screenId'] ?? null;
$password = $data['password'] ?? null;

// 输入验证
if (empty($screenId) || !is_numeric($screenId) || empty($password)) {
    http_response_code(400); // Bad Request
    echo json_encode(["status" => "error", "message" => "请提供有效的大屏 ID 和密码。"]);
    exit;
}

$screenId = (int)$screenId;

try {
    // 1. 查询大屏资讯（仅查询密码和 Id）
    $stmt = $db->prepare("SELECT Id, password FROM screens WHERE Id = ?");
    $stmt->execute([$screenId]);
    $screen = $stmt->fetch();

    // 2. 验证密码
    if ($screen && password_verify($password, $screen['password'])) {

        // 3. 登入成功：生成新的 Token 和过期时间
        $token = bin2hex(random_bytes(32)); // 安全的随机 Token
        $tokenExpire = time() + (3600 * 24 * 30); // Token 30 天后过期

        // 4. 更新数据库中的 Token
        $updateStmt = $db->prepare("UPDATE screens SET token = ?, tokenExpire = ? WHERE Id = ?");
        $updateStmt->execute([$token, $tokenExpire, $screenId]);

        // 5. 设定 screen_token Cookie
        // 必须与 database.php 中的 check_screen_auth 函数使用的名称一致
        // 参数: 名称, 值, 过期时间, 路径, 域名, 安全(https), HttpOnly
        setcookie('screen_token', $token, $tokenExpire, '/', '', false, false);

        // 6. 回传成功响应
        http_response_code(200);
        echo json_encode([
            "status" => "success",
            "message" => "班级大屏启动成功。",
            "screenId" => $screenId,
        ]);
    } else {
        // 7. 登入失败
        http_response_code(401); // Unauthorized
        echo json_encode(["status" => "error", "message" => "大屏 ID 或密码验证失败。"]);
    }
} catch (\PDOException $e) {
    // 服务器错误处理
    http_response_code(500);
    error_log("Screen Login Error: " . $e->getMessage()); // 记录错误到服务器日志
    echo json_encode(["status" => "error", "message" => "服务器内部错误，请稍后再试。"]);
}
