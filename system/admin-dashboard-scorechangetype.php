<?php
// 设置响应头为 JSON 格式
header("Content-Type: application/json; charset=UTF-8");

// 引入数据库配置文件和连接
// 此处假设 database.php 位于同一目录下，且已正确配置
require_once 'database.php';

// 强制执行管理员权限验证。如果用户不是管理员，脚本将在此处终止并输出 HTML 错误。
$admin = require_admin_auth();

// 获取请求方法
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            // --- 读取所有评分改动项 ---
            $stmt = $db->query("SELECT Id, name, `change`, timestamp FROM scorechangetype ORDER BY Id DESC");
            $data = $stmt->fetchAll();

            echo json_encode([
                "status" => "success",
                "data" => $data
            ]);
            break;

        case 'POST':
            // --- 创建新项 (使用 JSON body) ---
            $input = json_decode(file_get_contents("php://input"), true);

            if (empty($input['name']) || !isset($input['change']) || !is_numeric($input['change'])) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "缺少必要的参数或参数格式错误 (name, change)。"]);
                exit;
            }

            $name = trim($input['name']);
            $change = (float)$input['change'];
            $timestamp = time(); // 使用当前的 Unix 时间戳

            $stmt = $db->prepare("INSERT INTO scorechangetype (name, `change`, timestamp) VALUES (?, ?, ?)");
            $stmt->execute([$name, $change, $timestamp]);

            echo json_encode([
                "status" => "success",
                "message" => "评分改动项创建成功。",
                "Id" => $db->lastInsertId()
            ]);
            break;

        case 'PUT':
            // --- 更新现有项 (使用 x-www-form-urlencoded 格式) ---
            // 解析 PUT 数据流
            parse_str(file_get_contents("php://input"), $input);

            if (empty($input['Id']) || empty($input['name']) || !isset($input['change']) || !is_numeric($input['change'])) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "缺少必要的参数或参数格式错误 (Id, name, change)。"]);
                exit;
            }

            $Id = (int)$input['Id'];
            $name = trim($input['name']);
            $change = (float)$input['change'];
            $timestamp = time(); // 更新时间戳

            $stmt = $db->prepare("UPDATE scorechangetype SET name = ?, `change` = ?, timestamp = ? WHERE Id = ?");

            if ($stmt->execute([$name, $change, $timestamp, $Id]) && $stmt->rowCount() > 0) {
                echo json_encode([
                    "status" => "success",
                    "message" => "评分改动项更新成功。",
                    "Id" => $Id
                ]);
            } else {
                http_response_code(404);
                echo json_encode(["status" => "error", "message" => "未找到要更新的记录或更新失败。"]);
            }
            break;

        case 'DELETE':
            // --- 删除现有项 (使用 x-www-form-urlencoded 格式) ---
            // 解析 DELETE 数据流
            parse_str(file_get_contents("php://input"), $input);

            if (empty($input['Id'])) {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "缺少必要的参数 (Id)。"]);
                exit;
            }

            $Id = (int)$input['Id'];

            $stmt = $db->prepare("DELETE FROM scorechangetype WHERE Id = ?");
            if ($stmt->execute([$Id]) && $stmt->rowCount() > 0) {
                echo json_encode([
                    "status" => "success",
                    "message" => "评分改动项删除成功。",
                    "Id" => $Id
                ]);
            } else {
                http_response_code(404);
                echo json_encode(["status" => "error", "message" => "未找到要删除的记录或删除失败。"]);
            }
            break;

        default:
            http_response_code(405);
            echo json_encode(["status" => "error", "message" => "不支持的请求方法"]);
            break;
    }
} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "数据库操作失败: " . $e->getMessage()
    ]);
}
// 避免意外的空格或输出