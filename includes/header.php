<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Queue Management - Staff Portal</title>
    
    <!-- CareFlow Custom Favicon -->
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Cdefs%3E%3ClinearGradient id='g' x1='0%25' y1='0%25' x2='100%25' y2='100%25'%3E%3Cstop offset='0%25' stop-color='%2360a5fa'/%3E%3Cstop offset='100%25' stop-color='%232563eb'/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect width='64' height='64' rx='16' fill='url(%23g)'/%3E%3Cpath d='M16 26h32v26H16z' fill='none' stroke='white' stroke-width='4' stroke-linejoin='round'/%3E%3Cpath d='M24 16h16v10H24z' fill='none' stroke='white' stroke-width='4' stroke-linejoin='round'/%3E%3Cpath d='M32 33v12M26 39h12' stroke='white' stroke-width='4' stroke-linecap='round'/%3E%3C/svg%3E">
    
    <!-- Theme Script to Prevent Flash -->
    <script>
        if (localStorage.getItem('theme') === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    </script>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <!-- Phosphor Icons -->
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                        display: ['Outfit', 'sans-serif'],
                    },
                    colors: {
                        brand: {
                            50: '#eff6ff',
                            100: '#dbeafe',
                            200: '#bfdbfe',
                            300: '#93c5fd',
                            400: '#60a5fa',
                            500: '#3b82f6', // Brand Blue
                            600: '#2563eb', // Brand Blue Dark
                            700: '#1d4ed8',
                            800: '#1e40af',
                            900: '#1e3a8a',
                            950: '#172554',
                        }
                    },
                    animation: {
                        'float': 'float 8s ease-in-out infinite',
                        'pulse-slow': 'pulse 3s cubic-bezier(0.4, 0, 0.6, 1) infinite',
                    },
                    keyframes: {
                        'float': {
                            '0%, 100%': { transform: 'translate(0, 0)' },
                            '50%': { transform: 'translate(0, -15px)' },
                        }
                    }
                }
            }
        }
    </script>
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #ebeef0; /* Pearl grayish white */
            position: relative;
        }
        html.dark body {
            background-color: #0f172a;
        }
        
        /* Noise Overlay for premium feel */
        body::before {
            content: '';
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noiseFilter'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.65' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noiseFilter)' opacity='0.05'/%3E%3C/svg%3E");
            pointer-events: none;
            z-index: 9999;
            opacity: 0.4;
        }
        html.dark body::before { opacity: 0.15; }

        .glass-card {
            background: rgba(255, 255, 255, 0.45);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.5);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.05);
            border-radius: 1.5rem; /* 2xl */
        }
        
        .dark .glass-card {
            background: rgba(30, 41, 59, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.05);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }

        .glass-nav {
            background: rgba(255, 255, 255, 0.5);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.5);
        }
        
        .dark .glass-nav {
            background: rgba(15, 23, 42, 0.7);
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }

        .form-input { 
            width: 100%; 
            padding: 0.6rem 1rem 0.6rem 2.5rem; /* Space for icon, reduced padding */
            border: 2px solid rgba(226, 232, 240, 0.8); 
            border-radius: 1rem; 
            background: rgba(255, 255, 255, 0.8);
            font-size: 1rem; 
            color: #1e293b;
            transition: all 0.2s ease;
        }
        
        .dark .form-input {
            background: rgba(15, 23, 42, 0.6);
            color: #f8fafc;
            border: 2px solid rgba(51, 65, 85, 0.8);
        }
        
        .form-input:focus { 
            border-color: #3b82f6; 
            outline: none; 
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.15); 
            background: #ffffff;
        }
        
        .dark .form-input:focus {
            border-color: #3b82f6;
            background: rgba(15, 23, 42, 0.9);
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.2); 
        }
        
        .form-label { 
            font-family: 'Inter', sans-serif;
            font-weight: 700; 
            font-size: 10px; 
            color: #64748b; 
            text-transform: uppercase;
            letter-spacing: 0.1em;
            margin-bottom: 0.5rem;
            display: block;
        }
        
        .dark .form-label { color: #94a3b8; }

        .btn-primary { 
            background: #2563eb;
            color: white; 
            font-weight: 600; 
            border-radius: 1rem; 
            transition: all 0.2s ease;
            box-shadow: 0 10px 15px -3px rgba(37, 99, 235, 0.3);
        }
        .btn-primary:hover { 
            background: #1d4ed8;
            transform: translateY(-2px);
            box-shadow: 0 15px 20px -3px rgba(37, 99, 235, 0.4);
        }
        .btn-primary:active { transform: translateY(0); }

        .nav-link {
            font-family: 'Inter', sans-serif;
            font-weight: 600;
            color: #475569;
            transition: color 0.2s ease;
        }
        .dark .nav-link { color: #cbd5e1; }
        .nav-link:hover { color: #2563eb; }
        .dark .nav-link:hover { color: #60a5fa; }
        
        h1, h2, h3, h4, h5, h6 { font-family: 'Outfit', sans-serif; }
        
        /* Hide number input spinners */
        input[type="number"]::-webkit-inner-spin-button, 
        input[type="number"]::-webkit-outer-spin-button { 
            -webkit-appearance: none; 
            margin: 0; 
        }
        input[type="number"] { -moz-appearance: textfield; }
    </style>
</head>
<body class="antialiased min-h-screen flex flex-col relative overflow-x-hidden text-slate-800 dark:text-slate-200 transition-colors duration-300">
    
    <script>
        function toggleTheme() {
            const html = document.documentElement;
            if (html.classList.contains('dark')) {
                html.classList.remove('dark');
                localStorage.setItem('theme', 'light');
            } else {
                html.classList.add('dark');
                localStorage.setItem('theme', 'dark');
            }
        }
    </script>

    <!-- Floating background glow blobs (radial-gradient blue/purple) -->
    <div class="fixed top-[-10%] left-[-10%] w-[40vw] h-[40vw] bg-brand-500/20 rounded-full mix-blend-multiply dark:mix-blend-screen filter blur-[24px] opacity-60 animate-float pointer-events-none -z-10" style="will-change: transform;"></div>
    <div class="fixed bottom-[-10%] right-[-5%] w-[35vw] h-[35vw] bg-purple-500/20 rounded-full mix-blend-multiply dark:mix-blend-screen filter blur-[24px] opacity-60 animate-float pointer-events-none -z-10" style="animation-delay: 2s; will-change: transform;"></div>

    <nav class="glass-nav relative z-50 transition-colors duration-300">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-20">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-brand-400 to-brand-600 flex items-center justify-center text-white shadow-lg shadow-brand-500/30">
                        <i class="ph ph-hospital text-2xl"></i>
                    </div>
                    <h1 class="text-2xl font-extrabold bg-clip-text text-transparent bg-gradient-to-r from-brand-600 to-purple-600 dark:from-brand-400 dark:to-purple-400">
                        CareFlow
                    </h1>
                </div>
                <div class="flex space-x-6 items-center">
                    <?php if (!isset($_SESSION['user_logged_in'])): ?>
                        <?php if (basename($_SERVER['PHP_SELF']) !== 'book_appointment.php'): ?>
                            <a href="book_appointment.php" class="nav-link text-sm flex items-center gap-1.5 font-bold text-brand-600 dark:text-brand-400">
                                <i class="ph ph-calendar-plus text-base"></i> Book Appointment
                            </a>
                        <?php endif; ?>
                        <?php if (basename($_SERVER['PHP_SELF']) !== 'lookup.php'): ?>
                            <a href="lookup.php" class="nav-link text-sm">Patient Lookup</a>
                        <?php endif; ?>
                        <a href="display.php" target="_blank" class="nav-link text-sm flex items-center gap-1">Live Display <i class="ph ph-arrow-up-right"></i></a>
                    <?php endif; ?>

                    <!-- Theme Toggle (Perfect Round Shape) -->
                    <button onclick="toggleTheme()" class="w-10 h-10 rounded-full flex items-center justify-center bg-slate-100/90 dark:bg-slate-800/90 border border-slate-200/80 dark:border-slate-700/80 text-slate-600 dark:text-yellow-400 hover:bg-slate-200/80 dark:hover:bg-slate-700 transition-all duration-200 shadow-sm ml-2 focus:outline-none focus:ring-2 focus:ring-brand-500/20" title="Toggle Dark Mode">
                        <i class="ph ph-moon text-lg hidden dark:block"></i>
                        <i class="ph ph-sun text-lg block dark:hidden"></i>
                    </button>
                    <?php if (isset($_SESSION['user_logged_in'])): ?>
                        <?php 
                        $role = $_SESSION['user_role'] ?? 'Staff';
                        $pill_classes = match($role) {
                            'Admin' => 'bg-purple-100 text-purple-700 border-purple-200 dark:bg-purple-950/60 dark:text-purple-300 dark:border-purple-800/60',
                            'Doctor' => 'bg-emerald-100 text-emerald-700 border-emerald-200 dark:bg-emerald-950/60 dark:text-emerald-300 dark:border-emerald-800/60',
                            'Receptionist' => 'bg-amber-100 text-amber-700 border-amber-200 dark:bg-amber-950/60 dark:text-amber-300 dark:border-amber-800/60',
                            default => 'bg-brand-100 text-brand-700 border-brand-200 dark:bg-brand-950/60 dark:text-brand-300 dark:border-brand-800/60'
                        };
                        ?>
                        <div class="flex items-center gap-3 ml-2 pl-4 border-l border-slate-200 dark:border-slate-700">
                            <!-- Role Pill Badge -->
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold border shadow-xs tracking-wide <?= $pill_classes ?>">
                                <span class="w-1.5 h-1.5 rounded-full bg-current opacity-70"></span>
                                <?= htmlspecialchars($role) ?>
                            </span>
                            <!-- Logout Button (Icon Only) -->
                            <a href="logout.php" class="text-slate-400 hover:text-red-500 hover:bg-slate-100 dark:hover:bg-slate-800 p-1.5 rounded-lg transition-all" title="Logout">
                                <i class="ph ph-sign-out text-lg"></i>
                            </a>
                        </div>
                    <?php elseif (basename($_SERVER['PHP_SELF']) !== 'login.php'): ?>
                        <!-- Login button (hidden on login page itself) -->
                        <a href="login.php" class="flex items-center gap-2 px-5 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold transition-colors ml-4 shadow-md shadow-brand-500/20">
                            <i class="ph ph-sign-in"></i> Staff Login
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </nav>
    <div class="container mx-auto px-4 py-8 relative z-10 flex-1 flex flex-col">
