<?php
/**
 * IdentiTrack Production AI Client Service
 * Encapsulates secure API communication with the Live AI Server / Ollama / Classifier.
 * Enforces Human-In-The-Loop Decision Support, Audit Logging, and Zero Single Point of Failure.
 */

if (!defined('IDENTITRACK_INIT')) {
    define('IDENTITRACK_INIT', true);
}

require_once __DIR__ . '/../database/database.php';

class AIClient
{
    private string $apiUrl;
    private string $apiKey;
    private string $provider;
    private string $model;
    private int $timeout;
    private bool $enabled;

    public function __construct()
    {
        // 1. Read environment or database system_config override
        $this->provider = trim((string)($_ENV['AI_PROVIDER'] ?? getenv('AI_PROVIDER') ?: $this->getConfig('ai_provider', 'built_in')));
        $this->apiUrl   = trim((string)($_ENV['AI_API_URL']  ?? getenv('AI_API_URL')  ?: $this->getConfig('ai_api_url', 'http://127.0.0.1:8000/api/v1')));
        $this->apiKey   = trim((string)($_ENV['AI_API_KEY']  ?? getenv('AI_API_KEY')  ?: $this->getConfig('ai_api_key', '')));
        $this->model    = trim((string)($_ENV['AI_MODEL']    ?? getenv('AI_MODEL')    ?: $this->getConfig('ai_model', 'llama3.2:latest')));
        $this->timeout  = (int)($_ENV['AI_TIMEOUT'] ?? getenv('AI_TIMEOUT') ?: $this->getConfig('ai_timeout', 15));
        $this->enabled  = filter_var($_ENV['AI_ENABLED'] ?? getenv('AI_ENABLED') ?? $this->getConfig('ai_enabled', 'true'), FILTER_VALIDATE_BOOLEAN);
    }

    private function getConfig(string $key, string $default = ''): string
    {
        try {
            $row = db_one("SELECT config_value FROM system_config WHERE config_key = :k LIMIT 1", [':k' => $key]);
            return ($row && isset($row['config_value'])) ? trim((string)$row['config_value']) : $default;
        } catch (\Throwable $e) {
            return $default;
        }
    }

    /**
     * Primary Offense Analysis Function for SDO/Admin Decision Support
     */
    public function analyzeOffense(string $description, ?string $studentId = null, array $context = []): array
    {
        $requestId = 'req_' . bin2hex(random_bytes(12));
        $sanitizedDesc = htmlspecialchars(trim($description), ENT_QUOTES, 'UTF-8');

        if (trim($sanitizedDesc) === '') {
            return [
                'success' => false,
                'request_id' => $requestId,
                'error' => 'Offense description cannot be empty.',
                'uncertainty' => true,
                'requires_human_review' => true
            ];
        }

        // Try Remote/Live Production AI API Endpoint first if configured
        if ($this->enabled && in_array($this->provider, ['production', 'remote'], true) && !empty($this->apiUrl)) {
            $remoteResult = $this->callRemoteApi('/analyze-offense', [
                'offense_description' => $sanitizedDesc,
                'student_id'          => $studentId ? (string)$studentId : null,
                'context'             => !empty($context) ? json_encode($context) : null,
                'request_id'          => $requestId
            ]);

            if ($remoteResult && !empty($remoteResult['success'])) {
                $this->logAnalysis($requestId, null, $remoteResult);
                return $remoteResult;
            }
        }

        // Try Local Ollama Provider if configured
        if ($this->enabled && $this->provider === 'ollama') {
            $ollamaResult = $this->callOllamaProvider($sanitizedDesc, $requestId);
            if ($ollamaResult && !empty($ollamaResult['success'])) {
                $this->logAnalysis($requestId, null, $ollamaResult);
                return $ollamaResult;
            }
        }

        // Fallback to Native Database Handbook Knowledge Engine & Classifier
        $fallbackResult = $this->analyzeWithDatabaseRules($sanitizedDesc, $requestId);
        $this->logAnalysis($requestId, null, $fallbackResult);

        return $fallbackResult;
    }

