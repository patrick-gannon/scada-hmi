<?php
require_once __DIR__ . '/includes/auth.php';
require_login();
$user = current_user();
$is_admin = is_admin();
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SCADA HMI — Environment Monitor</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Rajdhani:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
/* ── DESIGN TOKENS ───────────────────────────────────────────────────── */
:root {
  --bg:        #070910;
  --panel:     #0c1015;
  --panel2:    #0f1318;
  --border:    #1a2230;
  --border2:   #243040;
  --amber:     #e8a020;
  --amber-dim: #7a5010;
  --amber-bg:  rgba(232,160,32,0.06);
  --green:     #00e676;
  --green-dim: #00602e;
  --green-bg:  rgba(0,230,118,0.06);
  --red:       #ff4444;
  --red-dim:   #6a1515;
  --red-bg:    rgba(255,68,68,0.06);
  --cyan:      #00c8e0;
  --cyan-dim:  #005a66;
  --yellow:    #ffe04a;
  --text:      #ddeaf2;
  --text-dim:  #7a9aaa;
  --text-med:  #a0bcc8;
  --mono:      'Share Tech Mono', monospace;
  --head:      'Rajdhani', sans-serif;
  --header-h:  56px;
  /* boosted panel contrast vs background */
  --panel:     #111820;
  --panel2:    #151e28;
  --border:    #253545;
  --border2:   #2e4255;
}

/* ── RESET ─────────────────────────────────────────────────────────── */
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
html, body { height:100%; }

body {
  background: var(--bg);
  color: var(--text);
  font-family: var(--mono);
  font-size: 16px;
  overflow: hidden;
  height: 100vh;
  display: flex;
  flex-direction: column;
}

/* grid bg — very subtle */
body::before {
  content:'';
  position:fixed; inset:0;
  background-image:
    linear-gradient(rgba(0,200,224,0.015) 1px, transparent 1px),
    linear-gradient(90deg, rgba(0,200,224,0.015) 1px, transparent 1px);
  background-size: 40px 40px;
  pointer-events:none;
  z-index:0;
}
/* scanlines — removed, too heavy */
body::after { display: none; }

/* ── TOP HEADER ─────────────────────────────────────────────────────── */
#header {
  height: var(--header-h);
  border-bottom: 1px solid var(--border);
  background: var(--panel);
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 20px;
  position: relative;
  z-index: 100;
  flex-shrink: 0;
}
#header::after {
  content:'';
  position:absolute; bottom:0; left:0; right:0;
  height:1px;
  background: linear-gradient(90deg, transparent, var(--amber-dim), transparent);
}
.hdr-left { display:flex; align-items:center; gap:20px; }
.brand { font-family:var(--head); font-size:20px; font-weight:700; letter-spacing:3px; color:var(--amber); }
.brand-sub { font-size:12px; letter-spacing:2px; color:var(--text-dim); }
.hdr-clock { font-size:16px; letter-spacing:2px; color:var(--cyan); }
.hdr-right { display:flex; align-items:center; gap:16px; }
.user-badge {
  display:flex; align-items:center; gap:8px;
  font-size:14px; letter-spacing:1px; color:var(--text-med);
}
.user-badge .role-pill {
  background: var(--amber-bg);
  border: 1px solid var(--amber-dim);
  color: var(--amber);
  font-family:var(--head); font-weight:600; font-size:12px;
  letter-spacing:2px; padding:2px 8px; text-transform:uppercase;
}
.hdr-btn {
  background:transparent; border:1px solid var(--border2);
  color:var(--text-med); font-family:var(--mono); font-size:13px;
  letter-spacing:1px; padding:6px 14px; cursor:pointer;
  transition: all 0.2s;
}
.hdr-btn:hover { border-color:var(--amber); color:var(--amber); }

/* ── NAV TABS ───────────────────────────────────────────────────────── */
#nav {
  height: 40px;
  background: var(--panel2);
  border-bottom: 1px solid var(--border);
  display: flex;
  align-items: flex-end;
  padding: 0 20px;
  gap: 2px;
  position: relative;
  z-index: 90;
  flex-shrink: 0;
}
.nav-tab {
  font-family: var(--head);
  font-size: 13px;
  font-weight: 600;
  letter-spacing: 2px;
  text-transform: uppercase;
  color: var(--text-dim);
  padding: 8px 18px;
  cursor: pointer;
  border: 1px solid transparent;
  border-bottom: none;
  transition: all 0.15s;
  position: relative;
  bottom: -1px;
}
.nav-tab:hover { color: var(--text); background: rgba(255,255,255,0.03); }
.nav-tab.active {
  color: var(--amber);
  background: var(--panel);
  border-color: var(--border);
  border-bottom-color: var(--panel);
}
.nav-tab .badge {
  display:inline-block; background:var(--red); color:#fff;
  font-size:11px; border-radius:10px; padding:1px 6px; margin-left:6px;
  font-family:var(--mono); letter-spacing:0;
}

/* ── MAIN CONTENT AREA ──────────────────────────────────────────────── */
#content {
  flex: 1;
  overflow-y: auto;
  padding: 18px 20px;
  position: relative;
  z-index: 10;
}

/* ── SECTION (tab pane) ─────────────────────────────────────────────── */
.section { display: none; }
.section.active { display: block; }

/* ── PANEL PRIMITIVE ────────────────────────────────────────────────── */
.panel {
  background: var(--panel);
  border: 1px solid var(--border);
  position: relative;
}
.panel::before {
  content: attr(data-label);
  position: absolute;
  top: -1px; left: 12px;
  background: var(--panel);
  padding: 0 6px;
  font-size: 11px;
  letter-spacing: 2px;
  color: var(--text-dim);
  text-transform: uppercase;
  transform: translateY(-50%);
  white-space: nowrap;
}
.panel-body { padding: 16px; }

/* corner decorations */
.panel .c { position:absolute; width:8px; height:8px; }
.panel .c.tl { top:-1px;left:-1px; border-top:1px solid var(--amber-dim); border-left:1px solid var(--amber-dim); }
.panel .c.tr { top:-1px;right:-1px; border-top:1px solid var(--amber-dim); border-right:1px solid var(--amber-dim); }
.panel .c.bl { bottom:-1px;left:-1px; border-bottom:1px solid var(--amber-dim); border-left:1px solid var(--amber-dim); }
.panel .c.br { bottom:-1px;right:-1px; border-bottom:1px solid var(--amber-dim); border-right:1px solid var(--amber-dim); }

