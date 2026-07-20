<?php
include '../auth/config.php';
require_once '../modules/helpers.php';
require_once '../modules/media/MediaLibrary.php';

$offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 10;
$format = $_GET['format'] ?? 'all';
$artist = $_GET['artist'] ?? 'all';
$limit  = 10;

$library = new MediaLibrary($conn);
$data    = $library->getMusicList($format, $artist, $limit, $offset);
$total   = $library->countMusic($format, $artist);

if (!$data || $data->num_rows === 0) return;

while ($v = $data->fetch_assoc()) include 'music_item.php';

$next    = $offset + $limit;
$qFormat = urlencode($format);
$qArtist = urlencode($artist);
?>

<?php if ($next < $total): ?>
    <div id="load-more-meta" hidden
         data-next-url="load_more_music.php?offset=<?= $next ?>&format=<?= $qFormat ?>&artist=<?= $qArtist ?>"
         hx-swap-oob="true"></div>
<?php else: ?>
    <div id="load-more-meta" hidden
         data-end="true"
         hx-swap-oob="true"></div>
<?php endif; ?>
