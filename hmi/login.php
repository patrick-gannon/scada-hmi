<?php
require_once __DIR__ . '/includes/auth.php';
session_start_once();
if (current_user()) {
    $next = $_GET['next'] ?? '/environment-monitor/index.php';
    // Safety check — only redirect to our own pages
    if (!str_starts_with($next, '/environment-monitor/')) {
        $next = '/environment-monitor/index.php';
    }
    header('Location: ' . $next);
    exit;
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SCADA HMI — Login</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Rajdhani:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --bg:       #0a0c0e;
    --panel:    #0f1318;
    --border:   #1e2730;
    --amber:    #e8a020;
    --amber-dim:#7a5010;
    --green:    #00e676;
    --green-dim:#005c2e;
    --red:      #ff3d3d;
    --cyan:     #00c8e0;
    --text:     #c8d4dc;
    --text-dim: #556070;
    --mono:     'Share Tech Mono', monospace;
    --head:     'Rajdhani', sans-serif;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  body {
    background: var(--bg);
    color: var(--text);
    font-family: var(--mono);
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    overflow: hidden;
  }

  /* animated grid background */
  body::before {
    content: '';
    position: fixed; inset: 0;
    background-image:
      linear-gradient(rgba(0,200,224,0.03) 1px, transparent 1px),
      linear-gradient(90deg, rgba(0,200,224,0.03) 1px, transparent 1px);
    background-size: 40px 40px;
    pointer-events: none;
  }

  /* scanline overlay */
  body::after {
    content: '';
    position: fixed; inset: 0;
    background: repeating-linear-gradient(
      0deg,
      transparent,
      transparent 2px,
      rgba(0,0,0,0.15) 2px,
      rgba(0,0,0,0.15) 4px
    );
    pointer-events: none;
    z-index: 100;
  }

  .login-wrap {
    width: 100%;
    max-width: 420px;
    padding: 0 20px;
    position: relative;
    z-index: 10;
  }

  .system-header {
    text-align: center;
    margin-bottom: 32px;
  }
  .system-header .logo-mark {
    font-family: var(--head);
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 4px;
    color: var(--text-dim);
    text-transform: uppercase;
    margin-bottom: 8px;
  }
  .system-header h1 {
    font-family: var(--head);
    font-size: 28px;
    font-weight: 700;
    letter-spacing: 3px;
    color: var(--amber);
    text-transform: uppercase;
    text-shadow: 0 0 20px rgba(232,160,32,0.4);
  }
  .system-header .subtitle {
    font-size: 10px;
    letter-spacing: 2px;
    color: var(--text-dim);
    margin-top: 6px;
  }

  /* status bar */
  .status-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    border: 1px solid var(--border);
    background: rgba(0,200,224,0.04);
    padding: 6px 14px;
    margin-bottom: 4px;
    font-size: 10px;
    letter-spacing: 1px;
    color: var(--text-dim);
  }
  .status-bar .indicator {
    display: flex;
    align-items: center;
    gap: 6px;
    color: var(--green);
  }
  .dot {
    width: 6px; height: 6px;
    border-radius: 50%;
    background: var(--green);
    box-shadow: 0 0 6px var(--green);
    animation: pulse 2s infinite;
  }
  @keyframes pulse {
    0%,100% { opacity:1; }
    50%      { opacity:0.4; }
  }

  .login-panel {
    border: 1px solid var(--border);
    background: var(--panel);
    padding: 36px 32px;
    position: relative;
  }
  .login-panel::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 2px;
    background: linear-gradient(90deg, transparent, var(--amber), transparent);
  }

  .corner { position: absolute; width: 10px; height: 10px; }
  .corner.tl { top: -1px; left: -1px; border-top: 2px solid var(--amber); border-left: 2px solid var(--amber); }
  .corner.tr { top: -1px; right: -1px; border-top: 2px solid var(--amber); border-right: 2px solid var(--amber); }
  .corner.bl { bottom: -1px; left: -1px; border-bottom: 2px solid var(--amber); border-left: 2px solid var(--amber); }
  .corner.br { bottom: -1px; right: -1px; border-bottom: 2px solid var(--amber); border-right: 2px solid var(--amber); }

  .panel-label {
    font-size: 9px;
    letter-spacing: 3px;
    color: var(--text-dim);
    text-transform: uppercase;
    margin-bottom: 28px;
    text-align: center;
  }

  .field {
    margin-bottom: 20px;
  }
  .field label {
    display: block;
    font-size: 9px;
    letter-spacing: 2px;
    color: var(--text-dim);
    text-transform: uppercase;
    margin-bottom: 8px;
  }
  .field input {
    width: 100%;
    background: rgba(0,0,0,0.5);
    border: 1px solid var(--border);
    border-bottom-color: var(--amber-dim);
    color: var(--text);
    font-family: var(--mono);
    font-size: 14px;
    padding: 10px 14px;
    outline: none;
    transition: border-color 0.2s, box-shadow 0.2s;
    letter-spacing: 1px;
  }
  .field input:focus {
    border-color: var(--amber);
    box-shadow: 0 0 0 1px var(--amber-dim), 0 0 12px rgba(232,160,32,0.1);
  }

  .login-btn {
    width: 100%;
    background: transparent;
    border: 1px solid var(--amber);
    color: var(--amber);
    font-family: var(--head);
    font-size: 13px;
    font-weight: 700;
    letter-spacing: 4px;
    text-transform: uppercase;
    padding: 13px;
    cursor: pointer;
    margin-top: 8px;
    transition: background 0.2s, box-shadow 0.2s, color 0.2s;
    position: relative;
    overflow: hidden;
  }
  .login-btn:hover {
    background: rgba(232,160,32,0.1);
    box-shadow: 0 0 20px rgba(232,160,32,0.2);
  }
  .login-btn:active {
    background: rgba(232,160,32,0.2);
  }
  .login-btn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
  }

  .error-msg {
    font-size: 11px;
    color: var(--red);
    letter-spacing: 1px;
    margin-top: 16px;
    text-align: center;
    min-height: 16px;
    display: none;
  }
  .error-msg.show { display: block; }

  .footer-info {
    text-align: center;
    margin-top: 20px;
    font-size: 9px;
    letter-spacing: 1px;
    color: var(--text-dim);
    line-height: 1.8;
  }