/* ── GRID HELPERS ───────────────────────────────────────────────────── */
.grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.grid-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:14px; }
.grid-auto { display:grid; grid-template-columns:repeat(auto-fill,minmax(300px,1fr)); gap:14px; }

/* ── NODE CARD ──────────────────────────────────────────────────────── */
.node-card {
  background: #111a24;
  border: 1px solid var(--border2);
  border-left: 3px solid var(--cyan);
  position: relative;
  overflow: hidden;
  box-shadow: 0 4px 24px rgba(0,0,0,0.4);
}
.node-card::before { display:none; }
.node-card .card-header {
  display:flex; align-items:center; justify-content:space-between;
  padding: 12px 16px;
  border-bottom: 1px solid var(--border2);
  background: rgba(0,0,0,0.35);
}
.node-card .node-name {
  font-family:var(--head); font-weight:700; font-size:16px; letter-spacing:2px;
  color: #4dd8f0; text-transform: uppercase;
}
.node-card .node-id-label {
  font-size:12px; letter-spacing:1px; color:var(--text-med); margin-top:2px;
}
.status-badge {
  font-size:12px; letter-spacing:2px; font-family:var(--head); font-weight:600;
  padding: 4px 12px; border-radius:2px; text-transform:uppercase;
}
.status-badge.online  { background:var(--green-bg); border:1px solid var(--green-dim); color:var(--green); }
.status-badge.offline { background:var(--red-bg);   border:1px solid var(--red-dim);   color:var(--red);   }

.node-card .readings {
  padding: 16px;
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}
.reading-block { text-align:center; }
.reading-block .r-label { font-size:12px; letter-spacing:2px; color:var(--text-med); text-transform:uppercase; margin-bottom:6px; }
.reading-block .r-value {
  font-family:var(--head); font-size:40px; font-weight:700; letter-spacing:1px;
  line-height:1; color:#f0b030;
  transition: color 0.4s;
}
.reading-block .r-value.alarm { color:var(--red); animation: almblink 1s infinite; }
.reading-block .r-unit { font-size:14px; color:var(--text-med); margin-top:4px; }

@keyframes almblink { 0%,100%{opacity:1} 50%{opacity:0.5} }

.node-card .card-footer {
  padding: 10px 16px;
  border-top: 1px solid var(--border);
  display:flex; justify-content:space-between; align-items:center;
  background:rgba(0,0,0,0.15);
}
.timestamp { font-size:13px; color:var(--text-med); letter-spacing:1px; }
.age-indicator { font-size:13px; }

/* mini sparkline */
.sparkline-wrap { padding: 0 16px 14px; }
canvas.sparkline { width:100%; height:48px; display:block; }

/* ── SYSTEM CONTROLS ────────────────────────────────────────────────── */
.ctrl-row {
  display:flex; align-items:center; justify-content:space-between;
  padding: 14px 0;
  border-bottom: 1px solid var(--border);
}
.ctrl-row:last-child { border-bottom:none; }
.ctrl-left .ctrl-name { font-family:var(--head); font-weight:600; font-size:16px; letter-spacing:1px; color:var(--text); }
.ctrl-left .ctrl-desc { font-size:13px; letter-spacing:1px; color:var(--text-dim); margin-top:4px; }

/* toggle switch */
.toggle-wrap { display:flex; align-items:center; gap:12px; }
.toggle-label { font-size:14px; letter-spacing:1px; }
.toggle {
  position:relative; width:52px; height:26px; cursor:pointer;
}
.toggle input { opacity:0; width:0; height:0; }
.toggle-slider {
  position:absolute; inset:0;
  background: var(--border2);
  border: 1px solid var(--border2);
  transition: 0.3s;
}
.toggle-slider::before {
  content:'';
  position:absolute; width:18px; height:18px;
  top:3px; left:3px;
  background: var(--text-dim);
  transition: 0.3s;
}
.toggle input:checked + .toggle-slider { background:var(--green-bg); border-color:var(--green-dim); }
.toggle input:checked + .toggle-slider::before { transform:translateX(26px); background:var(--green); }

/* number input */
.num-input-wrap { display:flex; align-items:center; gap:8px; }
.hmi-input {
  background: rgba(0,0,0,0.5);
  border: 1px solid var(--border2);
  color: var(--amber);
  font-family:var(--mono); font-size:16px;
  padding: 7px 10px; width: 100px;
  outline:none;
  transition: border-color 0.2s;
  letter-spacing:1px;
  text-align:right;
}
.hmi-input:focus { border-color:var(--amber); }
.hmi-input:disabled { opacity:0.4; cursor:not-allowed; }
.unit-label { font-size:14px; color:var(--text-dim); letter-spacing:1px; }
.apply-btn {
  background:transparent; border:1px solid var(--amber-dim);
  color:var(--amber); font-family:var(--head); font-weight:600;
  font-size:13px; letter-spacing:2px; padding:7px 16px; cursor:pointer;
  transition:all 0.2s;
}
.apply-btn:hover { background:var(--amber-bg); border-color:var(--amber); }
.apply-btn:disabled { opacity:0.3; cursor:not-allowed; }

/* Kasa switch */
.kasa-btn {
  font-family:var(--head); font-weight:700; font-size:14px; letter-spacing:2px;
  text-transform:uppercase; padding:9px 22px; cursor:pointer;
  border:1px solid; transition:all 0.2s;
}
.kasa-btn.on  { background:var(--green-bg); border-color:var(--green-dim); color:var(--green); }
.kasa-btn.off { background:var(--red-bg);   border-color:var(--red-dim);   color:var(--red); }
.kasa-btn.on:hover  { background:rgba(0,230,118,0.12); }
.kasa-btn.off:hover { background:rgba(255,68,68,0.12); }
.kasa-status { font-size:14px; letter-spacing:1px; margin-left:8px; }

