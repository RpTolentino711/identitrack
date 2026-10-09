api_path = r'c:\xampp\htdocs\identitrack\admin\api_ai_suggest_sanction.php'
with open(api_path, 'r', encoding='utf-8') as f:
    content = f.read()

# 1. Remove global_chat block
global_chat_block = """    // ── ACTION: global_chat — Standalone Global AI Precedent & Analytics Hub ──
    if ($action === 'global_chat') {
        if ($userQuery === '') {
            echo json_encode(['ok' => false, 'error' => 'Please type a question for the AI Assistant.']);
            exit;
        }

        $aiEngineRes = queryAiEngine('', $userQuery);

        echo json_encode([
            'ok' => true,
            'action' => 'global_chat',
            'query' => $userQuery,
            'reply' => $aiEngineRes['text'],
            'ai_available' => true,
            'engine' => $aiEngineRes['engine'],
            'privacy' => $aiEngineRes['privacy']
        ]);
        exit;
    }"""

if global_chat_block in content:
    content = content.replace(global_chat_block, "", 1)
    print("global_chat block removed")
else:
    print("global_chat block not matched exactly")

# 2. Clean up $action !== 'global_chat' checks
content = content.replace("if ($rawCaseId === '' && $studentId === '' && $action !== 'global_chat')", "if ($rawCaseId === '' && $studentId === '')")
content = content.replace("if (!$case && $action !== 'global_chat')", "if (!$case)")

with open(api_path, 'w', encoding='utf-8') as f:
    f.write(content)
print("api_ai_suggest_sanction.php updated")
