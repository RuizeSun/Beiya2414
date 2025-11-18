<?php
// 设定回传 JSON 格式
header('Content-Type: application/json; charset=utf-8');

// 引入数据库连线和权限验证 (database.php 必须位于同目录)
require_once 'database.php';

// 检查管理员权限，如果失败则脚本终止
$admin = require_admin_auth();
$adminId = $admin['Id'];

// 分页设定
$limit = 20;

try {
    $action = $_GET['action'] ?? '';

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if ($action === 'list') {
            // ----------------------------------------
            // 获取变动记录列表 (包含所有筛选逻辑)
            // ----------------------------------------
            $offset = (int)($_GET['offset'] ?? 0);
            $studentId = trim($_GET['studentId'] ?? ''); // 获取学生 ID，可能是空字串
            $reasonFilter = trim($_GET['reason'] ?? '');
            $startDate = (int)($_GET['startDate'] ?? 0);
            $endDate = (int)($_GET['endDate'] ?? 0);

            $params = [];
            $whereClauses = ["1=1"];

            // 1. 学生 ID 筛选 (如果为空，则显示所有学生)
            if (!empty($studentId)) {
                $whereClauses[] = "scl.studentid = ?";
                $params[] = $studentId;
            }

            // 2. 扣分/加分项筛选
            if (!empty($reasonFilter)) {
                $whereClauses[] = "scl.reason = ?";
                $params[] = $reasonFilter;
            }

            // 3. 时间段筛选 (timestamp)
            if ($startDate > 0) {
                $whereClauses[] = "scl.timestamp >= ?";
                $params[] = $startDate;
            }
            if ($endDate > 0) {
                $whereClauses[] = "scl.timestamp <= ?";
                $params[] = $endDate;
            }

            $sqlWhere = implode(' AND ', $whereClauses);

            // 查询语句，获取所有相关资讯，并新增连接 scorechangetype 以获取原因名称
            $sql = "SELECT 
                scl.Id, scl.teacherid, scl.reason, scl.change, scl.timestamp, scl.studentid,
                t.firstname AS teacher_firstname, t.lastname AS teacher_lastname,
                st.firstname AS student_firstname, st.lastname AS student_lastname,
                sct.name AS reason_type_name -- 新增：变动类型名称
            FROM scorechangelog scl
            LEFT JOIN teachers t ON scl.teacherid = t.Id
            LEFT JOIN students st ON scl.studentid = st.Id
            LEFT JOIN scorechangetype sct ON scl.reason = sct.Id -- 连接变动类型表
            WHERE {$sqlWhere}
            ORDER BY scl.timestamp DESC
            LIMIT ?, ?";

            // 准备 LIMIT/OFFSET 参数 (放在最后)
            $params[] = $offset;
            $params[] = $limit + 1; // 多取一笔，判断是否有更多数据

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $logs = $stmt->fetchAll();

            $hasMore = count($logs) > $limit;
            if ($hasMore) {
                array_pop($logs); // 移除多取的那一笔
            }

            // 格式化姓名和时间
            $formattedLogs = [];
            foreach ($logs as $log) {
                $log['teacherName'] = $log['teacher_lastname'] . $log['teacher_firstname'];
                $log['studentName'] = $log['student_lastname'] . $log['student_firstname'];
                $log['datetime'] = date('Y-m-d H:i:s', $log['timestamp']);

                // 判断是否为撤销操作
                $log['isUndo'] = (strpos($log['reason'], 'custom-撤销') === 0);

                // *** 新增逻辑：如果 reason_type_name 存在，则使用它来取代 reason 字段 ***
                if (!empty($log['reason_type_name'])) {
                    // 格式化为：名称 (ID: 原始 ID)
                    $log['reason'] = $log['reason_type_name'] . " (ID: {$log['reason']})";
                }

                unset($log['teacher_firstname'], $log['teacher_lastname'], $log['student_firstname'], $log['student_lastname'], $log['reason_type_name']);
                $formattedLogs[] = $log;
            }

            echo json_encode(["status" => "success", "data" => $formattedLogs, "hasMore" => $hasMore, "nextOffset" => $offset + $limit]);
        } elseif ($action === 'types') {
            // ----------------------------------------
            // 获取所有用于筛选的变动原因：排除 custom- 开头的 reason
            // ----------------------------------------

            // 1. 获取所有非 custom- 开头的唯一 reason 值
            $stmtAllReasons = $db->query("SELECT DISTINCT reason FROM scorechangelog 
                                        WHERE reason NOT LIKE 'custom-%'");
            $allReasonsValues = $stmtAllReasons->fetchAll(PDO::FETCH_COLUMN);

            // 2. 获取所有标准类型名称用于显示
            $stmt = $db->query("SELECT Id, name FROM scorechangetype");
            $standardTypes = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // Id => name

            // 3. 组合给前端的筛选列表：Value for API (reason string/ID), Text for Display
            $filterList = [];
            foreach ($allReasonsValues as $reasonValue) {
                $displayText = $reasonValue;

                // 如果是数字，尝试从 scorechangetype 获取名称
                if (is_numeric($reasonValue) && isset($standardTypes[$reasonValue])) {
                    $displayText = $standardTypes[$reasonValue] . " (ID: {$reasonValue})";
                }

                // 排除空值
                if (!empty($reasonValue)) {
                    $filterList[] = ['value' => $reasonValue, 'text' => $displayText];
                }
            }

            echo json_encode(["status" => "success", "data" => $filterList]);
        } else {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "无效的 GET 动作"]);
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'undo') {
        // ----------------------------------------
        // 撤销变动操作
        // ----------------------------------------
        $input = json_decode(file_get_contents('php://input'), true);
        $originalLogId = (int)($input['originalLogId'] ?? 0);

        if ($originalLogId <= 0) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "无效的原始变动 ID"]);
            exit();
        }

        // 1. 获取原始变动记录
        $stmt = $db->prepare("SELECT Id, `change`, studentid, reason FROM scorechangelog WHERE Id = ?");
        $stmt->execute([$originalLogId]);
        $originalLog = $stmt->fetch();

        if (!$originalLog) {
            http_response_code(404);
            echo json_encode(["status" => "error", "message" => "找不到要撤销的变动记录"]);
            exit();
        }

        // 检查是否已是撤销记录
        if (strpos($originalLog['reason'], 'custom-撤销') === 0) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "该记录本身就是撤销记录，不允许再次撤销"]);
            exit();
        }

        // 检查是否已被撤销过（防止重复撤销）
        $checkUndoStmt = $db->prepare("SELECT Id FROM scorechangelog WHERE reason = ?");
        $checkUndoStmt->execute(["custom-撤销[{$originalLogId}]的变动"]);
        if ($checkUndoStmt->rowCount() > 0) {
            http_response_code(400);
            echo json_encode(["status" => "error", "message" => "该记录已被撤销过"]);
            exit();
        }

        // 开始事务，确保两步操作（新增记录 + 更新学生分数）同时成功或失败
        $db->beginTransaction();

        $originalChange = (float)$originalLog['change'];
        $newChange = -$originalChange; // 撤销变动量
        $studentId = $originalLog['studentid'];
        $undoReason = "custom-撤销[{$originalLogId}]的变动";
        $currentTime = time();

        // 2. 新增一条撤销记录
        $stmt = $db->prepare("INSERT INTO scorechangelog (teacherid, reason, `change`, timestamp, studentid) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$adminId, $undoReason, $newChange, $currentTime, $studentId]);
        $newLogId = $db->lastInsertId();

        // 3. 更新学生量化评分
        // 使用 score = score + change 来处理加分和扣分
        $stmt = $db->prepare("UPDATE students SET score = score + ? WHERE Id = ?");
        $stmt->execute([$newChange, $studentId]);

        if ($stmt->rowCount() === 0) {
            // 如果学生不存在，则回滚
            $db->rollBack();
            http_response_code(404);
            echo json_encode(["status" => "error", "message" => "学生 ID 无效，撤销失败"]);
            exit();
        }

        $db->commit();

        echo json_encode(["status" => "success", "message" => "成功撤销变动 ID: {$originalLogId}", "newLogId" => $newLogId]);
    } else {
        http_response_code(405); // Method Not Allowed
        echo json_encode(["status" => "error", "message" => "不允许的请求方法或无效动作"]);
    }
} catch (\PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "数据库操作错误: " . $e->getMessage()]);
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "服务器错误: " . $e->getMessage()]);
}