/* ── THRESHOLD FORM ──────────────────────────────────────────────────── */
.thresh-grid {
  display:grid;
  grid-template-columns: 1fr 1fr 1fr 1fr 1fr;
  gap:10px; align-items:end;
}
.thresh-grid .col { }
.thresh-label { font-size:12px; letter-spacing:1px; color:var(--text-dim); text-transform:uppercase; margin-bottom:6px; }
.thresh-input {
  width:100%; background:rgba(0,0,0,0.5);
  border:1px solid var(--border2); border-bottom-color:var(--cyan-dim);
  color:var(--text); font-family:var(--mono); font-size:16px;
  padding:8px 10px; outline:none; transition:border-color 0.2s;
}
.thresh-input:focus { border-color:var(--cyan); }
.thresh-row { margin-bottom:14px; }
.thresh-node-label {
  font-family:var(--head); font-weight:600; font-size:16px; letter-spacing:2px;
  color:var(--cyan); padding:10px 0 8px; border-bottom:1px solid var(--border); margin-bottom:10px;
}

.save-btn {
  background:transparent; border:1px solid var(--cyan-dim);
  color:var(--cyan); font-family:var(--head); font-weight:600;
  font-size:13px; letter-spacing:2px; padding:9px 22px; cursor:pointer;
  transition:all 0.2s; text-transform:uppercase;
}
.save-btn:hover { background:rgba(0,200,224,0.06); border-color:var(--cyan); }

/* ── AUDIT LOG TABLE ─────────────────────────────────────────────────── */
.log-table { width:100%; border-collapse:collapse; font-size:14px; }
.log-table th {
  text-align:left; padding:9px 12px;
  font-family:var(--head); font-weight:600; font-size:12px; letter-spacing:2px;
  color:var(--text-dim); text-transform:uppercase;
  border-bottom: 1px solid var(--border2);
  background:rgba(0,0,0,0.3);
}
.log-table td { padding:9px 12px; border-bottom:1px solid var(--border); vertical-align:middle; }
.log-table tr:hover td { background:rgba(255,255,255,0.02); }
.log-table .col-time  { color:var(--text-dim); letter-spacing:1px; white-space:nowrap; }
.log-table .col-user  { color:var(--amber); font-weight:bold; }
.log-table .col-action{ color:var(--text); }
.log-table .col-old   { color:var(--text-dim); }
.log-table .col-new   { color:var(--cyan); }

.load-more-btn {
  width:100%; background:transparent; border:1px dashed var(--border2);
  color:var(--text-dim); font-family:var(--mono); font-size:13px;
  letter-spacing:2px; padding:12px; cursor:pointer; margin-top:8px;
  transition:all 0.2s;
}
.load-more-btn:hover { border-color:var(--amber); color:var(--amber); }

/* alarm table */
.alarm-row.high-alarm td:nth-child(2) { color:var(--red); }
.alarm-row.low-alarm  td:nth-child(2) { color:var(--yellow); }

/* ── USER MANAGEMENT ─────────────────────────────────────────────────── */
.user-table { width:100%; border-collapse:collapse; font-size:14px; }
.user-table th { text-align:left; padding:9px 12px; font-family:var(--head); font-weight:600; font-size:12px; letter-spacing:2px; color:var(--text-dim); text-transform:uppercase; border-bottom:1px solid var(--border2); background:rgba(0,0,0,0.3); }
.user-table td { padding:10px 12px; border-bottom:1px solid var(--border); }
.role-badge { font-size:12px; letter-spacing:2px; font-family:var(--head); font-weight:600; padding:3px 10px; text-transform:uppercase; }
.role-badge.admin  { background:var(--amber-bg); border:1px solid var(--amber-dim); color:var(--amber); }
.role-badge.viewer { background:var(--green-bg); border:1px solid var(--green-dim); color:var(--green); }
.inactive-user td { opacity:0.4; }

/* ── TOAST NOTIFICATIONS ─────────────────────────────────────────────── */
#toasts {
  position: fixed; bottom:20px; right:20px;
  display:flex; flex-direction:column; gap:8px;
  z-index:9999; pointer-events:none;
}
.toast {
  padding:12px 18px; border-left:3px solid;
  font-size:14px; letter-spacing:1px;
  background: var(--panel2); border-color:var(--border);
  min-width:260px;
  animation: toastIn 0.3s ease, toastOut 0.3s ease 2.7s forwards;
  pointer-events:auto;
}
.toast.ok  { border-left-color:var(--green); color:var(--green); }
.toast.err { border-left-color:var(--red); color:var(--red); }
.toast.info{ border-left-color:var(--cyan); color:var(--cyan); }

@keyframes toastIn  { from{opacity:0;transform:translateX(20px)} to{opacity:1;transform:translateX(0)} }
@keyframes toastOut { from{opacity:1} to{opacity:0} }

/* ── MISC ────────────────────────────────────────────────────────────── */
.section-title {
  font-family:var(--head); font-size:13px; font-weight:600; letter-spacing:3px;
  color:var(--text-dim); text-transform:uppercase;
  display:flex; align-items:center; gap:10px; margin-bottom:14px;
}
.section-title::after { content:''; flex:1; height:1px; background:var(--border); }

.inline-form { display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap; margin-top:12px; }
.form-field { display:flex; flex-direction:column; gap:6px; }
.form-field label { font-size:12px; letter-spacing:2px; color:var(--text-dim); text-transform:uppercase; }
.form-field input, .form-field select {
  background:rgba(0,0,0,0.5); border:1px solid var(--border2);
  color:var(--text); font-family:var(--mono); font-size:16px;
  padding:8px 12px; outline:none; transition:border-color 0.2s; min-width:140px;
}
.form-field input:focus, .form-field select:focus { border-color:var(--cyan); }
.form-field select option { background:var(--panel2); }

.viewer-lock { display:none; }
.viewer-only-msg {
  font-size:13px; letter-spacing:1px; color:var(--text-dim);
  font-style:italic; padding:4px 0;
}

/* scrollbar */
::-webkit-scrollbar { width:6px; height:6px; }
::-webkit-scrollbar-track { background:var(--panel); }
::-webkit-scrollbar-thumb { background:var(--border2); }
::-webkit-scrollbar-thumb:hover { background:var(--amber-dim); }

