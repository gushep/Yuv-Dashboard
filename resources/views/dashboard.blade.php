<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>yuv · sprint metrics</title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root{
  --blue:#1d6ef5;--blue-lt:#4d8ef7;--blue-bg:#eff5ff;--blue-bg2:#ddeaff;
  --green:#16a34a;--green-bg:#f0fdf4;--green-bd:#bbf7d0;
  --red:#dc2626;--red-bg:#fef2f2;--red-bd:#fecaca;
  --yellow:#d97706;--yel-bg:#fffbeb;--yel-bd:#fde68a;
  --purple:#7c3aed;--teal:#0d9488;
  --bg:#f0f2f8;--sidebar:#ffffff;--card:#ffffff;--border:#e5e9f5;
  --text:#0d1117;--text2:#4b5568;--muted:#94a3b8;
  --shadow:0 1px 8px rgba(0,0,0,.06);--shadow-md:0 4px 20px rgba(0,0,0,.1);
  --sidebar-w:230px;--sidebar-rail-w:64px;--topbar-h:52px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}

/* SIDEBAR */
.sidebar{width:var(--sidebar-w);min-height:100vh;background:var(--sidebar);border-right:1px solid var(--border);display:flex;flex-direction:column;position:fixed;top:0;left:0;bottom:0;z-index:50;transform:translateX(-100%);transition:transform .25s ease,width .2s ease;box-shadow:var(--shadow);overflow:hidden}
.sidebar.open{transform:translateX(0)}
.sidebar-logo{height:var(--topbar-h);padding:0 14px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.logo-text{display:flex;align-items:center;gap:9px;font-size:14px;font-weight:700;color:var(--text);letter-spacing:-.02em}
.sidebar-brand-image{display:block;width:72px;height:auto}
.logo-dot{width:7px;height:7px;border-radius:50%;background:var(--blue);display:inline-block;margin-right:6px}
.sidebar-close{display:flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:6px;cursor:pointer;color:var(--muted);border:none;background:transparent;transition:background .15s}
.sidebar-close:hover{background:var(--bg);color:var(--text)}
.sidebar-nav{flex:1;padding:10px 0;overflow-y:auto}
.nav-item,.sprint-toggle,.sidebar-action{display:flex;align-items:center;gap:12px;width:100%;padding:9px 20px;border:0;border-left:2px solid transparent;background:transparent;font:inherit;font-size:13px;font-weight:500;text-align:left;white-space:nowrap;cursor:pointer;color:var(--text2);transition:all .15s;user-select:none}
.sprint-toggle[hidden]{display:none}
.nav-item:hover,.sidebar-action:hover{color:var(--blue);background:var(--blue-bg)}
.nav-item.active{color:var(--blue);border-left-color:var(--blue);background:var(--blue-bg);font-weight:600}
.nav-item:focus-visible,.sprint-toggle:focus-visible,.sidebar-action:focus-visible{outline:2px solid var(--blue);outline-offset:-2px}
.nav-item svg,.sidebar-action svg{width:20px;height:20px;flex-shrink:0}
.nav-group-label{font-size:10px;font-weight:600;color:var(--muted);letter-spacing:.12em;text-transform:uppercase;padding:14px 18px 4px}
.sprint-toggle:hover{color:var(--blue)}
.sprint-toggle.active{color:var(--blue);font-weight:600}
.sprint-toggle svg{width:20px;height:20px;flex-shrink:0}
.sprint-arrow{margin-left:auto;width:12px;height:12px;transition:transform .2s;opacity:.5}
.sprint-arrow.open{transform:rotate(90deg)}
.sprint-children{display:none;background:var(--bg)}
.sprint-children.open{display:block}
.sprint-children .nav-item{font-size:12px;padding:7px 20px 7px 44px;border-left:none}
.sprint-children .nav-item svg{width:15px;height:15px}
.sprint-children .nav-item.active{background:var(--blue-bg2);color:var(--blue)}
.sidebar-footer{padding:6px 0 10px;border-top:1px solid var(--border)}
.sidebar-footer form{margin:0}
.sidebar-version{padding:6px 20px 0;font-size:10px;color:var(--muted);font-family:'DM Mono',monospace;white-space:nowrap}

/* OVERLAY */
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:49}
.sidebar-overlay.open{display:block}

/* MAIN */
.main{min-height:100vh;display:flex;flex-direction:column;transition:margin-left .25s}
.topbar{background:var(--card);border-bottom:1px solid var(--border);height:var(--topbar-h);display:flex;align-items:center;gap:12px;padding:0 20px;position:sticky;top:0;z-index:40;box-shadow:var(--shadow)}
.hamburger{display:flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:7px;cursor:pointer;color:var(--text2);border:1px solid var(--border);background:transparent;transition:all .15s;flex-shrink:0}
.hamburger:hover{background:var(--blue-bg);color:var(--blue);border-color:var(--blue-lt)}
.hamburger svg{width:16px;height:16px}
.topbar-title{font-size:14px;font-weight:700;letter-spacing:-.02em}
.topbar-sub{font-size:11px;color:var(--muted);margin-top:1px;font-weight:400}
.topbar-right{margin-left:auto;font-size:11px;color:var(--muted);font-family:'DM Mono',monospace}

@media(min-width:1024px){
  /* Menu sempre recolhido em ícones; expande sobre o conteúdo no hover/teclado e recolhe ao abrir uma página (.suppressed). */
  .sidebar{transform:translateX(0);width:var(--sidebar-rail-w);transition:width .18s ease,box-shadow .18s ease}
  .sidebar:is(:hover,:has(:focus-visible)):not(.suppressed){width:var(--sidebar-w);box-shadow:var(--shadow-md);transition-delay:.12s}
  .sidebar:is(:not(:hover):not(:has(:focus-visible)),.suppressed) :is(.logo-text span,.nav-label,.sprint-arrow,.sprint-children,.sidebar-version){display:none}
  .sidebar:is(:not(:hover):not(:has(:focus-visible)),.suppressed) .sidebar-logo{padding:0 10px}
  .sidebar:is(:not(:hover):not(:has(:focus-visible)),.suppressed) .sidebar-brand-image{width:44px}
  .sidebar:is(:not(:hover):not(:has(:focus-visible)),.suppressed) .sprint-toggle.active{background:var(--blue-bg);border-left-color:var(--blue)}
  .main{margin-left:var(--sidebar-rail-w)}
  .hamburger{display:none}
  .sidebar-close{display:none}
  .sidebar-overlay{display:none !important}
}

/* CONTENT */
.content{padding:24px 20px 60px;flex:1}
@media(min-width:768px){.content{padding:28px 28px 60px}}
@media(min-width:1200px){.content{padding:32px 36px 60px}}

/* SECTION LABEL */
.sec{display:flex;align-items:center;gap:10px;margin-bottom:14px;margin-top:32px}
.sec:first-child{margin-top:0}
.sec-label{font-size:10px;font-weight:600;color:var(--blue);letter-spacing:.15em;text-transform:uppercase;white-space:nowrap}
.sec-line{flex:1;height:1px;background:var(--border)}

