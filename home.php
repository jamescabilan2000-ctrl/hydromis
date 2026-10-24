<?php
session_start();
// The public landing page can still be useful while the database service is
// temporarily unavailable (for example, during maintenance).  Let the page
// render its existing setup state instead of returning an HTTP 500.
define('HYDROMIS_ALLOW_DB_FAILURE', true);
// Do not expose connection paths or credentials on the public storefront.
ini_set('display_errors', '0');
require_once 'config/database.php';

// Check if database is initialized
$db_initialized = empty($conn->connect_error);
if ($db_initialized) {
    $check_table = $conn->query("SHOW TABLES LIKE 'users'");
    $db_initialized = $check_table && $check_table->num_rows > 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HydroMIS — Water Refilling Station Management</title>
    <link rel="icon" type="image/png" href="imagess/favicon-cropped.png?v=2">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;1,9..40,300&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="css/animations.css" rel="stylesheet">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --blue-deep:   #0a2540;
            --blue-mid:    #1a56db;
            --blue-light:  #3b82f6;
            --aqua:        #06b6d4;
            --teal:        #0d9488;
            --surface:     #ffffff;
            --surface-2:   #f6f9fc;
            --surface-3:   #eef2f7;
            --text-1:      #0a2540;
            --text-2:      #4b5e73;
            --text-3:      #8a9bb0;
            --border:      #d8e3ed;
            --shadow-sm:   0 1px 4px rgba(10,37,64,.06);
            --shadow-md:   0 4px 16px rgba(10,37,64,.09);
            --shadow-lg:   0 16px 48px rgba(10,37,64,.13);
        }

        html { scroll-behavior: smooth; }

        body {
            font-family: 'DM Sans', sans-serif;
            color: var(--text-1);
            background: var(--surface);
            -webkit-font-smoothing: antialiased;
            overflow-x: hidden;
        }

        h1,h2,h3,h4,h5 { font-family: 'Sora', sans-serif; }

        /* ─── NAV ──────────────────────────────── */
        nav {
            position: sticky;
            top: 0;
            z-index: 100;
            background: rgba(255,255,255,.88);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
            border-bottom: 1px solid var(--border);
        }

        .nav-inner {
            max-width: 1160px;
            margin: 0 auto;
            padding: 0 24px;
            height: 64px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }

        .logo-icon {
            width: 36px;
            height: 36px;
            background: transparent;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 16px;
            flex-shrink: 0;
        }

        .logo-text {
            font-family: 'Sora', sans-serif;
            font-weight: 800;
            font-size: 1.35rem;
            color: var(--blue-deep);
            letter-spacing: -.02em;
        }

        .logo-text span { color: var(--blue-mid); }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .nav-links a, .nav-links button {
            font-family: 'DM Sans', sans-serif;
            font-size: .875rem;
            font-weight: 500;
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            transition: all .18s ease;
            cursor: pointer;
            border: none;
            white-space: nowrap;
        }

        .btn-ghost {
            color: var(--text-2);
            background: transparent;
        }
        .btn-ghost:hover {
            color: var(--text-1);
            background: var(--surface-3);
        }

        .btn-outline {
            color: var(--blue-mid);
            background: transparent;
            border: 1.5px solid var(--blue-mid) !important;
        }
        .btn-outline:hover {
            background: #eff6ff;
        }

        .btn-solid {
            color: #fff;
            background: var(--blue-mid);
            box-shadow: 0 2px 8px rgba(26,86,219,.28);
        }
        .btn-solid:hover {
            background: #1648c0;
            box-shadow: 0 4px 14px rgba(26,86,219,.38);
            transform: translateY(-1px);
        }

        /* ─── HERO ──────────────────────────────── */
        .hero {
            position: relative;
            min-height: calc(100vh - 64px);
            display: flex;
            align-items: center;
            overflow: hidden;
            background: var(--blue-deep);
        }

        .hero-bg {
            position: absolute;
            inset: 0;
            background:
                linear-gradient(90deg,rgba(2,18,38,.82) 0%,rgba(3,29,55,.7) 45%,rgba(2,26,50,.6) 100%),
                linear-gradient(180deg,rgba(1,15,34,.15),rgba(1,20,39,.58)),
                url('imagess/home-water-station-hero-v2.png') center 48% / cover no-repeat;
            transform:scale(1.035);
            filter:saturate(1.04) brightness(.92);
            animation:heroWaterPan 18s ease-in-out infinite alternate;
            transition:filter 1s ease,transform 1.2s cubic-bezier(.2,.8,.2,1);
            will-change:background-position,transform;
        }
        .hero-bg::after{content:'';position:absolute;inset:-25%;pointer-events:none;background:linear-gradient(112deg,transparent 38%,rgba(128,232,255,.11) 49%,transparent 60%);transform:translateX(-45%) rotate(3deg);animation:heroWaterShimmer 9s ease-in-out infinite}
        .hero:hover .hero-bg{filter:saturate(1.11) brightness(.98);transform:scale(1.055)}
        @keyframes heroWaterPan{0%{background-position:center,center,44% 47%;transform:scale(1.035)}50%{background-position:center,center,50% 51%;transform:scale(1.055)}100%{background-position:center,center,57% 46%;transform:scale(1.04)}}
        @keyframes heroWaterShimmer{0%,18%{opacity:0;transform:translateX(-48%) rotate(3deg)}48%{opacity:1}78%,100%{opacity:0;transform:translateX(48%) rotate(3deg)}}

        /* Animated water circles */
        .water-ring {
            position: absolute;
            border-radius: 50%;
            border: 1px solid rgba(6,182,212,.15);
            animation: expandRing 8s linear infinite;
            pointer-events: none;
        }
        .water-ring:nth-child(1) { width: 300px; height: 300px; top: 20%; right: 15%; animation-delay: 0s; }
        .water-ring:nth-child(2) { width: 500px; height: 500px; top: 10%; right: 10%; animation-delay: 2s; }
        .water-ring:nth-child(3) { width: 700px; height: 700px; top: 0; right: 5%; animation-delay: 4s; }

        @keyframes expandRing {
            0%   { opacity: .5; transform: scale(.85); }
            100% { opacity: 0; transform: scale(1.1); }
        }

        /* Floating drops */
        .drop {
            position: absolute;
            border-radius: 50% 50% 50% 50% / 60% 60% 40% 40%;
            background: rgba(6,182,212,.12);
            border: 1px solid rgba(6,182,212,.2);
            animation: floatDrop 12s ease-in-out infinite;
            pointer-events: none;
        }
        .drop:nth-child(4)  { width: 24px; height: 30px; top: 20%; left: 8%;  animation-delay: 0s;  animation-duration: 10s; }
        .drop:nth-child(5)  { width: 16px; height: 20px; top: 55%; left: 12%; animation-delay: 3s;  animation-duration: 13s; }
        .drop:nth-child(6)  { width: 20px; height: 26px; top: 70%; left: 5%;  animation-delay: 6s;  animation-duration: 11s; }
        .drop:nth-child(7)  { width: 12px; height: 16px; top: 35%; right: 30%; animation-delay: 1.5s; animation-duration: 9s; }

        @keyframes floatDrop {
            0%,100% { transform: translateY(0) rotate(0deg); opacity: .6; }
            50%      { transform: translateY(-24px) rotate(8deg); opacity: 1; }
        }

        .hero-content {
            position: relative;
            z-index: 2;
            max-width: 1160px;
            margin: 0 auto;
            padding: 80px 24px;
            display: grid;
            grid-template-columns: minmax(0, 760px);
            justify-content: center;
            align-items: center;
        }

        .hero-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(6,182,212,.12);
            border: 1px solid rgba(6,182,212,.25);
            color: var(--aqua);
            font-size: .8rem;
            font-weight: 600;
            letter-spacing: .08em;
            text-transform: uppercase;
            padding: 6px 14px;
            border-radius: 100px;
            margin-bottom: 24px;
        }

        .hero-title {
            font-size: clamp(2.2rem, 4vw, 3.4rem);
            font-weight: 800;
            color: #fff;
            line-height: 1.1;
            letter-spacing: -.03em;
            margin-bottom: 20px;
        }

        .hero-title .accent {
            background: linear-gradient(90deg, var(--aqua), var(--blue-light));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .hero-desc {
            font-size: 1.05rem;
            line-height: 1.7;
            color: rgba(255,255,255,.65);
            margin-bottom: 36px;
            max-width: 480px;
        }

        .hero-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .hero-actions a {
            font-family: 'DM Sans', sans-serif;
            font-size: .95rem;
            font-weight: 600;
            padding: 13px 26px;
            border-radius: 10px;
            text-decoration: none;
            transition: all .2s ease;
        }

        .hero-btn-primary {
            background: linear-gradient(135deg, var(--blue-mid), var(--aqua));
            color: #fff;
            box-shadow: 0 4px 20px rgba(6,182,212,.35);
        }
        .hero-btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 28px rgba(6,182,212,.45);
        }

        .hero-btn-secondary {
            background: rgba(255,255,255,.08);
            color: rgba(255,255,255,.9);
            border: 1.5px solid rgba(255,255,255,.2);
        }
        .hero-btn-secondary:hover {
            background: rgba(255,255,255,.14);
            border-color: rgba(255,255,255,.35);
        }

        .hero-stats {
            display: flex;
            gap: 32px;
            margin-top: 48px;
            padding-top: 32px;
            border-top: 1px solid rgba(255,255,255,.1);
        }

        .hero-stat-num {
            font-family: 'Sora', sans-serif;
            font-size: 1.6rem;
            font-weight: 800;
            color: #fff;
            letter-spacing: -.03em;
        }

        .hero-stat-num span { color: var(--aqua); }

        .hero-stat-label {
            font-size: .8rem;
            color: rgba(255,255,255,.45);
            margin-top: 2px;
        }

        /* Hero visual panel */
        .hero-visual {
            position: relative;
        }

        .app-mockup {
            background: rgba(255,255,255,.05);
            border: 1px solid rgba(255,255,255,.1);
            border-radius: 24px;
            padding: 24px;
            backdrop-filter: blur(12px);
            box-shadow: 0 32px 64px rgba(0,0,0,.4);
        }

        .mockup-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .mockup-dots {
            display: flex;
            gap: 6px;
        }

        .mockup-dots span {
            width: 10px;
            height: 10px;
            border-radius: 50%;
        }

        .dot-red    { background: #ff5f57; }
        .dot-yellow { background: #febc2e; }
        .dot-green  { background: #28c840; }

        .mockup-title {
            font-size: .75rem;
            color: rgba(255,255,255,.4);
            font-weight: 500;
        }

        .mockup-stat-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 16px;
        }

        .mockup-stat-card {
            background: rgba(255,255,255,.06);
            border: 1px solid rgba(255,255,255,.08);
            border-radius: 14px;
            padding: 16px;
        }

        .mockup-stat-card .label {
            font-size: .72rem;
            color: rgba(255,255,255,.4);
            margin-bottom: 6px;
        }

        .mockup-stat-card .value {
            font-family: 'Sora', sans-serif;
            font-size: 1.4rem;
            font-weight: 700;
            color: #fff;
        }

        .mockup-stat-card .badge {
            font-size: .68rem;
            padding: 2px 8px;
            border-radius: 100px;
            margin-left: 6px;
            font-weight: 600;
        }

        .badge-up { background: rgba(16,185,129,.2); color: #10b981; }
        .badge-pending { background: rgba(251,191,36,.2); color: #f59e0b; }

        .mockup-order-list { display: flex; flex-direction: column; gap: 8px; }

        .mockup-order-item {
            display: flex;
            align-items: center;
            gap: 12px;
            background: rgba(255,255,255,.05);
            border: 1px solid rgba(255,255,255,.07);
            border-radius: 10px;
            padding: 11px 14px;
        }

        .order-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .7rem;
            font-weight: 700;
            color: #fff;
            flex-shrink: 0;
        }

        .order-info { flex: 1; min-width: 0; }
        .order-name { font-size: .8rem; font-weight: 600; color: rgba(255,255,255,.9); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .order-sub  { font-size: .7rem; color: rgba(255,255,255,.4); margin-top: 1px; }

        .order-status {
            font-size: .68rem;
            font-weight: 600;
            padding: 3px 10px;
            border-radius: 100px;
            flex-shrink: 0;
        }

        .status-delivered { background: rgba(16,185,129,.18); color: #10b981; }
        .status-transit   { background: rgba(59,130,246,.18); color: #60a5fa; }
        .status-pending   { background: rgba(251,191,36,.18);  color: #fbbf24; }

        /* Floating badge */
        .floating-badge {
            position: absolute;
            top: -16px;
            right: -16px;
            background: linear-gradient(135deg, var(--teal), var(--aqua));
            color: #fff;
            border-radius: 14px;
            padding: 12px 16px;
            box-shadow: 0 8px 24px rgba(13,148,136,.4);
            font-size: .75rem;
        }

        .floating-badge .big { font-family: 'Sora', sans-serif; font-size: 1.3rem; font-weight: 800; display: block; }

        .floating-badge-2 {
            position: absolute;
            bottom: -16px;
            left: -16px;
            background: rgba(10,37,64,.9);
            border: 1px solid rgba(255,255,255,.12);
            color: #fff;
            border-radius: 14px;
            padding: 10px 14px;
            backdrop-filter: blur(10px);
            box-shadow: var(--shadow-lg);
            font-size: .75rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .pulse-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #10b981;
            flex-shrink: 0;
            animation: pulse 2s ease infinite;
        }

        @keyframes pulse {
            0%,100% { box-shadow: 0 0 0 0 rgba(16,185,129,.5); }
            50%      { box-shadow: 0 0 0 6px rgba(16,185,129,0); }
        }

        /* ─── LOGOS / TRUST BAR ─────────────────── */
        .trust-bar {
            background: var(--surface-2);
            border-top: 1px solid var(--border);
            border-bottom: 1px solid var(--border);
            padding: 28px 24px;
        }

        .trust-inner {
            max-width: 1160px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            flex-wrap: wrap;
            text-align: center;
        }

        .trust-label {
            font-size: .8rem;
            color: var(--text-3);
            font-weight: 500;
            letter-spacing: .04em;
            text-transform: uppercase;
            flex-basis: 100%;
            margin-bottom: 8px;
        }

        .trust-item {
            display: flex;
            align-items: center;
            gap: 8px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 100px;
            padding: 8px 18px;
            font-size: .82rem;
            font-weight: 600;
            color: var(--text-2);
            box-shadow: var(--shadow-sm);
        }

        .trust-item i { color: var(--blue-mid); font-size: .9rem; }

        /* ─── SECTION SHARED ────────────────────── */
        section { padding: 96px 24px; }

        .section-inner { max-width: 1160px; margin: 0 auto; }

        .section-tag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: .75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: var(--blue-mid);
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            padding: 5px 12px;
            border-radius: 100px;
            margin-bottom: 16px;
        }

        .section-title {
            font-size: clamp(1.8rem, 3vw, 2.6rem);
            font-weight: 800;
            letter-spacing: -.03em;
            color: var(--text-1);
            line-height: 1.15;
            margin-bottom: 14px;
        }

        .section-sub {
            font-size: 1.05rem;
            color: var(--text-2);
            line-height: 1.65;
            max-width: 560px;
        }

        .section-header { margin-bottom: 56px; }
        .section-header.center { text-align: center; }
        .section-header.center .section-sub { margin: 0 auto; }

        /* ─── FEATURES / ROLE CARDS ─────────────── */
        .cards-3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 24px;
        }

        .role-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 32px;
            transition: all .22s ease;
            position: relative;
            overflow: hidden;
        }

        .role-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            border-radius: 20px 20px 0 0;
            opacity: 0;
            transition: opacity .22s ease;
        }

        .role-card:hover {
            box-shadow: var(--shadow-lg);
            transform: translateY(-4px);
            border-color: transparent;
        }

        .role-card:hover::before { opacity: 1; }

        .role-card.blue::before  { background: linear-gradient(90deg, var(--blue-mid), var(--aqua)); }
        .role-card.green::before { background: linear-gradient(90deg, #059669, #34d399); }
        .role-card.purple::before{ background: linear-gradient(90deg, #7c3aed, #a78bfa); }

        .role-icon {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            margin-bottom: 20px;
            flex-shrink: 0;
        }

        .icon-blue   { background: #eff6ff; color: var(--blue-mid); }
        .icon-green  { background: #ecfdf5; color: #059669; }
        .icon-purple { background: #f5f3ff; color: #7c3aed; }

        .role-card h3 {
            font-size: 1.15rem;
            font-weight: 700;
            margin-bottom: 6px;
            letter-spacing: -.02em;
        }

        .role-card .subtitle {
            font-size: .82rem;
            color: var(--text-3);
            margin-bottom: 20px;
        }

        .feature-list {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .feature-list li {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: .875rem;
            color: var(--text-2);
        }

        .check-icon {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .6rem;
            flex-shrink: 0;
        }

        .check-blue   { background: #eff6ff; color: var(--blue-mid); }
        .check-green  { background: #ecfdf5; color: #059669; }
        .check-purple { background: #f5f3ff; color: #7c3aed; }

        .card-cta {
            margin-top: 28px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: .875rem;
            font-weight: 600;
            text-decoration: none;
            transition: gap .18s ease;
            padding: 10px 20px;
            border-radius: 10px;
        }

        .card-cta-blue   { color: var(--blue-mid);  background: #eff6ff; border: 1px solid #bfdbfe; }
        .card-cta-green  { color: #059669;           background: #ecfdf5; border: 1px solid #a7f3d0; }
        .card-cta-purple { color: #7c3aed;           background: #f5f3ff; border: 1px solid #ddd6fe; }

        .card-cta:hover { gap: 14px; }

        /* ─── HOW IT WORKS ──────────────────────── */
        .how-section { background: var(--surface-2); }

        .steps-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0;
            position: relative;
        }

        .steps-grid::before {
            content: '';
            position: absolute;
            top: 36px;
            left: calc(12.5% + 20px);
            right: calc(12.5% + 20px);
            height: 2px;
            background: linear-gradient(90deg, var(--blue-mid), var(--aqua));
            z-index: 0;
        }

        .step-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            padding: 0 20px;
            position: relative;
            z-index: 1;
        }

        .step-num {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Sora', sans-serif;
            font-size: 1.3rem;
            font-weight: 800;
            margin-bottom: 20px;
            position: relative;
            background: var(--surface);
            border: 2px solid var(--border);
            color: var(--text-3);
            transition: all .3s ease;
        }

        .step-item.active .step-num {
            background: linear-gradient(135deg, var(--blue-mid), var(--aqua));
            background-repeat: no-repeat;
            background-origin: border-box;
            border: 0;
            color: #fff;
            box-shadow: 0 8px 24px rgba(26,86,219,.3);
        }

        .step-title {
            font-size: .95rem;
            font-weight: 700;
            margin-bottom: 8px;
            color: var(--text-1);
        }

        .step-desc {
            font-size: .82rem;
            color: var(--text-2);
            line-height: 1.6;
        }

        /* ─── PREMIUM FEATURES ──────────────────── */
        .features-section { background: var(--surface); }

        .features-2col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }

        .feat-card {
            background: var(--surface-2);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 28px;
            display: flex;
            gap: 20px;
            align-items: flex-start;
            transition: all .22s ease;
        }

        .feat-card:hover {
            background: var(--surface);
            box-shadow: var(--shadow-md);
            border-color: #bfdbfe;
            transform: translateY(-2px);
        }

        .feat-icon {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--blue-mid), var(--aqua));
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 1.1rem;
            flex-shrink: 0;
        }

        .feat-card h4 {
            font-size: 1rem;
            font-weight: 700;
            margin-bottom: 8px;
            letter-spacing: -.015em;
        }

        .feat-card p {
            font-size: .875rem;
            color: var(--text-2);
            line-height: 1.6;
        }

        /* ─── CTA SECTION ───────────────────────── */
        .cta-section {
            background: var(--blue-deep);
            position: relative;
            overflow: hidden;
            padding: 96px 24px;
        }

        .cta-bg {
            position: absolute;
            inset: 0;
            background:
                radial-gradient(ellipse 70% 70% at 80% 50%, rgba(6,182,212,.15) 0%, transparent 65%),
                radial-gradient(ellipse 50% 80% at 10% 50%, rgba(26,86,219,.18) 0%, transparent 60%);
        }

        .cta-inner {
            position: relative;
            z-index: 2;
            max-width: 680px;
            margin: 0 auto;
            text-align: center;
        }

        .cta-inner h2 {
            font-size: clamp(2rem, 4vw, 3rem);
            font-weight: 800;
            color: #fff;
            margin-bottom: 16px;
            letter-spacing: -.03em;
        }

        .cta-inner p {
            font-size: 1.05rem;
            color: rgba(255,255,255,.6);
            margin-bottom: 40px;
            line-height: 1.65;
        }

        .cta-actions {
            display: flex;
            gap: 14px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .cta-actions a {
            font-family: 'DM Sans', sans-serif;
            font-size: .95rem;
            font-weight: 600;
            padding: 13px 28px;
            border-radius: 10px;
            text-decoration: none;
            transition: all .2s ease;
        }

        .cta-btn-primary {
            background: linear-gradient(135deg, var(--blue-mid), var(--aqua));
            color: #fff;
            box-shadow: 0 4px 20px rgba(6,182,212,.3);
        }

        .cta-btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 28px rgba(6,182,212,.4);
        }

        .cta-btn-secondary {
            background: rgba(255,255,255,.08);
            color: rgba(255,255,255,.85);
            border: 1.5px solid rgba(255,255,255,.18);
        }

        .cta-btn-secondary:hover {
            background: rgba(255,255,255,.14);
        }

        /* ─── FOOTER ────────────────────────────── */
        footer {
            background: #060f1e;
            color: rgba(255,255,255,.5);
            padding: 48px 24px 32px;
        }

        .footer-inner {
            max-width: 1160px;
            margin: 0 auto;
        }

        .footer-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 40px;
            padding-bottom: 40px;
            border-bottom: 1px solid rgba(255,255,255,.07);
            flex-wrap: wrap;
        }

        .footer-brand { max-width: 280px; }

        .footer-logo {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 14px;
        }

        .footer-logo .logo-icon { width: 32px; height: 32px; font-size: 13px; }

        .footer-logo-text {
            font-family: 'Sora', sans-serif;
            font-weight: 800;
            font-size: 1.2rem;
            color: #fff;
        }

        .footer-brand p {
            font-size: .855rem;
            line-height: 1.6;
            color: rgba(255,255,255,.4);
        }

        .footer-links-group h5 {
            font-family: 'Sora', sans-serif;
            font-size: .8rem;
            font-weight: 700;
            color: rgba(255,255,255,.7);
            text-transform: uppercase;
            letter-spacing: .07em;
            margin-bottom: 16px;
        }

        .footer-links-group a {
            display: block;
            font-size: .875rem;
            color: rgba(255,255,255,.45);
            text-decoration: none;
            margin-bottom: 10px;
            transition: color .15s ease;
        }

        .footer-links-group a:hover { color: rgba(255,255,255,.85); }

        .footer-bottom {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 28px;
            font-size: .8rem;
            flex-wrap: wrap;
            gap: 12px;
        }

        /* ─── MOBILE MENU ───────────────────────── */
        .mobile-burger {
            display: none;
            align-items: center;
            justify-content: center;
            width: 44px;
            height: 44px;
            border: 0;
            background: transparent;
            cursor: pointer;
            color: var(--text-2);
            font-size: 1rem;
            box-shadow: none;
            transition: transform .18s ease,color .18s ease;
        }
        .mobile-burger:hover{color:var(--blue-mid);background:transparent;transform:translateY(-1px);box-shadow:none}
        .mobile-burger:active{transform:scale(.96)}

        .mobile-overlay {
            position: fixed;
            inset: 0;
            background: rgba(10,37,64,.5);
            z-index: 200;
            opacity: 0;
            pointer-events: none;
            transition: opacity .25s ease;
        }

        .mobile-drawer {
            position: absolute;
            top: 0;
            right: 0;
            width: min(88vw, 340px);
            height: 100%;
            background: #fff;
            transform: translateX(100%);
            transition: transform .25s ease;
            overflow-y: auto;
            box-shadow: -20px 0 60px rgba(10,37,64,.2);
        }

        .mobile-drawer-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 20px;
            border-bottom: 1px solid var(--border);
        }

        .mobile-nav-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 14px 20px;
            font-size: .95rem;
            font-weight: 600;
            color: var(--text-1);
            text-decoration: none;
            border-bottom: 1px solid var(--surface-3);
            transition: background .15s ease;
        }

        .mobile-nav-item:hover { background: var(--surface-2); }
        .mobile-nav-item i { width: 22px; color: var(--blue-mid); text-align: center; }

        .mobile-nav-cta {
            margin: 20px;
            display: block;
            text-align: center;
            background: linear-gradient(135deg, var(--blue-mid), var(--aqua));
            color: #fff;
            font-size: .95rem;
            font-weight: 600;
            padding: 13px;
            border-radius: 10px;
            text-decoration: none;
        }

        body.nav-open .mobile-overlay { opacity: 1; pointer-events: auto; }
        body.nav-open .mobile-drawer  { transform: translateX(0); }
        body.nav-open { overflow: hidden; }

        /* ─── RESPONSIVE ────────────────────────── */
        @media (max-width: 960px) {
            .nav-links { display: flex; }
            .mobile-burger { display: none; }
            .nav-inner { padding: 0 20px; }
            .hero-content { grid-template-columns: 1fr; gap: 48px; padding: 60px 24px; }
            .hero-visual  { display: none; }
            .cards-3      { grid-template-columns: 1fr 1fr; }
            .steps-grid   { grid-template-columns: 1fr 1fr; gap: 32px; }
            .steps-grid::before { display: none; }
        }

        /* ─── MOBILE (≤640px) ───────────────────── */
        @media (max-width: 640px) {

            /* Global */
            section { padding: 56px 18px; }
            /* Nav */
            nav{position:sticky}
            .nav-inner { width:100%;height:auto;min-height:112px;padding:10px 0 0;gap:8px;flex-wrap:wrap;align-content:center; }
            .logo{width:calc(100% - 32px);margin:0 16px}
            .logo-text  { font-size: 1.2rem; }
            .logo-icon  { width: 36px; height: 36px; font-size: 14px;border-radius:11px; }
            .mobile-burger { display:none; }
            .nav-links{order:2;width:100%;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:2px;padding:7px 10px 9px;overflow:visible;border-top:1px solid rgba(216,227,237,.72)}
            .nav-links::-webkit-scrollbar{display:none}
            .nav-links a,.nav-links button{min-width:0;padding:8px 4px;font-size:.78rem;border-radius:8px;text-align:center;white-space:nowrap}
            .nav-links .btn-outline{border-width:1px!important}
            .nav-links .btn-solid{box-shadow:0 2px 7px rgba(26,86,219,.2)}
            .mobile-drawer{width:min(88vw,340px);padding-bottom:env(safe-area-inset-bottom)}

            /* Hero — tighter, thumb-friendly */
            .hero { min-height: auto; }
            .hero-bg{background:linear-gradient(180deg,rgba(2,18,38,.7),rgba(2,25,48,.82)),url('imagess/home-water-station-hero-v2.png') center 48% / cover no-repeat;}
            .hero-content {
                padding: 52px 18px 60px;
                gap: 32px;
                text-align: center;
            }
            .hero-visual { display: none; }
            .hero-eyebrow { font-size: .72rem; padding: 5px 12px; }
            .hero-title   { font-size: 2rem; letter-spacing: -.025em; }
            .hero-desc    { font-size: .95rem; margin: 0 auto 28px; }
            .hero-actions {
                flex-direction: column;
                gap: 10px;
                align-items: stretch;
            }
            .hero-actions a {
                text-align: center;
                padding: 15px 20px;
                font-size: 1rem;
                border-radius: 12px;
            }
            .hero-stats {
                justify-content: center;
                gap: 0;
                flex-wrap: nowrap;
                border-top: 1px solid rgba(255,255,255,.1);
                margin-top: 36px;
                padding-top: 28px;
            }
            .hero-stats > div {
                flex: 1;
                text-align: center;
                padding: 0 10px;
                border-right: 1px solid rgba(255,255,255,.1);
            }
            .hero-stats > div:last-child { border-right: none; }
            .hero-stat-num  { font-size: 1.4rem; }
            .hero-stat-label { font-size: .72rem; }

            /* Trust bar — horizontal scroll on mobile */
            .trust-bar { padding: 20px 0; overflow: hidden; }
            .trust-inner {
                flex-wrap: nowrap;
                justify-content: flex-start;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                scrollbar-width: none;
                padding: 8px 18px;
                gap: 8px;
                cursor: grab;
                user-select: none;
                touch-action: pan-x pan-y pinch-zoom;
            }
            .trust-inner.is-dragging { cursor: grabbing; scroll-snap-type: none; }
            .trust-inner::-webkit-scrollbar { display: none; }
            .trust-label { flex: 0 0 auto; white-space: nowrap; margin-bottom: 0; }
            .trust-item  { flex-shrink: 0; white-space: nowrap; font-size: .78rem; padding: 7px 14px; }

            /* Section headers */
            .section-title { font-size: 1.65rem; letter-spacing: -.025em; }
            .section-sub   { font-size: .9rem; }
            .section-header { margin-bottom: 36px; }

            /* Role cards — horizontal scroll carousel */
            .cards-3 {
                display: flex;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                scrollbar-width: none;
                gap: 14px;
                padding: 4px 18px 16px;
                margin: 0 -18px;
                scroll-snap-type: x mandatory;
                scroll-behavior: smooth;
                cursor: grab;
                user-select: none;
                touch-action: pan-x pan-y;
            }
            .cards-3.is-dragging { cursor: grabbing; scroll-snap-type: none; scroll-behavior: auto; }
            .cards-3::-webkit-scrollbar { display: none; }
            .role-card {
                flex: 0 0 82vw;
                max-width: 300px;
                scroll-snap-align: start;
                border-radius: 18px;
            }
            .role-card:hover { transform: none; } /* disable hover lift on touch */
            .card-cta { width: 100%; justify-content: center; margin-top: 24px; padding: 12px 20px; }

            /* Scroll hint dots */
            .cards-scroll-hint {
                display: flex !important;
                justify-content: center;
                gap: 6px;
                margin-top: 16px;
            }
            .scroll-dot {
                width: 6px;
                height: 6px;
                border-radius: 50%;
                background: var(--border);
                border: 0;
                padding: 0;
                cursor: pointer;
                transition: width .25s ease, background .2s, transform .2s;
            }
            .scroll-dot:hover { transform: scale(1.25); }
            .scroll-dot:focus-visible { outline: 2px solid var(--blue-mid); outline-offset: 3px; }
            .scroll-dot.active { background: var(--blue-mid); width: 18px; border-radius: 3px; }

            /* How it works — vertical on mobile */
            .steps-grid {
                display: flex;
                flex-direction: column;
                gap: 0;
            }
            .step-item {
                flex-direction: row;
                text-align: left;
                align-items: flex-start;
                gap: 14px;
                padding: 0 0 24px 0;
                position: relative;
            }
            .step-item:not(:last-child)::after {
                content: '';
                position: absolute;
                left: 23px;
                top: 48px;
                bottom: 0;
                width: 2px;
                background: linear-gradient(to bottom, var(--blue-mid), var(--aqua));
                opacity: .3;
            }
            .step-num { margin-bottom: 0; flex-shrink: 0; width: 48px; height: 48px; font-size: 1rem; }
            .step-item.active .step-num { box-shadow: 0 4px 12px rgba(26,86,219,.18); }
            .step-item:last-child { padding-bottom: 0; }
            .step-text { min-width: 0; padding-top: 3px; }
            .step-title { font-size: .95rem; }
            .step-desc  { font-size: .82rem; }

            /* Features 2col → 1col */
            .features-2col { grid-template-columns: 1fr; gap: 14px; }
            .feat-card { padding: 20px; gap: 16px; border-radius: 16px; }
            .feat-icon { width: 42px; height: 42px; font-size: 1rem; flex-shrink: 0; }
            .feat-card h4 { font-size: .95rem; margin-bottom: 6px; }
            .feat-card p  { font-size: .835rem; }

            /* CTA */
            .cta-section { padding: 72px 18px; }
            .cta-inner h2  { font-size: 1.9rem; letter-spacing: -.025em; }
            .cta-inner p   { font-size: .9rem; margin-bottom: 32px; }
            .cta-actions {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
            }
            .cta-actions a {
                text-align: center;
                padding: 15px 20px;
                font-size: 1rem;
                border-radius: 12px;
            }

            /* Footer */
            footer { padding: 40px 18px 100px; } /* extra bottom for sticky bar */
            .footer-top { flex-direction: column; gap: 28px; }
            .footer-brand { max-width: 100%; }
            .footer-links-cols {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 24px;
                width: 100%;
            }
            .footer-bottom {
                flex-direction: column;
                text-align: center;
                gap: 6px;
            }

            /* ── Sticky bottom CTA bar ── */
            /* Mobile drawer improvements */
            .mobile-drawer { width: min(92vw, 360px); }
            .mobile-nav-item { padding: 16px 20px; font-size: 1rem; }
            .mobile-nav-cta  { margin: 16px; padding: 15px; font-size: 1rem; border-radius: 12px; }
        }

        /* Very small phones */
        @media (max-width: 380px) {
            .hero-title  { font-size: 1.75rem; }
            .role-card   { flex: 0 0 90vw; }
            .hero-stats  { gap: 0; }
            .hero-stat-num { font-size: 1.2rem; }
        }

        /* ─── ANIMATIONS ────────────────────────── */
        .fade-up {
            opacity: 0;
            transform: translateY(28px);
            transition: opacity .6s ease, transform .6s ease;
        }

        .fade-up.visible {
            opacity: 1;
            transform: translateY(0);
        }

        .fade-up:nth-child(2) { transition-delay: .1s; }
        .fade-up:nth-child(3) { transition-delay: .2s; }
        .fade-up:nth-child(4) { transition-delay: .3s; }

        /* Premium interaction layer */
        :root {
            --motion-fast: 180ms;
            --motion-normal: 360ms;
            --motion-slow: 760ms;
            --ease-premium: cubic-bezier(.22,1,.36,1);
        }
        @media (max-width:360px){.logo-text{font-size:1.05rem}.logo{width:calc(100% - 24px);gap:8px;margin-left:12px;margin-right:12px}.nav-links{padding-left:6px;padding-right:6px}.nav-links a,.nav-links button{font-size:.69rem;padding:7px 2px;letter-spacing:-.15px}}
        nav { animation: navReveal .65s var(--ease-premium) both; }
        .logo-icon { box-shadow: none; }
        .nav-links a, .mobile-burger, .hero-actions a {
            outline: none;
            transition: transform var(--motion-fast) ease, box-shadow var(--motion-fast) ease,
                        background-color var(--motion-fast) ease, border-color var(--motion-fast) ease;
        }
        a:focus-visible, button:focus-visible {
            outline: 3px solid rgba(6,182,212,.42);
            outline-offset: 3px;
        }
        .hero::after {
            content: '';
            position: absolute;
            width: 520px;
            height: 520px;
            left: 8%;
            top: 12%;
            border-radius: 50%;
            background: rgba(6,182,212,.13);
            filter: blur(110px);
            pointer-events: none;
            animation: heroGlow 8s ease-in-out infinite alternate;
        }
        .hero-left > * { opacity: 0; animation: heroReveal .8s var(--ease-premium) forwards; }
        .hero-eyebrow { animation-delay: .12s; }
        .hero-title { animation-delay: .23s; }
        .hero-desc { animation-delay: .34s; }
        .hero-actions { animation-delay: .45s; }
        .hero-stats { animation-delay: .58s; }
        .hero-visual { opacity: 0; animation: visualReveal 1s .35s var(--ease-premium) forwards; }
        .app-mockup { animation: mockupFloat 6s 1.3s ease-in-out infinite; }
        .hero-btn-primary {
            position: relative;
            overflow: hidden;
            background-size: 180% 180%;
            animation: gradientDrift 6s ease infinite;
        }
        .hero-btn-primary::before {
            content: '';
            position: absolute;
            inset: 0;
            transform: translateX(-120%) skewX(-18deg);
            background: linear-gradient(90deg,transparent,rgba(255,255,255,.26),transparent);
            transition: transform .7s var(--ease-premium);
        }
        .hero-btn-primary:hover::before { transform: translateX(120%) skewX(-18deg); }
        .hero-btn-primary i { transition: transform var(--motion-fast) ease; }
        .hero-btn-primary:hover i { transform: translateX(4px); }
        .hero-btn-secondary { backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); }
        .hero-actions a:active { transform: scale(.98); }
        .hero-scroll-hint {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 22px;
            color: rgba(255,255,255,.45);
            font-size: .76rem;
            text-decoration: none;
            opacity: 0;
            animation: heroReveal .7s .72s var(--ease-premium) forwards;
        }
        .hero-scroll-hint i { color: var(--aqua); animation: scrollNudge 1.8s ease-in-out infinite; }
        @keyframes navReveal { from { opacity:0; transform:translateY(-14px); } to { opacity:1; transform:none; } }
        @keyframes heroReveal { from { opacity:0; transform:translateY(22px); } to { opacity:1; transform:none; } }
        @keyframes visualReveal { from { opacity:0; transform:translate(24px,18px) scale(.97); } to { opacity:1; transform:none; } }
        @keyframes mockupFloat { 0%,100% { transform:translateY(0); } 50% { transform:translateY(-9px); } }
        @keyframes heroGlow { from { transform:translate(-5%,-3%) scale(.9); opacity:.55; } to { transform:translate(8%,5%) scale(1.12); opacity:1; } }
        @keyframes gradientDrift { 0%,100% { background-position:0 50%; } 50% { background-position:100% 50%; } }
        @keyframes scrollNudge { 0%,100% { transform:translateY(0); } 50% { transform:translateY(4px); } }
        @media (max-width:640px) {
            nav { background:rgba(255,255,255,.93); box-shadow:0 8px 30px rgba(10,37,64,.08); }
            .hero-content { padding-top:64px; }
            .hero-title { font-size:clamp(1.85rem,8.4vw,2.25rem); line-height:1.12; }
            .hero-eyebrow { margin-bottom:20px; }
            .hero-actions a { min-height:56px; display:flex; align-items:center; justify-content:center; }
            .hero-scroll-hint { justify-content:center; }
        }
        /* Premium water-glass navigation */
        nav{padding:10px 14px;background:linear-gradient(180deg,rgba(3,20,39,.92),rgba(3,27,51,.76));border-bottom:0;transition:padding .35s ease,background .35s ease,box-shadow .35s ease}
        nav::after{content:'';position:absolute;left:8%;right:8%;bottom:0;height:1px;background:linear-gradient(90deg,transparent,rgba(91,224,255,.72),transparent);opacity:.65}
        nav.nav-scrolled{padding-top:6px;padding-bottom:6px;background:rgba(3,20,39,.94);box-shadow:0 14px 34px rgba(1,13,29,.28)}
        .nav-inner{height:66px;max-width:1180px;padding:0 18px;border:1px solid rgba(145,226,255,.16);border-radius:18px;background:linear-gradient(120deg,rgba(13,53,83,.7),rgba(6,33,61,.58));box-shadow:inset 0 1px rgba(255,255,255,.08),0 12px 32px rgba(0,10,25,.2);transition:height .35s ease,background .35s ease,border-color .35s ease}
        nav.nav-scrolled .nav-inner{height:56px;background:rgba(8,38,67,.74);border-color:rgba(145,226,255,.23)}
        .logo{transition:transform .3s ease}.logo:hover{transform:translateY(-1px)}.logo-text{color:#f3f9ff}.logo-text span{color:#55d9ff}
        .nav-links{gap:4px;margin-left:auto}.nav-links a,.nav-links button{position:relative;color:#c4d7e8;background:transparent;font-weight:700;letter-spacing:.01em}
        .nav-links .btn-ghost::after{content:'';position:absolute;left:16px;right:16px;bottom:2px;height:2px;border-radius:2px;background:linear-gradient(90deg,#31d7e5,#5595ff);transform:scaleX(0);transition:transform .25s ease}
        .nav-links .btn-ghost:hover,.nav-links .btn-ghost.active{color:#fff;background:rgba(126,220,255,.08)}.nav-links .btn-ghost:hover::after,.nav-links .btn-ghost.active::after{transform:scaleX(1)}
        .account-menu{position:relative}.account-menu summary{list-style:none;display:flex;align-items:center;gap:7px;padding:8px 14px;border-radius:10px;cursor:pointer;color:#c4d7e8;font-size:.875rem;font-weight:700;transition:background .2s ease,color .2s ease}.account-menu summary::-webkit-details-marker{display:none}.account-menu summary:hover,.account-menu[open] summary{color:#fff;background:rgba(126,220,255,.1)}.account-menu summary i:last-child{font-size:9px;transition:transform .25s ease}.account-menu[open] summary i:last-child{transform:rotate(180deg)}.login-link{display:inline-flex;align-items:center;gap:7px;padding:8px 14px!important;border-radius:10px;color:#c4d7e8!important;font-size:.875rem;font-weight:700}.login-link:hover{color:#fff!important;background:rgba(126,220,255,.1)!important}
        .account-popover{position:absolute;right:0;top:calc(100% + 12px);width:220px;padding:8px;border:1px solid rgba(144,221,255,.2);border-radius:15px;background:rgba(5,30,55,.96);box-shadow:0 22px 55px rgba(0,8,22,.38);backdrop-filter:blur(20px);animation:navPopover .25s ease both}.account-popover a{display:flex;align-items:center;gap:10px;padding:11px 12px!important;color:#c8dced!important;border-radius:9px!important}.account-popover a:hover{color:#fff!important;background:rgba(68,200,239,.12)!important}.account-popover i{width:18px;color:#55d9ff;text-align:center}
        .nav-cta{display:inline-flex;align-items:center;gap:7px;color:#fff!important;background:linear-gradient(120deg,#2878ee,#09b9d1)!important;box-shadow:0 8px 22px rgba(17,157,218,.28)!important}.nav-cta:hover{transform:translateY(-2px);box-shadow:0 12px 27px rgba(17,181,221,.4)!important}@keyframes navPopover{from{opacity:0;transform:translateY(-7px) scale(.98)}to{opacity:1;transform:none}}
        @media(max-width:760px){nav{padding:7px 9px}.nav-inner{width:100%;height:58px;min-height:0;padding:0 12px;flex-wrap:nowrap;border-radius:15px}.logo{width:auto;margin:0}.logo-text{font-size:1.12rem}.logo-icon{width:34px;height:34px}.nav-links{display:none}.mobile-burger{display:flex;margin-left:auto;color:#d7e9f7;border:0;background:transparent;box-shadow:none}.mobile-burger:hover{color:#fff;background:transparent}.mobile-drawer{width:min(84vw,320px);background:linear-gradient(160deg,#071d35,#0b3153);border-left:1px solid rgba(126,220,255,.18)}.mobile-drawer-head{padding:16px 18px;border-color:rgba(126,220,255,.12)}.mobile-drawer-head span{color:#fff!important;font-weight:700!important}.mobile-drawer-head button{color:#c8dced!important}.mobile-nav-label{padding:18px 19px 7px;color:#7196b7;font-size:10px;font-weight:700;letter-spacing:.14em;text-transform:uppercase}.mobile-nav-item{gap:12px;margin:2px 10px;padding:11px 10px;border:0;border-radius:10px;color:#c7d8e7;font-size:15px;font-weight:500}.mobile-nav-item i{width:20px;color:#56bfe6;font-size:14px}.mobile-nav-item:hover{color:#fff;background:rgba(126,220,255,.08)}.mobile-nav-item.primary-account{color:#eaf8ff;background:rgba(68,190,225,.09)}.mobile-nav-cta{margin:18px 14px;padding:12px;min-height:48px;border-radius:999px;font-size:14px;font-weight:600;box-shadow:0 10px 24px rgba(5,156,211,.24);transition:transform .2s ease,box-shadow .2s ease}.mobile-nav-cta:hover{transform:translateY(-2px);box-shadow:0 14px 30px rgba(5,179,218,.34)}}

        /* Compact hero actions keep the water scene visible. */
        .hero-actions{align-items:center}.hero-actions a{display:inline-flex;align-items:center;justify-content:center;min-height:52px;border-radius:999px;padding:12px 22px}.hero-btn-primary{min-width:210px}.hero-btn-secondary{min-width:260px}
        @media(max-width:640px){.hero-actions{align-items:center}.hero-actions a{width:min(85%,280px);min-height:52px;padding:12px 18px;border-radius:999px}.hero-btn-primary,.hero-btn-secondary{min-width:0}.hero-btn-secondary{font-size:.9rem!important}}

        /* Keep touch scrolling light: static scenery and no live backdrop blur. */
        @media (max-width: 760px), (hover: none) and (pointer: coarse) {
            .hero-bg, .hero:hover .hero-bg {
                animation: none;
                transform: none;
                filter: none;
                transition: none;
                will-change: auto;
            }
            .hero-bg::after, .hero::after { display: none; }
            .water-ring, .drop, .app-mockup,
            .hero-btn-primary, .hero-scroll-hint i { animation: none; }
            nav, .hero-btn-secondary, .app-mockup, .floating-badge-2, .account-popover {
                backdrop-filter: none;
                -webkit-backdrop-filter: none;
            }
            nav, nav.nav-scrolled {
                padding: 7px 9px;
                background: #071e35;
                transition: none;
            }
            .nav-inner, nav.nav-scrolled .nav-inner {
                height: 58px;
                transition: none;
            }
        }

        /* Use the same still hero presentation on desktop as on mobile. */
        @media (min-width: 761px) and (hover: hover) {
            nav, .hero-bg, .water-ring, .drop, .hero::after,
            .hero-left > *, .hero-visual, .app-mockup,
            .hero-btn-primary, .hero-scroll-hint, .hero-scroll-hint i {
                animation: none;
            }
            .hero-bg, .hero:hover .hero-bg {
                transform: none;
                filter: none;
                transition: none;
                will-change: auto;
            }
            .hero-bg::after, .hero::after { display: none; }
            .hero-left > *, .hero-visual, .hero-scroll-hint { opacity: 1; }
        }

        @media (prefers-reduced-motion:reduce) {
            html { scroll-behavior:auto; }
            *,*::before,*::after { animation-duration:.01ms!important; animation-iteration-count:1!important; transition-duration:.01ms!important; }
        }
    </style>
    <script src="js/ui-protection.js" defer></script>
<style>
.logo-icon{width:56px!important;height:56px!important;flex-shrink:0}
@media(max-width:599px){.logo-icon{width:48px!important;height:48px!important}}

/* Quick order preview — a polished, customer-first path to checkout. */
.logo-icon { width:46px!important; height:46px!important; }
nav { padding:0; background:#082b49; border-bottom:1px solid rgba(180,221,239,.16); box-shadow:0 8px 24px rgba(2,20,35,.14); }
nav.nav-scrolled { padding:0; background:#072640; }
.nav-inner, nav.nav-scrolled .nav-inner { height:72px; max-width:1240px; padding:0 28px; border:0; border-radius:0; background:transparent; box-shadow:none; }
.logo { gap:11px; }
.logo-text { color:#f4fbff; font-size:1.28rem; letter-spacing:-.035em; }
.logo-text span { color:#3bd4ed; }
.nav-links { gap:2px; }
.nav-links a, .nav-links button { position:relative; padding:10px 16px; border-radius:7px; color:#bfd3e2!important; font-weight:650; }
.nav-links .btn-ghost:hover, .nav-links .btn-ghost.active { color:#fff!important; background:transparent; }
.nav-links .btn-ghost::after { content:''; position:absolute; left:16px; right:16px; bottom:5px; height:2px; border-radius:2px; background:#55d8ea; transform:scaleX(0); transition:transform .2s ease; }
.nav-links .btn-ghost:hover::after, .nav-links .btn-ghost.active::after { transform:scaleX(1); }
.login-link { color:#dbeaf3!important; }
.login-link:hover { color:#fff!important; background:rgba(255,255,255,.06)!important; }
.nav-cta { margin-left:8px; padding:11px 18px!important; border-radius:8px!important; color:#fff!important; background:#1687d5!important; box-shadow:none!important; }
.nav-cta:hover { background:#0f75bd!important; transform:translateY(-1px); box-shadow:none!important; }
@media(max-width:760px){
    nav{padding:0}.nav-inner,nav.nav-scrolled .nav-inner{height:62px;padding:0 17px;border:0;border-radius:0}.logo-icon{width:39px!important;height:39px!important}.logo-text{font-size:1.14rem}.mobile-burger{color:#e1f0f8}.mobile-drawer{background:#082b49}
}

.hero-content.order-first { grid-template-columns:minmax(0,1fr) minmax(360px,440px); gap:54px; align-items:center; }
.order-first .hero-title { max-width:620px; }
.order-first .hero-desc { max-width:570px; }
.quick-order {
    position:relative; overflow:hidden; padding:22px; border-radius:26px;
    color:#eaf8ff; background:linear-gradient(145deg,rgba(6,39,70,.96),rgba(9,73,103,.92));
    border:1px solid rgba(151,233,255,.28); box-shadow:0 30px 70px rgba(0,9,30,.42), inset 0 1px rgba(255,255,255,.1);
    backdrop-filter:blur(18px);
}
.quick-order::before { content:''; position:absolute; width:260px; height:260px; right:-120px; top:-135px; border-radius:50%; background:radial-gradient(circle,rgba(61,221,255,.26),transparent 68%); pointer-events:none; }
.order-panel-head,.cart-total,.product-copy,.product-add { position:relative; z-index:1; }
.order-panel-head { display:flex; justify-content:space-between; gap:14px; align-items:flex-start; margin-bottom:18px; }
.order-kicker { display:block; margin-bottom:5px; color:#68e5fa; text-transform:uppercase; letter-spacing:.13em; font-size:.67rem; font-weight:800; }
.order-panel-head h2 { color:#fff; font-size:1.18rem; line-height:1.3; }
.cart-count { display:inline-flex; align-items:center; gap:6px; padding:7px 10px; border-radius:999px; color:#fff; background:rgba(255,255,255,.11); border:1px solid rgba(255,255,255,.12); font-size:.74rem; font-weight:700; white-space:nowrap; }
.quick-products { display:grid; gap:10px; }
.quick-product { display:flex; align-items:center; gap:11px; padding:12px; border-radius:16px; background:rgba(255,255,255,.075); border:1px solid rgba(210,247,255,.1); transition:transform .2s ease,background .2s ease,border-color .2s ease; }
.quick-product:hover { transform:translateY(-2px); background:rgba(255,255,255,.12); border-color:rgba(112,232,255,.37); }
.product-icon { width:42px; height:42px; display:grid; place-items:center; border-radius:13px; flex:0 0 auto; color:#76eaff; background:linear-gradient(145deg,rgba(56,212,240,.27),rgba(67,119,244,.24)); font-size:1.05rem; }
.product-copy { flex:1; min-width:0; }
.product-copy strong { display:block; color:#fff; font-size:.86rem; }
.product-copy span { display:block; margin-top:2px; color:#a9cadb; font-size:.71rem; }
.product-add { width:34px; height:34px; border:0; border-radius:11px; cursor:pointer; color:#063049; background:linear-gradient(135deg,#85f3ff,#38b9ed); font-size:1rem; box-shadow:0 5px 12px rgba(17,210,241,.22); transition:transform .18s ease,box-shadow .18s ease; }
.product-add:hover { transform:scale(1.08); box-shadow:0 8px 18px rgba(17,210,241,.35); }
.cart-total { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-top:16px; padding:14px 0 0; border-top:1px solid rgba(201,244,255,.13); }
.cart-total small { display:block; color:#9bc3d6; font-size:.7rem; line-height:1.35; }
.cart-total strong { display:block; color:#fff; font-size:.9rem; margin-top:2px; }
.checkout-link { display:inline-flex; align-items:center; gap:8px; padding:11px 14px; border-radius:12px; color:#063049; background:#e9fbff; text-decoration:none; font-size:.78rem; font-weight:800; white-space:nowrap; transition:transform .2s ease,box-shadow .2s ease; }
.checkout-link:hover { transform:translateY(-2px); box-shadow:0 8px 18px rgba(0,0,0,.16); }
.order-note { position:relative; z-index:1; display:flex; gap:7px; margin-top:13px; color:#a6c9da; font-size:.68rem; line-height:1.4; }
.order-note i { color:#73e7f8; margin-top:2px; }
@media(max-width:900px){.hero-content.order-first{grid-template-columns:1fr;gap:34px}.quick-order{max-width:520px}.hero-visual{display:block!important}}
@media(max-width:640px){.quick-order{padding:17px;border-radius:21px}.order-panel-head h2{font-size:1.05rem}.quick-product{padding:10px}.cart-total{align-items:flex-end}.checkout-link{padding:10px 11px}.order-first .hero-actions{margin-bottom:0}}

/* First-order decision — keeps refill and new-container journeys clear. */
.gallon-choice-modal { position:fixed; inset:0; z-index:1000; display:none; align-items:center; justify-content:center; padding:22px; background:rgba(2,18,31,.68); backdrop-filter:blur(7px); }
.gallon-choice-modal.is-open { display:flex; }
.gallon-choice-dialog { position:relative; width:min(100%,760px); padding:34px; border-radius:24px; color:#17334a; background:#f8fcff; box-shadow:0 28px 70px rgba(0,13,29,.4); }
.choice-close { position:absolute; top:18px; right:18px; width:36px; height:36px; border:0; border-radius:50%; cursor:pointer; color:#547086; background:#e9f2f7; font-size:1.05rem; }
.choice-close:hover { color:#0b2e49; background:#dcebf3; }
.choice-overline { display:inline-flex; align-items:center; gap:7px; color:#1678bd; font-size:.72rem; font-weight:800; letter-spacing:.11em; text-transform:uppercase; }
.gallon-choice-dialog h2 { max-width:530px; margin:10px 0 9px; color:#092e4a; font-size:clamp(1.6rem,3vw,2.15rem); letter-spacing:-.035em; }
.gallon-choice-dialog > p { max-width:550px; color:#627b8d; line-height:1.6; font-size:.94rem; }
.gallon-choice-cards { display:grid; grid-template-columns:1fr 1fr; gap:15px; margin-top:26px; }
.gallon-choice-card { display:block; padding:21px; border:1px solid #d9e9f1; border-radius:17px; color:inherit; text-decoration:none; background:#fff; transition:border-color .2s ease,transform .2s ease,box-shadow .2s ease; }
.gallon-choice-card:hover { transform:translateY(-3px); border-color:#4bbce0; box-shadow:0 14px 30px rgba(19,97,135,.12); }
.choice-icon { width:48px; height:48px; display:grid; place-items:center; margin-bottom:16px; border-radius:14px; color:#0e7ec0; background:#e4f6fc; font-size:1.2rem; }
.choice-icon.new { color:#225dd2; background:#eaf0ff; }
.gallon-choice-card h3 { color:#103a59; font-size:1.02rem; margin-bottom:7px; }
.gallon-choice-card p { color:#6a8191; line-height:1.55; font-size:.82rem; }
.choice-link { display:inline-flex; align-items:center; gap:7px; margin-top:16px; color:#1279bd; font-size:.8rem; font-weight:800; }
.choice-footnote { display:flex; align-items:flex-start; gap:8px; margin-top:20px; padding-top:18px; border-top:1px solid #e2edf3; color:#6b8291; font-size:.75rem; line-height:1.5; }
.choice-footnote i { color:#18a4bd; margin-top:2px; }
.returning-customer { display:flex; align-items:center; justify-content:space-between; gap:16px; margin-top:14px; padding:13px 15px; border:1px solid #d9eaf1; border-radius:13px; background:#f5fbfe; }
.returning-customer strong { display:block; color:#17425e; font-size:.8rem; }.returning-customer span { display:block; margin-top:2px; color:#718898; font-size:.73rem; }
.returning-customer a { display:inline-flex; align-items:center; gap:7px; flex:0 0 auto; color:#137ebd; font-size:.78rem; font-weight:800; text-decoration:none; }.returning-customer a:hover { color:#075b91; }
@media(max-width:600px){.gallon-choice-dialog{padding:27px 20px 21px;border-radius:20px}.gallon-choice-cards{grid-template-columns:1fr;gap:11px}.gallon-choice-card{padding:16px}.choice-icon{width:42px;height:42px;margin-bottom:11px}.choice-close{top:11px;right:11px}}
</style>
</head>
<body>

    <!-- ─── NAV ─────────────────────────────── -->
    <nav>
        <div class="nav-inner">
            <a href="index.php" class="logo">
                <div class="logo-icon"><img src="imagess/hydromis-logo-v2.png" alt="HydroMIS Logo" style="width: 100%; height: 100%; object-fit: contain;"></div>
                <span class="logo-text">Hydro<span>MIS</span></span>
            </a>

            <div class="nav-links">
                <a href="#home" class="btn-ghost active">Home</a>
                <a href="#features"    class="btn-ghost">Features</a>
                <a href="#how"         class="btn-ghost">How it works</a>
                <a href="user/track_order.php?view=search" class="btn-ghost">Track Order</a>
                <a href="login.php" class="login-link"><i class="fas fa-circle-user"></i> Login</a>
                <a href="#gallon-choice" class="nav-cta js-gallon-choice">Get Started <i class="fas fa-arrow-right"></i></a>
            </div>

            <button class="mobile-burger" id="burger" aria-label="Open menu" aria-expanded="false" aria-controls="mobile-drawer">
                <i class="fas fa-bars" id="burger-icon"></i>
            </button>
        </div>
    </nav>

    <!-- Mobile nav overlay -->
    <div class="mobile-overlay" id="mobile-overlay">
        <aside class="mobile-drawer" id="mobile-drawer">
            <div class="mobile-drawer-head">
                <div style="display:flex;align-items:center;gap:8px;">
                    <div class="logo-icon" style="width:28px;height:28px;font-size:12px;"><img src="imagess/hydromis-logo-v2.png" alt="HydroMIS Logo" style="width: 100%; height: 100%; object-fit: contain;"></div>
                    <span style="font-family:'Sora',sans-serif;font-weight:800;font-size:1.1rem;color:var(--text-1);">HydroMIS</span>
                </div>
                <button id="drawer-close" style="background:none;border:none;cursor:pointer;font-size:1.1rem;color:var(--text-2);">
                    <i class="fas fa-xmark"></i>
                </button>
            </div>
            <div class="mobile-nav-label">Account</div>
            <a href="login.php" class="mobile-nav-item"><i class="fas fa-circle-user"></i> Login</a>
            <div class="mobile-nav-label">Explore</div>
            <a href="#features"            class="mobile-nav-item"><i class="fas fa-star"></i> Features</a>
            <a href="#how"                 class="mobile-nav-item"><i class="fas fa-route"></i> How it works</a>
            <a href="user/track_order.php?view=search" class="mobile-nav-item"><i class="fas fa-location-dot"></i> Track order</a>
            <a href="#gallon-choice" class="mobile-nav-item js-gallon-choice"><i class="fas fa-bag-shopping"></i> Start an order</a>
        </aside>
    </div>

    <!-- First-time order guidance -->
    <div class="gallon-choice-modal" id="gallon-choice" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="gallon-choice-title">
        <div class="gallon-choice-dialog">
            <button class="choice-close" id="choice-close" type="button" aria-label="Close"><i class="fas fa-xmark"></i></button>
            <span class="choice-overline"><i class="fas fa-droplet"></i> First order setup</span>
            <h2 id="gallon-choice-title">Do you already have an empty gallon?</h2>
            <p>Choose the setup that matches your first delivery. You can select the gallon size, delivery schedule, and payment method in the secure order flow.</p>
            <div class="gallon-choice-cards">
                <a class="gallon-choice-card" href="create_account.php?order_type=refill&amp;container=existing&amp;container_size=5gal-round&amp;quantity=1&amp;fulfillment=delivery">
                    <div class="choice-icon"><i class="fas fa-recycle"></i></div>
                    <h3>Yes, I have a gallon</h3>
                    <p>Choose a refill and return your empty container during delivery or pickup.</p>
                    <span class="choice-link">Continue with a refill <i class="fas fa-arrow-right"></i></span>
                </a>
                <a class="gallon-choice-card" href="create_account.php?order_type=first_order&amp;container=new&amp;container_size=5gal-round&amp;quantity=1&amp;fulfillment=delivery">
                    <div class="choice-icon new"><i class="fas fa-bottle-water"></i></div>
                    <h3>No, I need a new gallon</h3>
                    <p>Start with a new container and add your first purified-water refill.</p>
                    <span class="choice-link">Continue with a new gallon <i class="fas fa-arrow-right"></i></span>
                </a>
            </div>
            <p class="choice-footnote"><i class="fas fa-circle-info"></i><span>Not sure? Choose the option that matches your home today. Our team can confirm the final order details before delivery.</span></p>
            <div class="returning-customer"><div><strong>Already a HydroMIS customer?</strong><span>Log in to reorder or create your next water request.</span></div><a href="user/scan_qr.php">Log in <i class="fas fa-arrow-right"></i></a></div>
        </div>
    </div>

    <!-- ─── HERO ─────────────────────────────── -->
    <section class="hero" id="home">
        <div class="hero-bg"></div>
        <div class="water-ring"></div>
        <div class="water-ring"></div>
        <div class="water-ring"></div>
        <div class="drop"></div>
        <div class="drop"></div>
        <div class="drop"></div>
        <div class="drop"></div>

        <div class="hero-content order-first">
            <div class="hero-left">
                <div class="hero-eyebrow">
                    <i class="fas fa-droplet"></i>
                    Water Station Management
                </div>
                <h1 class="hero-title">
                    Fresh water, <span class="accent">ready when you are.</span>
                </h1>
                <p class="hero-desc">
                    Add the gallons you need, continue ordering whenever you like, then check out in one smooth flow. Track every delivery from your customer portal.
                </p>
                <div class="hero-actions">
                    <a href="#gallon-choice" class="hero-btn-primary js-gallon-choice">Start an order <i class="fas fa-arrow-right" style="margin-left:6px;font-size:.85em;"></i></a>
                    <a href="user/scan_qr.php" class="hero-btn-secondary"><i class="fas fa-qrcode" style="margin-right:6px;font-size:.85em;"></i> Log in to order again</a>
                </div>
                <a href="#features" class="hero-scroll-hint"><i class="fas fa-chevron-down"></i> Scroll to explore</a>
                <div class="hero-stats">
                    <div>
                        <div class="hero-stat-num">3<span>+</span></div>
                        <div class="hero-stat-label">User Roles</div>
                    </div>
                    <div>
                        <div class="hero-stat-num">100<span>%</span></div>
                        <div class="hero-stat-label">Real-Time Tracking</div>
                    </div>
                    <div>
                        <div class="hero-stat-num">0</div>
                        <div class="hero-stat-label">Passwords Needed</div>
                    </div>
                </div>
            </div>

            <aside class="quick-order" id="quick-order" aria-label="Quick order cart preview">
                <div class="order-panel-head">
                    <div>
                        <span class="order-kicker"><i class="fas fa-bag-shopping"></i> Quick order</span>
                        <h2>Build your delivery cart</h2>
                    </div>
                    <span class="cart-count"><i class="fas fa-cart-shopping"></i> <span id="cart-count">0</span> items</span>
                </div>
                <div class="quick-products">
                    <article class="quick-product">
                        <div class="product-icon"><i class="fas fa-droplet"></i></div>
                        <div class="product-copy"><strong>19L Refill</strong><span>Return your empty gallon</span></div>
                        <button class="product-add" type="button" data-product="19L Refill" aria-label="Add 19L refill to cart"><i class="fas fa-plus"></i></button>
                    </article>
                    <article class="quick-product">
                        <div class="product-icon"><i class="fas fa-bottle-water"></i></div>
                        <div class="product-copy"><strong>19L New Gallon</strong><span>Fresh container, ready for delivery</span></div>
                        <button class="product-add" type="button" data-product="19L New Gallon" aria-label="Add 19L new gallon to cart"><i class="fas fa-plus"></i></button>
                    </article>
                    <article class="quick-product">
                        <div class="product-icon"><i class="fas fa-glass-water"></i></div>
                        <div class="product-copy"><strong>9.5L Refill</strong><span>Compact option for everyday use</span></div>
                        <button class="product-add" type="button" data-product="9.5L Refill" aria-label="Add 9.5L refill to cart"><i class="fas fa-plus"></i></button>
                    </article>
                </div>
                <div class="cart-total">
                    <div><small>Your cart</small><strong id="cart-summary">No gallons added yet</strong></div>
                    <a href="user/scan_qr.php" class="checkout-link">Checkout <i class="fas fa-arrow-right"></i></a>
                </div>
                <p class="order-note"><i class="fas fa-circle-info"></i><span>Add more items any time. Sign in securely to confirm your address, schedule, and final total.</span></p>
            </aside>

        </div>
    </section>

    <!-- ─── TRUST BAR ──────────────────────── -->
    <div class="trust-bar">
        <div class="trust-inner">
            <div class="trust-label">Built with</div>
            <div class="trust-item"><i class="fas fa-qrcode"></i> QR Code &amp; Mobile Login</div>
            <div class="trust-item"><i class="fas fa-bolt"></i> Order Status Tracking</div>
            <div class="trust-item"><i class="fas fa-lock"></i> Convenient Customer Access</div>
            <div class="trust-item"><i class="fas fa-chart-bar"></i> Sales &amp; Order Reports</div>
            <div class="trust-item"><i class="fas fa-mobile-alt"></i> Mobile-Responsive System</div>
        </div>
    </div>

    <!-- ─── FEATURES / ROLES ───────────────── -->
    <section id="features">
        <div class="section-inner">
            <div class="section-header center">
                <div class="section-tag"><i class="fas fa-users"></i> For everyone</div>
                <h2 class="section-title">Three portals, one system</h2>
                <p class="section-sub">Whether you're ordering water, delivering it, or running the whole operation — HydroMIS has a tailored experience for you.</p>
            </div>

            <div class="cards-3">
                <!-- Customer -->
                <div class="role-card blue fade-up">
                    <div class="role-icon icon-blue"><i class="fas fa-users"></i></div>
                    <h3>Customer Portal</h3>
                    <p class="subtitle">Order and manage your water deliveries</p>
                    <ul class="feature-list">
                        <li><span class="check-icon check-blue"><i class="fas fa-check"></i></span> Place water refill orders easily</li>
                        <li><span class="check-icon check-blue"><i class="fas fa-check"></i></span> Log in using your mobile number or QR code</li>
                        <li><span class="check-icon check-blue"><i class="fas fa-check"></i></span> Track order and delivery status</li>
                        <li><span class="check-icon check-blue"><i class="fas fa-check"></i></span> Submit gallon cap replacement requests</li>
                        <li><span class="check-icon check-blue"><i class="fas fa-check"></i></span> View order history and rate the service</li>
                    </ul>
                </div>

                <!-- Sign In -->
                <div class="role-card green fade-up">
                    <div class="role-icon icon-green"><i class="fas fa-qrcode"></i></div>
                    <h3>Customer Login</h3>
                    <p class="subtitle">Quick and convenient account access</p>
                    <ul class="feature-list">
                        <li><span class="check-icon check-green"><i class="fas fa-check"></i></span> Scan your unique QR code</li>
                        <li><span class="check-icon check-green"><i class="fas fa-check"></i></span> Log in using your registered mobile number</li>
                        <li><span class="check-icon check-green"><i class="fas fa-check"></i></span> Access your customer account</li>
                        <li><span class="check-icon check-green"><i class="fas fa-check"></i></span> Manage your water refill requests</li>
                        <li><span class="check-icon check-green"><i class="fas fa-check"></i></span> Enjoy convenient and secure access</li>
                    </ul>
                </div>

                <!-- Order Tracking -->
                <div class="role-card purple fade-up">
                    <div class="role-icon icon-purple"><i class="fas fa-map-location-dot"></i></div>
                    <h3>Order Management &amp; Tracking</h3>
                    <p class="subtitle">Monitor your water refill orders</p>
                    <ul class="feature-list">
                        <li><span class="check-icon check-purple"><i class="fas fa-check"></i></span> View order request updates</li>
                        <li><span class="check-icon check-purple"><i class="fas fa-check"></i></span> Track order confirmation and processing</li>
                        <li><span class="check-icon check-purple"><i class="fas fa-check"></i></span> Monitor delivery status</li>
                        <li><span class="check-icon check-purple"><i class="fas fa-check"></i></span> Review previous orders</li>
                        <li><span class="check-icon check-purple"><i class="fas fa-check"></i></span> Rate the service after delivery</li>
                    </ul>
                </div>
            </div>

            <!-- Scroll hint dots (visible on mobile only) -->
            <div class="cards-scroll-hint" style="display:none;">
                <button type="button" class="scroll-dot active" aria-label="Show customer portal" aria-current="true"></button>
                <button type="button" class="scroll-dot" aria-label="Show sign in portal"></button>
                <button type="button" class="scroll-dot" aria-label="Show order tracking portal"></button>
            </div>
        </div>
    </section>

    <!-- ─── HOW IT WORKS ──────────────────── -->
    <section class="how-section" id="how">
        <div class="section-inner">
            <div class="section-header center">
                <div class="section-tag"><i class="fas fa-route"></i> Process</div>
                <h2 class="section-title">Up and running in minutes</h2>
                <p class="section-sub">From registration to your first delivery — the whole journey is frictionless.</p>
            </div>

            <div class="steps-grid">
                <div class="step-item active">
                    <div class="step-num">1</div>
                    <div class="step-text">
                    <div class="step-title">Create Account</div>
                    <div class="step-desc">Register with your name, address, and mobile number. No password required.</div>
                    </div>
                </div>
                <div class="step-item active">
                    <div class="step-num">2</div>
                    <div class="step-text">
                    <div class="step-title">Get Your QR</div>
                    <div class="step-desc">Receive a unique QR code that serves as your identity and login key.</div>
                    </div>
                </div>
                <div class="step-item active">
                    <div class="step-num">3</div>
                    <div class="step-text">
                    <div class="step-title">Place an Order</div>
                    <div class="step-desc">Log in by scanning your QR and place a water order from the portal.</div>
                    </div>
                </div>
                <div class="step-item active">
                    <div class="step-num">4</div>
                    <div class="step-text">
                    <div class="step-title">Track & Receive</div>
                    <div class="step-desc">Watch your delivery in real-time and rate the experience after it arrives.</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ─── PREMIUM FEATURES ──────────────── -->
    <section class="features-section">
        <div class="section-inner">
            <div class="section-header">
                <div class="section-tag"><i class="fas fa-sparkles"></i> Capabilities</div>
                <h2 class="section-title">Everything you need,<br>nothing you don't</h2>
                <p class="section-sub">Modern technology baked in — so you focus on the water, not the paperwork.</p>
            </div>

            <div class="features-2col">
                <div class="feat-card fade-up">
                    <div class="feat-icon"><i class="fas fa-qrcode"></i></div>
                    <div>
                        <h4>QR Code Technology</h4>
                        <p>Each customer gets a unique QR code containing their profile. Scan to log in instantly at the station — no app download, no passwords.</p>
                    </div>
                </div>
                <div class="feat-card fade-up">
                    <div class="feat-icon"><i class="fas fa-shield-halved"></i></div>
                    <div>
                        <h4>Passwordless Authentication</h4>
                        <p>Your mobile number is your unique identifier. Staff verify customers with a quick QR scan — secure, fast, and foolproof.</p>
                    </div>
                </div>
                <div class="feat-card fade-up">
                    <div class="feat-icon"><i class="fas fa-satellite-dish"></i></div>
                    <div>
                        <h4>Real-Time Order Tracking</h4>
                        <p>Customers see live status updates from "Order Placed" to "Out for Delivery" to "Delivered." No more waiting and wondering.</p>
                    </div>
                </div>
                <div class="feat-card fade-up">
                    <div class="feat-icon"><i class="fas fa-chart-bar"></i></div>
                    <div>
                        <h4>Comprehensive Analytics</h4>
                        <p>Detailed reports on sales, delivery performance, customer satisfaction scores, and staff activity — all exportable.</p>
                    </div>
                </div>
                <div class="feat-card fade-up">
                    <div class="feat-icon"><i class="fas fa-boxes-stacked"></i></div>
                    <div>
                        <h4>Inventory Management</h4>
                        <p>Staff update stock levels in real time. Get alerts before you run out, and keep operations running without interruption.</p>
                    </div>
                </div>
                <div class="feat-card fade-up">
                    <div class="feat-icon"><i class="fas fa-clipboard-list"></i></div>
                    <div>
                        <h4>Activity & Audit Logs</h4>
                        <p>Every action in the system is logged. Admins can review who did what and when — full accountability at every level.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ─── CTA ───────────────────────────── -->
    <section class="cta-section">
        <div class="cta-bg"></div>
        <div class="cta-inner">
            <div class="hero-eyebrow" style="display:inline-flex;margin-bottom:20px;">
                <i class="fas fa-rocket"></i> Ready to launch?
            </div>
            <h2>Start managing your station smarter today</h2>
            <p>Join HydroMIS and give your customers, staff, and yourself a system that actually works.</p>

        </div>  
    </section>

    <!-- ─── FOOTER ────────────────────────── -->
    <footer>
        <div class="footer-inner">
            <div class="footer-top">
                <div class="footer-brand">
                    <div class="footer-logo">
                        <div class="logo-icon"><img src="imagess/hydromis-logo-v2.png" alt="HydroMIS Logo" style="width: 100%; height: 100%; object-fit: contain;"></div>
                        <span class="footer-logo-text">HydroMIS</span>
                    </div>
                    <p>Water Refilling Station Management System. Built for efficiency, designed for simplicity.</p>
                </div>

                <div class="footer-links-cols">
                <div class="footer-links-group">
                    <h5>Portal</h5>
                    <a href="create_account.php">Register</a>
                    <a href="user/scan_qr.php">Customer Login</a>
                    <a href="login.php?role=admin">Admin Login</a>
                </div>

                <div class="footer-links-group">
                    <h5>Legal</h5>
                    <a href="terms.php">Terms &amp; Conditions</a>
                    <a href="privacy.php">Privacy Policy</a>
                </div>

                <div class="footer-links-group">
                    <h5>Support</h5>
                    <a href="mailto:hydromis.support@gmail.com">Contact Support</a>
                </div>
                </div>
            </div>

            <div class="footer-bottom">
                <span>&copy; 2026 HydroMIS. All rights reserved.</span>
                <span>Water Refilling Station Management System</span>
            </div>
        </div>
    </footer>

    <!-- ─── STICKY MOBILE BOTTOM BAR ──────── -->
    <script>
        // ─── Mobile nav ───────────────────────
        const burger        = document.getElementById('burger');
        const burgerIcon    = document.getElementById('burger-icon');
        const mobileOverlay = document.getElementById('mobile-overlay');
        const drawerClose   = document.getElementById('drawer-close');
        const siteNav       = document.querySelector('nav');
        const accountMenu   = document.querySelector('.account-menu');

        let navScrolled = false;
        let navFramePending = false;
        function syncNavDepth() {
            const nextScrolled = window.scrollY > 24;
            if (nextScrolled === navScrolled) return;
            navScrolled = nextScrolled;
            siteNav?.classList.toggle('nav-scrolled', navScrolled);
        }
        syncNavDepth();
        window.addEventListener('scroll', () => {
            if (navFramePending) return;
            navFramePending = true;
            requestAnimationFrame(() => {
                navFramePending = false;
                syncNavDepth();
            });
        }, { passive: true });
        document.addEventListener('click', event => {
            if (accountMenu?.open && !accountMenu.contains(event.target)) accountMenu.open = false;
        });

        function openNav() {
            document.body.classList.add('nav-open');
            burgerIcon.className = 'fas fa-xmark';
            burger.setAttribute('aria-expanded', 'true');
            burger.setAttribute('aria-label', 'Close menu');
        }
        function closeNav() {
            document.body.classList.remove('nav-open');
            burgerIcon.className = 'fas fa-bars';
            burger.setAttribute('aria-expanded', 'false');
            burger.setAttribute('aria-label', 'Open menu');
        }

        burger.addEventListener('click', () => document.body.classList.contains('nav-open') ? closeNav() : openNav());
        drawerClose.addEventListener('click', closeNav);
        mobileOverlay.addEventListener('click', e => { if (!document.getElementById('mobile-drawer').contains(e.target)) closeNav(); });
        document.addEventListener('keydown', e => { if (e.key === 'Escape') closeNav(); });
        document.querySelectorAll('#mobile-drawer a[href^="#"]').forEach(a => a.addEventListener('click', closeNav));

        // ─── First-order gallon choice ─────────
        const gallonChoice = document.getElementById('gallon-choice');
        const choiceClose = document.getElementById('choice-close');
        const choiceTriggers = document.querySelectorAll('.js-gallon-choice');
        const openGallonChoice = event => {
            event.preventDefault();
            closeNav();
            gallonChoice.classList.add('is-open');
            gallonChoice.setAttribute('aria-hidden', 'false');
            choiceClose.focus();
        };
        const closeGallonChoice = () => {
            gallonChoice.classList.remove('is-open');
            gallonChoice.setAttribute('aria-hidden', 'true');
        };
        choiceTriggers.forEach(trigger => trigger.addEventListener('click', openGallonChoice));
        choiceClose.addEventListener('click', closeGallonChoice);
        gallonChoice.addEventListener('click', event => { if (event.target === gallonChoice) closeGallonChoice(); });
        document.addEventListener('keydown', event => { if (event.key === 'Escape' && gallonChoice.classList.contains('is-open')) closeGallonChoice(); });
        if (window.location.hash === '#gallon-choice') {
            gallonChoice.classList.add('is-open');
            gallonChoice.setAttribute('aria-hidden', 'false');
        }

        // ─── Scroll fade-up ───────────────────
        const fadeEls = document.querySelectorAll('.fade-up');
        const obs = new IntersectionObserver(entries => {
            entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('visible'); obs.unobserve(e.target); } });
        }, { threshold: .15 });
        fadeEls.forEach(el => obs.observe(el));

        // ─── Role card carousel dots ──────────
        const carousel = document.querySelector('.cards-3');
        const dots     = document.querySelectorAll('.scroll-dot');
        if (carousel && dots.length) {
            const cards = [...carousel.querySelectorAll('.role-card')];
            const updateDots = () => {
                const center = carousel.scrollLeft + carousel.clientWidth / 2;
                let active = 0;
                let distance = Infinity;
                cards.forEach((card, index) => {
                    const cardCenter = card.offsetLeft + card.offsetWidth / 2;
                    const nextDistance = Math.abs(center - cardCenter);
                    if (nextDistance < distance) { distance = nextDistance; active = index; }
                });
                dots.forEach((dot, index) => {
                    dot.classList.toggle('active', index === active);
                    dot.setAttribute('aria-current', index === active ? 'true' : 'false');
                });
            };
            carousel.addEventListener('scroll', updateDots, { passive: true });
            dots.forEach((dot, index) => dot.addEventListener('click', () => {
                cards[index]?.scrollIntoView({ behavior:'smooth', block:'nearest', inline:'center' });
            }));
            carousel.setAttribute('tabindex', '0');
            carousel.setAttribute('aria-label', 'Portal options carousel');
            carousel.addEventListener('keydown', event => {
                if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
                event.preventDefault();
                const current = [...dots].findIndex(dot => dot.classList.contains('active'));
                const next = Math.max(0, Math.min(cards.length - 1, current + (event.key === 'ArrowRight' ? 1 : -1)));
                cards[next]?.scrollIntoView({ behavior:'smooth', block:'nearest', inline:'center' });
            });
            updateDots();
        }

        // Mouse drag support for horizontally scrolling mobile-style rows.
        function enableDragScroll(element) {
            if (!element) return;
            let dragging = false, moved = false, startX = 0, startScroll = 0;
            element.addEventListener('pointerdown', event => {
                if (event.pointerType !== 'mouse' || event.button !== 0) return;
                dragging = true; moved = false; startX = event.clientX; startScroll = element.scrollLeft;
                element.classList.add('is-dragging'); element.setPointerCapture(event.pointerId);
            });
            element.addEventListener('pointermove', event => {
                if (!dragging) return;
                const distance = event.clientX - startX;
                if (Math.abs(distance) > 4) moved = true;
                element.scrollLeft = startScroll - distance;
            });
            const stopDrag = event => {
                if (!dragging) return;
                dragging = false; element.classList.remove('is-dragging');
                if (element.hasPointerCapture?.(event.pointerId)) element.releasePointerCapture(event.pointerId);
            };
            element.addEventListener('pointerup', stopDrag);
            element.addEventListener('pointercancel', stopDrag);
            element.addEventListener('click', event => {
                if (moved) { event.preventDefault(); event.stopPropagation(); moved = false; }
            }, true);
        }
        enableDragScroll(carousel);
        enableDragScroll(document.querySelector('.trust-inner'));

        // ─── Quick order preview ──────────────
        // The public homepage keeps a lightweight cart preview. The real order
        // is confirmed only after the customer signs in through the secure flow.
        const cartItems = [];
        const cartCount = document.getElementById('cart-count');
        const cartSummary = document.getElementById('cart-summary');
        document.querySelectorAll('.product-add').forEach(button => {
            button.addEventListener('click', () => {
                cartItems.push(button.dataset.product);
                const quantities = cartItems.reduce((summary, name) => {
                    summary[name] = (summary[name] || 0) + 1;
                    return summary;
                }, {});
                const itemLabel = `${cartItems.length} ${cartItems.length === 1 ? 'item' : 'items'}`;
                cartCount.textContent = cartItems.length;
                cartSummary.textContent = `${itemLabel} · ${Object.entries(quantities).map(([name, quantity]) => `${quantity} ${name}`).join(', ')}`;
                button.innerHTML = '<i class="fas fa-check"></i>';
                button.setAttribute('aria-label', `${button.dataset.product} added to cart`);
                window.setTimeout(() => {
                    button.innerHTML = '<i class="fas fa-plus"></i>';
                    button.setAttribute('aria-label', `Add ${button.dataset.product} to cart`);
                }, 850);
            });
        });

        // ─── Share ────────────────────────────
        async function shareWithFriends() {
            const shareData = { title: 'HydroMIS', text: 'Check out HydroMIS for easy water refill ordering and tracking.', url: window.location.origin + '/HydroMIS-1.3/' };
            if (navigator.share) { try { await navigator.share(shareData); } catch {} return; }
            try { await navigator.clipboard.writeText(shareData.url); alert('Link copied!'); } catch { alert('Sharing not available on this device.'); }
        }
    </script>
<script src="js/user-notifications.js"></script>
</body>
</html>
