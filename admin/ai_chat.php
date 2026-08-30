<?php
/**
 * IdentiTrack Live Conversational AI Chat Interface (ChatGPT-Style)
 * Full multi-session memory, RAG Student Handbook citations, and live status monitoring.
 */
session_start();
require_once __DIR__ . '/../database/database.php';
require_once __DIR__ . '/../includes/AIClient.php';

$aiClient = new AIClient();

// Handle AJAX Chat API Endpoints
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    if ($action === 'send_message') {
        $msg = trim((string)($_POST['message'] ?? ''));
        $convUuid = trim((string)($_POST['conversation_uuid'] ?? ''));
        $res = $aiClient->chat($msg, $convUuid);
        echo json_encode($res);
        exit;
    }

    if ($action === 'get_messages') {
        $uuid = trim((string)($_POST['conversation_uuid'] ?? ''));
        $conv = db_one("SELECT id FROM ai_conversation WHERE conversation_uuid = :uuid LIMIT 1", [':uuid' => $uuid]);
        if ($conv) {
            $history = $aiClient->getConversationHistory((int)$conv['id']);
            echo json_encode(['success' => true, 'messages' => $history]);
        } else {
            echo json_encode(['success' => false, 'messages' => []]);
        }
        exit;
    }

    if ($action === 'new_chat') {
        $newConv = $aiClient->getOrCreateConversation(null, $_SESSION['user_id'] ?? null, 'New Conversation');
        echo json_encode(['success' => true, 'conversation_uuid' => $newConv['conversation_uuid']]);
        exit;
    }
}

