<?php
/**
 * OpenAI API 中转代理
 * 功能：修复 Claude 工具格式、强制非流式请求上游、模拟流式输出
 */

// ============ 配置常量 ============
define('UPSTREAM_URL', 'https://ai.hybgzs.com/v1/chat/completions');
define('CURL_TIMEOUT', 600);
define('CURL_CONNECT_TIMEOUT', 60);
define('STREAM_CHUNK_SIZE', 200);  // 流式分片字符数（按字符，非字节）

// ============ 运行时设置 ============
error_reporting(0);
ini_set('display_errors', '0');
set_time_limit(CURL_TIMEOUT);
ini_set('memory_limit', '512M');
ignore_user_abort(true);

// ============ 读取请求 ============
$requestBody = file_get_contents('php://input');
$requestData = json_decode($requestBody, true);
if (!$requestData) {
    http_response_code(400);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['error' => ['message' => 'Invalid JSON request body', 'type' => 'invalid_request_error']], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 修复 Claude 工具调用格式
 * - 空描述补充默认值
 * - 空 properties 转为对象
 * - 确保 required 是数组
 */
function fixClaudeTools(array &$data): void {
    if (!isset($data['tools']) || !is_array($data['tools'])) {
        return;
    }

    foreach ($data['tools'] as &$tool) {
        if (!isset($tool['function']) || !is_array($tool['function'])) {
            continue;
        }
        $func = &$tool['function'];

        // 1. 修复空描述：Claude 严禁 description 为空
        if (!isset($func['description']) || trim((string)$func['description']) === '') {
            $func['description'] = 'No description provided.';
        }

        // 2. 强制参数结构：如果不存在 parameters，补充默认结构
        if (!isset($func['parameters']) || !is_array($func['parameters'])) {
            $func['parameters'] = [
                'type' => 'object',
                'properties' => new \stdClass(),
                'required' => []
            ];
            continue;
        }

        // 3. 修复 properties 数组问题（空数组转对象）
        if (!isset($func['parameters']['properties'])) {
            $func['parameters']['properties'] = new \stdClass();
        } elseif (is_array($func['parameters']['properties']) && empty($func['parameters']['properties'])) {
            $func['parameters']['properties'] = new \stdClass();
        }

        // 4. 确保 required 是数组而非 null
        if (!isset($func['parameters']['required']) || !is_array($func['parameters']['required'])) {
            $func['parameters']['required'] = [];
        }
    }
}

fixClaudeTools($requestData);

$clientWantsStream = !empty($requestData['stream']);
$includeUsage = !empty($requestData['stream_options']['include_usage']);

// ============ 获取 Authorization ============
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if (empty($auth) && function_exists('apache_request_headers')) {
    $headers = apache_request_headers();
    $auth = $headers['Authorization'] ?? '';
}

/**
 * 关键：无论客户端要不要 stream，上游都强制用非流式拿完整结果
 * 这样规避"上游流式只回 usage / 空 choices"的不稳定。
 */
$upReq = $requestData;
$upReq['stream'] = false;

// ============ 请求上游 ============
$ch = curl_init(UPSTREAM_URL);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($upReq, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: ' . $auth,
        'Accept-Encoding: identity',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_HEADER => false,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => CURL_TIMEOUT,
    CURLOPT_CONNECTTIMEOUT => CURL_CONNECT_TIMEOUT,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
]);

$upBody = curl_exec($ch);
$upCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$curlError = curl_error($ch);
$curlErrno = curl_errno($ch);
curl_close($ch);

// ============ cURL 错误处理 ============
if ($curlErrno !== 0) {
    $errorResponse = [
        'error' => [
            'message' => 'Upstream request failed: ' . $curlError,
            'type' => 'upstream_error',
            'code' => 'curl_error_' . $curlErrno
        ]
    ];
    http_response_code(502);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($errorResponse, JSON_UNESCAPED_UNICODE);
    exit;
}

// ============ 非流式响应 ============
if (!$clientWantsStream) {
    if ($upCode) {
        http_response_code($upCode);
    }
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-cache');
    echo $upBody;
    flush();
    exit;
}

// ============ SSE 流式输出模式 ============
header('Content-Type: text/event-stream; charset=UTF-8');
header('Cache-Control: no-cache, no-transform');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');
header('Content-Encoding: none');

ini_set('zlib.output_compression', '0');
ini_set('output_buffering', 'Off');
ini_set('implicit_flush', '1');
while (ob_get_level() > 0) {
    ob_end_flush();
}
ob_implicit_flush(true);

/**
 * 发送 SSE 事件
 */
