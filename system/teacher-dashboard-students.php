<?
// 設定內容類型為 JSON
header('Content-Type: application/json; charset=utf-8');
// 引入資料庫連線和認證函式庫
require_once './database.php';
// 獲取 action 參數
$action = $_GET['action'] ?? '';

// 只有在處理 POST 請求時才解析輸入
$input = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $json = file_get_contents('php://input');
    $input = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "無效的 JSON 輸入"]);
        exit();
    }
}

// 路由處理
switch ($action) {
    case 'checkAuth':
        handleCheckAuth();
        break;
    case 'getStudents':
        handleGetStudents();
        break;
    case 'getScoreTypes':
        handleGetScoreTypes();
        break;
    case 'updateScore':
        handleUpdateScore($input);
        break;
    case 'getTeacherLogs':
        handleGetTeacherLogs();
        break;
    case 'undoLog':
        handleUndoLog($input);
        break;
    default:
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "無效的 API 動作"]);
        break;
}


/**
 * 處理驗證檢查
 */
function handleCheckAuth()
{
    $teacher = check_teacher_auth();
    if ($teacher) {
        echo json_encode([
            "status" => "success",
            "isLoggedIn" => true,
            "teacherId" => $teacher['Id'],
            "isAdmin" => $teacher['isAdmin']
        ]);
    } else {
        echo json_encode(["status" => "success", "isLoggedIn" => false]);
    }
}


/**
 * 處理獲取學生列表
 */
function handleGetStudents()
{
    global $db;
    require_teacher_auth(); // 驗證教師身份

    $sort = $_GET['sort'] ?? 'Id'; // 預設排序為學號
    $allowedSorts = ['Id', 'groupId', 'score'];

    if (!in_array($sort, $allowedSorts)) {
        $sort = 'Id';
    }

    // --- ⬇️ 修改的部分 ⬇️ ---
    // 检查排序字段是否为 'score'，如果是，则使用 DESC (降序)，否则使用 ASC (升序)。
    $sortDirection = ($sort === 'score') ? 'DESC' : 'ASC';

    // 组合 SQL 语句
    $sql = "SELECT s.Id, s.firstname, s.lastname, s.score, g.groupName 
            FROM students s
            LEFT JOIN `groups` g ON s.groupId = g.Id
            ORDER BY s." . $sort . " " . $sortDirection;
    // --- ⬆️ 修改的部分 ⬆️ ---

    try {
        $stmt = $db->query($sql);
        $students = $stmt->fetchAll();
        // 假设 format_names() 函数可以处理学生数据
        $students = format_names($students);

        echo json_encode(["status" => "success", "students" => $students]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "查詢學生資料失敗: " . $e->getMessage()]);
    }
}

/**
 * 處理獲取量化評分變動類型
 */
function handleGetScoreTypes()
{
    global $db;
    require_teacher_auth();

    try {
        $stmt = $db->query("SELECT Id,name,`change` FROM scorechangetype");
        $types = $stmt->fetchAll();
        echo json_encode(["status" => "success", "scoreTypes" => $types]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "查詢評分類型失敗: " . $e->getMessage()]);
    }
}


/**
 * 處理學生量化評分加分或減分
 * @param array $input POST 資料
 */
function handleUpdateScore(array $input)
{
    global $db;
    $teacher = require_teacher_auth();
    $teacherId = $teacher['Id'];
    $timestamp = time();

    // 參數驗證
    $studentId = $input['studentId'] ?? null;
    $changeAmount = $input['changeAmount'] ?? null;
    $reasonId = $input['reasonId'] ?? null;
    $customReason = $input['customReason'] ?? null;

    if (!$studentId || $reasonId === null) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "缺少學生ID或變動量"]);
        exit();
    }

    $reason = '';
    $finalChange = (float)$changeAmount;

    try {
        $db->beginTransaction(); // 開始事務

        // 1. 處理原因和變動量
        if ($reasonId && $reasonId !== 'custom') {
            // 使用預設變動項
            $stmt = $db->prepare("SELECT name, `change` FROM scorechangetype WHERE Id = ?");
            $stmt->execute([$reasonId]);
            $type = $stmt->fetch();

            if (!$type) {
                $db->rollBack();
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "無效的變動項ID"]);
                exit();
            }
            $reason = $reasonId; // 記錄 scorechangetype Id
            $finalChange = (float)$type['change']; // 使用預設變動量
        } else if ($reasonId === 'custom') {
            // 自定義變動項
            if (!$customReason) {
                $db->rollBack();
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "自定義原因不能為空"]);
                exit();
            }
            // 記錄為 custom-<原因>
            $reason = "custom-" . $customReason;
            // $finalChange 已經從 $input['changeAmount'] 取得
        } else {
            // 如果沒有提供任何原因，視為無效
            $db->rollBack();
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "必須提供變動原因"]);
            exit();
        }

        // 2. 更新 students 表中的 score
        $stmt = $db->prepare("UPDATE students SET score = score + ? WHERE Id = ?");
        $stmt->execute([$finalChange, $studentId]);

        if ($stmt->rowCount() === 0) {
            $db->rollBack();
            http_response_code(404);
            echo json_encode(["status" => "error", "message" => "找不到學生，或分數無變動"]);
            exit();
        }

        // 3. 寫入 scorechangelog
        $stmt = $db->prepare("INSERT INTO scorechangelog (teacherid, reason, `change`, timestamp, studentid) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$teacherId, $reason, $finalChange, $timestamp, $studentId]);

        $db->commit(); // 提交事務

        echo json_encode([
            "status" => "success",
            "message" => "評分更新成功",
            "change" => $finalChange
        ]);
    } catch (PDOException $e) {
        $db->rollBack(); // 發生錯誤時回滾
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "評分更新失敗: " . $e->getMessage()]);
    }
}

