<?php
header('Content-Type: application/json; charset=utf-8');
require_once 'database.php';

/**
 * 接口专用管理员鉴权
 */
function require_admin_auth_api()
{
    $admin = check_admin_auth();
    if (!$admin) {
        http_response_code(401);
        echo json_encode([
            'status' => 'error',
            'message' => '未授权，需管理员身份'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
    return $admin;
}

// 必须管理员才能调用
$admin = require_admin_auth_api();

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'list':
            listScreens($db);
            break;
        case 'create':
            createScreen($db);
            break;
        case 'update_password':
            updateScreenPassword($db);
            break;
        default:
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => '无效的 action'
            ], JSON_UNESCAPED_UNICODE);
            break;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

function listScreens(PDO $db)
{
    $stmt = $db->query("SELECT Id FROM screens ORDER BY Id DESC");
    $rows = $stmt->fetchAll();

    $data = array_map(function ($row) {
        return [
            'Id' => (int)$row['Id']
        ];
    }, $rows);

    echo json_encode([
        'status' => 'success',
        'data' => $data
    ], JSON_UNESCAPED_UNICODE);
}

function createScreen(PDO $db)
{
    $password = trim($_POST['password'] ?? '');

    if ($password === '') {
        http_response_code(400);
        throw new Exception('初始密码不能为空');
    }

    if (mb_strlen($password) < 6) {
        http_response_code(400);
        throw new Exception('密码长度不能少于 6 位');
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $db->prepare("INSERT INTO screens (password) VALUES (?)");
    $stmt->execute([$passwordHash]);

    $newId = $db->lastInsertId();

    echo json_encode([
        'status' => 'success',
        'message' => '大屏帐号创建成功',
        'id' => $newId
    ], JSON_UNESCAPED_UNICODE);
}

function updateScreenPassword(PDO $db)
{
    $id = intval($_POST['id'] ?? 0);
    $password = trim($_POST['password'] ?? '');

    if ($id <= 0) {
        http_response_code(400);
        throw new Exception('参数 id 无效');
    }

    if ($password === '') {
        http_response_code(400);
        throw new Exception('新密码不能为空');
    }

    if (mb_strlen($password) < 6) {
        http_response_code(400);
        throw new Exception('密码长度不能少于 6 位');
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $db->prepare("UPDATE screens SET password = ? WHERE Id = ?");
    $stmt->execute([$passwordHash, $id]);

    if ($stmt->rowCount() === 0) {
        $checkStmt = $db->prepare("SELECT Id FROM screens WHERE Id = ?");
        $checkStmt->execute([$id]);
        $exists = $checkStmt->fetch();

        if (!$exists) {
            http_response_code(404);
            throw new Exception('大屏不存在');
        }
    }

    echo json_encode([
        'status' => 'success',
        'message' => '密码修改成功'
    ], JSON_UNESCAPED_UNICODE);
}
