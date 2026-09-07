<?php
/**
 * Live Activity Monitor — AJAX polling endpoint for user-management.php.
 * Returns the full monitor table body for vanilla JS polling.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user_id'])) {
    http_response_code(403);
    exit;
}

if (!isset($conn) || !$conn instanceof \mysqli) {
    require_once __DIR__ . '/../../auth/config.php';
}

require_once __DIR__ . '/../../modules/auth/helpers/authz.php';
require_admin($conn);

header('Content-Type: text/html; charset=utf-8');

$result_monitor = $conn->query(
    "SELECT id, username, role, last_activity, last_page, user_agent, access_via, ip_address
     FROM users ORDER BY last_activity DESC LIMIT 10"
);
?>
<?php if ($result_monitor && $result_monitor->num_rows > 0): ?>
    <?php while ($row = $result_monitor->fetch_assoc()):
        $is_online = (time() - strtotime($row['last_activity'])) < 300;
        $is_cloud = strpos($row['access_via'] ?? '', 'trycloudflare.com') !== false;
        $is_mobile = strpos($row['user_agent'] ?? '', 'Smartphone') !== false || strpos($row['user_agent'] ?? '', 'Android') !== false;
    ?>
        <tr class="group hover:bg-white/[0.02] transition-colors" data-sec-since="<?= max(0, time() - strtotime($row['last_activity'])) ?>">
            <td class="py-4 px-2">
                <div class="flex items-center gap-2">
                    <span class="text-sm font-bold <?= $row['role'] === 'guest' ? 'text-gray-500 italic' : 'text-white' ?>">
                        <a href="<?= meel_base_url_path() ?>/profile/<?= htmlspecialchars($row['username'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($row['username']) ?></a>
                    </span>
                    <?php if ($row['role'] === 'guest'): ?>
                        <span class="text-[7px] bg-white/5 text-gray-500 px-1 rounded border border-white/10 uppercase font-black">Guest</span>
                    <?php endif; ?>
                </div>
                <div class="flex flex-col">
                    <span class="text-xs font-bold text-white">
                        <?= htmlspecialchars($row['user_agent']) ?>
                    </span>

                    <div class="flex items-center gap-1 mt-1 flex-wrap">
                        <?php
                        $ip_display = $row['ip_address'] ?? 'Unknown';
                        $is_local = ($ip_display === 'LOCAL' || strpos($ip_display, 'Local') !== false);

                        $ip_type = 'Unknown';
                        $ip_color_class = 'bg-gray-800 text-gray-400 border-gray-700';
                        if ($is_local) {
                            $ip_color_class = 'bg-amber-800 text-amber-300 border-amber-700';
                            $ip_type = 'Local';
                        } elseif (filter_var($ip_display, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                            $ip_color_class = 'bg-blue-800 text-blue-300 border-blue-700';
                            $ip_type = 'IPv6';
                        } elseif (filter_var($ip_display, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                            $ip_color_class = 'bg-cyan-800 text-cyan-300 border-cyan-700';
                            $ip_type = 'IPv4';
                        }

                        $ip_badge_text = $is_local ? 'LOCAL' : $ip_display;
                        ?>
                        <code class="text-[10px] <?= $ip_color_class ?> px-2 py-0.5 rounded border font-mono select-all">
                            <?= htmlspecialchars($ip_badge_text) ?>
                        </code>

                        <?php if ($is_local): ?>
                            <span class="text-[7px] bg-amber-500/10 text-amber-500 px-1.5 rounded border border-amber-500/30 uppercase font-black tracking-wider">Lokal</span>
                        <?php elseif ($ip_type === 'IPv6'): ?>
                            <span class="text-[7px] bg-blue-500/10 text-blue-500 px-1.5 rounded border border-blue-500/30 uppercase font-black tracking-wider">IPv6</span>
                        <?php elseif ($ip_type === 'IPv4'): ?>
                            <span class="text-[7px] bg-green-500/10 text-green-500 px-1.5 rounded border border-green-500/30 uppercase font-black tracking-wider">IPv4</span>
                        <?php endif; ?>
                    </div>

                    <span class="text-[9px] text-gray-500 font-semibold mt-1">
                        <?= htmlspecialchars($row['access_via']) ?>
                    </span>
                </div>
            </td>
            <td class="py-4 px-2">
                <div class="monitor-status flex items-center gap-2 <?= $is_online ? 'text-green-500' : 'text-gray-600' ?>">
                    <span class="monitor-dot h-1.5 w-1.5 rounded-full <?= $is_online ? 'bg-green-500 animate-pulse' : 'bg-gray-700' ?>"></span>
                    <span class="monitor-label text-[10px] font-black uppercase tracking-tighter"><?= $is_online ? 'Online' : 'Offline' ?></span>
                </div>
            </td>
            <td class="py-4 px-2">
                <code class="text-[10px] bg-orange-500/10 text-orange-500 px-2 py-1 rounded border border-orange-500/20 font-mono"><?= htmlspecialchars($row['last_page']) ?></code>
            </td>
            <td class="py-4 px-6 text-right">
                <div class="flex items-center justify-end gap-3">
                    <span class="text-xs text-gray-400 font-mono"><?= date('H:i:s', strtotime($row['last_activity'])) ?></span>

                    <?php
                    $is_online = (time() - strtotime($row['last_activity'])) < 300;

                    if ($is_online && $row['username'] !== $_SESSION['username'] && $row['role'] !== 'guest'):
                    ?>
                        <form method="POST" class="inline" onsubmit="return meelConfirmForm(event, { title: 'Kick User', text: 'Tendang <?= htmlspecialchars($row['username'], ENT_QUOTES) ?>? User akan langsung offline.', confirmButtonText: 'TENDANG' })">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="kick_user" value="<?= htmlspecialchars($row['username'], ENT_QUOTES) ?>">
                            <button type="submit" class="p-1.5 bg-red-600/10 text-red-500 border border-red-500/20 rounded-lg hover:bg-red-600 hover:text-white transition-all cursor-pointer" title="Kick Active User">
                                <i data-lucide="log-out" class="w-3.5 h-3.5"></i>
                            </button>
                        </form>
                    <?php elseif (!$is_online && $row['username'] !== $_SESSION['username']): ?>
                        <span class="p-1.5 bg-gray-800/30 text-gray-700 rounded-lg border border-gray-800/50 cursor-not-allowed" title="User is already offline">
                            <i data-lucide="user-minus" class="w-3.5 h-3.5"></i>
                        </span>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
    <?php endwhile; ?>
<?php endif; ?>