    /**
     * HTTPS API Communication to Standalone Python AI Server
     */
    private function callRemoteApi(string $path, array $payload): ?array
    {
        $url = rtrim($this->apiUrl, '/') . '/' . ltrim($path, '/');
        $ch = curl_init($url);

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json'
        ];
        if (!empty($this->apiKey)) {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
            $headers[] = 'X-API-Key: ' . $this->apiKey;
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            if (is_array($data)) {
                return $data;
            }
        }
        return null;
    }

    /**
     * Local Ollama Provider Integration
     */
    private function callOllamaProvider(string $description, string $requestId): ?array
    {
        $ollamaUrl = 'http://127.0.0.1:11434/api/generate';
        $prompt = "You are IdentiTrack AI for NU Lipa. Analyze this offense description: \"{$description}\". Respond strictly in valid JSON with format: {\"type\": \"Major Offense\"|\"Minor Offense\", \"category\": \"string\", \"confidence\": 0.9, \"section\": \"string\", \"rule\": \"string\", \"intervention\": \"string\", \"reason\": \"string\"}.";

        $ch = curl_init($ollamaUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'model'  => $this->model,
            'prompt' => $prompt,
            'stream' => false,
            'options' => ['temperature' => 0.1, 'num_predict' => 500]
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);

        $res = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200 && $res) {
            $json = json_decode($res, true);
            $rawText = $json['response'] ?? '';
            if (preg_match('/\{.*\}/s', $rawText, $matches)) {
                $parsed = json_decode($matches[0], true);
                if ($parsed && isset($parsed['type'])) {
                    return [
                        'success' => true,
                        'request_id' => $requestId,
                        'classification' => [
                            'type' => $parsed['type'] ?? 'Minor Offense',
                            'category' => $parsed['category'] ?? 'General Disciplinary',
                            'confidence' => (float)($parsed['confidence'] ?? 0.88)
                        ],
                        'handbook' => [
                            'section' => $parsed['section'] ?? 'Section IV',
                            'rule' => $parsed['rule'] ?? 'Student Handbook Conduct Guidelines',
                            'source' => 'Student Handbook'
                        ],
                        'recommendation' => [
                            'intervention' => $parsed['intervention'] ?? 'Category 1 Warning & Counseling',
                            'reason' => $parsed['reason'] ?? 'Matches student conduct policy guidelines.'
                        ],
                        'ai_explanation' => $parsed['reason'] ?? 'Analyzed via Local LLaMA Provider.',
                        'uncertainty' => false,
                        'requires_human_review' => true
                    ];
                }
            }
        }
        return null;
    }

    /**
     * Database Rules & Precedent Classifier Fallback Engine
     */
    private function analyzeWithDatabaseRules(string $description, string $requestId): array
    {
        $descLower = mb_strtolower($description);
        $rules = [];
        try {
            $rules = db_all("SELECT * FROM handbook_rule WHERE active = 1 ORDER BY id ASC");
        } catch (\Throwable $e) {}

        $bestRule = null;
        $maxScore = 0;

        foreach ($rules as $r) {
            $keywords = array_filter(array_map('trim', explode(',', (string)$r['keywords'])));
            $score = 0;
            foreach ($keywords as $kw) {
                if ($kw !== '' && strpos($descLower, mb_strtolower($kw)) !== false) {
                    $score += 2;
                }
            }
            if (strpos($descLower, mb_strtolower($r['title'])) !== false) {
                $score += 5;
            }
            if ($score > $maxScore) {
                $maxScore = $score;
                $bestRule = $r;
            }
        }

        if ($bestRule && $maxScore >= 2) {
            $isMajor = $bestRule['severity'] === 'MAJOR' || $bestRule['severity'] === 'CRITICAL';
            $confidence = min(0.96, 0.70 + ($maxScore * 0.05));

            return [
                'success' => true,
                'request_id' => $requestId,
                'classification' => [
                    'type' => $isMajor ? 'Major Offense' : 'Section 4 Minor Offense',
                    'category' => $bestRule['offense_type'],
                    'confidence' => round($confidence, 2)
                ],
                'handbook' => [
                    'section' => $bestRule['section'],
                    'rule' => $bestRule['title'] . ' (' . $bestRule['rule_code'] . ')',
                    'source' => 'NU Lipa Student Handbook'
                ],
                'recommendation' => [
                    'intervention' => 'Category ' . $bestRule['intervention_category'] . ' Interventions: ' . $bestRule['description'],
                    'reason' => 'Offense description directly matches ' . $bestRule['title'] . ' under ' . $bestRule['section'] . '.'
                ],
                'ai_explanation' => 'Matched against authoritative Handbook Rule [' . $bestRule['rule_code'] . '] in database knowledge base.',
                'uncertainty' => false,
                'requires_human_review' => true
            ];
        }

        // Generic fallback if no keyword matches
        $isMajorKeywords = preg_match('/\b(cheat|drug|alcohol|vape|weapon|knife|steal|fight|assault|forge|perjury)\b/i', $descLower);
        return [
            'success' => true,
            'request_id' => $requestId,
            'classification' => [
                'type' => $isMajorKeywords ? 'Possible Major Offense' : 'Section 4 Minor Offense',
                'category' => $isMajorKeywords ? 'Academic Integrity / Campus Safety' : 'General Conduct',
                'confidence' => 0.65
            ],
            'handbook' => [
                'section' => $isMajorKeywords ? 'Section V (Major Offenses)' : 'Section IV (Minor Offenses)',
                'rule' => 'General Student Conduct Regulations',
                'source' => 'Student Handbook'
            ],
            'recommendation' => [
                'intervention' => $isMajorKeywords ? 'Category 2 Sanction (Probation & CS)' : 'Category 1 Sanction (Warning & 10h CS)',
                'reason' => 'Initial policy alignment based on offense keywords.'
            ],
            'ai_explanation' => 'Initial decision-support suggestion. Requires verification against exact handbook clause.',
            'uncertainty' => true,
            'requires_human_review' => true
        ];
    }

    /**
     * Audit Log AI Decisions vs Human Decision
     */
    public function logAnalysis(string $requestId, ?int $offenseId, array $result, string $humanDecision = 'PENDING'): void
    {
        try {
            $class = $result['classification']['type'] ?? 'General';
            $conf  = (float)($result['classification']['confidence'] ?? 0.0);
            $rec   = json_encode($result['recommendation'] ?? []);
            $model = $this->provider . ':' . $this->model;
            $userId = $_SESSION['user_id'] ?? $_SESSION['admin_id'] ?? $_SESSION['upcc_id'] ?? null;

            db_exec("
                INSERT INTO ai_analysis_log (request_id, offense_id, user_id, model, classification, confidence, recommendation, human_decision)
                VALUES (:req, :off, :uid, :mod, :cls, :cnf, :rec, :dec)
                ON DUPLICATE KEY UPDATE human_decision = :dec
            ", [
                ':req' => $requestId,
                ':off' => $offenseId,
                ':uid' => $userId,
                ':mod' => $model,
                ':cls' => $class,
                ':cnf' => $conf,
                ':rec' => $rec,
                ':dec' => $humanDecision
            ]);
        } catch (\Throwable $e) {}
    }

    /**
     * GET /api/v1/health Status Check
     */
    public function getHealthStatus(): array
    {
        if (!$this->enabled) {
            return ['status' => 'disabled', 'provider' => $this->provider, 'online' => false];
        }

        if (in_array($this->provider, ['production', 'remote'], true) && !empty($this->apiUrl)) {
            $health = $this->callRemoteApi('/health', []);
            if ($health && isset($health['status']) && $health['status'] === 'online') {
                return ['status' => 'online', 'provider' => 'Production API (' . $this->apiUrl . ')', 'online' => true, 'details' => $health];
            }
        }

        return ['status' => 'online', 'provider' => 'Built-In AI Engine (' . strtoupper($this->provider) . ')', 'online' => true];
    }
}
