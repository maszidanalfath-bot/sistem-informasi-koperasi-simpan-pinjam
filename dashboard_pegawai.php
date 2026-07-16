<?php
require 'function.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Redirect to pegawai login if not authenticated
if (empty($_SESSION['pegawai_id'])) {
    header('Location: pegawai_login.php');
    exit;
}

// Optional: If you have pegawai login, $_SESSION['pegawai_id'] can be used to scope data
$pegawai_id = $_SESSION['pegawai_id'] ?? null;
// Tentukan daftar anggota yang menjadi tanggung jawab pegawai ini
$assignedAnggotaIds = [];
if ($pegawai_id) {
    // Deteksi kolom relasi anggota -> pegawai (idpegawai_pembina | idpegawai)
    $relCol = null;
    $ck = mysqli_query($conn, "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='anggota' AND COLUMN_NAME='idpegawai_pembina'");
    if ($ck && ($rw = mysqli_fetch_assoc($ck)) && (int)$rw['c'] > 0) {
        $relCol = 'idpegawai_pembina';
    } else {
        $ck2 = mysqli_query($conn, "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='anggota' AND COLUMN_NAME='idpegawai'");
        if ($ck2 && ($rw2 = mysqli_fetch_assoc($ck2)) && (int)$rw2['c'] > 0) {
            $relCol = 'idpegawai';
        }
    }
    if ($relCol) {
        $qIds = mysqli_query($conn, "SELECT idanggota FROM anggota WHERE `".mysqli_real_escape_string($conn,$relCol)."`=".(int)$pegawai_id);
        if ($qIds) while ($r = mysqli_fetch_assoc($qIds)) $assignedAnggotaIds[] = (int)$r['idanggota'];
    }
}
// Set timezone untuk memastikan tanggal benar
date_default_timezone_set('Asia/Jakarta');

// Absen pegawai (masuk/pulang)
$absen_msg = '';$absen_type='';$absen_today = null;
// Definisikan tanggal hari ini sekali di awal (konsisten untuk semua penggunaan)
$today = date('Y-m-d');
$todayDisplay = formatTanggalIndonesia(date('Y-m-d')); // Format tampilan: Senin, 15 Januari 2024