/* responsive */
@media (max-width: 700px) {
  .grid-2, .grid-3 { grid-template-columns:1fr; }
  .thresh-grid { grid-template-columns:1fr 1fr; }

  #header { padding:0 10px; gap:8px; }
  .brand { font-size:15px; letter-spacing:2px; }
  .brand-sub { display:none; }
  .hdr-clock { font-size:13px; letter-spacing:1px; }
  .user-badge .role-pill { display:none; }
  .user-badge { font-size:12px; }

  /* nav: scrollable single row, no tab-style borders */
  #nav {
    height: auto;
    overflow-x: auto;
    overflow-y: hidden;
    flex-wrap: nowrap;
    align-items: center;
    padding: 0 8px;
    gap: 0;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
  }
  #nav::-webkit-scrollbar { display:none; }
  .nav-tab {
    font-size: 11px;
    letter-spacing: 1px;
    padding: 10px 12px;
    white-space: nowrap;
    border: none;
    border-bottom: 2px solid transparent;
    bottom: 0;
    flex-shrink: 0;
  }
  .nav-tab.active {
    border-bottom-color: var(--amber);
    background: transparent;
  }

  /* node cards full width */
  .grid-auto { grid-template-columns: 1fr; }

  /* reading values slightly smaller on mobile */
  .reading-block .r-value { font-size: 32px; }

  /* controls stack vertically */
  .ctrl-row { flex-direction: column; align-items: flex-start; gap: 10px; }
  .num-input-wrap { flex-wrap: wrap; }
}
</style>
</head>
<body>

<!-- ── HEADER ───────────────────────────────────────────────────────── -->
<header id="header">
  <div class="hdr-left">
    <div>
      <div class="brand">SCADA / HMI</div>
      <div class="brand-sub">Environment Monitoring System</div>
    </div>
    <div class="hdr-clock" id="clock">--:--:--</div>
  </div>
  <div class="hdr-right">
    <div class="user-badge">
      <span id="userNameDisplay"><?= htmlspecialchars($user['username']) ?></span>
      <span class="role-pill"><?= htmlspecialchars($user['role']) ?></span>
    </div>
    <button class="hdr-btn" onclick="logout()">LOGOUT</button>
  </div>
</header>

<!-- ── NAV TABS ─────────────────────────────────────────────────────── -->
<nav id="nav">
  <div class="nav-tab active" data-tab="live">Live View</div>
  <div class="nav-tab" data-tab="controls">Controls</div>
  <div class="nav-tab" data-tab="thresholds">Thresholds</div>
  <div class="nav-tab" data-tab="alarms">Alarms <span class="badge" id="alarmBadge" style="display:none">0</span></div>
  <div class="nav-tab" data-tab="audit">Audit Log</div>
  <?php if($is_admin): ?>
  <div class="nav-tab" data-tab="users">Users</div>
  <?php endif; ?>
</nav>

<!-- ── CONTENT ──────────────────────────────────────────────────────── -->
<main id="content">

  <!-- ═══════════ LIVE VIEW ════════════ -->
  <section class="section active" id="tab-live">
    <div class="section-title">Node Status — Live Readings</div>
    <div class="grid-auto" id="nodeGrid">
      <div style="color:var(--text-dim);font-size:11px;letter-spacing:1px;">Loading nodes...</div>
    </div>
  </section>

  <!-- ═══════════ CONTROLS ════════════ -->
  <section class="section" id="tab-controls">
    <div class="grid-2" style="gap:16px;">

      <!-- System Settings -->
      <div class="panel" data-label="System Settings">
        <div class="c tl"></div><div class="c tr"></div><div class="c bl"></div><div class="c br"></div>
        <div class="panel-body">

          <!-- Logging Active -->
          <div class="ctrl-row">
            <div class="ctrl-left">
              <div class="ctrl-name">DATA LOGGING</div>
              <div class="ctrl-desc">Enable or pause all node data ingestion</div>
            </div>
            <div class="toggle-wrap">
              <span class="toggle-label" id="loggingLabel" style="color:var(--text-dim)">PAUSED</span>
              <label class="toggle" <?= !$is_admin ? 'title="Admin only"' : '' ?>>
                <input type="checkbox" id="loggingToggle" <?= !$is_admin ? 'disabled' : '' ?> onchange="setLogging(this.checked)">
                <span class="toggle-slider"></span>
              </label>
            </div>
          </div>

          <!-- Scan rate -->
          <div class="ctrl-row">
            <div class="ctrl-left">
              <div class="ctrl-name">SCAN RATE</div>
              <div class="ctrl-desc">Logging interval applied to all nodes</div>
            </div>
            <div class="num-input-wrap">
              <input type="number" class="hmi-input" id="intervalInput" min="5" max="3600" value="300" <?= !$is_admin ? 'disabled' : '' ?>>
              <span class="unit-label">SEC</span>
              <?php if($is_admin): ?>
              <button class="apply-btn" onclick="setScanRate()">APPLY</button>
              <?php endif; ?>
            </div>
          </div>

          <?php if(!$is_admin): ?>
          <p class="viewer-only-msg">Controls are read-only in viewer mode.</p>
          <?php endif; ?>
        </div>
      </div>

      <!-- Kasa Smart Plug Controls -->
      <div class="panel" data-label="Kasa Smart Plug Controls">
        <div class="c tl"></div><div class="c tr"></div><div class="c bl"></div><div class="c br"></div>
        <div class="panel-body">
          <div class="ctrl-row">
            <div class="ctrl-left">
              <div class="ctrl-name">OUTLET — SWITCH 1</div>
              <div class="ctrl-desc">Plug A — assigned device</div>
            </div>
            <div style="display:flex;align-items:center;">
              <?php if($is_admin): ?>
              <button class="kasa-btn off" id="kasaBtn1" onclick="kasaToggle('switch_01', this)">OFF</button>
              <?php else: ?>
              <span class="kasa-status" id="kasaStatus1" style="color:var(--text-dim)">—</span>
              <?php endif; ?>
            </div>
          </div>
          <div class="ctrl-row">
            <div class="ctrl-left">
              <div class="ctrl-name">OUTLET — SWITCH 2</div>
              <div class="ctrl-desc">Plug B — assigned device</div>
            </div>
            <div style="display:flex;align-items:center;">
              <?php if($is_admin): ?>
              <button class="kasa-btn off" id="kasaBtn2" onclick="kasaToggle('switch_02', this)">OFF</button>
              <?php else: ?>
              <span class="kasa-status" id="kasaStatus2" style="color:var(--text-dim)">—</span>
              <?php endif; ?>
            </div>
          </div>
          <div style="margin-top:12px; padding-top:12px; border-top:1px solid var(--border); font-size:9px; color:var(--text-dim); letter-spacing:1px; line-height:1.8;">
            ⚠ Kasa integration pending. Actions are logged to audit trail.
          </div>
        </div>
      </div>

      <!-- Node Display Names -->
      <?php if($is_admin): ?>
      <div class="panel" data-label="Node Display Names" style="grid-column:1/-1;">
        <div class="c tl"></div><div class="c tr"></div><div class="c bl"></div><div class="c br"></div>
        <div class="panel-body">
          <div id="nodeRenameList" style="color:var(--text-dim);font-size:11px;">Loading nodes...</div>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </section>

  <!-- ═══════════ THRESHOLDS ════════════ -->
  <section class="section" id="tab-thresholds">
    <div class="section-title">Alert Thresholds — per Node</div>
    <div id="thresholdForms" style="display:flex;flex-direction:column;gap:14px;">
      <div style="color:var(--text-dim);font-size:11px;letter-spacing:1px;">Loading...</div>
    </div>
  </section>

  <!-- ═══════════ ALARMS ════════════ -->
  <section class="section" id="tab-alarms">
    <div class="section-title">Alarm Historian</div>
    <div class="panel" data-label="Recent Alarm Events">
      <div class="c tl"></div><div class="c tr"></div><div class="c bl"></div><div class="c br"></div>
      <div class="panel-body">
        <table class="log-table" id="alarmTable">
          <thead><tr>
            <th>Timestamp</th><th>Node</th><th>Field</th>
            <th>Value</th><th>Direction</th><th>Threshold</th>
          </tr></thead>
          <tbody id="alarmBody"><tr><td colspan="6" style="color:var(--text-dim);letter-spacing:1px;">Loading...</td></tr></tbody>
        </table>
      </div>
    </div>
  </section>

  <!-- ═══════════ AUDIT LOG ════════════ -->
  <section class="section" id="tab-audit">
    <div class="section-title">Operator Audit Trail</div>
    <div class="panel" data-label="All Operator Actions">
      <div class="c tl"></div><div class="c tr"></div><div class="c bl"></div><div class="c br"></div>
      <div class="panel-body">
        <table class="log-table" id="auditTable">
          <thead><tr>
            <th>Timestamp</th><th>Username</th><th>Action</th><th>Old Value</th><th>New Value</th>
          </tr></thead>
          <tbody id="auditBody"><tr><td colspan="5" style="color:var(--text-dim);letter-spacing:1px;">Loading...</td></tr></tbody>
        </table>
        <button class="load-more-btn" id="loadMoreBtn" onclick="loadMoreAudit()">▼ LOAD MORE</button>
      </div>
    </div>
  </section>

  <!-- ═══════════ USERS (admin only) ════════════ -->
  <?php if($is_admin): ?>
  <section class="section" id="tab-users">
    <div class="grid-2" style="gap:16px;">
      <div>
        <div class="section-title">User Accounts</div>
        <div class="panel" data-label="HMI Users">
          <div class="c tl"></div><div class="c tr"></div><div class="c bl"></div><div class="c br"></div>
          <div class="panel-body">
            <table class="user-table" id="userTable">
              <thead><tr><th>Username</th><th>Role</th><th>Status</th><th>Actions</th></tr></thead>
              <tbody id="userBody"><tr><td colspan="4" style="color:var(--text-dim);">Loading...</td></tr></tbody>
            </table>
          </div>
        </div>
      </div>

      <div>
        <div class="section-title">Create New User</div>
        <div class="panel" data-label="New Operator Account">
          <div class="c tl"></div><div class="c tr"></div><div class="c bl"></div><div class="c br"></div>
          <div class="panel-body">
            <div class="form-field" style="margin-bottom:12px;">
              <label>Username</label>
              <input type="text" id="newUsername">
            </div>
            <div class="form-field" style="margin-bottom:12px;">
              <label>Password (min 8 chars)</label>
              <input type="password" id="newPassword">
            </div>
            <div class="form-field" style="margin-bottom:16px;">
              <label>Role</label>
              <select id="newRole">
                <option value="viewer">Viewer</option>
                <option value="admin">Admin</option>
              </select>
            </div>
            <button class="save-btn" onclick="createUser()">CREATE USER</button>
          </div>
        </div>
      </div>
    </div>
  </section>
  <?php endif; ?>