$health = $aiClient->getHealthStatus();
$conversations = $aiClient->getUserConversations();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IdentiTrack AI | Live Conversational Assistant</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-dark: #090d16;
            --sidebar-bg: #0f172a;
            --panel-bg: rgba(30, 41, 59, 0.7);
            --border-glass: rgba(255, 255, 255, 0.1);
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --accent-blue: #3b82f6;
            --accent-emerald: #10b981;
            --user-bubble: #1e3a8a;
            --ai-bubble: rgba(30, 41, 59, 0.8);
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Plus Jakarta Sans', sans-serif; }
        body { background: var(--bg-dark); color: var(--text-main); height: 100vh; display: flex; overflow: hidden; }

        /* SIDEBAR */
        .sidebar { width: 280px; background: var(--sidebar-bg); border-right: 1px solid var(--border-glass); display: flex; flex-direction: column; padding: 16px; gap: 16px; }
        .sidebar-header { display: flex; align-items: center; justify-content: space-between; }
        .sidebar-title { font-size: 16px; font-weight: 800; display: flex; align-items: center; gap: 8px; }
        .btn-new-chat { background: var(--accent-blue); color: #fff; border: none; padding: 10px 14px; border-radius: 10px; font-weight: 700; font-size: 13px; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px; width: 100%; transition: all 0.2s; }
        .btn-new-chat:hover { background: #2563eb; }

        .conv-list { display: flex; flex-direction: column; gap: 6px; overflow-y: auto; flex: 1; }
        .conv-item { padding: 10px 12px; border-radius: 8px; font-size: 13px; color: var(--text-muted); cursor: pointer; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; border: 1px solid transparent; }
        .conv-item:hover, .conv-item.active { background: rgba(255, 255, 255, 0.05); color: #fff; border-color: var(--border-glass); }

        /* MAIN CHAT AREA */
        .chat-container { flex: 1; display: flex; flex-direction: column; height: 100vh; position: relative; }
        .chat-topbar { padding: 16px 24px; border-bottom: 1px solid var(--border-glass); background: rgba(15, 23, 42, 0.8); display: flex; align-items: center; justify-content: space-between; backdrop-filter: blur(12px); }
        .status-pill { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: 16px; font-size: 12px; font-weight: 700; }
        .status-online { background: rgba(16, 185, 129, 0.15); color: #6ee7b7; border: 1px solid rgba(16, 185, 129, 0.3); }

        .chat-messages { flex: 1; overflow-y: auto; padding: 24px; display: flex; flex-direction: column; gap: 20px; }
        
        .msg-row { display: flex; gap: 12px; max-width: 800px; margin: 0 auto; width: 100%; }
        .msg-row.user { justify-content: flex-end; }
        
        .avatar { width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 14px; flex-shrink: 0; }
        .avatar-ai { background: linear-gradient(135deg, #3b82f6, #10b981); color: #fff; }
        .avatar-user { background: #475569; color: #fff; }

        .bubble { padding: 14px 18px; border-radius: 14px; font-size: 14px; line-height: 1.6; max-width: 85%; }
        .user .bubble { background: var(--user-bubble); color: #fff; border-bottom-right-radius: 4px; }
        .assistant .bubble { background: var(--ai-bubble); border: 1px solid var(--border-glass); border-bottom-left-radius: 4px; color: var(--text-main); }

        .source-card { margin-top: 8px; background: rgba(15, 23, 42, 0.8); border: 1px solid rgba(59, 130, 246, 0.3); border-radius: 8px; padding: 10px 12px; font-size: 12px; color: #93c5fd; }
        
        .tool-indicator { font-size: 12px; color: #fcd34d; font-style: italic; display: flex; align-items: center; gap: 6px; }

        /* INPUT BAR */
        .chat-input-area { padding: 16px 24px; border-top: 1px solid var(--border-glass); background: rgba(15, 23, 42, 0.9); }
        .input-box { max-width: 800px; margin: 0 auto; display: flex; gap: 10px; background: rgba(30, 41, 59, 0.8); border: 1px solid var(--border-glass); border-radius: 14px; padding: 8px 12px; }
        textarea { flex: 1; background: transparent; border: none; outline: none; color: #fff; font-size: 14px; resize: none; max-height: 120px; height: 40px; padding: 8px; }
        .btn-send { background: var(--accent-blue); color: #fff; border: none; width: 40px; height: 40px; border-radius: 10px; font-weight: 700; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s; }
        .btn-send:hover { background: #2563eb; }

        .disclaimer-footer { text-align: center; font-size: 11px; color: var(--text-muted); margin-top: 8px; }
    </style>
</head>
<body>

<!-- SIDEBAR -->
<div class="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-title"><span>🤖</span> IdentiTrack AI</div>
    </div>
    <button class="btn-new-chat" onclick="createNewChat()"><span>+</span> New Chat</button>
    <div class="conv-list" id="convList">
        <?php foreach ($conversations as $c): ?>
            <div class="conv-item" onclick="loadChat('<?= htmlspecialchars($c['conversation_uuid']) ?>', this)">
                💬 <?= htmlspecialchars($c['title']) ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- MAIN CHAT CONTAINER -->
<div class="chat-container">
    <!-- TOP BAR -->
    <div class="chat-topbar">
        <div style="font-weight:700; font-size:15px; display:flex; align-items:center; gap:8px;">
            <span>IdentiTrack Conversational AI</span>
            <span style="font-size:12px; color:var(--text-muted);">(NU Lipa Handbook Decision Support)</span>
        </div>
        <div class="status-pill status-online">
            <span>●</span> <span>AI ONLINE</span>
        </div>
    </div>

    <!-- MESSAGES AREA -->
    <div class="chat-messages" id="chatMessages">
        <div class="msg-row assistant">
            <div class="avatar avatar-ai">AI</div>
            <div class="bubble">
                👋 **Hello! I am IdentiTrack AI**, your official conversational assistant for NU Lipa.<br><br>
                I can help you review Student Handbook rules, calculate community service hours, analyze reported offenses, and lookup 204 case precedents.<br><br>
                How can I assist your case review today?
            </div>
        </div>
    </div>

    <!-- INPUT AREA -->
    <div class="chat-input-area">
        <div class="input-box">
            <textarea id="msgInput" placeholder="Ask IdentiTrack AI a question or paste an offense description..." onkeydown="handleKeyDown(event)"></textarea>
            <button class="btn-send" onclick="sendMessage()">➔</button>
        </div>
        <div class="disclaimer-footer">
            IDENTITRACK AI is a decision-support component. It does not independently impose sanctions. Final decisions remain under authorized SDO authority.
        </div>
    </div>
</div>

<script>
let currentConvUuid = '';

function handleKeyDown(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
    }
}

function createNewChat() {
    fetch('ai_chat.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'new_chat' })
    })
    .then(r => r.json())
    .then(data => {
        if (data.conversation_uuid) {
            currentConvUuid = data.conversation_uuid;
            document.getElementById('chatMessages').innerHTML = `
                <div class="msg-row assistant">
                    <div class="avatar avatar-ai">AI</div>
                    <div class="bubble">
                        👋 **New Conversation Started.** Ask me any question about the Student Handbook or case precedents!
                    </div>
                </div>`;
        }
    });
}

function loadChat(uuid, el) {
    currentConvUuid = uuid;
    document.querySelectorAll('.conv-item').forEach(i => i.classList.remove('active'));
    if (el) el.classList.add('active');

    fetch('ai_chat.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'get_messages', conversation_uuid: uuid })
    })
    .then(r => r.json())
    .then(data => {
        const box = document.getElementById('chatMessages');
        box.innerHTML = '';
        if (data.messages && data.messages.length > 0) {
            data.messages.forEach(m => {
                appendBubble(m.role, m.content, m.sources);
            });
        }
    });
}

function sendMessage() {
    const input = document.getElementById('msgInput');
    const msg = input.value.trim();
    if (!msg) return;

    appendBubble('user', msg);
    input.value = '';

    // Typing Indicator
    const typingId = 'typing_' + Date.now();
    const box = document.getElementById('chatMessages');
    box.innerHTML += `
        <div class="msg-row assistant" id="${typingId}">
            <div class="avatar avatar-ai">AI</div>
            <div class="bubble tool-indicator">
                <span>⚡</span> <span>Searching Student Handbook & Analyzing Query...</span>
            </div>
        </div>`;
    box.scrollTop = box.scrollHeight;

    fetch('ai_chat.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            action: 'send_message',
            message: msg,
            conversation_uuid: currentConvUuid
        })
    })
    .then(r => r.json())
    .then(data => {
        const typingEl = document.getElementById(typingId);
        if (typingEl) typingEl.remove();

        if (data.conversation_uuid) currentConvUuid = data.conversation_uuid;
        appendBubble('assistant', data.reply, data.sources);
    })
    .catch(err => {
        const typingEl = document.getElementById(typingId);
        if (typingEl) typingEl.remove();
        appendBubble('assistant', '⚠️ Error generating response: ' + err.message);
    });
}

function appendBubble(role, text, sources = []) {
    const box = document.getElementById('chatMessages');
    let formattedText = text.replace(/\n/g, '<br>').replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
    
    let sourceHtml = '';
    if (sources && sources.length > 0) {
        sourceHtml = `<div class="source-card">📖 <strong>Handbook Source:</strong> ${sources[0].title} (${sources[0].section})</div>`;
    }

    const html = `
        <div class="msg-row ${role}">
            ${role === 'assistant' ? '<div class="avatar avatar-ai">AI</div>' : ''}
            <div class="bubble">
                ${formattedText}
                ${sourceHtml}
            </div>
            ${role === 'user' ? '<div class="avatar avatar-user">You</div>' : ''}
        </div>`;
    box.innerHTML += html;
    box.scrollTop = box.scrollHeight;
}
</script>
</body>
</html>
