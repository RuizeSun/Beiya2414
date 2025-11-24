<?php
// 设置 HTTP 响应头，允许跨域（如果前端是不同域名，否则不需要）
// header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');

// 引入数据库连线配置
require_once 'database.php'; // 确保路径正确

// ----------------------------------------------------
// 1. 接收并验证输入
// ----------------------------------------------------

// 检查请求方法是否为 POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(["status" => "error", "message" => "不支援的请求方法。"]);
    exit;
}

// 从请求体读取 JSON 资料
$input = file_get_contents("php://input");
$data = json_decode($input, true);

// 检查 ID 和密码是否存在
if (!isset($data['id']) || !isset($data['password'])) {
    http_response_code(400); // Bad Request
    echo json_encode(["status" => "error", "message" => "请提供用户 ID 和密码。"]);
    exit;
}

$id = $data['id'];
$password = $data['password'];

// ----------------------------------------------------
// 2. 数据库验证
// ----------------------------------------------------

try {
    // 查询用户资讯，准备验证密码
    $stmt = $db->prepare("SELECT `password`, `isAdmin` FROM `teachers` WHERE `Id` = ?");
    $stmt->execute([$id]);
    $user = $stmt->fetch();

    // 检查用户是否存在
    if (!$user) {
        http_response_code(401); // Unauthorized
        echo json_encode(["status" => "error", "message" => "用户 ID 或密码错误。"]);
        exit;
    }

    // 验证密码 (假设您在数据库中储存的是明文密码，这**非常不安全**，请使用 `password_hash()` 和 `password_verify()` 替代)
    // **安全警告：以下使用明文比对，实际生产环境请使用密码杂凑**
    if (!password_verify($password, $user['password'])) {
        http_response_code(401); // Unauthorized
        echo json_encode(["status" => "error", "message" => "用户 ID 或密码错误。"]);
        exit;
    }
    // **安全建议：使用 password_verify 的安全写法 (如果数据库中是杂凑密码):**
    // if (!password_verify($password, $user['password'])) { ... }


    // ----------------------------------------------------
    // 3. 生成/更新 Token
    // ----------------------------------------------------

    // 生成一个新的随机 Token
    // 使用 bin2hex(random_bytes(16)) 得到 32 个字元的十六进制字串，更安全
    $newToken = bin2hex(random_bytes(16));

    // 计算 Token 过期时间：当前时间 + 7 天 (7 * 24 * 60 * 60 秒)
    $tokenExpire = time() + (7 * 24 * 60 * 60);

    // 更新数据库中的 Token 和过期时间
    $stmt = $db->prepare("UPDATE `teachers` SET `token` = ?, `tokenExpire` = ? WHERE `Id` = ?");
    $stmt->execute([$newToken, $tokenExpire, $id]);

    // ----------------------------------------------------
    // 4. 设置 Token Cookie
    // ----------------------------------------------------

    // 设置 Cookie
    // - 名称: 'token'
    // - 值: $newToken
    // - 过期时间: $tokenExpire (必须是 Unix timestamp)
    // - Path: '/' (在整个站点可用)
    // - Domain: '' (空字串表示当前域名)
    // - Secure: false (如果您的网站是 HTTPS，请改为 true)
    // - HttpOnly: true (**重要**：防止 XSS 攻击通过 JavaScript 读取 Cookie)
    setcookie(
        'token',
        $newToken,
        [
            'expires' => $tokenExpire,
            'path' => '/',
            'domain' => '',
            'secure' => false, // 除非是 HTTPS 网站，否则请保持 false 或删除此行
            'httponly' => false,
            'samesite' => 'Lax' // 建议使用 Lax 或 Strict
        ]
    );

    // ----------------------------------------------------
    // 5. 返回成功响应
    // ----------------------------------------------------

    // 返回成功信息
    echo json_encode([
        "status" => "success",
        "message" => "登录成功！欢迎回来。",
        "isAdmin" => (bool)$user['isAdmin'] // 返回是否为管理员
    ]);
} catch (\PDOException $e) {
    // 数据库操作失败
    http_response_code(500); // Internal Server Error
    echo json_encode([
        "status" => "error",
        "message" => "登录失败：服务器数据库操作错误。",
        "details" => $e->getMessage() // 仅用于调试，正式环境应隐藏
    ]);
}
// 脚本结束
exit;
