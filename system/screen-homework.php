<?php
// 引入数据库连接和认证文件
require_once 'database.php';

// 设置响应头为 JSON 格式，并允许跨域（如果前端在不同端口或域名）
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// 强制要求大屏权限
require_screen_auth();

$action = $_GET['action'] ?? '';
$homeworkId = $_REQUEST['homework_id'] ?? null;
$studentId = $_REQUEST['student_id'] ?? null;
$submissionId = $_REQUEST['submission_id'] ?? null;
global $action, $homeworkId, $studentId, $submissionId;
// --- 图像处理函数 ---

/**
 * 将图像数据压缩和缩放至短边为 720px，质量 30% 的 JPEG 格式。
 * 此函数用于后端对前端传输过来的数据进行最终格式化和校验。
 * @param string $image_data 原始图像的二进制数据
 * @param int $target_short_side 目标短边长度
 * @param int $quality JPEG 质量 (0-100)
 * @return string 压缩后的 JPEG 图像二进制数据
 */
function process_and_compress_image(string $image_data, $target_short_side = 720, $quality = 20): ?string
{
    try {
        // 使用 GD 库从字符串创建图像
        $image = imagecreatefromstring($image_data);
        if ($image === false) {
            return null; // 无法识别的图像格式
        }

        $width = imagesx($image);
        $height = imagesy($image);

        // 计算新的尺寸
        if ($width > $height) {
            // 横向图像，短边是高度
            $new_height = $target_short_side;
            $new_width = floor($width * ($target_short_side / $height));
        } else {
            // 纵向或方形图像，短边是宽度
            $new_width = $target_short_side;
            $new_height = floor($height * ($target_short_side / $width));
        }

        // 创建新的真彩色图像
        $new_image = imagecreatetruecolor($new_width, $new_height);

        // 缩放图像
        imagecopyresampled($new_image, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);

        // 将图像数据捕获到输出缓冲区
        ob_start();
        imagejpeg($new_image, null, $quality);
        $compressed_data = ob_get_clean();

        // 释放内存
        imagedestroy($image);
        imagedestroy($new_image);

        return $compressed_data;
    } catch (\Throwable $e) {
        // 记录错误或返回 null
        error_log("圖像處理錯誤: " . $e->getMessage());
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
        handleViewSubmission($db, $submissionId);
        break;

    case 'submit_homework':
        handleSubmitHomework($db, $homeworkId, $studentId);
        break;

    default:
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "無效的動作"]);
}

exit;


// --- API 處理器函數 (部分省略，僅展示更新的 handleSubmitHomework) ---

function handleGetActiveHomeworks($db)
{
    $currentTime = time();
    try {
        // 查詢截止時間 >= 當前時間的作業
        $stmt = $db->prepare("SELECT Id, title, description, stoptime FROM homework WHERE stoptime >= ? ORDER BY stoptime ASC");
        $stmt->execute([$currentTime]);
        $homeworks = $stmt->fetchAll();

        // 格式化截止時間
        foreach ($homeworks as &$hw) {
            $hw['stoptime_formatted'] = date('Y-m-d H:i:s', $hw['stoptime']);
        }

        echo json_encode(["status" => "success", "data" => $homeworks]);
    } catch (\PDOException $e) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "查詢作業失敗: " . $e->getMessage()]);
    }
}

function handleGetStudentsSubmissions($db, $homeworkId)
{
    if (empty($homeworkId)) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "缺少作業 ID"]);
        return;
    }

    try {
        // 1. 獲取所有學生
        $stmtStudents = $db->query("SELECT Id, firstname, lastname FROM students ORDER BY Id ASC");
        $students = format_names($stmtStudents->fetchAll());

        // 2. 獲取該作業的所有提交記錄
        $stmtSubmissions = $db->prepare("SELECT studentid, Id AS submissionId, time, updatetime FROM homeworksubmission WHERE homeworkid = ?");
        $stmtSubmissions->execute([$homeworkId]);
        $submissions = $stmtSubmissions->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_UNIQUE);

        // 3. 合併數據
        $results = [];
        foreach ($students as $student) {
            $studentId = $student['Id'];
            $submission = $submissions[$studentId] ?? null;

            $results[] = [
                'Id' => $studentId,
                'fullName' => $student['fullName'],
                'submitted' => (bool)$submission,
                'submissionId' => $submission['submissionId'] ?? null,
                'submissionTime' => isset($submission['time']) ? date('Y-m-d H:i:s', $submission['time']) : null,
                'updateTime' => isset($submission['updatetime']) ? date('Y-m-d H:i:s', $submission['updatetime']) : null,
            ];
        }

        echo json_encode(["status" => "success", "data" => $results]);
    } catch (\PDOException $e) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "查詢提交情況失敗: " . $e->getMessage()]);
    }
}

