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
  --sky:#D6EEFF;
  --sky-mid:#B8DCFA;
  --sky-deep:#7BBDE8;
  --blue:#2B7CC4;
  --blue-dark:#1A5490;
  --white:#FFFFFF;
  --text:#1C2B3A;
  --muted:#5A7088;
  --border:#C8DCEE;
  --surface:#EEF6FF;
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
.logo-badge{
  width:36px;height:36px;border-radius:8px;
  background:var(--blue);
  display:flex;align-items:center;justify-content:center;
  font-size:14px;font-weight:700;color:#fff;font-family:'Playfair Display',serif;
}
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
  padding:110px 6% 90px;
  text-align:center;
  position:relative;overflow:hidden;
}
.hero-clouds{
  position:absolute;inset:0;
  background-image:
    radial-gradient(ellipse 300px 120px at 10% 30%, rgba(255,255,255,0.55) 0%, transparent 70%),
    radial-gradient(ellipse 400px 150px at 80% 20%, rgba(255,255,255,0.4) 0%, transparent 70%),
    radial-gradient(ellipse 250px 100px at 55% 70%, rgba(255,255,255,0.3) 0%, transparent 70%);
}
.hero-inner{position:relative;z-index:2;max-width:660px;margin:0 auto}
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
      <div class="logo-badge">CL</div>
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
  <div class="hero-clouds"></div>
  <div class="hero-inner">
    <div class="hero-tag">Vĩnh Long · Đồng bằng sông Cửu Long</div>
    <h1>Nơi bầu trời<br>rộng mở cho tri thức</h1>
    <p>Đào tạo nhân lực chất lượng cao — chất lượng, sáng tạo, hội nhập.</p>
    <div class="hero-btns">
      <a href="#" class="btn-solid">Khám phá ngành học</a>
      <a href="#" class="btn-ghost">Thông tin tuyển sinh 2026</a>
    </div>
  </div>
</section>

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
        <img src="https://images.unsplash.com/photo-1522202176988-66273c2fd55f?auto=format&fit=crop&w=600&q=80" alt="Tuyển sinh">
        <div class="news-body">
          <div class="news-date">15 · 06 · 2026 · Tuyển sinh</div>
          <h3>Thông báo tuyển sinh 2026–2027 theo nhiều phương thức xét tuyển</h3>
          <p>Hạn nộp hồ sơ: 31/7/2026.</p>
        </div>
      </a>
      <a href="#" class="news-card">
        <img src="https://images.unsplash.com/photo-1516321318423-f06f85e504b3?auto=format&fit=crop&w=600&q=80" alt="Hội thảo AI">
        <div class="news-body">
          <div class="news-date">10 · 06 · 2026 · Sự kiện</div>
          <h3>Hội thảo AI và chuyển đổi số trong giáo dục đại học</h3>
          <p>Chuyên gia từ Google, FPT và VNG tham dự.</p>
        </div>
      </a>
      <a href="#" class="news-card">
        <img src="https://images.unsplash.com/photo-1523240795612-9a054b0db644?auto=format&fit=crop&w=600&q=80" alt="Việc làm">
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
  <h2>Hệ thống OCR nhận dạng CCCD</h2>
  <p>Trích xuất thông tin căn cước tự động bằng AI — xuất Word & Excel trong vài giây.</p>
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