<?php
// 包含数据库连线和认证函数
require_once 'database.php';

// 检查管理员权限
$admin = check_admin_auth();
if (!$admin) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => '未授权或登录已过期，请重新登录。']);
    exit();
}

// 全局数据库连线物件
global $db;

// 设置响应头为 JSON
header('Content-Type: application/json; charset=utf-8');
$response = ['success' => false, 'message' => '操作失败'];
$action = $_GET['action'] ?? null;

if (!$action) {
    http_response_code(400);
    $response['message'] = '缺少操作参数 (action)。';
    echo json_encode($response);
    exit;
}

try {
    switch ($action) {
        case 'get_students':
            // 获取所有学生及其组名
            $query = "
                SELECT 
                    s.Id, 
                    s.firstname, 
                    s.lastname, 
                    s.score,
                    s.groupId,
                    g.groupName
                FROM students s
                LEFT JOIN `groups` g ON s.groupId = g.Id
                ORDER BY s.Id";
            $stmt = $db->query($query);
            $students = $stmt->fetchAll();
            $students = format_names($students);

            $response = ['success' => true, 'students' => $students];
            break;

        case 'get_groups':
            // 获取所有小组
            $query = "SELECT Id, groupName FROM `groups` ORDER BY Id ASC";
            $stmt = $db->query($query);
            $groups = $stmt->fetchAll();
            // 添加一个“未分组”选项
            array_unshift($groups, ['Id' => null, 'groupName' => '未分组']);

            $response = ['success' => true, 'groups' => $groups];
            break;

        case 'add_student':
            // 处理新增学生 (POST)
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                $response['message'] = '仅允许 POST 请求。';
                break;
            }
            $firstname = trim($_POST['firstname'] ?? '');
            $lastname = trim($_POST['lastname'] ?? '');
            $password = $_POST['password'] ?? '';

            if (empty($firstname) || empty($lastname) || empty($password)) {
                $response['message'] = '姓名和密码不能为空。';
                break;
            }

            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            $query = "INSERT INTO students (firstname, lastname, password, score) VALUES (?, ?, ?, 0)";
            $stmt = $db->prepare($query);
            if ($stmt->execute([$firstname, $lastname, $hashed_password])) {
                $response = ['success' => true, 'message' => '学生新增成功。'];
            }
            break;

        case 'update_student':
            // 处理修改学生信息 (POST)
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                $response['message'] = '仅允许 POST 请求。';
                break;
            }
            $studentId = $_POST['Id'] ?? null;
            $firstname = trim($_POST['firstname'] ?? '');
            $lastname = trim($_POST['lastname'] ?? '');
            $password = $_POST['password'] ?? null; // 密码可选
            $score = $_POST['score'] ?? null;

            if (!$studentId) {
                $response['message'] = '缺少学号。';
                break;
            }

            $set_parts = [];
            $params = [];

            if (!empty($firstname)) {
                $set_parts[] = 'firstname = ?';
                $params[] = $firstname;
            }
            if (!empty($lastname)) {
                $set_parts[] = 'lastname = ?';
                $params[] = $lastname;
            }
            if (!is_null($score) && is_numeric($score)) {
                $set_parts[] = 'score = ?';
                $params[] = (int)$score;
            }
            if (!empty($password)) {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $set_parts[] = 'password = ?';
                $params[] = $hashed_password;
            }

            if (empty($set_parts)) {
                $response['message'] = '没有需要更新的字段。';
                break;
            }

            $query = "UPDATE students SET " . implode(', ', $set_parts) . " WHERE Id = ?";
            $params[] = $studentId;

            $stmt = $db->prepare($query);
            if ($stmt->execute($params)) {
                $response = ['success' => true, 'message' => '学生信息更新成功。'];
            }
            break;

        case 'delete_student':
            // 处理删除学生 (POST)
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                $response['message'] = '仅允许 POST 请求。';
                break;
            }
            $studentId = $_POST['Id'] ?? null;

            if (!$studentId) {
                $response['message'] = '缺少学号。';
                break;
            }

            $query = "DELETE FROM students WHERE Id = ?";
            $stmt = $db->prepare($query);
            if ($stmt->execute([$studentId])) {
                $response = ['success' => true, 'message' => '学生删除成功。'];
            }
            break;

        case 'assign_group':
            // 处理小组分配 (POST)
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                $response['message'] = '仅允许 POST 请求。';
                break;
            }
            $studentId = $_POST['Id'] ?? null;
            $groupId = $_POST['groupId'] ?? null;

            // 将 'null' 字串转换为 PHP 的 null 值
            $groupId = ($groupId === 'null' || $groupId === '') ? null : (int)$groupId;

            if (!$studentId) {
                $response['message'] = '缺少学号。';
                break;
            }

            $query = "UPDATE students SET groupId = ? WHERE Id = ?";
            $stmt = $db->prepare($query);

            if ($stmt->execute([$groupId, $studentId])) {
                $response = ['success' => true, 'message' => '小组分配成功。'];
            }
            break;

        case 'import_students':
            // 处理 CSV/Excel 导入 (POST)
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                $response['message'] = '仅允许 POST 请求。';
                break;
            }
            if (!isset($_FILES['file'])) {
                $response['message'] = '未上传文件。';
                break;
            }
            $file = $_FILES['file'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['csv', 'xlsx'])) {
                $response['message'] = '仅支持 CSV 或 Excel (.xlsx) 文件。';
                break;
            }

            // 列映射参数，-1 表示不使用
            $idCol = isset($_POST['idCol']) ? (int)$_POST['idCol'] : -1;
            $lastNameCol = isset($_POST['lastNameCol']) ? (int)$_POST['lastNameCol'] : -1;
            $firstNameCol = isset($_POST['firstNameCol']) ? (int)$_POST['firstNameCol'] : -1;
            $fullNameCol = isset($_POST['fullNameCol']) ? (int)$_POST['fullNameCol'] : -1;

            $rows = [];
            if ($ext === 'csv') {
                // 读取 CSV 前先检测文件编码，确保后续处理为 UTF-8
                $rawContent = file_get_contents($file['tmp_name']);
                // 常见的 Excel 导出 ANSI 编码（Windows-1252、GBK 等）
                $encoding = mb_detect_encoding($rawContent, ['UTF-8', 'GBK', 'GB2312', 'BIG5', 'Windows-1252'], true);
                if ($encoding !== 'UTF-8') {
                    // 将内容转换为 UTF-8，防止中文乱码
                    $rawContent = mb_convert_encoding($rawContent, 'UTF-8', $encoding);
                }
                // 将转换后的内容写入临时流，以便 fgetcsv 正常读取
                $tempHandle = fopen('php://temp', 'r+');
                fwrite($tempHandle, $rawContent);
                rewind($tempHandle);
                $rowIndex = 0;
                while (($data = fgetcsv($tempHandle)) !== false) {
                    // 跳过可能的表头行（如果第一行包含非数字的学号列或明显的标题）
                    if ($rowIndex === 0) {
                        $firstCell = $data[0] ?? '';
                        // 若首列包含中文或字母且不全为数字，则视为表头
                        if (preg_match('/[\p{L}]/u', $firstCell) && !ctype_digit($firstCell)) {
                            $rowIndex++;
                            continue;
                        }
                    }
                    $rows[] = $data;
                    $rowIndex++;
                }
                fclose($tempHandle);
            } else { // xlsx
                // 使用 PhpSpreadsheet 读取 Excel
                if (!class_exists('PhpOffice\PhpSpreadsheet\Reader\Xlsx')) {
                    $response['message'] = '服务器未安装 PhpSpreadsheet，无法解析 Excel 文件。';
                    break;
                }
                $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
                $spreadsheet = $reader->load($file['tmp_name']);
                $sheet = $spreadsheet->getActiveSheet();
                foreach ($sheet->toArray(null, true, true, true) as $row) {
                    $rows[] = array_values($row);
                }
            }

            if (empty($rows)) {
                $response['message'] = '文件为空或未读取到数据。';
                break;
            }

            // 复姓列表（常见）
            $compoundSurnames = ['欧阳', '司马', '上官', '诸葛', '东方', '皇甫', '尉迟', '公孙', '慕容', '夏侯', '诸葛', '长孙', '宇文', '司徒', '鲜于', '闾丘', '子车', '亓官', '司空', '闫', '万俟', '司寇', '公羊', '赫连', '澹台', '公冶', '宗政', '濮阳', '淳于', '仲孙', '太叔', '申屠', '公良', '轩辕', '令狐', '钟离'];

            $inserted = 0;
            $passwords = [];
            foreach ($rows as $row) {
                // 跳过空行
                if (count(array_filter($row, function ($v) {
                    return trim($v) !== '';
                })) === 0) continue;

                $firstname = '';
                $lastname = '';
                $providedId = ''; // 初始化学号
                if ($fullNameCol >= 0 && $fullNameCol < count($row) && $fullNameCol != -1) {
                    $full = trim($row[$fullNameCol]);
                    if ($full !== '') {
                        // 处理姓名列中可能混入的学号（如 "曹意瀚,1"），提取学号并清理姓名
                        if (preg_match('/^(.+),(\d+)$/u', $full, $matches)) {
                            $full = trim($matches[1]);
                            $providedId = $matches[2];
                        }
                        // 复姓匹配
                        $matched = false;
                        foreach ($compoundSurnames as $cs) {
                            if (mb_strpos($full, $cs) === 0) {
                                $lastname = $cs;
                                $firstname = mb_substr($full, mb_strlen($cs));
                                $matched = true;
                                break;
                            }
                        }
                        if (!$matched) {
                            $lastname = mb_substr($full, 0, 1);
                            $firstname = mb_substr($full, 1);
                        }
                    }
                } else {
                    if ($lastNameCol >= 0 && $lastNameCol < count($row) && $lastNameCol != -1) {
                        $lastname = trim($row[$lastNameCol]);
                    }
                    if ($firstNameCol >= 0 && $firstNameCol < count($row) && $firstNameCol != -1) {
                        $firstname = trim($row[$firstNameCol]);
                    }
                }

                // 清理可能混入的学号或其他数字字符，防止姓名中出现数字
                $firstname = preg_replace('/\d+/', '', $firstname);
                $lastname = preg_replace('/\d+/', '', $lastname);
                // 去除姓名两端的逗号和空格
                $firstname = trim($firstname, " ,");
                $lastname = trim($lastname, " ,");
                if ($firstname === '' || $lastname === '') continue; // 必须有姓名

                // 生成密码（不再使用 idCol 作为密码列）
                $password = bin2hex(random_bytes(4));
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                // 如果提供了学号列且不为空，则优先使用该学号作为 ID（覆盖从姓名中提取的）
                if ($idCol >= 0 && $idCol < count($row) && $idCol != -1) {
                    $idFromCol = trim($row[$idCol]);
                    if ($idFromCol !== '') {
                        $providedId = $idFromCol;
                    }
                }
                if ($providedId !== '' && ctype_digit($providedId)) {
                    $stmt = $db->prepare('INSERT INTO students (Id, firstname, lastname, password, score) VALUES (?, ?, ?, ?, 0)');
                    $executeParams = [(int)$providedId, $firstname, $lastname, $hashed];
                } else {
                    $stmt = $db->prepare('INSERT INTO students (firstname, lastname, password, score) VALUES (?, ?, ?, 0)');
                    $executeParams = [$firstname, $lastname, $hashed];
                }
                if ($stmt->execute($executeParams)) {
                    $inserted++;
                    // 记录生成的密码，供下载
                    $passwords[] = ['firstname' => $firstname, 'lastname' => $lastname, 'password' => $password];
                }
            }

            // 生成密码 CSV 内容（若有）
            $passwordCsv = '';
            if (!empty($passwords)) {
                $lines = [];
                $lines[] = "姓,名,密码";
                foreach ($passwords as $p) {
                    $lines[] = "{$p['lastname']},{$p['firstname']},{$p['password']}";
                }
                $passwordCsv = implode("\n", $lines);
            }

            $response = ['success' => true, 'message' => "成功导入 {$inserted} 条学生记录。", 'passwords' => $passwords, 'passwordCsv' => $passwordCsv];
            break;

        default:
            http_response_code(400);
            $response['message'] = '未知操作';
            break;
    }
} catch (\PDOException $e) {
    // 捕获所有 PDO 异常
    http_response_code(500);
    $response['message'] = '数据库操作错误: ' . $e->getMessage();
} catch (\Exception $e) {
    // 捕获其他异常
    http_response_code(500);
    $response['message'] = '服务器内部错误: ' . $e->getMessage();
}

echo json_encode($response);
exit;