</main>

<div id="toasts"></div>

<!-- ── JAVASCRIPT ────────────────────────────────────────────────────── -->
<script>
const IS_ADMIN = <?= $is_admin ? 'true' : 'false' ?>;

// ── clock ─────────────────────────────────────────────────────────────
function tickClock() {
  const now = new Date();
  document.getElementById('clock').textContent =
    now.toLocaleTimeString('en-CA', {hour12:false}) + '  ' +
    now.toLocaleDateString('en-CA');
}
setInterval(tickClock, 1000); tickClock();

// ── tabs ──────────────────────────────────────────────────────────────
document.querySelectorAll('.nav-tab').forEach(tab => {
  tab.addEventListener('click', () => {
    document.querySelectorAll('.nav-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
    tab.classList.add('active');
    const id = 'tab-' + tab.dataset.tab;
    const sec = document.getElementById(id);
    if (sec) sec.classList.add('active');
    // Refresh on tab switch
    if (tab.dataset.tab === 'audit') { auditOffset=0; loadAudit(); }
    if (tab.dataset.tab === 'alarms') loadAlarms();
    if (tab.dataset.tab === 'users' && IS_ADMIN) loadUsers();
    if (tab.dataset.tab === 'thresholds') loadThresholds();
    if (tab.dataset.tab === 'controls') { loadSettings(); if(IS_ADMIN) loadNodeRename(); }
  });
});

// ── toast ─────────────────────────────────────────────────────────────
function toast(msg, type='info') {
  const t = document.createElement('div');
  t.className = 'toast ' + type;
  t.textContent = msg;
  document.getElementById('toasts').appendChild(t);
  setTimeout(() => t.remove(), 3200);
}

// ── api helpers ───────────────────────────────────────────────────────
async function apiGet(action, params={}) {
  const q = new URLSearchParams({action, ...params});
  const r = await fetch('/environment-monitor/api/data.php?' + q);
  return r.json();
}
async function apiPost(action, body={}) {
  const r = await fetch('/environment-monitor/api/control.php', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action, ...body})
  });
  return r.json();
}

// ── logout ────────────────────────────────────────────────────────────
async function logout() {
  await fetch('/environment-monitor/api/auth.php', {
    method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({action:'logout'})
  });
  window.location.href = '/environment-monitor/login.php';
}

