<?php
// 引入数据库连接和认证文件
require_once 'database.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// 强制要求大屏权限
require_screen_auth();

// 获取所有可能需要的参数
$action = $_GET['action'] ?? '';
$homeworkId = $_REQUEST['homework_id'] ?? null;
$studentId = $_REQUEST['student_id'] ?? null;
$submissionId = $_REQUEST['submission_id'] ?? null;
$viewType = $_REQUEST['type'] ?? 'student'; // 关键：获取查看类型（student/teacher）

// --- 图像处理函数 (保持不变) ---
function process_and_compress_image(string $image_data, $target_short_side = 720, $quality = 50): ?string
{
    try {
        $image = imagecreatefromstring($image_data);
        if ($image === false) return null;
        $width = imagesx($image);
        $height = imagesy($image);
        if ($width > $height) {
            $new_height = $target_short_side;
            $new_width = floor($width * ($target_short_side / $height));
        } else {
            $new_width = $target_short_side;
            $new_height = floor($height * ($target_short_side / $width));
        }
        $new_image = imagecreatetruecolor($new_width, $new_height);
        imagecopyresampled($new_image, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
        ob_start();
        imagejpeg($new_image, null, $quality);
        $compressed_data = ob_get_clean();
        imagedestroy($image);
        imagedestroy($new_image);
        return $compressed_data;
    } catch (\Throwable $e) {
        return null;
    }
}

// --- API 路由 ---
switch ($action) {
    case 'get_active_homeworks':
        handleGetActiveHomeworks($db);
        break;
    case 'get_students_submissions':
        handleGetStudentsSubmissions($db, $homeworkId);
        break;
    case 'view_submission':
        // 显式传入 $viewType
        handleViewSubmission($db, $submissionId, $viewType);
        break;
    case 'submit_homework':
        handleSubmitHomework($db, $homeworkId, $studentId);
        break;
    default:
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "无效的动作"]);
}
exit;

// --- API 处理器函数 ---

function handleGetActiveHomeworks($db)
{
    $currentTime = time();
    try {
        $stmt = $db->prepare("SELECT Id, title, description, stoptime FROM homework WHERE stoptime >= ? ORDER BY stoptime ASC");
        $stmt->execute([$currentTime]);
        $homeworks = $stmt->fetchAll();
        foreach ($homeworks as &$hw) {
            $hw['stoptime_formatted'] = date('Y-m-d H:i:s', $hw['stoptime']);
        }
        echo json_encode(["status" => "success", "data" => $homeworks]);
    } catch (\PDOException $e) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "查询失败"]);
    }
}

function handleGetStudentsSubmissions($db, $homeworkId)
{
    if (empty($homeworkId)) {
        echo json_encode(["status" => "error", "message" => "缺少作业ID"]);
        return;
    }
    try {
        $stmtStudents = $db->query("SELECT Id, firstname, lastname FROM students ORDER BY Id ASC");
        $students = $stmtStudents->fetchAll();

        $sql = "SELECT hs.studentid, hs.Id AS submissionId, hs.time, hs.updatetime, hc.Id AS checkId
                FROM homeworksubmission hs
                LEFT JOIN homeworkcheck hc ON hs.Id = hc.submissionid
                WHERE hs.homeworkid = ?";
        $stmtSubmissions = $db->prepare($sql);
        $stmtSubmissions->execute([$homeworkId]);
        $submissions = $stmtSubmissions->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);

        $results = [];
        foreach ($students as $s) {
            $sid = $s['Id'];
            $sub = $submissions[$sid] ?? null;
            $results[] = [
                'Id' => $sid,
                'fullName' => $s['lastname'] . $s['firstname'],
                'submitted' => (bool)$sub,
                'graded' => $sub && !empty($sub['checkId']),
                'submissionId' => $sub['submissionId'] ?? null,
                'updateTime' => $sub ? date('Y-m-d H:i:s', $sub['updatetime']) : null,
            ];
        }
        echo json_encode(["status" => "success", "data" => $results]);
    } catch (\PDOException $e) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
}

function handleViewSubmission($db, $submissionId, $type)
{
    if (empty($submissionId)) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "缺少提交ID"]);
        return;
    }

    try {
        if ($type === 'teacher') {
            // 获取批改图
            $stmt = $db->prepare("SELECT check_image FROM homeworkcheck WHERE submissionid = ?");
            $stmt->execute([$submissionId]);
            $blob = $stmt->fetchColumn();

            if (!$blob) {
                // 如果没有批改图，返回透明像素
                header("Content-Type: image/png");
                echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAACklEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
                exit;
            }
            header("Content-Type: image/png");
            echo $blob;
        } else {
            // 获取学生原图 (注意：这里要确保字段名是 submission 而不是 submission_image)
            $stmt = $db->prepare("SELECT submission FROM homeworksubmission WHERE Id = ?");
            $stmt->execute([$submissionId]);
            $blob = $stmt->fetchColumn();

            if ($blob) {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->buffer($blob);
                header("Content-Type: " . $mimeType);
                echo $blob;
            } else {
                // 关键点：如果数据库里对应的 Id 确实没有 blob 数据，就会报错
                http_response_code(404);
                header('Content-Type: application/json');
                echo json_encode(["status" => "error", "message" => "找不到提交记录", "debug_id" => $submissionId]);
            }
        }
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
}

function handleSubmitHomework($db, $homeworkId, $studentId)
{
    if (empty($homeworkId) || empty($studentId) || !isset($_FILES['submission_file'])) {
        echo json_encode(["status" => "error", "message" => "参数缺失"]);
        return;
    }
    $fileData = file_get_contents($_FILES['submission_file']['tmp_name']);
    $finalData = process_and_compress_image($fileData);
    $now = time();

    try {
        $stmt = $db->prepare("SELECT Id FROM homeworksubmission WHERE homeworkid = ? AND studentid = ?");
        $stmt->execute([$homeworkId, $studentId]);
        $exist = $stmt->fetch();

        if ($exist) {
            $sql = "UPDATE homeworksubmission SET submission = ?, updatetime = ? WHERE Id = ?";
            $st = $db->prepare($sql);
            $st->bindParam(1, $finalData, PDO::PARAM_LOB);
            $st->bindParam(2, $now);
            $st->bindParam(3, $exist['Id']);
            $st->execute();
        } else {
            $sql = "INSERT INTO homeworksubmission (studentid, homeworkid, submission, time, updatetime) VALUES (?, ?, ?, ?, ?)";
            $st = $db->prepare($sql);
            $st->bindParam(1, $studentId);
            $st->bindParam(2, $homeworkId);
            $st->bindParam(3, $finalData, PDO::PARAM_LOB);
            $st->bindParam(4, $now);
            $st->bindParam(5, $now);
            $st->execute();
        }
        echo json_encode(["status" => "success", "message" => "提交成功"]);
    } catch (\Exception $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
}
