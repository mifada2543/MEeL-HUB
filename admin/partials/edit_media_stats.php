<?php
/**
 * Deretan statistik (views / likes / dislikes) di sidebar editor admin.
 *
 * Dipakai `admin/edit-music.php` dan `admin/edit-video.php`.
 * Baris media harus tersedia sebagai `$record`.
 *
 * @var array $record
 */
?>
                <div class="stats-strip">
                    <div class="stat-chip">
                        <div class="stat-number"><?= number_format($record['views'] ?? 0) ?></div>
                        <div class="stat-label">Views</div>
                    </div>
                    <div class="stat-chip">
                        <div class="stat-number"><?= number_format($record['likes'] ?? 0) ?></div>
                        <div class="stat-label">Likes</div>
                    </div>
                    <div class="stat-chip">
                        <div class="stat-number" style="color:#f87171;"><?= number_format($record['dislikes'] ?? 0) ?></div>
                        <div class="stat-label">Dislikes</div>
                    </div>
                </div>
