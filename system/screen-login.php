<?php
// 確保 database.php 包含在內，以便訪問 $db 連線
require_once 'database.php';

// 設定內容類型為 JSON
header('Content-Type: application/json; charset=utf-8');

// 只處理 POST 請求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(["status" => "error", "message" => "僅接受 POST 請求。"]);
    exit;
}

// 讀取 JSON 請求體
$input = file_get_contents("php://input");
$data = json_decode($input, true);

$screenId = $data['screenId'] ?? null;
$password = $data['password'] ?? null;

// 輸入驗證
if (empty($screenId) || !is_numeric($screenId) || empty($password)) {
    http_response_code(400); // Bad Request
    echo json_encode(["status" => "error", "message" => "請提供有效的大屏 ID 和密碼。"]);
    exit;
}

$screenId = (int)$screenId;

try {
    // 1. 查詢大屏資訊（僅查詢密碼和 Id）
    $stmt = $db->prepare("SELECT Id, password FROM screens WHERE Id = ?");
    $stmt->execute([$screenId]);
    $screen = $stmt->fetch();

    // 2. 驗證密碼
    if ($screen && password_verify($password, $screen['password'])) {

        // 3. 登入成功：生成新的 Token 和過期時間
        $token = bin2hex(random_bytes(32)); // 安全的隨機 Token
        $tokenExpire = time() + (3600 * 24 * 30); // Token 30 天後過期

        // 4. 更新資料庫中的 Token
        $updateStmt = $db->prepare("UPDATE screens SET token = ?, tokenExpire = ? WHERE Id = ?");
        $updateStmt->execute([$token, $tokenExpire, $screenId]);

        // 5. 設定 screen_token Cookie
        // 必須與 database.php 中的 check_screen_auth 函數使用的名稱一致
        // 參數: 名稱, 值, 過期時間, 路徑, 域名, 安全(https), HttpOnly
        setcookie('screen_token', $token, $tokenExpire, '/', '', false, false);

        // 6. 回傳成功響應
        http_response_code(200);
        echo json_encode([
            "status" => "success",
            "message" => "班級大屏啟動成功。",
            "screenId" => $screenId,
        ]);
    } else {
        // 7. 登入失敗
        http_response_code(401); // Unauthorized
        echo json_encode(["status" => "error", "message" => "大屏 ID 或密碼驗證失敗。"]);
    }
} catch (\PDOException $e) {
    // 伺服器錯誤處理
    http_response_code(500);
    error_log("Screen Login Error: " . $e->getMessage()); // 記錄錯誤到伺服器日誌
    echo json_encode(["status" => "error", "message" => "伺服器內部錯誤，請稍後再試。"]);
}