/**
 * 處理查看教師自己對學生的加減分紀錄
 */
function handleGetTeacherLogs()
{
    global $db;
    $teacher = require_teacher_auth();
    $teacherId = $teacher['Id'];

    $sql = "SELECT 
                scl.Id AS logId, 
                scl.reason, 
                scl.change, 
                scl.timestamp, 
                scl.studentid, 
                s.lastname, 
                s.firstname, 
                t.lastname AS teacherLastname, 
                t.firstname AS teacherFirstname
            FROM scorechangelog scl
            JOIN students s ON scl.studentid = s.Id
            JOIN teachers t ON scl.teacherid = t.Id
            WHERE scl.teacherid = ?
            ORDER BY scl.timestamp DESC";

    try {
        $stmt = $db->prepare($sql);
        $stmt->execute([$teacherId]);
        $logs = $stmt->fetchAll();

        // 格式化學生和教師姓名
        foreach ($logs as &$log) {
            $log['studentFullName'] = $log['lastname'] . $log['firstname'];
            $log['teacherFullName'] = $log['teacherLastname'] . $log['teacherFirstname'];
            // 處理原因顯示
            if (strpos($log['reason'], 'custom-') === 0) {
                $log['displayReason'] = substr($log['reason'], 7);
            } else {
                // 嘗試查詢 scorechangetype 的名稱
                $reasonId = $log['reason'];
                $stmtType = $db->prepare("SELECT name FROM scorechangetype WHERE Id = ?");
                $stmtType->execute([$reasonId]);
                $type = $stmtType->fetch();
                $log['displayReason'] = $type ? $type['name'] : $log['reason'];
            }
        }

        echo json_encode(["status" => "success", "logs" => $logs]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "查詢變動紀錄失敗: " . $e->getMessage()]);
    }
}

/**
 * 處理撤銷教師自己對學生的加減分
 * @param array $input POST 資料
 */
function handleUndoLog(array $input)
{
    global $db;
    $teacher = require_teacher_auth();
    $teacherId = $teacher['Id'];

    $logIdToUndo = $input['logId'] ?? null;
    $timestamp = time();

    if (!$logIdToUndo) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "缺少要撤銷的紀錄 ID"]);
        exit();
    }

    try {
        // 1. 獲取原始紀錄並驗證權限（只能撤銷自己的紀錄，且非撤銷紀錄本身）
        $stmt = $db->prepare("SELECT Id, studentid, `change` FROM scorechangelog WHERE Id = ? AND teacherid = ? AND reason NOT LIKE 'custom-撤销[%'");
        $stmt->execute([$logIdToUndo, $teacherId]);
        $originalLog = $stmt->fetch();

        if (!$originalLog) {
            http_response_code(403);
            echo json_encode(["status" => "error", "message" => "找不到记录，可能为以下原因：①该记录为撤回记录②您无权撤销该记录③记录不存在"]);
            exit();
        }

        // =======================================================
        // 【關鍵修改】：檢查是否已被撤銷過（防止重複撤銷）
        // =======================================================
        $undoReasonCheck = "custom-撤销[{$logIdToUndo}]的变动";
        $checkUndoStmt = $db->prepare("SELECT Id FROM scorechangelog WHERE reason = ?");
        $checkUndoStmt->execute([$undoReasonCheck]);

        if ($checkUndoStmt->rowCount() > 0) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "該紀錄已被撤銷過，不能再次撤銷"]);
            exit();
        }
        // =======================================================

        // 開始事務，確保操作原子性
        $db->beginTransaction();

        $studentId = $originalLog['studentid'];
        $originalChange = (float)$originalLog['change'];
        $undoChange = -$originalChange; // 撤銷變動量：相反數
        $undoReason = $undoReasonCheck; // 使用已檢查過的原因格式

        // 2. 寫入新的撤銷紀錄
        $stmt = $db->prepare("INSERT INTO scorechangelog (teacherid, reason, `change`, timestamp, studentid) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$teacherId, $undoReason, $undoChange, $timestamp, $studentId]);

        // 3. 更新 students 表中的 score
        $stmt = $db->prepare("UPDATE students SET score = score + ? WHERE Id = ?");
        $stmt->execute([$undoChange, $studentId]);

        // 檢查學生是否更新成功（可選，如果確定學生存在則可省略）
        if ($stmt->rowCount() === 0) {
            $db->rollBack();
            http_response_code(404);
            echo json_encode(["status" => "error", "message" => "更新學生分數失敗，撤銷操作回滾"]);
            exit();
        }

        $db->commit();

        echo json_encode([
            "status" => "success",
            "message" => "记录撤销成功。",
            "undoChange" => $undoChange
        ]);
    } catch (PDOException $e) {
        // 確保在發生異常時回滾
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "撤銷操作失敗: " . $e->getMessage()]);
    }
}
