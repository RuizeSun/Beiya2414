<?php
date_default_timezone_set('Asia/Taipei');
define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'beiya2414');
define('DB_PASSWORD', 'beiya2414');
define('DB_NAME', 'beiya2414');
$dsn = "mysql:host=" . DB_SERVER . ";dbname=" . DB_NAME . ";charset=utf8mb4";
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false,];
try {
    $db = new PDO($dsn, DB_USERNAME, DB_PASSWORD, $options);
} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "数据库连线错误: " . $e->getMessage()]);
    exit;
}
function check_admin_auth()
{
    global $db;
    if (!isset($_COOKIE['token'])) {
        return false;
    }
    $token = $_COOKIE['token'];
    $currentTime = time();
    $stmt = $db->prepare("SELECT Id, firstname, lastname FROM teachers WHERE token = ? AND tokenExpire > ? AND isAdmin = 1");
    $stmt->execute([$token, $currentTime]);
    $admin = $stmt->fetch();
    if ($admin) {
        return $admin;
    }
    return false;
}
function require_admin_auth()
{
    $admin = check_admin_auth();
    if (!$admin) {
        http_response_code(401);
        header('Content-Type: text/html; charset=utf-8');
        echo '<div style="padding: 20px; font-family: sans-serif; text-align: center; background-color: #fef2f2; border: 1px solid #f87171; border-radius: 8px; color: #b91c1c;"><h1>未授权访问</h1><p>您必须以管理员身份登录才能查看此页面。</p><p>请确保您的浏览器中设置了有效的管理员 Token Cookie。</p></div>';
        exit();
    }
    return $admin;
}
function check_teacher_auth()
{
    global $db;
    if (!isset($_COOKIE['token'])) {
        return false;
    }
    $token = $_COOKIE['token'];
    $currentTime = time();
    $stmt = $db->prepare("SELECT Id, firstname, lastname FROM teachers WHERE token = ? AND tokenExpire > ?");
    $stmt->execute([$token, $currentTime]);
    $usr = $stmt->fetch();
    if ($usr) {
        return $usr;
    }
    return false;
}
function require_teacher_auth()
{
    $teacher = check_teacher_auth();
    if (!$teacher) {
        http_response_code(401);
        header('Content-Type: text/html; charset=utf-8');
        echo '<div style="padding: 20px; font-family: sans-serif; text-align: center; background-color: #fef2f2; border: 1px solid #f87171; border-radius: 8px; color: #b91c1c;"><h1>未授权访问</h1><p>您必须以教师身份登录才能查看此页面。</p><p>请确保您的浏览器中设置了有效的教师 Token Cookie。</p></div>';
        exit();
    }
    return $teacher;
}
function check_screen_auth()
{
    global $db;
    if (!isset($_COOKIE['screen_token'])) {
        return false;
    }
    $token = $_COOKIE['screen_token'];
    $currentTime = time();
    $stmt = $db->prepare("SELECT Id FROM screens WHERE token = ? AND tokenExpire > ?");
    $stmt->execute([$token, $currentTime]);
    $screen = $stmt->fetch();
    if ($screen) {
        return $screen;
    }
    return false;
}
function require_screen_auth()
{
    $screen = check_screen_auth();
    if (!$screen) {
        http_response_code(401);
        header('Content-Type: text/html; charset=utf-8');
        echo '<div style="padding: 20px; font-family: sans-serif; text-align: center; background-color: #fef2f2; border: 1px solid #f87171; border-radius: 8px; color: #b91c1c;"><h1>大屏未授权访问</h1><p>您必须使用有效的 Token 才能访问大屏页面。</p><p>请确保浏览器中设置了有效的 <kbd>screen_token</kbd> Cookie。</p></div>';
        exit();
    }
    return $screen;
}
function format_names(array $rows): array
{
    foreach ($rows as &$row) {
        if (isset($row['firstname']) && isset($row['lastname'])) {
            $row['fullName'] = $row['lastname'] . $row['firstname'];
        }
    }
    return $rows;
}
