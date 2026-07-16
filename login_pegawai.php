<?php
require 'function.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Jika sudah login sebagai pegawai arahkan ke dashboard
if (!empty($_SESSION['pegawai_id'])) {
    header('Location: dashboard_pegawai.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $identifier = trim($_POST['username'] ?? '');
    $password   = $_POST['password'] ?? '';

    if ($identifier === '' || $password === '') {
        $error = 'Masukkan username/email dan password.';
    } else {
        // Deteksi apakah kolom email tersedia di login_pegawai
        $hasEmail = false;
        $ck = mysqli_query($conn, "SHOW COLUMNS FROM login_pegawai LIKE 'email'");
        if ($ck && mysqli_num_rows($ck) > 0) $hasEmail = true;

        if ($hasEmail) {
            $sql = "SELECT idpegawai AS uid, nama AS uname, username, email, password FROM login_pegawai WHERE username = ? OR email = ? LIMIT 1";
            $stmt = mysqli_prepare($conn, $sql);
        } else {
            $sql = "SELECT idpegawai AS uid, nama AS uname, username, password FROM login_pegawai WHERE username = ? LIMIT 1";
            $stmt = mysqli_prepare($conn, $sql);
        }

        if ($stmt) {
            if ($hasEmail) {
                mysqli_stmt_bind_param($stmt, 'ss', $identifier, $identifier);
            } else {
                mysqli_stmt_bind_param($stmt, 's', $identifier);
            }
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            if ($row = mysqli_fetch_assoc($res)) {
                $hash = (string)$row['password'];
                if (password_verify($password, $hash)) {
                    session_regenerate_id(true);
                    unset($_SESSION['log'], $_SESSION['admin_email'], $_SESSION['admin_name']);
                    $_SESSION['pegawai_id'] = (int)($row['uid'] ?? 0);
                    $_SESSION['pegawai_nama'] = (string)($row['uname'] ?? 'Pegawai');
                    header('Location: dashboard_pegawai.php');
                    exit;
                } else {
                    $error = 'Username/email atau password salah.';
                }
            } else {
                $error = 'Username/email atau password salah.';
            }
            mysqli_stmt_close($stmt);
        } else {
            $error = 'Terjadi kesalahan server.';
        }
    }
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Login Pegawai - KSP Arthapura</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="css/styles.css" rel="stylesheet">
    <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&display=swap');
        
        :root {
            --bg1: #0b3a57;
            --bg2: #145a8a;
            --bg3: #1e6ba8;
            --accent: #8bb8e8;
            --accent-light: #a8c9f0;
            --gold: #ffd700;
            --gold-light: #ffed4e;
            --glass: rgba(255, 255, 255, 0.15);
            --glass-border: rgba(255, 255, 255, 0.25);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--bg1) 0%, var(--bg2) 50%, var(--bg3) 100%);
            overflow-x: hidden;
            font-family: 'Poppins', 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            position: relative;
        }
        
        /* Animated Background */
        body::before {
            content: '';
            position: fixed;
            inset: -50%;
            background: 
                radial-gradient(circle at 20% 20%, rgba(20, 90, 138, 0.6), transparent 50%),
                radial-gradient(circle at 80% 30%, rgba(139, 184, 232, 0.5), transparent 50%),
                radial-gradient(circle at 30% 80%, rgba(52, 152, 219, 0.4), transparent 50%),
                radial-gradient(circle at 70% 70%, rgba(255, 215, 0, 0.15), transparent 50%),
                linear-gradient(135deg, var(--bg1) 0%, var(--bg2) 50%, var(--bg3) 100%);
            filter: saturate(130%) blur(15px);
            z-index: -2;
            animation: gradientShift 15s ease infinite;
        }
        
        @keyframes gradientShift {
            0%, 100% { transform: translate(0, 0) scale(1); }
            33% { transform: translate(20px, -20px) scale(1.05); }
            66% { transform: translate(-20px, 20px) scale(0.95); }
        }
        
        /* Floating Particles */
        body::after {
            content: '';
            position: fixed;
            inset: 0;
            background-image: 
                radial-gradient(2px 2px at 20% 30%, rgba(255, 255, 255, 0.3), transparent),
                radial-gradient(2px 2px at 60% 70%, rgba(139, 184, 232, 0.4), transparent),
                radial-gradient(1px 1px at 50% 50%, rgba(255, 255, 255, 0.2), transparent),
                radial-gradient(1px 1px at 80% 10%, rgba(255, 215, 0, 0.3), transparent),
                radial-gradient(2px 2px at 90% 40%, rgba(255, 255, 255, 0.25), transparent);
            background-size: 200% 200%;
            background-position: 0% 0%, 100% 0%, 50% 100%, 0% 100%, 100% 100%;
            animation: particleFloat 20s linear infinite;
            z-index: -1;
            pointer-events: none;
        }
        
        @keyframes particleFloat {
            0% { background-position: 0% 0%, 100% 0%, 50% 100%, 0% 100%, 100% 100%; }
            100% { background-position: 100% 100%, 0% 100%, 50% 0%, 100% 0%, 0% 0%; }
        }
        
        /* Login Card */
        .login-card {
            background: var(--glass);
            border: 2px solid var(--glass-border);
            border-radius: 2rem;
            backdrop-filter: blur(20px) saturate(180%);
            -webkit-backdrop-filter: blur(20px) saturate(180%);
            width: 100%;
            max-width: 450px;
            padding: 3rem 2.5rem 2rem;
            box-shadow: 
                0 25px 80px rgba(0, 0, 0, 0.4),
                0 0 0 1px rgba(255, 255, 255, 0.1) inset,
                0 0 60px rgba(139, 184, 232, 0.2);
            position: relative;
            animation: cardFadeIn 0.8s ease-out;
            overflow: visible;
        }
        
        @keyframes cardFadeIn {
            from {
                opacity: 0;
                transform: translateY(30px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }
        
        .login-card::before {
            content: '';
            position: absolute;
            inset: -2px;
            border-radius: 2rem;
            padding: 2px;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.3), rgba(139, 184, 232, 0.3), rgba(255, 215, 0, 0.2));
            -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            -webkit-mask-composite: xor;
            mask-composite: exclude;
            z-index: -1;
            animation: borderGlow 3s ease infinite;
        }
        
        @keyframes borderGlow {
            0%, 100% { opacity: 0.5; }
            50% { opacity: 1; }
        }
        
        /* Brand Section */
        .brand {
            text-align: center;
            margin-bottom: 2rem;
            color: #fff;
            animation: brandSlideIn 1s ease-out 0.2s both;
            overflow: visible;
            padding-top: 0.5rem;
        }
        
        @keyframes brandSlideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .brand img {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            display: block;
            margin: 0 auto 1.5rem;
            border: 5px solid rgba(255, 255, 255, 0.4);
            box-shadow: 
                0 12px 32px rgba(0, 0, 0, 0.3),
                0 0 0 4px rgba(139, 184, 232, 0.3),
                0 0 40px rgba(139, 184, 232, 0.4);
            transition: all 0.4s ease;
            animation: logoFloat 3s ease-in-out infinite;
            position: relative;
            z-index: 1;
        }
        
        @keyframes logoFloat {
            0%, 100% { transform: translateY(0) scale(1); }
            50% { transform: translateY(-8px) scale(1.02); }
        }
        
        .brand img:hover {
            transform: translateY(-5px) scale(1.05);
            box-shadow: 
                0 16px 40px rgba(0, 0, 0, 0.4),
                0 0 0 4px rgba(255, 215, 0, 0.5),
                0 0 60px rgba(255, 215, 0, 0.3);
        }
        
        .brand h3 {
            margin: 0;
            font-weight: 800;
            letter-spacing: 0.5px;
            text-shadow: 0 3px 12px rgba(0, 0, 0, 0.4);
            font-size: 1.5rem;
            background: linear-gradient(135deg, #ffffff 0%, #e8f2f8 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .brand p {
            margin: 0.5rem 0 0;
            color: #e9f2fa;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
            font-size: 0.875rem;
            font-weight: 400;
        }
        
        /* Form Elements */
        .form-label {
            color: #e6eef8;
            font-weight: 600;
            letter-spacing: 0.3px;
            margin-bottom: 0.5rem;
            font-size: 0.875rem;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
        }
        
        .input-group {
            position: relative;
            margin-bottom: 0.5rem;
        }
        
        .input-group-text {
            border-radius: 1rem 0 0 1rem;
            background: linear-gradient(180deg, #f0f7ff 0%, #e0efff 100%);
            color: #2e5a7a;
            border: 2px solid rgba(139, 184, 232, 0.7);
            border-right: none;
            padding: 0.75rem 1rem;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        
        .input-group:focus-within .input-group-text {
            background: linear-gradient(180deg, #ffffff 0%, #f0f7ff 100%);
            border-color: #6b9bd2;
            box-shadow: 0 0 0 3px rgba(107, 155, 210, 0.2);
        }
        
        .form-control {
            border-radius: 0 1rem 1rem 0;
            border: 2px solid rgba(139, 184, 232, 0.7);
            border-left: none;
            background: rgba(255, 255, 255, 0.95);
            padding: 0.7rem 1rem;
            font-size: 0.875rem;
            transition: all 0.3s ease;
            color: #2e5a7a;
        }
        
        .form-control:focus {
            border-color: #6b9bd2;
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(107, 155, 210, 0.2);
            outline: none;
        }
        
        .form-control::placeholder {
            color: #9bb5d1;
        }
        
        /* Error Message */
        .alert-error {
            background: rgba(220, 53, 69, 0.2);
            border: 1px solid rgba(220, 53, 69, 0.5);
            color: #ffcccc;
            border-radius: 0.75rem;
            padding: 0.75rem 1rem;
            margin-bottom: 1rem;
            backdrop-filter: blur(10px);
            animation: shake 0.5s ease;
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-10px); }
            75% { transform: translateX(10px); }
        }
        
        /* Login Button */
        .btn-login {
            background: linear-gradient(135deg, #2e5a7a 0%, #4a7ba7 50%, #5a8bb7 100%);
            border: none;
            color: #fff;
            font-weight: 700;
            border-radius: 1rem;
            padding: 0.7rem 2rem;
            box-shadow: 
                0 10px 30px rgba(46, 90, 122, 0.4),
                0 0 0 0 rgba(139, 184, 232, 0.4);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            letter-spacing: 0.5px;
            font-size: 0.95rem;
            text-transform: uppercase;
            width: 100%;
        }
        
        .btn-login::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transform: translateX(-100%);
            transition: transform 0.6s ease;
        }
        
        .btn-login:hover {
            transform: translateY(-3px);
            box-shadow: 
                0 16px 40px rgba(46, 90, 122, 0.5),
                0 0 20px rgba(139, 184, 232, 0.6);
            filter: brightness(1.1);
        }
        
        .btn-login:hover::before {
            transform: translateX(100%);
        }
        
        .btn-login:active {
            transform: translateY(-1px);
        }
        
        /* Create Account Section */
        .create-account {
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .create-account-text {
            color: #e9f2fa;
            margin-bottom: 0.75rem;
            font-size: 0.85rem;
        }
        
        .btn-outline-light {
            border: 2px solid rgba(255, 255, 255, 0.6);
            color: #fff;
            border-radius: 0.75rem;
            padding: 0.5rem 1.25rem;
            font-weight: 600;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }
        
        .btn-outline-light:hover {
            background: rgba(255, 255, 255, 0.2);
            border-color: rgba(255, 255, 255, 0.9);
            color: #fff;
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(255, 255, 255, 0.2);
        }
        
        /* Responsive */
        @media (max-width: 576px) {
            .login-card {
                max-width: 90%;
                padding: 2rem 1.5rem 1.5rem;
            }
            
            .brand img {
                width: 100px;
                height: 100px;
            }
            
            .brand h3 {
                font-size: 1.35rem;
            }
            
            .brand p {
                font-size: 0.8rem;
            }
            
            .form-label {
                font-size: 0.8rem;
            }
            
            .form-control {
                font-size: 0.85rem;
                padding: 0.65rem 0.9rem;
            }
            
            .btn-login {
                font-size: 0.9rem;
                padding: 0.65rem 1.5rem;
            }
        }
    </style>
    <script>
        function getQueryVar(key) {
            const p = new URLSearchParams(window.location.search);
            return p.get(key) || '';
        }
        
        document.addEventListener('DOMContentLoaded', () => {
            const msg = getQueryVar('msg');
            if (msg) {
                try {
                    alert(decodeURIComponent(msg));
                } catch (e) {
                    alert(msg);
                }
            }
        });
    </script>
    <meta name="robots" content="noindex,nofollow">
</head>
<body>
    <div class="login-card">
        <div class="brand">
            <img src="assets/img/ksp.jpg" alt="Arthapura">
            <h3>Login Pegawai</h3>
            <p>Masuk ke sistem pegawai KSP Arthapura</p>
        </div>
        
        <form method="post" action="pegawai_login.php">
            <?php if (!empty($error)) { ?>
                <div class="alert-error">
                    <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error); ?>
                </div>
            <?php } ?>
            
            <div class="mb-3">
                <label class="form-label" for="usernameInput">
                    <i class="fas fa-user me-2"></i>Username / Email
                </label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-user"></i></span>
                    <input type="text" id="usernameInput" name="username" class="form-control" placeholder="Masukkan username atau email" required autofocus>
                </div>
            </div>
            
            <div class="mb-4">
                <label class="form-label" for="passwordInput">
                    <i class="fas fa-lock me-2"></i>Password
                </label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-lock"></i></span>
                    <input type="password" id="passwordInput" name="password" class="form-control" placeholder="Masukkan password" required>
                </div>
            </div>
            
            <div class="d-grid mb-3">
                <button class="btn btn-login" name="login" value="1" type="submit">
                    <i class="fas fa-sign-in-alt me-2"></i>Login
                </button>
            </div>
            
            <div class="create-account text-center">
                <div class="create-account-text">Belum punya akun?</div>
                <a href="buat_akun_login_pegawai.php" class="btn btn-sm btn-outline-light">
                    <i class="fas fa-user-plus me-1"></i> Buat Akun Baru
                </a>
            </div>
        </form>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>


