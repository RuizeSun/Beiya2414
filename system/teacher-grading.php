<?php

/**
 * teacher-grading.php
 * 教师批改作业后端接口 - 支援多模态 AI 图片传输与权限优化
 */

require_once 'database.php';
require_once 'aiprovider.php';

header('Content-Type: application/json; charset=utf-8');

// 因为原 database.php 中的 require_teacher_auth 会直接 echo HTML 并 exit
// 在 API 环境下，我们改用 check_teacher_auth 以便回传 JSON
$teacher = check_teacher_auth();
if (!$teacher) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => '登入已过期或未授权，请重新登入']);
    exit;
}

$aiService = new AIService($db);
$action = $_GET['action'] ?? 'list';

/**
 * 动作 1: 获取作业提交列表
 */
if ($action === 'list') {
    try {
        $studentName = $_GET['student_name'] ?? '';
        $dateFilter = $_GET['date'] ?? '';
        $statusFilter = $_GET['status'] ?? 'all';

        $currentSubject = $teacher['subject'] ?? '';
        if (empty($currentSubject)) {
            echo json_encode(['status' => 'error', 'message' => '您的帐号未设置科目，无法获取作业清单']);
            exit;
        }

        $sql = "SELECT 
                    hs.Id AS submission_id,
                    hs.time AS submit_time,
                    hs.submission AS student_image, 
                    h.title AS homework_title,
                    s.firstname, 
                    s.lastname,
                    hc.score,
                    hc.content AS teacher_comment
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

        $sql .= " ORDER BY hs.time DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = array_map(function ($row) {
            $imageBase64 = '';
            if (!empty($row['student_image'])) {
                $imageBase64 = 'data:image/jpeg;base64,' . base64_encode($row['student_image']);
            }
            return [
                'submission_id' => (int)$row['submission_id'],
                'student_name' => $row['lastname'] . $row['firstname'],
                'homework_title' => $row['homework_title'],
                'submit_time' => date('Y-m-d H:i', (int)$row['submit_time']),
                'student_image' => $imageBase64,
                'score' => $row['score'],
                'comment' => $row['teacher_comment'] ?? ''
            ];
        }, $rows);

        echo json_encode(['status' => 'success', 'data' => $result]);
    } catch (PDOException $e) {
        error_log("Database Error in list: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => '资料库连线失败']);
    }
}

/**
 * 动作 2: 获取可用 AI 模型
 */
elseif ($action === 'get_ai_models') {
    try {
        $models = $aiService->getAvailableModels($teacher['Id']);
        echo json_encode(['status' => 'success', 'data' => $models]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
}

/**
 * 动作 3: AI 批改作业
 */
elseif ($action === 'ai_grade') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo json_encode(['status' => 'error', 'message' => 'JSON 解析错误']);
        exit;
    }

    $submissionId = $input['submission_id'] ?? null;
    $modelAlias = $input['model_alias'] ?? null;
    $prompt = $input['prompt'] ?? '';
    $studentImage = $input['student_image'] ?? null;

    if (!$submissionId || !$modelAlias) {
        echo json_encode(['status' => 'error', 'message' => '缺少必要参数 (submission_id 或 model_alias)']);
        exit;
    }

    try {
        // 呼叫 AIService 的 askAI，确保传入图片 Base64
        $aiResult = $aiService->askAI($teacher['Id'], $modelAlias, $prompt, $studentImage);

        if ($aiResult['success']) {
            // content 应该是 AI 回传的纯文字内容 (已在 aiprovider.php 中清理过 markdown)
            echo json_encode(['status' => 'success', 'data' => $aiResult['content']]);
        } else {
            echo json_encode(['status' => 'error', 'message' => $aiResult['error']]);
        }
    } catch (Exception $e) {
        error_log("AI Grade Exception: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'AI 服务异常: ' . $e->getMessage()]);
    }
}

/**
 * 动作 4: 储存批改结果
 */
elseif ($action === 'submit_grade') {
    $input = json_decode(file_get_contents('php://input'), true);
    $submissionId = $input['submission_id'] ?? null;
    $score = $input['score'] ?? 0;
    $content = $input['content'] ?? '';
    $checkImageBase64 = $input['check_image'] ?? null;

    try {
        $blobData = null;
        if ($checkImageBase64 && strpos($checkImageBase64, 'base64,') !== false) {
            $blobData = base64_decode(explode(',', $checkImageBase64)[1]);
        }

        $stmt = $db->prepare("SELECT Id FROM homeworkcheck WHERE submissionid = ?");
        $stmt->execute([$submissionId]);
        $existing = $stmt->fetch();
        $now = time();

        if ($existing) {
            $sql = "UPDATE homeworkcheck SET teacherid=?, score=?, content=?, check_image=?, createtime=? WHERE Id=?";
            $stmt = $db->prepare($sql);
            $stmt->execute([$teacher['Id'], $score, $content, $blobData, $now, $existing['Id']]);
        } else {
            $sql = "INSERT INTO homeworkcheck (submissionid, teacherid, score, content, check_image, createtime) VALUES (?,?,?,?,?,?)";
            $stmt = $db->prepare($sql);
            $stmt->execute([$submissionId, $teacher['Id'], $score, $content, $blobData, $now]);
        }
        echo json_encode(['status' => 'success', 'message' => '批改已储存']);
    } catch (PDOException $e) {
        error_log("Save Grade Error: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => '储存批改失败']);
    }
}
