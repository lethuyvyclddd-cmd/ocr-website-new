<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Trường Đại học Cửu Long</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{
  --sky:#EFF7FF;
  --sky-mid:#DCEEFF;
  --sky-deep:#A9D3F5;
  --blue:#4A90D9;
  --blue-dark:#2E6DA4;
  --white:#FFFFFF;
  --text:#1C2B3A;
  --muted:#5A7088;
  --border:#D9E9F8;
  --surface:#F4F9FF;
}
body{font-family:'Inter',sans-serif;color:var(--text);background:var(--white);line-height:1.5}

nav{
  position:sticky;top:0;z-index:100;
  background:rgba(255,255,255,0.92);
  backdrop-filter:blur(14px);
  border-bottom:1px solid var(--border);
  padding:0 6%;
}
.nav-inner{
  max-width:1100px;margin:0 auto;
  display:flex;align-items:center;justify-content:space-between;
  height:56px;
}
.logo{display:flex;align-items:center;gap:8px;text-decoration:none}
.logo-name{color:var(--blue-dark);font-size:13px;font-weight:600;letter-spacing:0.01em}
.nav-links{display:flex;align-items:center;gap:4px}
.nav-links a{
  color:var(--muted);text-decoration:none;
  padding:6px 12px;border-radius:6px;font-size:13px;
  transition:all 0.15s;
}
.nav-links a:hover{color:var(--text);background:var(--surface)}
.nav-cta{
  background:var(--blue)!important;color:#fff!important;
  font-weight:500!important;padding:6px 14px!important;
  border-radius:6px!important;
}
.nav-cta:hover{background:var(--blue-dark)!important}

.hero{
  background:var(--sky);
  padding:44px 6% 34px;
  text-align:center;
}
.hero-inner{max-width:640px;margin:0 auto}
.hero-logo{margin-bottom:12px;display:flex;justify-content:center}
.hero-tag{
  display:inline-block;
  background:#fff;color:var(--blue);
  font-size:11px;font-weight:600;letter-spacing:0.1em;text-transform:uppercase;
  padding:4px 12px;border-radius:50px;border:1px solid var(--sky-mid);
  margin-bottom:16px;
}
.hero h1{
  font-family:'Playfair Display',serif;
  font-size:clamp(26px,4vw,42px);
  font-weight:700;color:var(--blue-dark);
  line-height:1.15;margin-bottom:12px;
}
.hero p{
  color:var(--blue);font-size:15px;font-weight:300;
  margin-bottom:22px;opacity:0.85;
}
.hero-btns{display:flex;gap:10px;justify-content:center;flex-wrap:wrap}
.btn-solid{
  display:inline-block;background:var(--blue);color:#fff;
  font-weight:500;font-size:14px;padding:11px 24px;
  border-radius:8px;text-decoration:none;transition:all 0.2s;
}
.btn-solid:hover{background:var(--blue-dark)}
.btn-ghost{
  display:inline-block;background:#fff;color:var(--blue-dark);
  font-weight:500;font-size:14px;padding:11px 24px;
  border-radius:8px;text-decoration:none;border:1px solid var(--sky-deep);
  transition:all 0.2s;
}
.btn-ghost:hover{border-color:var(--blue);background:var(--surface)}

.stats-bar{
  background:#fff;border-bottom:1px solid var(--border);
  padding:16px 6%;
}
.stats-inner{
  max-width:1100px;margin:0 auto;
  display:grid;grid-template-columns:repeat(4,1fr);
}
.stat{text-align:center;padding:0 16px;border-right:1px solid var(--border)}
.stat:last-child{border-right:none}
.stat-n{font-family:'Playfair Display',serif;font-size:24px;font-weight:700;color:var(--blue-dark)}
.stat-l{font-size:12px;color:var(--muted);margin-top:1px}

.river-divider{display:block;width:100%;line-height:0;height:28px}

