<?php

/**
 * @var array $record
 * @var bool $is_admin
 * @var bool $is_owner
 */
?>
<div class="uploader-card">
    <?php if (!empty($record['uploader_pfp'])): ?>
        <img src="<?= htmlspecialchars(meel_profile_image_url($record['uploader_pfp'], '../profile/upload/'), ENT_QUOTES, 'UTF-8') ?>"
            alt="<?= htmlspecialchars($record['uploader'] ?? '') ?>"
            class="uploader-avatar">
    <?php else: ?>
        <div class="uploader-avatar-fallback">
            <?= strtoupper(substr($record['uploader'] ?? 'U', 0, 1)) ?>
        </div>
    <?php endif; ?>
    <div class="uploader-info">
        <div class="uploader-label">Diunggah oleh</div>
        <div class="uploader-name">@<?= htmlspecialchars($record['uploader'] ?? '—') ?></div>
    </div>
    <div class="uploader-role-badge"><?= $is_admin && !$is_owner ? 'Admin Edit' : 'Uploader' ?></div>
</div>