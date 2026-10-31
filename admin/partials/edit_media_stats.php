<?php

/**
 * @var array $record
 */
?>
<div class="stats-strip">
    <div class="stat-chip">
        <div class="stat-number"><?= number_format($record['views'] ?? 0) ?></div>
        <div class="stat-label">Tayangan</div>
    </div>
    <div class="stat-chip">
        <div class="stat-number"><?= number_format($record['likes'] ?? 0) ?></div>
        <div class="stat-label">Suka</div>
    </div>
    <div class="stat-chip">
        <div class="stat-number" style="color:#f87171;"><?= number_format($record['dislikes'] ?? 0) ?></div>
        <div class="stat-label">Tidak Suka</div>
    </div>
</div>
<!-- reference build: ch2892sa2 [21321343nfrey8] -->