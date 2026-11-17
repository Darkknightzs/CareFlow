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
    <!-- Google Fonts: Inter & Outfit -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@500;600;700;800;900&display=swap" rel="stylesheet">
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
                            500: '#3b82f6',
                            600: '#2563eb',
                            700: '#1d4ed8',
                            800: '#1e40af',
                            900: '#1e3a8a',
                        }
                    }
                }
            }
        }
    </script>
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f1f5f9; /* Slate 100 base */
            color: #0f172a;
            position: relative;
            min-height: 100vh;
        }
        html.dark body {
            background-color: #0b0f19; /* Deep midnight */
            color: #f8fafc;
        }

        /* Subtle Ambient Glows (Fixed, elegant, non-blurry, zero noise) */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 20%;
            width: 60vw;
            height: 35vh;
            background: radial-gradient(circle at 50% 0%, rgba(59, 130, 246, 0.12), transparent 70%);
            pointer-events: none;
            z-index: 0;
        }
        html.dark body::before {
            background: radial-gradient(circle at 50% 0%, rgba(37, 99, 235, 0.18), transparent 75%);
        }

        .glass-card, .card {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(226, 232, 240, 0.9);
            box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.04), 0 8px 10px -6px rgba(15, 23, 42, 0.02);
            border-radius: 1.125rem; /* 18px */
        }
        
        .dark .glass-card, .dark .card {
            background: rgba(15, 23, 42, 0.8);
            border: 1px solid rgba(30, 41, 59, 0.85);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.5);
        }

        .glass-nav, .app-nav {
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-bottom: 1px solid rgba(226, 232, 240, 0.8);
        }
        
        .dark .glass-nav, .dark .app-nav {
            background: rgba(11, 15, 25, 0.85);
            border-bottom: 1px solid rgba(30, 41, 59, 0.8);
        }

        .form-input { 
            width: 100%; 
            padding: 0.625rem 0.875rem 0.625rem 2.35rem; 
            border: 1.5px solid #cbd5e1; 
            border-radius: 0.75rem; 
            background: #ffffff;
            font-size: 0.875rem; 
            color: #0f172a;
            transition: all 0.15s ease;
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.02);
        }
        
        .dark .form-input {
            background: #0f172a;
            color: #f8fafc;
            border: 1.5px solid #334155;
        }
        
        .form-input:focus { 
            border-color: #2563eb; 
            outline: none; 
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15); 
            background: #ffffff;
        }
        
        .dark .form-input:focus {
            border-color: #3b82f6;
            background: #0f172a;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.25); 
        }
        
        .form-label { 
            font-family: 'Inter', sans-serif;
            font-weight: 700; 
            font-size: 0.6875rem; /* 11px */
            color: #475569; 
            text-transform: uppercase;
            letter-spacing: 0.06em;
            margin-bottom: 0.4rem;
            display: block;
        }
        
        .dark .form-label { color: #94a3b8; }

        .btn-primary { 
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: #ffffff; 
            font-weight: 600; 
            border-radius: 0.75rem; 
            transition: all 0.15s ease;
            box-shadow: 0 4px 12px -2px rgba(37, 99, 235, 0.35);
        }
        .btn-primary:hover { 
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            box-shadow: 0 6px 16px -2px rgba(37, 99, 235, 0.45);
            transform: translateY(-1px);
        }
        .btn-primary:active { 
            transform: translateY(0); 
            box-shadow: 0 2px 6px -1px rgba(37, 99, 235, 0.3);
        }

        .nav-link {
            font-family: 'Inter', sans-serif;
            font-weight: 600;
            color: #475569;
            transition: all 0.15s ease;
        }
        .dark .nav-link { color: #94a3b8; }
        .nav-link:hover { color: #1d4ed8; }
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
<body class="antialiased min-h-screen flex flex-col relative overflow-x-hidden text-slate-900 dark:text-slate-100 transition-colors duration-200">
    
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

    <nav class="app-nav sticky top-0 z-50 transition-colors duration-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-14 sm:h-15">
                <!-- Brand Logo & Name -->
                <a href="index.php" class="flex items-center gap-2.5 group">
                    <div class="w-8 h-8 rounded-xl bg-gradient-to-tr from-blue-600 to-indigo-600 flex items-center justify-center text-white shadow-md shadow-blue-500/25 group-hover:scale-105 transition-transform">
                        <i class="ph-bold ph-heartbeat text-lg"></i>
                    </div>
                    <span class="text-xl font-black tracking-tight text-slate-900 dark:text-white">CareFlow</span>
                </a>

                <!-- Nav Items -->
                <div class="flex space-x-5 items-center">
                    <?php if (!isset($_SESSION['user_logged_in'])): ?>
                        <?php if (basename($_SERVER['PHP_SELF']) !== 'book_appointment.php'): ?>
                            <a href="book_appointment.php" class="nav-link text-xs sm:text-sm flex items-center gap-1.5 font-bold text-blue-600 dark:text-blue-400 bg-blue-50 dark:bg-blue-950/60 hover:bg-blue-100 px-3 py-1.5 rounded-lg border border-blue-200/60 dark:border-blue-900/60 transition-all">
                                <i class="ph-bold ph-calendar-plus text-base"></i>
                                <span>Book Appointment</span>
                            </a>
                        <?php endif; ?>
                        <?php if (basename($_SERVER['PHP_SELF']) !== 'lookup.php'): ?>
                            <a href="lookup.php" class="nav-link text-xs sm:text-sm font-semibold">Status Lookup</a>
                        <?php endif; ?>
                        <a href="display.php" target="_blank" class="nav-link text-xs sm:text-sm font-semibold flex items-center gap-1">
                            <span>Waiting Board</span>
                            <i class="ph-bold ph-arrow-up-right text-xs"></i>
                        </a>
                    <?php endif; ?>

                    <!-- Theme Toggle -->
                    <button onclick="toggleTheme()" class="w-9 h-9 rounded-xl flex items-center justify-center bg-white dark:bg-slate-800 text-slate-600 dark:text-amber-400 hover:bg-slate-100 dark:hover:bg-slate-700 border border-slate-200 dark:border-slate-700 transition-all shadow-xs" title="Toggle Theme">
                        <i class="ph-bold ph-moon text-base hidden dark:block"></i>
                        <i class="ph-bold ph-sun text-base block dark:hidden"></i>
                    </button>

                    <?php if (isset($_SESSION['user_logged_in'])): ?>
                        <?php 
                        $role = $_SESSION['user_role'] ?? 'Staff';
                        $pill_classes = match($role) {
                            'Admin' => 'bg-purple-100/80 text-purple-800 border-purple-200 dark:bg-purple-950/60 dark:text-purple-300 dark:border-purple-800',
                            'Doctor' => 'bg-blue-100/80 text-blue-800 border-blue-200 dark:bg-blue-950/60 dark:text-blue-300 dark:border-blue-800',
                            'Receptionist' => 'bg-emerald-100/80 text-emerald-800 border-emerald-200 dark:bg-emerald-950/60 dark:text-emerald-300 dark:border-emerald-800',
                            default => 'bg-slate-100 text-slate-700 border-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:border-slate-700'
                        };
                        ?>
                        <div class="flex items-center gap-3 ml-2 pl-3 border-l border-slate-200 dark:border-slate-800">
                            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold border shadow-xs <?= $pill_classes ?>">
                                <span class="w-1.5 h-1.5 rounded-full bg-current animate-pulse"></span>
                                <?= htmlspecialchars($role) ?>
                            </span>
                            <a href="logout.php" class="text-slate-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-950/40 p-2 rounded-lg transition-colors" title="Logout">
                                <i class="ph-bold ph-sign-out text-base"></i>
                            </a>
                        </div>
                    <?php elseif (basename($_SERVER['PHP_SELF']) !== 'login.php'): ?>
                        <a href="login.php" class="flex items-center gap-1.5 px-4 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 dark:bg-white dark:hover:bg-slate-100 text-white dark:text-slate-900 text-xs font-bold transition-all shadow-sm">
                            <i class="ph-bold ph-lock-key"></i> Staff Portal
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </nav>
    <div class="<?= basename($_SERVER['PHP_SELF']) === 'login.php' ? 'container mx-auto px-3 sm:px-4 py-2 sm:py-3 relative z-10 flex-1 flex flex-col justify-center' : 'container mx-auto px-4 py-8 relative z-10 flex-1 flex flex-col' ?>">