function handleViewSubmission($db, $submissionId)
{
    if (empty($submissionId)) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "缺少提交 ID"]);
        return;
    }

    try {
        $stmt = $db->prepare("SELECT submission FROM homeworksubmission WHERE Id = ?");
        $stmt->execute([$submissionId]);
        $submission = $stmt->fetchColumn();

        if ($submission) {
            // 直接輸出圖像
            header('Content-Type: image/jpeg');
            http_response_code(200);
            echo $submission;
        } else {
            http_response_code(404);
            // 只有在找不到圖像時，才輸出 JSON 錯誤
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(["status" => "error", "message" => "找不到提交記錄"]);
        }
    } catch (\PDOException $e) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(["status" => "error", "message" => "查看提交失敗: " . $e->getMessage()]);
    }
}

/**
 * 接收前端已壓縮的圖片，進行後端最終壓縮和驗證。
 */
function handleSubmitHomework($db, $homeworkId, $studentId)
{
    // 檢查參數和文件
    if (empty($homeworkId) || empty($studentId) || !isset($_FILES['submission_file'])) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "缺少必要的參數或文件"]);
        return;
    }

    $file = $_FILES['submission_file'];
    $fileData = file_get_contents($file['tmp_name']);
    $currentTime = time();

    // 1. 檢驗是否為圖像（通過 MIME 類型）
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_buffer($finfo, $fileData);
    finfo_close($finfo);

    // PHP 7.x 兼容性修复：使用 strncmp 替换 str_starts_with
    if (strncmp($mimeType, 'image/', strlen('image/')) !== 0) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "上傳的文件不是有效的圖像。"]);
        return;
    }

    // 2. 後端執行最終壓縮（即原流程中的“第二次壓縮”）
    // 確保格式和質量符合要求
    $finalCompressedData = process_and_compress_image($fileData);
    if ($finalCompressedData === null) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "後端圖像處理失敗 (無法識別的格式或處理錯誤)。"]);
        return;
    }

    try {
        // 檢查是否已存在提交記錄 (更新或插入)
        $stmtCheck = $db->prepare("SELECT Id, time FROM homeworksubmission WHERE homeworkid = ? AND studentid = ?");
        $stmtCheck->execute([$homeworkId, $studentId]);
        $existingSubmission = $stmtCheck->fetch();

        if ($existingSubmission) {
            // 更新現有記錄
            $stmtUpdate = $db->prepare("UPDATE homeworksubmission SET submission = ?, updatetime = ? WHERE Id = ?");
            // 注意：PDO::PARAM_LOB 应该绑定实际的数据变量
            $stmtUpdate->bindParam(1, $finalCompressedData, PDO::PARAM_LOB);
            $stmtUpdate->bindParam(2, $currentTime); // updatetime
            $stmtUpdate->bindParam(3, $existingSubmission['Id']); // Id
            $stmtUpdate->execute(); // 不需要额外的参数数组
            $message = "作業更新成功！";
        } else {
            // 插入新記錄
            // INSERT INTO homeworksubmission (studentid, homeworkid, submission, time, updatetime) VALUES (?, ?, ?, ?, ?)
            $stmtInsert = $db->prepare("INSERT INTO homeworksubmission (studentid, homeworkid, submission, time, updatetime) VALUES (?, ?, ?, ?, ?)");
            $stmtInsert->bindParam(1, $studentId);
            $stmtInsert->bindParam(2, $homeworkId);
            $stmtInsert->bindParam(3, $finalCompressedData, PDO::PARAM_LOB);
            $stmtInsert->bindParam(4, $currentTime);
            $stmtInsert->bindParam(5, $currentTime);
            $stmtInsert->execute(); // 不需要额外的参数数组
            $message = "作業提交成功！";
        }

        echo json_encode(["status" => "success", "message" => $message]);
    } catch (\PDOException $e) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "數據庫操作失敗: " . $e->getMessage()]);
    }
}
