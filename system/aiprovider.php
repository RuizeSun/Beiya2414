<?php

/**
 * AIService.php 
 * 内部 AI 调用核心类别
 */

class AIService
{
    private $db;

    public function __construct($databaseConnection)
    {
        $this->db = $databaseConnection;
    }

    /**
     * 获取指定老师可用的所有 AI 模型
     */
    public function getAvailableModels($teacherId)
    {
        // 联表查询：查找老师有额度且未过期、且系统已启用的模型
        $sql = "
            SELECT m.model_alias, m.model_name, q.used_quota, q.max_quota 
            FROM teacher_model_quotas q
            JOIN ai_models m ON q.modelid = m.Id
            WHERE q.teacherid = ? 
            AND q.is_enabled = 1 
            AND m.is_active = 1
            AND (q.expire_time IS NULL OR q.expire_time > ?)
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$teacherId, time()]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 过滤掉已用完额度的 (max_quota = 0 表示无限)
        $available = [];
        foreach ($rows as $row) {
            if ($row['max_quota'] == 0 || $row['used_quota'] < $row['max_quota']) {
                $available[] = $row;
            }
        }
        return $available;
    }

    /**
     * 调用 AI 模型的主要入口
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

    private function getAndValidateModel($alias)
    {
        $stmt = $this->db->prepare("SELECT * FROM ai_models WHERE model_alias = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$alias]);
        return $stmt->fetch();
    }

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

        if ($record['max_quota'] > 0 && $record['used_quota'] >= $record['max_quota']) {
            return ['can_use' => false, 'reason' => '您的使用额度已达上限。'];
        }

        return [
            'can_use' => true,
            'quota_record_id' => $record['Id']
        ];
    }

    private function getProviderConfig($providerId)
    {
        $stmt = $this->db->prepare("SELECT api_key, base_url FROM ai_providers WHERE Id = ? AND is_active = 1");
        $stmt->execute([$providerId]);
        return $stmt->fetch();
    }

    private function consumeQuota($quotaId)
    {
        $stmt = $this->db->prepare("UPDATE teacher_model_quotas SET used_quota = used_quota + 1 WHERE Id = ?");
        $stmt->execute([$quotaId]);
    }

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
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

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