// ── sparkline drawing ─────────────────────────────────────────────────
function drawSparkline(canvas, data, key, lineColor, fillColor) {
  if (!data || data.length < 2) return;
  const W = canvas.offsetWidth || 280, H = 48;
  canvas.width = W; canvas.height = H;
  const ctx = canvas.getContext('2d');
  const vals = data.map(d => parseFloat(d[key]));
  const min = Math.min(...vals), max = Math.max(...vals);
  const range = max - min || 1;
  const pad = 4;

  ctx.clearRect(0, 0, W, H);

  // subtle horizontal midline
  ctx.strokeStyle = 'rgba(255,255,255,0.06)';
  ctx.lineWidth = 1;
  ctx.beginPath();
  ctx.moveTo(0, H / 2); ctx.lineTo(W, H / 2);
  ctx.stroke();

  const points = vals.map((v, i) => ({
    x: (i / (vals.length - 1)) * W,
    y: H - pad - ((v - min) / range) * (H - pad * 2)
  }));

  // filled area
  const grad = ctx.createLinearGradient(0, 0, 0, H);
  grad.addColorStop(0, fillColor);
  grad.addColorStop(1, 'rgba(0,0,0,0)');
  ctx.beginPath();
  ctx.moveTo(points[0].x, H);
  points.forEach(p => ctx.lineTo(p.x, p.y));
  ctx.lineTo(points[points.length - 1].x, H);
  ctx.closePath();
  ctx.fillStyle = grad;
  ctx.fill();

  // line
  ctx.beginPath();
  points.forEach((p, i) => i === 0 ? ctx.moveTo(p.x, p.y) : ctx.lineTo(p.x, p.y));
  ctx.strokeStyle = lineColor;
  ctx.lineWidth = 2;
  ctx.lineJoin = 'round';
  ctx.stroke();

  // end dot
  const last = points[points.length - 1];
  ctx.beginPath();
  ctx.arc(last.x, last.y, 3, 0, Math.PI * 2);
  ctx.fillStyle = lineColor;
  ctx.fill();
}

// ── LIVE VIEW ─────────────────────────────────────────────────────────
let sparklineData = {};

async function loadLive() {
  const d = await apiGet('live');
  if (!d.ok) return;

  const grid = document.getElementById('nodeGrid');

  d.nodes.forEach(node => {
    let card = document.getElementById('node-' + node.node_id);
    if (!card) {
      card = buildNodeCard(node);
      grid.innerHTML = '';
      grid.appendChild(card);
    }

    // Update values
    const temp = parseFloat(node.temperature).toFixed(1);
    const hum  = parseFloat(node.humidity).toFixed(1);

    const tempEl = card.querySelector('.temp-val');
    const humEl  = card.querySelector('.hum-val');
    if (tempEl) tempEl.textContent = temp;
    if (humEl)  humEl.textContent  = hum;

    // Status
    const sb = card.querySelector('.status-badge');
    if (sb) {
      sb.textContent  = node.online ? 'ONLINE' : 'OFFLINE';
      sb.className = 'status-badge ' + (node.online ? 'online' : 'offline');
    }
    const ts = card.querySelector('.timestamp');
    if (ts) ts.textContent = node.recorded_at;
    const age = card.querySelector('.age-indicator');
    if (age) {
      const s = node.age_seconds;
      age.textContent = s < 60 ? s+'s ago' : Math.round(s/60)+'m ago';
      age.style.color = node.online ? 'var(--green)' : 'var(--red)';
    }
  });

  // If grid is empty (first load with nodes), rebuild entirely
  if (d.nodes.length > 0 && grid.querySelector('.node-card') === null) {
    grid.innerHTML = '';
    d.nodes.forEach(n => grid.appendChild(buildNodeCard(n)));
  } else if (d.nodes.length === 0) {
    grid.innerHTML = '<div style="color:var(--text-dim);font-size:11px;letter-spacing:1px;">No nodes reporting data yet.</div>';
  }
}

function buildNodeCard(node) {
  const temp = parseFloat(node.temperature).toFixed(1);
  const hum  = parseFloat(node.humidity).toFixed(1);
  const card = document.createElement('div');
  card.className = 'node-card panel';
  card.id = 'node-' + node.node_id;
  card.innerHTML = `
    <div class="card-header">
      <div>
        <div class="node-name">${escHtml(node.display_name)}</div>
        <div class="node-id-label">${escHtml(node.node_id)}</div>
      </div>
      <span class="status-badge ${node.online?'online':'offline'}">${node.online?'ONLINE':'OFFLINE'}</span>
    </div>
    <div class="readings">
      <div class="reading-block">
        <div class="r-label">Temperature</div>
        <div class="r-value temp-val">${temp}</div>
        <div class="r-unit">°C</div>
      </div>
      <div class="reading-block">
        <div class="r-label">Humidity</div>
        <div class="r-value hum-val">${hum}</div>
        <div class="r-unit">% RH</div>
      </div>
    </div>
    <div class="sparkline-wrap">
      <canvas class="sparkline" id="spark-t-${node.node_id}" style="display:block;margin-bottom:2px;"></canvas>
      <div style="font-size:8px;color:var(--text-dim);letter-spacing:1px;text-align:right;">Temp ↑</div>
      <canvas class="sparkline" id="spark-h-${node.node_id}" style="display:block;margin-top:4px;"></canvas>
      <div style="font-size:8px;color:var(--text-dim);letter-spacing:1px;text-align:right;">Humidity ↑</div>
    </div>
    <div class="card-footer">
      <span class="timestamp">${escHtml(node.recorded_at)}</span>
      <span class="age-indicator"></span>
    </div>`;
  // Load sparklines after a tick
  setTimeout(() => loadSparklines(node.node_id), 200);
  return card;
}

async function loadSparklines(nodeId) {
  const d = await apiGet('history', {node: nodeId, hours: 1});
  if (!d.ok || !d.history.length) return;
  const tc = document.getElementById('spark-t-' + nodeId);
  const hc = document.getElementById('spark-h-' + nodeId);
  if (tc) drawSparkline(tc, d.history, 'temperature', '#f0b030', 'rgba(240,176,48,0.15)');
  if (hc) drawSparkline(hc, d.history, 'humidity',    '#00c8e0', 'rgba(0,200,224,0.12)');
}

