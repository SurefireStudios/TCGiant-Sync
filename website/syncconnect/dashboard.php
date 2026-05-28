<?php
/**
 * TCGiant Sync - Internal Analytics Dashboard
 */

// Super simple hardcoded password protection (CHANGE THIS IF PUTTING ON PUBLIC WEB)
$password = 'TCGiant2026!';

session_start();

if (isset($_POST['password']) && $_POST['password'] === $password) {
    $_SESSION['admin_logged_in'] = true;
}

if (isset($_GET['logout'])) {
    unset($_SESSION['admin_logged_in']);
    header('Location: dashboard.php');
    exit;
}

if (empty($_SESSION['admin_logged_in'])) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>TCGiant Admin Login</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background-color: #0f172a; color: white; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
            .login-box { background: #1e293b; padding: 2rem; border-radius: 8px; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); width: 100%; max-width: 400px; text-align: center; }
            input[type="password"] { width: 100%; padding: 0.75rem; margin-bottom: 1rem; border-radius: 4px; border: 1px solid #334155; background: #0f172a; color: white; box-sizing: border-box; }
            button { width: 100%; padding: 0.75rem; border: none; border-radius: 4px; background: #3b82f6; color: white; font-weight: bold; cursor: pointer; }
            button:hover { background: #2563eb; }
        </style>
    </head>
    <body>
        <div class="login-box">
            <h2>TCGiant Analytics</h2>
            <form method="POST">
                <input type="password" name="password" placeholder="Enter password" required>
                <button type="submit">Login</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$db = new SQLite3(__DIR__ . '/sync.db');
$db->exec("CREATE TABLE IF NOT EXISTS sites (id INTEGER PRIMARY KEY, site_url TEXT UNIQUE, last_connected DATETIME, total_synced INTEGER DEFAULT 0, license_type TEXT DEFAULT 'free')");
@$db->exec("ALTER TABLE sites ADD COLUMN total_synced INTEGER DEFAULT 0"); // Ensure column exists
@$db->exec("ALTER TABLE sites ADD COLUMN license_type TEXT DEFAULT 'free'"); // Ensure column exists

$total_sites = $db->querySingle("SELECT COUNT(*) FROM sites");
$total_synced_global = $db->querySingle("SELECT SUM(total_synced) FROM sites");

$results = $db->query("SELECT * FROM sites ORDER BY last_connected DESC");
$sites = [];
while ($row = $results->fetchArray(SQLITE3_ASSOC)) {
    $sites[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TCGiant Sync Analytics</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background-color: #0f172a; color: #f8fafc; margin: 0; padding: 2rem; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; }
        .header a { color: #94a3b8; text-decoration: none; font-size: 0.875rem; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
        .stat-card { background: #1e293b; padding: 1.5rem; border-radius: 8px; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); border: 1px solid #334155; }
        .stat-card h3 { margin: 0 0 0.5rem 0; color: #94a3b8; font-size: 0.875rem; text-transform: uppercase; letter-spacing: 0.05em; }
        .stat-card .value { font-size: 2rem; font-weight: bold; color: #38bdf8; }
        table { width: 100%; border-collapse: collapse; background: #1e293b; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); }
        th, td { padding: 1rem; text-align: left; border-bottom: 1px solid #334155; }
        th { background: #0f172a; color: #94a3b8; font-weight: 600; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.05em; }
        tr:last-child td { border-bottom: none; }
        .domain { color: #cbd5e1; font-weight: 500; }
        .count { color: #38bdf8; font-weight: bold; }
        .date { color: #94a3b8; font-size: 0.875rem; }
    </style>
</head>
<body>

    <div class="header">
        <h1>Admin Analytics Dashboard</h1>
        <a href="?logout=1">Logout</a>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <h3>Total Connected Sites</h3>
            <div class="value"><?= number_format((int)$total_sites) ?></div>
        </div>
        <div class="stat-card">
            <h3>Total Products Pushed</h3>
            <div class="value"><?= number_format((int)$total_synced_global) ?></div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Site URL</th>
                <th>License</th>
                <th>Total Synced</th>
                <th>Last Active</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($sites)): ?>
                <tr><td colspan="3" style="text-align: center; color: #94a3b8;">No sites connected yet.</td></tr>
            <?php else: ?>
                <?php foreach ($sites as $site): ?>
                    <tr>
                        <td class="domain"><?= htmlspecialchars($site['site_url']) ?></td>
                        <td>
                            <?php 
                                $lic = $site['license_type'] ?: 'free';
                                $color = $lic === 'free' ? '#94a3b8' : '#10b981';
                            ?>
                            <span style="background: <?= $color ?>; color: #fff; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.75rem; text-transform: uppercase; font-weight: bold;">
                                <?= htmlspecialchars($lic) ?>
                            </span>
                        </td>
                        <td class="count"><?= number_format((int)$site['total_synced']) ?></td>
                        <td class="date"><?= date('M j, Y g:i A', strtotime($site['last_connected'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

</body>
</html>
