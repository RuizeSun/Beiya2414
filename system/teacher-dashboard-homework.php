<?php
// 设置 Content-Type 为 JSON
header('Content-Type: application/json; charset=utf-8');

// 引入数据库连线与验证脚本
// 假设 database.php 包含 $db (PDO物件), require_teacher_auth() 和 format_names()
require_once 'database.php';

/**
 * 辅助函数：输出 JSON 响应并终止脚本
 * @param array $data 响应数据
 * @param int $httpCode HTTP 状态码
 */
function send_json_response(array $data, int $httpCode = 200)
{
    http_response_code($httpCode);
    echo json_encode($data);
    exit;
}

// ----------------------------------------------------
// 1. 验证教师权限并取得教师 ID
// ----------------------------------------------------
try {
    // require_teacher_auth 会在验证失败时终止脚本并输出 HTML 错误
    $teacher = require_teacher_auth();
    $teacherId = $teacher['Id'];
} catch (Exception $e) {
    // 捕获任何潜在的例外，并以 JSON 形式回传错误 (虽然 require_teacher_auth 通常会在内部处理错误)
    send_json_response(['status' => 'error', 'message' => '验证失败或数据库连线错误。'], 500);
}


// 获取请求的动作
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// 确保请求是 POST 方式用于修改操作
if (in_array($action, ['create', 'update', 'delete']) && $method !== 'POST') {
    send_json_response(['status' => 'error', 'message' => '此操作必须使用 POST 方法。'], 405);
}

// 获取 POST 请求体数据
$input = [];
if ($method === 'POST') {
    $json = file_get_contents('php://input');
    $input = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        send_json_response(['status' => 'error', 'message' => '无效的 JSON 输入。'], 400);
    }
}


