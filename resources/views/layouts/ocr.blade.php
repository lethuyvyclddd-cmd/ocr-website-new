<!DOCTYPE html>
<html lang="vi">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title','OCR Document Management System')</title>

    <!-- Bootstrap -->

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <!-- Bootstrap Icons -->

    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <!-- Google Font -->

    <link rel="preconnect"
          href="https://fonts.googleapis.com">

    <link rel="preconnect"
          href="https://fonts.gstatic.com"
          crossorigin>

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap"
          rel="stylesheet">

<style>

:root{

    --primary:#2563eb;

    --primary-dark:#1d4ed8;

    --primary-light:#dbeafe;

    --success:#10b981;

    --danger:#ef4444;

    --warning:#f59e0b;

    --white:#ffffff;

    --bg:#f5f7fb;

    --sidebar:#ffffff;

    --text:#1e293b;

    --text-light:#64748b;

    --border:#e2e8f0;

    --shadow:0 12px 30px rgba(15,23,42,.08);

    --radius:18px;

    --transition:.3s;

}

*{

    margin:0;

    padding:0;

    box-sizing:border-box;

}

html{

    scroll-behavior:smooth;

}

body{

    font-family:'Inter',sans-serif;

    background:linear-gradient(180deg,#f8fbff,#eef4ff);

    color:var(--text);

    min-height:100vh;

}

/* Scrollbar */

::-webkit-scrollbar{

    width:10px;

}

::-webkit-scrollbar-track{

    background:#eef2ff;

}

::-webkit-scrollbar-thumb{

    background:#c7d2fe;

    border-radius:30px;

}

::-webkit-scrollbar-thumb:hover{

    background:var(--primary);

}

/* Navbar */

.topbar{

    position:sticky;

    top:0;

    left:0;

    width:100%;

    height:72px;

    background:#fff;

    display:flex;

    justify-content:space-between;

    align-items:center;

    padding:0 35px;

    box-shadow:0 5px 20px rgba(0,0,0,.05);

    z-index:999;

}

/* Logo */

.brand{

    display:flex;

    align-items:center;

    text-decoration:none;

    gap:14px;

}

.brand-logo{

    width:46px;

    height:46px;

    border-radius:14px;

    background:linear-gradient(135deg,#2563eb,#60a5fa);

    color:#fff;

    display:flex;

    justify-content:center;

    align-items:center;

    font-size:22px;

    box-shadow:0 8px 18px rgba(37,99,235,.25);

}

.brand-text{

    display:flex;

    flex-direction:column;

}

.brand-title{

    font-size:18px;

    font-weight:800;

    color:#0f172a;

}

.brand-sub{

    font-size:12px;

    color:#64748b;

}

/* Menu */

.nav-menu{

    display:flex;

    align-items:center;

    gap:10px;

}

.nav-menu a{

    text-decoration:none;

    color:#64748b;

    font-weight:600;

    padding:10px 18px;

    border-radius:12px;

    transition:.25s;

}

.nav-menu a:hover{

    background:#dbeafe;

    color:#2563eb;

    transform:translateY(-2px);

}

.nav-menu a.active{

    background:#2563eb;

    color:#fff;

}

/* User */

.user-box{

    display:flex;

    align-items:center;

    gap:14px;

}

.avatar{

    width:46px;

    height:46px;

    border-radius:50%;

    background:linear-gradient(135deg,#2563eb,#3b82f6);

    display:flex;

    justify-content:center;

    align-items:center;

    color:#fff;

    font-size:18px;

    font-weight:700;

}

.user-info{

    display:flex;

    flex-direction:column;

}

.user-info strong{

    font-size:15px;

}

.user-info span{

    font-size:12px;

    color:#64748b;

}

/* Logout */

.logout-btn{

    border:none;

    background:#fee2e2;

    color:#dc2626;

    padding:10px 18px;

    border-radius:12px;

    font-weight:600;

    transition:.3s;

}

.logout-btn:hover{

    background:#dc2626;

    color:#fff;

}

/* Main */

.page{

    max-width:1400px;

    margin:auto;

    padding:35px;

}

/* Card */

.card-box{

    background:#fff;

    border-radius:20px;

    box-shadow:var(--shadow);

    border:1px solid var(--border);

    padding:25px;

    transition:.3s;

}

.card-box:hover{

    transform:translateY(-3px);

}

/* Title */

.page-title{

    font-size:32px;

    font-weight:800;

    margin-bottom:8px;

}

.page-subtitle{

    color:#64748b;

    margin-bottom:30px;

}

/* Alert */

.alert{

    border:none;

    border-radius:15px;

    padding:15px 20px;

    font-weight:500;

    box-shadow:0 10px 20px rgba(0,0,0,.04);

}

/* Table */

.table{

    vertical-align:middle;

}

.table thead{

    background:#f8fafc;

}

.table th{

    font-weight:700;

    color:#475569;

}

.table tbody tr{

    transition:.2s;

}

.table tbody tr:hover{

    background:#f8fbff;

}

/* Badge */

.badge{

    padding:8px 14px;

    border-radius:30px;

    font-size:12px;

}

/* Buttons */

.btn{

    border-radius:12px !important;

    font-weight:600 !important;

}

.btn-primary{

    background:#2563eb !important;

    border-color:#2563eb !important;

}

.btn-primary:hover{

    background:#1d4ed8 !important;

}

/* Animation */

.fade-up{

    animation:fadeUp .5s ease;

}

@keyframes fadeUp{

    from{

        opacity:0;

        transform:translateY(20px);

    }

    to{

        opacity:1;

        transform:translateY(0);

    }

}

@yield('extra_style')

</style>


</head>
<body>

<div class="topbar">

    <!-- Logo -->

    <a href="{{ Route::has('dashboard') ? route('dashboard') : url('/dashboard') }}"
       class="brand">

        <div class="brand-logo">

            <i class="bi bi-file-earmark-text-fill"></i>

        </div>

        <div class="brand-text">

            <div class="brand-title">

                OCR Document

            </div>

            <div class="brand-sub">

                Management System

            </div>

        </div>

    </a>

    <!-- Menu -->

    <nav class="nav-menu">

        <a href="{{ Route::has('dashboard') ? route('dashboard') : url('/dashboard') }}"
           class="{{ request()->is('dashboard') ? 'active' : '' }}">

            <i class="bi bi-speedometer2 me-2"></i>

            Dashboard

        </a>

        <a href="{{ route('applicants.create') }}"
           class="{{ request()->routeIs('applicants.create') ? 'active' : '' }}">

            <i class="bi bi-file-earmark-plus-fill me-2"></i>

            Hồ sơ mới

        </a>

        <a href="{{ route('applicants.index') }}"
           class="{{ request()->routeIs('applicants.index') || request()->routeIs('applicants.workspace') ? 'active' : '' }}">

            <i class="bi bi-folder2-open me-2"></i>

            Danh sách hồ sơ

        </a>

        @auth

            @if(auth()->user()->role=='admin')

            <a href="{{ route('users.index') }}"
               class="{{ request()->routeIs('users.*') ? 'active' : '' }}">

                <i class="bi bi-people-fill me-2"></i>

                Nhân viên

            </a>

            @endif

        @endauth

    </nav>

    <!-- User -->

    @auth

    <div class="user-box">

        <div class="avatar">

            {{ strtoupper(substr(auth()->user()->name,0,1)) }}

        </div>

        <div class="user-info">

            <strong>

                {{ auth()->user()->name }}

            </strong>

            <span>

                @if(auth()->user()->role=='admin')

                    Quản trị viên

                @else

                    Nhân viên

                @endif

            </span>

        </div>

        @if(Route::has('logout'))

        <form method="POST"
              action="{{ route('logout') }}"
              class="m-0">

            @csrf

            <button
                class="logout-btn">

                <i class="bi bi-box-arrow-right me-1"></i>

                Đăng xuất

            </button>

        </form>

        @endif

    </div>

    @endauth

</div>

<!-- Main -->

<div class="page fade-up">

    @if(session('success'))

        <div class="alert alert-success d-flex align-items-center mb-4">

            <i class="bi bi-check-circle-fill me-2"></i>

            {{ session('success') }}

        </div>

    @endif

    @if(session('error'))

        <div class="alert alert-danger d-flex align-items-center mb-4">

            <i class="bi bi-exclamation-triangle-fill me-2"></i>

            {{ session('error') }}

        </div>

    @endif

    @yield('content')
        <!-- Footer -->

    <footer class="mt-5">

        <div class="card-box">

            <div class="row align-items-center">

                <div class="col-md-6">

                    <h5 class="fw-bold mb-2">

                        <i class="bi bi-file-earmark-text-fill text-primary"></i>

                        OCR Document Management System

                    </h5>

                    <p class="text-muted mb-0">

                        Hệ thống nhận dạng và trích xuất thông tin tài liệu bằng công nghệ OCR.

                    </p>

                </div>

                <div class="col-md-6 text-md-end mt-3 mt-md-0">

                    <div class="mb-2">

                        <span class="badge bg-primary">

                            Version 1.0

                        </span>

                    </div>

                    <small class="text-muted">

                        © {{ date('Y') }}

                        Khoa Công nghệ Thông tin

                    </small>

                </div>

            </div>

        </div>

    </footer>

</div>

<!-- Loading -->

<div id="loadingScreen">

    <div class="loading-box">

        <div class="spinner-border text-primary"

             style="width:4rem;height:4rem;">

        </div>

        <h5 class="mt-4">

            Đang xử lý...

        </h5>

        <p class="text-muted">

            Vui lòng chờ trong giây lát

        </p>

    </div>

</div>

<!-- Back To Top -->

<button id="backTop">

    <i class="bi bi-arrow-up-short"></i>

</button>

<style>

#loadingScreen{

    position:fixed;

    inset:0;

    background:rgba(255,255,255,.85);

    backdrop-filter:blur(6px);

    display:none;

    justify-content:center;

    align-items:center;

    z-index:99999;

}

.loading-box{

    background:#fff;

    padding:35px;

    border-radius:20px;

    box-shadow:0 15px 35px rgba(0,0,0,.08);

    text-align:center;

    width:320px;

}

#backTop{

    position:fixed;

    right:30px;

    bottom:30px;

    width:55px;

    height:55px;

    border:none;

    border-radius:50%;

    background:#2563eb;

    color:#fff;

    font-size:24px;

    display:none;

    box-shadow:0 10px 25px rgba(37,99,235,.35);

    transition:.3s;

    z-index:999;

}

#backTop:hover{

    transform:translateY(-5px);

    background:#1d4ed8;

}