@mysqli_query($conn, "CREATE TABLE IF NOT EXISTS absensi_pegawai (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    pegawai_id INT NOT NULL,
    tanggal DATE NOT NULL,
    jam_masuk DATETIME NULL,
    jam_pulang DATETIME NULL,
    note VARCHAR(255) NULL,
    UNIQUE KEY uniq_peg_tgl (pegawai_id, tanggal),
    KEY idx_peg (pegawai_id),
    KEY idx_tgl (tanggal)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if (!empty($pegawai_id)) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['absen_action'])) {
        $action = $_POST['absen_action'];
        if ($action === 'masuk') {
            // Cek apakah sudah ada absen untuk hari ini
            $sqlCek = "SELECT jam_masuk FROM absensi_pegawai WHERE pegawai_id=? AND tanggal=? LIMIT 1";
            $stCek = mysqli_prepare($conn, $sqlCek);
            $sudahAbsen = false;
            if ($stCek) {
                mysqli_stmt_bind_param($stCek, 'is', $pegawai_id, $today);
                mysqli_stmt_execute($stCek);
                $rsCek = mysqli_stmt_get_result($stCek);
                if ($rsCek && ($rowCek = mysqli_fetch_assoc($rsCek))) {
                    $sudahAbsen = !empty($rowCek['jam_masuk']);
                }
                mysqli_stmt_close($stCek);
            }
            
            if ($sudahAbsen) {
                // Sudah absen hari ini
                $absen_msg = 'Anda sudah absen masuk hari ini.';
                $absen_type = 'info';
            } else {
                // Belum absen hari ini, izinkan absen masuk (tetap ditandai terlambat jika > 07:30)
                $sql = "INSERT INTO absensi_pegawai (pegawai_id, tanggal, jam_masuk) VALUES (?, ?, NOW())
                        ON DUPLICATE KEY UPDATE jam_masuk = IF(jam_masuk IS NULL, NOW(), jam_masuk)";
                $st = mysqli_prepare($conn, $sql);
                if ($st) {
                    mysqli_stmt_bind_param($st, 'is', $pegawai_id, $today);
                    if (mysqli_stmt_execute($st)) {
                        $nowTime = date('H:i:s');
                        $batasWaktu = '07:30:00';
                        if ($nowTime > $batasWaktu) {
                            $absen_msg = 'Absen masuk tercatat (terlambat).';
                            $absen_type = 'warning';
                        } else {
                            $absen_msg = 'Absen masuk tercatat.';
                            $absen_type = 'success';
                        }
                    } else { 
                        $absen_msg = 'Gagal mencatat absen masuk.'; 
                        $absen_type = 'danger'; 
                    }
                    mysqli_stmt_close($st);
                } else { 
                    $absen_msg = 'Kesalahan server saat absen masuk.'; 
                    $absen_type = 'danger'; 
                }
            }
        } elseif ($action === 'pulang') {
            // Update jam_pulang hanya jika jam_masuk sudah ada dan jam_pulang masih null
            $sql = "UPDATE absensi_pegawai SET jam_pulang = NOW() WHERE pegawai_id=? AND tanggal=? AND jam_masuk IS NOT NULL AND jam_pulang IS NULL";
            $st = mysqli_prepare($conn, $sql);
            if ($st) {
                mysqli_stmt_bind_param($st, 'is', $pegawai_id, $today);
                if (mysqli_stmt_execute($st)) {
                    if (mysqli_stmt_affected_rows($st) > 0) { $absen_msg='Absen pulang tercatat.'; $absen_type='success'; }
                    else { $absen_msg='Belum absen masuk atau sudah absen pulang.'; $absen_type='warning'; }
                } else { $absen_msg='Gagal mencatat absen pulang.'; $absen_type='danger'; }
                mysqli_stmt_close($st);
            } else { $absen_msg='Kesalahan server saat absen pulang.'; $absen_type='danger'; }
        }
        // Setelah POST, refresh data absen hari ini untuk memastikan tampilan terbaru
        $stRefresh = mysqli_prepare($conn, 'SELECT * FROM absensi_pegawai WHERE pegawai_id=? AND tanggal=? LIMIT 1');
        if ($stRefresh) {
            mysqli_stmt_bind_param($stRefresh, 'is', $pegawai_id, $today);
            mysqli_stmt_execute($stRefresh);
            $rsRefresh = mysqli_stmt_get_result($stRefresh);
            $absen_today = $rsRefresh ? mysqli_fetch_assoc($rsRefresh) : null;
            mysqli_stmt_close($stRefresh);
        }
    } else {
        // Jika bukan POST, ambil status absen hari ini (menggunakan $today yang sudah didefinisikan di atas)
        $st = mysqli_prepare($conn, 'SELECT * FROM absensi_pegawai WHERE pegawai_id=? AND tanggal=? LIMIT 1');
        if ($st) {
            mysqli_stmt_bind_param($st, 'is', $pegawai_id, $today);
            mysqli_stmt_execute($st);
            $rs = mysqli_stmt_get_result($st);
            $absen_today = $rs ? mysqli_fetch_assoc($rs) : null;
            mysqli_stmt_close($st);
        }
    }
}
// Fetch anggota list untuk sidebar (hanya milik pegawai ini)
$anggota_list = [];
if (!empty($assignedAnggotaIds)) {
    $idsCsv = implode(',', array_map('intval',$assignedAnggotaIds));
    $q_angg = mysqli_query($conn, "SELECT idanggota, nama FROM anggota WHERE idanggota IN (".$idsCsv.") ORDER BY nama ASC");
    if ($q_angg) while ($r = mysqli_fetch_assoc($q_angg)) $anggota_list[] = $r;
} else {
    // fallback: jika belum ada relasi, coba batasi ke created_by_pegawai
    $colCreated = mysqli_query($conn, "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='anggota' AND COLUMN_NAME='created_by_pegawai' LIMIT 1");
    if ($colCreated && mysqli_num_rows($colCreated) > 0) {
        $q_angg = mysqli_query($conn, "SELECT idanggota, nama FROM anggota WHERE created_by_pegawai=".(int)$pegawai_id." ORDER BY nama ASC");
        if ($q_angg) while ($r = mysqli_fetch_assoc($q_angg)) $anggota_list[] = $r;
    } else {
        // jika tidak ada kolom relasi apapun, tampilkan kosong agar tidak melihat milik orang lain
        $anggota_list = [];
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
    <title>Dashboard Pegawai - KSP Arthapura Sanggar Waringin</title>
    <link href="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/style.min.css" rel="stylesheet" />
    <link href="css/styles.css" rel="stylesheet" />
    <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
    <style>
        /* Enhanced dashboard styles */
        body { background: linear-gradient(180deg,#f3f7fb 0%,#eef4fa 100%); font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; color:#1f2937; }
        /* Sidebar (pegawai-only visual polish) */
        .sb-sidenav { background: linear-gradient(180deg,#07263a 0%,#0d3b57 100%); box-shadow:3px 0 22px rgba(2,6,23,0.16); border-radius:0 1rem 1rem 0; min-height:100vh; padding-top:1.25rem; }
        .sb-sidenav .nav-link { color: rgba(255,255,255,0.9); padding:0.8rem 1.1rem; font-weight:600; }
        .sb-sidenav .sb-nav-link-icon { color: rgba(255,255,255,0.95); width:36px; display:inline-flex; align-items:center; justify-content:center; }
        .sb-sidenav .nav-link:hover { color:#fff; background: linear-gradient(90deg, rgba(255,255,255,0.03), rgba(255,255,255,0.02)); }
        .sb-sidenav .nav-link.active { color:#fff; background: linear-gradient(90deg, rgba(255,255,255,0.06), rgba(255,255,255,0.03)); border-left:4px solid rgba(255,255,255,0.18); }
        .sb-sidenav .sb-sidenav-collapse-arrow i { color: rgba(255,255,255,0.8); }
        .sb-sidenav .sb-sidenav-menu-nested .nav-link { padding-left:2.4rem; font-weight:600; color:rgba(255,255,255,0.9); }
        .sb-sidenav .collapse.show { background: transparent; }
        .sb-sidenav-footer { background: linear-gradient(180deg, rgba(255,255,255,0.03), rgba(255,255,255,0.02)); color:rgba(255,255,255,0.9); border-top:1px solid rgba(255,255,255,0.03); padding:0.9rem 1.1rem; border-radius:0 0 0.85rem 0; }

        .sb-topnav.navbar { background: linear-gradient(90deg,#0b3a57 0%,#145a8a 100%); }
        .main-content { background: transparent; padding:0; }
        .panel { background: #fff; padding:1.25rem; border-radius:0.85rem; box-shadow:0 10px 30px rgba(17,24,39,0.06); }
        .hero { display:flex; gap:1rem; align-items:center; padding:1rem; background:linear-gradient(90deg,#fff 0%,#f8fbff 100%); border-radius:0.75rem; }
        .hero .avatar { width:72px; height:72px; border-radius:12px; object-fit:cover; box-shadow:0 6px 18px rgba(16,24,40,0.06); }
        .hero h3 { margin:0; font-size:1.3rem; }
        .summary-grid { margin-top:1rem; }
        .metric { border-radius:0.75rem; padding:1rem; color:#fff; display:flex; justify-content:space-between; align-items:center; box-shadow:0 6px 18px rgba(16,24,40,0.04); }
        .metric .left { display:flex; gap:0.75rem; align-items:center; }
        .metric .icon { width:44px; height:44px; display:flex; align-items:center; justify-content:center; border-radius:10px; font-size:1.1rem; }
        .metric .value { font-weight:800; font-size:1.15rem; }
        .metric.bg-primary { background:linear-gradient(90deg,#2563eb,#3b82f6); }
        .metric.bg-success { background:linear-gradient(90deg,#059669,#10b981); }
        .metric.bg-warning { background:linear-gradient(90deg,#d97706,#f59e0b); }
        .metric.bg-muted { background:linear-gradient(90deg,#6b7280,#9ca3af); }
        .card .card-header { font-weight:700; background:transparent; border-bottom:0; }
        .quick-actions a { min-width:120px; }
        @media (max-width:767px){ .hero{flex-direction:column;align-items:flex-start} .metric .value{font-size:1rem} }
    </style>
</head>
<body class="sb-nav-fixed">

<nav class="sb-topnav navbar navbar-expand navbar-dark bg-dark">
    <a class="navbar-brand ps-3" href="#">KSP Arthapura - Pegawai</a>
    <button class="btn btn-link btn-sm me-4" id="sidebarToggle"><i class="fas fa-bars"></i></button>

    <?php
    // unread notifications count
    $notif_count = 0;
    if (!empty($pegawai_id)) {
        @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS notifikasi (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            pegawai_id INT NULL,
            pesan VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            read_at DATETIME NULL,
            KEY idx_peg (pegawai_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $nq = mysqli_query($conn, "SELECT COUNT(*) AS c FROM notifikasi WHERE pegawai_id=".(int)$pegawai_id." AND read_at IS NULL");
        if ($nq && ($nr = mysqli_fetch_assoc($nq))) $notif_count = (int)$nr['c'];
    }
    ?>

    <div class="ms-auto me-3 text-white d-flex align-items-center gap-3">
        <a href="#" class="position-relative text-white text-decoration-none" data-bs-toggle="offcanvas" data-bs-target="#notifCanvas" aria-controls="notifCanvas">
            <i class="fas fa-bell"></i>
            <?php if ($notif_count > 0): ?><span class="badge bg-danger position-absolute" style="top:-8px; right:-10px; font-size:0.65rem;"><?= $notif_count ?></span><?php endif; ?>
        </a>
        <span class="d-none d-md-inline">Selamat datang, <?= htmlspecialchars($_SESSION['pegawai_nama'] ?? 'Pegawai'); ?></span>
    </div>
</nav>

<div id="layoutSidenav">
    <div id="layoutSidenav_nav">
        <nav class="sb-sidenav accordion sb-sidenav-dark" id="sidenavAccordion">
            <div class="sb-sidenav-menu">
                <div class="nav">
                    <a class="nav-link" href="dashboard_pegawai.php">
                        <div class="sb-nav-link-icon"><i class="fas fa-tachometer-alt"></i></div>
                        Dashboard Utama
                    </a>

                    <a class="nav-link collapsed" href="#" data-bs-toggle="collapse" data-bs-target="#collapseTransaksiPeg" aria-expanded="false" aria-controls="collapseTransaksiPeg">
                        <div class="sb-nav-link-icon"><i class="fas fa-exchange-alt"></i></div>
                        Transaksi
                        <div class="sb-sidenav-collapse-arrow"><i class="fas fa-angle-down"></i></div>
                    </a>
                    <div class="collapse" id="collapseTransaksiPeg" data-bs-parent="#sidenavAccordion">
                        <nav class="sb-sidenav-menu-nested nav">
                            <a class="nav-link" href="input_simpanan_pegawai.php">Input Simpanan</a>
                            <a class="nav-link" href="input_pinjaman_pegawai.php">Input Pinjaman</a>
                            <a class="nav-link" href="input_angsuran_pegawai.php">Input Angsuran</a>
                            <a class="nav-link" href="penarikan_simpanan_pegawai.php">Penarikan Simpanan</a>
                        </nav>
                    </div>

                    <a class="nav-link" href="data_anggota_pegawai.php">
                        <div class="sb-nav-link-icon"><i class="fas fa-users"></i></div>
                        Data Anggota
                    </a>

                    <a class="nav-link collapsed" href="#" data-bs-toggle="collapse" data-bs-target="#collapseLaporanPeg" aria-expanded="false" aria-controls="collapseLaporanPeg">
                        <div class="sb-nav-link-icon"><i class="fas fa-file-alt"></i></div>
                        Laporan Pribadi
                        <div class="sb-sidenav-collapse-arrow"><i class="fas fa-angle-down"></i></div>
                    </a>
                    <div class="collapse" id="collapseLaporanPeg" data-bs-parent="#sidenavAccordion">
                        <nav class="sb-sidenav-menu-nested nav">
                            <a class="nav-link" href="laporan_transaksi_pegawai.php">Laporan Transaksi</a>
                            <a class="nav-link" href="laporan_kinerja_pegawai.php">Laporan Kinerja</a>
                        </nav>
                    </div>

                    <a class="nav-link" href="profil_pegawai.php">
                        <div class="sb-nav-link-icon"><i class="fas fa-user-cog"></i></div>
                        Profil
                    </a>

                    <a class="nav-link text-danger" href="pegawai_logout.php">
                        <div class="sb-nav-link-icon"><i class="fas fa-door-open"></i></div>
                        Logout
                    </a>
                </div>
            </div>
            <div class="sb-sidenav-footer">
                <div class="small">Anda masuk sebagai</div>
                <?= htmlspecialchars($_SESSION['pegawai_nama'] ?? 'Pegawai'); ?>
            </div>
        </nav>
    </div>

    <div id="layoutSidenav_content">
        <main class="container-fluid px-4">
            <div class="main-content mt-4">
                <div class="panel">
                    <div class="hero mb-3">
                        <img src="assets/img/ksp.jpg" alt="avatar" class="avatar">
                        <div>
                            <h3>Selamat datang, <?= htmlspecialchars($_SESSION['pegawai_nama'] ?? 'Pegawai') ?></h3>
                            <div class="text-muted">Ringkasan aktivitas Anda hari ini dan akses cepat untuk tugas penting.</div>
                        </div>
                        <div class="ms-auto text-end">
                            <div class="text-muted small">Tanggal</div>
                            <div style="font-weight:700"><?= $todayDisplay ?></div>
                        </div>
                    </div>

                    <?php if ($absen_msg): ?>
                        <div class="alert alert-<?= htmlspecialchars($absen_type) ?>"><?= htmlspecialchars($absen_msg) ?></div>
                    <?php endif; ?>

                    <div class="row summary-grid g-3">
                        <div class="col-sm-6 col-md-3">
                            <div class="metric bg-primary">
                                <div class="left">
                                    <div class="icon" style="background:rgba(255,255,255,0.15)"><i class="fas fa-receipt"></i></div>
                                    <div>
                                        <div class="label">Transaksi Hari Ini</div>
                                        <div class="value">
                                            <?php
                                            $count_trans = 0;
                                            if (!empty($assignedAnggotaIds)) {
                                                $idsCsv = implode(',', array_map('intval',$assignedAnggotaIds));
                                                // Simpanan hari ini milik anggota tanggung jawab
                                                $q1 = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM simpanan WHERE idanggota IN (".$idsCsv.") AND DATE(tanggal)='".$today."'");
                                                if ($q1 && ($r1 = mysqli_fetch_assoc($q1))) $count_trans += (int)$r1['cnt'];
                                                // Pinjaman hari ini milik anggota tanggung jawab
                                                $q2 = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM pinjaman WHERE idanggota IN (".$idsCsv.") AND DATE(tanggal)='".$today."'");
                                                if ($q2 && ($r2 = mysqli_fetch_assoc($q2))) $count_trans += (int)$r2['cnt'];
                                                // Angsuran hari ini milik anggota tanggung jawab (join via pinjaman jika perlu)
                                                // Cek apakah angsuran memiliki idanggota langsung
                                                $hasIdAng = false; $chkA = mysqli_query($conn, "SHOW COLUMNS FROM angsuran LIKE 'idanggota'");
                                                if ($chkA && mysqli_num_rows($chkA)>0) $hasIdAng = true;
                                                if ($hasIdAng) {
                                                    $q3 = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM angsuran WHERE idanggota IN (".$idsCsv.") AND DATE(tanggal)='".$today."'");
                                                } else {
                                                    $q3 = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM angsuran a LEFT JOIN pinjaman p ON a.idpinjaman=p.idpinjaman WHERE p.idanggota IN (".$idsCsv.") AND DATE(a.tanggal)='".$today."'");
                                                }
                                                if ($q3 && ($r3 = mysqli_fetch_assoc($q3))) $count_trans += (int)$r3['cnt'];
                                            }
                                            echo (int)$count_trans;
                                            ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6 col-md-3">
                            <div class="metric bg-success">
                                <div class="left">
                                    <div class="icon" style="background:rgba(255,255,255,0.15)"><i class="fas fa-piggy-bank"></i></div>
                                    <div>
                                        <div class="label">Simpanan Dikelola</div>
                                        <div class="value">
                                            <?php
                                            $count_simp = 0;
                                            if (!empty($assignedAnggotaIds)) {
                                                $idsCsv = implode(',', array_map('intval',$assignedAnggotaIds));
                                                $q = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM simpanan WHERE idanggota IN (".$idsCsv.")");
                                                if ($q && $r = mysqli_fetch_assoc($q)) $count_simp = (int)$r['cnt'];
                                            }
                                            echo (int)$count_simp;
                                            ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6 col-md-3">
                            <div class="metric bg-warning">
                                <div class="left">
                                    <div class="icon" style="background:rgba(255,255,255,0.15)"><i class="fas fa-hand-holding-usd"></i></div>
                                    <div>
                                        <div class="label">Pinjaman Dikelola</div>
                                        <div class="value">
                                            <?php
                                            $count_pin = 0;
                                            if (!empty($assignedAnggotaIds)) {
                                                $idsCsv = implode(',', array_map('intval',$assignedAnggotaIds));
                                                $q = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM pinjaman WHERE idanggota IN (".$idsCsv.")");
                                                if ($q && $r = mysqli_fetch_assoc($q)) $count_pin = (int)$r['cnt'];
                                            }
                                            echo (int)$count_pin;
                                            ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6 col-md-3">
                            <div class="metric bg-muted">
                                <div class="left">
                                    <div class="icon" style="background:rgba(255,255,255,0.15)"><i class="fas fa-users"></i></div>
                                    <div>
                                        <div class="label">Anggota Tanggung Jawab</div>
                                        <div class="value">
                                            <?php
                                            $count_angg = 0;
                                            if (!empty($assignedAnggotaIds)) {
                                                $count_angg = count($assignedAnggotaIds);
                                            }
                                            echo (int)$count_angg;
                                            ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row mt-4">
                    <div class="col-lg-6">
                        <div class="card mb-4 panel">
                            <div class="card-header">Absen Pegawai (Hari Ini)</div>
                            <div class="card-body">
                                <div class="mb-2">Tanggal: <strong><?= $todayDisplay ?></strong></div>
                                <div class="mb-3">
                                    <?php
                                    $jm = !empty($absen_today['jam_masuk']) ? strtotime($absen_today['jam_masuk']) : null;
                                    $isLate = $jm ? (date('H:i:s', $jm) > '07:30:00') : false;
                                    ?>
                                    <div>Jam Masuk: <strong style="<?= $isLate ? 'color:#dc3545' : '' ?>">
                                        <?= $jm ? htmlspecialchars(date('H:i:s', $jm)) : '--' ?>
                                        <?= $isLate ? '<span class="badge bg-danger ms-2">Terlambat</span>' : '' ?>
                                    </strong></div>
                                    <div>Jam Pulang: <strong><?= !empty($absen_today['jam_pulang']) ? htmlspecialchars(date('H:i:s', strtotime($absen_today['jam_pulang']))) : '--' ?></strong></div>
                                </div>
                                <form method="post" class="d-flex gap-2 flex-wrap">
                                    <button class="btn btn-primary btn-sm" name="absen_action" value="masuk" <?= !empty($absen_today['jam_masuk']) ? 'disabled' : '' ?>><i class="fas fa-sign-in-alt me-1"></i> Absen Masuk</button>
                                    <button class="btn btn-success btn-sm" name="absen_action" value="pulang" <?= empty($absen_today['jam_masuk']) || !empty($absen_today['jam_pulang']) ? 'disabled' : '' ?>><i class="fas fa-sign-out-alt me-1"></i> Absen Pulang</button>
                                </form>
                            </div>
                        </div>
                        <div class="card mb-4 panel">
                            <div class="card-header">Informasi Koperasi</div>
                            <div class="card-body">
                                <?php
                                // Pastikan tabel ada
                                @mysqli_query($conn, "CREATE TABLE IF NOT EXISTS informasi_koperasi (
                                    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                                    judul VARCHAR(255) NOT NULL,
                                    konten TEXT NOT NULL,
                                    urutan INT DEFAULT 0,
                                    aktif TINYINT(1) DEFAULT 1,
                                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                                    KEY idx_aktif (aktif),
                                    KEY idx_urutan (urutan)
                                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                                
                                // Ambil informasi aktif
                                $infoList = [];
                                $qInfo = mysqli_query($conn, "SELECT * FROM informasi_koperasi WHERE aktif=1 ORDER BY urutan ASC, id DESC");
                                while ($row = $qInfo ? mysqli_fetch_assoc($qInfo) : null) {
                                    if ($row) $infoList[] = $row;
                                }
                                
                                if (!empty($infoList)):
                                ?>
                                    <ul class="mb-0">
                                        <?php foreach($infoList as $info): ?>
                                            <li><?= htmlspecialchars($info['konten']) ?></li>
                                        <?php endforeach; ?>
                                </ul>
                                <?php else: ?>
                                    <p class="text-muted mb-0">Belum ada informasi. Admin dapat menambahkannya di <a href="kelola_informasi.php">Kelola Informasi</a></p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="card mb-4 panel">
                            <div class="card-header">Cari Anggota</div>
                            <div class="card-body">
                                <form method="get" action="daftar_anggota_pegawai.php" class="d-flex gap-2">
                                    <input type="search" name="q" class="form-control" placeholder="Cari nama atau ID anggota">
                                    <button class="btn btn-primary">Cari</button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-6">
                        <div class="card mb-4 panel">
                            <div class="card-header">Laporan Pribadi (ringkasan)</div>
                            <div class="card-body">
                                <p>Transaksi bulan ini: <strong>--</strong></p>
                                <p>Penilaian kinerja: <strong>--</strong></p>
                                <a href="laporan_transaksi_pegawai.php" class="btn btn-outline-primary btn-sm">Lihat Laporan Transaksi</a>
                                <a href="laporan_kinerja_pegawai.php" class="btn btn-outline-secondary btn-sm">Lihat Laporan Kinerja</a>
                            </div>
                        </div>

                        <div class="card mb-4 panel">
                            <div class="card-header">Quick Actions</div>
                            <div class="card-body d-flex gap-2 quick-actions flex-wrap">
                                <a href="input_simpanan_pegawai.php" class="btn btn-success btn-sm"><i class="fas fa-piggy-bank me-1"></i> Input Simpanan</a>
                                <a href="input_pinjaman_pegawai.php" class="btn btn-warning btn-sm"><i class="fas fa-hand-holding-usd me-1"></i> Input Pinjaman</a>
                                <a href="input_angsuran_pegawai.php" class="btn btn-info btn-sm"><i class="fas fa-wallet me-1"></i> Input Angsuran</a>
                                <a href="data_anggota_pegawai.php" class="btn btn-primary btn-sm"><i class="fas fa-users me-1"></i> Data Anggota</a>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </main>

        <footer class="py-4 bg-light mt-auto">
            <div class="container-fluid px-4">
                <div class="d-flex align-items-center justify-content-between small">
                    <div class="text-muted">Copyright &copy; KSP Arthapura Sanggar Waringin 2025</div>
                </div>
            </div>
        </footer>
    </div>
</div>

<!-- Offcanvas Notifikasi -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="notifCanvas" aria-labelledby="notifCanvasLabel">
  <div class="offcanvas-header">
    <h5 id="notifCanvasLabel">Notifikasi</h5>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
  </div>
  <div class="offcanvas-body">
    <?php
    $notif_list = [];
    if (!empty($pegawai_id)) {
        $nl = mysqli_query($conn, "SELECT * FROM notifikasi WHERE pegawai_id=".(int)$pegawai_id." ORDER BY (read_at IS NULL) DESC, created_at DESC LIMIT 20");
        if ($nl) while ($row = mysqli_fetch_assoc($nl)) $notif_list[] = $row;
    }
    if (!empty($notif_list)) {
        echo '<ul class="list-group">';
        foreach ($notif_list as $n) {
            $isUnread = empty($n['read_at']);
            echo '<li class="list-group-item d-flex justify-content-between align-items-start '.($isUnread?'list-group-item-warning':'').'">';
            echo '<div class="ms-2 me-auto">'.htmlspecialchars($n['pesan']).'<div class="small text-muted">'.htmlspecialchars($n['created_at']).'</div></div>';
            if ($isUnread) {
                echo '<a class="btn btn-sm btn-outline-primary" href="read_notif.php?id='.$n['id'].'">Tandai dibaca</a>';
            }
            echo '</li>';
        }
        echo '</ul>';
    } else {
        echo '<div class="text-muted">Belum ada notifikasi.</div>';
    }
    ?>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="js/scripts.js"></script>
<!-- Page-scoped script: highlight current nav and auto-expand parent groups (dashboard only) -->
<script>
document.addEventListener('DOMContentLoaded', function(){
    try{
        var path = window.location.pathname.split('/').pop();
        // find all nav links inside the sidenav
        var sidenav = document.getElementById('sidenavAccordion');
        if(!sidenav) return;
        var links = sidenav.querySelectorAll('a.nav-link');
        links.forEach(function(a){
            // normalize href (only filename)
            var href = a.getAttribute('href') || '';
            var file = href.split('/').pop();
            if(file && file === path){
                a.classList.add('active');
                // if this link is inside a collapsed submenu, open it
                var parentCollapse = a.closest('.collapse');
                if(parentCollapse){
                    parentCollapse.classList.add('show');
                    var toggleId = parentCollapse.getAttribute('id');
                    if(toggleId){
                        var toggleBtn = sidenav.querySelector('[data-bs-target="#'+toggleId+'"], [data-bs-target=\'#'+toggleId+'\']');
                        if(toggleBtn){
                            toggleBtn.classList.remove('collapsed');
                            toggleBtn.setAttribute('aria-expanded','true');
                        }
                    }
                }
            }
        });
        // If no exact match found, keep Dashboard active
        var anyActive = sidenav.querySelector('a.nav-link.active');
        if(!anyActive){
            var dash = sidenav.querySelector('a.nav-link[href="dashboard_pegawai.php"]');
            if(dash) dash.classList.add('active');
        }
    }catch(e){ console.error('Sidebar highlight error', e); }
});
</script>
</body>
</html>
