<?php
// 设定回传 JSON 格式
header('Content-Type: application/json; charset=utf-8');

// 引入数据库连线和权限验证
require_once 'database.php';

// 检查管理员权限，如果失败则脚本终止并输出 HTML 错误
$admin = require_admin_auth();
// $admin 变量现在包含管理员的 Id, firstname, lastname

// 处理 POST/PUT/DELETE 请求时的 JSON 输入
function get_json_input()
{
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method == 'POST') {
        // For POST requests, usually use $_POST, but we check raw input too for flexibility
        $data = $_POST;
        if (empty($data)) {
            $data = json_decode(file_get_contents('php://input'), true);
        }
    } else {
        // For PUT, PATCH, DELETE, read from php://input
        $data = json_decode(file_get_contents('php://input'), true);
    }
    return is_array($data) ? $data : [];
}

// 根据 action 参数处理不同请求
$action = $_GET['action'] ?? '';

try {
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            if ($action === 'list') {
                // 获取教师列表
                $stmt = $db->query("SELECT Id, firstname, lastname, subject, isAdmin FROM teachers ORDER BY Id ASC");
                $teachers = $stmt->fetchAll();

                // 格式化姓名
                $teachers = format_names($teachers);

                echo json_encode(["status" => "success", "data" => $teachers]);
            } else {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "无效的 GET 动作"]);
            }
            break;

        case 'POST':
            if ($action === 'add') {
                $input = get_json_input();
                $required_fields = ['firstname', 'lastname', 'subject', 'password', 'isAdmin'];

                foreach ($required_fields as $field) {
                    if (!isset($input[$field])) {
                        http_response_code(400);
                        echo json_encode(["status" => "error", "message" => "缺少必要的字段: {$field}"]);
                        exit();
                    }
                }

                $firstname = trim($input['firstname']);
                $lastname = trim($input['lastname']);
                $subject = trim($input['subject']);
                $password = password_hash($input['password'], PASSWORD_DEFAULT); // 密码加密
                $isAdmin = (int)$input['isAdmin'];

                $stmt = $db->prepare("INSERT INTO teachers (firstname, lastname, subject, isAdmin, password) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$firstname, $lastname, $subject, $isAdmin, $password]);
                $newId = $db->lastInsertId();

                echo json_encode(["status" => "success", "message" => "教师添加成功", "id" => $newId]);
            } else {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "无效的 POST 动作"]);
            }
            break;

        case 'PUT':
        case 'PATCH':
            if ($action === 'update') {
                $input = get_json_input();

                if (!isset($input['Id'])) {
                    http_response_code(400);
                    echo json_encode(["status" => "error", "message" => "缺少教师 ID"]);
                    exit();
                }

                $Id = (int)$input['Id'];
                $params = [];
                $setClauses = [];

                // 检查并准备更新字段
                if (isset($input['firstname'])) {
                    $setClauses[] = "firstname = ?";
                    $params[] = trim($input['firstname']);
                }
                if (isset($input['lastname'])) {
                    $setClauses[] = "lastname = ?";
                    $params[] = trim($input['lastname']);
                }
                if (isset($input['subject'])) {
                    $setClauses[] = "subject = ?";
                    $params[] = trim($input['subject']);
                }
                if (isset($input['isAdmin'])) {
                    $setClauses[] = "isAdmin = ?";
                    $params[] = (int)$input['isAdmin'];
                }
                if (isset($input['password']) && !empty($input['password'])) {
                    $setClauses[] = "password = ?";
                    $params[] = password_hash($input['password'], PASSWORD_DEFAULT); // 密码加密
                }

                if (empty($setClauses)) {
                    http_response_code(400);
                    echo json_encode(["status" => "error", "message" => "没有提供更新的字段"]);
                    exit();
                }

                $params[] = $Id; // 将 Id 加入参数列表的最后

                $sql = "UPDATE teachers SET " . implode(', ', $setClauses) . " WHERE Id = ?";
                $stmt = $db->prepare($sql);
                $stmt->execute($params);

                echo json_encode(["status" => "success", "message" => "教师资讯更新成功", "Id" => $Id]);
            } else {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "无效的 PUT/PATCH 动作"]);
            }
            break;

        case 'DELETE':
            if ($action === 'delete') {
                $input = get_json_input();
                $Id = (int)($input['Id'] ?? 0);

                if ($Id <= 0) {
                    http_response_code(400);
                    echo json_encode(["status" => "error", "message" => "无效的教师 ID"]);
                    exit();
                }

                // 防止管理员删除自己
                if ($Id === (int)$admin['Id']) {
                    http_response_code(403);
                    echo json_encode(["status" => "error", "message" => "您不能删除自己的管理员账号！"]);
                    exit();
                }

                $stmt = $db->prepare("DELETE FROM teachers WHERE Id = ?");
                $stmt->execute([$Id]);

                if ($stmt->rowCount() > 0) {
                    echo json_encode(["status" => "success", "message" => "教师删除成功", "Id" => $Id]);
                } else {
                    http_response_code(404);
                    echo json_encode(["status" => "error", "message" => "找不到该教师或已删除"]);
                }
            } else {
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "无效的 DELETE 动作"]);
            }
            break;

        default:
            http_response_code(405); // Method Not Allowed
            echo json_encode(["status" => "error", "message" => "不允许的请求方法"]);
            break;
    }
} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "数据库操作错误: " . $e->getMessage()]);
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "服务器错误: " . $e->getMessage()]);
}
