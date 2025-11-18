<?php
// 包含数据库连线和认证函数
require_once 'database.php';

// 检查管理员权限
$admin = check_admin_auth();
if (!$admin) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => '未授权或登录已过期，请重新登录。']);
    exit();
}

// 全局数据库连线物件
global $db;

// 设置响应头为 JSON
header('Content-Type: application/json; charset=utf-8');
$response = ['success' => false, 'message' => '操作失败'];
$action = $_GET['action'] ?? null;

if (!$action) {
    http_response_code(400);
    $response['message'] = '缺少操作参数 (action)。';
    echo json_encode($response);
    exit;
}

try {
    switch ($action) {
        case 'get_students':
            // 获取所有学生及其组名
            $query = "
                SELECT 
                    s.Id, 
                    s.firstname, 
                    s.lastname, 
                    s.score,
                    s.groupId,
                    g.groupName
                FROM students s
                LEFT JOIN `groups` g ON s.groupId = g.Id
                ORDER BY s.Id";
            $stmt = $db->query($query);
            $students = $stmt->fetchAll();
            $students = format_names($students);

            $response = ['success' => true, 'students' => $students];
            break;

        case 'get_groups':
            // 获取所有小组
            $query = "SELECT Id, groupName FROM `groups` ORDER BY Id ASC";
            $stmt = $db->query($query);
            $groups = $stmt->fetchAll();
            // 添加一个“未分组”选项
            array_unshift($groups, ['Id' => null, 'groupName' => '未分组']);

            $response = ['success' => true, 'groups' => $groups];
            break;

        case 'add_student':
            // 处理新增学生 (POST)
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                $response['message'] = '仅允许 POST 请求。';
                break;
            }
            $firstname = trim($_POST['firstname'] ?? '');
            $lastname = trim($_POST['lastname'] ?? '');
            $password = $_POST['password'] ?? '';

            if (empty($firstname) || empty($lastname) || empty($password)) {
                $response['message'] = '姓名和密码不能为空。';
                break;
            }

            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            $query = "INSERT INTO students (firstname, lastname, password, score) VALUES (?, ?, ?, 0)";
            $stmt = $db->prepare($query);
            if ($stmt->execute([$firstname, $lastname, $hashed_password])) {
                $response = ['success' => true, 'message' => '学生新增成功。'];
            }
            break;

        case 'update_student':
            // 处理修改学生信息 (POST)
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                $response['message'] = '仅允许 POST 请求。';
                break;
            }
            $studentId = $_POST['Id'] ?? null;
            $firstname = trim($_POST['firstname'] ?? '');
            $lastname = trim($_POST['lastname'] ?? '');
            $password = $_POST['password'] ?? null; // 密码可选
            $score = $_POST['score'] ?? null;

            if (!$studentId) {
                $response['message'] = '缺少学生 ID。';
                break;
            }

            $set_parts = [];
            $params = [];

            if (!empty($firstname)) {
                $set_parts[] = 'firstname = ?';
                $params[] = $firstname;
            }
            if (!empty($lastname)) {
                $set_parts[] = 'lastname = ?';
                $params[] = $lastname;
            }
            if (!is_null($score) && is_numeric($score)) {
                $set_parts[] = 'score = ?';
                $params[] = (int)$score;
            }
            if (!empty($password)) {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $set_parts[] = 'password = ?';
                $params[] = $hashed_password;
            }

            if (empty($set_parts)) {
                $response['message'] = '没有需要更新的字段。';
                break;
            }

            $query = "UPDATE students SET " . implode(', ', $set_parts) . " WHERE Id = ?";
            $params[] = $studentId;

            $stmt = $db->prepare($query);
            if ($stmt->execute($params)) {
                $response = ['success' => true, 'message' => '学生信息更新成功。'];
            }
            break;

        case 'delete_student':
            // 处理删除学生 (POST)
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                $response['message'] = '仅允许 POST 请求。';
                break;
            }
            $studentId = $_POST['Id'] ?? null;

            if (!$studentId) {
                $response['message'] = '缺少学生 ID。';
                break;
            }

            $query = "DELETE FROM students WHERE Id = ?";
            $stmt = $db->prepare($query);
            if ($stmt->execute([$studentId])) {
                $response = ['success' => true, 'message' => '学生删除成功。'];
            }
            break;

        case 'assign_group':
            // 处理小组分配 (POST)
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                $response['message'] = '仅允许 POST 请求。';
                break;
            }
            $studentId = $_POST['Id'] ?? null;
            $groupId = $_POST['groupId'] ?? null;

            // 将 'null' 字串转换为 PHP 的 null 值
            $groupId = ($groupId === 'null' || $groupId === '') ? null : (int)$groupId;

            if (!$studentId) {
                $response['message'] = '缺少学生 ID。';
                break;
            }

            $query = "UPDATE students SET groupId = ? WHERE Id = ?";
            $stmt = $db->prepare($query);

            if ($stmt->execute([$groupId, $studentId])) {
                $response = ['success' => true, 'message' => '小组分配成功。'];
            }
            break;

        default:
            http_response_code(400);
            $response['message'] = '未知操作';
            break;
    }
} catch (\PDOException $e) {
    // 捕获所有 PDO 异常
    http_response_code(500);
    $response['message'] = '数据库操作错误: ' . $e->getMessage();
} catch (\Exception $e) {
    // 捕获其他异常
    http_response_code(500);
    $response['message'] = '服务器内部错误: ' . $e->getMessage();
}

echo json_encode($response);
exit;
