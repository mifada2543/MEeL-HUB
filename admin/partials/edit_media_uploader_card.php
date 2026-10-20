<?php
/**
 * Kartu "diunggah oleh" di sidebar editor admin.
 *
 * Dipakai `admin/edit-music.php` dan `admin/edit-video.php`.
 * Baris media harus tersedia sebagai `$record`.
 *
 * @var array $record
 * @var bool $is_admin
 * @var bool $is_owner
 */
?>
                <div class="uploader-card">
                    <?php if (!empty($record['uploader_pfp'])): ?>
                        <img src="../profile/upload/<?= htmlspecialchars($record['uploader_pfp']) ?>"
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
