{{-- Visual das telas do OAuth e das ferramentas de IA conectadas: o mesmo da tela de login. --}}
<style>
    *{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px 16px;background:linear-gradient(135deg,#eef5ff,#f7f3ff);font:15px system-ui,sans-serif;color:#10214b}
    main{width:min(100%,520px);padding:34px;background:#fff;border:1px solid #dfe7f4;border-radius:20px;box-shadow:0 18px 55px #172a5c1a}
    img{width:130px;height:auto}h1{margin:24px 0 6px;font-size:25px;overflow-wrap:anywhere}p{color:#61718e;margin:0 0 16px}
    dl{display:grid;grid-template-columns:auto 1fr;gap:6px 14px;margin:18px 0;padding:14px;background:#f5f8fd;border-radius:12px}dt{font-weight:700}dd{margin:0;overflow-wrap:anywhere}.mono{font-family:ui-monospace,Consolas,monospace;font-size:13px}
    .scope{border:1px solid #dfe7f4;border-radius:12px;padding:14px 16px}.scope ul{margin:8px 0;padding-left:20px;display:grid;gap:6px}.scope small{color:#61718e}
    a{color:#1554e8}
    .actions{display:flex;gap:12px;margin-top:24px}.actions form{flex:1}
    button,.button{display:inline-block;width:100%;padding:13px;background:#1554e8;color:#fff;font:700 15px system-ui,sans-serif;text-align:center;text-decoration:none;border:0;border-radius:9px;cursor:pointer}
    button.ghost{background:#fff;color:#10214b;border:1px solid #cbd6e9}button.danger{width:auto;padding:8px 14px;background:#fff;color:#bb1c38;border:1px solid #f0c4cc;font-size:14px}
    button:focus-visible,.button:focus-visible,a:focus-visible{outline:2px solid #3673ee;outline-offset:2px}
    table{width:100%;border-collapse:collapse;margin:12px 0}th,td{text-align:left;padding:10px 8px;border-bottom:1px solid #e6ecf5;vertical-align:middle}th{font-size:12px;color:#61718e;text-transform:uppercase;letter-spacing:.04em}
    .notice{color:#13643f;background:#e9f8f0;border-radius:8px;padding:10px;margin:12px 0}.empty{color:#61718e}
    code{font-family:ui-monospace,Consolas,monospace;font-size:13px;background:#f5f8fd;padding:2px 6px;border-radius:6px;overflow-wrap:anywhere}
</style>
