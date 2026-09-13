<?php
ini_set('display_errors', 0); ini_set('display_startup_errors', 0); error_reporting(E_ALL);


require_once __DIR__ . '/../../../auth/auth.php';
require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../../../modules/core/base_url.php';

$root = meel_base_url_path();

$user_id = $_SESSION['user_id'] ?? null;
$username = $_SESSION['username'] ?? null;
$is_logged_in = $user_id !== null;
$is_admin = $is_logged_in && is_admin($conn);

$user_songs = [];
if ($is_logged_in) {
    $stmt = $conn->prepare("SELECT id, title, artist, bpm, difficulty, difficulty_label, duration, note_count, audio_file, cover_file, play_count, created_at, updated_at FROM arcade_song WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $user_songs[] = $row;
    }
    $stmt->close();
}

$all_songs = [];
if ($is_admin) {
    $result = $conn->query("SELECT s.*, u.username FROM arcade_song s LEFT JOIN users u ON s.user_id = u.id ORDER BY s.created_at DESC LIMIT 500");
    while ($row = $result->fetch_assoc()) {
        $all_songs[] = $row;
    }
}

$total_plays = 0;
$total_notes = 0;
foreach ($user_songs as $s) {
    $total_plays += $s['play_count'];
    $total_notes += $s['note_count'];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>MEeL!Mania — Manage Beatmaps</title>
  <link rel="icon" type="image/png" href="<?= $root ?>/assets/MEeL.png">
  <link href="<?= $root ?>/assets/css/font.css" rel="stylesheet">
  <link rel="stylesheet" href="<?= $root ?>/arcade/rhythm/assets/css/editor.css">
  <link rel="stylesheet" href="<?= $root ?>/arcade/rhythm/assets/css/manage.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . $root . '/arcade/rhythm/assets/css/manage.css') ?>">
  <script>window.MEEL_BASE = <?= json_encode($root) ?>;</script>
</head>
<body>
  <?php if (!$is_logged_in): ?>
  <div class="auth-required">
    <div class="auth-card">
      <div class="auth-icon">🔒</div>
      <h2>Login Diperlukan</h2>
      <p>Anda harus login untuk mengelola beatmap.</p>
      <a href="../../auth/login.php" class="btn btn-primary">Login</a>
      <a href="../" class="btn btn-ghost">Kembali</a>
    </div>
  </div>
  <?php else: ?>

  <nav class="nav-bar">
    <a href="../" class="nav-back">← Kembali ke Lobby</a>
    <div class="nav-brand">
      <span class="brand-icon">♫</span>
      <span>Manage Beatmaps</span>
    </div>
    <div class="nav-actions">
      <a href="edit" class="btn btn-primary btn-sm">+ Buat Baru</a>
      <span class="user-info">👤 <?= htmlspecialchars($username) ?></span>
    </div>
  </nav>

  <div class="manage-layout">
    
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-num"><?= count($user_songs) ?></div>
        <div class="stat-label">Beatmaps</div>
      </div>
      <div class="stat-card">
        <div class="stat-num"><?= number_format($total_notes) ?></div>
        <div class="stat-label">Total Notes</div>
      </div>
      <div class="stat-card">
        <div class="stat-num"><?= number_format($total_plays) ?></div>
        <div class="stat-label">Total Plays</div>
      </div>
      <?php if ($is_admin): ?>
      <div class="stat-card">
        <div class="stat-num"><?= count($all_songs) ?></div>
        <div class="stat-label">Semua Song</div>
      </div>
      <?php endif; ?>
    </div>

    
    <div class="section-header">
      <h2>Beatmap Saya (<?= count($user_songs) ?>)</h2>
      <a href="edit" class="btn btn-primary btn-sm">+ Buat Baru</a>
    </div>

    <?php if (empty($user_songs)): ?>
    <div class="empty-state">
      <div class="empty-icon">🎵</div>
      <p>Belum ada beatmap. Mulai buat yang pertama!</p>
      <a href="edit" class="btn btn-primary">Buat Beatmap Pertama</a>
    </div>
    <?php else: ?>
    <div class="beatmap-grid" id="mySongs">
      <?php foreach ($user_songs as $s): ?>
      <div class="beatmap-card" data-id="<?= $s['id'] ?>">
        <div class="bm-cover" style="background:linear-gradient(135deg,<?= htmlspecialchars($s['color_primary'], ENT_QUOTES, 'UTF-8') ?>,<?= htmlspecialchars($s['color_secondary'], ENT_QUOTES, 'UTF-8') ?>);display:flex;align-items:center;justify-content:center;font-size:22px;">
          ♫
        </div>
        <div class="bm-info">
          <div class="bm-title"><?= htmlspecialchars($s['title']) ?></div>
          <div class="bm-meta"><?= htmlspecialchars($s['artist'] ?: 'Unknown') ?> · <?= (int)$s['bpm'] ?> BPM</div>
          <div class="bm-tags">
            <span class="bm-tag diff"><?= htmlspecialchars($s['difficulty_label'] ?: $s['difficulty'], ENT_QUOTES, 'UTF-8') ?></span>
            <span class="bm-tag notes"><?= (int)$s['note_count'] ?> notes</span>
            <span class="bm-tag plays"><?= (int)$s['play_count'] ?> plays</span>
          </div>
        </div>
        <div class="bm-actions">
          <a href="edit?id=<?= $s['id'] ?>" class="btn btn-edit">✏️ Edit</a>
          <button class="btn" onclick="showDetail(<?= $s['id'] ?>)" title="Detail">ℹ️</button>
          <button class="btn btn-delete" onclick="deleteSong(<?= $s['id'] ?>)" title="Hapus">🗑</button>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($is_admin && !empty($all_songs)): ?>
    <div class="section-header" style="margin-top:32px;">
      <h2>🔧 Admin: Semua Song (<?= count($all_songs) ?>)</h2>
    </div>
    <div class="beatmap-grid">
      <?php foreach ($all_songs as $s): ?>
      <div class="beatmap-card" data-id="<?= $s['id'] ?>">
        <div class="bm-cover" style="background:linear-gradient(135deg,<?= htmlspecialchars($s['color_primary'], ENT_QUOTES, 'UTF-8') ?>,<?= htmlspecialchars($s['color_secondary'], ENT_QUOTES, 'UTF-8') ?>);display:flex;align-items:center;justify-content:center;font-size:22px;">
          ♫
        </div>
        <div class="bm-info">
          <div class="bm-title"><?= htmlspecialchars($s['title']) ?></div>
          <div class="bm-meta">by <?= htmlspecialchars($s['username'] ?? '?') ?> · <?= (int)$s['bpm'] ?> BPM · <?= (int)$s['note_count'] ?> notes</div>
          <div class="bm-tags">
            <span class="bm-tag diff"><?= htmlspecialchars($s['difficulty_label'] ?: $s['difficulty'], ENT_QUOTES, 'UTF-8') ?></span>
            <span class="bm-tag plays"><?= (int)$s['play_count'] ?> plays</span>
          </div>
        </div>
        <div class="bm-actions">
          <a href="edit?id=<?= $s['id'] ?>" class="btn btn-edit">✏️ Edit</a>
          <button class="btn btn-delete" onclick="deleteSong(<?= $s['id'] ?>)" title="Hapus (Admin)">🗑</button>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  
  <div id="detailModal" class="detail-modal" onclick="if(event.target===this)this.classList.remove('active')">
    <div class="detail-card" id="detailContent"></div>
  </div>

  <script src="<?= $root ?>/assets/js/compatibilitas/sweetalert2.all.min.js"></script>
  <script src="<?= $root ?>/assets/js/compatibilitas/script.min.js"></script>
  <script>
    const CSRF_TOKEN = '<?= $_SESSION['csrf_token'] ?? '' ?>';
    const SONGS_DATA = <?= json_encode(array_map(function($s) {
      return [
        'id' => $s['id'],
        'title' => $s['title'],
        'artist' => $s['artist'],
        'bpm' => $s['bpm'],
        'difficulty' => $s['difficulty'],
        'difficulty_label' => $s['difficulty_label'],
        'duration' => $s['duration'],
        'note_count' => $s['note_count'],
        'play_count' => $s['play_count'],
        'created_at' => $s['created_at'],
      ];
    }, $user_songs)) ?>;

    function showDetail(id) {
      var song = SONGS_DATA.find(function(s) { return s.id === id; });
      if (!song) return;
      var html = '<h3>' + song.title + '</h3>';
      html += '<div class="detail-row"><span>Artist</span><span>' + (song.artist || '-') + '</span></div>';
      html += '<div class="detail-row"><span>BPM</span><span>' + song.bpm + '</span></div>';
      html += '<div class="detail-row"><span>Difficulty</span><span>' + song.difficulty_label + '</span></div>';
      html += '<div class="detail-row"><span>Duration</span><span>' + song.duration + 's</span></div>';
      html += '<div class="detail-row"><span>Notes</span><span>' + song.note_count + '</span></div>';
      html += '<div class="detail-row"><span>Plays</span><span>' + song.play_count + '</span></div>';
      html += '<div class="detail-row"><span>Created</span><span>' + (song.created_at || '-') + '</span></div>';
      html += '<div style="margin-top:16px;display:flex;gap:8px;">';
      html += '<a href="edit?id=' + song.id + '" class="btn btn-edit" style="flex:1;">✏️ Edit</a>';
      html += '<button class="btn btn-delete" onclick="deleteSong(' + song.id + ')" style="flex:1;">🗑 Hapus</button>';
      html += '<button class="btn" onclick="document.getElementById(\'detailModal\').classList.remove(\'active\')" style="flex:1;">Tutup</button>';
      html += '</div>';
      document.getElementById('detailContent').innerHTML = html;
      document.getElementById('detailModal').classList.add('active');
    }

    function deleteSong(id) {
      Swal.fire({
        title: 'Hapus Beatmap?',
        text: 'Tindakan ini tidak dapat dibatalkan.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#475569',
        confirmButtonText: 'Hapus',
        background: '#0e1118',
        color: '#fff',
      }).then(function(r) {
        if (!r.isConfirmed) return;
        var fd = new FormData();
        fd.append('song_id', id);
        fd.append('csrf_token', CSRF_TOKEN);
        fetch('../api/delete', { method: 'POST', body: fd })
          .then(function(r) { return r.json(); })
          .then(function(res) {
            if (res.success) {
              Swal.fire({ title: 'Terhapus!', text: res.message, icon: 'success', background: '#0e1118', color: '#fff' })
                .then(function() { window.location.reload(); });
            } else {
              Swal.fire({ title: 'Error', text: res.error, icon: 'error', background: '#0e1118', color: '#fff' });
            }
          })
          .catch(function() {
            Swal.fire({ title: 'Error', text: 'Gagal menghapus', icon: 'error', background: '#0e1118', color: '#fff' });
          });
      });
    }
  </script>
  <?php endif; ?>
</body>
</html>
