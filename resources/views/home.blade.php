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
body{font-family:'Inter',sans-serif;color:var(--text);background:var(--white);line-height:1.6}

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
  height:64px;
}
.logo{display:flex;align-items:center;gap:10px;text-decoration:none}
.logo-name{color:var(--blue-dark);font-size:14px;font-weight:600;letter-spacing:0.01em}
.nav-links{display:flex;align-items:center;gap:4px}
.nav-links a{
  color:var(--muted);text-decoration:none;
  padding:7px 13px;border-radius:6px;font-size:14px;
  transition:all 0.15s;
}
.nav-links a:hover{color:var(--text);background:var(--surface)}
.nav-cta{
  background:var(--blue)!important;color:#fff!important;
  font-weight:500!important;padding:7px 16px!important;
  border-radius:6px!important;
}
.nav-cta:hover{background:var(--blue-dark)!important}

.hero{
  background:var(--sky);
  padding:90px 6% 70px;
  text-align:center;
}
.hero-inner{max-width:660px;margin:0 auto}
.hero-logo{margin-bottom:22px;display:flex;justify-content:center}
.hero-tag{
  display:inline-block;
  background:#fff;color:var(--blue);
  font-size:12px;font-weight:600;letter-spacing:0.1em;text-transform:uppercase;
  padding:5px 14px;border-radius:50px;border:1px solid var(--sky-mid);
  margin-bottom:24px;
}
.hero h1{
  font-family:'Playfair Display',serif;
  font-size:clamp(34px,5vw,56px);
  font-weight:700;color:var(--blue-dark);
  line-height:1.18;margin-bottom:18px;
}
.hero p{
  color:var(--blue);font-size:17px;font-weight:300;
  margin-bottom:36px;opacity:0.85;
}
.hero-btns{display:flex;gap:12px;justify-content:center;flex-wrap:wrap}
.btn-solid{
  display:inline-block;background:var(--blue);color:#fff;
  font-weight:500;font-size:15px;padding:13px 28px;
  border-radius:8px;text-decoration:none;transition:all 0.2s;
}
.btn-solid:hover{background:var(--blue-dark)}
.btn-ghost{
  display:inline-block;background:#fff;color:var(--blue-dark);
  font-weight:500;font-size:15px;padding:13px 28px;
  border-radius:8px;text-decoration:none;border:1px solid var(--sky-deep);
  transition:all 0.2s;
}
.btn-ghost:hover{border-color:var(--blue);background:var(--surface)}

.stats-bar{
  background:#fff;border-bottom:1px solid var(--border);
  padding:28px 6%;
}
.stats-inner{
  max-width:1100px;margin:0 auto;
  display:grid;grid-template-columns:repeat(4,1fr);
  divide-x:1px solid var(--border);
}
.stat{text-align:center;padding:0 20px;border-right:1px solid var(--border)}
.stat:last-child{border-right:none}
.stat-n{font-family:'Playfair Display',serif;font-size:32px;font-weight:700;color:var(--blue-dark)}
.stat-l{font-size:13px;color:var(--muted);margin-top:2px}

.river-divider{display:block;width:100%;line-height:0}

.about-row{
  display:grid;grid-template-columns:1fr 1fr;gap:56px;align-items:center;margin-top:40px;
}
.about-visual{background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:12px}
.about-visual svg{width:100%;height:auto;display:block;border-radius:10px}
.about-list{list-style:none;margin-top:18px}
.about-list li{
  display:flex;gap:10px;align-items:flex-start;color:var(--muted);
  font-size:14px;padding:7px 0;
}

.news-illus{width:100%;height:160px;display:block}

.section{padding:80px 6%}
.section-inner{max-width:1100px;margin:0 auto}
.sec-tag{
  font-size:11px;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;
  color:var(--blue);margin-bottom:10px;display:block;
}
.sec-h{
  font-family:'Playfair Display',serif;
  font-size:clamp(24px,2.8vw,36px);font-weight:700;
  color:var(--blue-dark);margin-bottom:12px;line-height:1.25;
}
.sec-sub{color:var(--muted);font-size:16px;font-weight:300;max-width:480px;line-height:1.75}

