<?php
require_once 'database.php';

// 使用 database.php 中定义的验证函数
$teacher = require_teacher_auth();

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? 'list';

/**
 * 获取作业列表
 * 规则：老师只能看到同科目老师布置的作业
 */
if ($action === 'list') {
    try {
        $studentName = $_GET['student_name'] ?? '';
        $dateFilter = $_GET['date'] ?? '';
        $statusFilter = $_GET['status'] ?? 'all';
        $sortOrder = $_GET['sort'] ?? 'desc';

        $currentSubject = $teacher['subject'];
        if (empty($currentSubject)) {
            echo json_encode(['status' => 'error', 'message' => '您的帐号未设置科目，无法获取作业列表']);
            exit;
        }

        $sql = "SELECT 
                    hs.Id AS submission_id,
                    hs.time AS submit_time,
                    h.title AS homework_title,
                    s.firstname, 
                    s.lastname,
                    hc.score,
                    hc.Id AS check_id
                FROM homeworksubmission hs
                JOIN homework h ON hs.homeworkid = h.Id
                JOIN teachers creator ON h.teacherid = creator.Id
                JOIN students s ON hs.studentid = s.Id
                LEFT JOIN homeworkcheck hc ON hs.Id = hc.submissionid
                WHERE creator.subject = :subject";

        $params = [':subject' => $currentSubject];

        if (!empty($studentName)) {
            $sql .= " AND (s.firstname LIKE :sname OR s.lastname LIKE :sname OR CONCAT(s.lastname, s.firstname) LIKE :sname)";
            $params[':sname'] = "%$studentName%";
        }

        if (!empty($dateFilter)) {
            $startTime = strtotime($dateFilter . ' 00:00:00');
            $endTime = strtotime($dateFilter . ' 23:59:59');
            $sql .= " AND hs.time BETWEEN :start AND :end";
            $params[':start'] = $startTime;
            $params[':end'] = $endTime;
        }

        if ($statusFilter === 'graded') {
            $sql .= " AND hc.Id IS NOT NULL";
        } elseif ($statusFilter === 'ungraded') {
            $sql .= " AND hc.Id IS NULL";
        }

        $order = ($sortOrder === 'asc') ? 'ASC' : 'DESC';
        $sql .= " ORDER BY hs.time $order";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $result = array_map(function ($row) {
            return [
                'id' => $row['submission_id'],
                'student_name' => $row['lastname'] . $row['firstname'],
                'homework_title' => $row['homework_title'],
                'submit_time' => date('Y-m-d H:i', $row['submit_time']),
                'is_graded' => !empty($row['check_id']),
                'score' => $row['score']
            ];
        }, $rows);

        echo json_encode(['status' => 'success', 'data' => $result]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => '资料库错误: ' . $e->getMessage()]);
    }
}

/**
 * 提交批改结果（包含画图图片）
 */
elseif ($action === 'submit_grade') {
    $input = json_decode(file_get_contents('php://input'), true);

    $submissionId = $input['submission_id'] ?? null;
    $score = $input['score'] ?? null;
    $content = $input['content'] ?? '';
    $checkImageBase64 = $input['check_image'] ?? null; // 接收前端的 Base64 数据

    if (!$submissionId || $score === null) {
        echo json_encode(['status' => 'error', 'message' => '参数不完整']);
        exit;
    }

    try {
        // 处理 Base64 图片数据转换为二进位 Blob
        $blobData = null;
        if ($checkImageBase64 && strpos($checkImageBase64, 'data:image/png;base64,') === 0) {
            $imgData = str_replace('data:image/png;base64,', '', $checkImageBase64);
            $imgData = str_replace(' ', '+', $imgData);
            $blobData = base64_decode($imgData);
        }

        // 检查是否已有批改记录
        $checkSql = "SELECT Id FROM homeworkcheck WHERE submissionid = ?";
        $stmt = $db->prepare($checkSql);
        $stmt->execute([$submissionId]);
        $existing = $stmt->fetch();

        $timestamp = time();

        if ($existing) {
            // 更新现有记录
            $updateSql = "UPDATE homeworkcheck 
                          SET teacherid = ?, score = ?, content = ?, check_image = ?, createtime = ? 
                          WHERE Id = ?";
            $stmt = $db->prepare($updateSql);
            // 使用 PDO::PARAM_LOB 处理二进位数据
            $stmt->bindParam(1, $teacher['Id']);
            $stmt->bindParam(2, $score);
            $stmt->bindParam(3, $content);
            $stmt->bindParam(4, $blobData, PDO::PARAM_LOB);
            $stmt->bindParam(5, $timestamp);
            $stmt->bindParam(6, $existing['Id']);
            $stmt->execute();
        } else {
            // 插入新记录
            $insertSql = "INSERT INTO homeworkcheck (submissionid, teacherid, score, content, check_image, createtime) 
                          VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $db->prepare($insertSql);
            $stmt->bindParam(1, $submissionId);
            $stmt->bindParam(2, $teacher['Id']);
            $stmt->bindParam(3, $score);
            $stmt->bindParam(4, $content);
            $stmt->bindParam(5, $blobData, PDO::PARAM_LOB);
            $stmt->bindParam(6, $timestamp);
            $stmt->execute();
        }

        echo json_encode(['status' => 'success', 'message' => '批改已保存']);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => '保存失败: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => '未知操作']);
}
