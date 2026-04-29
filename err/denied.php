<?php $back_url = '../index.php';
if (isset($_SERVER['HTTP_REFERER']) && !empty($_SERVER['HTTP_REFERER'])) {
    $ref = $_SERVER['HTTP_REFERER'];
    $host = $_SERVER['HTTP_HOST'];
    if (parse_url($ref, PHP_URL_HOST) === $host) {
        $ref_path = parse_url($ref, PHP_URL_PATH);
        $excluded_pages = ['denied.php', 'index.php'];
        $should_exclude = false;
        foreach ($excluded_pages as $page) {
            if (strpos($ref_path, $page) !== false) {
                $should_exclude = true;
                break;
            }
        }
        if (!$should_exclude) {
            $back_url = $ref;
        }
    }
} ?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=0.7">
    <title>Access Denied | MEeL</title>
    <script src="../assets/js/tailwind.js"></script>
    <script src="../assets/js/lucide.js"></script>
    <style>
        .glass {
            background: rgba(255, 255, 255, 0.02);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }
    </style>
</head>

<body class="bg-[#0b0e14] text-white font-sans antialiased flex items-center justify-center min-h-screen">
    <div class="max-w-md w-full px-6 text-center">
        <div class="relative inline-block mb-8">
            <div class="absolute inset-0 bg-orange-600 blur-3xl opacity-20 rounded-full"></div>
            <div class="relative w-24 h-24 glass rounded-[2rem] flex items-center justify-center mx-auto border-orange-500/20">
                <i data-lucide="shield-alert" class="w-12 h-12 text-orange-500"></i>
            </div>
        </div>

        <p class="text-[10px] font-black uppercase tracking-[0.5em] text-orange-500 mb-2">Error 403 / 404</p>
        <h1 class="text-4xl font-black tracking-tighter mb-4 italic">ACCESS DENIED</h1>
        <p class="text-gray-500 font-medium mb-10 leading-relaxed">
            Anda tidak memiliki izin untuk mengakses ini.
        </p>

        <div class="flex flex-col gap-4">
            <a href="<?= htmlspecialchars($back_url) ?>"
                class="bg-orange-600 hover:bg-orange-500 text-white px-8 py-4 rounded-2xl font-black uppercase tracking-widest text-xs transition-all flex items-center justify-center gap-3 shadow-lg shadow-orange-600/20">
                <i data-lucide="arrow-left" class="w-4 h-4"></i> Back to HUB
            </a>
        </div>
    </div>

    <script>
        lucide.createIcons();
    </script>
</body>

</html>