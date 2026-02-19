<?php

/**
 * AIService.php 
 * 内部 AI 调用核心类别
 * * 使用方式：
 * require_once 'database.php';
 * require_once 'AIService.php';
 * $aiService = new AIService($db);
 */

class AIService
{
    private $db;

    /**
     * @param PDO $databaseConnection 传入 database.php 中的 $db 实例
     */
    public function __construct($databaseConnection)
    {
        $this->db = $databaseConnection;
    }

    /**
     * 调用 AI 模型的主要入口
     * * @param int $teacherId 老师的 ID (从 require_teacher_auth() 获取)
     * @param string $modelAlias 模型标识 (如 'gpt-4o', 'deepseek-v3')
     * @param string $prompt 提示词内容
     * @return array 执行结果
     */
    public function askAI($teacherId, $modelAlias, $prompt)
    {
        try {
            // 1. 获取模型详细资讯
            $modelInfo = $this->getAndValidateModel($modelAlias);
            if (!$modelInfo) {
                throw new Exception("系统未支援模型 [$modelAlias] 或该模型已禁用。");
            }

            // 2. 检查该教师是否有权限使用此模型及剩馀额度
            $quota = $this->checkTeacherQuota($teacherId, $modelInfo['Id']);
            if (!$quota['can_use']) {
                throw new Exception($quota['reason']);
            }

            // 3. 获取供应商配置 (API Key / Base URL)
            $provider = $this->getProviderConfig($modelInfo['provider_id']);
            if (!$provider) {
                throw new Exception("找不到该模型的服务供应商配置。");
            }

            // 4. 执行 API 请求
            $response = $this->callExternalApi($provider, $modelAlias, $prompt);

            // 5. 扣除该教师在该模型下的额度 (used_quota + 1)
            $this->consumeQuota($quota['quota_record_id']);

            return [
                'success' => true,
                'content' => $response,
                'model'   => $modelAlias
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error'   => $e->getMessage()
            ];
        }
    }

    /**
     * 从资料库验证模型标识
     */
    private function getAndValidateModel($alias)
    {
        $stmt = $this->db->prepare("SELECT * FROM ai_models WHERE model_alias = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$alias]);
        return $stmt->fetch();
    }

    /**
     * 检查教师在特定模型上的授权记录
     */
    private function checkTeacherQuota($teacherId, $modelId)
    {
        $stmt = $this->db->prepare("
            SELECT Id, max_quota, used_quota, is_enabled, expire_time 
            FROM teacher_model_quotas 
            WHERE teacherid = ? AND modelid = ? 
            LIMIT 1
        ");
        $stmt->execute([$teacherId, $modelId]);
        $record = $stmt->fetch();

        if (!$record) {
            return ['can_use' => false, 'reason' => '您尚未获得调用此模型的权限。'];
        }

        if ($record['is_enabled'] == 0) {
            return ['can_use' => false, 'reason' => '管理员已暂停您的此模型使用权限。'];
        }

        if ($record['expire_time'] && $record['expire_time'] < time()) {
            return ['can_use' => false, 'reason' => '您的 AI 模型授权已过期。'];
        }

        // max_quota = 0 代表无限额度
        if ($record['max_quota'] > 0 && $record['used_quota'] >= $record['max_quota']) {
            return ['can_use' => false, 'reason' => '您的使用额度已达上限。'];
        }

        return [
            'can_use' => true,
            'quota_record_id' => $record['Id']
        ];
    }

    /**
     * 获取 API 供应商配置
     */
    private function getProviderConfig($providerId)
    {
        $stmt = $this->db->prepare("SELECT api_key, base_url FROM ai_providers WHERE Id = ? AND is_active = 1");
        $stmt->execute([$providerId]);
        return $stmt->fetch();
    }

    /**
     * 更新额度使用记录
     */
    private function consumeQuota($quotaId)
    {
        $stmt = $this->db->prepare("UPDATE teacher_model_quotas SET used_quota = used_quota + 1 WHERE Id = ?");
        $stmt->execute([$quotaId]);
    }

    /**
     * 封装 cURL 调用外部 AI API (OpenAI 兼容协议)
     */
    private function callExternalApi($provider, $model, $prompt)
    {
        $ch = curl_init();
        $url = rtrim($provider['base_url'], '/') . '/chat/completions';

        $postData = [
            'model' => $model,
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'temperature' => 0.7
        ];

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $provider['api_key']
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60); // 设置超时时间

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            $errorMsg = curl_error($ch);
            curl_close($ch);
            throw new Exception("网路通讯错误: " . $errorMsg);
        }
        curl_close($ch);

        $json = json_decode($result, true);

        if ($httpCode !== 200) {
            $msg = $json['error']['message'] ?? '未知错误';
            throw new Exception("API 伺服器返回错误 ($httpCode): " . $msg);
        }

        if (isset($json['choices'][0]['message']['content'])) {
            return $json['choices'][0]['message']['content'];
        }

        throw new Exception("无法从 AI 回应中提取内容。");
    }
}

/* 
<?php
require_once 'database.php';   // 初始化 $db 连接与 auth 函数
require_once 'AIService.php';  // 引入 AI 服务

// 1. 强制教师身分验证，并获取教师资讯
$teacher = require_teacher_auth(); 

// 2. 初始化 AI 服务
$aiService = new AIService($db);

// 3. 获取前端提交的内容或从资料库读取的作业
$studentWork = "这是学生的作业内容...";
$prompt = "请以专业老师的角度，为以下作业提供 200 字以内的点评：" . $studentWork;

// 4. 指定模型并调用 (此模型必须已在 ai_models 定义，且老师在 teacher_model_quotas 有额度)
$response = $aiService->askAI($teacher['Id'], 'gpt-4o', $prompt);

if ($response['success']) {
    // 成功：将 $response['content'] 显示或存入 homeworkcheck 表
    echo "AI 点评成功：" . $response['content'];
} else {
    // 失败：可能是额度不足、模型不存在或网络问题
    echo "AI 调用失败：" . $response['error'];
}

这个设计完美结合了你现有的 `database.php`：
1.  **身分连动**：直接使用 `require_teacher_auth()` 返回的 `$teacher['Id']` 进行权限比对。
2.  **连线复用**：不需要重复创建 PDO 连接，减少资源消耗。
3.  **模型隔离**：你可以随时在资料库中为某位老师单独开启 `deepseek-v3` 或关闭 `gpt-4`。