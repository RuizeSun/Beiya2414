<?php

/**
 * api_ai_admin.php
 * AI 系統管理後台 API
 */

require_once 'database.php';

// 1. 權限檢查：僅限管理員
$admin = check_admin_auth();
if (!$admin) {
    echo json_encode(["status" => "error", "message" => "權限不足，請重新登入管理員帳號"]);
    exit;
}

// 獲取 POST 請求資料
$input = json_decode(file_get_contents('php://input'), true);
$action = $_GET['action'] ?? $input['action'] ?? '';

header('Content-Type: application/json');

try {
    switch ($action) {
        // --- 初始化資料 ---
        case 'init_data':
            // 獲取所有提供商
            $providers = $db->query("SELECT * FROM ai_providers ORDER BY Id DESC")->fetchAll();

            // 獲取所有模型 (串接提供商名稱)
            $models = $db->query("
                SELECT m.*, p.provider_name 
                FROM ai_models m 
                LEFT JOIN ai_providers p ON m.provider_id = p.Id 
                ORDER BY m.Id DESC
            ")->fetchAll();

            // 獲取所有老師 (用於下拉選單)
            $teachers = $db->query("SELECT Id, firstname, lastname FROM teachers ORDER BY lastname ASC")->fetchAll();

            // 獲取所有配額設定
            $quotas = $db->query("
                SELECT q.*, t.firstname, t.lastname, m.model_name 
                FROM teacher_model_quotas q
                JOIN teachers t ON q.teacherid = t.Id
                JOIN ai_models m ON q.modelid = m.Id
                ORDER BY q.Id DESC
            ")->fetchAll();

            echo json_encode([
                "status" => "success",
                "data" => [
                    "providers" => $providers,
                    "models" => $models,
                    "teachers" => $teachers,
                    "quotas" => $quotas
                ]
            ]);
            break;

        // --- 提供商管理 ---
        case 'add_provider':
            $stmt = $db->prepare("INSERT INTO ai_providers (provider_name, base_url, api_key) VALUES (?, ?, ?)");
            $stmt->execute([$input['provider_name'], $input['base_url'], $input['api_key']]);
            echo json_encode(["status" => "success", "message" => "提供商已添加"]);
            break;

        case 'update_provider':
            // 安全處理：如果 API Key 欄位為空，則不更新密鑰
            if (empty($input['api_key'])) {
                $stmt = $db->prepare("UPDATE ai_providers SET provider_name = ?, base_url = ?, is_active = ? WHERE Id = ?");
                $stmt->execute([$input['provider_name'], $input['base_url'], $input['is_active'], $input['id']]);
            } else {
                $stmt = $db->prepare("UPDATE ai_providers SET provider_name = ?, base_url = ?, api_key = ?, is_active = ? WHERE Id = ?");
                $stmt->execute([$input['provider_name'], $input['base_url'], $input['api_key'], $input['is_active'], $input['id']]);
            }
            echo json_encode(["status" => "success", "message" => "提供商資訊已更新"]);
            break;

        case 'delete_provider':
            $stmt = $db->prepare("DELETE FROM ai_providers WHERE Id = ?");
            $stmt->execute([$input['id']]);
            echo json_encode(["status" => "success", "message" => "提供商已移除"]);
            break;

        // --- 模型管理 ---
        case 'add_model':
            $stmt = $db->prepare("INSERT INTO ai_models (model_name, model_alias, provider_id) VALUES (?, ?, ?)");
            $stmt->execute([$input['model_name'], $input['model_alias'], $input['provider_id']]);
            echo json_encode(["status" => "success", "message" => "模型已添加"]);
            break;

        case 'update_model':
            $stmt = $db->prepare("UPDATE ai_models SET model_name = ?, model_alias = ?, is_active = ? WHERE Id = ?");
            $stmt->execute([$input['model_name'], $input['model_alias'], $input['is_active'], $input['id']]);
            echo json_encode(["status" => "success", "message" => "模型資訊已更新"]);
            break;

        case 'delete_model':
            $stmt = $db->prepare("DELETE FROM ai_models WHERE Id = ?");
            $stmt->execute([$input['id']]);
            echo json_encode(["status" => "success", "message" => "模型已移除"]);
            break;

        // --- 配額與權限管理 ---
        case 'set_quota':
            $expire = !empty($input['expire_time']) ? strtotime($input['expire_time']) : null;
            // 使用 ON DUPLICATE KEY UPDATE 處理新增或更新
            $stmt = $db->prepare("
                INSERT INTO teacher_model_quotas (teacherid, modelid, max_quota, expire_time, is_enabled) 
                VALUES (?, ?, ?, ?, ?) 
                ON DUPLICATE KEY UPDATE max_quota = VALUES(max_quota), expire_time = VALUES(expire_time), is_enabled = VALUES(is_enabled)
            ");
            $stmt->execute([$input['teacherid'], $input['modelid'], $input['max_quota'], $expire, $input['is_enabled']]);
            echo json_encode(["status" => "success", "message" => "配額權限已儲存"]);
            break;

        case 'delete_quota':
            $stmt = $db->prepare("DELETE FROM teacher_model_quotas WHERE Id = ?");
            $stmt->execute([$input['id']]);
            echo json_encode(["status" => "success", "message" => "授權已取消"]);
            break;

        default:
            echo json_encode(["status" => "error", "message" => "未定義的操作: " . $action]);
            break;
    }
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => "資料庫錯誤: " . $e->getMessage()]);
}
