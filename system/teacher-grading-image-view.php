<?php
require_once 'database.php';

// 验证教师登录
$teacher = check_teacher_auth();
if (!$teacher) {
    http_response_code(403);
    exit;
}

/**
 * type: 
 * - submission: 获取学生提交的作业原图 (从 homeworksubmission 表)
 * - check: 获取教师批改的透明涂鸦图层 (从 homeworkcheck 表)
 * id: 
 * - 指的是 homeworksubmission 表的 Id
 */
$type = $_GET['type'] ?? 'submission';
$submissionId = $_GET['id'] ?? 0;

if ($type === 'submission') {
    // 获取作业原图
    $stmt = $db->prepare("SELECT submission FROM homeworksubmission WHERE Id = ?");
    $stmt->execute([$submissionId]);
} else {
    // 修正点：获取批改图层应透过 submissionid 查询
    // 因为前端传入的 ID 是该次提交的 ID
    $stmt = $db->prepare("SELECT check_image FROM homeworkcheck WHERE submissionid = ?");
    $stmt->execute([$submissionId]);
}

$row = $stmt->fetch();

if ($row) {
    $blob = ($type === 'submission') ? $row['submission'] : $row['check_image'];

    if (empty($blob)) {
        if ($type === 'check') {
            // 批改图层为空时，返回 1x1 透明像素，避免前端绘图报错
            header("Content-Type: image/png");
            echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAACklEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
            exit;
        }

        // 作业原图为空时返回占位图
        header("Content-Type: image/png");
        $im = imagecreate(200, 200);
        $bg = imagecolorallocate($im, 240, 240, 240);
        $text_color = imagecolorallocate($im, 100, 100, 100);
        imagestring($im, 5, 50, 90, "No Image", $text_color);
        imagepng($im);
        imagedestroy($im);
        exit;
    }

    // 动态检测 MIME 类型并输出
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->buffer($blob);

    header("Content-Type: " . $mimeType);
    echo $blob;
} else {
    // 如果找不到记录且请求的是批改图层，同样返回透明像素（代表尚未批改过）
    if ($type === 'check') {
        header("Content-Type: image/png");
        echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAACklEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
        exit;
    }
    http_response_code(404);
}
