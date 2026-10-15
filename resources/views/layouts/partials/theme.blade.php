{{--
    Design system (getdesign.md, NVIDIA-style): one accent (NVIDIA Green #76b900), black chrome, white canvas,
    #f7f7f7 soft strips, 1px #cccccc hairlines, 2px radius on everything, no card shadows, Inter 400/700,
    16px body, a 12px green corner square on every card.
    Existing pages keep their Tailwind classes: the palette below maps them onto the tokens
    (teal + emerald-600 = the green CTA, slate = ink / mute / hairline / soft, amber / rose = warning / error).
--}}
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700&display=swap" rel="stylesheet">
<link rel="icon" type="image/png" href="{{ asset('images/logo.png') }}">
<script src="https://cdn.tailwindcss.com"></script>
<script>
    tailwind.config = {
        theme: {
            extend: {
                screens: { nav: '768px' },   // primary nav becomes the hamburger drawer below 768px
                fontFamily: {
                    sans: ['Inter', 'Arial', 'sans-serif'],
                    display: ['Inter', 'Arial', 'sans-serif'],
                },
                fontWeight: { light: '400', normal: '400', medium: '700', semibold: '700', bold: '700', extrabold: '700' },
                fontSize: {
                    micro: ['10px', { lineHeight: '1.5' }],
                    xs: ['12px', { lineHeight: '1.25' }],
                    sm: ['14px', { lineHeight: '1.43' }],
                    base: ['16px', { lineHeight: '1.5' }],
                    lg: ['18px', { lineHeight: '1.4' }],
                    xl: ['20px', { lineHeight: '1.25' }],
                    '2xl': ['24px', { lineHeight: '1.25' }],
                    '3xl': ['28px', { lineHeight: '1.25' }],
                    '4xl': ['36px', { lineHeight: '1.25' }],
                    '5xl': ['48px', { lineHeight: '1.25' }],
                },
                colors: {
                    primary: '#76b900', 'primary-dark': '#5a8d00', 'primary-on-dark': '#76b900', 'link-blue': '#0046a4',
                    ink: '#000000', 'ink-80': '#1a1a1a', 'ink-48': '#757575', stone: '#898989', ash: '#a7a7a7',
                    parchment: '#f7f7f7', pearl: '#f7f7f7', hairline: '#cccccc', 'hairline-strong': '#5e5e5e',
                    tile: { 1: '#000000', 2: '#1a1a1a', 3: '#1a1a1a' },
                    teal: { 50: '#f3f9e6', 100: '#e3f1c7', 200: '#c7e38f', 300: '#a9d25a', 400: '#8cc22a', 500: '#76b900',
                            600: '#76b900', 700: '#76b900', 800: '#3f8500', 900: '#2e6100' },
                    emerald: { 50: '#f3f9e6', 100: '#e3f1c7', 200: '#c7e38f', 300: '#a9d25a', 400: '#8cc22a', 500: '#76b900',
                               600: '#76b900', 700: '#76b900', 800: '#3f8500', 900: '#2e6100' },
                    amber: { 50: '#fff4e8', 100: '#ffe6cc', 200: '#ffd0a3', 300: '#f7b06a', 400: '#ef9100', 500: '#df6500',
                             600: '#df6500', 700: '#b85300', 800: '#9a4500', 900: '#7a3600' },
                    rose: { 50: '#fdecec', 100: '#fbd5d5', 200: '#f5a9a9', 300: '#ee7c7c', 400: '#e94c4c', 500: '#e52020',
                            600: '#e52020', 700: '#c41b1b', 800: '#650b0b', 900: '#650b0b' },
                    slate: { 50: '#f7f7f7', 100: '#f7f7f7', 200: '#cccccc', 300: '#cccccc', 400: '#a7a7a7', 500: '#757575',
                             600: '#5e5e5e', 700: '#1a1a1a', 800: '#1a1a1a', 900: '#000000' },
                },
                borderRadius: { none: '0', sm: '2px', DEFAULT: '2px', md: '2px', lg: '2px', xl: '2px', '2xl': '2px', '3xl': '2px', pill: '2px' },
                boxShadow: { sm: 'none', DEFAULT: 'none', md: 'none', lg: 'none', xl: 'none', '2xl': 'none',
                             sticky: '0 0 5px 0 rgba(0, 0, 0, 0.3)' },
            },
        },
    };
</script>
<style>
    [x-cloak] { display: none !important; }
    body { background: #ffffff; color: #1a1a1a; font-family: Inter, Arial, sans-serif; font-size: 16px; line-height: 1.5; }

    /* button-primary: NVIDIA Green, black text, 2px, 44px; pressed = primary-dark */
    :is(a, button)[class*="bg-teal-600"], :is(a, button)[class*="bg-teal-700"], :is(a, button)[class*="bg-emerald-600"] {
        background-color: #76b900; color: #000 !important; font-weight: 700; border: 0; border-radius: 2px; min-height: 44px;
        display: inline-flex; align-items: center; justify-content: center;
    }
    :is(a, button)[class*="bg-teal-600"]:not(:disabled):active, :is(a, button)[class*="bg-teal-700"]:not(:disabled):active,
    :is(a, button)[class*="bg-emerald-600"]:not(:disabled):active { background-color: #5a8d00; }
    /* destructive: error red, white text */
    :is(a, button)[class*="bg-rose-600"], :is(a, button)[class*="bg-rose-700"] {
        background-color: #e52020; color: #fff !important; font-weight: 700; border: 0; border-radius: 2px; min-height: 44px;
        display: inline-flex; align-items: center; justify-content: center;
    }
    :is(a, button)[class*="bg-rose-600"]:active, :is(a, button)[class*="bg-rose-700"]:active { background-color: #650b0b; }
    /* button-outline: transparent, black text, 2px green border */
    :is(a, button)[class*="border-slate-300"][class*="bg-white"], :is(a, button)[class*="border-teal-600"][class*="bg-white"] {
        background: transparent; color: #000 !important; border: 2px solid #76b900 !important; border-radius: 2px; font-weight: 700;
        min-height: 44px; display: inline-flex; align-items: center; justify-content: center;
    }
    :is(a, button)[class*="border-rose-300"] { border: 2px solid #e52020 !important; color: #000 !important; font-weight: 700; border-radius: 2px; }
    button:disabled { background: #f7f7f7 !important; color: #a7a7a7 !important; border-color: #f7f7f7 !important; opacity: 1 !important; }

    /* button-ghost-link / table action links: black bold with a green underline (green text on white is unreadable) */
    a.text-teal-700, a.text-teal-800, button.text-teal-700 {
        color: #000; font-weight: 700; text-decoration: underline; text-decoration-color: #76b900;
        text-decoration-thickness: 2px; text-underline-offset: 4px;
    }
    .bg-tile-1 a.text-teal-700 { color: #76b900; }

    /* text-input: hairline, 2px radius, green border on focus (the only focus signal) */
    input:not([type="checkbox"]):not([type="radio"]):not([type="hidden"]):not([type="file"]), select { min-height: 40px; border-radius: 2px; }
    :is(input, select, textarea):focus { outline: none !important; border-color: #76b900 !important; box-shadow: 0 0 0 1px #76b900 !important; }
    :is(a, button, summary):focus-visible { outline: 2px solid #76b900; outline-offset: 2px; }
    input[type="checkbox"], input[type="radio"] { accent-color: #76b900; }

    /* cards: white, 1px hairline, 2px radius, no shadow, 12px green corner square */
    .rounded-lg.border, .rounded-xl.border, .rounded-xl.border-2 { position: relative; }
    .rounded-lg.border::before, .rounded-xl.border::before, .rounded-xl.border-2::before {
        content: ""; position: absolute; top: -1px; left: -1px; width: 12px; height: 12px; background: #76b900;
        z-index: 25; pointer-events: none;
    }
    .rounded-lg.border-slate-200 { border-color: #cccccc; }
    .no-corner::before { display: none !important; }

    /* table-lines: 1px hairline grid on assessment tables (separate borders so the sticky header keeps its lines) */
    .table-lines { border-collapse: separate; border-spacing: 0; }
    .table-lines > thead > tr > th, .table-lines > tbody > tr > td, .table-lines > tfoot > tr > td {
        border-right: 1px solid #cccccc; border-bottom: 1px solid #cccccc;
    }
    .table-lines > thead > tr > th { background-clip: padding-box; vertical-align: middle; }
    .table-lines > tfoot > tr > td { border-color: #5e5e5e; }
</style>