.footer-link{

    color:#64748b;

    text-decoration:none;

}

.footer-link:hover{

    color:#2563eb;

}

</style>
<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>

// ==============================
// Loading khi submit form
// ==============================

document.addEventListener("DOMContentLoaded",function(){

    const loading=document.getElementById("loadingScreen");

    document.querySelectorAll("form").forEach(form=>{

        form.addEventListener("submit",function(){

            loading.style.display="flex";

        });

    });

});

// ==============================
// Back To Top
// ==============================

const backTop=document.getElementById("backTop");

window.addEventListener("scroll",function(){

    if(window.scrollY>250){

        backTop.style.display="block";

    }else{

        backTop.style.display="none";

    }

});

backTop.addEventListener("click",function(){

    window.scrollTo({

        top:0,

        behavior:"smooth"

    });

});

// ==============================
// Hiệu ứng Card
// ==============================

document.querySelectorAll(".card-box").forEach(card=>{

    card.addEventListener("mouseenter",()=>{

        card.style.transform="translateY(-6px)";

        card.style.transition=".3s";

    });

    card.addEventListener("mouseleave",()=>{

        card.style.transform="translateY(0)";

    });

});

// ==============================
// Tooltip Bootstrap
// ==============================

const tooltipTriggerList=[].slice.call(

    document.querySelectorAll('[data-bs-toggle="tooltip"]')

);

