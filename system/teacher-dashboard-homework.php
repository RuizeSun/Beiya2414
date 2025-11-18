<?php
// 設置 Content-Type 為 JSON
header('Content-Type: application/json; charset=utf-8');

// 引入資料庫連線與驗證腳本
// 假設 database.php 包含 $db (PDO物件), require_teacher_auth() 和 format_names()
require_once 'database.php';

/**
 * 輔助函數：輸出 JSON 響應並終止腳本
 * @param array $data 響應數據
 * @param int $httpCode HTTP 狀態碼
 */
function send_json_response(array $data, int $httpCode = 200)
{
    http_response_code($httpCode);
    echo json_encode($data);
    exit;
}

// ----------------------------------------------------
// 1. 驗證教師權限並取得教師 ID
// ----------------------------------------------------
try {
    // require_teacher_auth 會在驗證失敗時終止腳本並輸出 HTML 錯誤
    $teacher = require_teacher_auth();
    $teacherId = $teacher['Id'];
} catch (Exception $e) {
    // 捕獲任何潛在的例外，並以 JSON 形式回傳錯誤 (雖然 require_teacher_auth 通常會在內部處理錯誤)
    send_json_response(['status' => 'error', 'message' => '驗證失敗或資料庫連線錯誤。'], 500);
}


// 獲取請求的動作
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// 確保請求是 POST 方式用於修改操作
if (in_array($action, ['create', 'update', 'delete']) && $method !== 'POST') {
    send_json_response(['status' => 'error', 'message' => '此操作必須使用 POST 方法。'], 405);
}

// 獲取 POST 請求體數據
$input = [];
if ($method === 'POST') {
    $json = file_get_contents('php://input');
    $input = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        send_json_response(['status' => 'error', 'message' => '無效的 JSON 輸入。'], 400);
    }
}