switch ($action) {
    // ----------------------------------------------------
    // 2. 布置作业 (Create Homework) - POST
    // ----------------------------------------------------
    case 'create':
        $title = $input['title'] ?? null;
        $description = $input['description'] ?? null;
        // 确保布林值是 0 或 1
        $isforallstudents = ($input['isforallstudents'] ?? 0) ? 1 : 0;
        $submit = ($input['submit'] ?? 0) ? 1 : 0;
        $stopTime = $input['stopTime'] ?? null;

        if (!$title || !$description || !$stopTime) {
            send_json_response(['status' => 'error', 'message' => '标题、描述和截止时间不能为空。'], 400);
        }

        // 截止时间转换为 UNIX 时间戳
        $stopTimestamp = strtotime($stopTime);
        if ($stopTimestamp === false) {
            send_json_response(['status' => 'error', 'message' => '截止时间格式无效。'], 400);
        }

        $releaseTime = time();

        $sql = "INSERT INTO homework (teacherid, isforallstudents, submit, releasetime, stoptime, description, title) 
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        try {
            $stmt = $db->prepare($sql);
            $stmt->execute([$teacherId, $isforallstudents, $submit, $releaseTime, $stopTimestamp, $description, $title]);
            send_json_response(['status' => 'success', 'message' => '作业布置成功。', 'id' => $db->lastInsertId()], 201);
        } catch (\PDOException $e) {
            send_json_response(['status' => 'error', 'message' => '数据库错误: ' . $e->getMessage()], 500);
        }
        break;

    // ----------------------------------------------------
    // 3. 删除作业 (Delete Homework) - POST
    // ----------------------------------------------------
    case 'delete':
        $homeworkId = $input['id'] ?? null;
        if (!$homeworkId) {
            send_json_response(['status' => 'error', 'message' => '作业 ID 缺失。'], 400);
        }

        try {
            // 开始事务，确保操作的原子性
            $db->beginTransaction();

            // 1. 删除相关的提交记录 (homeworksubmission)
            $stmt_sub = $db->prepare("DELETE FROM homeworksubmission WHERE homeworkid = ?");
            $stmt_sub->execute([$homeworkId]);

            // 2. 删除作业本身 (homework)，并确保只有该教师才能删除自己的作业
            $stmt_hw = $db->prepare("DELETE FROM homework WHERE Id = ? AND teacherid = ?");
            $stmt_hw->execute([$homeworkId, $teacherId]);

            // 提交事务
            $db->commit();

            if ($stmt_hw->rowCount() > 0) {
                send_json_response(['status' => 'success', 'message' => '作业及其所有提交记录已成功删除。']);
            } else {
                send_json_response(['status' => 'error', 'message' => '删除失败。作业不存在或您无权删除此作业。'], 403);
            }
        } catch (\PDOException $e) {
            // 发生错误时回滚事务
            $db->rollBack();
            send_json_response(['status' => 'error', 'message' => '删除作业时发生数据库错误: ' . $e->getMessage()], 500);
        }
        break;

    // ----------------------------------------------------
    // 4. 修改作业内容 (Update Homework) - POST
    // ----------------------------------------------------
    case 'update':
        $homeworkId = $input['id'] ?? null;
        $title = $input['title'] ?? null;
        $description = $input['description'] ?? null;
        $enddate = $input['stopTime'] ?? null;

        if (!$homeworkId || (!$title && !$description && !$enddate)) {
            send_json_response(['status' => 'error', 'message' => '作业 ID 和至少一个修改内容 (标题或描述) 不能为空。'], 400);
        }

        $updates = [];
        $params = [];
        if ($title) {
            $updates[] = "title = ?";
            $params[] = $title;
        }
        if ($description) {
            $updates[] = "description = ?";
            $params[] = $description;
        }
        if ($description) {
            $updates[] = "description = ?";
            $params[] = $description;
        }
        if ($enddate) {
            $updates[] = "stoptime = ?";
            $params[] = strtotime($enddate);
        }

        $params[] = $homeworkId;
        $params[] = $teacherId;

        $sql = "UPDATE homework SET " . implode(', ', $updates) . " WHERE Id = ? AND teacherid = ?";

        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            if ($stmt->rowCount() > 0) {
                send_json_response(['status' => 'success', 'message' => '作业内容已成功更新。']);
            } else {
                send_json_response(['status' => 'error', 'message' => '更新失败。作业不存在、您无权修改或内容无变化。'], 403);
            }
        } catch (\PDOException $e) {
            send_json_response(['status' => 'error', 'message' => '数据库错误: ' . $e->getMessage()], 500);
        }
        break;

    // ----------------------------------------------------
    // 5. 查看作业列表 (List Homework) - GET
    // ----------------------------------------------------
    case 'list':
        $sql = "SELECT Id, title, description, releasetime, stoptime, isforallstudents, submit 
                FROM homework 
                WHERE teacherid = ?
                ORDER BY releasetime DESC";

        try {
            $stmt = $db->prepare($sql);
            $stmt->execute([$teacherId]);
            $homeworkList = $stmt->fetchAll();

            // 格式化时间戳
            foreach ($homeworkList as &$hw) {
                $hw['releasetime_formatted'] = date('Y-m-d H:i:s', $hw['releasetime']);
                $hw['stoptime_formatted'] = date('Y-m-d H:i:s', $hw['stoptime']);
                // 检查是否逾期
                $hw['isExpired'] = $hw['stoptime'] < time();
            }

            send_json_response(['status' => 'success', 'data' => $homeworkList]);
        } catch (\PDOException $e) {
            send_json_response(['status' => 'error', 'message' => '数据库错误: ' . $e->getMessage()], 500);
        }
        break;

    // ----------------------------------------------------
    // 6. 查看提交状态 (List Submissions) - GET
    //    包含已提交和未提交名单
    // ----------------------------------------------------
    case 'submissions':
        $homeworkId = $_GET['homeworkId'] ?? null;
        if (!$homeworkId) {
            send_json_response(['status' => 'error', 'message' => '作业 ID 缺失。'], 400);
        }

        // 确保该作业存在且属于当前教师
        $stmt_check = $db->prepare("SELECT Id, title, submit FROM homework WHERE Id = ? AND teacherid = ?");
        $stmt_check->execute([$homeworkId, $teacherId]);
        $homework = $stmt_check->fetch();

        if (!$homework) {
            send_json_response(['status' => 'error', 'message' => '作业不存在或您无权查看。'], 403);
        }

        // 仅当 submit=1 时才检查提交记录
        if ($homework['submit'] != 1) {
            send_json_response(['status' => 'warning', 'message' => '此作业不要求平台提交。', 'data' => []]);
        }

        try {
            // 1. 获取所有学生 (简单起见，假设所有学生都应提交)
            // 在实际应用中，这里可能需要根据教师教授的班级来筛选学生
            $stmt_all_students = $db->prepare("SELECT Id, firstname, lastname FROM students ORDER BY Id ASC");
            $stmt_all_students->execute();
            // FIX: 使用 FETCH_ASSOC 而非 FETCH_KEY_PAIR，因为我们选取了 3 个字段
            $allStudents = $stmt_all_students->fetchAll(PDO::FETCH_ASSOC);

            // 格式化学生成为 Id => fullName
            $studentsMap = [];
            foreach ($allStudents as $student) {
                $studentId = $student['Id'];
                // 使用辅助函数格式化名称 (假设 database.php 引入了 format_names)
                $studentsMap[$studentId] = $student['lastname'] . $student['firstname'];
            }
            // ----------------------------------------------------

            // 2. 获取已提交的记录
            $sql_submissions = "SELECT Id, studentid, time, updatetime 
                                FROM homeworksubmission 
                                WHERE homeworkid = ?
                                ORDER BY updatetime DESC";

            $stmt_submissions = $db->prepare($sql_submissions);
            $stmt_submissions->execute([$homeworkId]);
            $submissions = $stmt_submissions->fetchAll();

            $submittedStudentIds = array_column($submissions, 'studentid');
            $submittedStudentIds = array_map('strval', $submittedStudentIds); // 确保类型一致

            $submissionData = [];
            $notSubmitted = $studentsMap; // 初始设定所有学生都未提交

            // 整理已提交的数据
            foreach ($submissions as $sub) {
                $studentId = $sub['studentid'];
                // 确保学生存在于名单中
                if (isset($studentsMap[$studentId])) {
                    $submissionData[] = [
                        'submissionId' => $sub['Id'],
                        'studentId' => $studentId,
                        'fullName' => $studentsMap[$studentId],
                        'status' => 'Submitted',
                        'time_formatted' => date('Y-m-d H:i:s', $sub['time']),
                        'updatetime_formatted' => date('Y-m-d H:i:s', $sub['updatetime']),
                    ];
                    // 从未提交名单中移除
                    unset($notSubmitted[$studentId]);
                }
            }

            // 整理未提交的数据
            foreach ($notSubmitted as $studentId => $fullName) {
                $submissionData[] = [
                    'submissionId' => null, // 无提交ID
                    'studentId' => $studentId,
                    'fullName' => $fullName,
                    'status' => 'Not Submitted',
                    'time_formatted' => '-',
                    'updatetime_formatted' => '-',
                ];
            }

            // 按学生ID排序 (可选)
            usort($submissionData, function ($a, $b) {
                return $a['studentId'] <=> $b['studentId'];
            });

            send_json_response([
                'status' => 'success',
                'title' => $homework['title'],
                'message' => '已成功获取所有学生的提交状态。',
                'data' => $submissionData
            ]);
        } catch (\PDOException $e) {
            send_json_response(['status' => 'error', 'message' => '数据库错误: ' . $e->getMessage()], 500);
        }
        break;

    // ----------------------------------------------------
    // 7. 查看学生提交的作业 (View Submission Content) - GET
    // ----------------------------------------------------
    case 'view_submission':
        $submissionId = $_GET['submissionId'] ?? null;
        if (!$submissionId) {
            send_json_response(['status' => 'error', 'message' => '提交 ID 缺失。'], 400);
        }

        // 获取提交记录并确认该作业属于当前教师
        $sql = "SELECT hs.submission, hs.studentid, hs.updatetime, h.title, s.firstname, s.lastname
                FROM homeworksubmission hs
                JOIN homework h ON hs.homeworkid = h.Id
                LEFT JOIN students s ON hs.studentid = s.Id
                WHERE hs.Id = ? AND h.teacherid = ?";

        try {
            $stmt = $db->prepare($sql);
            $stmt->execute([$submissionId, $teacherId]);
            $submission = $stmt->fetch();

            if (!$submission) {
                send_json_response(['status' => 'error', 'message' => '提交记录不存在或您无权查看。'], 403);
            }

            // 将 BLOB (图片二进制数据) 编码为 Base64 字符串
            $base64Image = base64_encode($submission['submission']);
            $fullName = $submission['lastname'] . $submission['firstname'];

            send_json_response([
                'status' => 'success',
                'image' => $base64Image,
                'title' => $submission['title'],
                'studentId' => $submission['studentid'],
                'fullName' => $fullName,
                'updateTime' => date('Y-m-d H:i:s', $submission['updatetime']),
                'message' => '已获取作业提交内容。'
            ]);
        } catch (\PDOException $e) {
            send_json_response(['status' => 'error', 'message' => '数据库错误: ' . $e->getMessage()], 500);
        }
        break;

    // ----------------------------------------------------
    // 8. 预设/错误处理
    // ----------------------------------------------------
    default:
        send_json_response(['status' => 'error', 'message' => '无效的 API 动作。'], 400);
        break;
}
