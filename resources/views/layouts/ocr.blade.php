<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Hệ thống OCR - Trường Đại học Cửu Long')</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>

        :root{
            --primary:#4A90D9;
            --primary-dark:#2E6DA4;
            --primary-light:#DCEEFF;
            --sky:#EFF7FF;
            --success:#3DAE8B;
            --danger:#E0685F;
            --warning:#E0A93D;
            --white:#ffffff;
            --bg:#F4F9FF;
            --text:#1C2B3A;
            --text-light:#5A7088;
            --border:#D9E9F8;
            --shadow:0 4px 16px rgba(74,144,217,.08);
            --radius:14px;
        }

        *{margin:0;padding:0;box-sizing:border-box}
        html{scroll-behavior:smooth}
        body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}

        ::-webkit-scrollbar{width:9px}
        ::-webkit-scrollbar-track{background:var(--sky)}
        ::-webkit-scrollbar-thumb{background:#BEDCF7;border-radius:20px}
        ::-webkit-scrollbar-thumb:hover{background:var(--primary)}

        /* Topbar */
        .topbar{
            position:sticky;top:0;left:0;width:100%;z-index:999;
            background:var(--white);
            display:flex;align-items:center;gap:20px;
            padding:12px 30px;
            border-bottom:1px solid var(--border);
        }
        .brand{display:flex;align-items:center;gap:12px;text-decoration:none}
        .brand-text{display:flex;flex-direction:column;line-height:1.2}
        .brand-title{font-size:16px;font-weight:700;color:var(--primary-dark)}
        .brand-sub{font-size:11.5px;color:var(--text-light)}

        .nav-menu{display:flex;align-items:center;gap:4px;flex:1;flex-wrap:wrap}
        .nav-menu a{
            text-decoration:none;color:var(--text-light);font-weight:500;font-size:14px;
            padding:8px 14px;border-radius:10px;transition:.15s;white-space:nowrap;
        }
        .nav-menu a:hover{background:var(--primary-light);color:var(--primary-dark)}
        .nav-menu a.active{background:var(--primary);color:#fff}

        .user-box{display:flex;align-items:center;gap:10px}
        .avatar{
            width:36px;height:36px;border-radius:50%;background:var(--primary-light);
            display:flex;align-items:center;justify-content:center;color:var(--primary-dark);
            font-size:14px;font-weight:700;
        }
        .user-info{display:flex;flex-direction:column;line-height:1.2}
        .user-info strong{font-size:13.5px}
        .user-info span{font-size:11px;color:var(--text-light)}

        .logout-btn{
            border:none;background:transparent;color:var(--danger);
            padding:8px 12px;border-radius:10px;font-weight:500;font-size:13px;transition:.15s;
        }
        .logout-btn:hover{background:#FDEDEC}

        /* Main */
        .page{max-width:96%;margin:auto;padding:28px 24px 40px}

        .card-box{
            background:var(--white);border-radius:var(--radius);
            box-shadow:var(--shadow);border:1px solid var(--border);
            padding:22px;transition:.2s;
        }
        .card-box:hover{box-shadow:0 8px 24px rgba(74,144,217,.12)}

        .page-title{font-size:26px;font-weight:700;margin-bottom:6px;color:var(--text)}
        .page-subtitle{color:var(--text-light);margin-bottom:24px}

        .alert{border:none;border-radius:12px;padding:13px 18px;font-weight:500}
        .alert-success{background:#E4F5EF;color:#1F6E52}
        .alert-danger{background:#FBEAE8;color:#A5352C}
        .alert-warning{background:#FCF3E1;color:#8A6412}
        .alert-info{background:var(--primary-light);color:var(--primary-dark)}

        .table thead{background:var(--sky)}
        .table th{font-weight:600;color:var(--text-light);font-size:13.5px}
        .table tbody tr:hover{background:var(--sky)}

        .badge{padding:6px 12px;border-radius:20px;font-size:11.5px;font-weight:600}

        .btn{border-radius:10px !important;font-weight:600 !important;font-size:14px}
        .btn-primary{background:var(--primary) !important;border-color:var(--primary) !important}
        .btn-primary:hover{background:var(--primary-dark) !important;border-color:var(--primary-dark) !important}
        .btn-success{background:var(--success) !important;border-color:var(--success) !important}
        .btn-outline-primary{color:var(--primary) !important;border-color:var(--primary) !important}
        .btn-outline-primary:hover{background:var(--primary) !important;color:#fff !important}

        .text-primary{color:var(--primary) !important}

        footer .card-box{background:var(--sky);border:none}

        @media (max-width:900px){
            .topbar{flex-wrap:wrap}
            .nav-menu{order:3;width:100%}
        }

        @yield('extra_style')
    </style>
</head>
<body>

<div class="topbar">
    <a href="{{ Route::has('dashboard') ? route('dashboard') : url('/dashboard') }}" class="brand">
        <x-university-logo :size="42" />
        <div class="brand-text">
            <div class="brand-title">Đại học Cửu Long</div>
            <div class="brand-sub">Hệ thống OCR hồ sơ</div>
        </div>
    </a>

    <nav class="nav-menu">
        <a href="{{ Route::has('dashboard') ? route('dashboard') : url('/dashboard') }}"
           class="{{ request()->is('dashboard') ? 'active' : '' }}">
            <i class="bi bi-speedometer2 me-1"></i> Dashboard
        </a>
        <a href="{{ route('applicants.create') }}"
           class="{{ request()->routeIs('applicants.create') ? 'active' : '' }}">
            <i class="bi bi-file-earmark-plus me-1"></i> Hồ sơ mới
        </a>
        <a href="{{ route('applicants.index') }}"
           class="{{ request()->routeIs('applicants.index') || request()->routeIs('applicants.workspace') ? 'active' : '' }}">
            <i class="bi bi-folder2-open me-1"></i> Danh sách hồ sơ
        </a>
        @auth
            @if(auth()->user()->role == 'admin')
                <a href="{{ route('users.index') }}" class="{{ request()->routeIs('users.*') ? 'active' : '' }}">
                    <i class="bi bi-people me-1"></i> Nhân viên
                </a>
            @endif
        @endauth
    </nav>

    @auth
    <div class="user-box">
        <div class="avatar">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</div>
        <div class="user-info">
            <strong>{{ auth()->user()->name }}</strong>
            <span>{{ auth()->user()->role == 'admin' ? 'Quản trị viên' : 'Nhân viên' }}</span>
        </div>
        @if(Route::has('logout'))
        <form method="POST" action="{{ route('logout') }}" class="m-0">
            @csrf
            <button type="submit" class="logout-btn"><i class="bi bi-box-arrow-right"></i></button>
        </form>
        @endif
    </div>
    @endauth
</div>

<div class="page">

    @if(session('success'))
        <div class="alert alert-success d-flex align-items-center mb-4">
            <i class="bi bi-check-circle-fill me-2"></i>{{ session('success') }}
        </div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger d-flex align-items-center mb-4">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>{{ session('error') }}
        </div>
    @endif
    @if(session('warning'))
        <div class="alert alert-warning d-flex align-items-center mb-4">
            <i class="bi bi-exclamation-circle-fill me-2"></i>{{ session('warning') }}
        </div>
    @endif
    @if(session('info'))
        <div class="alert alert-info d-flex align-items-center mb-4">
            <i class="bi bi-info-circle-fill me-2"></i>{{ session('info') }}
        </div>
    @endif

    @yield('content')

    <footer class="mt-5">
        <div class="card-box d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <strong>Trường Đại học Cửu Long</strong>
                <div class="text-muted small">Hệ thống nhận dạng và trích xuất thông tin hồ sơ bằng OCR.</div>
            </div>
            <small class="text-muted">© {{ date('Y') }} MKU</small>
        </div>
    </footer>
</div>

<div id="loadingScreen">
    <div class="loading-box">
        <div class="spinner-border" style="width:3rem;height:3rem;color:var(--primary)"></div>
        <h6 class="mt-3 mb-1">Đang xử lý...</h6>
        <p class="text-muted small mb-0">Vui lòng chờ trong giây lát</p>
    </div>
</div>

<button id="backTop"><i class="bi bi-arrow-up-short"></i></button>

<style>
    #loadingScreen{
        position:fixed;inset:0;background:rgba(255,255,255,.85);backdrop-filter:blur(4px);
        display:none;justify-content:center;align-items:center;z-index:99999;
    }
    .loading-box{background:#fff;padding:28px 34px;border-radius:16px;box-shadow:var(--shadow);text-align:center}
    #backTop{
        position:fixed;right:24px;bottom:24px;width:46px;height:46px;border:none;border-radius:50%;
        background:var(--primary);color:#fff;font-size:22px;display:none;
        box-shadow:0 6px 18px rgba(74,144,217,.35);transition:.2s;z-index:999;
    }
    #backTop:hover{background:var(--primary-dark)}
</style>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function () {
    const loading = document.getElementById("loadingScreen");

    document.querySelectorAll("form").forEach(function (form) {
        form.addEventListener("submit", function (event) {
            if (form.classList.contains("delete-form")) {
                if (!confirm("Bạn có chắc chắn muốn xóa hồ sơ này không?")) {
                    event.preventDefault();
                    loading.style.display = "none";
                    return;
                }
            }
            loading.style.display = "flex";
        });
    });

    const backTop = document.getElementById("backTop");
    window.addEventListener("scroll", function () {
        backTop.style.display = window.scrollY > 250 ? "block" : "none";
    });
    backTop.addEventListener("click", function () {
        window.scrollTo({ top: 0, behavior: "smooth" });
    });

    setTimeout(function () {
        document.querySelectorAll(".alert").forEach(function (alert) {
            alert.style.transition = ".5s";
            alert.style.opacity = "0";
            setTimeout(function () { alert.remove(); }, 500);
        });
    }, 4000);
});
</script>

@yield('extra_script')

</body>
</html>