.programs-grid{
  display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-top:40px;
}
.prog{
  background:var(--surface);border:1px solid var(--border);
  border-radius:12px;padding:24px 20px;transition:all 0.2s;
}
.prog:hover{background:var(--sky);border-color:var(--sky-deep);transform:translateY(-2px)}
.prog-icon{font-size:24px;margin-bottom:12px}
.prog h3{font-size:15px;font-weight:600;color:var(--blue-dark);margin-bottom:6px}
.prog p{font-size:13px;color:var(--muted);line-height:1.6}

.news-row{display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px;margin-top:40px}
.news-card{
  background:#fff;border:1px solid var(--border);
  border-radius:12px;overflow:hidden;transition:box-shadow 0.2s;
  text-decoration:none;color:inherit;display:block;
}
.news-card:hover{box-shadow:0 6px 24px rgba(43,124,196,0.12)}
.news-card img{width:100%;height:160px;object-fit:cover;display:block}
.news-body{padding:18px 16px}
.news-date{font-size:11px;color:var(--blue);font-weight:600;letter-spacing:0.06em;margin-bottom:6px}
.news-body h3{font-size:14px;font-weight:600;color:var(--text);line-height:1.45;margin-bottom:6px}
.news-body p{font-size:13px;color:var(--muted)}

.ocr-banner{
  background:var(--sky);border-top:1px solid var(--border);
  border-bottom:1px solid var(--border);
  padding:60px 6%;text-align:center;
}
.ocr-banner h2{
  font-family:'Playfair Display',serif;
  font-size:28px;color:var(--blue-dark);margin-bottom:10px;
}
.ocr-banner p{color:var(--muted);font-size:15px;margin-bottom:28px}