// ── SETTINGS / CONTROLS ───────────────────────────────────────────────
async function loadSettings() {
  const d = await apiGet('settings');
  if (!d.ok) return;
  const s = d.settings;
  const tog = document.getElementById('loggingToggle');
  const lbl = document.getElementById('loggingLabel');
  const inp = document.getElementById('intervalInput');
  if (tog) { tog.checked = s.logging_active === '1'; }
  if (lbl) { lbl.textContent = s.logging_active === '1' ? 'ACTIVE' : 'PAUSED'; lbl.style.color = s.logging_active === '1' ? 'var(--green)' : 'var(--red)'; }
  if (inp) inp.value = s.log_interval;
}

async function setLogging(val) {
  const lbl = document.getElementById('loggingLabel');
  const d = await apiPost('set_setting', {name:'logging_active', value: val ? '1' : '0'});
  if (d.ok) {
    toast(val ? 'Logging ACTIVE' : 'Logging PAUSED', 'ok');
    if (lbl) { lbl.textContent = val ? 'ACTIVE' : 'PAUSED'; lbl.style.color = val ? 'var(--green)' : 'var(--red)'; }
  } else toast(d.error || 'Error', 'err');
}

async function setScanRate() {
  const val = parseInt(document.getElementById('intervalInput').value);
  if (isNaN(val) || val < 5) { toast('Minimum 5 seconds', 'err'); return; }
  const d = await apiPost('set_setting', {name:'log_interval', value: val});
  d.ok ? toast(`Scan rate set to ${val}s`, 'ok') : toast(d.error || 'Error', 'err');
}

async function loadNodeRename() {
  const d = await apiGet('nodes');
  const container = document.getElementById('nodeRenameList');
  if (!container) return;
  // Also get nodes from live data
  const live = await apiGet('live');
  const allNodes = {};
  (live.nodes || []).forEach(n => allNodes[n.node_id] = n.display_name || n.node_id);
  (d.nodes || []).forEach(n => allNodes[n.node_id] = n.display_name || n.node_id);

  if (!Object.keys(allNodes).length) {
    container.innerHTML = '<span style="color:var(--text-dim);font-size:11px;">No nodes found.</span>';
    return;
  }
  container.innerHTML = Object.entries(allNodes).map(([id, name]) => `
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
      <span style="font-size:10px;color:var(--text-dim);letter-spacing:1px;min-width:90px;">${escHtml(id)}</span>
      <input class="hmi-input" style="width:200px;" id="rename-${id}" value="${escHtml(name)}" placeholder="Display name">
      <button class="apply-btn" onclick="renameNode('${id}')">RENAME</button>
    </div>`).join('');
}

async function renameNode(nodeId) {
  const inp = document.getElementById('rename-' + nodeId);
  const d = await apiPost('rename_node', {node_id: nodeId, display_name: inp.value});
  d.ok ? toast('Node renamed', 'ok') : toast(d.error || 'Error', 'err');
}

// ── KASA ──────────────────────────────────────────────────────────────
async function kasaToggle(switchId, btn) {
  const isOn = btn.textContent.trim() === 'ON';
  const d = await apiPost('kasa_toggle', {switch_id: switchId, state: !isOn});
  if (d.ok) {
    const newState = !isOn;
    btn.textContent = newState ? 'ON' : 'OFF';
    btn.className = 'kasa-btn ' + (newState ? 'on' : 'off');
    toast(`${switchId} turned ${newState ? 'ON' : 'OFF'} — logged`, 'ok');
  } else toast(d.error || 'Error', 'err');
}

// ── THRESHOLDS ────────────────────────────────────────────────────────
async function loadThresholds() {
  const [nodesD, threshD, liveD] = await Promise.all([
    apiGet('nodes'), apiGet('thresholds'), apiGet('live')
  ]);

  // Collect all known node_ids
  const nodeMap = {};
  (liveD.nodes || []).forEach(n => nodeMap[n.node_id] = n.display_name || n.node_id);
  (nodesD.nodes || []).forEach(n => nodeMap[n.node_id] = n.display_name || n.node_id);

  const threshByNode = {};
  (threshD.thresholds || []).forEach(t => threshByNode[t.node_id] = t);

  const container = document.getElementById('thresholdForms');
  const allNodes = ['global', ...Object.keys(nodeMap)];

  container.innerHTML = allNodes.map(nid => {
    const t   = threshByNode[nid] || {};
    const lbl = nid === 'global' ? 'GLOBAL (all nodes)' : (nodeMap[nid] || nid);
    const disabled = IS_ADMIN ? '' : 'disabled';
    return `
    <div class="panel" data-label="${escHtml(lbl)}">
      <div class="c tl"></div><div class="c tr"></div><div class="c bl"></div><div class="c br"></div>
      <div class="panel-body">
        <div class="thresh-node-label">${escHtml(lbl)}</div>
        <div class="thresh-grid">
          <div class="col">
            <div class="thresh-label">Temp High (°C)</div>
            <input class="thresh-input" ${disabled} id="t-th-${nid}" type="number" step="0.5" value="${t.temp_high ?? ''}">
          </div>
          <div class="col">
            <div class="thresh-label">Temp Low (°C)</div>
            <input class="thresh-input" ${disabled} id="t-tl-${nid}" type="number" step="0.5" value="${t.temp_low ?? ''}">
          </div>
          <div class="col">
            <div class="thresh-label">Humidity High (%)</div>
            <input class="thresh-input" ${disabled} id="t-hh-${nid}" type="number" step="1" value="${t.humidity_high ?? ''}">
          </div>
          <div class="col">
            <div class="thresh-label">Humidity Low (%)</div>
            <input class="thresh-input" ${disabled} id="t-hl-${nid}" type="number" step="1" value="${t.humidity_low ?? ''}">
          </div>
          <div class="col" style="display:flex;flex-direction:column;gap:8px;justify-content:flex-end;">
            ${IS_ADMIN ? `
            <label style="display:flex;align-items:center;gap:6px;font-size:9px;letter-spacing:1px;color:var(--text-dim);cursor:pointer;">
              <input type="checkbox" id="t-em-${nid}" ${t.alert_email!=='0'?'checked':''} style="accent-color:var(--amber);">
              EMAIL
            </label>
            <label style="display:flex;align-items:center;gap:6px;font-size:9px;letter-spacing:1px;color:var(--text-dim);cursor:pointer;">
              <input type="checkbox" id="t-dc-${nid}" ${t.alert_discord!=='0'?'checked':''} style="accent-color:var(--amber);">
              DISCORD
            </label>
            <button class="save-btn" style="margin-top:4px;" onclick="saveThreshold('${nid}')">SAVE</button>
            ` : '<span class="viewer-only-msg">Read-only</span>'}
          </div>
        </div>
      </div>
    </div>`;
  }).join('');
}

