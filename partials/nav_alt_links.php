<?php
$_nav_alt_links = $_nav_alt_links ?? [];
$_nav_root = $_nav_root ?? '';
foreach ($_nav_alt_links as $_nav_alt): ?>
    <a href="<?= $_nav_root ?><?= $_nav_alt['url'] ?>"
        class="flex items-center gap-4 px-6 py-4 text-base text-gray-400 <?= $_nav_alt['drawer_hover'] ?> transition-all no-underline">
        <i data-lucide="<?= $_nav_alt['icon'] ?>" class="w-5 h-5 flex-shrink-0"></i>
        <span><?= $_nav_alt['label'] ?></span>
    </a>
<?php endforeach; ?>