tooltipTriggerList.map(function(el){

    return new bootstrap.Tooltip(el);

});

// ==============================
// Fade Animation
// ==============================

const observer=new IntersectionObserver(entries=>{

    entries.forEach(entry=>{

        if(entry.isIntersecting){

            entry.target.classList.add("fade-up");

        }

    });

});

document.querySelectorAll(".card-box").forEach(el=>{

    observer.observe(el);

});

// ==============================
// Đồng hồ thời gian
// ==============================

function updateClock(){

    const clock=document.getElementById("clock");

    if(clock){

        const now=new Date();

        clock.innerHTML=now.toLocaleString('vi-VN');

    }

}

setInterval(updateClock,1000);

updateClock();

// ==============================
// Toast tự động ẩn
// ==============================

setTimeout(()=>{

    document.querySelectorAll(".alert").forEach(alert=>{

        alert.style.transition=".5s";

        alert.style.opacity="0";

        setTimeout(()=>{

            alert.remove();

        },500);

    });

},4000);

// ==============================
// Ripple Effect Button
// ==============================

document.querySelectorAll(".btn").forEach(btn=>{

    btn.addEventListener("click",function(e){

        const ripple=document.createElement("span");

        ripple.style.position="absolute";

        ripple.style.borderRadius="50%";

        ripple.style.background="rgba(255,255,255,.5)";

        ripple.style.transform="scale(0)";

        ripple.style.animation="ripple .6s linear";

        ripple.style.width="120px";

        ripple.style.height="120px";

        ripple.style.left=(e.offsetX-60)+"px";

        ripple.style.top=(e.offsetY-60)+"px";

        ripple.style.pointerEvents="none";

        this.style.position="relative";

        this.style.overflow="hidden";

        this.appendChild(ripple);

        setTimeout(()=>{

            ripple.remove();

        },600);

    });

});

// ==============================
// Ripple CSS
// ==============================

const style=document.createElement("style");

style.innerHTML=`

@keyframes ripple{

0%{

transform:scale(0);

opacity:.7;

}

100%{

transform:scale(4);

opacity:0;

}

}

`;

document.head.appendChild(style);

</script>

@yield('extra_script')

</body>

</html>