async function saveThreshold(nid) {
  const v = k => { const el = document.getElementById(k); return el && el.value !== '' ? parseFloat(el.value) : null; };
  const c = k => { const el = document.getElementById(k); return el ? el.checked : true; };
  const d = await apiPost('set_threshold', {
    node_id: nid,
    temp_high: v(`t-th-${nid}`), temp_low: v(`t-tl-${nid}`),
    humidity_high: v(`t-hh-${nid}`), humidity_low: v(`t-hl-${nid}`),
    alert_email: c(`t-em-${nid}`), alert_discord: c(`t-dc-${nid}`)
  });
  d.ok ? toast(`Thresholds saved: ${nid}`, 'ok') : toast(d.error || 'Error', 'err');
}

// ── ALARM LOG ─────────────────────────────────────────────────────────
async function loadAlarms() {
  const d = await apiGet('alarms', {limit:50});
  const body = document.getElementById('alarmBody');
  if (!d.ok) { body.innerHTML = '<tr><td colspan="6" style="color:var(--red);">Error loading alarms</td></tr>'; return; }
  const badge = document.getElementById('alarmBadge');
  if (d.alarms.length > 0) {
    badge.style.display = '';
    badge.textContent = d.alarms.length;
  } else badge.style.display = 'none';

  body.innerHTML = d.alarms.length ? d.alarms.map(a => `
    <tr class="alarm-row ${a.direction==='HIGH'?'high-alarm':'low-alarm'}">
      <td class="col-time">${a.created_at}</td>
      <td>${escHtml(a.display_name||a.node_id)}</td>
      <td>${escHtml(a.field).toUpperCase()}</td>
      <td style="color:var(--amber)">${parseFloat(a.value).toFixed(2)}</td>
      <td>${a.direction==='HIGH'?'▲ HIGH':'▼ LOW'}</td>
      <td style="color:var(--text-dim)">${a.threshold}</td>
    </tr>`).join('') :
    '<tr><td colspan="6" style="color:var(--text-dim);letter-spacing:1px;">No alarms recorded.</td></tr>';
}

// ── AUDIT LOG ─────────────────────────────────────────────────────────
let auditOffset = 0;
const AUDIT_PAGE = 50;

async function loadAudit(append=false) {
  const d = await apiGet('audit', {limit: AUDIT_PAGE, offset: auditOffset});
  const body = document.getElementById('auditBody');
  if (!d.ok) return;
  const rows = d.log.map(r => `
    <tr>
      <td class="col-time">${r.created_at}</td>
      <td class="col-user">${escHtml(r.username||'—')}</td>
      <td class="col-action">${escHtml(r.action||'')}</td>
      <td class="col-old">${escHtml(r.old_value||'—')}</td>
      <td class="col-new">${escHtml(r.new_value||'—')}</td>
    </tr>`).join('');

  if (append) {
    body.innerHTML += rows;
  } else {
    body.innerHTML = rows || '<tr><td colspan="5" style="color:var(--text-dim);">No audit entries.</td></tr>';
  }
  const btn = document.getElementById('loadMoreBtn');
  if (btn) btn.style.display = (auditOffset + AUDIT_PAGE) < d.total ? '' : 'none';
}

function loadMoreAudit() {
  auditOffset += AUDIT_PAGE;
  loadAudit(true);
}

// ── USERS ─────────────────────────────────────────────────────────────
async function loadUsers() {
  const d = await apiPost('list_users');
  const body = document.getElementById('userBody');
  if (!d.ok || !body) return;
  body.innerHTML = d.users.map(u => `
    <tr class="${u.active==='0'||u.active===0?'inactive-user':''}">
      <td>${escHtml(u.username)}</td>
      <td><span class="role-badge ${u.role}">${u.role}</span></td>
      <td style="color:${u.active?'var(--green)':'var(--text-dim)'};">${u.active?'ACTIVE':'INACTIVE'}</td>
      <td>
        <select onchange="updateUserRole(${u.id}, this.value)" style="background:var(--panel2);border:1px solid var(--border2);color:var(--text);font-family:var(--mono);font-size:11px;padding:3px 6px;">
          <option value="viewer" ${u.role==='viewer'?'selected':''}>Viewer</option>
          <option value="admin"  ${u.role==='admin' ?'selected':''}>Admin</option>
        </select>
        <button class="apply-btn" style="margin-left:6px;" onclick="toggleUserActive(${u.id}, ${u.active?0:1})">
          ${u.active?'DISABLE':'ENABLE'}
        </button>
      </td>
    </tr>`).join('') || '<tr><td colspan="4" style="color:var(--text-dim);">No users.</td></tr>';
}

async function updateUserRole(id, role) {
  const d = await apiPost('update_user', {id, role, active:1});
  d.ok ? toast('Role updated', 'ok') : toast(d.error||'Error','err');
  loadUsers();
}
async function toggleUserActive(id, active) {
  const d = await apiPost('update_user', {id, active});
  d.ok ? toast('User updated', 'ok') : toast(d.error||'Error','err');
  loadUsers();
}
async function createUser() {
  const u = document.getElementById('newUsername').value;
  const p = document.getElementById('newPassword').value;
  const r = document.getElementById('newRole').value;
  const d = await apiPost('create_user', {username:u, password:p, role:r});
  if (d.ok) {
    toast('User created: ' + u, 'ok');
    document.getElementById('newUsername').value = '';
    document.getElementById('newPassword').value = '';
    loadUsers();
  } else toast(d.error||'Error','err');
}

// ── UTILS ─────────────────────────────────────────────────────────────
function escHtml(str) {
  if (str === null || str === undefined) return '';
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── POLLING LOOP ──────────────────────────────────────────────────────
let liveInterval = null;

function startPolling() {
  loadLive();
  loadSettings();
  loadAlarms();
  liveInterval = setInterval(() => {
    loadLive();
    loadAlarms();
  }, 5000);
  // Refresh sparklines every minute
  setInterval(() => {
    document.querySelectorAll('.node-card').forEach(card => {
      const nid = card.id.replace('node-', '');
      if (nid) loadSparklines(nid);
    });
  }, 60000);
}

// Initial audit load
loadAudit();
startPolling();
</script>
</body>
</html>
