<?php
// 包含数据库连线和认证函数
require_once 'database.php';

// 检查管理员权限。非管理员将终止脚本并输出错误 JSON
require_admin_auth();

// 全局数据库连线物件
global $db;

// 设置 JSON 响应头部
header('Content-Type: application/json; charset=utf-8');
$response = ['success' => false, 'message' => '操作失败'];

// 检查是否提供了操作行为
if (!isset($_GET['action'])) {
    http_response_code(400);
    $response['message'] = '缺少操作行为 (action)。';
    echo json_encode($response);
    exit;
}

$action = $_GET['action'];

try {
    switch ($action) {
        case 'get_groups_details':
            // 获取所有小组详情，包括成员数、分数汇总和组长姓名
            $query = "
                SELECT 
                    g.Id, 
                    g.groupName, 
                    g.groupLeader,
                    t_leader.firstname AS leader_firstname,
                    t_leader.lastname AS leader_lastname,
                    COUNT(s.Id) AS studentCount,
                    IFNULL(SUM(s.score), 0) AS totalScore,
                    IFNULL(AVG(s.score), 0) AS averageScore
                FROM `groups` g
                LEFT JOIN students s ON g.Id = s.groupId
                LEFT JOIN students t_leader ON g.groupLeader = t_leader.Id
                GROUP BY g.Id, g.groupName, g.groupLeader, leader_firstname, leader_lastname
                ORDER BY g.Id ASC";

            $stmt = $db->query($query);
            $groups = $stmt->fetchAll();

            foreach ($groups as &$group) {
                // 格式化组长姓名
                if ($group['leader_firstname'] && $group['leader_lastname']) {
                    $group['leaderName'] = $group['leader_lastname'] . $group['leader_firstname'];
                } else {
                    $group['leaderName'] = '未设置';
                }

                // 确保数值类型正确并格式化平均分
                $group['studentCount'] = (int)$group['studentCount'];
                $group['totalScore'] = (int)$group['totalScore'];
                $group['averageScore'] = round((float)$group['averageScore'], 2);
                $group['groupLeader'] = $group['groupLeader'] ? (int)$group['groupLeader'] : null;

                unset($group['leader_firstname']);
                unset($group['leader_lastname']);
            }

            $response = ['success' => true, 'groups' => $groups];
            break;

        case 'get_students_by_group':
            // 获取特定小组的所有成员，用于组长设置下拉选单
            $groupId = $_GET['groupId'] ?? null;
            if (!$groupId || !is_numeric($groupId)) {
                http_response_code(400);
                $response['message'] = '缺少小组 ID 或 ID 无效。';
                break;
            }

            $query = "SELECT Id, firstname, lastname FROM students WHERE groupId = ? ORDER BY lastname, firstname";
            $stmt = $db->prepare($query);
            $stmt->execute([$groupId]);
            $students = $stmt->fetchAll();
            $students = format_names($students); // 使用 utility function 格式化为 "姓+名"

            $response = ['success' => true, 'students' => $students];
            break;

        case 'add_group':
            // 处理新增小组
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                break;
            }
            $groupName = trim($_POST['groupName'] ?? '');

            if (empty($groupName)) {
                $response['message'] = '小组名称不能为空。';
                break;
            }

            $query = "INSERT INTO `groups` (groupName) VALUES (?)";
            $stmt = $db->prepare($query);
            if ($stmt->execute([$groupName])) {
                $response = ['success' => true, 'message' => '小组新增成功。', 'newId' => $db->lastInsertId()];
            }
            break;

        case 'delete_group':
            // 处理删除小组 (解散)
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                break;
            }
            $groupId = $_POST['Id'] ?? null;

            if (!$groupId || !is_numeric($groupId)) {
                $response['message'] = '缺少小组 ID。';
                break;
            }

            $db->beginTransaction();
            // 1. 将所有属于该组的学生的 groupId 设为 NULL，并分数重置为 0
            $update_students_query = "UPDATE students SET groupId = NULL, score = 0 WHERE groupId = ?";
            $update_students_stmt = $db->prepare($update_students_query);
            $update_students_stmt->execute([$groupId]);

            // 2. 删除小组
            $delete_query = "DELETE FROM `groups` WHERE Id = ?";
            $delete_stmt = $db->prepare($delete_query);
            $delete_stmt->execute([$groupId]);
            $db->commit();

            $response = ['success' => true, 'message' => '小组已解散，成员的分组资讯已清除。'];
            break;

        case 'set_leader':
            // 处理设置组长
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                break;
            }
            $groupId = $_POST['groupId'] ?? null;
            $groupLeader = $_POST['groupLeader'] ?? null;

            if (!$groupId || !is_numeric($groupId)) {
                $response['message'] = '缺少小组 ID。';
                break;
            }

            // 将 'null' 字串 (来自 JS 的清除选项) 转换为 PHP 的 null 值
            $groupLeader = ($groupLeader === 'null' || $groupLeader === '') ? null : (int)$groupLeader;

            $query = "UPDATE `groups` SET groupLeader = ? WHERE Id = ?";
            $stmt = $db->prepare($query);

            if ($stmt->execute([$groupLeader, $groupId])) {
                $response = ['success' => true, 'message' => '组长设置成功。'];
            }
            break;

        default:
            http_response_code(400);
            $response['message'] = '未知操作';
            break;
    }
} catch (\PDOException $e) {
    http_response_code(500);
    $response['message'] = '数据库操作错误: ' . $e->getMessage();
    if ($db->inTransaction()) {
        $db->rollBack();
    }
} catch (\Exception $e) {
    http_response_code(500);
    $response['message'] = '服务器内部错误: ' . $e->getMessage();
}

echo json_encode($response);
exit;