/* KPI GRID */
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:10px;margin-bottom:4px}
.kpi{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:16px 16px 14px;position:relative;overflow:hidden;box-shadow:var(--shadow);transition:box-shadow .2s,transform .18s;cursor:default}
.kpi:hover{box-shadow:var(--shadow-md);transform:translateY(-1px)}
.kpi-accent{position:absolute;top:0;left:0;right:0;height:3px}
.kpi.green .kpi-accent{background:var(--green)}
.kpi.red .kpi-accent{background:var(--red)}
.kpi.blue .kpi-accent{background:var(--blue)}
.kpi.neutral .kpi-accent{background:var(--muted)}
.kpi.yellow .kpi-accent{background:var(--yellow)}
.kpi-label{font-size:10px;font-weight:600;color:var(--muted);letter-spacing:.08em;text-transform:uppercase;margin-bottom:8px;padding-top:4px}
.kpi-val{font-size:26px;font-weight:700;letter-spacing:-.03em;line-height:1;margin-bottom:5px}
.kpi.green .kpi-val{color:var(--text)}
.kpi.red .kpi-val{color:var(--red)}
.kpi.blue .kpi-val{color:var(--blue)}
.kpi.neutral .kpi-val{color:var(--text)}
.kpi-sub{font-size:11px;color:var(--text2);margin-bottom:8px;line-height:1.4}
.kpi-trend{display:inline-flex;align-items:center;gap:3px;font-size:10.5px;font-weight:600;padding:2px 7px;border-radius:100px;font-family:'DM Mono',monospace}
.trend-good{background:var(--green-bg);color:var(--green);border:1px solid var(--green-bd)}
.trend-bad{background:var(--red-bg);color:var(--red);border:1px solid var(--red-bd)}
.trend-neutral{background:#f8fafc;color:var(--muted);border:1px solid var(--border)}
.kpi-meta{font-size:10px;color:var(--muted);margin-top:5px}
.kpi-breakdown{display:flex;gap:6px;margin-top:6px;flex-wrap:wrap}
.kpi.kpi-clickable{cursor:pointer}
.kpi.kpi-clickable:focus-visible{outline:2px solid var(--blue);outline-offset:2px}
.kpi-tip{position:fixed;z-index:60;max-width:300px;padding:10px 12px;border-radius:8px;background:var(--text);color:#fff;font-size:11.5px;line-height:1.45;box-shadow:var(--shadow-md);pointer-events:none}
.kpi-tip[hidden]{display:none}
.kpi-tip-crit{margin-top:6px;color:#cbd5e1}
.kpi-tip-hint{margin-top:6px;font-size:10.5px;color:var(--muted)}
.kpi-modal{width:min(1000px,calc(100vw - 32px));max-height:calc(100vh - 64px);padding:0;border:1px solid var(--border);border-radius:12px;background:var(--card);color:var(--text);box-shadow:var(--shadow-md)}
.kpi-modal[open]{display:flex;flex-direction:column}
.kpi-modal::backdrop{background:rgba(0,0,0,.45)}
.kpi-modal-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:16px 18px;border-bottom:1px solid var(--border)}
.kpi-modal-title{font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px}
.kpi-modal-sub{font-size:11.5px;color:var(--text2);margin-top:4px;line-height:1.4}
.kpi-modal-close{flex:none;border:0;background:var(--bg);color:var(--text2);width:30px;height:30px;border-radius:8px;cursor:pointer;font-size:14px}
.kpi-modal-close:hover{background:var(--border)}
.kpi-modal-body{overflow:auto}
.kpi-modal-body a{color:var(--blue);text-decoration:none}
.kpi-modal-body a:hover{text-decoration:underline}
.kpi-bd-item{font-size:10px;padding:1px 6px;border-radius:4px;font-weight:600}

/* CHART GRID */
.g2{display:grid;grid-template-columns:1fr;gap:12px;margin-bottom:4px}
.g3{display:grid;grid-template-columns:1fr;gap:12px;margin-bottom:4px}
@media(min-width:700px){.g2{grid-template-columns:1fr 1fr}.g3{grid-template-columns:1fr 1fr}}
@media(min-width:1100px){.g3{grid-template-columns:1fr 1fr 1fr}}

/* CARD */
.card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:18px 18px 14px;box-shadow:var(--shadow);transition:box-shadow .2s}
.card:hover{box-shadow:var(--shadow-md)}
.card-head{margin-bottom:14px}
.card-title{font-size:12.5px;font-weight:700;color:var(--text);letter-spacing:-.01em}
.card-sub{font-size:11px;color:var(--muted);margin-top:3px}
.cw{position:relative}
.h160{height:160px}.h200{height:200px}.h220{height:220px}.h260{height:260px}

/* TABLE */
.tcard{background:var(--card);border:1px solid var(--border);border-radius:12px;overflow:hidden;box-shadow:var(--shadow)}
.tbar{padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.tbar-title{font-size:12.5px;font-weight:700;color:var(--text)}
.tcnt{font-size:11px;color:var(--muted);background:var(--bg);border:1px solid var(--border);padding:2px 8px;border-radius:100px;font-family:'DM Mono',monospace}
.tfilters{display:flex;gap:6px;flex-wrap:wrap;margin-left:auto}
.tfbtn{font-size:11px;font-weight:600;padding:4px 10px;border-radius:6px;border:1px solid var(--border);background:var(--card);color:var(--text2);cursor:pointer;transition:all .14s}
.tfbtn:hover{border-color:var(--blue);color:var(--blue);background:var(--blue-bg)}
.tfbtn.active{border-color:var(--blue);color:var(--blue);background:var(--blue-bg)}
.tsrch{font-size:12px;background:var(--bg);border:1px solid var(--border);border-radius:7px;padding:6px 12px;color:var(--text);width:200px;outline:none;transition:border-color .15s}
.tsrch:focus{border-color:var(--blue);box-shadow:0 0 0 3px rgba(29,110,245,.08)}
.tscroll{overflow-x:auto}
table{width:100%;border-collapse:collapse}
thead th{font-size:10px;font-weight:600;color:var(--muted);text-align:left;padding:9px 16px;letter-spacing:.08em;text-transform:uppercase;background:var(--bg);border-bottom:1px solid var(--border);white-space:nowrap;cursor:pointer;user-select:none}
thead th:hover{color:var(--blue)}
tbody tr{border-bottom:1px solid var(--border);transition:background .1s}
tbody tr:last-child{border-bottom:none}
tbody tr:hover{background:#f8f9ff}
tbody td{padding:9px 16px;font-size:12px;color:var(--text);max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
td.cm{color:var(--text2);font-family:'DM Mono',monospace;font-size:11px}
.tfoot{padding:10px 18px;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;background:var(--bg)}
.pgr{font-size:11px;color:var(--muted);font-family:'DM Mono',monospace}
.pbtns{display:flex;gap:6px}
.pbtn{font-size:11px;font-weight:600;padding:5px 12px;border:1px solid var(--border);border-radius:7px;background:var(--card);color:var(--text2);cursor:pointer;transition:all .14s}
.pbtn:hover:not(:disabled){border-color:var(--blue);color:var(--blue);background:var(--blue-bg)}
.pbtn:disabled{opacity:.35;cursor:default}

/* BADGES */
.b{display:inline-flex;align-items:center;padding:2px 7px;border-radius:5px;font-size:10px;font-weight:600;white-space:nowrap;letter-spacing:.02em}
.bd{background:var(--green-bg);color:var(--green);border:1px solid var(--green-bd)}
.bw{background:var(--blue-bg);color:var(--blue);border:1px solid var(--blue-bg2)}
.bn{background:var(--yel-bg);color:var(--yellow);border:1px solid var(--yel-bd)}
.bs{background:var(--red-bg);color:var(--red);border:1px solid var(--red-bd)}
.bq{background:#faf5ff;color:var(--purple);border:1px solid #e9d5ff}
.bo{background:#f8fafc;color:var(--text2);border:1px solid var(--border)}
.bep{background:#fff7ed;color:#c2410c;border:1px solid #fed7aa}
.bbug{background:var(--red-bg);color:var(--red);border:1px solid var(--red-bd)}
.bval{background:var(--green-bg);color:var(--green);border:1px solid var(--green-bd)}

/* MISC */
.nd{text-align:center;padding:40px;color:var(--muted);font-size:12px}
.page{display:none}.page.active{display:block}
.dev-hdr{display:flex;align-items:center;gap:14px;background:var(--card);border:1px solid var(--border);border-radius:12px;padding:18px 22px;margin-bottom:12px;box-shadow:var(--shadow)}
.dev-av{width:44px;height:44px;border-radius:10px;background:linear-gradient(135deg,var(--blue),var(--purple));display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:700;color:#fff;flex-shrink:0;letter-spacing:-.02em}
.dev-name{font-size:15px;font-weight:700;letter-spacing:-.02em}
.dev-meta{font-size:11px;color:var(--muted);margin-top:2px}
.filter-bar{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:14px 18px;margin-bottom:12px;box-shadow:var(--shadow);display:flex;flex-wrap:wrap;align-items:flex-end;gap:12px}
.fgroup{display:flex;flex-direction:column;gap:4px}
.flabel{font-size:10px;font-weight:600;color:var(--muted);letter-spacing:.08em;text-transform:uppercase}
.fsel{font-size:12.5px;background:var(--bg);border:1px solid var(--border);border-radius:7px;padding:6px 10px;color:var(--text);outline:none;cursor:pointer;min-width:190px;transition:border-color .15s}
.fsel:focus{border-color:var(--blue)}
.fclear{display:flex;align-items:center;gap:5px;padding:6px 12px;border-radius:7px;cursor:pointer;font-size:11.5px;font-weight:600;color:var(--red);background:var(--red-bg);border:1px solid var(--red-bd);transition:all .15s;align-self:flex-end}
.fclear:hover{background:#fee2e2}
.fclear svg{width:12px;height:12px}
.epic-card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:18px 20px;box-shadow:var(--shadow);transition:box-shadow .2s,transform .18s}
.epic-card:hover{box-shadow:var(--shadow-md);transform:translateY(-1px)}
.epic-name{font-size:13px;font-weight:700;letter-spacing:-.01em;margin-bottom:12px;display:flex;align-items:center;gap:8px}
.epic-status{display:inline-flex;align-items:center;padding:2px 8px;border-radius:100px;font-size:10px;font-weight:600}
.epic-done{background:var(--green-bg);color:var(--green);border:1px solid var(--green-bd)}
.epic-wip{background:var(--blue-bg);color:var(--blue);border:1px solid var(--blue-bg2)}
.epic-stats{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.epic-stat{background:var(--bg);border-radius:8px;padding:10px 12px}
.epic-stat-val{font-size:18px;font-weight:700;letter-spacing:-.02em;color:var(--text)}
.epic-stat-lbl{font-size:10px;color:var(--muted);margin-top:2px;font-weight:500}
.epics-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px;margin-bottom:4px}
.settings-card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:22px;box-shadow:var(--shadow);max-width:900px;margin-bottom:14px}
.settings-card h2{font-size:15px;margin-bottom:7px}
.settings-card p{font-size:12px;color:var(--text2);line-height:1.55;margin-bottom:15px}
.settings-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:13px;margin:14px 0}
.settings-field{display:flex;flex-direction:column;gap:5px;font-size:11px;font-weight:600;color:var(--text2)}
.settings-field input,.settings-field select,.settings-field textarea{width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:7px;background:#fff;color:var(--text);font:inherit;font-size:12px}
.settings-actions{display:flex;gap:9px;flex-wrap:wrap;margin-top:17px}
.settings-actions button{padding:9px 14px;border:1px solid var(--blue);border-radius:7px;background:var(--blue);color:#fff;font-size:12px;font-weight:600;cursor:pointer}
.settings-actions button.secondary{background:#fff;color:var(--blue)}
.settings-actions button:disabled{opacity:.55;cursor:wait}
.empty-state{grid-column:1/-1;padding:28px;border:1px dashed var(--border);border-radius:12px;background:var(--card);color:var(--text2);font-size:13px;text-align:center}
.settings-message{font-size:12px;line-height:1.5;color:var(--text2);margin:10px 0}
.settings-message.error{color:var(--red)}
.settings-message.success{color:var(--green)}
 </style>
<link rel="stylesheet" href="bombeiro.css">
<link rel="stylesheet" href="sprint-panel.css">
<link rel="stylesheet" href="panel-carousel.css">
<link rel="stylesheet" href="capacity.css">
<link rel="stylesheet" href="forecast.css">
<link rel="stylesheet" href="consumption.css">
<link rel="stylesheet" href="release.css">
<link rel="stylesheet" href="control-tower.css">
<link rel="stylesheet" href="users.css">
</head>
<body>
<aside class="sidebar" id="sidebar" onmouseleave="this.classList.remove('suppressed')" onkeydown="this.classList.remove('suppressed')">
  <div class="sidebar-logo">
    <div class="logo-text"><img src="yuv-logo.png" alt="YUV" class="sidebar-brand-image"><span>metrics</span></div>
    <button type="button" class="sidebar-close" onclick="dismissSidebar()" aria-label="Fechar menu lateral">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>
  </div>
  <nav class="sidebar-nav">
    @can('access-screen', 'control-tower')
    <button type="button" class="nav-item" onclick="navigate('control-tower')" id="nav-control-tower">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v3M5 5l2 2M19 5l-2 2"/><circle cx="12" cy="12" r="4"/><path d="M8 21h8M12 16v5"/></svg>
      <span class="nav-label">Torre de controle</span>
      <span class="nav-badge" id="tower-badge" hidden></span>
    </button>
    @endcan
    @can('access-screen', 'dashboard')
    <button type="button" class="nav-item active" onclick="navigate('dashboard')" id="nav-dashboard" aria-current="page">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
      <span class="nav-label">Dashboard</span>
    </button>
    @endcan
    <button type="button" class="sprint-toggle" onclick="toggleSprints()" id="sprint-toggle" aria-expanded="false" aria-controls="sprint-children" @cannot('access-screen', 'sprint') hidden @endcannot>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
      <span class="nav-label">Sprints</span>
      <svg class="sprint-arrow" id="sprint-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>
    </button>
    <div class="sprint-children" id="sprint-children">
      <div id="sprint-nav-items"></div>
    </div>
    @can('access-screen', 'epicos')
    <button type="button" class="nav-item" onclick="navigate('epicos')" id="nav-epicos">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3h7v7H3z"/><path d="M14 3h7v7h-7z"/><path d="M14 14h7v7h-7z"/><path d="M3 14h7v7H3z"/></svg>
      <span class="nav-label">Épicos</span>
    </button>
    @endcan
    @can('access-screen', 'time')
    <button type="button" class="nav-item" onclick="navigate('time')" id="nav-time">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      <span class="nav-label">Time</span>
    </button>
    @endcan
    @can('access-screen', 'raw')
    <button type="button" class="nav-item" onclick="navigate('raw')" id="nav-raw">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M3 15h18M9 3v18"/></svg>
      <span class="nav-label">Dado Bruto</span>
    </button>
    @endcan
    @can('access-screen', 'bombeiro')
    <button type="button" class="nav-item" onclick="navigate('bombeiro')" id="nav-bombeiro">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3c2 3 2 5 0 7 3-1 5 2 5 5a5 5 0 0 1-10 0c0-2 1-4 3-6 0 2 1 3 2 3"/><path d="M12 21a5 5 0 0 0 5-5"/></svg>
      <span class="nav-label">Painel do Bombeiro</span>
    </button>
    @endcan
    @can('access-screen', 'sprint-panel')
    <button type="button" class="nav-item" onclick="navigate('sprint-panel')" id="nav-sprint-panel">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 10h18M9 10v10m6-10v10"/></svg>
      <span class="nav-label">Painel da Sprint</span>
    </button>
    @endcan
    @can('access-screen', 'carousel')
    <button type="button" class="nav-item" onclick="navigate('carousel')" id="nav-carousel">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m9 8 7 4-7 4V8Z"/></svg>
      <span class="nav-label">Apresentação dos Painéis</span>
    </button>
    @endcan
    @can('access-screen', 'capacity')
    <button type="button" class="nav-item" onclick="navigate('capacity')" id="nav-capacity">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 20h18M6 17V9m6 8V4m6 13v-6"/><circle cx="6" cy="7" r="1"/><circle cx="12" cy="2" r="1"/><circle cx="18" cy="9" r="1"/></svg>
      <span class="nav-label">Capacity</span>
    </button>
    @endcan
    @can('access-screen', 'forecast')
    <button type="button" class="nav-item" onclick="navigate('forecast')" id="nav-forecast">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v18h18M6 15l5-6 4 3 6-8"/></svg>
      <span class="nav-label">Previsibilidade</span>
    </button>
    @endcan
    @can('access-screen', 'consumption')
    <button type="button" class="nav-item" onclick="navigate('consumption')" id="nav-consumption">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 6v6l4 2"/></svg>
      <span class="nav-label">Consumo de horas</span>
    </button>
    @endcan
    @can('access-screen', 'release')
    <button type="button" class="nav-item" onclick="navigate('release')" id="nav-release">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 3h9l4 4v14H6zM14 3v5h5M9 12h7M9 16h7"/></svg>
      <span class="nav-label">Release da sprint</span>
    </button>
    @endcan
    @can('access-screen', 'users')
    <button type="button" class="nav-item" onclick="navigate('users')" id="nav-users">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="7" r="4"/><path d="M2 21v-2a5 5 0 0 1 5-5h4a5 5 0 0 1 5 5v2"/><path d="M19 8v6M16 11h6"/></svg>
      <span class="nav-label">Usuários</span>
    </button>
    @endcan
    @can('access-screen', 'settings')
    <button type="button" class="nav-item" onclick="navigate('settings')" id="nav-settings">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M12 2v2m0 16v2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M2 12h2m16 0h2M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42"/></svg>
      <span class="nav-label">Configurações</span>
    </button>
    @endcan
  </nav>
  <div class="sidebar-footer">
    <form method="post" action="/logout">@csrf<button type="submit" class="sidebar-action">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
      <span class="nav-label">Sair da conta</span>
    </button></form>
    <div class="sidebar-version">sprint metrics v4</div>
  </div>
</aside>
<div class="sidebar-overlay" id="overlay" onclick="dismissSidebar()"></div>
<div class="main">
  <div class="topbar">
    <button type="button" class="hamburger" onclick="openSidebar()" aria-label="Abrir menu lateral">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
    </button>
    <div><div class="topbar-title" id="tb-title">Dashboard</div><div class="topbar-sub" id="tb-sub">aguardando dados do Monday</div></div>
    <div class="topbar-right" id="tb-right"></div>
  </div>
  <div class="content">

    <!-- DASHBOARD -->
    <div class="page active" id="page-dashboard">
      <div class="sec"><span class="sec-label">indicadores gerais</span><div class="sec-line"></div></div>
      <div class="kpi-grid" id="dash-kpis"></div>
      <div class="sec"><span class="sec-label">entrega</span><div class="sec-line"></div></div>
      <div class="g2">
        <div class="card"><div class="card-head"><div class="card-title">Taxa de Conclusão por Sprint</div><div class="card-sub">% implementados · meta &gt;80%</div></div><div class="cw h200"><canvas id="cConclusion"></canvas></div></div>
        <div class="card"><div class="card-head"><div class="card-title">Volume por Sprint</div><div class="card-sub">planejados vs não planejados</div></div><div class="cw h200"><canvas id="cVolume"></canvas></div></div>
        <div class="card"><div class="card-head"><div class="card-title">Taxa de Arrastamento</div><div class="card-sub">itens carregados de sprints anteriores · meta &lt;25%</div></div><div class="cw h200"><canvas id="cDrag"></canvas></div></div>
        <div class="card"><div class="card-head"><div class="card-title">Cobertura de Estimativa</div><div class="card-sub">% itens com estimativa preenchida · meta &gt;90%</div></div><div class="cw h200"><canvas id="cAdher"></canvas></div></div>
      </div>
      <div class="sec"><span class="sec-label">qualidade</span><div class="sec-line"></div></div>
      <div class="g3">
        <div class="card"><div class="card-head"><div class="card-title">Retrabalho por Sprint</div><div class="card-sub">% · meta &lt;15%</div></div><div class="cw h200"><canvas id="cRework"></canvas></div></div>
        <div class="card"><div class="card-head"><div class="card-title">Bugs em Produção</div><div class="card-sub">por sprint · meta = 0</div></div><div class="cw h200"><canvas id="cBugs"></canvas></div></div>
        <div class="card"><div class="card-head"><div class="card-title">Novas Funcionalidades</div><div class="card-sub">novas funcionalidades entregues</div></div><div class="cw h200"><canvas id="cValor"></canvas></div></div>
        <div class="card"><div class="card-head"><div class="card-title">Índice de Escopo Limpo</div><div class="card-sub">planejados + implementados + sem retrabalho · meta &gt;60%</div></div><div class="cw h200"><canvas id="cClean"></canvas></div></div>
        <div class="card"><div class="card-head"><div class="card-title">Carga de Épicos por Sprint</div><div class="card-sub">% tasks vinculadas a épicos</div></div><div class="cw h200"><canvas id="cEpicLoad"></canvas></div></div>
      </div>
      <div class="sec"><span class="sec-label">composição</span><div class="sec-line"></div></div>
      <div class="g2">
        <div class="card"><div class="card-head"><div class="card-title">Distribuição por Tipo</div><div class="card-sub">todos os sprints</div></div><div class="cw h220"><canvas id="cType"></canvas></div></div>
        <div class="card"><div class="card-head"><div class="card-title">Breakup de Qualidade</div><div class="card-sub">limpos vs retrabalho vs bug vs ambos</div></div><div class="cw h220"><canvas id="cQuality"></canvas></div></div>
      </div>
    </div>

    <!-- SPRINT -->
    <div class="page" id="page-sprint">
      <div class="sec"><span class="sec-label">indicadores da sprint</span><div class="sec-line"></div></div>
      <div class="kpi-grid" id="sprint-kpis"></div>
      <div class="kpi-tip" id="kpi-tip" role="tooltip" hidden></div>
      <dialog class="kpi-modal" id="kpi-modal" aria-label="Tasks do indicador" onclick="if(event.target===this) this.close()"></dialog>
      <div class="sec"><span class="sec-label">composição</span><div class="sec-line"></div></div>
      <div class="g2">
        <div class="card"><div class="card-head"><div class="card-title">Por Status</div><div class="card-sub">distribuição dos itens</div></div><div class="cw h220"><canvas id="sStatus"></canvas></div></div>
        <div class="card"><div class="card-head"><div class="card-title">Por Tipo</div><div class="card-sub">categorias de trabalho</div></div><div class="cw h220"><canvas id="sType"></canvas></div></div>
        <div class="card"><div class="card-head"><div class="card-title">Por Prioridade</div><div class="card-sub">distribuição crítica → baixa</div></div><div class="cw h220"><canvas id="sPriority"></canvas></div></div>
        <div class="card"><div class="card-head"><div class="card-title">Planejados vs Não Planejados</div><div class="card-sub">surpresas da sprint</div></div><div class="cw h220"><canvas id="sPlanned"></canvas></div></div>
      </div>
      <div class="sec"><span class="sec-label">épicos na sprint</span><div class="sec-line"></div></div>
      <div class="g2">
        <div class="card"><div class="card-head"><div class="card-title">Itens por Épico</div><div class="card-sub">volume de trabalho por épico nesta sprint</div></div><div class="cw h200"><canvas id="sEpicos"></canvas></div></div>
        <div class="card"><div class="card-head"><div class="card-title">Itens por Dev</div><div class="card-sub">total vs implementados</div></div><div class="cw h200"><canvas id="sDev"></canvas></div></div>
      </div>
      <div class="sec"><span class="sec-label">itens da sprint</span><div class="sec-line"></div></div>
      <div class="tcard" id="sprint-table-wrap"></div>
    </div>

    <!-- ÉPICOS -->
    <div class="page" id="page-epicos">
      <div class="sec"><span class="sec-label">épicos</span><div class="sec-line"></div></div>
      <div class="epics-grid" id="epicos-cards"></div>
      <div class="sec"><span class="sec-label">visão histórica</span><div class="sec-line"></div></div>
      <div class="g2">
        <div class="card"><div class="card-head"><div class="card-title">Horas Investidas por Épico</div><div class="card-sub">horas reais acumuladas</div></div><div class="cw h220"><canvas id="eHours"></canvas></div></div>
        <div class="card"><div class="card-head"><div class="card-title">% das Tasks da Sprint por Épico</div><div class="card-sub">fatia do volume de cada sprint consumida por épico</div></div><div class="cw h220"><canvas id="ePerSprint"></canvas></div></div>
      </div>
      <div class="sec"><span class="sec-label">detalhe</span><div class="sec-line"></div></div>
      <div class="tcard" id="epicos-table-wrap"></div>
    </div>

    <!-- TIME -->
    <div class="page" id="page-time">
      <div class="filter-bar">
        <div class="fgroup">
          <span class="flabel">Desenvolvedor</span>
          <select id="timeDevSel" onchange="renderTime()" class="fsel"><option value="all">Todos os devs</option></select>
        </div>
        <div class="fgroup">
          <span class="flabel">Sprint</span>
          <select id="timeSpSel" onchange="renderTime()" class="fsel"><option value="all">Todas as sprints</option></select>
        </div>
        <div class="fclear" id="timeClear" onclick="clearTimeFilters()" style="display:none">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          Limpar
        </div>
      </div>
      <div id="time-overview">
        <div class="sec"><span class="sec-label">comparativo do time</span><div class="sec-line"></div></div>
        <div class="g2">
          <div class="card"><div class="card-head"><div class="card-title">Total e Implementados por Dev</div><div class="card-sub">volume vs entrega</div></div><div class="cw h220"><canvas id="tDevItems"></canvas></div></div>
          <div class="card"><div class="card-head"><div class="card-title">Retrabalho por Dev</div><div class="card-sub">% · meta &lt;15%</div></div><div class="cw h220"><canvas id="tDevRework"></canvas></div></div>
          <div class="card"><div class="card-head"><div class="card-title">Cobertura de Estimativa por Dev</div><div class="card-sub">% itens estimados · meta &gt;90%</div></div><div class="cw h220"><canvas id="tDevAdher"></canvas></div></div>
          <div class="card"><div class="card-head"><div class="card-title">Perfil de Trabalho por Dev</div><div class="card-sub">distribuição por tipo</div></div><div class="cw h220"><canvas id="tDevProfile"></canvas></div></div>
          <div class="card"><div class="card-head"><div class="card-title">Horas Registradas por Dev</div><div class="card-sub">por sprint · cap. 60h</div></div><div class="cw h220"><canvas id="tDevHours"></canvas></div></div>
        </div>
      </div>
      <div id="time-individual" style="display:none">
        <div class="dev-hdr" id="dev-header"></div>
        <div class="sec"><span class="sec-label">indicadores individuais</span><div class="sec-line"></div></div>
        <div class="kpi-grid" id="dev-kpis"></div>
        <div id="dev-charts-section">
          <div class="sec"><span class="sec-label">evolução sprint a sprint</span><div class="sec-line"></div></div>
          <div class="g2">
            <div class="card"><div class="card-head"><div class="card-title">Taxa de Conclusão</div><div class="card-sub">% por sprint</div></div><div class="cw h180"><canvas id="tDevConclusion"></canvas></div></div>
            <div class="card"><div class="card-head"><div class="card-title">Volume de Itens</div><div class="card-sub">participou por sprint</div></div><div class="cw h180"><canvas id="tDevVolume"></canvas></div></div>
            <div class="card"><div class="card-head"><div class="card-title">Horas por Sprint</div><div class="card-sub">registradas vs capacidade 60h</div></div><div class="cw h180"><canvas id="tDevHoursEvo"></canvas></div></div>
            <div class="card"><div class="card-head"><div class="card-title">Retrabalho por Sprint</div><div class="card-sub">% por sprint</div></div><div class="cw h180"><canvas id="tDevReworkEvo"></canvas></div></div>
          </div>
          <div class="sec"><span class="sec-label">perfil de trabalho</span><div class="sec-line"></div></div>
          <div class="g3">
            <div class="card"><div class="card-head"><div class="card-title">Por Tipo</div><div class="card-sub">categorias de trabalho</div></div><div class="cw h200"><canvas id="tDevType"></canvas></div></div>
            <div class="card"><div class="card-head"><div class="card-title">Planejado vs Não Planejado</div><div class="card-sub">quanto foi surpresa</div></div><div class="cw h200"><canvas id="tDevPlanned"></canvas></div></div>
            <div class="card"><div class="card-head"><div class="card-title">Por Prioridade</div><div class="card-sub">nível de responsabilidade</div></div><div class="cw h200"><canvas id="tDevPriority"></canvas></div></div>
          </div>
        </div>
        <div class="sec"><span class="sec-label">itens do período</span><div class="sec-line"></div></div>
        <div class="tcard" id="dev-table-wrap"></div>
      </div>
    </div>

    <!-- RAW -->
    <div class="page" id="page-raw">
      <div class="tcard">
        <div class="tbar">
          <span class="tbar-title">Dado Bruto</span>
          <span class="tcnt" id="raw-cnt"></span>
          <div class="tfilters" id="raw-filters">
            <button class="tfbtn" id="rfbug" onclick="toggleRawFilter('bug')">🐛 Bug prod.</button>
            <button class="tfbtn" id="rfval" onclick="toggleRawFilter('val')">★ Novas func.</button>
  <button class="tfbtn" id="rfrework" onclick="toggleRawFilter('rework')">↩ Retrabalho</button>
            <button class="tfbtn" id="rfepic" onclick="toggleRawFilter('epic')">◈ Épico</button>
          </div>
        </div>
        <div class="tbar" style="border-top:none;padding-top:0;gap:8px;flex-wrap:wrap">
          <select id="rawSpSel" onchange="renderRaw()" style="font-size:12px;background:var(--bg);border:1px solid var(--border);border-radius:7px;padding:5px 9px;color:var(--text);outline:none;cursor:pointer"><option value="all">Todas as sprints</option></select>
          <select id="rawDevSel" onchange="renderRaw()" style="font-size:12px;background:var(--bg);border:1px solid var(--border);border-radius:7px;padding:5px 9px;color:var(--text);outline:none;cursor:pointer"><option value="all">Todos os devs</option></select>
          <select id="rawTipoSel" onchange="renderRaw()" style="font-size:12px;background:var(--bg);border:1px solid var(--border);border-radius:7px;padding:5px 9px;color:var(--text);outline:none;cursor:pointer"><option value="all">Todos os tipos</option></select>
          <input class="tsrch" type="text" id="rawSearch" placeholder="Buscar..." oninput="renderRaw()">
        </div>
        <div class="tscroll">
          <table>
            <thead><tr>
              <th onclick="sortRaw('Nome')">Item</th>
              <th onclick="sortRaw('Responsavel')">Responsável</th>
              <th onclick="sortRaw('Sprint')">Sprint</th>
              <th onclick="sortRaw('Status')">Status</th>
              <th onclick="sortRaw('Prioridade')">Prioridade</th>
              <th onclick="sortRaw('Tipo')">Tipo</th>
              <th onclick="sortRaw('Epico')">Épico</th>
              <th onclick="sortRaw('EstH')">Est(h)</th>
              <th onclick="sortRaw('RealH')">Real(h)</th>
              <th onclick="sortRaw('Planejada')">Planejado</th>
              <th onclick="sortRaw('Retrabalho')">Retrab.</th>
              <th onclick="sortRaw('BugProducao')">Bug Prod.</th>
              <th onclick="sortRaw('ValorAgregado')">Nova Func.</th>
            </tr></thead>
            <tbody id="raw-body"></tbody>
          </table>
        </div>
        <div class="tfoot">
          <span class="pgr" id="raw-pgr"></span>
          <div class="pbtns">
            <button class="pbtn" id="rawPrev" onclick="rawPg(-1)">← Anterior</button>
            <button class="pbtn" id="rawNext" onclick="rawPg(1)">Próximo →</button>
          </div>
        </div>
      </div>
    </div>

    @include('forecast')
    @include('consumption')
    @include('release')
    @include('control-tower')
    @include('users')

    <!-- SEM ACESSO -->
    <div class="page" id="page-no-access">
      <p class="empty-state">Você não tem acesso a esta tela. Use o menu lateral para abrir uma tela liberada ou peça acesso a quem administra os usuários.</p>
    </div>
    <!-- CAPACITY -->
    <div class="page" id="page-capacity">
      <header class="cap-hero">
        <div class="cap-heading"><img src="yuv-logo.png" alt="YUV"><span class="cap-divider"></span><div><h1>Capacidade e execução da sprint</h1><p>Planejado, suporte não planejado e entregas por desenvolvedor</p></div></div>
        <div class="cap-filters">
          <label>Sprint <select id="cap-sprint" aria-label="Selecionar sprint"></select></label>
          <span id="cap-dates">Datas não disponíveis</span>
        </div>
      </header>
      <div class="cap-info"><span class="cap-info-icon" aria-hidden="true">i</span><strong id="cap-reference"></strong><span id="cap-calendar"></span><label class="cap-dev-filter">Dev <select id="cap-developer" aria-label="Filtrar desenvolvedor"><option value="all">Todos</option></select></label><span id="cap-last-sync" role="status">Carregando dados...</span></div><p id="cap-pace" class="cap-pace" role="status"></p>
      <div id="cap-error" class="cap-message cap-error" role="status" hidden></div>
      <details id="cap-quality" class="cap-quality" hidden><summary id="cap-warning-summary"></summary><div id="cap-warning" class="cap-message cap-warning"></div></details>
      <div class="cap-table-scroll"><table class="cap-table"><thead><tr>
        <th>Desenvolvedor</th><th title="Edite a capacidade desta pessoa e sprint; o valor é salvo ao sair do campo">Disponíveis ✎</th><th title="Soma das estimativas dos itens planejados">Previstas</th><th title="Disponíveis − Previstas">Saldo previsto</th><th>Real planejado</th><th title="Tempo real dos itens não planejados: suporte + outros tipos">Real não planejado</th><th title="Real planejado + Real não planejado">Registradas</th><th title="Disponíveis − Registradas">Saldo atual</th><th title="Registradas ÷ capacidade transcorrida até hoje">Utilização até hoje</th><th title="Estimativas dos itens planejados concluídos ÷ todas as estimativas planejadas">Entrega do planejado</th><th title="Real suporte ÷ Registradas">Impacto do suporte</th>
      </tr></thead><tbody id="cap-body"><tr><td colspan="11" class="cap-empty">Carregando...</td></tr></tbody></table></div>
      <div class="cap-formulas"><div><span class="cap-formula-icon cap-orange" aria-hidden="true">▥</span><span><strong>Utilização até hoje</strong><small>Registradas ÷ capacidade até hoje</small></span></div><div><span class="cap-formula-icon cap-blue" aria-hidden="true">◎</span><span><strong>Entrega do planejado</strong><small>Estimativas concluídas ÷ estimativas planejadas</small></span></div><div><span class="cap-formula-icon cap-purple" aria-hidden="true">◔</span><span><strong>Impacto do suporte</strong><small>Real suporte ÷ Registradas</small></span></div></div>
      <p class="cap-note">Saldo atual inclui o período restante da sprint. Horas sem apontamento não indicam, por si só, ociosidade.</p>
      <p class="cap-note">Entrega do planejado considera as estimativas das tarefas concluídas, e não o tempo real gasto.</p>
      <section id="cap-pending" class="cap-extra" hidden></section>
      <section id="cap-unassigned" class="cap-extra" hidden></section>
    </div>

    <!-- PAINEL DO BOMBEIRO -->
    <div class="page" id="page-bombeiro">
      <div class="fire-hero">
        <div class="fire-brand"><img src="yuv-logo.png" alt="YUV"></div>
        <div class="fire-hero-copy"><div class="fire-eyebrow">PAINEL DO BOMBEIRO · MONDAY</div><h1>Sustentação</h1><p id="fire-context">Carregando quadro e sprint...</p></div>
        <div class="fire-hero-orb" aria-hidden="true"></div>
      </div>
      <div class="fire-toolbar">
        <label for="fire-sprint">Sprint <select id="fire-sprint" aria-label="Filtrar sprint do Painel do Bombeiro"></select></label>
        <div class="fire-toolbar-actions">
          <span id="fire-last-sync" role="status">Aguardando primeira consulta</span>
          <button type="button" id="fire-refresh">Atualizar agora</button>
          <button type="button" id="fire-audio-toggle" aria-pressed="true">Silenciar alertas</button>
          <button type="button" id="fire-audio-test" aria-label="Testar toque completo" title="Testar toque completo (aprox. 8 s)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M11 5 6 9H3v6h3l5 4V5Z"/><path d="M15 9a5 5 0 0 1 0 6"/><path d="M18 6a9 9 0 0 1 0 12"/></svg></button>
        </div>
      </div>
      <div id="fire-audio-state" class="fire-audio-state" role="status">Alertas sonoros ativados · clique ou toque na página para liberar o áudio</div>
      <div id="fire-error" class="fire-error" role="status" hidden></div>
      <div id="fire-alert" class="fire-alert" role="alert" hidden>
        <span class="fire-siren" aria-hidden="true">🚨</span>
        <div><strong id="fire-alert-title">Nova demanda de suporte</strong><div id="fire-alert-items"></div></div>
        <button type="button" id="fire-alert-close" aria-label="Fechar aviso">×</button>
      </div>
      <div class="fire-board" id="fire-board" aria-label="Kanban de demandas de suporte">
        <section class="fire-lane fire-lane-backlog" aria-labelledby="fire-backlog-title"><header><h2 id="fire-backlog-title">Backlog</h2><span id="fire-backlog-count">0</span></header><div class="fire-lane-list" id="fire-backlog"></div></section>
        <section class="fire-lane fire-lane-progress" aria-labelledby="fire-progress-title"><header><h2 id="fire-progress-title">Em andamento</h2><span id="fire-progress-count">0</span></header><div class="fire-lane-list" id="fire-progress"></div></section>
        <section class="fire-lane fire-lane-done" aria-labelledby="fire-done-title"><header><h2 id="fire-done-title">Concluído</h2><span id="fire-done-count">0</span></header><div class="fire-lane-list" id="fire-done"></div></section>
      </div>
    </div>

    <!-- PAINEL DA SPRINT -->
    <div class="page" id="page-sprint-panel">
      <div class="fire-hero">
        <div class="fire-brand"><img src="yuv-logo.png" alt="YUV"></div>
        <div class="fire-hero-copy"><div class="fire-eyebrow">ACOMPANHAMENTO DE DEMANDAS · MONDAY</div><h1 id="sp-title">Painel da Sprint</h1><p id="sp-context">Aguardando dados do Monday...</p></div>
        <div class="fire-hero-orb" aria-hidden="true"></div>
      </div>
      <div class="fire-toolbar">
        <label for="sp-sprint">Sprint <select id="sp-sprint" aria-label="Filtrar sprint do Painel da Sprint"></select></label>
        <div class="fire-toolbar-actions"><span id="sp-last-sync" role="status">Aguardando primeira consulta</span><button type="button" id="sp-refresh">Atualizar agora</button></div>
      </div>
      <div id="sp-error" class="fire-error" role="status" hidden></div>
      <div class="fire-board" id="sp-board" aria-label="Kanban de demandas da sprint que não são de suporte">
        <section class="fire-lane fire-lane-backlog" aria-labelledby="sp-backlog-title"><header><h2 id="sp-backlog-title">Backlog</h2><span id="sp-backlog-count">0</span></header><div class="fire-lane-list" id="sp-backlog"></div></section>
        <section class="fire-lane fire-lane-progress" aria-labelledby="sp-progress-title"><header><h2 id="sp-progress-title">Em andamento</h2><span id="sp-progress-count">0</span></header><div class="fire-lane-list" id="sp-progress"></div></section>
        <section class="fire-lane fire-lane-done" aria-labelledby="sp-done-title"><header><h2 id="sp-done-title">Concluído</h2><span id="sp-done-count">0</span></header><div class="fire-lane-list" id="sp-done"></div></section>
      </div>
      <div class="sprint-panel-note">Backlog inclui “Pronto pra iniciar” e “Pronto para começar” · “Implementado” equivale a concluído</div>
    </div>

    <!-- CONFIGURAÇÕES -->
    <div class="page" id="page-settings">
      <div class="sec"><span class="sec-label">integração com Monday</span><div class="sec-line"></div></div>
      <div class="settings-card">
        <h2>Conexão</h2>
        <p>Informe o ID do quadro e um token pessoal da API do Monday com acesso de leitura. O token é criptografado no banco de dados do servidor.</p>
        <div class="settings-grid">
          <label class="settings-field">ID do quadro<input id="monday-board" inputmode="numeric" autocomplete="off" placeholder="Ex.: 1234567890"></label>
          <label class="settings-field">Token da API<input id="monday-token" type="password" autocomplete="off" placeholder="Informe para cadastrar ou substituir"></label>
          <label class="settings-field">Atualização automática
            <select id="monday-refresh">
              <option value="0">Desligada (somente manual)</option>
              <option value="5" selected>A cada 5 minutos</option>
              <option value="10">A cada 10 minutos</option>
              <option value="15">A cada 15 minutos</option>
              <option value="30">A cada 30 minutos</option>
              <option value="60">A cada 60 minutos</option>
            </select>
          </label>
        </div>
        <label class="settings-field" id="monday-board-picker-wrap" style="display:none">Quadros disponíveis<select id="monday-board-picker" onchange="chooseMondayBoard()"></select></label>
        <div class="settings-actions">
          <button type="button" class="secondary" id="monday-boards" onclick="findMondayBoards()">Localizar quadros</button>
          <button type="button" class="secondary" id="monday-inspect" onclick="inspectMonday()">Buscar colunas</button>
          <button type="button" class="secondary" id="monday-save-refresh" onclick="saveMondayRefresh()">Salvar intervalo</button>
        </div>
        <div class="settings-message" id="monday-connection">Configure a integração com o Monday para carregar dados atuais.</div>
        <div class="settings-message" id="monday-refresh-status" aria-live="polite"></div>
      </div>
      <div class="settings-card" id="monday-mapping-card" style="display:none">
        <h2>Colunas do quadro</h2>
        <p>Confira a correspondência com os indicadores. A coluna Sprint é obrigatória. Subtarefas ficam fora da contagem de tarefas para evitar duplicações.</p>
        <div class="settings-grid" id="monday-mapping"></div>
        <div class="settings-actions">
          <button type="button" id="monday-save" onclick="saveMonday()">Salvar configuração</button>
          <button type="button" class="secondary" id="monday-sync" onclick="syncMonday()">Atualizar dados agora</button>
        </div>
        <div class="settings-message" id="monday-sync-status"></div>
      </div>
      <div class="settings-card">
        <h2>Regras dos indicadores</h2>
        <p>Valem para todas as telas. Concluído é somente "Implementado"; "Aguardando Deploy" e "QA - Aprovado" contam como feitos, não entregues; cancelados, descontinuados e despriorizados saem do total da sprint.</p>
        <div class="settings-grid">
          <label class="settings-field">Capacidade padrão por pessoa (horas por sprint)<input id="metric-capacity" type="number" min="0" max="200" step="0.5"></label>
          <label class="settings-field">Pessoas fora das métricas individuais (uma por linha, ex.: QA)<textarea id="metric-excluded" rows="3"></textarea></label>
        </div>
        <div class="settings-actions"><button type="button" id="metric-save" onclick="saveMetricSettings()">Salvar regras</button></div>
        <div class="settings-message" id="metric-status" aria-live="polite"></div>
      </div>
      <div class="settings-card">
        <h2>Como os dados aparecem</h2>
        <p>Todas as sprints vêm do Monday. O log de atividades do Monday é copiado para o banco local, preservando o histórico de status, sprint, estimativas e horas além do prazo de retenção do Monday.</p>
      </div>
    </div>

  </div>
</div>
<div id="tower-toast" role="status" aria-live="polite" hidden></div>
<div id="carousel-controls" role="toolbar" aria-label="Controles da apresentação" hidden>
  <span id="carousel-current">Sustentação · 1/2</span>
  <button type="button" id="carousel-prev" aria-label="Painel anterior" title="Painel anterior">‹</button>
  <button type="button" id="carousel-pause" aria-pressed="false">⏸ Pausar</button>
  <button type="button" id="carousel-next" aria-label="Próximo painel" title="Próximo painel">›</button>
  <label for="carousel-interval">Trocar a cada <select id="carousel-interval" aria-label="Intervalo de troca"><option value="15">15 s</option><option value="30" selected>30 s</option><option value="45">45 s</option><option value="60">60 s</option><option value="90">90 s</option></select></label>
  <button type="button" id="carousel-fullscreen" title="Abrir tela cheia">⛶ Tela cheia</button>
  <button type="button" id="carousel-exit">Sair</button>
  <span id="carousel-status" role="status">Troca automática a cada 30 segundos</span>
</div>
<script src="sprint-rules.js"></script>
<script>
const DATA=[];

// ── CONFIG ────────────────────────────────────────────────────────────────────
const SPRINTS=[];
const EPICOS_LIST=[];
const DEVS=[];
const PAL=['#1d6ef5','#7c3aed','#0d9488','#d97706','#dc2626','#059669','#9333ea','#0891b2','#be185d','#65a30d','#475569'];
const FONT={family:"'DM Sans',sans-serif",size:11};
const GRID='rgba(0,0,0,.05)';
const TK={color:'#94a3b8',font:FONT};

// ── UTILS ─────────────────────────────────────────────────────────────────────
const pct=(a,b)=>b===0?0:Math.round(a/b*100);
const f1=v=>parseFloat(v.toFixed(1));
const clean=v=>v==null?'':String(v).trim();
const esc=v=>clean(v).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
function filtSp(sp){return DATA.filter(x=>x.Sprint===sp);}
function devPeople(r){return clean(r.Responsavel).split(',').map(x=>x.trim()).filter(Boolean);}
/** Arrastado: o mesmo card (Id) já esteve em sprint anterior; cópias "(copy)" sem Id comum são reconhecidas pelo nome. */
function isArrastado(item){
  if(item.Id&&DATA.some(x=>x.Id===item.Id&&x.Sprint<item.Sprint)) return true;
  const base=item.Nome.replace(/\s*\(copy\)\s*/gi,'').trim().toLowerCase();
  if(base.length<8||!/\(copy\)/i.test(item.Nome)) return false;
  return DATA.some(x=>x.Sprint<item.Sprint && x.Nome.toLowerCase().includes(base));
}
function sprintRangeLabel(){return SPRINTS.length?`S${SPRINTS[0]}–S${SPRINTS.at(-1)}`:'';}
function toNum(v){if(!v) return 0; try{return parseFloat(String(v).replace(',','.'))||0;}catch{return 0;}}

// ── METRICS ───────────────────────────────────────────────────────────────────
/** Itens cancelados, descontinuados ou despriorizados saem do total e são contados como retirados do escopo. */
function spMetrics(sp){
  const all=filtSp(sp);
  const d=all.filter(x=>!SprintRules.isRemoved(x.Status));
  const removed=all.length-d.length;
  const tot=d.length;
  const done=d.filter(x=>SprintRules.isDone(x.Status)).length;
  const waiting=d.filter(x=>SprintRules.isDoneNotDelivered(x.Status)).length;
  const open=d.filter(x=>SprintRules.isOpen(x.Status)).length;
  const unplan=d.filter(x=>x.Planejada==='Não Planejado').length;
  const rework=d.filter(x=>x.Retrabalho).length;
  const bugs=d.filter(x=>x.BugProducao).length;
  const valor=d.filter(x=>x.ValorAgregado).length;
  const drag=d.filter(x=>isArrastado(x)).length;
  const withEst=d.filter(x=>x.EstH>0).length;
  const epicItems=d.filter(x=>x.Epico).length;
  const cleanScope=d.filter(x=>x.Planejada==='Planejada'&&SprintRules.isDone(x.Status)&&!x.Retrabalho).length;
  const unplanCrit=d.filter(x=>x.Planejada==='Não Planejado'&&x.Prioridade==='Crítica').length;
  const unplanBug=d.filter(x=>x.Planejada==='Não Planejado'&&x.Tipo==='Bug').length;
  const supportItems=d.filter(x=>SprintRules.normalize(x.Tipo)==='suporte');
  const support=supportItems.length;
  const supportDone=supportItems.filter(x=>SprintRules.isDone(x.Status)).length;
  // Planejados e não planejados da sprint excluem o suporte, como as origens da torre de controle.
  const sprintItems=d.filter(x=>SprintRules.normalize(x.Tipo)!=='suporte');
  const plannedItems=sprintItems.filter(x=>SprintRules.normalize(x.Planejada)==='planejada');
  const unplannedItems=sprintItems.filter(x=>SprintRules.normalize(x.Planejada)!=='planejada');
  const planned=plannedItems.length,plannedDone=plannedItems.filter(x=>SprintRules.isDone(x.Status)).length;
  const unplanned=unplannedItems.length,unplannedDone=unplannedItems.filter(x=>SprintRules.isDone(x.Status)).length;
  const unplannedCrit=unplannedItems.filter(x=>x.Prioridade==='Crítica').length;
  const unplannedBug=unplannedItems.filter(x=>x.Tipo==='Bug').length;
  // Tasks por indicador, listadas no modal ao clicar no card.
  const lists={
    tot:d,done:d.filter(x=>SprintRules.isDone(x.Status)),waiting:d.filter(x=>SprintRules.isDoneNotDelivered(x.Status)),
    open:d.filter(x=>SprintRules.isOpen(x.Status)),planned:plannedItems,unplanned:unplannedItems,support:supportItems,
    rework:d.filter(x=>x.Retrabalho),clean:d.filter(x=>x.Planejada==='Planejada'&&SprintRules.isDone(x.Status)&&!x.Retrabalho),
    drag:d.filter(x=>isArrastado(x)),noEst:d.filter(x=>!(x.EstH>0)),bugs:d.filter(x=>x.BugProducao),
    valor:d.filter(x=>x.ValorAgregado),epic:d.filter(x=>x.Epico),
  };
  return {lists,tot,done,waiting,open,removed,unplan,rework,bugs,valor,drag,withEst,epicItems,cleanScope,unplanCrit,unplanBug,support,supportDone,
    planned,plannedDone,unplanned,unplannedDone,unplannedCrit,unplannedBug,unplannedPct:pct(unplanned,tot),
    conclusao:pct(done,tot),reworkPct:pct(rework,tot),aderencia:pct(withEst,tot),
    arrastPct:pct(drag,tot),cleanPct:pct(cleanScope,tot),epicPct:pct(epicItems,tot),
    interruptPct:pct(unplan,tot)};
}
function precStats(rows){
  const both=rows.filter(x=>x.EstH>0&&x.RealH>0);
  if(!both.length) return {avg:null,label:'sem dados'};
  const avg=both.reduce((s,x)=>s+((x.RealH-x.EstH)/x.EstH*100),0)/both.length;
  return {avg,label:Math.abs(avg)<5?'preciso':avg>0?`subestimou ${f1(avg)}%`:`superestimou ${f1(Math.abs(avg))}%`};
}

// ── CHARTS ────────────────────────────────────────────────────────────────────
const charts={};
function mk(id,cfg){
  const el=document.getElementById(id);
  if(!el) return;
  if(charts[id]) charts[id].destroy();
  charts[id]=new Chart(el.getContext('2d'),cfg);
}
function bOpts(unit='',yMin=undefined){
  const o={responsive:true,maintainAspectRatio:false,
    plugins:{legend:{labels:{color:'#64748b',font:FONT,boxWidth:12}},tooltip:{callbacks:{label:c=>`${c.dataset.label||''}: ${c.parsed.y}${unit}`}}},
    scales:{x:{ticks:TK,grid:{color:GRID}},y:{ticks:{...TK,...(unit?{callback:v=>v+unit}:{})},grid:{color:GRID}}}};
  if(yMin!==undefined) o.scales.y.min=yMin;
  return o;
}
function donutOpts(){return{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'right',labels:{color:'#64748b',font:FONT,boxWidth:12}}}};}
function hbarOpts(unit=''){return{...bOpts(unit),indexAxis:'y',plugins:{legend:{labels:{color:'#64748b',font:FONT,boxWidth:12}}}};}

// ── BADGES ────────────────────────────────────────────────────────────────────
function stBadge(s){
  const m={'Implementado':'bd','Aguardando Deploy':'bw','QA - Testes':'bq','QA - Reprovado':'bs','QA - Aprovado':'bval','Em progresso':'bn','Pronto para começar':'bo','Parado':'bs','Próx. Sprint':'bo','Limitação Externa':'bs','Descontinuado':'bs','Despriorizado':'bo'};
  return `<span class="b ${m[s]||'bo'}">${esc(s)}</span>`;
}
function prioBadge(p){const m={'Crítica':'bs','Alta':'bn','Média':'bw','Baixa':'bo'};return `<span class="b ${m[p]||'bo'}">${esc(p)}</span>`;}
function epicBadge(e){return e?`<span class="b bep">${esc(e)}</span>`:'-';}
function boolBadge(v,yes='✓'){return v?`<span class="b bval">${yes}</span>`:`<span style="color:var(--muted);font-size:11px">—</span>`;}
function bugBadge(v){return v?`<span class="b bbug">🐛 bug</span>`:`<span style="color:var(--muted);font-size:11px">—</span>`;}

// ── TREND ─────────────────────────────────────────────────────────────────────
function trendHtml(cur,ref,higherIsBetter=true){
  if(ref===null||ref===undefined) return '';
  const diff=cur-ref;
  if(Math.abs(diff)<0.5) return `<span class="kpi-trend trend-neutral">→ estável</span>`;
  const good=(diff>0)===higherIsBetter;
  const arrow=diff>0?'↑':'↓';
  const cls=good?'trend-good':'trend-bad';
  const unit=typeof cur==='number'&&String(cur).includes('%')||typeof ref==='number'&&ref<=100&&cur<=100?'%':'';
  return `<span class="kpi-trend ${cls}">${arrow} ${f1(Math.abs(diff))}${unit}</span>`;
}

// ── NAVIGATION ────────────────────────────────────────────────────────────────
let currentPage='dashboard', currentSprint=null, timeInited=false;
/** Telas liberadas para o usuário logado; o servidor também bloqueia as APIs de cada tela. */
const ALLOWED_SCREENS=@json(auth()->user()->screenValues());
function canAccessScreen(page){return ALLOWED_SCREENS.includes(page);}
function navigate(page,sp=null){
  if(page!=='no-access'&&!canAccessScreen(page)){page=ALLOWED_SCREENS.find(p=>p!=='sprint')||'no-access';sp=null;}
  const previousPage=currentPage, previousSprint=currentSprint;
  if(previousPage==='bombeiro'&&page!=='bombeiro') window.Bombeiro?.leave();
  if(previousPage==='sprint-panel'&&page!=='sprint-panel') window.SprintPanel?.leave();
  if(previousPage==='carousel'&&page!=='carousel') window.PanelCarousel?.leave();
  if(previousPage==='capacity'&&page!=='capacity') window.Capacity?.leave();
  if(previousPage==='forecast'&&page!=='forecast') window.Forecast?.leave();
  if(previousPage==='consumption'&&page!=='consumption') window.Consumption?.leave();
  if(previousPage==='release'&&page!=='release') window.SprintRelease?.leave();
  if(previousPage==='control-tower'&&page!=='control-tower') window.ControlTower?.leave();
  if(previousPage==='users'&&page!=='users') window.Users?.leave();
  document.querySelectorAll('.page').forEach(e=>e.classList.remove('active'));
  currentPage=page; currentSprint=sp;
  setActiveNav(page==='sprint'?`nav-sprint-${sp}`:`nav-${page}`);
  document.getElementById('page-'+page)?.classList.add('active');
  const titles={dashboard:['Dashboard',SPRINTS.length?`visão geral histórica · sprints ${SPRINTS[0]}–${SPRINTS.at(-1)}`:'aguardando dados do Monday'],sprint:[`Sprint ${sp}`,`análise detalhada · comparação com sprint ${SPRINTS[SPRINTS.indexOf(sp)-1]||'—'}`],epicos:['Épicos','investimento e histórico por épico'],time:['Time','análise por desenvolvedor'],raw:['Dado Bruto','itens das sprints disponíveis'],bombeiro:['Painel do Bombeiro','sustentação · atualização a cada 60 segundos'],'sprint-panel':['Painel da Sprint','demandas da sprint · atualização a cada 60 segundos'],carousel:['Apresentação dos Painéis','alternância automática · Sustentação e Sprint'],capacity:['Capacity','capacidade e execução por sprint'],forecast:['Previsibilidade da sprint','demanda restante versus capacidade futura'],consumption:['Consumo de horas | Sprint e Suporte','Onde o esforço do time está sendo consumido'],release:['Release da sprint','Itens implementados, organizados automaticamente por Tipo'],settings:['Configurações','integração com Monday'],users:['Usuários','cadastro e permissões de acesso às telas'],'no-access':['Sem acesso','esta tela não está liberada para o seu usuário'],'control-tower':['Torre de controle','para onde a sprint está indo e quanto tempo resta para manobrar']};
  const [t,s]=titles[page]||[page,''];
  document.getElementById('tb-title').textContent=t;
  document.getElementById('tb-sub').textContent=s;
  document.getElementById('tb-right').textContent=sp?`S${sp}`:sprintRangeLabel();
  dismissSidebar();
  if(page==='dashboard') renderDashboard();
  else if(page==='sprint') renderSprint(sp);
  else if(page==='epicos') renderEpicos();
  else if(page==='time'){if(!timeInited)initTime();renderTime();}
  else if(page==='raw') renderRaw();
  else if(page==='bombeiro') window.Bombeiro?.enter(previousPage==='sprint'?previousSprint:null);
  else if(page==='sprint-panel') window.SprintPanel?.enter(previousPage==='sprint'?previousSprint:null);
  else if(page==='carousel') window.PanelCarousel?.enter(previousPage==='sprint-panel'?1:0,previousPage==='sprint'?previousSprint:null);
  else if(page==='capacity') window.Capacity?.enter(previousPage==='sprint'?previousSprint:null);
  else if(page==='forecast') window.Forecast?.enter(previousPage==='sprint'?previousSprint:null);
  else if(page==='consumption') window.Consumption?.enter(previousPage==='sprint'?previousSprint:null);
  else if(page==='release') window.SprintRelease?.enter(previousPage==='sprint'?previousSprint:null);
  else if(page==='control-tower') window.ControlTower?.enter(previousPage==='sprint'?previousSprint:null);
  else if(page==='users') window.Users?.enter();
  if(page==='carousel') scheduleMondayRefresh();
  if((previousPage==='bombeiro'&&page!=='bombeiro')||(previousPage==='sprint-panel'&&page!=='sprint-panel')||(previousPage==='carousel'&&page!=='carousel')) scheduleMondayRefresh();
}
function setActiveNav(id){
  document.querySelectorAll('.nav-item').forEach(e=>{e.classList.remove('active');e.removeAttribute('aria-current');});
  const ni=document.getElementById(id);
  if(ni){ni.classList.add('active');ni.setAttribute('aria-current','page');}
  document.getElementById('sprint-toggle').classList.toggle('active',id.startsWith('nav-sprint-'));
}
function setSprintsOpen(open){
  document.getElementById('sprint-children').classList.toggle('open',open);
  document.getElementById('sprint-arrow').classList.toggle('open',open);
  document.getElementById('sprint-toggle').setAttribute('aria-expanded',String(open));
}
function toggleSprints(){
  setSprintsOpen(!document.getElementById('sprint-children').classList.contains('open'));
}
function openSidebar(){document.getElementById('sidebar').classList.add('open');document.getElementById('overlay').classList.add('open');}
/** Fecha a gaveta (mobile) e recolhe o menu expandido (desktop) até o mouse sair dele. */
function dismissSidebar(){
  const sidebar=document.getElementById('sidebar');
  sidebar.classList.remove('open');
  document.getElementById('overlay').classList.remove('open');
  if(sidebar.matches(':hover,:has(:focus-visible)')) sidebar.classList.add('suppressed');
}
function initSprintNav(){
  const nav=document.getElementById('sprint-nav-items');
  nav.replaceChildren();
  SPRINTS.forEach(s=>{
    const el=document.createElement('button');
    el.type='button'; el.className='nav-item'; el.id=`nav-sprint-${s}`;
    el.innerHTML=`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg><span class="nav-label">Sprint ${s}</span>`;
    el.onclick=()=>navigate('sprint',s);
    if(currentPage==='sprint'&&currentSprint===s) el.classList.add('active');
    nav.appendChild(el);
  });
  // raw filters
  const rsp=document.getElementById('rawSpSel');
  rsp.length=1;
  SPRINTS.forEach(s=>{const o=document.createElement('option');o.value=s;o.textContent=`Sprint ${s}`;rsp.appendChild(o);});
  const rdev=document.getElementById('rawDevSel');
  rdev.length=1;
  [...DEVS,...SprintRules.settings.excludedPeople].forEach(d=>{const o=document.createElement('option');o.value=d;o.textContent=d.split(' ')[0]+' '+d.split(' ').slice(-1)[0];rdev.appendChild(o);});
  const rtipo=document.getElementById('rawTipoSel');
  rtipo.length=1;
  [...new Set(DATA.map(x=>x.Tipo))].filter(Boolean).forEach(t=>{const o=document.createElement('option');o.value=t;o.textContent=t;rtipo.appendChild(o);});
}

// ── DASHBOARD ─────────────────────────────────────────────────────────────────
function renderDashboard(){
  if(!SPRINTS.length){document.getElementById('dash-kpis').innerHTML='<p class="empty-state">Nenhuma sprint carregada ainda. Configure a integração com o Monday em Configurações ou aguarde a atualização dos dados.</p>';return;}
  const all=DATA.filter(x=>x.Sprint&&!SprintRules.isRemoved(x.Status));
  const allMs=SPRINTS.map(s=>spMetrics(s));
  function avg(fn){const vs=allMs.map(fn);return vs.reduce((a,b)=>a+b,0)/vs.length;}

  // Averages across all sprints
  const avgConclusion=Math.round(avg(m=>m.conclusao));
  const avgRework=Math.round(avg(m=>m.reworkPct));
  const avgClean=Math.round(avg(m=>m.cleanPct));
  const avgDrag=Math.round(avg(m=>m.arrastPct));
  const avgAdher=Math.round(avg(m=>m.aderencia));
  const avgEpicPct=Math.round(avg(m=>m.epicPct));
  const avgWaiting=Math.round(avg(m=>m.waiting));
  const avgOpen=Math.round(avg(m=>m.open));
  const avgUnplan=Math.round(avg(m=>m.unplan));
  const avgUnplanCrit=Math.round(avg(m=>m.unplanCrit));
  const avgUnplanBug=Math.round(avg(m=>m.unplanBug));
  const avgInterruptPct=Math.round(avg(m=>m.interruptPct));
  const avgWithEst=Math.round(avg(m=>m.withEst));
  const avgTot=Math.round(avg(m=>m.tot));
  const avgEpicItems=Math.round(avg(m=>m.epicItems));

  // Totals
  const totalBugs=all.filter(x=>x.BugProducao).length;
  const totalValor=all.filter(x=>x.ValorAgregado).length;
  const epicDone=DATA.filter(x=>x.Tipo==='Épico'&&SprintRules.isDone(x.Status)).length;
  const totalWaiting=all.filter(x=>SprintRules.isDoneNotDelivered(x.Status)).length;
  const totalOpen=all.filter(x=>SprintRules.isOpen(x.Status)).length;
  const totalUnplan=all.filter(x=>x.Planejada==='Não Planejado').length;
  const totalUnplanCrit=all.filter(x=>x.Planejada==='Não Planejado'&&x.Prioridade==='Crítica').length;
  const totalUnplanBug=all.filter(x=>x.Planejada==='Não Planejado'&&x.Tipo==='Bug').length;

  const grid=document.getElementById('dash-kpis');
  grid.innerHTML='';
  const kpis=[
    {lbl:'Total de Itens',val:all.length,sub:`${SPRINTS.length} sprints · média ${avgTot}/sprint`,cls:'neutral'},
    {lbl:'Taxa de Conclusão',val:`${avgConclusion}%`,sub:`média · meta >80%`,cls:avgConclusion>=80?'green':'red'},
    {lbl:'Feitos Não Entregues',val:totalWaiting,sub:`total · média ${avgWaiting}/sprint`,cls:avgWaiting<=2?'green':'yellow'},
    {lbl:'Não Entregues',val:totalOpen,sub:`total · média ${avgOpen}/sprint`,cls:avgOpen<=3?'green':'yellow'},
    {lbl:'Não Planejados',val:totalUnplan,sub:`total · ${totalUnplanCrit} críticos · ${totalUnplanBug} bugs`,cls:avgInterruptPct<=20?'green':'red'},
    {lbl:'Retrabalho',val:`${avgRework}%`,sub:`média · meta <15%`,cls:avgRework<=15?'green':'red'},
    {lbl:'Índice Escopo Limpo',val:`${avgClean}%`,sub:`média · meta >60%`,cls:avgClean>=60?'green':'red'},
    {lbl:'Taxa de Arrastamento',val:`${avgDrag}%`,sub:`média · meta <25%`,cls:avgDrag<=25?'green':'red'},
    {lbl:'Cobertura de Estimativa',val:`${avgAdher}%`,sub:`média · meta >90%`,cls:avgAdher>=90?'green':'red'},
    {lbl:'Carga de Épicos',val:`${avgEpicPct}%`,sub:`média · ${epicDone} épico(s) concluído(s)`,cls:'neutral'},
  ];
  kpis.forEach(k=>{
    const div=document.createElement('div');div.className=`kpi ${k.cls}`;
    div.innerHTML=`<div class="kpi-accent"></div><div class="kpi-label">${k.lbl}</div><div class="kpi-val">${k.val}</div><div class="kpi-sub">${k.sub}</div>`;
    grid.appendChild(div);
  });
  const L=SPRINTS.map(s=>`S${s}`);
  const spMs=SPRINTS.map(s=>spMetrics(s));
  // Charts entrega
  mk('cConclusion',{type:'line',data:{labels:L,datasets:[
    {label:'Conclusão (%)',data:spMs.map(m=>m.conclusao),borderColor:'#1d6ef5',backgroundColor:'rgba(29,110,245,.07)',fill:true,tension:.4,pointBackgroundColor:'#1d6ef5',pointBorderColor:'#fff',pointRadius:5,pointBorderWidth:2},
    {label:'Meta (80%)',data:SPRINTS.map(()=>80),borderColor:'#dc2626',borderDash:[5,4],borderWidth:1.5,pointRadius:0,fill:false},
  ]},options:{...bOpts('%'),scales:{...bOpts('%').scales,y:{...bOpts('%').scales.y,min:0,max:100}}}});
  mk('cVolume',{type:'bar',data:{labels:L,datasets:[
    {label:'Planejado',data:spMs.map(m=>m.tot-m.unplan),backgroundColor:'rgba(29,110,245,.7)',borderRadius:4,stack:'s'},
    {label:'Não Planejado',data:spMs.map(m=>m.unplan),backgroundColor:'rgba(220,38,38,.6)',borderRadius:4,stack:'s'},
  ]},options:{...bOpts(),scales:{...bOpts().scales,x:{...bOpts().scales.x,stacked:true},y:{...bOpts().scales.y,stacked:true}}}});
  mk('cDrag',{type:'bar',data:{labels:L,datasets:[
    {label:'Arrastamento (%)',data:spMs.map(m=>m.arrastPct),backgroundColor:spMs.map(m=>m.arrastPct>25?'rgba(220,38,38,.7)':'rgba(124,58,237,.6)'),borderRadius:4},
    {label:'Meta (25%)',data:SPRINTS.map(()=>25),type:'line',borderColor:'#d97706',borderDash:[5,4],borderWidth:1.5,pointRadius:0,fill:false},
  ]},options:{...bOpts('%')}});
  mk('cAdher',{type:'line',data:{labels:L,datasets:[
    {label:'Cobertura de estimativa (%)',data:spMs.map(m=>m.aderencia),borderColor:'#0d9488',backgroundColor:'rgba(13,148,136,.07)',fill:true,tension:.4,pointBackgroundColor:'#0d9488',pointBorderColor:'#fff',pointRadius:5,pointBorderWidth:2},
    {label:'Meta (90%)',data:SPRINTS.map(()=>90),borderColor:'#dc2626',borderDash:[5,4],borderWidth:1.5,pointRadius:0,fill:false},
  ]},options:{...bOpts('%'),scales:{...bOpts('%').scales,y:{...bOpts('%').scales.y,min:0,max:100}}}});
  // Charts qualidade
  mk('cRework',{type:'bar',data:{labels:L,datasets:[
    {label:'Retrabalho (%)',data:spMs.map(m=>m.reworkPct),backgroundColor:spMs.map(m=>m.reworkPct>15?'rgba(220,38,38,.7)':'rgba(124,58,237,.6)'),borderRadius:4},
    {label:'Meta (15%)',data:SPRINTS.map(()=>15),type:'line',borderColor:'#d97706',borderDash:[5,4],borderWidth:1.5,pointRadius:0,fill:false},
  ]},options:{...bOpts('%')}});
  mk('cBugs',{type:'bar',data:{labels:L,datasets:[
    {label:'Bugs em produção',data:SPRINTS.map(s=>filtSp(s).filter(x=>x.BugProducao).length),backgroundColor:SPRINTS.map(s=>filtSp(s).filter(x=>x.BugProducao).length>0?'rgba(220,38,38,.7)':'rgba(22,163,74,.5)'),borderRadius:4},
  ]},options:{...bOpts('',0)}});
  mk('cValor',{type:'bar',data:{labels:L,datasets:[
    {label:'Novas funcionalidades',data:SPRINTS.map(s=>filtSp(s).filter(x=>x.ValorAgregado).length),backgroundColor:'rgba(13,148,136,.7)',borderRadius:4},
  ]},options:{...bOpts('',0)}});
  mk('cClean',{type:'line',data:{labels:L,datasets:[
    {label:'Escopo limpo (%)',data:spMs.map(m=>m.cleanPct),borderColor:'#059669',backgroundColor:'rgba(5,150,105,.07)',fill:true,tension:.4,pointBackgroundColor:'#059669',pointBorderColor:'#fff',pointRadius:5,pointBorderWidth:2},
    {label:'Meta (60%)',data:SPRINTS.map(()=>60),borderColor:'#dc2626',borderDash:[5,4],borderWidth:1.5,pointRadius:0,fill:false},
  ]},options:{...bOpts('%'),scales:{...bOpts('%').scales,y:{...bOpts('%').scales.y,min:0,max:100}}}});
  mk('cEpicLoad',{type:'bar',data:{labels:L,datasets:[
    {label:'Tasks de épico (%)',data:spMs.map(m=>m.epicPct),backgroundColor:'rgba(217,119,6,.6)',borderRadius:4},
  ]},options:{...bOpts('%')}});
  // Composição
  const typeMap={};
  all.forEach(x=>{typeMap[x.Tipo]=(typeMap[x.Tipo]||0)+1;});
  const tEntries=Object.entries(typeMap).sort((a,b)=>b[1]-a[1]);
  mk('cType',{type:'doughnut',data:{labels:tEntries.map(x=>x[0]),datasets:[{data:tEntries.map(x=>x[1]),backgroundColor:PAL,borderWidth:2,borderColor:'#fff'}]},options:donutOpts()});
  const onlyBug=all.filter(x=>x.BugProducao&&!x.Retrabalho).length;
  const onlyRework=all.filter(x=>x.Retrabalho&&!x.BugProducao).length;
  const both=all.filter(x=>x.BugProducao&&x.Retrabalho).length;
  const cleanAll=all.filter(x=>!x.BugProducao&&!x.Retrabalho).length;
  mk('cQuality',{type:'doughnut',data:{labels:['Limpos','Retrabalho','Bug produção','Ambos'],datasets:[{data:[cleanAll,onlyRework,onlyBug,both],backgroundColor:['#059669','#d97706','#dc2626','#7c3aed'],borderWidth:2,borderColor:'#fff'}]},options:donutOpts()});
}

// ── SPRINT PAGE ───────────────────────────────────────────────────────────────
/**
 * O que cada indicador da sprint apresenta, como é calculado e quais tasks lista ao clicar.
 * Cancelado, Descontinuado e Despriorizado ficam fora de todos os indicadores.
 */
const SPRINT_KPI_INFO={
  'Total de Itens':{text:'Quantidade de cards da sprint, separada em planejados, não planejados e suporte.',criteria:'Todos os cards com esta sprint no Monday, exceto os retirados do escopo (Cancelado, Descontinuado e Despriorizado).',list:'tot'},
  'Taxa de Conclusão':{text:'Quanto da sprint já foi implementado.',criteria:'Cards com status Implementado ÷ total de itens. Aguardando Deploy e QA - Aprovado ainda não contam. Meta: acima de 80%.',list:'done',listTitle:'Tasks implementadas'},
  'Feitos Não Entregues':{text:'Cards prontos que ainda não chegaram em produção.',criteria:'Status Aguardando Deploy ou QA - Aprovado.',list:'waiting'},
  'Não Entregues':{text:'Cards que ainda exigem trabalho do time nesta sprint.',criteria:'Tudo que não está Implementado, Aguardando Deploy ou QA - Aprovado.',list:'open'},
  'Sprint Planejados':{text:'Trabalho que entrou no planejamento da sprint.',criteria:'Campo Planejada igual a "Planejada" e Tipo diferente de Suporte.',list:'planned'},
  'Sprint Não Planejados':{text:'Trabalho que entrou na sprint fora do planejamento.',criteria:'Tipo diferente de Suporte e campo Planejada diferente de "Planejada" (inclusive vazio). Verde até 20% do total de itens.',list:'unplanned'},
  'Tickets de Suporte':{text:'Demandas de suporte atendidas na sprint.',criteria:'Tipo igual a Suporte, independente do campo Planejada.',list:'support'},
  'Retrabalho':{text:'Cards que precisaram voltar para ajuste.',criteria:'Coluna Retrabalho marcada ÷ total de itens. Meta: abaixo de 15%.',list:'rework'},
  'Índice Escopo Limpo':{text:'Trabalho planejado entregue de primeira.',criteria:'Campo Planejada igual a "Planejada", status Implementado e sem Retrabalho, ÷ total de itens. Meta: acima de 60%.',list:'clean'},
  'Taxa de Arrastamento':{text:'Cards que vieram de sprints anteriores.',criteria:'O mesmo card (ou uma cópia "(copy)") já esteve em uma sprint anterior, ÷ total de itens. Meta: abaixo de 25%.',list:'drag'},
  'Cobertura de Estimativa':{text:'Cards com tempo estimado preenchido.',criteria:'Tempo estimado maior que zero ÷ total de itens. Meta: acima de 90%.',list:'noEst',listTitle:'Tasks sem estimativa'},
  'Bugs em Produção':{text:'Cards que geraram bug depois do deploy.',criteria:'Coluna Bug Produção marcada. Meta: zero.',list:'bugs'},
  'Novas Funcionalidades':{text:'Entregas que trazem funcionalidade nova.',criteria:'Coluna Novas Funcionalidades (antiga Valor Agregado) marcada.',list:'valor'},
  'Carga de Épicos':{text:'Quanto da sprint está ligado a épicos.',criteria:'Cards com o campo Épico preenchido ÷ total de itens.',list:'epic'},
};
function showKpiTip(card,info){
  const tip=document.getElementById('kpi-tip');
  tip.innerHTML=`<div>${esc(info.text)}</div><div class="kpi-tip-crit"><b>Critério:</b> ${esc(info.criteria)}</div><div class="kpi-tip-hint">Clique para ver as tasks</div>`;
  tip.hidden=false;
  const r=card.getBoundingClientRect(),w=tip.offsetWidth,h=tip.offsetHeight;
  const left=Math.max(8,Math.min(r.left+r.width/2-w/2,window.innerWidth-w-8));
  const top=r.bottom+8+h>window.innerHeight?r.top-h-8:r.bottom+8;
  tip.style.left=`${left}px`;tip.style.top=`${Math.max(8,top)}px`;
}
function hideKpiTip(){const tip=document.getElementById('kpi-tip');if(tip) tip.hidden=true;}
window.addEventListener('scroll',hideKpiTip,true);
function openKpiModal(label,info,items,sp){
  const modal=document.getElementById('kpi-modal');
  const title=info.listTitle||label;
  const name=x=>x.Url?`<a href="${esc(x.Url)}" target="_blank" rel="noopener">${esc(x.Nome)}</a>`:esc(x.Nome);
  modal.innerHTML=`<div class="kpi-modal-head">
      <div><div class="kpi-modal-title">${esc(title)} <span class="tcnt">${items.length}</span></div><div class="kpi-modal-sub">Sprint ${esc(sp)} · ${esc(info.criteria)}</div></div>
      <button type="button" class="kpi-modal-close" aria-label="Fechar" onclick="document.getElementById('kpi-modal').close()">✕</button>
    </div>
    <div class="tscroll kpi-modal-body"><table><thead><tr>
      <th>Item</th><th>Responsável</th><th>Status</th><th>Tipo</th><th>Planejada</th><th>Prioridade</th><th>Est(h)</th><th>Real(h)</th>
    </tr></thead><tbody>${items.map(x=>`<tr>
      <td title="${esc(x.Nome)}">${name(x)}</td>
      <td class="cm">${esc(x.Responsavel)||'—'}</td>
      <td>${stBadge(x.Status)}</td>
      <td><span class="b bo">${esc(x.Tipo)||'—'}</span></td>
      <td class="cm">${esc(x.Planejada)||'—'}</td>
      <td>${prioBadge(x.Prioridade)}</td>
      <td class="cm">${x.EstH||'—'}</td>
      <td class="cm">${x.RealH||'—'}</td>
    </tr>`).join('')||'<tr><td colspan="8" class="nd">Nenhuma task neste indicador</td></tr>'}</tbody></table></div>`;
  modal.showModal();
}
function renderSprint(sp){
  const d=filtSp(sp);
  const m=spMetrics(sp);
  const prevSp=SPRINTS[SPRINTS.indexOf(sp)-1]||null;
  const prevM=prevSp?spMetrics(prevSp):null;
  const epicDone=d.filter(x=>x.Tipo==='Épico'&&SprintRules.isDone(x.Status));
  const grid=document.getElementById('sprint-kpis');
  grid.innerHTML='';
  const kpis=[
    {lbl:'Total de Itens',val:m.tot,sub:`${m.planned} planejados · ${m.unplanned} não planejados · ${m.support} suporte · ${m.removed} retirados do escopo`,cls:'neutral',trend:null},
    {lbl:'Taxa de Conclusão',val:`${m.conclusao}%`,sub:`${m.done}/${m.tot} · meta >80%`,cls:m.conclusao>=80?'green':'red',trend:prevM?trendHtml(m.conclusao,prevM.conclusao,true):null,meta:prevSp?`vs sprint ${prevSp}`:null},
    {lbl:'Feitos Não Entregues',val:m.waiting,sub:`Aguardando Deploy ou QA aprovado`,cls:prevM?(m.waiting<=prevM.waiting?'green':'red'):'neutral',trend:prevM?trendHtml(m.waiting,prevM.waiting,false):null,meta:prevSp?`vs sprint ${prevSp}`:null},
    {lbl:'Não Entregues',val:m.open,sub:`itens em aberto`,cls:prevM?(m.open<=prevM.open?'green':'red'):'neutral',trend:prevM?trendHtml(m.open,prevM.open,false):null,meta:prevSp?`vs sprint ${prevSp}`:null},
    {lbl:'Sprint Planejados',val:m.planned,sub:`${m.plannedDone}/${m.planned} implementados · sem suporte`,cls:'neutral',trend:prevM?trendHtml(m.planned,prevM.planned,true):null,meta:prevSp?`vs sprint ${prevSp}`:null},
    {lbl:'Sprint Não Planejados',val:m.unplanned,sub:`${m.unplannedDone}/${m.unplanned} implementados · ${m.unplannedCrit} críticos · ${m.unplannedBug} bugs · sem suporte`,cls:m.unplannedPct<=20?'green':'red',trend:prevM?trendHtml(m.unplanned,prevM.unplanned,false):null,meta:prevSp?`vs sprint ${prevSp}`:null},
    {lbl:'Tickets de Suporte',val:m.support,sub:`${m.supportDone}/${m.support} implementados`,cls:'neutral',trend:prevM?trendHtml(m.support,prevM.support,false):null,meta:prevSp?`vs sprint ${prevSp}`:null},
    {lbl:'Retrabalho',val:`${m.reworkPct}%`,sub:`${m.rework} itens · meta <15%`,cls:m.reworkPct<=15?'green':'red',trend:prevM?trendHtml(m.reworkPct,prevM.reworkPct,false):null,meta:prevSp?`vs sprint ${prevSp}`:null},
    {lbl:'Índice Escopo Limpo',val:`${m.cleanPct}%`,sub:`${m.cleanScope} itens · meta >60%`,cls:m.cleanPct>=60?'green':'red',trend:prevM?trendHtml(m.cleanPct,prevM.cleanPct,true):null,meta:prevSp?`vs sprint ${prevSp}`:null},
    {lbl:'Taxa de Arrastamento',val:`${m.arrastPct}%`,sub:`${m.drag} itens · meta <25%`,cls:m.arrastPct<=25?'green':'red',trend:prevM?trendHtml(m.arrastPct,prevM.arrastPct,false):null,meta:prevSp?`vs sprint ${prevSp}`:null},
    {lbl:'Cobertura de Estimativa',val:`${m.aderencia}%`,sub:`${m.withEst}/${m.tot} itens com estimativa · meta >90%`,cls:m.aderencia>=90?'green':'red',trend:prevM?trendHtml(m.aderencia,prevM.aderencia,true):null,meta:prevSp?`vs sprint ${prevSp}`:null},
    {lbl:'Bugs em Produção',val:m.bugs,sub:m.bugs===0?'nenhum bug registrado':'itens com bug após deploy',cls:m.bugs===0?'green':'red',trend:prevM?trendHtml(m.bugs,prevM.bugs,false):null,meta:prevSp?`vs sprint ${prevSp}`:null},
    {lbl:'Novas Funcionalidades',val:m.valor,sub:`itens de nova funcionalidade`,cls:'blue',trend:prevM?trendHtml(m.valor,prevM.valor,true):null,meta:prevSp?`vs sprint ${prevSp}`:null},
    {lbl:'Carga de Épicos',val:`${m.epicPct}%`,sub:`${m.epicItems} tasks${epicDone.length?` · ${epicDone.length} épico(s) concluído(s)`:''}`,cls:'neutral',trend:prevM?trendHtml(m.epicPct,prevM.epicPct,false):null,meta:prevSp?`vs sprint ${prevSp}`:null},
  ];
  kpis.forEach(k=>{
    const info=SPRINT_KPI_INFO[k.lbl];
    const div=document.createElement('div');div.className=`kpi ${k.cls}${info?' kpi-clickable':''}`;
    const valFs=String(k.val).length>8?'font-size:18px':'';
    div.innerHTML=`<div class="kpi-accent"></div><div class="kpi-label">${k.lbl}</div><div class="kpi-val" style="${valFs}">${k.val}</div><div class="kpi-sub">${k.sub}</div>${k.trend||''}${k.meta&&k.trend?`<div class="kpi-meta">${k.meta}</div>`:''}`;
    if(info){
      const open=()=>{hideKpiTip();openKpiModal(k.lbl,info,m.lists[info.list]||[],sp);};
      div.tabIndex=0;div.setAttribute('role','button');div.setAttribute('aria-label',`${k.lbl}: ${info.text} Clique para ver as tasks.`);
      div.addEventListener('mouseenter',()=>showKpiTip(div,info));
      div.addEventListener('mouseleave',hideKpiTip);
      div.addEventListener('click',open);
      div.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();open();}});
    }
    grid.appendChild(div);
  });
  // Charts composição
  const stMap={};d.forEach(x=>{stMap[x.Status]=(stMap[x.Status]||0)+1;});
  const stColors={'Implementado':'#059669','Aguardando Deploy':'#1d6ef5','QA - Testes':'#7c3aed','QA - Reprovado':'#dc2626','Em progresso':'#d97706','Pronto para começar':'#94a3b8','Parado':'#dc2626','Próx. Sprint':'#64748b','Limitação Externa':'#dc2626','Descontinuado':'#ef4444'};
  const stE=Object.entries(stMap).sort((a,b)=>b[1]-a[1]);
  mk('sStatus',{type:'doughnut',data:{labels:stE.map(x=>x[0]),datasets:[{data:stE.map(x=>x[1]),backgroundColor:stE.map(x=>stColors[x[0]]||'#94a3b8'),borderWidth:2,borderColor:'#fff'}]},options:donutOpts()});
  const tpMap={};d.forEach(x=>{tpMap[x.Tipo]=(tpMap[x.Tipo]||0)+1;});
  const tpE=Object.entries(tpMap).sort((a,b)=>b[1]-a[1]);
  mk('sType',{type:'doughnut',data:{labels:tpE.map(x=>x[0]),datasets:[{data:tpE.map(x=>x[1]),backgroundColor:PAL,borderWidth:2,borderColor:'#fff'}]},options:donutOpts()});
  const prMap={};d.forEach(x=>{prMap[x.Prioridade]=(prMap[x.Prioridade]||0)+1;});
  const prOrd=['Crítica','Alta','Média','Baixa'];
  const prCol={'Crítica':'#dc2626','Alta':'#f97316','Média':'#1d6ef5','Baixa':'#94a3b8'};
  const prE=prOrd.filter(p=>prMap[p]).map(p=>[p,prMap[p]]);
  mk('sPriority',{type:'doughnut',data:{labels:prE.map(x=>x[0]),datasets:[{data:prE.map(x=>x[1]),backgroundColor:prE.map(x=>prCol[x[0]]),borderWidth:2,borderColor:'#fff'}]},options:donutOpts()});
  const planCount=d.filter(x=>x.Planejada==='Planejada').length;
  const nplanCount=d.filter(x=>x.Planejada==='Não Planejado').length;
  mk('sPlanned',{type:'doughnut',data:{labels:['Planejado','Não Planejado'],datasets:[{data:[planCount,nplanCount],backgroundColor:['#1d6ef5','#dc2626'],borderWidth:2,borderColor:'#fff'}]},options:donutOpts()});
  // Épicos
  const epicMap={};
  d.filter(x=>x.Epico).forEach(x=>{epicMap[x.Epico]=(epicMap[x.Epico]||0)+1;});
  const eE=Object.entries(epicMap).sort((a,b)=>b[1]-a[1]);
  if(eE.length){
    mk('sEpicos',{type:'bar',data:{labels:eE.map(x=>x[0]),datasets:[{label:'Tasks',data:eE.map(x=>x[1]),backgroundColor:'rgba(217,119,6,.7)',borderRadius:4}]},options:{...hbarOpts(),plugins:{legend:{display:false}}}});
  } else {
    document.getElementById('sEpicos')?.parentElement?.parentElement?.classList.add('nd');
    document.getElementById('sEpicos')?.parentElement?.parentElement?.querySelector('.card-head')?.remove();
    if(document.getElementById('sEpicos')) document.getElementById('sEpicos').parentElement.innerHTML='<div class="nd">Nenhum item de épico nesta sprint</div>';
  }
  // Devs
  const devMap={};
  const devDone={};
  d.forEach(x=>{
    devPeople(x).filter(p=>!SprintRules.isExcludedPerson(p)).forEach(p=>{
      devMap[p]=(devMap[p]||0)+1;
      if(SprintRules.isDone(x.Status)) devDone[p]=(devDone[p]||0)+1;
    });
  });
  const devNames=Object.keys(devMap).sort((a,b)=>devMap[b]-devMap[a]);
  mk('sDev',{type:'bar',data:{labels:devNames.map(n=>n.split(' ')[0]),datasets:[
    {label:'Total',data:devNames.map(n=>devMap[n]),backgroundColor:'rgba(29,110,245,.3)',borderRadius:3},
    {label:'Implementados',data:devNames.map(n=>devDone[n]||0),backgroundColor:'rgba(5,150,105,.7)',borderRadius:3},
  ]},options:{...bOpts(),plugins:{legend:{labels:{color:'#64748b',font:FONT,boxWidth:12}}}}});
  renderSprintTable(d);
}
function renderSprintTable(d){
  let activeFilters=new Set();
  const wrap=document.getElementById('sprint-table-wrap');
  wrap.innerHTML=`<div class="tbar">
    <span class="tbar-title">Itens</span><span class="tcnt">${d.length}</span>
    <div class="tfilters">
      <button class="tfbtn" id="sf-val" onclick="spFilterToggle('val')">★ Novas func.</button>
      <button class="tfbtn" id="sf-epic" onclick="spFilterToggle('epic')">◈ Épico</button>
      <button class="tfbtn" id="sf-bug" onclick="spFilterToggle('bug')">🐛 Bug prod.</button>
    </div>
    <input class="tsrch" type="text" id="sp-search" placeholder="Buscar..." oninput="spFilterRender()" style="margin-left:auto">
  </div>
  <div class="tscroll"><table><thead><tr>
    <th>Item</th><th>Responsável</th><th>Status</th><th>Tipo</th><th>Prioridade</th><th>Épico</th><th>Est(h)</th><th>Real(h)</th><th>Retrab.</th><th>Bug Prod.</th><th>Nova Func.</th>
  </tr></thead><tbody id="sp-body"></tbody></table></div>`;
  window._spItems=d;
  window._spFilters=new Set();
  window.spFilterToggle=function(f){
    if(window._spFilters.has(f)) window._spFilters.delete(f); else window._spFilters.add(f);
    document.getElementById('sf-'+f)?.classList.toggle('active',window._spFilters.has(f));
    spFilterRender();
  };
  spFilterRender();
}
function spFilterRender(){
  const q=(document.getElementById('sp-search')?.value||'').toLowerCase();
  const f=window._spFilters||new Set();
  let rows=window._spItems||[];
  if(f.has('val')) rows=rows.filter(x=>x.ValorAgregado);
  if(f.has('epic')) rows=rows.filter(x=>x.Epico);
  if(f.has('bug')) rows=rows.filter(x=>x.BugProducao);
  if(q) rows=rows.filter(x=>x.Nome.toLowerCase().includes(q)||x.Responsavel.toLowerCase().includes(q));
  const tbody=document.getElementById('sp-body');
  if(!tbody) return;
  tbody.innerHTML=rows.map(x=>`<tr>
    <td title="${esc(x.Nome)}">${esc(x.Nome)}</td>
    <td class="cm">${esc(devPeople(x).filter(p=>!SprintRules.isExcludedPerson(p))[0]?.split(' ')[0]||x.Responsavel.split(' ')[0])}</td>
    <td>${stBadge(x.Status)}</td>
    <td><span class="b bo">${esc(x.Tipo)}</span></td>
    <td>${prioBadge(x.Prioridade)}</td>
    <td>${epicBadge(x.Epico)}</td>
    <td class="cm">${x.EstH||'—'}</td>
    <td class="cm">${x.RealH||'—'}</td>
    <td>${boolBadge(x.Retrabalho)}</td>
    <td>${bugBadge(x.BugProducao)}</td>
    <td>${boolBadge(x.ValorAgregado,'★')}</td>
  </tr>`).join('')||'<tr><td colspan="11" class="nd">Nenhum item</td></tr>';
}

// ── ÉPICOS PAGE ───────────────────────────────────────────────────────────────
function renderEpicos(){
  const epicData=EPICOS_LIST.map(ep=>{
    const items=DATA.filter(x=>x.Epico===ep);
    const done=DATA.filter(x=>x.Tipo==='Épico'&&SprintRules.isDone(x.Status)&&x.Epico===ep);
    const hours=items.reduce((s,x)=>s+(x.RealH||0),0);
    const sprints=[...new Set(items.map(x=>x.Sprint))].filter(Boolean).sort();
    return {ep,items,done:done.length,hours:f1(hours),sprints,tot:items.length};
  }).filter(x=>x.tot>0);
  const grid=document.getElementById('epicos-cards');
  grid.innerHTML='';
  epicData.forEach(e=>{
    const div=document.createElement('div');div.className='epic-card';
    div.innerHTML=`<div class="epic-name">${esc(e.ep)}<span class="epic-status ${e.done?'epic-done':'epic-wip'}">${e.done?'Concluído':'Em andamento'}</span></div>
      <div class="epic-stats">
        <div class="epic-stat"><div class="epic-stat-val">${e.tot}</div><div class="epic-stat-lbl">tasks</div></div>
        <div class="epic-stat"><div class="epic-stat-val">${e.hours}h</div><div class="epic-stat-lbl">horas investidas</div></div>
        <div class="epic-stat" style="grid-column:1/-1"><div class="epic-stat-val" style="font-size:13px;font-weight:500">S${e.sprints.join(' · S')}</div><div class="epic-stat-lbl">sprints envolvidas</div></div>
      </div>`;
    grid.appendChild(div);
  });
  // Charts
  mk('eHours',{type:'bar',data:{labels:epicData.map(x=>x.ep),datasets:[{label:'Horas reais',data:epicData.map(x=>x.hours),backgroundColor:PAL.slice(0,epicData.length).map(c=>c+'bb'),borderRadius:4}]},options:{...hbarOpts('h'),plugins:{legend:{display:false}}}});
  mk('ePerSprint',{type:'bar',data:{
    labels:SPRINTS.map(s=>`S${s}`),
    datasets:epicData.map((e,i)=>({label:e.ep,data:SPRINTS.map(s=>{const tot=filtSp(s).length;const ep=DATA.filter(x=>x.Sprint===s&&x.Epico===e.ep).length;return tot>0?Math.round(ep/tot*100):0;}),backgroundColor:PAL[i%PAL.length]+'99',borderRadius:3,stack:'s'}))
  },options:{...bOpts('%'),scales:{...bOpts('%').scales,x:{...bOpts('%').scales.x,stacked:true},y:{...bOpts('%').scales.y,stacked:true,max:100}},plugins:{legend:{labels:{color:'#64748b',font:FONT,boxWidth:12}}}}});
  // Table
  const allEp=DATA.filter(x=>x.Epico).sort((a,b)=>a.Sprint-b.Sprint);
  let activeEpic='all';
  const wrap=document.getElementById('epicos-table-wrap');
  wrap.innerHTML=`<div class="tbar">
    <span class="tbar-title">Itens de Épico</span><span class="tcnt" id="ep-cnt">${allEp.length}</span>
    <div class="tfilters">
      <button class="tfbtn active" onclick="epFilterSet('all',this)">Todos</button>
      ${epicData.map((e,i)=>`<button class="tfbtn" data-ep-index="${i}">${esc(e.ep)}</button>`).join('')}
    </div>
  </div>
  <div class="tscroll"><table><thead><tr>
    <th>Item</th><th>Épico</th><th>Sprint</th><th>Status</th><th>Tipo</th><th>Responsável</th><th>Est(h)</th><th>Real(h)</th><th>Retrab.</th><th>Bug Prod.</th>
  </tr></thead><tbody id="ep-body"></tbody></table></div>`;
  window._epItems=allEp;
  document.querySelectorAll('#epicos-table-wrap [data-ep-index]').forEach(btn=>{
    btn.addEventListener('click',()=>window.epFilterSet(epicData[Number(btn.dataset.epIndex)].ep,btn));
  });
  window.epFilterSet=function(val,btn){
    window.activeEpic=val;
    document.querySelectorAll('#epicos-table-wrap .tfbtn').forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');
    epRender();
  };
  epRender();
}
function epRender(){
  const items=window._epItems.filter(x=>window.activeEpic==='all'||x.Epico===window.activeEpic);
  document.getElementById('ep-cnt').textContent=items.length;
  document.getElementById('ep-body').innerHTML=items.map(x=>`<tr>
    <td title="${esc(x.Nome)}">${esc(x.Nome)}</td>
    <td>${epicBadge(x.Epico)}</td>
    <td class="cm">Sprint ${x.Sprint}</td>
    <td>${stBadge(x.Status)}</td>
    <td><span class="b bo">${esc(x.Tipo)}</span></td>
    <td class="cm">${esc(devPeople(x).filter(p=>!SprintRules.isExcludedPerson(p))[0]?.split(' ')[0]||'—')}</td>
    <td class="cm">${x.EstH||'—'}</td>
    <td class="cm">${x.RealH||'—'}</td>
    <td>${boolBadge(x.Retrabalho)}</td>
    <td>${bugBadge(x.BugProducao)}</td>
  </tr>`).join('')||'<tr><td colspan="10" class="nd">Nenhum item</td></tr>';
}
// expose for onclick
window.activeEpic='all';

// ── TIME PAGE ─────────────────────────────────────────────────────────────────
function initTime(){
  timeInited=true;
  const devSel=document.getElementById('timeDevSel');
  DEVS.forEach(d=>{const o=document.createElement('option');o.value=d;o.textContent=d.split(' ')[0]+' '+d.split(' ').slice(-1)[0];devSel.appendChild(o);});
  const spSel=document.getElementById('timeSpSel');
  SPRINTS.forEach(s=>{const o=document.createElement('option');o.value=s;o.textContent=`Sprint ${s}`;spSel.appendChild(o);});
}
function getTimeFilters(){
  return{dev:document.getElementById('timeDevSel').value,sp:document.getElementById('timeSpSel').value};
}
function clearTimeFilters(){
  document.getElementById('timeDevSel').value='all';
  document.getElementById('timeSpSel').value='all';
  renderTime();
}
function renderTime(){
  const{dev,sp}=getTimeFilters();
  document.getElementById('timeClear').style.display=(dev!=='all'||sp!=='all')?'flex':'none';
  let rows=DATA.filter(x=>x.Sprint&&!devPeople(x).every(p=>SprintRules.isExcludedPerson(p)));
  if(sp!=='all') rows=rows.filter(x=>x.Sprint===parseInt(sp));
  if(dev!=='all'){
    document.getElementById('time-overview').style.display='none';
    document.getElementById('time-individual').style.display='';
    renderTimeIndividual(dev,rows,sp);
  } else {
    document.getElementById('time-overview').style.display='';
    document.getElementById('time-individual').style.display='none';
    renderTimeOverview(rows);
  }
}
function devStats(dev,rows){
  const items=rows.filter(x=>devPeople(x).filter(p=>!SprintRules.isExcludedPerson(p)).includes(dev));
  const tot=items.length;
  const done=items.filter(x=>SprintRules.isDone(x.Status)).length;
  const rework=items.filter(x=>x.Retrabalho).length;
  const bugs=items.filter(x=>x.BugProducao).length;
  const valor=items.filter(x=>x.ValorAgregado).length;
  const open=items.filter(x=>SprintRules.isOpen(x.Status)).length;
  const withEst=items.filter(x=>x.EstH>0).length;
  const epicItems=items.filter(x=>x.Epico).length;
  const epicDone=items.filter(x=>x.Tipo==='Épico'&&SprintRules.isDone(x.Status)).length;
  let allocH=0;
  items.forEach(x=>{
    if(!x.RealH) return;
    const devs=devPeople(x).filter(p=>!SprintRules.isExcludedPerson(p));
    if(devs.includes(dev)) allocH+=x.RealH/devs.length;
  });
  const ps=precStats(items);
  return{tot,done,rework,bugs,valor,open,withEst,epicItems,epicDone,allocH:f1(allocH),
    conclusao:pct(done,tot),reworkPct:pct(rework,tot),aderencia:pct(withEst,tot),
    netPrec:ps.avg,precLabel:ps.label};
}
function renderTimeOverview(rows){
  const stats=DEVS.filter(d=>rows.filter(x=>devPeople(x).filter(p=>!SprintRules.isExcludedPerson(p)).includes(d)).length>0).map(d=>({d,...devStats(d,rows)}));
  const labels=stats.map(x=>x.d.split(' ')[0]);
  mk('tDevItems',{type:'bar',data:{labels,datasets:[
    {label:'Total',data:stats.map(x=>x.tot),backgroundColor:'rgba(29,110,245,.3)',borderRadius:3},
    {label:'Implementados',data:stats.map(x=>x.done),backgroundColor:'rgba(5,150,105,.7)',borderRadius:3},
  ]},options:{...bOpts(),plugins:{legend:{labels:{color:'#64748b',font:FONT,boxWidth:12}}}}});
  mk('tDevRework',{type:'bar',data:{labels,datasets:[
    {label:'Retrabalho (%)',data:stats.map(x=>x.reworkPct),backgroundColor:stats.map(x=>x.reworkPct>15?'rgba(220,38,38,.7)':'rgba(124,58,237,.6)'),borderRadius:3},
    {label:'Meta (15%)',data:stats.map(()=>15),type:'line',borderColor:'#d97706',borderDash:[5,4],borderWidth:1.5,pointRadius:0,fill:false},
  ]},options:{...bOpts('%')}});
  mk('tDevAdher',{type:'bar',data:{labels,datasets:[
    {label:'Cobertura de estimativa (%)',data:stats.map(x=>x.aderencia),backgroundColor:'rgba(13,148,136,.7)',borderRadius:3},
    {label:'Meta (90%)',data:stats.map(()=>90),type:'line',borderColor:'#dc2626',borderDash:[5,4],borderWidth:1.5,pointRadius:0,fill:false},
  ]},options:{...bOpts('%')}});
  const tipos=[...new Set(rows.map(x=>x.Tipo))].filter(Boolean);
  mk('tDevProfile',{type:'bar',data:{labels,datasets:tipos.map((t,i)=>({label:t,data:stats.map(st=>rows.filter(x=>devPeople(x).filter(p=>!SprintRules.isExcludedPerson(p)).includes(st.d)&&x.Tipo===t).length),backgroundColor:PAL[i%PAL.length]+'99',borderRadius:2,stack:'s'}))},options:{...bOpts(),scales:{...bOpts().scales,x:{...bOpts().scales.x,stacked:true},y:{...bOpts().scales.y,stacked:true}},plugins:{legend:{labels:{color:'#64748b',font:FONT,boxWidth:12}}}}});
  const hDatasets=DEVS.filter(d=>stats.find(s=>s.d===d)).map((d,i)=>({label:d.split(' ')[0],data:SPRINTS.map(s=>devStats(d,DATA.filter(x=>x.Sprint===s)).allocH),backgroundColor:PAL[i%PAL.length]+'99',borderRadius:3}));
  mk('tDevHours',{type:'bar',data:{labels:SPRINTS.map(s=>`S${s}`),datasets:hDatasets},options:{...bOpts('h'),plugins:{legend:{labels:{color:'#64748b',font:FONT,boxWidth:12}}}}});
}
function renderTimeIndividual(dev,rows,spFilter){
  const st=devStats(dev,rows);
  const spRows=SPRINTS.map(s=>({s,...devStats(dev,DATA.filter(x=>x.Sprint===s))}));
  const totalItems=rows.length;
  const pctOfTeam=pct(st.tot,totalItems);
  const sprintCount=spFilter==='all'?SPRINTS.length:1;
  const cap=SprintRules.settings.capacityHours*sprintCount;
  const ocup=pct(st.allocH,cap);
  const gap=f1(cap-st.allocH);
  const initials=dev.split(' ').filter((_,i,a)=>i===0||i===a.length-1).map(x=>x[0]).join('');
  document.getElementById('dev-header').innerHTML=`<div class="dev-av">${esc(initials)}</div><div class="dev-info"><div class="dev-name">${esc(dev)}</div><div class="dev-meta">${spFilter==='all'?'Todas as sprints':`Sprint ${esc(spFilter)}`} · ${st.tot} itens</div></div>`;
  // individual KPIs
  const grid=document.getElementById('dev-kpis');grid.innerHTML='';
  const kpis=[
    {lbl:'Total de Itens',val:st.tot,sub:`${pctOfTeam}% do total do time`,cls:'blue'},
    {lbl:'Taxa de Conclusão',val:`${st.conclusao}%`,sub:`${st.done}/${st.tot} implementados`,cls:st.conclusao>=80?'green':'red'},
    {lbl:'Não Entregues',val:st.open,sub:`itens em aberto`,cls:st.open===0?'green':'yellow'},
    {lbl:'Épicos',val:st.epicItems,sub:`tasks de épico${st.epicDone?` · ${st.epicDone} concluído(s)`:''}`,cls:'neutral'},
    {lbl:'Bugs que Participou',val:st.bugs,sub:`itens com bug`,cls:st.bugs===0?'green':'red'},
    {lbl:'Retrabalho Gerado',val:`${st.reworkPct}%`,sub:`${st.rework} itens · meta <15%`,cls:st.reworkPct<=15?'green':'red'},
    {lbl:'Horas Registradas',val:`${st.allocH}h`,sub:`cap. ${cap}h`,cls:'neutral'},
    {lbl:'Taxa de Ocupação',val:`${ocup}%`,sub:`horas reais / capacidade`,cls:ocup>=70&&ocup<=110?'green':ocup<70?'yellow':'red'},
    {lbl:'Gap de Registro',val:`${gap}h`,sub:`capacidade − horas reais`,cls:gap<=10?'green':gap<=20?'yellow':'red'},
    {lbl:'Cobertura de Estimativa',val:`${st.aderencia}%`,sub:`${st.withEst}/${st.tot} · meta >90%`,cls:st.aderencia>=90?'green':'red'},
    {lbl:'Precisão da Estimativa',val:st.precLabel,sub:'desvio médio real vs estimado',cls:'neutral'},
  ];
  kpis.forEach(k=>{
    const div=document.createElement('div');div.className=`kpi ${k.cls}`;
    const fs=String(k.val).length>12?'font-size:14px':String(k.val).length>7?'font-size:18px':'';
    div.innerHTML=`<div class="kpi-accent"></div><div class="kpi-label">${k.lbl}</div><div class="kpi-val" style="${fs}">${k.val}</div><div class="kpi-sub">${k.sub}</div>`;
    grid.appendChild(div);
  });
  // Show/hide evolution charts
  const showCharts=spFilter==='all';
  document.getElementById('dev-charts-section').style.display=showCharts?'':'none';
  if(showCharts){
    const L=SPRINTS.map(s=>`S${s}`);
    mk('tDevConclusion',{type:'line',data:{labels:L,datasets:[
      {label:'Conclusão (%)',data:spRows.map(x=>x.conclusao),borderColor:'#1d6ef5',backgroundColor:'rgba(29,110,245,.07)',fill:true,tension:.4,pointBackgroundColor:'#1d6ef5',pointBorderColor:'#fff',pointRadius:5,pointBorderWidth:2},
      {label:'Meta (80%)',data:SPRINTS.map(()=>80),borderColor:'#dc2626',borderDash:[5,4],borderWidth:1.5,pointRadius:0,fill:false},
    ]},options:{...bOpts('%'),scales:{...bOpts('%').scales,y:{...bOpts('%').scales.y,min:0,max:100}}}});
    mk('tDevVolume',{type:'bar',data:{labels:L,datasets:[{label:'Itens',data:spRows.map(x=>x.tot),backgroundColor:'rgba(29,110,245,.5)',borderRadius:4}]},options:{...bOpts()}});
    mk('tDevHoursEvo',{type:'bar',data:{labels:L,datasets:[
      {label:'Horas registradas',data:spRows.map(x=>x.allocH),backgroundColor:'rgba(13,148,136,.6)',borderRadius:4},
      {label:`Capacidade (${SprintRules.settings.capacityHours}h)`,data:SPRINTS.map(()=>SprintRules.settings.capacityHours),type:'line',borderColor:'#dc2626',borderDash:[5,4],borderWidth:1.5,pointRadius:0,fill:false},
    ]},options:{...bOpts('h')}});
    mk('tDevReworkEvo',{type:'bar',data:{labels:L,datasets:[
      {label:'Retrabalho (%)',data:spRows.map(x=>x.reworkPct),backgroundColor:spRows.map(x=>x.reworkPct>15?'rgba(220,38,38,.7)':'rgba(124,58,237,.6)'),borderRadius:4},
      {label:'Meta (15%)',data:SPRINTS.map(()=>15),type:'line',borderColor:'#d97706',borderDash:[5,4],borderWidth:1.5,pointRadius:0,fill:false},
    ]},options:{...bOpts('%')}});
    // Profile donuts
    const devItems=rows.filter(x=>devPeople(x).filter(p=>!SprintRules.isExcludedPerson(p)).includes(dev));
    const tpMap={};devItems.forEach(x=>{tpMap[x.Tipo]=(tpMap[x.Tipo]||0)+1;});
    const tpE=Object.entries(tpMap);
    mk('tDevType',{type:'doughnut',data:{labels:tpE.map(x=>x[0]),datasets:[{data:tpE.map(x=>x[1]),backgroundColor:PAL,borderWidth:2,borderColor:'#fff'}]},options:donutOpts()});
    const planC=devItems.filter(x=>x.Planejada==='Planejada').length;
    const nplanC=devItems.filter(x=>x.Planejada==='Não Planejado').length;
    mk('tDevPlanned',{type:'doughnut',data:{labels:['Planejado','Não Planejado'],datasets:[{data:[planC,nplanC],backgroundColor:['#1d6ef5','#dc2626'],borderWidth:2,borderColor:'#fff'}]},options:donutOpts()});
    const prMap={};devItems.forEach(x=>{prMap[x.Prioridade]=(prMap[x.Prioridade]||0)+1;});
    const prOrd=['Crítica','Alta','Média','Baixa'];const prCol={'Crítica':'#dc2626','Alta':'#f97316','Média':'#1d6ef5','Baixa':'#94a3b8'};
    const prE=prOrd.filter(p=>prMap[p]).map(p=>[p,prMap[p]]);
    mk('tDevPriority',{type:'doughnut',data:{labels:prE.map(x=>x[0]),datasets:[{data:prE.map(x=>x[1]),backgroundColor:prE.map(x=>prCol[x[0]]),borderWidth:2,borderColor:'#fff'}]},options:donutOpts()});
  }
  // Dev table
  const devItems2=rows.filter(x=>devPeople(x).filter(p=>!SprintRules.isExcludedPerson(p)).includes(dev));
  const dWrap=document.getElementById('dev-table-wrap');
  dWrap.innerHTML=`<div class="tbar"><span class="tbar-title">Itens</span><span class="tcnt">${devItems2.length}</span></div>
  <div class="tscroll"><table><thead><tr>
    <th>Item</th><th>Sprint</th><th>Status</th><th>Tipo</th><th>Épico</th><th>Est(h)</th><th>Real(h)</th><th>Retrab.</th><th>Bug Prod.</th><th>Nova Func.</th>
  </tr></thead><tbody>${devItems2.map(x=>`<tr>
    <td title="${esc(x.Nome)}">${esc(x.Nome)}</td>
    <td class="cm">S${x.Sprint}</td>
    <td>${stBadge(x.Status)}</td>
    <td><span class="b bo">${esc(x.Tipo)}</span></td>
    <td>${epicBadge(x.Epico)}</td>
    <td class="cm">${x.EstH||'—'}</td>
    <td class="cm">${x.RealH||'—'}</td>
    <td>${boolBadge(x.Retrabalho)}</td>
    <td>${bugBadge(x.BugProducao)}</td>
    <td>${boolBadge(x.ValorAgregado,'★')}</td>
  </tr>`).join('')||'<tr><td colspan="10" class="nd">Nenhum item</td></tr>'}</tbody></table></div>`;
}

// ── RAW PAGE ──────────────────────────────────────────────────────────────────
let rawPage=0,rawSort={col:'Sprint',asc:true},rawActiveFilters=new Set();
const RAW_PER=30;
function toggleRawFilter(f){
  if(rawActiveFilters.has(f)) rawActiveFilters.delete(f); else rawActiveFilters.add(f);
  document.getElementById('rf'+f)?.classList.toggle('active',rawActiveFilters.has(f));
  rawPage=0; renderRaw();
}
function sortRaw(col){rawSort=rawSort.col===col?{col,asc:!rawSort.asc}:{col,asc:true};rawPage=0;renderRaw();}
function rawPg(d){rawPage+=d;renderRaw();}
function renderRaw(){
  const sp=document.getElementById('rawSpSel').value;
  const dev=document.getElementById('rawDevSel').value;
  const tipo=document.getElementById('rawTipoSel').value;
  const q=document.getElementById('rawSearch').value.toLowerCase();
  let rows=DATA.filter(x=>x.Sprint);
  if(sp!=='all') rows=rows.filter(x=>x.Sprint===parseInt(sp));
  if(dev!=='all') rows=rows.filter(x=>devPeople(x).includes(dev));
  if(tipo!=='all') rows=rows.filter(x=>x.Tipo===tipo);
  if(rawActiveFilters.has('bug')) rows=rows.filter(x=>x.BugProducao);
  if(rawActiveFilters.has('val')) rows=rows.filter(x=>x.ValorAgregado);
  if(rawActiveFilters.has('rework')) rows=rows.filter(x=>x.Retrabalho);
  if(rawActiveFilters.has('epic')) rows=rows.filter(x=>x.Epico);
  if(q) rows=rows.filter(x=>x.Nome.toLowerCase().includes(q)||x.Responsavel.toLowerCase().includes(q)||x.Status.toLowerCase().includes(q));
  rows=[...rows].sort((a,b)=>{
    const av=a[rawSort.col]??'',bv=b[rawSort.col]??'';
    if(typeof av==='number') return rawSort.asc?av-bv:bv-av;
    return rawSort.asc?String(av).localeCompare(String(bv)):String(bv).localeCompare(String(av));
  });
  const total=rows.length,pages=Math.ceil(total/RAW_PER)||1;
  rawPage=Math.max(0,Math.min(rawPage,pages-1));
  const slice=rows.slice(rawPage*RAW_PER,(rawPage+1)*RAW_PER);
  document.getElementById('raw-cnt').textContent=`${total} itens`;
  document.getElementById('raw-body').innerHTML=slice.map(x=>`<tr>
    <td title="${esc(x.Nome)}">${esc(x.Nome)}</td>
    <td class="cm">${esc(devPeople(x).filter(p=>!SprintRules.isExcludedPerson(p))[0]?.split(' ')[0]||'—')}</td>
    <td class="cm">Sprint ${x.Sprint}</td>
    <td>${stBadge(x.Status)}</td>
    <td>${prioBadge(x.Prioridade)}</td>
    <td><span class="b bo">${esc(x.Tipo)}</span></td>
    <td>${epicBadge(x.Epico)}</td>
    <td class="cm">${x.EstH||'—'}</td>
    <td class="cm">${x.RealH||'—'}</td>
    <td>${x.Planejada==='Planejada'?'<span class="b bval">Sim</span>':x.Planejada==='Não Planejado'?'<span class="b bs">Não</span>':'—'}</td>
    <td>${boolBadge(x.Retrabalho)}</td>
    <td>${bugBadge(x.BugProducao)}</td>
    <td>${boolBadge(x.ValorAgregado,'★')}</td>
  </tr>`).join('');
  document.getElementById('raw-pgr').textContent=`${rawPage+1} / ${pages} · ${total} itens`;
  document.getElementById('rawPrev').disabled=rawPage===0;
  document.getElementById('rawNext').disabled=rawPage>=pages-1;
}

// ── INIT ──────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded',()=>{
  initSprintNav();
  renderDashboard();
  if(location.hash==='#bombeiro') navigate('bombeiro');
  else if(location.hash==='#sprint-panel') navigate('sprint-panel');
  else if(location.hash==='#carousel') navigate('carousel');
  else if(location.hash==='#capacity') navigate('capacity');
  else if(location.hash==='#forecast') navigate('forecast');
  else if(location.hash==='#dashboard') navigate('dashboard');
  else navigate('control-tower');
});
</script>
<script src="monday-client.js"></script>
<script src="bombeiro.js"></script>
<script src="sprint-panel.js"></script>
<script src="panel-carousel.js"></script>
<script src="capacity.js"></script>
<script src="forecast.js"></script>
<script src="consumption.js"></script>
<script src="release.js"></script>
<script src="control-tower.js"></script>
<script src="users.js"></script>
</body></html>

