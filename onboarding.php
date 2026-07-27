<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HydroMIS - Welcome</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;600;700;800&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link href="css/animations.css" rel="stylesheet">
    <style>
        :root {
            --blue-950: #0a1a5c;
            --blue-900: #1230a0;
            --blue-800: #1d3ea8;
            --blue-700: #2853c8;
            --blue-500: #4f73d6;
            --blue-300: #b9c8f5;
            --blue-100: #dce6ff;
            --blue-50: #eef3ff;
            --text-900: #0b1437;
            --text-700: #3d5070;
            --text-500: #7a8fae;
            --surface: #ffffff;
            --accent: #38bff8;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'DM Sans', sans-serif;
            background:
                radial-gradient(ellipse at 10% 10%, rgba(56, 191, 248, 0.18) 0%, transparent 55%),
                radial-gradient(ellipse at 90% 85%, rgba(79, 115, 214, 0.22) 0%, transparent 55%),
                linear-gradient(160deg, #d0eeff 0%, #e8f4ff 45%, #ddeeff 100%);
            padding: 20px;
            overflow: auto;
        }

        /* Floating background bubbles */
        .bg-bubbles {
            position: fixed;
            inset: 0;
            pointer-events: none;
            overflow: hidden;
            z-index: 0;
        }

        .bg-bubble {
            position: absolute;
            border-radius: 50%;
            background: rgba(79, 115, 214, 0.07);
            animation: floatBubble linear infinite;
        }

        .bg-bubble:nth-child(1) { width: 180px; height: 180px; left: 5%; animation-duration: 14s; animation-delay: 0s; }
        .bg-bubble:nth-child(2) { width: 100px; height: 100px; left: 20%; animation-duration: 18s; animation-delay: -4s; }
        .bg-bubble:nth-child(3) { width: 140px; height: 140px; left: 60%; animation-duration: 16s; animation-delay: -8s; }
        .bg-bubble:nth-child(4) { width: 70px;  height: 70px;  left: 78%; animation-duration: 12s; animation-delay: -2s; }
        .bg-bubble:nth-child(5) { width: 200px; height: 200px; left: 85%; animation-duration: 20s; animation-delay: -10s; }

        @keyframes floatBubble {
            0%   { transform: translateY(110vh) scale(0.8); opacity: 0; }
            10%  { opacity: 1; }
            90%  { opacity: 1; }
            100% { transform: translateY(-15vh) scale(1.1); opacity: 0; }
        }

        /* ── Card ── */
        .card {
            width: 100%;
            max-width: 430px;
            background: var(--surface);
            border-radius: 28px;
            overflow: hidden;
            box-shadow:
                0 4px 6px rgba(18, 48, 160, 0.06),
                0 20px 60px rgba(18, 48, 160, 0.16),
                0 0 0 1px rgba(29, 62, 168, 0.08);
            position: relative;
            z-index: 1;
            animation: cardEnter 0.8s cubic-bezier(0.22, 1, 0.36, 1) both;
        }

        @keyframes cardEnter {
            from { opacity: 0; transform: translateY(40px) scale(0.96); }
            to   { opacity: 1; transform: translateY(0)   scale(1); }
        }

        /* ── Hero area ── */
        .hero {
            position: relative;
            min-height: 340px;
            background: linear-gradient(148deg, var(--blue-700) 0%, var(--blue-950) 100%);
            padding: 22px 22px 60px;
            overflow: hidden;
        }

        /* Animated shimmer rings */
        .ring {
            position: absolute;
            border-radius: 50%;
            border: 1px solid rgba(255,255,255,0.08);
            animation: pulse-ring 4s ease-in-out infinite;
        }
        .ring-1 { width: 260px; height: 260px; right: -90px; top: -80px; animation-delay: 0s; }
        .ring-2 { width: 380px; height: 380px; right: -140px; top: -130px; animation-delay: 0.8s; }
        .ring-3 { width: 500px; height: 500px; right: -190px; top: -180px; animation-delay: 1.6s; }

        @keyframes pulse-ring {
            0%, 100% { opacity: 0.4; transform: scale(1); }
            50%       { opacity: 0.08; transform: scale(1.04); }
        }

        /* Water shimmer overlay */
        .water-shimmer {
            position: absolute;
            inset: 0;
            background: linear-gradient(
                100deg,
                transparent 20%,
                rgba(255,255,255,0.04) 40%,
                rgba(255,255,255,0.08) 50%,
                rgba(255,255,255,0.04) 60%,
                transparent 80%
            );
            background-size: 200% 100%;
            animation: shimmer 3.5s ease-in-out infinite;
        }
        @keyframes shimmer {
            0%   { background-position: -100% 0; }
            100% { background-position: 200% 0; }
        }

        /* Brand pill */
        .brand {
            position: relative;
            z-index: 2;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255,255,255,0.12);
            border: 1px solid rgba(255,255,255,0.25);
            border-radius: 999px;
            padding: 7px 14px;
            color: #fff;
            font-family: 'Sora', sans-serif;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            backdrop-filter: blur(4px);
            animation: fadeDown 0.6s 0.3s both;
        }
        .brand i { animation: waveIcon 2.5s ease-in-out infinite; }
        @keyframes waveIcon {
            0%,100% { transform: translateY(0); }
            50%      { transform: translateY(-2px); }
        }

        /* Bottles */
        .bottles {
            position: relative;
            z-index: 2;
            display: flex;
            align-items: flex-end;
            justify-content: center;
            gap: 8px;
            margin-top: 10px;
            height: 240px;
        }

        .bottle { position: relative; }

        .bottle svg {
            display: block;
            filter: drop-shadow(0 16px 24px rgba(2, 11, 60, 0.45));
        }

        .bottle.main { animation: floatMain 3.6s ease-in-out infinite; }
        .bottle.left  { animation: floatSide 4.2s ease-in-out infinite 0.5s; transform-origin: bottom center; }
        .bottle.right { animation: floatSide 3.8s ease-in-out infinite 1s;   transform-origin: bottom center; }

        @keyframes floatMain {
            0%,100% { transform: translateY(0); }
            50%      { transform: translateY(-10px); }
        }
        @keyframes floatSide {
            0%,100% { transform: translateY(0) rotate(-3deg); }
            50%      { transform: translateY(-7px) rotate(-1deg); }
        }

        /* Water fill animation inside SVG */
        .water-fill {
            animation: waterRise 2.5s cubic-bezier(0.22, 1, 0.36, 1) both;
            transform-origin: bottom;
        }
        @keyframes waterRise {
            from { transform: scaleY(0); opacity: 0.5; }
            to   { transform: scaleY(1); opacity: 1; }
        }

        /* Droplet particles */
        .drops {
            position: absolute;
            inset: 0;
            pointer-events: none;
            z-index: 1;
        }
        .drop {
            position: absolute;
            width: 5px;
            height: 8px;
            border-radius: 50% 50% 50% 50% / 60% 60% 40% 40%;
            background: rgba(147, 210, 255, 0.55);
            animation: drip linear infinite;
        }
        .drop:nth-child(1) { left: 30%; top: 20%; animation-duration: 4s; animation-delay: 0s; }
        .drop:nth-child(2) { left: 55%; top: 10%; animation-duration: 5s; animation-delay: 1.2s; }
        .drop:nth-child(3) { left: 70%; top: 30%; animation-duration: 3.5s; animation-delay: 2.4s; }
        .drop:nth-child(4) { left: 20%; top: 40%; animation-duration: 4.8s; animation-delay: 0.7s; }
        .drop:nth-child(5) { left: 82%; top: 15%; animation-duration: 3.8s; animation-delay: 3s; }

        @keyframes drip {
            0%   { transform: translateY(0) scale(1); opacity: 0.7; }
            70%  { opacity: 0.5; }
            100% { transform: translateY(160px) scale(0.4); opacity: 0; }
        }

        /* Wave divider */
        .wave-wrap {
            position: absolute;
            bottom: -2px; left: -10px; right: -10px;
            height: 70px;
            overflow: hidden;
        }
        .wave-wrap svg { width: 110%; height: 100%; }

        /* Pager dots */
        .pager {
            position: relative;
            z-index: 3;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            margin-top: 6px;
        }
        .dot {
            height: 6px;
            border-radius: 10px;
            background: rgba(255,255,255,0.3);
            transition: all 0.4s ease;
        }
        .dot.active { width: 22px; background: #fff; }
        .dot:not(.active) { width: 6px; }

        /* ── Content area ── */
        .content {
            padding: 40px 34px 36px;
        }

        .eyebrow {
            font-family: 'Sora', sans-serif;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 1.6px;
            text-transform: uppercase;
            color: var(--blue-700);
            opacity: 0;
            animation: fadeUp 0.5s 0.55s both;
        }

        .headline {
            font-family: 'Sora', sans-serif;
            font-size: 42px;
            line-height: 1.08;
            letter-spacing: -1.2px;
            color: var(--text-900);
            margin-top: 10px;
            opacity: 0;
            animation: fadeUp 0.6s 0.65s both;
        }

        .headline span {
            background: linear-gradient(135deg, var(--blue-700), var(--blue-500));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .sub {
            margin-top: 16px;
            font-size: 16px;
            line-height: 1.6;
            color: var(--text-700);
            opacity: 0;
            animation: fadeUp 0.6s 0.75s both;
        }

        /* Chips */
        .chips {
            margin-top: 18px;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            opacity: 0;
            animation: fadeUp 0.6s 0.9s both;
        }

        .chip {
            font-size: 12px;
            font-weight: 600;
            color: #1c3f9d;
            background: #edf2ff;
            border: 1px solid #d0dafc;
            border-radius: 999px;
            padding: 6px 12px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: background 0.25s, transform 0.25s, box-shadow 0.25s;
            cursor: default;
        }
        .chip:hover {
            background: #dde6ff;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(29, 62, 168, 0.12);
        }

        /* Stats row */
        .stats {
            margin-top: 20px;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            opacity: 0;
            animation: fadeUp 0.6s 1.0s both;
        }
        .stat {
            background: var(--blue-50);
            border: 1px solid var(--blue-100);
            border-radius: 14px;
            padding: 12px 10px;
            text-align: center;
        }
        .stat-num {
            font-family: 'Sora', sans-serif;
            font-size: 20px;
            font-weight: 800;
            color: var(--blue-800);
            line-height: 1;
        }
        .stat-label {
            font-size: 10px;
            font-weight: 600;
            color: var(--text-500);
            margin-top: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* CTA Button */
        .actions {
            margin-top: 24px;
            opacity: 0;
            animation: fadeUp 0.6s 1.1s both;
        }

        .btn-primary {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            min-height: 56px;
            border-radius: 999px;
            text-decoration: none;
            font-family: 'Sora', sans-serif;
            font-size: 16px;
            font-weight: 700;
            letter-spacing: 0.4px;
            color: #fff;
            background: linear-gradient(135deg, var(--blue-700) 0%, var(--blue-950) 100%);
            box-shadow:
                0 0 0 0 rgba(40, 83, 200, 0.5),
                0 8px 24px rgba(29, 62, 168, 0.3);
            position: relative;
            overflow: hidden;
            transition: transform 0.2s, box-shadow 0.2s;
            animation: glow 2.5s 1.8s ease-in-out infinite;
        }

        .btn-primary::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(255,255,255,0.15), transparent);
            opacity: 0;
            transition: opacity 0.3s;
        }

        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 14px 36px rgba(29, 62, 168, 0.38); }
        .btn-primary:hover::before { opacity: 1; }
        .btn-primary:active { transform: scale(0.98); }

        /* Ripple on click */
        .btn-primary .ripple {
            position: absolute;
            border-radius: 50%;
            background: rgba(255,255,255,0.3);
            transform: scale(0);
            animation: rippleAnim 0.6s linear;
            pointer-events: none;
        }
        @keyframes rippleAnim {
            to { transform: scale(4); opacity: 0; }
        }

        @keyframes glow {
            0%,100% { box-shadow: 0 8px 24px rgba(29,62,168,0.3), 0 0 0 0 rgba(40,83,200,0.0); }
            50%      { box-shadow: 0 8px 24px rgba(29,62,168,0.3), 0 0 0 8px rgba(40,83,200,0.12); }
        }

        .arrow-icon {
            width: 28px; height: 28px;
            background: rgba(255,255,255,0.18);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 13px;
            transition: transform 0.25s;
        }
        .btn-primary:hover .arrow-icon { transform: translateX(3px); }

        .note {
            margin-top: 12px;
            text-align: center;
            font-size: 12px;
            color: var(--text-500);
            font-weight: 500;
        }
        .note a { color: var(--blue-700); text-decoration: none; font-weight: 600; }

        /* Keyframes */
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(18px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        @keyframes fadeDown {
            from { opacity: 0; transform: translateY(-12px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 420px) {
            .headline { font-size: 28px; }
            .hero { min-height: 290px; }
            .bottles { height: 200px; }
        }
    </style>
</head>
<body>

<!-- Floating background bubbles -->
<div class="bg-bubbles" aria-hidden="true">
    <div class="bg-bubble"></div>
    <div class="bg-bubble"></div>
    <div class="bg-bubble"></div>
    <div class="bg-bubble"></div>
    <div class="bg-bubble"></div>
</div>

<main class="card">

    <!-- ── Hero ── -->
    <section class="hero">
        <div class="ring ring-1"></div>
        <div class="ring ring-2"></div>
        <div class="ring ring-3"></div>
        <div class="water-shimmer"></div>

        <!-- Drip particles -->
        <div class="drops" aria-hidden="true">
            <div class="drop"></div>
            <div class="drop"></div>
            <div class="drop"></div>
            <div class="drop"></div>
            <div class="drop"></div>
        </div>

        <div class="brand"><img src="imagess/logosystem.png" alt="HydroMIS Logo" style="width: 20px; height: 20px; object-fit: contain; margin-right: 6px;">HydroMIS Water Station</div>

        <!-- Inline SVG bottles -->
        <div class="bottles" aria-label="Water gallons">

            <!-- Left bottle (side) -->
            <div class="bottle left">
                <svg width="90" height="180" viewBox="0 0 90 200" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <defs>
                        <clipPath id="leftCap"><rect x="14" y="28" width="62" height="162" rx="16"/></clipPath>
                    </defs>
                    <!-- Cap -->
                    <rect x="28" y="4" width="34" height="26" rx="10" fill="rgba(255,255,255,0.35)"/>
                    <!-- Body -->
                    <rect x="14" y="28" width="62" height="162" rx="16" fill="rgba(255,255,255,0.18)" stroke="rgba(255,255,255,0.35)" stroke-width="1.5"/>
                    <!-- Water fill -->
                    <rect class="water-fill" x="15" y="80" width="60" height="109" rx="0" clip-path="url(#leftCap)" fill="rgba(56,191,248,0.45)"/>
                    <!-- Highlight -->
                    <rect x="22" y="40" width="10" height="60" rx="5" fill="rgba(255,255,255,0.2)"/>
                    <!-- Label area -->
                    <rect x="20" y="100" width="50" height="36" rx="8" fill="rgba(255,255,255,0.15)"/>
                    <rect x="26" y="108" width="38" height="4" rx="2" fill="rgba(255,255,255,0.4)"/>
                    <rect x="30" y="116" width="30" height="3" rx="2" fill="rgba(255,255,255,0.25)"/>
                    <rect x="33" y="122" width="24" height="3" rx="2" fill="rgba(255,255,255,0.2)"/>
                </svg>
            </div>

            <!-- Main bottle (center) -->
            <div class="bottle main">
                <svg width="140" height="240" viewBox="0 0 140 260" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <defs>
                        <clipPath id="mainCap"><rect x="14" y="36" width="112" height="214" rx="24"/></clipPath>
                        <linearGradient id="waterGrad" x1="0" y1="0" x2="1" y2="0">
                            <stop offset="0%" stop-color="rgba(56,191,248,0.6)"/>
                            <stop offset="100%" stop-color="rgba(79,115,214,0.5)"/>
                        </linearGradient>
                    </defs>
                    <!-- Handle -->
                    <path d="M100 55 Q126 55 126 80 Q126 100 106 100" stroke="rgba(255,255,255,0.3)" stroke-width="10" fill="none" stroke-linecap="round"/>
                    <!-- Cap -->
                    <rect x="44" y="4" width="52" height="34" rx="14" fill="rgba(255,255,255,0.4)"/>
                    <rect x="50" y="10" width="40" height="22" rx="10" fill="rgba(255,255,255,0.25)"/>
                    <!-- Body -->
                    <rect x="14" y="36" width="112" height="214" rx="24" fill="rgba(255,255,255,0.15)" stroke="rgba(255,255,255,0.4)" stroke-width="2"/>
                    <!-- Water -->
                    <rect class="water-fill" x="15" y="110" width="111" height="139" rx="0" clip-path="url(#mainCap)" fill="url(#waterGrad)"/>
                    <!-- Wave on water surface -->
                    <path d="M15 112 Q45 106 70 112 Q95 118 125 112" stroke="rgba(255,255,255,0.35)" stroke-width="1.5" fill="none" clip-path="url(#mainCap)"/>
                    <!-- Highlight streak -->
                    <rect x="26" y="50" width="16" height="90" rx="8" fill="rgba(255,255,255,0.2)"/>
                    <!-- Label band -->
                    <rect x="22" y="130" width="96" height="58" rx="12" fill="rgba(255,255,255,0.18)"/>
                    <rect x="32" y="140" width="76" height="5" rx="2.5" fill="rgba(255,255,255,0.5)"/>
                    <rect x="38" y="150" width="64" height="4" rx="2" fill="rgba(255,255,255,0.3)"/>
                    <rect x="44" y="158" width="52" height="4" rx="2" fill="rgba(255,255,255,0.22)"/>
                    <rect x="50" y="166" width="40" height="3.5" rx="2" fill="rgba(255,255,255,0.18)"/>
                    <!-- Bottom ridges -->
                    <rect x="24" y="222" width="92" height="5" rx="2.5" fill="rgba(255,255,255,0.1)"/>
                    <rect x="24" y="230" width="92" height="5" rx="2.5" fill="rgba(255,255,255,0.07)"/>
                    <rect x="24" y="238" width="92" height="5" rx="2.5" fill="rgba(255,255,255,0.05)"/>
                </svg>
            </div>

            <!-- Right bottle (side) -->
            <div class="bottle right">
                <svg width="90" height="180" viewBox="0 0 90 200" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <defs>
                        <clipPath id="rightCap"><rect x="14" y="28" width="62" height="162" rx="16"/></clipPath>
                    </defs>
                    <rect x="28" y="4" width="34" height="26" rx="10" fill="rgba(255,255,255,0.35)"/>
                    <rect x="14" y="28" width="62" height="162" rx="16" fill="rgba(255,255,255,0.18)" stroke="rgba(255,255,255,0.35)" stroke-width="1.5"/>
                    <rect class="water-fill" x="15" y="95" width="60" height="94" rx="0" clip-path="url(#rightCap)" fill="rgba(79,115,214,0.42)"/>
                    <rect x="22" y="40" width="10" height="60" rx="5" fill="rgba(255,255,255,0.2)"/>
                    <rect x="20" y="110" width="50" height="36" rx="8" fill="rgba(255,255,255,0.15)"/>
                    <rect x="26" y="118" width="38" height="4" rx="2" fill="rgba(255,255,255,0.4)"/>
                    <rect x="30" y="126" width="30" height="3" rx="2" fill="rgba(255,255,255,0.25)"/>
                    <rect x="33" y="132" width="24" height="3" rx="2" fill="rgba(255,255,255,0.2)"/>
                </svg>
            </div>
        </div>

        <!-- Pager -->
        <div class="pager" aria-hidden="true">
            <span class="dot active"></span>
            <span class="dot"></span>
        </div>

        <!-- Wave -->
        <div class="wave-wrap">
            <svg viewBox="0 0 500 70" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M0,30 C80,65 160,5 250,35 C340,65 420,8 500,30 L500,70 L0,70 Z" fill="white"/>
            </svg>
        </div>
    </section>

    <!-- ── Content ── -->
    <section class="content">
        <p class="eyebrow"><i class="fas fa-droplet" style="margin-right:5px;"></i>Pure Hydration, Every Refill</p>

        <h1 class="headline">Taste &amp; Feel<br><span>the Difference!</span></h1>

        <p class="sub">HydroMIS ensures clean, purified, and safe drinking water for every refill. Track orders, scan QR codes, and manage your water purchases with confidence.</p>

        <div class="chips">
            <span class="chip"><i class="fas fa-shield-alt"></i> Quality Assured</span>
            <span class="chip"><i class="fas fa-tint"></i> Daily Purification</span>
            <span class="chip"><i class="fas fa-qrcode"></i> Smart QR Tracking</span>
            <span class="chip"><i class="fas fa-truck"></i> Fast Delivery</span>
        </div>

        <div class="stats">
            <div class="stat">
                <div class="stat-num" data-target="500">0</div>
                <div class="stat-label">Happy Clients</div>
            </div>
            <div class="stat">
                <div class="stat-num" data-target="99">0<span style="font-size:12px">%</span></div>
                <div class="stat-label">Purity Rate</div>
            </div>
            <div class="stat">
                <div class="stat-num" data-target="24">0<span style="font-size:12px">h</span></div>
                <div class="stat-label">Service</div>
            </div>
        </div>

        <div class="actions">
            <a href="home.php" class="btn-primary" id="ctaBtn">
                <span>Get Started</span>
                <span class="arrow-icon"><i class="fas fa-arrow-right"></i></span>
            </a>
        </div>

        <p class="note">Continue to <a href="home.php">customer services</a> &amp; order tracking</p>
    </section>
</main>

<script>
    /* ── Counter animation ── */
    function animateCounter(el) {
        const parent = el.closest('.stat');
        if (!parent) return;
        const target = parseInt(el.dataset.target);
        const suffix = el.querySelector('span') ? el.querySelector('span').outerHTML : '';
        let start = null;
        const duration = 1600;
        function step(ts) {
            if (!start) start = ts;
            const progress = Math.min((ts - start) / duration, 1);
            const eased = 1 - Math.pow(1 - progress, 3);
            el.innerHTML = Math.round(eased * target) + suffix;
            if (progress < 1) requestAnimationFrame(step);
        }
        requestAnimationFrame(step);
    }

    const observer = new IntersectionObserver(entries => {
        entries.forEach(e => {
            if (e.isIntersecting) {
                document.querySelectorAll('.stat-num').forEach(animateCounter);
                observer.disconnect();
            }
        });
    }, { threshold: 0.5 });

    const statsEl = document.querySelector('.stats');
    if (statsEl) observer.observe(statsEl);

    /* ── Ripple on CTA ── */
    document.getElementById('ctaBtn').addEventListener('click', function(e) {
        const ripple = document.createElement('span');
        ripple.className = 'ripple';
        const rect = this.getBoundingClientRect();
        const size = Math.max(rect.width, rect.height);
        ripple.style.cssText = `width:${size}px;height:${size}px;left:${e.clientX - rect.left - size/2}px;top:${e.clientY - rect.top - size/2}px;`;
        this.appendChild(ripple);
        ripple.addEventListener('animationend', () => ripple.remove());
    });

    /* ── Pager auto-cycle ── */
    const dots = document.querySelectorAll('.dot');
    let active = 0;
    setInterval(() => {
        dots[active].classList.remove('active');
        active = (active + 1) % dots.length;
        dots[active].classList.add('active');
    }, 3000);
</script>
</body>
</html>