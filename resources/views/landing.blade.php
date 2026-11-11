<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CrediX — Smarter Financial Management</title>
    <link rel="icon" type="image/png" href="{{ asset('images/credix-favicon.png') }}">
    <link rel="shortcut icon" href="{{ asset('favicon.ico') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/credix-favicon.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700&family=Noto+Sans+Sinhala:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --bg-primary: #080503;
            --bg-deep: #050302;
            --brand-primary: #EC930B;
            --brand-hover: #F5A623;
            --brand-light: #FFBA53;
            --brand-dark: #9E4D03;
            --brand-glow: rgba(236, 147, 11, 0.45);
            --brand-glow-soft: rgba(236, 147, 11, 0.15);
            --text-hero: #FFFDF8;
            --text-secondary: #D5C6BA;
            --text-tagline: #A89587;
            --text-footer: #6F5E52;
            --badge-border: rgba(236, 147, 11, 0.22);
            --badge-bg: rgba(236, 147, 11, 0.06);
            --font-display: 'Space Grotesk', 'Noto Sans Sinhala', 'Manrope', system-ui, -apple-system, sans-serif;
            --font-currency: 'Space Grotesk', 'Noto Sans Sinhala', 'Iskoola Pota', 'Manrope', system-ui, sans-serif;
            --font-body: 'Manrope', 'Noto Sans Sinhala', system-ui, -apple-system, sans-serif;
        }

        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html, body {
            width: 100%;
            height: 100%;
            overflow: hidden;
            background-color: var(--bg-primary);
            color: var(--text-hero);
            font-family: var(--font-body);
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            user-select: none;
            -webkit-user-select: none;
        }

        /* ── Deep Warm Atmospheric Stage ── */
        .stage {
            position: relative;
            width: 100vw;
            height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            overflow: hidden;
            background:
                radial-gradient(ellipse 80% 60% at 50% 50%, rgba(38, 19, 8, 0.65) 0%, rgba(13, 7, 4, 0.85) 60%, var(--bg-deep) 100%),
                radial-gradient(circle at 20% 20%, rgba(236, 147, 11, 0.08) 0%, transparent 45%),
                radial-gradient(circle at 80% 80%, rgba(210, 106, 7, 0.06) 0%, transparent 50%),
                var(--bg-primary);
            cursor: default;
        }

        /* Subtle warm vignette on outer viewport */
        .stage::after {
            content: '';
            position: absolute;
            inset: 0;
            box-shadow: inset 0 0 160px rgba(5, 3, 2, 0.95);
            pointer-events: none;
            z-index: 4;
        }

        /* ── Page Load Intro Curtain ── */
        .intro-curtain {
            position: fixed;
            inset: 0;
            background: #000000;
            z-index: 100;
            pointer-events: none;
            animation: curtainFade 0.65s cubic-bezier(0.4, 0, 0.2, 1) 0.15s forwards;
        }

        @keyframes curtainFade {
            0% {
                opacity: 1;
                visibility: visible;
            }
            100% {
                opacity: 0;
                visibility: hidden;
            }
        }

        /* ── Center Breathing Aura ── */
        .center-aura {
            position: absolute;
            width: min(650px, 85vw);
            height: min(380px, 55vh);
            left: 50%;
            top: 50%;
            transform: translate(-50%, -50%);
            background: radial-gradient(ellipse at center, rgba(236, 147, 11, 0.22) 0%, rgba(226, 122, 10, 0.08) 45%, transparent 72%);
            filter: blur(55px);
            border-radius: 50%;
            pointer-events: none;
            z-index: 2;
            animation: auraBreathing 6s ease-in-out infinite alternate;
            transition: opacity 0.5s ease;
        }

        @keyframes auraBreathing {
            0% {
                opacity: 0.40;
                transform: translate(-50%, -50%) scale(0.94);
            }
            100% {
                opacity: 0.70;
                transform: translate(-50%, -50%) scale(1.06);
            }
        }

        /* ── Cursor Spotlight Glow ── */
        .cursor-glow {
            position: absolute;
            top: 0;
            left: 0;
            width: 300px;
            height: 300px;
            margin-left: -150px;
            margin-top: -150px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(236, 147, 11, 0.11) 0%, rgba(236, 147, 11, 0.03) 45%, transparent 70%);
            pointer-events: none;
            z-index: 3;
            will-change: transform, opacity;
            opacity: 0;
            transition: opacity 0.4s ease;
        }

        /* ── Floating Currencies Layer ── */
        .currency-field {
            position: absolute;
            inset: 0;
            overflow: hidden;
            pointer-events: none;
            z-index: 1;
            perspective: 1200px;
        }

        .currency-symbol {
            position: absolute;
            top: 0;
            left: 0;
            display: inline-block;
            font-family: var(--font-currency);
            font-weight: 500;
            letter-spacing: 0.04em;
            white-space: nowrap;
            will-change: transform, opacity;
            pointer-events: none;
            transform-style: preserve-3d;
            color: #F8B44A;
            /* Thin extrusion: 3 layers of 0.8px, glow radius max 3px */
            text-shadow:
                0 0.8px 0 #9E4D03,
                0 1.6px 0 #823D02,
                0 2.4px 0 #672F01,
                0 0 3px rgba(236, 147, 11, 0.45);
        }

        .currency-symbol.depth-far {
            filter: none;
            z-index: 1;
        }

        .currency-symbol.depth-mid {
            filter: none;
            z-index: 2;
        }

        .currency-symbol.depth-near {
            filter: blur(1px); /* Strictly max 1px blur */
            z-index: 3;
        }

        /* ── Twinkling Ambient Particles ── */
        .ambient-particles {
            position: absolute;
            inset: 0;
            overflow: hidden;
            pointer-events: none;
            z-index: 2;
        }

        .ember-particle {
            position: absolute;
            width: 2px;
            height: 2px;
            border-radius: 50%;
            background: #FFBA53;
            box-shadow: 0 0 5px 1px rgba(245, 166, 35, 0.7);
            opacity: 0.4;
            pointer-events: none;
            animation: emberTwinkle 4s ease-in-out infinite alternate;
        }

        @keyframes emberTwinkle {
            0% {
                opacity: 0.25;
                transform: translate3d(0, 0, 0) scale(0.85);
            }
            100% {
                opacity: 0.75;
                transform: translate3d(var(--drift-x, 15px), -24px, 0) scale(1.15);
            }
        }

        /* ── Background Shooting Glints ── */
        .glint-field {
            position: absolute;
            inset: 0;
            overflow: hidden;
            pointer-events: none;
            z-index: 2;
        }

        .shooting-glint {
            position: absolute;
            height: 1px;
            width: 140px;
            background: linear-gradient(90deg, transparent 0%, rgba(245, 166, 35, 0.4) 45%, rgba(255, 230, 180, 0.85) 80%, transparent 100%);
            box-shadow: 0 0 4px rgba(236, 147, 11, 0.45);
            pointer-events: none;
            opacity: 0;
            transform-origin: left center;
            transform: rotate(var(--glint-angle, -25deg));
            animation: glintTravel 1.1s cubic-bezier(0.2, 0.6, 0.3, 1) forwards;
        }

        @keyframes glintTravel {
            0% {
                opacity: 0;
                transform: rotate(var(--glint-angle, -25deg)) translate3d(0, 0, 0) scaleX(0.4);
            }
            30% {
                opacity: 0.55;
            }
            70% {
                opacity: 0.55;
            }
            100% {
                opacity: 0;
                transform: rotate(var(--glint-angle, -25deg)) translate3d(240px, 0, 0) scaleX(1);
            }
        }

        /* ── Centerpiece: CrediX Wordmark ── */
        .brand-container {
            position: relative;
            z-index: 10;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 1rem 2rem;
            pointer-events: auto;
            cursor: default;
        }

        .brand-title-wrap {
            position: relative;
            display: inline-flex;
            align-items: baseline;
            justify-content: center;
            transform-style: preserve-3d;
            perspective: 850px;
        }

        .brand-title {
            position: relative;
            font-family: var(--font-display);
            font-size: clamp(3.6rem, 9vw, 7.2rem);
            font-weight: 800;
            line-height: 1;
            letter-spacing: -0.035em;
            margin: 0;
            display: inline-flex;
            align-items: baseline;
            justify-content: center;
            transform-style: preserve-3d;
            transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .letter {
            display: inline-block;
            opacity: 0;
            transform: translateY(22px);
            filter: blur(8px);
            animation: letterReveal 0.65s cubic-bezier(0.16, 1, 0.3, 1) forwards;
            animation-delay: calc(0.22s + var(--i) * 0.11s);
            will-change: transform, opacity, filter;
        }

        @keyframes letterReveal {
            0% {
                opacity: 0;
                transform: translateY(22px);
                filter: blur(8px);
            }
            100% {
                opacity: 1;
                transform: translateY(0);
                filter: blur(0);
            }
        }

        .brand-title .stem {
            background: linear-gradient(180deg, #FFFFFF 15%, #F7EDE3 65%, #CBBBB0 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            filter: drop-shadow(0 8px 30px rgba(0, 0, 0, 0.9));
            transition: filter 0.4s ease;
        }

        .brand-title .accent-x {
            display: inline-block;
            margin-left: 1px;
            background: linear-gradient(135deg, #FFC978 0%, #F5A623 35%, #EC930B 70%, #C45E00 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            position: relative;
            animation:
                letterReveal 0.65s cubic-bezier(0.16, 1, 0.3, 1) 0.77s forwards,
                xIntroFlash 0.55s ease-out 1.20s 1,
                xGlowPulse 6s ease-in-out 1.85s infinite alternate;
        }

        @keyframes xIntroFlash {
            0% {
                filter: drop-shadow(0 0 20px rgba(236, 147, 11, 0.75));
            }
            40% {
                filter:
                    drop-shadow(0 0 45px rgba(255, 201, 120, 1))
                    drop-shadow(0 0 85px rgba(236, 147, 11, 0.95))
                    brightness(1.85);
            }
            100% {
                filter:
                    drop-shadow(0 0 22px rgba(236, 147, 11, 0.75))
                    drop-shadow(0 0 55px rgba(236, 147, 11, 0.35));
            }
        }

        @keyframes xGlowPulse {
            0% {
                filter:
                    drop-shadow(0 0 18px rgba(236, 147, 11, 0.70))
                    drop-shadow(0 0 45px rgba(236, 147, 11, 0.30));
            }
            100% {
                filter:
                    drop-shadow(0 0 32px rgba(245, 166, 35, 0.92))
                    drop-shadow(0 0 75px rgba(236, 147, 11, 0.55));
            }
        }

        /* ── Rebuilt Shimmer: Strictly Masked/Clipped to Text Glyphs ONLY ── */
        .wordmark-shimmer {
            position: absolute;
            inset: 0;
            display: inline-flex;
            align-items: baseline;
            justify-content: center;
            font-family: var(--font-display);
            font-size: clamp(3.6rem, 9vw, 7.2rem);
            font-weight: 800;
            line-height: 1;
            letter-spacing: -0.035em;
            pointer-events: none;
            z-index: 2;
            background: linear-gradient(
                110deg,
                rgba(255, 255, 255, 0) 0%,
                rgba(255, 255, 255, 0) 38%,
                rgba(255, 255, 255, 0.95) 50%,
                rgba(255, 205, 120, 0.90) 54%,
                rgba(255, 255, 255, 0) 66%,
                rgba(255, 255, 255, 0) 100%
            );
            background-size: 280% 100%;
            background-position: 140% 0;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            color: transparent;
            opacity: 0;
            mix-blend-mode: screen;
            transition: opacity 0.25s ease;
        }

        .wordmark-shimmer .shimmer-x {
            margin-left: 1px;
        }

        .wordmark-shimmer.active-loop {
            animation: textShimmerSweep 7s cubic-bezier(0.4, 0, 0.2, 1) infinite;
        }

        @keyframes textShimmerSweep {
            0% {
                background-position: 140% 0;
                opacity: 0;
            }
            3% {
                opacity: 1;
            }
            18% {
                background-position: -40% 0;
                opacity: 1;
            }
            22% {
                opacity: 0;
            }
            100% {
                background-position: -40% 0;
                opacity: 0;
            }
        }

        .wordmark-shimmer.shimmer-instant {
            animation: textShimmerInstant 1.2s cubic-bezier(0.2, 0.8, 0.2, 1) forwards !important;
        }

        @keyframes textShimmerInstant {
            0% {
                background-position: 140% 0;
                opacity: 0;
            }
            10% {
                opacity: 1;
            }
            85% {
                background-position: -40% 0;
                opacity: 1;
            }
            100% {
                background-position: -40% 0;
                opacity: 0;
            }
        }

        /* Hover interactions on the CrediX wordmark */
        .brand-container:hover .stem {
            filter:
                drop-shadow(0 8px 32px rgba(0, 0, 0, 0.95))
                drop-shadow(0 0 20px rgba(236, 147, 11, 0.35));
        }

        .brand-container:hover .accent-x {
            filter:
                drop-shadow(0 0 38px rgba(245, 166, 35, 1))
                drop-shadow(0 0 90px rgba(236, 147, 11, 0.85));
        }

        /* ── Tagline ── */
        .brand-tagline {
            font-family: var(--font-body);
            font-size: clamp(0.78rem, 1.5vw, 1.05rem);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.38em;
            color: var(--text-tagline);
            margin-top: 1.4rem;
            margin-bottom: 0;
            padding-left: 0.38em; /* optical balance */
            text-shadow: 0 2px 14px rgba(0, 0, 0, 0.9), 0 0 25px rgba(236, 147, 11, 0.22);
            opacity: 0;
            transform: translateY(10px);
            animation: taglineReveal 1.0s cubic-bezier(0.16, 1, 0.3, 1) 1.25s forwards;
            will-change: transform, opacity, letter-spacing;
        }

        @keyframes taglineReveal {
            0% {
                opacity: 0;
                letter-spacing: 0.65em;
                padding-left: 0.65em;
                transform: translateY(10px);
                filter: blur(4px);
            }
            100% {
                opacity: 1;
                letter-spacing: 0.38em;
                padding-left: 0.38em;
                transform: translateY(0);
                filter: blur(0);
            }
        }

        /* ── Footer & Version Badge ── */
        .page-footer {
            position: absolute;
            bottom: 2rem;
            left: 0;
            width: 100%;
            text-align: center;
            z-index: 10;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transform: translateY(8px);
            animation: footerReveal 0.8s ease-out 1.70s forwards;
            pointer-events: none;
        }

        @keyframes footerReveal {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .version-badge-container {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-bottom: 0.65rem;
            pointer-events: auto;
        }

        .version-badge {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 3px 11px;
            border-radius: 9999px;
            background: var(--badge-bg);
            border: 1px solid var(--badge-border);
            font-family: var(--font-display);
            font-size: 11px;
            font-weight: 500;
            letter-spacing: 0.12em;
            color: #9C8372;
            transition: all 0.3s ease;
            cursor: default;
            user-select: none;
        }

        .version-badge:hover {
            background: rgba(236, 147, 11, 0.12);
            border-color: rgba(236, 147, 11, 0.42);
            color: #D5C6BA;
            box-shadow: 0 0 12px rgba(236, 147, 11, 0.20);
        }

        .status-dot {
            width: 5px;
            height: 5px;
            border-radius: 50%;
            background: var(--brand-primary);
            box-shadow: 0 0 6px rgba(236, 147, 11, 0.8);
            animation: statusPulse 3s ease-in-out infinite;
        }

        @keyframes statusPulse {
            0%, 100% {
                opacity: 0.50;
                transform: scale(0.9);
                box-shadow: 0 0 4px rgba(236, 147, 11, 0.5);
            }
            50% {
                opacity: 1;
                transform: scale(1.15);
                box-shadow: 0 0 8px rgba(245, 166, 35, 0.95);
            }
        }

        .copyright-line {
            font-family: var(--font-body);
            font-size: clamp(0.70rem, 1.1vw, 0.82rem);
            font-weight: 400;
            letter-spacing: 0.08em;
            color: var(--text-footer);
            opacity: 0.75;
        }

        /* ── Click / Tap Ripple & Burst Particles ── */
        .burst-field {
            position: absolute;
            inset: 0;
            overflow: hidden;
            pointer-events: none;
            z-index: 15;
        }

        .click-ripple {
            position: absolute;
            border-radius: 50%;
            border: 1px solid rgba(245, 166, 35, 0.65);
            box-shadow: 0 0 10px rgba(236, 147, 11, 0.4);
            pointer-events: none;
            transform: translate(-50%, -50%) scale(0.2);
            animation: rippleExpand 0.85s cubic-bezier(0.1, 0.8, 0.3, 1) forwards;
        }

        @keyframes rippleExpand {
            0% {
                transform: translate(-50%, -50%) scale(0.2);
                opacity: 0.85;
            }
            100% {
                transform: translate(-50%, -50%) scale(4.5);
                opacity: 0;
            }
        }

        .burst-item {
            position: absolute;
            pointer-events: none;
            font-family: var(--font-currency);
            font-weight: 500;
            color: #F8B44A;
            text-shadow: 0 0 5px rgba(236, 147, 11, 0.85);
            transform: translate(-50%, -50%);
            animation: burstTravel 1.15s cubic-bezier(0.12, 0.85, 0.3, 1) forwards;
            white-space: nowrap;
        }

        @keyframes burstTravel {
            0% {
                opacity: 1;
                transform: translate(-50%, -50%) translate3d(0, 0, 0) scale(0.6) rotate(0deg);
            }
            75% {
                opacity: 0.75;
            }
            100% {
                opacity: 0;
                transform: translate(-50%, -50%) translate3d(var(--b-dx), var(--b-dy), 0) scale(0.95) rotate(var(--b-rot));
            }
        }

        /* ── Accessibility: Reduced Motion ── */
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
            .intro-curtain {
                display: none !important;
            }
            .letter {
                opacity: 1 !important;
                transform: none !important;
                filter: none !important;
            }
            .accent-x {
                animation: none !important;
                filter: drop-shadow(0 0 25px rgba(236, 147, 11, 0.8)) !important;
            }
            .brand-tagline {
                opacity: 1 !important;
                transform: none !important;
                filter: none !important;
                letter-spacing: 0.38em !important;
            }
            .page-footer {
                opacity: 1 !important;
                transform: none !important;
            }
            .center-aura {
                animation: none !important;
                opacity: 0.55 !important;
            }
            .cursor-glow, .wordmark-shimmer, .shooting-glint, .click-ripple, .burst-item {
                display: none !important;
            }
        }

        /* Responsive Breakpoints */
        @media (max-width: 768px) {
            .brand-tagline {
                letter-spacing: 0.24em;
                padding-left: 0.24em;
            }
            .page-footer {
                bottom: 1.4rem;
            }
            .version-badge {
                font-size: 10.5px;
                padding: 2.5px 9px;
            }
        }

        @media (max-width: 480px) {
            .brand-tagline {
                letter-spacing: 0.18em;
                padding-left: 0.18em;
            }
        }
    </style>
</head>
<body>
    <div class="stage" id="stage">
        <!-- Page Load Intro Curtain -->
        <div class="intro-curtain" id="introCurtain" aria-hidden="true"></div>

        <!-- Cursor Spotlight Glow -->
        <div class="cursor-glow" id="cursorGlow" aria-hidden="true"></div>

        <!-- Ambient Center Breathing Aura -->
        <div class="center-aura" id="centerAura" aria-hidden="true"></div>

        <!-- Ambient Twinkling Sparks -->
        <div class="ambient-particles" id="ambientParticles" aria-hidden="true"></div>

        <!-- Faint Background Light Streaks / Glints -->
        <div class="glint-field" id="glintField" aria-hidden="true"></div>

        <!-- Floating Sri Lankan Currency Symbols (Behind Branding) -->
        <div class="currency-field" id="currencyField" aria-hidden="true"></div>

        <!-- Interactive Click / Tap Burst Field -->
        <div class="burst-field" id="burstField" aria-hidden="true"></div>

        <!-- Center CrediX Branding -->
        <main class="brand-container" id="brandContainer">
            <div class="brand-title-wrap">
                <h1 class="brand-title" id="brandTitle">
                    <span class="letter stem" style="--i:0">C</span><span class="letter stem" style="--i:1">r</span><span class="letter stem" style="--i:2">e</span><span class="letter stem" style="--i:3">d</span><span class="letter stem" style="--i:4">i</span><span class="letter accent-x" id="brandX" style="--i:5">X</span>
                </h1>
                <!-- Shimmer is strictly confined inside the text glyphs via background-clip: text -->
                <div class="wordmark-shimmer" id="wordmarkShimmer" aria-hidden="true">
                    <span class="shimmer-stem">Credi</span><span class="shimmer-x">X</span>
                </div>
            </div>
            <p class="brand-tagline">Smarter Financial Management</p>
        </main>

        <!-- Minimal Footer with Version Badge -->
        <footer class="page-footer">
            <div class="version-badge-container">
                <div class="version-badge" title="CrediX Core">
                    <span class="status-dot" aria-hidden="true"></span>
                    <span class="version-text">{{ config('app.version', env('APP_VERSION', 'v1.0.0')) }}</span>
                </div>
            </div>
            <p class="copyright-line">&copy; 2026 CrediX. All rights reserved.</p>
        </footer>
    </div>

    <script>
        (function() {
            'use strict';

            // STRICT SRI LANKAN CURRENCIES ONLY: NO DOLLAR SYMBOLS ANYWHERE
            var SYMBOLS = ['LKR', 'රු', 'Rs'];

            var stage = document.getElementById('stage');
            var container = document.getElementById('currencyField');
            var burstField = document.getElementById('burstField');
            var glintField = document.getElementById('glintField');
            var particlesField = document.getElementById('ambientParticles');
            var cursorGlow = document.getElementById('cursorGlow');
            var brandContainer = document.getElementById('brandContainer');
            var brandTitleWrap = document.querySelector('.brand-title-wrap');
            var wordmarkShimmer = document.getElementById('wordmarkShimmer');

            var prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            // Viewport dimensions
            var vw = window.innerWidth;
            var vh = window.innerHeight;

            // Exclusion zone around centre (about 55vw x 28vh on desktop): no symbol passes through
            function getExclusionBounds() {
                var zoneW = Math.max(300, Math.min(vw * 0.55, 820));
                var zoneH = Math.max(170, Math.min(vh * 0.28, 300));
                var cx = vw / 2;
                var cy = vh / 2;
                return {
                    left: cx - (zoneW / 2),
                    right: cx + (zoneW / 2),
                    top: cy - (zoneH / 2),
                    bottom: cy + (zoneH / 2)
                };
            }

            var exclusion = getExclusionBounds();

            // Reduced Motion Mode: Render static, elegant distribution outside exclusion zone
            if (prefersReducedMotion) {
                var staticCount = 16;
                for (var s = 0; s < staticCount; s++) {
                    var staticEl = document.createElement('div');
                    var depthClass = (s % 6 === 0) ? 'depth-near' : ((s % 2 === 0) ? 'depth-mid' : 'depth-far');
                    staticEl.className = 'currency-symbol ' + depthClass;
                    staticEl.textContent = SYMBOLS[s % SYMBOLS.length];

                    var xPercent = 5 + (s * 6.0);
                    var yPercent = 8 + ((s * 19) % 78);
                    staticEl.style.left = xPercent + '%';
                    staticEl.style.top = yPercent + '%';
                    staticEl.style.fontSize = (s % 3 === 0) ? '28px' : '18px';
                    staticEl.style.opacity = '0.22';
                    container.appendChild(staticEl);
                }
                return;
            }

            // ── Target counts per screen size: Desktop 55, Tablet 38, Mobile 22 ──
            function getSymbolCount() {
                if (vw >= 1024) return 55;
                if (vw >= 641) return 38;
                return 22;
            }

            var targetCount = getSymbolCount();
            var symbolsData = [];

            // Helper to generate a single symbol's configuration
            function createSymbolConfig(index, total) {
                // Tier distribution: FAR 50%, MID 42%, NEAR 8%
                var nearThreshold = Math.max(1, Math.round(total * 0.08));
                var midThreshold = nearThreshold + Math.round(total * 0.42);

                // HARD CAP: Max 60px desktop, 44px mobile anywhere
                var maxAllowedSize = (vw <= 640) ? 44 : 60;

                var tier;
                var fontSize;
                var baseOpacity;
                var blurAmount;
                var duration;
                var maxParallax;

                if (index < nearThreshold) {
                    // NEAR layer (8%): 40–60px (desktop) / max 44px (mobile), opacity 0.45–0.60, blur max 1px, speed 12–18s
                    tier = 'depth-near';
                    var nearMin = (vw <= 640) ? 30 : 40;
                    var nearMax = (vw <= 640) ? 44 : 60;
                    fontSize = Math.round(nearMin + Math.random() * (nearMax - nearMin));
                    if (fontSize > maxAllowedSize) fontSize = maxAllowedSize;

                    baseOpacity = 0.45 + Math.random() * 0.15;
                    blurAmount = '1px'; // Max 1px blur strictly
                    duration = 12 + Math.random() * 6; // 12–18s
                    maxParallax = 25; // max 25px parallax
                } else if (index < midThreshold) {
                    // MID layer (42%): 20–34px, opacity 0.50–0.70, no blur, speed 16–24s
                    tier = 'depth-mid';
                    var midMin = (vw <= 640) ? 18 : 20;
                    var midMax = (vw <= 640) ? 26 : 34;
                    fontSize = Math.round(midMin + Math.random() * (midMax - midMin));
                    if (fontSize > maxAllowedSize) fontSize = maxAllowedSize;

                    baseOpacity = 0.50 + Math.random() * 0.20;
                    blurAmount = 'none';
                    duration = 16 + Math.random() * 8; // 16–24s
                    maxParallax = 14;
                } else {
                    // FAR layer (50%): 12–20px, opacity 0.30–0.45, no blur, speed 24–34s
                    tier = 'depth-far';
                    var farMin = 12;
                    var farMax = (vw <= 640) ? 16 : 20;
                    fontSize = Math.round(farMin + Math.random() * (farMax - farMin));
                    if (fontSize > maxAllowedSize) fontSize = maxAllowedSize;

                    baseOpacity = 0.30 + Math.random() * 0.15;
                    blurAmount = 'none';
                    duration = 24 + Math.random() * 10; // 24–34s
                    maxParallax = 6;
                }

                var el = document.createElement('div');
                el.className = 'currency-symbol ' + tier;
                el.textContent = SYMBOLS[index % SYMBOLS.length];
                el.style.fontSize = fontSize + 'px';
                if (blurAmount !== 'none') {
                    el.style.filter = 'blur(' + blurAmount + ')';
                }

                // Initial horizontal position distributed across screen width
                var marginX = 15;
                var baseX = marginX + Math.random() * (vw - marginX * 2);

                // Initial vertical position staggered throughout entire viewport
                var travelHeight = vh + 180;
                var initialProgress = Math.random();
                var startY = (vh + 90) - (initialProgress * travelHeight);

                var speedY = travelHeight / duration; // px per second

                // Tumbling properties: rotateX & rotateY limited to roughly ±55° (never thin slivers)
                var ampX = 18 + Math.random() * 32; // max ~50deg <= 55deg
                var freqX = 0.20 + Math.random() * 0.22;
                var phaseX = Math.random() * Math.PI * 2;

                var ampY = 18 + Math.random() * 32; // max ~50deg <= 55deg
                var freqY = 0.16 + Math.random() * 0.20;
                var phaseY = Math.random() * Math.PI * 2;

                // RotateZ gentle drift
                var speedZ = (Math.random() - 0.5) * 8; // very slow, graceful drift
                var currentRotZ = Math.random() * 360;

                // Horizontal gentle drift
                var driftAmp = 14 + Math.random() * 20;
                var driftFreq = 0.10 + Math.random() * 0.16;
                var driftPhase = Math.random() * Math.PI * 2;

                container.appendChild(el);

                return {
                    el: el,
                    tier: tier,
                    fontSize: fontSize,
                    baseOpacity: baseOpacity,
                    maxParallax: maxParallax,
                    baseX: baseX,
                    y: startY,
                    speedY: speedY,
                    ampX: ampX,
                    freqX: freqX,
                    phaseX: phaseX,
                    ampY: ampY,
                    freqY: freqY,
                    phaseY: phaseY,
                    speedZ: speedZ,
                    rotZ: currentRotZ,
                    driftAmp: driftAmp,
                    driftFreq: driftFreq,
                    driftPhase: driftPhase,
                    // Interactive states
                    pushX: 0,
                    pushY: 0,
                    glowBoost: 0,
                    zoneOpacity: 1.0
                };
            }

            function initSymbols() {
                container.innerHTML = '';
                symbolsData = [];
                targetCount = getSymbolCount();
                for (var i = 0; i < targetCount; i++) {
                    symbolsData.push(createSymbolConfig(i, targetCount));
                }
            }

            initSymbols();

            // ── Subtle Ambient Twinkling Particles ──
            function initParticles() {
                particlesField.innerHTML = '';
                var pCount = (vw >= 768) ? 14 : 8;
                for (var p = 0; p < pCount; p++) {
                    var spark = document.createElement('div');
                    spark.className = 'ember-particle';
                    spark.style.left = (Math.random() * 94 + 3) + '%';
                    spark.style.top = (Math.random() * 90 + 5) + '%';
                    spark.style.setProperty('--drift-x', (Math.random() * 30 - 15) + 'px');
                    var pDur = 3.5 + Math.random() * 3.5;
                    var pDelay = -(Math.random() * pDur);
                    spark.style.animationDuration = pDur.toFixed(1) + 's';
                    spark.style.animationDelay = pDelay.toFixed(1) + 's';
                    particlesField.appendChild(spark);
                }
            }

            initParticles();

            // ── Background Shooting Glints Generator (every 5–9s) ──
            function spawnGlint() {
                if (prefersReducedMotion || document.hidden) return;
                var glint = document.createElement('div');
                glint.className = 'shooting-glint';

                var startX = Math.random() * (vw * 0.7);
                var startY = Math.random() * (vh * 0.8);
                var angle = -22 + (Math.random() * 8 - 4);

                glint.style.left = startX + 'px';
                glint.style.top = startY + 'px';
                glint.style.setProperty('--glint-angle', angle.toFixed(1) + 'deg');

                glintField.appendChild(glint);

                glint.addEventListener('animationend', function() {
                    if (glint.parentNode) {
                        glint.parentNode.removeChild(glint);
                    }
                });

                var nextInterval = 5000 + Math.random() * 4000;
                setTimeout(spawnGlint, nextInterval);
            }

            setTimeout(spawnGlint, 4500);

            // ── Shimmer Loop Activation (starts after intro sequence ~2.2s) ──
            setTimeout(function() {
                if (wordmarkShimmer && !prefersReducedMotion) {
                    wordmarkShimmer.classList.add('active-loop');
                }
            }, 2200);

            // Hover on CrediX: trigger shimmer immediately
            function playShimmerInstant() {
                if (!wordmarkShimmer || prefersReducedMotion) return;
                wordmarkShimmer.classList.remove('shimmer-instant');
                void wordmarkShimmer.offsetWidth; // reflow
                wordmarkShimmer.classList.add('shimmer-instant');
            }

            if (brandContainer) {
                brandContainer.addEventListener('mouseenter', function() {
                    playShimmerInstant();
                });
            }

            // ── Mouse & Touch Coordinates Tracking ──
            var mouseX = vw / 2;
            var mouseY = vh / 2;
            var smoothNormX = 0;
            var smoothNormY = 0;
            var cursorGlowX = vw / 2;
            var cursorGlowY = vh / 2;
            var isCursorActive = false;
            var hasMouseMoved = false;

            function updateCursorPos(cx, cy) {
                mouseX = cx;
                mouseY = cy;
                isCursorActive = true;
                hasMouseMoved = true;
                if (cursorGlow) {
                    cursorGlow.style.opacity = '1';
                }
            }

            window.addEventListener('mousemove', function(e) {
                updateCursorPos(e.clientX, e.clientY);
            }, { passive: true });

            window.addEventListener('mouseleave', function() {
                isCursorActive = false;
                if (cursorGlow) {
                    cursorGlow.style.opacity = '0';
                }
            });

            window.addEventListener('touchstart', function(e) {
                if (e.touches && e.touches.length > 0) {
                    updateCursorPos(e.touches[0].clientX, e.touches[0].clientY);
                }
            }, { passive: true });

            window.addEventListener('touchmove', function(e) {
                if (e.touches && e.touches.length > 0) {
                    updateCursorPos(e.touches[0].clientX, e.touches[0].clientY);
                }
            }, { passive: true });

            window.addEventListener('touchend', function() {
                isCursorActive = false;
                if (cursorGlow) {
                    cursorGlow.style.opacity = '0';
                }
            });

            // Gyroscope Parallax on mobile devices
            if (window.DeviceOrientationEvent) {
                window.addEventListener('deviceorientation', function(e) {
                    if (e.gamma !== null && e.beta !== null) {
                        var clampedGamma = Math.max(-45, Math.min(45, e.gamma));
                        var clampedBeta = Math.max(-30, Math.min(60, e.beta - 30));
                        smoothNormX += ((clampedGamma / 45) - smoothNormX) * 0.08;
                        smoothNormY += ((clampedBeta / 45) - smoothNormY) * 0.08;
                        hasMouseMoved = true;
                    }
                }, { passive: true });
            }

            // ── Click / Tap Burst Interaction ──
            function createBurst(cx, cy) {
                if (prefersReducedMotion || !burstField) return;

                // 1. Soft ripple expanding ring
                var ripple = document.createElement('div');
                ripple.className = 'click-ripple';
                ripple.style.left = cx + 'px';
                ripple.style.top = cy + 'px';
                ripple.style.width = '20px';
                ripple.style.height = '20px';
                burstField.appendChild(ripple);

                ripple.addEventListener('animationend', function() {
                    if (ripple.parentNode) ripple.parentNode.removeChild(ripple);
                });

                // 2. Burst of 7–9 tiny symbols and glowing particles
                var burstCount = 7 + Math.floor(Math.random() * 3);
                for (var b = 0; b < burstCount; b++) {
                    var item = document.createElement('div');
                    item.className = 'burst-item';
                    item.style.left = cx + 'px';
                    item.style.top = cy + 'px';

                    var angle = (b / burstCount) * (Math.PI * 2) + (Math.random() * 0.4 - 0.2);
                    var distance = 35 + Math.random() * 45;
                    var dx = Math.cos(angle) * distance;
                    var dy = Math.sin(angle) * distance;
                    var rot = (Math.random() * 100 - 50) + 'deg';

                    item.style.setProperty('--b-dx', dx.toFixed(1) + 'px');
                    item.style.setProperty('--b-dy', dy.toFixed(1) + 'px');
                    item.style.setProperty('--b-rot', rot);

                    if (b % 2 === 0) {
                        item.textContent = SYMBOLS[b % SYMBOLS.length];
                        item.style.fontSize = (11 + Math.random() * 3) + 'px';
                    } else {
                        // Tiny glowing particle dot
                        item.textContent = '•';
                        item.style.fontSize = '14px';
                        item.style.color = '#FFBA53';
                    }

                    burstField.appendChild(item);

                    (function(el) {
                        el.addEventListener('animationend', function() {
                            if (el.parentNode) el.parentNode.removeChild(el);
                        });
                    })(item);
                }
            }

            stage.addEventListener('pointerdown', function(e) {
                createBurst(e.clientX, e.clientY);
            });

            // ── Tab Visibility Pause Management ──
            var isTabHidden = false;
            var lastTime = performance.now();

            document.addEventListener('visibilitychange', function() {
                isTabHidden = document.hidden;
                if (!isTabHidden) {
                    lastTime = performance.now();
                }
            });

            // ── Main Simulation & Animation Loop (requestAnimationFrame) ──
            var simTime = 0;

            function animationLoop(currentTime) {
                requestAnimationFrame(animationLoop);

                if (isTabHidden) return;

                var dt = (currentTime - lastTime) / 1000;
                lastTime = currentTime;

                // Cap dt to prevent jumps after long stalls
                if (dt > 0.1) dt = 0.1;
                simTime += dt;

                // Smooth cursor glow tracking
                if (isCursorActive && cursorGlow) {
                    cursorGlowX += (mouseX - cursorGlowX) * 0.14;
                    cursorGlowY += (mouseY - cursorGlowY) * 0.14;
                    cursorGlow.style.transform = 'translate3d(' + cursorGlowX.toFixed(1) + 'px,' + cursorGlowY.toFixed(1) + 'px,0)';
                }

                // Target normalized cursor coordinates [-1, 1]
                var targetNormX = hasMouseMoved ? ((mouseX / vw) * 2 - 1) : 0;
                var targetNormY = hasMouseMoved ? ((mouseY / vh) * 2 - 1) : 0;

                smoothNormX += (targetNormX - smoothNormX) * 0.08;
                smoothNormY += (targetNormY - smoothNormY) * 0.08;

                // CrediX Wordmark Tilt towards cursor (max 3–4° with perspective)
                if (brandTitleWrap) {
                    var tiltY = smoothNormX * 3.4; // deg
                    var tiltX = -smoothNormY * 3.4; // deg
                    brandTitleWrap.style.transform = 'perspective(850px) rotateX(' + tiltX.toFixed(2) + 'deg) rotateY(' + tiltY.toFixed(2) + 'deg)';
                }

                // Update each floating symbol
                for (var i = 0; i < symbolsData.length; i++) {
                    var s = symbolsData[i];

                    // Move upward smoothly
                    s.y -= s.speedY * dt;

                    // When symbol crosses top of stage, recycle to bottom
                    if (s.y < -90) {
                        s.y = vh + 80 + (Math.random() * 30);
                        s.baseX = 15 + Math.random() * (vw - 30);
                    }

                    // Gentle horizontal drift
                    var driftX = Math.sin(simTime * s.driftFreq + s.driftPhase) * s.driftAmp;
                    var currentX = s.baseX + driftX;
                    var currentY = s.y;

                    // 1. Exclusion Zone Check: no symbol passes through or over CrediX wordmark or tagline
                    // Fade strictly to 0 inside zone
                    var isInsideExclusion = (
                        currentX >= exclusion.left &&
                        currentX <= exclusion.right &&
                        currentY >= exclusion.top &&
                        currentY <= exclusion.bottom
                    );

                    var targetZoneOpacity = 1.0;
                    if (isInsideExclusion) {
                        targetZoneOpacity = 0.0; // Strictly 0 inside zone
                    } else {
                        // Smooth proximity buffer around exclusion zone
                        var padX = 55;
                        var padY = 45;
                        var nearLeft = currentX >= (exclusion.left - padX) && currentX <= (exclusion.right + padX);
                        var nearTop = currentY >= (exclusion.top - padY) && currentY <= (exclusion.bottom + padY);

                        if (nearLeft && nearTop) {
                            var distToBorderX = Math.min(Math.abs(currentX - exclusion.left), Math.abs(currentX - exclusion.right));
                            var distToBorderY = Math.min(Math.abs(currentY - exclusion.top), Math.abs(currentY - exclusion.bottom));
                            var factorX = distToBorderX / padX;
                            var factorY = distToBorderY / padY;
                            targetZoneOpacity = Math.min(factorX, factorY);
                        }
                    }

                    s.zoneOpacity += (targetZoneOpacity - s.zoneOpacity) * 0.14;

                    // 2. Cursor Interaction: push away gently within 140px and glow brighter, then ease back
                    var pushTargetX = 0;
                    var pushTargetY = 0;
                    var glowBoostTarget = 0;

                    if (isCursorActive && hasMouseMoved) {
                        var distDx = currentX - mouseX;
                        var distDy = currentY - mouseY;
                        var dist = Math.hypot(distDx, distDy);

                        if (dist < 140 && dist > 0.5) {
                            var repulsionFactor = (1 - (dist / 140));
                            var pushMagnitude = repulsionFactor * 38; // up to ~38px
                            pushTargetX = (distDx / dist) * pushMagnitude;
                            pushTargetY = (distDy / dist) * pushMagnitude;
                            glowBoostTarget = repulsionFactor;
                        }
                    }

                    s.pushX += (pushTargetX - s.pushX) * 0.12;
                    s.pushY += (pushTargetY - s.pushY) * 0.12;
                    s.glowBoost += (glowBoostTarget - s.glowBoost) * 0.12;

                    // 3. Mouse Parallax (opposite to cursor): deeper FAR moves less (~6px), NEAR moves more (~25px)
                    var paraX = -smoothNormX * s.maxParallax;
                    var paraY = -smoothNormY * s.maxParallax;

                    // 4. Tumbling: rotateX and rotateY oscillate smoothly within ±55°, rotateZ spins slowly
                    var rx = Math.sin(simTime * s.freqX + s.phaseX) * s.ampX;
                    var ry = Math.cos(simTime * s.freqY + s.phaseY) * s.ampY;
                    var rz = s.rotZ + (simTime * s.speedZ);

                    // Final rendered coordinates (NO scale factor > 1)
                    var renderX = currentX + s.pushX + paraX;
                    var renderY = currentY + s.pushY + paraY;

                    // Final opacity strictly multiplied by zoneOpacity so inside exclusion zone it is 0
                    var finalOpacity = Math.max(0, Math.min(1.0, (s.baseOpacity + s.glowBoost * 0.25) * s.zoneOpacity));

                    // Apply styles
                    s.el.style.transform =
                        'translate3d(' + renderX.toFixed(1) + 'px,' + renderY.toFixed(1) + 'px,0) ' +
                        'rotateX(' + rx.toFixed(1) + 'deg) rotateY(' + ry.toFixed(1) + 'deg) rotateZ(' + rz.toFixed(1) + 'deg)';
                    s.el.style.opacity = finalOpacity.toFixed(3);

                    // Interactive brightened glow when pushed by cursor
                    if (s.glowBoost > 0.05 && s.zoneOpacity > 0.05) {
                        var glowSpread = (3 + Math.round(s.glowBoost * 8));
                        var glowAlpha = (0.45 + s.glowBoost * 0.40).toFixed(2);
                        s.el.style.textShadow =
                            '0 0.8px 0 #9E4D03, 0 1.6px 0 #823D02, 0 2.4px 0 #672F01, 0 0 ' +
                            glowSpread + 'px rgba(245, 166, 35, ' + glowAlpha + ')';
                    } else {
                        s.el.style.textShadow = '';
                    }
                }
            }

            requestAnimationFrame(animationLoop);

            // ── Window Resize Handler ──
            var resizeDebounce = null;
            window.addEventListener('resize', function() {
                clearTimeout(resizeDebounce);
                resizeDebounce = setTimeout(function() {
                    vw = window.innerWidth;
                    vh = window.innerHeight;
                    exclusion = getExclusionBounds();

                    // Adjust symbol count if screen class changed
                    var newCount = getSymbolCount();
                    if (newCount !== targetCount) {
                        initSymbols();
                    }
                    initParticles();
                }, 150);
            }, { passive: true });

        })();
    </script>
</body>
</html>