</style>
</head>
<body>

<div class="login-wrap">
  <div class="system-header">
    <div class="logo-mark">Supervisory Control &amp; Data Acquisition</div>
    <h1>SCADA / HMI</h1>
    <div class="subtitle">Environment Monitoring System &nbsp;|&nbsp; v1.0</div>
  </div>

  <div class="status-bar">
    <span>SYS.AUTH.MODULE</span>
    <span class="indicator"><span class="dot"></span>ONLINE</span>
  </div>

  <div class="login-panel">
    <div class="corner tl"></div><div class="corner tr"></div>
    <div class="corner bl"></div><div class="corner br"></div>

    <div class="panel-label">Operator Authentication Required</div>

    <div class="field">
      <label>Username</label>
      <input type="text" id="username" autocomplete="username" spellcheck="false">
    </div>
    <div class="field">
      <label>Password</label>
      <input type="password" id="password" autocomplete="current-password">
    </div>

    <button class="login-btn" id="loginBtn">Authenticate</button>
    <div class="error-msg" id="errMsg">AUTHENTICATION FAILURE — INVALID CREDENTIALS</div>
  </div>

  <div class="footer-info">
    Patrick Gannon &nbsp;·&nbsp;<br>
    Unauthorized access is prohibited
  </div>
</div>

<script>
const btn  = document.getElementById('loginBtn');
const err  = document.getElementById('errMsg');
const uField = document.getElementById('username');
const pField = document.getElementById('password');

async function login() {
  err.classList.remove('show');
  btn.disabled = true;
  btn.textContent = 'AUTHENTICATING...';
  try {
    const r = await fetch('/environment-monitor/api/auth.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ username: uField.value, password: pField.value })
    });
    const d = await r.json();
    if (d.ok) {
      btn.textContent = 'ACCESS GRANTED';
      const params = new URLSearchParams(window.location.search);
      const next = params.get('next') || '/environment-monitor/index.php';
      setTimeout(() => { window.location.href = next; }, 400);
    } else {
      err.classList.add('show');
      btn.disabled = false;
      btn.textContent = 'AUTHENTICATE';
      pField.value = '';
      pField.focus();
    }
  } catch(e) {
    err.textContent = 'NETWORK ERROR — CANNOT REACH SERVER';
    err.classList.add('show');
    btn.disabled = false;
    btn.textContent = 'AUTHENTICATE';
  }
}

btn.addEventListener('click', login);
[uField, pField].forEach(el => el.addEventListener('keydown', e => { if(e.key==='Enter') login(); }));
document.addEventListener('DOMContentLoaded', () => uField.focus());
</script>
</body>
</html>