footer{background:var(--blue-dark);padding:48px 6% 28px}
.footer-inner{max-width:1100px;margin:0 auto}
.footer-top{
  display:grid;grid-template-columns:1.5fr 1fr 1fr;gap:48px;
  padding-bottom:36px;border-bottom:1px solid rgba(255,255,255,0.1);
}
.footer-brand strong{color:#fff;font-size:16px;font-family:'Playfair Display',serif;display:block;margin-bottom:8px}
.footer-brand p{color:rgba(255,255,255,0.45);font-size:13px;line-height:1.7}
.footer-col h4{color:rgba(255,255,255,0.5);font-size:11px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;margin-bottom:12px}
.footer-col a{display:block;color:rgba(255,255,255,0.6);font-size:13px;text-decoration:none;padding:4px 0;transition:color 0.15s}
.footer-col a:hover{color:#fff}
.footer-bottom{padding-top:20px;color:rgba(255,255,255,0.3);font-size:12px;text-align:center}

@media(max-width:860px){
  .stats-inner,.programs-grid,.news-row,.footer-top{grid-template-columns:1fr 1fr}
  .about-row{grid-template-columns:1fr;gap:28px}
}
@media(max-width:560px){
  .nav-links{display:none}
  .stats-inner,.programs-grid,.news-row,.footer-top{grid-template-columns:1fr}
  .stat{border-right:none;border-bottom:1px solid var(--border);padding:14px 0}
  .stat:last-child{border-bottom:none}
}
</style>
</head>
<body>

<nav>
  <div class="nav-inner">
    <a class="logo" href="/">
      <x-university-logo :size="34" />
      <span class="logo-name">Đại học Cửu Long</span>
    </a>
    <div class="nav-links">
      <a href="#">Trang chủ</a>
      <a href="#">Giới thiệu</a>
      <a href="#">Tuyển sinh</a>
      <a href="#">Đào tạo</a>
      <a href="#">Tin tức</a>
      <a href="/dashboard" class="nav-cta">Hệ thống OCR</a>
    </div>
  </div>
</nav>

<section class="hero">
  <div class="hero-inner">
    <div class="hero-logo"><x-university-logo :size="72" /></div>
    <div class="hero-tag">Vĩnh Long · Đồng bằng sông Cửu Long</div>
    <h1>Nơi bầu trời<br>rộng mở cho tri thức</h1>
    <p>Đào tạo nhân lực chất lượng cao — chất lượng, sáng tạo, hội nhập.</p>
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
      <div class="stat-n">20.000+</div>
      <div class="stat-l">Sinh viên</div>
    </div>
    <div class="stat">
      <div class="stat-n">500+</div>
      <div class="stat-l">Giảng viên</div>
    </div>
    <div class="stat">
      <div class="stat-n">50+</div>
      <div class="stat-l">Ngành đào tạo</div>
    </div>
    <div class="stat">
      <div class="stat-n">95%</div>
      <div class="stat-l">Có việc làm</div>
    </div>
  </div>
</div>

<section class="section">
  <div class="section-inner">
    <div class="about-row">
      <div>
        <span class="sec-tag">Về trường</span>
        <h2 class="sec-h">Trường Đại học Cửu Long</h2>
        <p class="sec-sub">Tọa lạc bên dòng Cửu Long tại Vĩnh Long, trường đào tạo đa ngành với môi trường học tập gắn liền thực tiễn vùng Đồng bằng sông Cửu Long.</p>
        <ul class="about-list">
          <li>📍 Quốc lộ 1A, Phú Quới, Long Hồ, Vĩnh Long</li>
          <li>🎓 Đào tạo đa ngành, đa lĩnh vực bậc đại học & sau đại học</li>
          <li>🤝 Gắn kết doanh nghiệp — sinh viên thực tập, việc làm ngay sau tốt nghiệp</li>
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

<section class="section">
  <div class="section-inner">
    <span class="sec-tag">Chương trình đào tạo</span>
    <h2 class="sec-h">Học ngành bạn đam mê</h2>
    <p class="sec-sub">Hơn 50 ngành đào tạo đại học và sau đại học, cập nhật theo nhu cầu thực tiễn.</p>
    <div class="programs-grid">
      <div class="prog">
        <div class="prog-icon">🖥️</div>
        <h3>Công nghệ thông tin</h3>
        <p>Lập trình, AI, an toàn thông tin và kỹ thuật phần mềm.</p>
      </div>
      <div class="prog">
        <div class="prog-icon">📊</div>
        <h3>Quản trị kinh doanh</h3>
        <p>Marketing, tài chính và quản lý trong môi trường số.</p>
      </div>
      <div class="prog">
        <div class="prog-icon">⚕️</div>
        <h3>Điều dưỡng & Y tế</h3>
        <p>Nhân lực y tế phục vụ hệ thống chăm sóc sức khỏe khu vực.</p>
      </div>
      <div class="prog">
        <div class="prog-icon">⚖️</div>
        <h3>Luật kinh tế</h3>
        <p>Pháp lý doanh nghiệp, thương mại và hành chính công.</p>
      </div>
      <div class="prog">
        <div class="prog-icon">🌱</div>
        <h3>Nông nghiệp công nghệ cao</h3>
        <p>IoT và khoa học đất trong sản xuất nông nghiệp bền vững.</p>
      </div>
      <div class="prog">
        <div class="prog-icon">🎨</div>
        <h3>Thiết kế & Truyền thông</h3>
        <p>UX/UI, đồ họa và sáng tạo nội dung số đa nền tảng.</p>
      </div>
    </div>
  </div>
</section>

<section class="section" style="background:var(--surface);padding-top:60px;padding-bottom:60px">
  <div class="section-inner">
    <span class="sec-tag">Tin tức</span>
    <h2 class="sec-h">Cập nhật mới nhất</h2>
    <div class="news-row">
      <a href="#" class="news-card">
        <svg class="news-illus" viewBox="0 0 400 160" xmlns="http://www.w3.org/2000/svg">
          <rect width="400" height="160" fill="#DCEEFF"/>
          <circle cx="200" cy="80" r="42" fill="#EFF7FF"/>
          <rect x="150" y="70" width="100" height="34" rx="4" fill="#FFFFFF" stroke="#4A90D9" stroke-width="2"/>
          <rect x="160" y="80" width="80" height="6" fill="#A9D3F5"/>
          <rect x="160" y="92" width="55" height="6" fill="#A9D3F5"/>
          <polygon points="200,40 240,58 200,76 160,58" fill="#4A90D9"/>
          <line x1="230" y1="58" x2="230" y2="76" stroke="#2E6DA4" stroke-width="2"/>
          <circle cx="230" cy="79" r="3" fill="#2E6DA4"/>
        </svg>
        <div class="news-body">
          <div class="news-date">15 · 06 · 2026 · Tuyển sinh</div>
          <h3>Thông báo tuyển sinh 2026–2027 theo nhiều phương thức xét tuyển</h3>
          <p>Hạn nộp hồ sơ: 31/7/2026.</p>
        </div>
      </a>
      <a href="#" class="news-card">
        <svg class="news-illus" viewBox="0 0 400 160" xmlns="http://www.w3.org/2000/svg">
          <rect width="400" height="160" fill="#EFF7FF"/>
          <rect x="140" y="55" width="120" height="75" rx="6" fill="#FFFFFF" stroke="#4A90D9" stroke-width="2"/>
          <circle cx="200" cy="92" r="20" fill="none" stroke="#4A90D9" stroke-width="2.5"/>
          <circle cx="200" cy="92" r="5" fill="#4A90D9"/>
          <line x1="200" y1="72" x2="200" y2="80" stroke="#4A90D9" stroke-width="2"/>
          <line x1="200" y1="104" x2="200" y2="112" stroke="#4A90D9" stroke-width="2"/>
          <line x1="180" y1="92" x2="188" y2="92" stroke="#4A90D9" stroke-width="2"/>
          <line x1="212" y1="92" x2="220" y2="92" stroke="#4A90D9" stroke-width="2"/>
          <rect x="118" y="130" width="164" height="8" rx="3" fill="#A9D3F5"/>
        </svg>
        <div class="news-body">
          <div class="news-date">10 · 06 · 2026 · Sự kiện</div>
          <h3>Hội thảo AI và chuyển đổi số trong giáo dục đại học</h3>
          <p>Chuyên gia từ Google, FPT và VNG tham dự.</p>
        </div>
      </a>
      <a href="#" class="news-card">
        <svg class="news-illus" viewBox="0 0 400 160" xmlns="http://www.w3.org/2000/svg">
          <rect width="400" height="160" fill="#DCEEFF"/>
          <rect x="160" y="80" width="80" height="55" rx="6" fill="#FFFFFF" stroke="#4A90D9" stroke-width="2"/>
          <rect x="182" y="65" width="36" height="18" rx="3" fill="none" stroke="#4A90D9" stroke-width="2"/>
          <line x1="160" y1="98" x2="240" y2="98" stroke="#4A90D9" stroke-width="2"/>
          <circle cx="145" cy="112" r="12" fill="#4A90D9"/>
          <circle cx="255" cy="112" r="12" fill="#2E6DA4"/>
          <rect x="120" y="124" width="50" height="10" rx="5" fill="#A9D3F5"/>
          <rect x="230" y="124" width="50" height="10" rx="5" fill="#A9D3F5"/>
        </svg>
        <div class="news-body">
          <div class="news-date">05 · 06 · 2026 · Sinh viên</div>
          <h3>Ngày hội việc làm 2026 — hơn 100 doanh nghiệp tham gia</h3>
          <p>Cơ hội gặp gỡ nhà tuyển dụng hàng đầu miền Tây.</p>
        </div>
      </a>
    </div>
  </div>
</section>

<div class="ocr-banner">
  <h2>Hệ thống OCR nhận dạng Hồ Sơ Xét Tuyển</h2>
  <p>Trích xuất thông tin hồ sơ xét tuyển tự động bằng AI — xuất Word & PDF .</p>
  <a href="/dashboard" class="btn-solid">Truy cập hệ thống →</a>
</div>

<footer>
  <div class="footer-inner">
    <div class="footer-top">
      <div class="footer-brand">
        <strong>Trường Đại học Cửu Long</strong>
        <p>Quốc lộ 1A, huyện Long Hồ, tỉnh Vĩnh Long<br>Điện thoại: (0270) 3 823 232<br>www.mku.edu.vn</p>
      </div>
      <div class="footer-col">
        <h4>Về trường</h4>
        <a href="#">Giới thiệu</a>
        <a href="#">Ban giám hiệu</a>
        <a href="#">Cơ sở vật chất</a>
        <a href="#">Tuyển dụng</a>
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