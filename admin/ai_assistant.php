<?php
// File: C:\xampp\htdocs\identitrack\admin\ai_assistant.php
require_once __DIR__ . '/../database/database.php';
require_admin_login();

$activeSidebar = 'ai_assistant';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>🧠 Global AI Precedent & Analytics Hub - IdentiTrack</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.net/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <style>
    body { background: #0f172a; color: #f8fafc; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
    .ai-hub-container { max-width: 1000px; margin: 30px auto; padding: 0 15px; }
    .glass-card { background: rgba(30, 41, 59, 0.85); backdrop-filter: blur(16px); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 20px; box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4); overflow: hidden; }
    .glass-header { padding: 20px 24px; background: rgba(15, 23, 42, 0.9); border-bottom: 1px solid rgba(255, 255, 255, 0.08); display: flex; align-items: center; justify-content: space-between; }
    .glass-title { font-size: 18px; font-weight: 700; color: #f8fafc; margin: 0; display: flex; align-items: center; gap: 10px; }
    .chat-thread { height: 480px; overflow-y: auto; padding: 24px; display: flex; flex-direction: column; gap: 16px; background: transparent; }
    .chip-btn { background: rgba(56, 189, 248, 0.12); border: 1px solid rgba(56, 189, 248, 0.3); color: #38bdf8; font-size: 12px; padding: 8px 14px; border-radius: 20px; cursor: pointer; transition: all 0.2s; font-weight: 500; }
    .chip-btn:hover { background: rgba(56, 189, 248, 0.25); transform: translateY(-2px); color: #fff; }
    .input-bar { padding: 16px 20px; background: rgba(15, 23, 42, 0.95); border-top: 1px solid rgba(255, 255, 255, 0.08); display: flex; gap: 12px; align-items: flex-end; }
    textarea.ai-input { flex: 1; background: rgba(30, 41, 59, 0.7); border: 1px solid rgba(255, 255, 255, 0.1); color: #f8fafc; padding: 12px 18px; border-radius: 20px; font-size: 14px; outline: none; resize: none; max-height: 120px; line-height: 1.4; transition: all 0.2s; word-break: break-word; overflow-wrap: break-word; height: 46px; }
    textarea.ai-input:focus { border-color: rgba(56, 189, 248, 0.5); background: rgba(30, 41, 59, 0.95); }
    .send-btn { background: linear-gradient(135deg, #0ea5e9, #2563eb); color: #fff; border: none; width: 44px; height: 44px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; box-shadow: 0 4px 15px rgba(14, 165, 233, 0.4); transition: all 0.2s; flex-shrink: 0; }
    .send-btn:hover { transform: scale(1.08); }
    .ai-bubble { background: rgba(30, 41, 59, 0.9); border: 1px solid rgba(56, 189, 248, 0.3); color: #f8fafc; padding: 16px 20px; border-radius: 20px 20px 20px 4px; font-size: 14px; line-height: 1.6; max-width: 90%; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3); word-break: break-word; overflow-wrap: break-word; }
    .user-bubble { background: linear-gradient(135deg, #0284c7, #2563eb); color: #fff; padding: 12px 18px; border-radius: 20px 20px 4px 20px; font-size: 14px; display: inline-block; max-width: 85%; box-shadow: 0 4px 15px rgba(2, 132, 199, 0.3); line-height: 1.4; word-break: break-word; overflow-wrap: break-word; white-space: pre-wrap; }
    @keyframes aiDotBounce { 0%, 80%, 100% { transform: translateY(0); opacity: 0.4; } 40% { transform: translateY(-6px); opacity: 1; } }
  </style>
</head>
<body>
  <?php include __DIR__ . '/header.php'; ?>
  <div class="d-flex">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="flex-grow-1">
      <div class="ai-hub-container">
        
        <div class="d-flex align-items-center justify-content-between mb-4">
          <div>
            <h3 class="fw-bold mb-1 text-white"><i class="fa-solid fa-brain me-2 text-info"></i>Global AI Precedent & Analytics Hub</h3>
            <p class="text-secondary small mb-0">Ask general questions about the 1,886-record campus dataset, handbook policy rules, or global precedents.</p>
          </div>
          <a href="dashboard.php" class="btn btn-outline-secondary btn-sm rounded-pill"><i class="fa-solid fa-arrow-left me-1"></i> Back to Dashboard</a>
        </div>

        <!-- PRECEDENT CHIPS -->
        <div class="d-flex flex-wrap gap-2 mb-3">
          <button class="chip-btn" onclick="sendGlobalChat('What are the historical precedents for Vaping?')">🚭 Vaping Precedents</button>
          <button class="chip-btn" onclick="sendGlobalChat('Show Category 2 offense statistics')">📊 Category 2 Statistics</button>
          <button class="chip-btn" onclick="sendGlobalChat('Explain Section 4 Minor Escalation Policy')">📜 Minor Escalation Rules</button>
          <button class="chip-btn" onclick="sendGlobalChat('What are the precedents for Gross Disrespect?')">⚖️ Gross Disrespect Cases</button>
        </div>

        <!-- MAIN CHAT GLASS CARD -->
        <div class="glass-card">
          <div class="glass-header">
            <div class="glass-title">
              <span class="fs-5">🧠</span> IdentiTrack AI Precedent Advisor
              <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2 py-1 fs-6 ms-2" style="font-size: 10px !important;">LIVE AI ACTIVE</span>
            </div>
            <div class="text-secondary small">1,886 Records Indexed</div>
          </div>

          <div id="aiChatThread" class="chat-thread">
            <div class="text-center py-4 text-secondary">
              <div class="fs-2 mb-2 opacity-75">🏛️</div>
              <div class="fw-semibold text-light mb-1">Welcome to the Global AI Precedent Hub</div>
              <div class="small text-secondary max-w-md mx-auto">Ask any question about campus violation records, handbook penalty matrices, or historical precedents across all academic programs.</div>
            </div>
          </div>

          <!-- INPUT BAR -->
          <div class="input-bar">
            <textarea id="aiChatInput" rows="1" placeholder="Ask AI about campus precedents, handbook rules, or statistics..." oninput="this.style.height='auto';this.style.height=Math.min(this.scrollHeight,120)+'px';" onkeydown="if(event.key==='Enter' && !event.shiftKey && !this.disabled){ event.preventDefault(); sendGlobalChat(); }"></textarea>
            <button id="aiSendBtn" onclick="sendGlobalChat()" class="send-btn" title="Send Message">
              <i class="fa-solid fa-paper-plane"></i>
            </button>
          </div>
        </div>

      </div>
    </div>
  </div>

  <script>
  let isAiBusy = false;
  let currentTypewriterTimer = null;

  function setAiBusy(busy) {
      isAiBusy = busy;
      const input = document.getElementById('aiChatInput');
      const btn = document.getElementById('aiSendBtn');
      if (input) input.disabled = busy;
      if (btn) {
          btn.disabled = busy;
          btn.style.opacity = busy ? '0.5' : '1';
      }
  }

  function typewriteAiReply(containerThread, markdownText) {
      const aiDiv = document.createElement('div');
      aiDiv.style.cssText = 'text-align:left;margin-top:6px;';
      
      const bubble = document.createElement('div');
      bubble.className = 'ai-bubble';
      aiDiv.appendChild(bubble);
      containerThread.appendChild(aiDiv);

      let text = markdownText
          .replace(/### (.*?)\n/g, '<strong style="color:#38bdf8;font-size:15px;display:block;margin-top:10px;margin-bottom:6px;">$1</strong>')
          .replace(/## (.*?)\n/g, '<strong style="color:#38bdf8;font-size:16px;display:block;margin-top:12px;margin-bottom:6px;">$1</strong>')
          .replace(/\*\*(.*?)\*\*/g, '<strong style="color:#f8fafc;">$1</strong>')
          .replace(/\*(.*?)\*/g, '<em>$1</em>')
          .replace(/\n\n/g, '<br><br>')
          .replace(/\n/g, '<br>');

      let i = 0;
      const speedMs = 12;

      function streamStep() {
          if (i < text.length) {
              if (text.substring(i, i+4) === '<br>') {
                  bubble.innerHTML += '<br>';
                  i += 4;
              } else if (text.substring(i, i+5) === '<br>') {
                  bubble.innerHTML += '<br>';
                  i += 5;
              } else {
                  bubble.innerHTML += text.charAt(i);
                  i++;
              }
              containerThread.scrollTop = containerThread.scrollHeight;
              currentTypewriterTimer = setTimeout(streamStep, speedMs);
          } else {
              setAiBusy(false);
          }
      }
      streamStep();
  }

  function sendGlobalChat(presetText) {
      const input = document.getElementById('aiChatInput');
      const thread = document.getElementById('aiChatThread');
      
      if (isAiBusy) return;
      
      const query = presetText || (input ? input.value.trim() : '');
      if (!query) return;
      
      if (input) {
          input.value = '';
          input.style.height = '46px';
      }

      setAiBusy(true);

      // User Message
      const userDiv = document.createElement('div');
      userDiv.style.cssText = 'text-align:right;margin-top:6px;';
      const safeQuery = query.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
      userDiv.innerHTML = `<div class="user-bubble">${safeQuery}</div>`;
      thread.appendChild(userDiv);

      // Loading Indicator
      const loadingId = 'ai_loading_' + Date.now();
      const loadingDiv = document.createElement('div');
      loadingDiv.id = loadingId;
      loadingDiv.style.cssText = 'text-align:left;margin-top:6px;';
      loadingDiv.innerHTML = `<div class="ai-bubble" style="display:inline-flex;align-items:center;gap:10px;"><span class="fs-5">🧠</span> <span class="fw-semibold text-info">Identi is thinking...</span> <span style="display:inline-flex;gap:4px;"><span style="animation: aiDotBounce 1.4s infinite 0s;">•</span><span style="animation: aiDotBounce 1.4s infinite 0.2s;">•</span><span style="animation: aiDotBounce 1.4s infinite 0.4s;">•</span></span></div>`;
      thread.appendChild(loadingDiv);
      thread.scrollTop = thread.scrollHeight;

      fetch(`../admin/api_ai_suggest_sanction.php?t=${Date.now()}`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ action: 'global_chat', query: query })
      })
      .then(res => res.json())
      .then(data => {
          const lNode = document.getElementById(loadingId);
          if (lNode) lNode.remove();

          if (data.ok && data.reply) {
              typewriteAiReply(thread, data.reply);
          } else {
              setAiBusy(false);
              let errText = data.error || 'No reply generated.';
              const errDiv = document.createElement('div');
              errDiv.style.cssText = 'text-align:left;margin-top:6px;';
              errDiv.innerHTML = `<div class="ai-bubble" style="border-color:rgba(239,68,68,0.4);color:#f87171;">⚠️ ${errText}</div>`;
              thread.appendChild(errDiv);
              thread.scrollTop = thread.scrollHeight;
          }
      })
      .catch(err => {
          setAiBusy(false);
          const lNode = document.getElementById(loadingId);
          if (lNode) lNode.remove();
          const errDiv = document.createElement('div');
          errDiv.style.cssText = 'text-align:left;margin-top:6px;';
          errDiv.innerHTML = `<div class="ai-bubble" style="border-color:rgba(239,68,68,0.4);color:#f87171;">⚠️ Connection error: ${err.message}</div>`;
          thread.appendChild(errDiv);
          thread.scrollTop = thread.scrollHeight;
      });
  }
  </script>
</body>
</html>