.about-row{
  display:grid;grid-template-columns:1fr 1fr;gap:32px;align-items:center;
}
.about-visual{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:8px}
.about-visual svg{width:100%;height:auto;display:block;border-radius:8px}
.about-list{list-style:none;margin-top:12px}
.about-list li{
  display:flex;gap:8px;align-items:flex-start;color:var(--muted);
  font-size:13px;padding:5px 0;
}

.section{padding:44px 6%}
.section-inner{max-width:1100px;margin:0 auto}
.sec-tag{
  font-size:10.5px;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;
  color:var(--blue);margin-bottom:6px;display:block;
}
.sec-h{
  font-family:'Playfair Display',serif;
  font-size:clamp(20px,2.2vw,28px);font-weight:700;
  color:var(--blue-dark);margin-bottom:8px;line-height:1.2;
}
.sec-sub{color:var(--muted);font-size:14px;font-weight:300;max-width:480px;line-height:1.6}

.programs-grid{
  display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-top:24px;
}
.prog{
  background:var(--surface);border:1px solid var(--border);
  border-radius:10px;padding:16px 14px;transition:all 0.2s;
}
.prog:hover{background:var(--sky);border-color:var(--sky-deep);transform:translateY(-2px)}
.prog-icon{font-size:20px;margin-bottom:8px}
.prog h3{font-size:14px;font-weight:600;color:var(--blue-dark);margin-bottom:4px}
.prog p{font-size:12px;color:var(--muted);line-height:1.5}

.ocr-banner{
  background:var(--sky);border-top:1px solid var(--border);
  border-bottom:1px solid var(--border);
  padding:36px 6%;text-align:center;
}
.ocr-banner h2{
  font-family:'Playfair Display',serif;
  font-size:22px;color:var(--blue-dark);margin-bottom:8px;
}
.ocr-banner p{color:var(--muted);font-size:14px;margin-bottom:18px}

