<?
// 设定内容类型为 JSON
header('Content-Type: application/json; charset=utf-8');
// 引入数据库连线和认证函式库
require_once './database.php';
// 获取 action 参数
$action = $_GET['action'] ?? '';

// 只有在处理 POST 请求时才解析输入
$input = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $json = file_get_contents('php://input');
    $input = json_decode($json, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "无效的 JSON 输入"]);
        exit();
    }
}

// 路由处理
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
        echo json_encode(["status" => "error", "message" => "无效的 API 动作"]);
        break;
}


/**
 * 处理验证检查
 */
function handleCheckAuth()
{
    $teacher = check_teacher_auth();
    if ($teacher) {
        echo json_encode([
            "status" => "success",
            "isLoggedIn" => true,
            "teacherId" => $teacher['Id'],
        ]);
    } else {
        echo json_encode(["status" => "success", "isLoggedIn" => false]);
    }
}


/**
 * 处理获取学生列表
 */
function handleGetStudents()
{
    global $db;
    require_teacher_auth(); // 验证教师身份

    $sort = $_GET['sort'] ?? 'Id'; // 预设排序为学号
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
        echo json_encode(["status" => "error", "message" => "查询学生资料失败: " . $e->getMessage()]);
    }
}

/**
 * 处理获取量化评分变动类型
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
        echo json_encode(["status" => "error", "message" => "查询评分类型失败: " . $e->getMessage()]);
    }
}


/**
 * 处理学生量化评分加分或减分
 * @param array $input POST 资料
 */
function handleUpdateScore(array $input)
{
    global $db;
    $teacher = require_teacher_auth();
    $teacherId = $teacher['Id'];
    $timestamp = time();

    // 参数验证
    $studentId = $input['studentId'] ?? null;
    $changeAmount = $input['changeAmount'] ?? null;
    $reasonId = $input['reasonId'] ?? null;
    $customReason = $input['customReason'] ?? null;

    if (!$studentId || $reasonId === null) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "缺少学生ID或变动量"]);
        exit();
    }

    $reason = '';
    $finalChange = (float)$changeAmount;

    try {
        $db->beginTransaction(); // 开始事务

        // 1. 处理原因和变动量
        if ($reasonId && $reasonId !== 'custom') {
            // 使用预设变动项
            $stmt = $db->prepare("SELECT name, `change` FROM scorechangetype WHERE Id = ?");
            $stmt->execute([$reasonId]);
            $type = $stmt->fetch();

            if (!$type) {
                $db->rollBack();
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "无效的变动项ID"]);
                exit();
            }
            $reason = $reasonId; // 记录 scorechangetype Id
            $finalChange = (float)$type['change']; // 使用预设变动量
        } else if ($reasonId === 'custom') {
            // 自定义变动项
            if (!$customReason) {
                $db->rollBack();
                http_response_code(400);
                echo json_encode(["status" => "error", "message" => "自定义原因不能为空"]);
                exit();
            }
            // 记录为 custom-<原因>
            $reason = "custom-" . $customReason;
            // $finalChange 已经从 $input['changeAmount'] 取得
        } else {
            // 如果没有提供任何原因，视为无效
            $db->rollBack();
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "必须提供变动原因"]);
            exit();
        }

        // 2. 更新 students 表中的 score
        $stmt = $db->prepare("UPDATE students SET score = score + ? WHERE Id = ?");
        $stmt->execute([$finalChange, $studentId]);

        if ($stmt->rowCount() === 0) {
            $db->rollBack();
            http_response_code(404);
            echo json_encode(["status" => "error", "message" => "找不到学生，或分数无变动"]);
            exit();
        }

        // 3. 写入 scorechangelog
        $stmt = $db->prepare("INSERT INTO scorechangelog (teacherid, reason, `change`, timestamp, studentid) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$teacherId, $reason, $finalChange, $timestamp, $studentId]);

        $db->commit(); // 提交事务

        echo json_encode([
            "status" => "success",
            "message" => "评分更新成功",
            "change" => $finalChange
        ]);
    } catch (PDOException $e) {
        $db->rollBack(); // 发生错误时回滚
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "评分更新失败: " . $e->getMessage()]);
    }
}

/**
 * 处理查看教师自己对学生的加减分纪录
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

        // 格式化学生和教师姓名
        foreach ($logs as &$log) {
            $log['studentFullName'] = $log['lastname'] . $log['firstname'];
            $log['teacherFullName'] = $log['teacherLastname'] . $log['teacherFirstname'];
            // 处理原因显示
            if (strpos($log['reason'], 'custom-') === 0) {
                $log['displayReason'] = substr($log['reason'], 7);
            } else {
                // 尝试查询 scorechangetype 的名称
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
        echo json_encode(["status" => "error", "message" => "查询变动纪录失败: " . $e->getMessage()]);
    }
}

/**
 * 处理撤销教师自己对学生的加减分
 * @param array $input POST 资料
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
        echo json_encode(["status" => "error", "message" => "缺少要撤销的纪录 ID"]);
        exit();
    }

    try {
        // 1. 获取原始纪录并验证权限（只能撤销自己的纪录，且非撤销纪录本身）
        $stmt = $db->prepare("SELECT Id, studentid, `change` FROM scorechangelog WHERE Id = ? AND teacherid = ? AND reason NOT LIKE 'custom-撤销[%'");
        $stmt->execute([$logIdToUndo, $teacherId]);
        $originalLog = $stmt->fetch();

        if (!$originalLog) {
            http_response_code(403);
            echo json_encode(["status" => "error", "message" => "找不到记录，可能为以下原因：①该记录为撤回记录②您无权撤销该记录③记录不存在"]);
            exit();
        }

        // =======================================================
        // 【关键修改】：检查是否已被撤销过（防止重复撤销）
        // =======================================================
        $undoReasonCheck = "custom-撤销[{$logIdToUndo}]的变动";
        $checkUndoStmt = $db->prepare("SELECT Id FROM scorechangelog WHERE reason = ?");
        $checkUndoStmt->execute([$undoReasonCheck]);

        if ($checkUndoStmt->rowCount() > 0) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "该纪录已被撤销过，不能再次撤销"]);
            exit();
        }
        // =======================================================

        // 开始事务，确保操作原子性
        $db->beginTransaction();

        $studentId = $originalLog['studentid'];
        $originalChange = (float)$originalLog['change'];
        $undoChange = -$originalChange; // 撤销变动量：相反数
        $undoReason = $undoReasonCheck; // 使用已检查过的原因格式

        // 2. 写入新的撤销纪录
        $stmt = $db->prepare("INSERT INTO scorechangelog (teacherid, reason, `change`, timestamp, studentid) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$teacherId, $undoReason, $undoChange, $timestamp, $studentId]);

        // 3. 更新 students 表中的 score
        $stmt = $db->prepare("UPDATE students SET score = score + ? WHERE Id = ?");
        $stmt->execute([$undoChange, $studentId]);

        // 检查学生是否更新成功（可选，如果确定学生存在则可省略）
        if ($stmt->rowCount() === 0) {
            $db->rollBack();
            http_response_code(404);
            echo json_encode(["status" => "error", "message" => "更新学生分数失败，撤销操作回滚"]);
            exit();
        }

        $db->commit();

        echo json_encode([
            "status" => "success",
            "message" => "记录撤销成功。",
            "undoChange" => $undoChange
        ]);
    } catch (PDOException $e) {
        // 确保在发生异常时回滚
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "撤销操作失败: " . $e->getMessage()]);
    }
}