function sendSSE(array $data): void {
    echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n\n";
    if (ob_get_level() > 0) {
        ob_flush();
    }
    flush();
}

// 先发 padding，减少 fcgi/apache 缓冲影响
echo ":" . str_repeat(" ", 2048) . "\n\n";
flush();

$upJson = json_decode($upBody, true);
if (!$upJson) {
    // 上游不是 JSON 或报错：用 SSE 形式给客户端一个错误事件
    $err = [
        'id' => 'proxy_err_' . time(),
        'object' => 'chat.completion.chunk',
        'created' => time(),
        'model' => $requestData['model'] ?? 'unknown',
        'choices' => [[
            'index' => 0,
            'delta' => ['content' => '[proxy] upstream returned non-JSON: ' . substr($upBody, 0, 200)],
            'finish_reason' => 'stop'
        ]]
    ];
    sendSSE($err);
    echo "data: [DONE]\n\n";
    flush();
    exit;
}

$id = $upJson['id'] ?? ('chatcmpl_' . bin2hex(random_bytes(8)));
$model = $upJson['model'] ?? ($requestData['model'] ?? 'unknown');
$created = $upJson['created'] ?? time();

$choice0 = $upJson['choices'][0] ?? [];
$msg = $choice0['message'] ?? [];
$content = $msg['content'] ?? '';
$toolCalls = $msg['tool_calls'] ?? null;
$finish = $choice0['finish_reason'] ?? 'stop';

// 1) role chunk
$chunk = [
    'id' => $id,
    'object' => 'chat.completion.chunk',
    'created' => $created,
    'model' => $model,
    'choices' => [[
        'index' => 0,
        'delta' => ['role' => 'assistant'],
        'finish_reason' => null
    ]]
];
sendSSE($chunk);

// 2) tool_calls（按 OpenAI 规范分片输出，每个带 index）
if (is_array($toolCalls) && count($toolCalls) > 0) {
    foreach ($toolCalls as $tcIndex => $tc) {
        // 第一个 chunk：包含 id、type、function.name
        $chunk = [
            'id' => $id,
            'object' => 'chat.completion.chunk',
            'created' => $created,
            'model' => $model,
            'choices' => [[
                'index' => 0,
                'delta' => [
                    'tool_calls' => [[
                        'index' => $tcIndex,
                        'id' => $tc['id'] ?? ('call_' . bin2hex(random_bytes(8))),
                        'type' => 'function',
                        'function' => [
                            'name' => $tc['function']['name'] ?? '',
                            'arguments' => ''
                        ]
                    ]]
                ],
                'finish_reason' => null
            ]]
        ];
        sendSSE($chunk);

        // 第二个 chunk：arguments 内容
        $args = $tc['function']['arguments'] ?? '';
        if ($args !== '') {
            $chunk = [
                'id' => $id,
                'object' => 'chat.completion.chunk',
                'created' => $created,
                'model' => $model,
                'choices' => [[
                    'index' => 0,
                    'delta' => [
                        'tool_calls' => [[
                            'index' => $tcIndex,
                            'function' => [
                                'arguments' => $args
                            ]
                        ]]
                    ],
                    'finish_reason' => null
                ]]
            ];
            sendSSE($chunk);
        }
    }
}

// 3) content 分片输出（使用 mb_substr 确保 UTF-8 安全）
if (is_string($content) && $content !== '') {
    $len = mb_strlen($content, 'UTF-8');
    for ($i = 0; $i < $len; $i += STREAM_CHUNK_SIZE) {
        $part = mb_substr($content, $i, STREAM_CHUNK_SIZE, 'UTF-8');
        $chunk = [
            'id' => $id,
            'object' => 'chat.completion.chunk',
            'created' => $created,
            'model' => $model,
            'choices' => [[
                'index' => 0,
                'delta' => ['content' => $part],
                'finish_reason' => null
            ]]
        ];
        sendSSE($chunk);
    }
}

// 4) finish chunk
$chunk = [
    'id' => $id,
    'object' => 'chat.completion.chunk',
    'created' => $created,
    'model' => $model,
    'choices' => [[
        'index' => 0,
        'delta' => new \stdClass(),
        'finish_reason' => $finish
    ]]
];
sendSSE($chunk);

// 5) include_usage：结尾 usage chunk（choices 为空是正常的）
if ($includeUsage && isset($upJson['usage'])) {
    $chunk = [
        'id' => $id,
        'object' => 'chat.completion.chunk',
        'created' => $created,
        'model' => $model,
        'choices' => [],
        'usage' => $upJson['usage']
    ];
    sendSSE($chunk);
}

echo "data: [DONE]\n\n";
flush();