switch ($action) {
    // ----------------------------------------------------
    // 2. 佈置作業 (Create Homework) - POST
    // ----------------------------------------------------
    case 'create':
        $title = $input['title'] ?? null;
        $description = $input['description'] ?? null;
        // 確保布林值是 0 或 1
        $isforallstudents = ($input['isforallstudents'] ?? 0) ? 1 : 0;
        $submit = ($input['submit'] ?? 0) ? 1 : 0;
        $stopTime = $input['stopTime'] ?? null;

        if (!$title || !$description || !$stopTime) {
            send_json_response(['status' => 'error', 'message' => '標題、描述和截止時間不能為空。'], 400);
        }

        // 截止時間轉換為 UNIX 時間戳
        $stopTimestamp = strtotime($stopTime);
        if ($stopTimestamp === false) {
            send_json_response(['status' => 'error', 'message' => '截止時間格式無效。'], 400);
        }

        $releaseTime = time();

        $sql = "INSERT INTO homework (teacherid, isforallstudents, submit, releasetime, stoptime, description, title) 
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        try {
            $stmt = $db->prepare($sql);
            $stmt->execute([$teacherId, $isforallstudents, $submit, $releaseTime, $stopTimestamp, $description, $title]);
            send_json_response(['status' => 'success', 'message' => '作業佈置成功。', 'id' => $db->lastInsertId()], 201);
        } catch (\PDOException $e) {
            send_json_response(['status' => 'error', 'message' => '資料庫錯誤: ' . $e->getMessage()], 500);
        }
        break;

    // ----------------------------------------------------
    // 3. 刪除作業 (Delete Homework) - POST
    // ----------------------------------------------------
    case 'delete':
        $homeworkId = $input['id'] ?? null;
        if (!$homeworkId) {
            send_json_response(['status' => 'error', 'message' => '作業 ID 缺失。'], 400);
        }

        try {
            // 開始事務，確保操作的原子性
            $db->beginTransaction();

            // 1. 刪除相關的提交記錄 (homeworksubmission)
            $stmt_sub = $db->prepare("DELETE FROM homeworksubmission WHERE homeworkid = ?");
            $stmt_sub->execute([$homeworkId]);

            // 2. 刪除作業本身 (homework)，並確保只有該教師才能刪除自己的作業
            $stmt_hw = $db->prepare("DELETE FROM homework WHERE Id = ? AND teacherid = ?");
            $stmt_hw->execute([$homeworkId, $teacherId]);

            // 提交事務
            $db->commit();

            if ($stmt_hw->rowCount() > 0) {
                send_json_response(['status' => 'success', 'message' => '作業及其所有提交記錄已成功刪除。']);
            } else {
                send_json_response(['status' => 'error', 'message' => '刪除失敗。作業不存在或您無權刪除此作業。'], 403);
            }
        } catch (\PDOException $e) {
            // 發生錯誤時回滾事務
            $db->rollBack();
            send_json_response(['status' => 'error', 'message' => '刪除作業時發生資料庫錯誤: ' . $e->getMessage()], 500);
        }
        break;

    // ----------------------------------------------------
    // 4. 修改作業內容 (Update Homework) - POST
    // ----------------------------------------------------
    case 'update':
        $homeworkId = $input['id'] ?? null;
        $title = $input['title'] ?? null;
        $description = $input['description'] ?? null;
        $enddate = $input['stopTime'] ?? null;

        if (!$homeworkId || (!$title && !$description && !$enddate)) {
            send_json_response(['status' => 'error', 'message' => '作業 ID 和至少一個修改內容 (標題或描述) 不能為空。'], 400);
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
                send_json_response(['status' => 'success', 'message' => '作業內容已成功更新。']);
            } else {
                send_json_response(['status' => 'error', 'message' => '更新失敗。作業不存在、您無權修改或內容無變化。'], 403);
            }
        } catch (\PDOException $e) {
            send_json_response(['status' => 'error', 'message' => '資料庫錯誤: ' . $e->getMessage()], 500);
        }
        break;

    // ----------------------------------------------------
    // 5. 查看作業列表 (List Homework) - GET
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

            // 格式化時間戳
            foreach ($homeworkList as &$hw) {
                $hw['releasetime_formatted'] = date('Y-m-d H:i:s', $hw['releasetime']);
                $hw['stoptime_formatted'] = date('Y-m-d H:i:s', $hw['stoptime']);
                // 檢查是否逾期
                $hw['isExpired'] = $hw['stoptime'] < time();
            }

            send_json_response(['status' => 'success', 'data' => $homeworkList]);
        } catch (\PDOException $e) {
            send_json_response(['status' => 'error', 'message' => '資料庫錯誤: ' . $e->getMessage()], 500);
        }
        break;

    // ----------------------------------------------------
    // 6. 查看提交狀態 (List Submissions) - GET
    //    包含已提交和未提交名單
    // ----------------------------------------------------
    case 'submissions':
        $homeworkId = $_GET['homeworkId'] ?? null;
        if (!$homeworkId) {
            send_json_response(['status' => 'error', 'message' => '作業 ID 缺失。'], 400);
        }

        // 確保該作業存在且屬於當前教師
        $stmt_check = $db->prepare("SELECT Id, title, submit FROM homework WHERE Id = ? AND teacherid = ?");
        $stmt_check->execute([$homeworkId, $teacherId]);
        $homework = $stmt_check->fetch();

        if (!$homework) {
            send_json_response(['status' => 'error', 'message' => '作業不存在或您無權查看。'], 403);
        }

        // 僅當 submit=1 時才檢查提交記錄
        if ($homework['submit'] != 1) {
            send_json_response(['status' => 'warning', 'message' => '此作業不要求平台提交。', 'data' => []]);
        }

        try {
            // 1. 獲取所有學生 (簡單起見，假設所有學生都應提交)
            // 在實際應用中，這裡可能需要根據教師教授的班級來篩選學生
            $stmt_all_students = $db->prepare("SELECT Id, firstname, lastname FROM students ORDER BY Id ASC");
            $stmt_all_students->execute();
            // FIX: 使用 FETCH_ASSOC 而非 FETCH_KEY_PAIR，因為我們選取了 3 個欄位
            $allStudents = $stmt_all_students->fetchAll(PDO::FETCH_ASSOC);

            // 格式化學生成為 Id => fullName
            $studentsMap = [];
            foreach ($allStudents as $student) {
                $studentId = $student['Id'];
                // 使用輔助函數格式化名稱 (假設 database.php 引入了 format_names)
                $studentsMap[$studentId] = $student['lastname'] . $student['firstname'];
            }
            // ----------------------------------------------------

            // 2. 獲取已提交的記錄
            $sql_submissions = "SELECT Id, studentid, time, updatetime 
                                FROM homeworksubmission 
                                WHERE homeworkid = ?
                                ORDER BY updatetime DESC";

            $stmt_submissions = $db->prepare($sql_submissions);
            $stmt_submissions->execute([$homeworkId]);
            $submissions = $stmt_submissions->fetchAll();

            $submittedStudentIds = array_column($submissions, 'studentid');
            $submittedStudentIds = array_map('strval', $submittedStudentIds); // 確保類型一致

            $submissionData = [];
            $notSubmitted = $studentsMap; // 初始設定所有學生都未提交

            // 整理已提交的數據
            foreach ($submissions as $sub) {
                $studentId = $sub['studentid'];
                // 確保學生存在於名單中
                if (isset($studentsMap[$studentId])) {
                    $submissionData[] = [
                        'submissionId' => $sub['Id'],
                        'studentId' => $studentId,
                        'fullName' => $studentsMap[$studentId],
                        'status' => 'Submitted',
                        'time_formatted' => date('Y-m-d H:i:s', $sub['time']),
                        'updatetime_formatted' => date('Y-m-d H:i:s', $sub['updatetime']),
                    ];
                    // 從未提交名單中移除
                    unset($notSubmitted[$studentId]);
                }
            }

            // 整理未提交的數據
            foreach ($notSubmitted as $studentId => $fullName) {
                $submissionData[] = [
                    'submissionId' => null, // 無提交ID
                    'studentId' => $studentId,
                    'fullName' => $fullName,
                    'status' => 'Not Submitted',
                    'time_formatted' => '-',
                    'updatetime_formatted' => '-',
                ];
            }

            // 按學生ID排序 (可選)
            usort($submissionData, function ($a, $b) {
                return $a['studentId'] <=> $b['studentId'];
            });

            send_json_response([
                'status' => 'success',
                'title' => $homework['title'],
                'message' => '已成功獲取所有學生的提交狀態。',
                'data' => $submissionData
            ]);
        } catch (\PDOException $e) {
            send_json_response(['status' => 'error', 'message' => '資料庫錯誤: ' . $e->getMessage()], 500);
        }
        break;

    // ----------------------------------------------------
    // 7. 查看學生提交的作業 (View Submission Content) - GET
    // ----------------------------------------------------
    case 'view_submission':
        $submissionId = $_GET['submissionId'] ?? null;
        if (!$submissionId) {
            send_json_response(['status' => 'error', 'message' => '提交 ID 缺失。'], 400);
        }

        // 獲取提交記錄並確認該作業屬於當前教師
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
                send_json_response(['status' => 'error', 'message' => '提交記錄不存在或您無權查看。'], 403);
            }

            // 將 BLOB (圖片二進位數據) 編碼為 Base64 字符串
            $base64Image = base64_encode($submission['submission']);
            $fullName = $submission['lastname'] . $submission['firstname'];

            send_json_response([
                'status' => 'success',
                'image' => $base64Image,
                'title' => $submission['title'],
                'studentId' => $submission['studentid'],
                'fullName' => $fullName,
                'updateTime' => date('Y-m-d H:i:s', $submission['updatetime']),
                'message' => '已獲取作業提交內容。'
            ]);
        } catch (\PDOException $e) {
            send_json_response(['status' => 'error', 'message' => '資料庫錯誤: ' . $e->getMessage()], 500);
        }
        break;

    // ----------------------------------------------------
    // 8. 預設/錯誤處理
    // ----------------------------------------------------
    default:
        send_json_response(['status' => 'error', 'message' => '無效的 API 動作。'], 400);
        break;
}
