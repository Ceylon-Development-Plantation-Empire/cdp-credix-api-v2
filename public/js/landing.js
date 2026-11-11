/* ============================================================
   CrediX — Cinematic 3D Money Rain Engine
   Pure vanilla JS, no libraries.
   STRICT: LKR, රු, Rs ONLY — NO dollar ($) symbols ANYWHERE
   ============================================================ */
(function () {
    'use strict';

    // ── Labels: Sri Lankan currency ONLY ──
    var LABELS = ['LKR', '\u0DBB\u0DD4', 'Rs'];

    // ── Depth tier config ──
    // FAR  40%: 28–50px, opacity 0.45, blur 0, slow 16–24s
    // MID  40%: 60–110px, opacity 0.85, blur 0, med 9–15s
    // NEAR 20%: 140–240px, opacity 0.9, blur 5–10px, fast 5–9s
    var TIERS = {
        far:  { prob: 0.40, sizeMin: 28,  sizeMax: 50,  opacity: 0.45, blur: 0,  durMin: 16, durMax: 24 },
        mid:  { prob: 0.40, sizeMin: 60,  sizeMax: 110, opacity: 0.85, blur: 0,  durMin: 9,  durMax: 15 },
        near: { prob: 0.20, sizeMin: 140, sizeMax: 240, opacity: 0.90, blur: 0,  durMin: 5,  durMax: 9  }
    };

    // ── Responsive counts ──
    function getCount() {
        var w = window.innerWidth;
        if (w < 640) return 16;
        if (w < 1024) return 26;
        return 36;
    }

    function getLayerCount() {
        return window.innerWidth < 640 ? 4 : 8;
    }

    function getParticleCount() {
        var w = window.innerWidth;
        if (w < 640) return 12;
        return 18;
    }

    // ── Helpers ──
    function rand(a, b) { return Math.random() * (b - a) + a; }
    function randInt(a, b) { return Math.floor(rand(a, b + 1)); }
    function pick(arr) { return arr[Math.floor(Math.random() * arr.length)]; }

    function pickTier() {
        var r = Math.random();
        if (r < TIERS.far.prob) return 'far';
        if (r < TIERS.far.prob + TIERS.mid.prob) return 'mid';
        return 'near';
    }

    // ── Jittered grid positions ──
    // Split viewport into a grid to prevent clumps and empty areas
    function jitteredPositions(count) {
        var cols = Math.ceil(Math.sqrt(count * 1.6));
        var rows = Math.ceil(count / cols);
        var cellW = 100 / cols;
        var cellH = 100 / rows;
        var positions = [];
        for (var r = 0; r < rows; r++) {
            for (var c = 0; c < cols; c++) {
                if (positions.length >= count) break;
                positions.push({
                    x: cellW * c + rand(cellW * 0.1, cellW * 0.9),
                    y: cellH * r + rand(cellH * 0.1, cellH * 0.9)
                });
            }
        }
        // Shuffle
        for (var i = positions.length - 1; i > 0; i--) {
            var j = Math.floor(Math.random() * (i + 1));
            var tmp = positions[i];
            positions[i] = positions[j];
            positions[j] = tmp;
        }
        return positions;
    }

    // ── Detect reduced motion ──
    var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // ── Scene container ──
    var scene = document.getElementById('scene');
    var particleField = document.getElementById('particleField');
    if (!scene) return;

    // ── Track animations for visibility pause ──
    var allAnimations = [];
    var isPaused = false;

    // ═══════════════════════════════════════════
    // CREATE A SINGLE 3D EXTRUDED SYMBOL
    // ═══════════════════════════════════════════
    function createSymbol(tierName, startPos, initialDelay) {
        var tier = TIERS[tierName];
        var layers = getLayerCount();
        var fontSize = rand(tier.sizeMin, tier.sizeMax);
        var label = pick(LABELS);
        var thicknessScale = fontSize / 80; // scale extrusion with size
        var layerSpacing = 1.5 * thicknessScale;

        // Wrapper
        var wrap = document.createElement('div');
        wrap.className = 'sym-wrap tier-' + tierName;
        wrap.style.position = 'absolute';

        // Apply depth-of-field blur for NEAR symbols
        if (tierName === 'near') {
            wrap.style.filter = 'blur(' + rand(5, 10).toFixed(1) + 'px)';
        }

        // z-index ordering: far=1, mid=3, near=4 (logo is 10)
        if (tierName === 'far') wrap.style.zIndex = '1';
        else if (tierName === 'mid') wrap.style.zIndex = '3';
        else wrap.style.zIndex = '4';

        // Build extrusion layers
        var backColors = ['#2A1400', '#321900', '#3A1E00', '#452500', '#502C00', '#5C3200', '#6E3C00', '#7A4200'];
        for (var i = 0; i < layers; i++) {
            var span = document.createElement('span');
            span.className = 'sym-layer';
            span.textContent = label;
            span.style.fontSize = fontSize + 'px';
            span.style.transform = 'translateZ(' + (i * layerSpacing).toFixed(1) + 'px)';

            if (i < layers - 1) {
                // Back layers
                span.classList.add('back-' + Math.min(i, 6));
                span.style.color = backColors[Math.min(i, backColors.length - 1)];
            } else {
                // Front layer: glossy gradient
                span.classList.add('front');
                span.setAttribute('data-text', label);
                // Animate the gradient position for lighting effect
                var shineDur = rand(3, 6);
                span.style.backgroundSize = '250% 250%';
                span.style.animation = 'shineShift ' + shineDur.toFixed(1) + 's ease-in-out infinite';
            }
            wrap.appendChild(span);
        }

        // ── Motion: diagonal crossing + 3D tumble ──
        var duration = rand(tier.durMin, tier.durMax) * 1000; // ms

        // Start and end positions: travel top-right to bottom-left (or variations)
        var angle = rand(-35, 35); // degrees of sideways drift direction
        var driftVW = rand(10, 35); // vw of sideways travel
        var dirX = Math.random() > 0.5 ? 1 : -1;

        // Start just outside top/right edge, end just outside bottom/left edge
        var startX, startY, endX, endY;

        // Randomize entry from top-ish area
        startX = startPos.x; // percentage from the jittered grid (for initial coverage)
        startY = -15; // above viewport
        endX = startX + dirX * driftVW;
        endY = 115; // below viewport

        // Convert to vw/vh for keyframes
        var kfStart = 'translate3d(' + startX.toFixed(1) + 'vw, ' + startY.toFixed(1) + 'vh, 0)';
        var kfEnd   = 'translate3d(' + endX.toFixed(1) + 'vw, ' + endY.toFixed(1) + 'vh, 0)';

        // 3D tumble: each axis gets at least 360 degrees
        var rxSpeed = rand(1, 3) * (Math.random() > 0.5 ? 1 : -1); // full rotations
        var rySpeed = rand(1, 2.5) * (Math.random() > 0.5 ? 1 : -1);
        var rzSpeed = rand(0.8, 2) * (Math.random() > 0.5 ? 1 : -1);

        var rxStart = rand(0, 360), ryStart = rand(0, 360), rzStart = rand(0, 360);
        var rxEnd = rxStart + rxSpeed * 360;
        var ryEnd = ryStart + rySpeed * 360;
        var rzEnd = rzStart + rzSpeed * 360;

        // Midpoint for organic feel
        var midX = (startX + endX) / 2 + rand(-5, 5);
        var midY = 50 + rand(-15, 15);
        var rxMid = (rxStart + rxEnd) / 2 + rand(-30, 30);
        var ryMid = (ryStart + ryEnd) / 2 + rand(-30, 30);
        var rzMid = (rzStart + rzEnd) / 2 + rand(-20, 20);

        var keyframes = [
            {
                transform: kfStart + ' rotateX(' + rxStart.toFixed(0) + 'deg) rotateY(' + ryStart.toFixed(0) + 'deg) rotateZ(' + rzStart.toFixed(0) + 'deg)',
                opacity: tier.opacity
            },
            {
                transform: 'translate3d(' + midX.toFixed(1) + 'vw, ' + midY.toFixed(1) + 'vh, 0) rotateX(' + rxMid.toFixed(0) + 'deg) rotateY(' + ryMid.toFixed(0) + 'deg) rotateZ(' + rzMid.toFixed(0) + 'deg)',
                opacity: tier.opacity,
                offset: 0.5
            },
            {
                transform: kfEnd + ' rotateX(' + rxEnd.toFixed(0) + 'deg) rotateY(' + ryEnd.toFixed(0) + 'deg) rotateZ(' + rzEnd.toFixed(0) + 'deg)',
                opacity: tier.opacity
            }
        ];

        var anim = wrap.animate(keyframes, {
            duration: duration,
            easing: 'linear',
            iterations: 1,
            fill: 'forwards',
            delay: initialDelay || 0
        });

        scene.appendChild(wrap);

        // When animation finishes: respawn with new properties
        anim.onfinish = function () {
            scene.removeChild(wrap);
            var idx = allAnimations.indexOf(anim);
            if (idx !== -1) allAnimations.splice(idx, 1);
            // Respawn
            var newTier = pickTier();
            var newPos = { x: rand(0, 100), y: 0 };
            spawnSymbol(newTier, newPos, 0);
        };

        allAnimations.push(anim);
        return anim;
    }

    function spawnSymbol(tierName, pos, delay) {
        if (reducedMotion) return;
        createSymbol(tierName, pos, delay);
    }


    // ═══════════════════════════════════════════
    // PARTICLES — Tiny glowing embers
    // ═══════════════════════════════════════════
    function createParticle() {
        if (!particleField) return;
        var p = document.createElement('div');
        p.className = 'particle';
        var size = rand(2, 4);
        p.style.width = size + 'px';
        p.style.height = size + 'px';
        p.style.background = pick(['#FFBA53', '#F5A623', '#FFC978']);
        p.style.boxShadow = '0 0 ' + rand(4, 8).toFixed(0) + 'px ' + rand(1, 3).toFixed(0) + 'px rgba(245, 166, 35, 0.8)';

        var startX = rand(2, 98);
        var startY = 105;
        var endX = startX + rand(-8, 8);
        var endY = -5;
        var dur = rand(8, 16) * 1000;

        var kf = [
            { transform: 'translate(' + startX + 'vw, ' + startY + 'vh)', opacity: 0 },
            { opacity: rand(0.5, 0.9), offset: 0.15 },
            { opacity: rand(0.3, 0.7), offset: 0.5 },
            { opacity: rand(0.1, 0.4), offset: 0.85 },
            { transform: 'translate(' + endX.toFixed(1) + 'vw, ' + endY + 'vh)', opacity: 0 }
        ];

        var anim = p.animate(kf, {
            duration: dur,
            easing: 'ease-in-out',
            iterations: 1,
            fill: 'forwards',
            delay: -rand(0, dur) // negative delay for pre-fill
        });

        particleField.appendChild(p);

        anim.onfinish = function () {
            particleField.removeChild(p);
            var idx = allAnimations.indexOf(anim);
            if (idx !== -1) allAnimations.splice(idx, 1);
            createParticle(); // respawn
        };

        allAnimations.push(anim);
    }


    // ═══════════════════════════════════════════
    // REDUCED MOTION — static symbols
    // ═══════════════════════════════════════════
    function initReduced() {
        var count = 12;
        var positions = jitteredPositions(count);
        for (var i = 0; i < count; i++) {
            var wrap = document.createElement('div');
            wrap.className = 'sym-wrap';
            wrap.style.position = 'absolute';
            wrap.style.left = positions[i].x + '%';
            wrap.style.top = positions[i].y + '%';
            wrap.style.opacity = '0.35';

            var span = document.createElement('span');
            span.className = 'sym-layer front';
            span.textContent = LABELS[i % LABELS.length];
            span.setAttribute('data-text', LABELS[i % LABELS.length]);
            span.style.fontSize = rand(30, 90) + 'px';
            span.style.position = 'relative';
            wrap.appendChild(span);
            scene.appendChild(wrap);
        }
    }


    // ═══════════════════════════════════════════
    // INIT — Populate scene
    // ═══════════════════════════════════════════
    function init() {
        if (reducedMotion) {
            initReduced();
            return;
        }

        var count = getCount();
        var positions = jitteredPositions(count);

        for (var i = 0; i < count; i++) {
            var tierName = pickTier();
            var tier = TIERS[tierName];
            var dur = rand(tier.durMin, tier.durMax) * 1000;
            // Negative delay so the scene is already full on load
            var negDelay = -rand(0, dur);
            createSymbol(tierName, positions[i], negDelay);
        }

        // Particles
        var pCount = getParticleCount();
        for (var j = 0; j < pCount; j++) {
            createParticle();
        }
    }


    // ═══════════════════════════════════════════
    // VISIBILITY — Pause when tab hidden
    // ═══════════════════════════════════════════
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            isPaused = true;
            for (var i = 0; i < allAnimations.length; i++) {
                if (allAnimations[i].playState === 'running') {
                    allAnimations[i].pause();
                }
            }
        } else {
            isPaused = false;
            for (var i = 0; i < allAnimations.length; i++) {
                if (allAnimations[i].playState === 'paused') {
                    allAnimations[i].play();
                }
            }
        }
    });


    // ═══════════════════════════════════════════
    // BOOT
    // ═══════════════════════════════════════════
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
