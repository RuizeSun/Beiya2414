<?php
// 设定内容类型为 JSON
header('Content-Type: application/json; charset=utf-8');
// 引入数据库连线和认证函式库 (假设 database.php 在同一目录)
require_once './database.php';

// 获取 action 参数
$action = $_GET['action'] ?? '';

// 解析 POST 输入
$input = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $json = file_get_contents('php://input');
    $input = json_decode($json, true);
}

// 路由处理
switch ($action) {
    case 'list':
        handleList();
        break;
    case 'create':
        handleCreate($input);
        break;
    case 'update':
        handleUpdate($input);
        break;
    case 'delete':
        handleDelete($input);
        break;
    case 'submissions':
        handleSubmissions();
        break;
    case 'view_submission':
        handleViewSubmission();
        break;
    case 'set_status':
        handleSetStatus($input);
        break;
    default:
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "无效的 API 动作"]);
        break;
}

/**
 * 获取作业列表
 */
function handleList()
{
    global $db;
    require_teacher_auth();

    try {
        $stmt = $db->query("SELECT * FROM homework ORDER BY Id DESC");
        $homeworks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 处理过期状态和时间格式
        foreach ($homeworks as &$hw) {
            $hw['isExpired'] = time() > $hw['stoptime'];
            $hw['stoptime_formatted'] = date('Y-m-d H:i', $hw['stoptime']);
        }

        echo json_encode(["status" => "success", "data" => $homeworks]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
}

/**
 * 创建新作业
 */
function handleCreate($input)
{
    global $db;
    $teacher = require_teacher_auth();

    $title = $input['title'] ?? '';
    $description = $input['description'] ?? '';
    $stopTimeStr = $input['stopTime'] ?? '';
    $isForAll = $input['isforallstudents'] ?? 1;
    $submit = $input['submit'] ?? 1;

    if (!$title || !$stopTimeStr) {
        echo json_encode(["status" => "error", "message" => "标题和截止时间必填"]);
        return;
    }

    $stopTime = strtotime($stopTimeStr);

    try {
        $stmt = $db->prepare("INSERT INTO homework (teacherid, title, description, stoptime, isforallstudents, submit, releasetime) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$teacher['Id'], $title, $description, $stopTime, $isForAll, $submit, time()]);
        echo json_encode(["status" => "success", "message" => "作业布置成功"]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
}

/**
 * 更新作业
 */
function handleUpdate($input)
{
    global $db;
    require_teacher_auth();

    $id = $input['id'] ?? null;
    $title = $input['title'] ?? '';
    $description = $input['description'] ?? '';
    $stopTimeStr = $input['stopTime'] ?? '';

    if (!$id || !$title || !$stopTimeStr) {
        echo json_encode(["status" => "error", "message" => "缺少必要参数"]);
        return;
    }

    $stopTime = strtotime($stopTimeStr);

    try {
        $stmt = $db->prepare("UPDATE homework SET title = ?, description = ?, stoptime = ? WHERE Id = ?");
        $stmt->execute([$title, $description, $stopTime, $id]);
        echo json_encode(["status" => "success", "message" => "作业更新成功"]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
}

/**
 * 删除作业
 */
function handleDelete($input)
{
    global $db;
    require_teacher_auth();

    $id = $input['id'] ?? null;
    if (!$id) {
        echo json_encode(["status" => "error", "message" => "ID 不能为空"]);
        return;
    }

    try {
        $db->beginTransaction();
        // 删除作业记录
        $stmt = $db->prepare("DELETE FROM homework WHERE Id = ?");
        $stmt->execute([$id]);
        // 删除相关的提交记录
        $stmt2 = $db->prepare("DELETE FROM homeworksubmission WHERE homeworkid = ?");
        $stmt2->execute([$id]);
        $db->commit();
        echo json_encode(["status" => "success", "message" => "删除成功"]);
    } catch (PDOException $e) {
        $db->rollBack();
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
}

/**
 * 获取某作业的学生提交状态列表
 */
function handleSubmissions()
{
    global $db;
    require_teacher_auth();

    $homeworkId = $_GET['homeworkId'] ?? null;
    if (!$homeworkId) {
        echo json_encode(["status" => "error", "message" => "缺少作业 ID"]);
        return;
    }

    try {
        // 获取作业标题
        $hwStmt = $db->prepare("SELECT title FROM homework WHERE Id = ?");
        $hwStmt->execute([$homeworkId]);
        $homework = $hwStmt->fetch(PDO::FETCH_ASSOC);
        $title = $homework ? $homework['title'] : '未知作业';

        // 获取所有学生并关联提交记录
        // 注意：这里假设是所有学生，如果以后有分组逻辑需要调整
        $sql = "SELECT s.Id as studentId, s.firstname, s.lastname, 
                       hs.Id as submissionId, hs.time, hs.updatetime
                FROM students s
                LEFT JOIN homeworksubmission hs ON s.Id = hs.studentid AND hs.homeworkid = ?
                ORDER BY s.Id ASC";

        $stmt = $db->prepare($sql);
        $stmt->execute([$homeworkId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $data = [];
        foreach ($rows as $row) {
            $isSubmitted = !empty($row['submissionId']);
            $data[] = [
                'studentId' => $row['studentId'],
                'fullName' => $row['lastname'] . $row['firstname'],
                'status' => $isSubmitted ? 'Submitted' : 'Pending',
                'submissionId' => $row['submissionId'],
                'time_formatted' => $isSubmitted ? date('Y-m-d H:i', $row['time']) : '-',
                'updatetime_formatted' => $isSubmitted ? date('Y-m-d H:i', $row['updatetime']) : '-'
            ];
        }

        echo json_encode(["status" => "success", "data" => $data, "title" => $title]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
}

/**
 * 查看提交详情（图片）
 */
function handleViewSubmission()
{
    global $db;
    require_teacher_auth();

    $submissionId = $_GET['submissionId'] ?? null;
    if (!$submissionId) {
        echo json_encode(["status" => "error", "message" => "缺少提交 ID"]);
        return;
    }

    try {
        $stmt = $db->prepare("SELECT submission, updatetime FROM homeworksubmission WHERE Id = ?");
        $stmt->execute([$submissionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            echo json_encode([
                "status" => "success",
                "image" => base64_encode($row['submission']), // 转为 Base64 给前端
                "updateTime" => date('Y-m-d H:i:s', $row['updatetime'])
            ]);
        } else {
            echo json_encode(["status" => "error", "message" => "找不到记录"]);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
}

/**
 * 【新功能】手动设置提交状态
 */
function handleSetStatus($input)
{
    global $db;
    require_teacher_auth();

    $studentId = $input['studentId'] ?? null;
    $homeworkId = $input['homeworkId'] ?? null;
    $status = $input['status'] ?? null; // 'submitted' or 'unsubmitted'

    if (!$studentId || !$homeworkId || !$status) {
        echo json_encode(["status" => "error", "message" => "参数不完整"]);
        return;
    }

    try {
        if ($status === 'unsubmitted') {
            // 设为未提交：删除记录
            $stmt = $db->prepare("DELETE FROM homeworksubmission WHERE studentid = ? AND homeworkid = ?");
            $stmt->execute([$studentId, $homeworkId]);
            $msg = "已设为未提交";
        } else {
            // 设为已提交：插入空记录（如果不存在）
            // 先检查是否存在
            $check = $db->prepare("SELECT Id FROM homeworksubmission WHERE studentid = ? AND homeworkid = ?");
            $check->execute([$studentId, $homeworkId]);
            if ($check->rowCount() > 0) {
                echo json_encode(["status" => "warning", "message" => "该学生已是提交状态"]);
                return;
            }

            $now = time();
            // 插入空内容的提交。submission 字段是 blob，插入空字符串即可
            $stmt = $db->prepare("INSERT INTO homeworksubmission (studentid, homeworkid, submission, time, updatetime) VALUES (?, ?, '', ?, ?)");
            $stmt->execute([$studentId, $homeworkId, $now, $now]);
            $msg = "已设为已提交 (手动)";
        }

        echo json_encode(["status" => "success", "message" => $msg]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
}