footer{background:var(--blue-dark);padding:28px 6% 16px}
.footer-inner{max-width:1100px;margin:0 auto}
.footer-top{
  display:grid;grid-template-columns:1.5fr 1fr 1fr;gap:28px;
  padding-bottom:20px;border-bottom:1px solid rgba(255,255,255,0.1);
}
.footer-brand strong{color:#fff;font-size:14px;font-family:'Playfair Display',serif;display:block;margin-bottom:6px}
.footer-brand p{color:rgba(255,255,255,0.45);font-size:12px;line-height:1.6}
.footer-col h4{color:rgba(255,255,255,0.5);font-size:10.5px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;margin-bottom:8px}
.footer-col a{display:block;color:rgba(255,255,255,0.6);font-size:12px;text-decoration:none;padding:3px 0;transition:color 0.15s}
.footer-col a:hover{color:#fff}
.footer-bottom{padding-top:14px;color:rgba(255,255,255,0.3);font-size:11px;text-align:center}

@media(max-width:860px){
  .stats-inner,.programs-grid,.footer-top{grid-template-columns:1fr 1fr}
  .about-row{grid-template-columns:1fr;gap:20px}
}
@media(max-width:560px){
  .nav-links{display:none}
  .stats-inner,.programs-grid,.footer-top{grid-template-columns:1fr}
  .stat{border-right:none;border-bottom:1px solid var(--border);padding:10px 0}
  .stat:last-child{border-bottom:none}
}
</style>
</head>
<body>

<nav>
  <div class="nav-inner">
    <a class="logo" href="/">
      <x-university-logo :size="28" />
      <span class="logo-name">Đại học Cửu Long</span>
    </a>
    <div class="nav-links">
      <a href="/dashboard" class="nav-cta">Hệ thống OCR</a>
    </div>
  </div>
</nav>

<section class="hero">
  <div class="hero-inner">
    <div class="hero-logo"><x-university-logo :size="56" /></div>
    <div class="hero-tag">Vĩnh Long · Đồng bằng sông Cửu Long</div>
    <h1>Nơi bầu trời<br>rộng mở cho tri thức</h1>
    <p>Đại học ngoài công lập đầu tiên vùng ĐBSCL — đào tạo đa ngành, đa bậc học từ năm 2000.</p>
    <div class="hero-btns">
      <a href="#" class="btn-solid">Khám phá ngành học</a>
      <a href="#" class="btn-ghost">Thông tin tuyển sinh 2026</a>
    </div>
  </div>
</section>

<svg class="river-divider" viewBox="0 0 1200 60" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">
  <path d="M0,20 C150,50 350,0 600,20 C850,40 1050,0 1200,20 L1200,60 L0,60 Z" fill="#ffffff"/>
</svg>

<div class="stats-bar">
  <div class="stats-inner">
    <div class="stat">
      <div class="stat-n">30.000+</div>
      <div class="stat-l">Sinh viên, học viên</div>
    </div>
    <div class="stat">
      <div class="stat-n">800+</div>
      <div class="stat-l">Cán bộ, giảng viên</div>
    </div>
    <div class="stat">
      <div class="stat-n">29</div>
      <div class="stat-l">Ngành đào tạo</div>
    </div>
    <div class="stat">
      <div class="stat-n">97%</div>
      <div class="stat-l">Có việc làm sau TN</div>
    </div>
  </div>
</div>

<section class="section">
  <div class="section-inner">
    <div class="about-row">
      <div>
        <span class="sec-tag">Về trường</span>
        <h2 class="sec-h">Trường Đại học Cửu Long</h2>
        <p class="sec-sub">Thành lập ngày 05/01/2000 theo Quyết định của Thủ tướng Chính phủ, Trường Đại học Cửu Long (Mekong University) là trường đại học ngoài công lập đầu tiên của khu vực Đồng bằng sông Cửu Long, với khuôn viên rộng hơn 22 ha tại Vĩnh Long.</p>
        <ul class="about-list">
          <li>📍 Quốc lộ 1A, Phú Quới, Long Hồ, Vĩnh Long</li>
          <li>🎓 29 ngành đào tạo bậc đại học, cùng các chương trình thạc sĩ, liên kết quốc tế</li>
          <li>🤝 Gần 97% sinh viên có việc làm sau tốt nghiệp</li>
        </ul>
      </div>
      <div class="about-visual">
        <svg viewBox="0 0 500 340" xmlns="http://www.w3.org/2000/svg">
          <rect width="500" height="340" fill="#EFF7FF"/>
          <circle cx="410" cy="70" r="34" fill="#FBEFC7"/>
          <rect y="240" width="500" height="40" fill="#DCEEFF"/>
          <rect y="272" width="500" height="68" fill="#A9D3F5"/>
          <path d="M0,272 C60,262 120,282 180,272 C240,262 300,282 360,272 C420,262 460,272 500,268 L500,290 L0,290 Z" fill="#8FC7EC"/>
          <g>
            <rect x="70" y="260" width="12" height="26" fill="#3E7A3F"/>
            <ellipse cx="76" cy="248" rx="26" ry="18" fill="#5CA860"/>
            <ellipse cx="60" cy="238" rx="16" ry="12" fill="#6BB86E"/>
            <ellipse cx="94" cy="238" rx="16" ry="12" fill="#6BB86E"/>
          </g>
          <g>
            <rect x="410" y="258" width="11" height="28" fill="#3E7A3F"/>
            <ellipse cx="415" cy="246" rx="24" ry="16" fill="#5CA860"/>
            <ellipse cx="400" cy="238" rx="14" ry="10" fill="#6BB86E"/>
            <ellipse cx="430" cy="238" rx="14" ry="10" fill="#6BB86E"/>
          </g>
          <rect x="140" y="150" width="220" height="100" fill="#FFFFFF" stroke="#B9D9F2" stroke-width="2"/>
          <polygon points="130,150 250,95 370,150" fill="#4A90D9"/>
          <rect x="235" y="105" width="30" height="45" fill="#2E6DA4"/>
          <rect x="150" y="180" width="26" height="70" fill="#DCEEFF" stroke="#A9D3F5"/>
          <rect x="185" y="180" width="26" height="70" fill="#DCEEFF" stroke="#A9D3F5"/>
          <rect x="289" y="180" width="26" height="70" fill="#DCEEFF" stroke="#A9D3F5"/>
          <rect x="324" y="180" width="26" height="70" fill="#DCEEFF" stroke="#A9D3F5"/>
          <rect x="235" y="200" width="30" height="50" fill="#2E6DA4"/>
          <rect x="140" y="245" width="220" height="7" fill="#B9D9F2"/>
          <rect x="120" y="252" width="260" height="8" fill="#A9D3F5"/>
          <rect x="248" y="70" width="4" height="35" fill="#2E6DA4"/>
          <path d="M252,70 L280,78 L252,86 Z" fill="#E0685F"/>
        </svg>
      </div>
    </div>
  </div>
</section>

<section class="section" style="padding-top:0">
  <div class="section-inner">
    <span class="sec-tag">Chương trình đào tạo</span>
    <h2 class="sec-h">Học ngành bạn đam mê</h2>
    <p class="sec-sub">29 ngành đào tạo bậc đại học tại các khoa: Công nghệ thông tin, Kỹ thuật công nghệ, Kinh tế - Quản trị kinh doanh, Điều dưỡng - Dược, Luật, Nông nghiệp - Thủy sản, Ngoại ngữ...</p>
    <div class="programs-grid">
      <div class="prog">
        <div class="prog-icon">🖥️</div>
        <h3>Công nghệ thông tin</h3>
        <p>Lập trình, mạng máy tính, kỹ thuật phần mềm.</p>
      </div>
      <div class="prog">
        <div class="prog-icon">📊</div>
        <h3>Kinh tế - QTKD</h3>
        <p>Quản trị kinh doanh, Kế toán, Tài chính - Ngân hàng.</p>
      </div>
      <div class="prog">
        <div class="prog-icon">⚕️</div>
        <h3>Điều dưỡng - Dược học</h3>
        <p>Đào tạo nhân lực y tế cho khu vực và hợp tác quốc tế (Nhật Bản).</p>
      </div>
      <div class="prog">
        <div class="prog-icon">⚖️</div>
        <h3>Luật kinh tế</h3>
        <p>Pháp lý doanh nghiệp, thương mại và hành chính công.</p>
      </div>
      <div class="prog">
        <div class="prog-icon">🌱</div>
        <h3>Nông nghiệp - Thủy sản</h3>
        <p>Khoa học đất, chăn nuôi thú y, thủy sản vùng ĐBSCL.</p>
      </div>
      <div class="prog">
        <div class="prog-icon">🔧</div>
        <h3>Kỹ thuật công nghệ</h3>
        <p>Cơ khí, điện - điện tử, kiến trúc và xây dựng.</p>
      </div>
    </div>
  </div>
</section>

<footer>
  <div class="footer-inner">
    <div class="footer-top">
      <div class="footer-brand">
        <strong>Trường Đại học Cửu Long (Mekong University)</strong>
        <p>
          Quốc lộ 1A, Phú Quới, Long Hồ, Vĩnh Long<br>
          Phòng Hành chính: (0270) 3 831 155<br>
          Phòng Tuyển sinh: (0270) 3 832 538<br>
          Email: cuulonguniversity@mku.edu.vn<br>
          www.mku.edu.vn
        </p>
      </div>

      <div class="footer-col">
        <h4>Sinh viên</h4>
        <a href="#">Cổng sinh viên</a>
        <a href="#">Học bổng</a>
        <a href="#">Lịch học & Thi</a>
        <a href="#">Hỗ trợ việc làm</a>
      </div>
    </div>
    <div class="footer-bottom">© 2026 Trường Đại học Cửu Long. All rights reserved.</div>
  </div>
</footer>

</body>
</html>