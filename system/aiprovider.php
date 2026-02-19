<?php

/**
 * AIService.php 
 * 内部 AI 呼叫核心类别 - 支援图片输入与多模态内容 (OpenRouter 相容版)
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

        $available = [];
        foreach ($rows as $row) {
            if ($row['max_quota'] == 0 || $row['used_quota'] < $row['max_quota']) {
                $available[] = $row;
            }
        }
        return $available;
    }

    /**
     * 核心方法：向 AI 发问
     * 支援传入图片资料 (Base64)
     */
    public function askAI($teacherId, $modelAlias, $prompt, $imageBase64 = null)
    {
        try {
            // 1. 验证权限与模型 - 联集 ai_providers 获取 API 金钥与 Base URL
            $sql = "
                SELECT q.*, m.model_name, p.api_key, p.base_url, p.provider_name
                FROM teacher_model_quotas q
                JOIN ai_models m ON q.modelid = m.Id
                JOIN ai_providers p ON m.provider_id = p.Id
                WHERE q.teacherid = ? AND m.model_alias = ? AND q.is_enabled = 1
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$teacherId, $modelAlias]);
            $config = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$config) throw new Exception("无权限使用此模型或模型不存在");
            if ($config['max_quota'] > 0 && $config['used_quota'] >= $config['max_quota']) throw new Exception("额度已用完");
            if ($config['expire_time'] && $config['expire_time'] < time()) throw new Exception("授权已过期");

            // 2. 呼叫外部 API
            // 已根据您的要求，撤销 model_name 修正，改回使用 $modelAlias
            $response = $this->callExternalApi($config, $modelAlias, $prompt, $imageBase64);

            // 3. 扣除额度
            $updateSql = "UPDATE teacher_model_quotas SET used_quota = used_quota + 1 WHERE Id = ?";
            $this->db->prepare($updateSql)->execute([$config['Id']]);

            return ['success' => true, 'content' => $response];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 实际发送 HTTP 请求 (OpenRouter 相容版本)
     */
    private function callExternalApi($provider, $model, $prompt, $imageBase64)
    {
        $ch = curl_init();
        $url = rtrim($provider['base_url'], '/') . '/chat/completions';

        // 构建多模态内容
        $messageContent = [];
        $messageContent[] = ["type" => "text", "text" => $prompt];

        if ($imageBase64) {
            // 智慧判断：检查是否已有 data: 标头，避免重复拼接
            if (strpos($imageBase64, 'data:') === false) {
                $imageBase64 = 'data:image/jpeg;base64,' . $imageBase64;
            }
            $messageContent[] = [
                "type" => "image_url",
                "image_url" => ["url" => $imageBase64]
            ];
        }

        $postData = [
            'model' => $model, // 使用传入的 model_alias
            'messages' => [
                ['role' => 'user', 'content' => $messageContent]
            ],
            'temperature' => 0.1
        ];
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $provider['api_key'],
            'HTTP-Referer: http://localhost', // OpenRouter 建议标头
            'X-Title: Teacher Grading System'  // OpenRouter 建议标头
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
            $msg = $json['error']['message'] ?? "API 伺服器回报错误 (HTTP $httpCode)";
            throw new Exception("AI 供应商错误: " . $msg);
        }

        return $json['choices'][0]['message']['content'] ?? "";
    }
}
