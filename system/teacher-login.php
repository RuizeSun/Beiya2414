<?php
// 設置 HTTP 響應頭，允許跨域（如果前端是不同域名，否則不需要）
// header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');

// 引入資料庫連線配置
require_once 'database.php'; // 確保路徑正確

// ----------------------------------------------------
// 1. 接收並驗證輸入
// ----------------------------------------------------

// 檢查請求方法是否為 POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(["status" => "error", "message" => "不支援的請求方法。"]);
    exit;
}

// 從請求體讀取 JSON 資料
$input = file_get_contents("php://input");
$data = json_decode($input, true);

// 檢查 ID 和密碼是否存在
if (!isset($data['id']) || !isset($data['password'])) {
    http_response_code(400); // Bad Request
    echo json_encode(["status" => "error", "message" => "請提供用戶 ID 和密碼。"]);
    exit;
}

$id = $data['id'];
$password = $data['password'];

// ----------------------------------------------------
// 2. 數據庫驗證
// ----------------------------------------------------

try {
    // 查詢用戶資訊，準備驗證密碼
    $stmt = $db->prepare("SELECT `password`, `isAdmin` FROM `teachers` WHERE `Id` = ?");
    $stmt->execute([$id]);
    $user = $stmt->fetch();

    // 檢查用戶是否存在
    if (!$user) {
        http_response_code(401); // Unauthorized
        echo json_encode(["status" => "error", "message" => "用戶 ID 或密碼錯誤。"]);
        exit;
    }

    // 驗證密碼 (假設您在數據庫中儲存的是明文密碼，這**非常不安全**，請使用 `password_hash()` 和 `password_verify()` 替代)
    // **安全警告：以下使用明文比對，實際生產環境請使用密碼雜湊**
    if (!password_verify($password, $user['password'])) {
        http_response_code(401); // Unauthorized
        echo json_encode(["status" => "error", "message" => "用戶 ID 或密碼錯誤。"]);
        exit;
    }
    // **安全建議：使用 password_verify 的安全寫法 (如果數據庫中是雜湊密碼):**
    // if (!password_verify($password, $user['password'])) { ... }


    // ----------------------------------------------------
    // 3. 生成/更新 Token
    // ----------------------------------------------------

    // 生成一個新的隨機 Token
    // 使用 bin2hex(random_bytes(16)) 得到 32 個字元的十六進制字串，更安全
    $newToken = bin2hex(random_bytes(16));

    // 計算 Token 過期時間：當前時間 + 7 天 (7 * 24 * 60 * 60 秒)
    $tokenExpire = time() + (7 * 24 * 60 * 60);

    // 更新數據庫中的 Token 和過期時間
    $stmt = $db->prepare("UPDATE `teachers` SET `token` = ?, `tokenExpire` = ? WHERE `Id` = ?");
    $stmt->execute([$newToken, $tokenExpire, $id]);

    // ----------------------------------------------------
    // 4. 設置 Token Cookie
    // ----------------------------------------------------

    // 設置 Cookie
    // - 名稱: 'token'
    // - 值: $newToken
    // - 過期時間: $tokenExpire (必須是 Unix timestamp)
    // - Path: '/' (在整個站點可用)
    // - Domain: '' (空字串表示當前域名)
    // - Secure: false (如果您的網站是 HTTPS，請改為 true)
    // - HttpOnly: true (**重要**：防止 XSS 攻擊通過 JavaScript 讀取 Cookie)
    setcookie(
        'token',
        $newToken,
        [
            'expires' => $tokenExpire,
            'path' => '/',
            'domain' => '',
            'secure' => false, // 除非是 HTTPS 網站，否則請保持 false 或刪除此行
            'httponly' => false,
            'samesite' => 'Lax' // 建議使用 Lax 或 Strict
        ]
    );

    // ----------------------------------------------------
    // 5. 返回成功響應
    // ----------------------------------------------------

    // 返回成功信息
    echo json_encode([
        "status" => "success",
        "message" => "登錄成功！歡迎回來。",
        "isAdmin" => (bool)$user['isAdmin'] // 返回是否為管理員
    ]);
} catch (\PDOException $e) {
    // 數據庫操作失敗
    http_response_code(500); // Internal Server Error
    echo json_encode([
        "status" => "error",
        "message" => "登錄失敗：伺服器數據庫操作錯誤。",
        "details" => $e->getMessage() // 僅用於調試，正式環境應隱藏
    ]);
}
// 腳本結束
exit;
