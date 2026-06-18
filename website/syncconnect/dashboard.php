<?php
/**
 * TCGiant Sync — SaaS Analytics Dashboard
 * 
 * Premium dashboard with revenue tracking, interactive charts,
 * and license tier analytics.
 */

// --- PASSWORD FROM .env FILE ---
// Reads the password from an .env file so it never lives in source code.
function load_env_password() {
    $env_file = __DIR__ . '/.env';
    if (!file_exists($env_file)) {
        return null;
    }
    $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            if (trim($key) === 'DASHBOARD_PASSWORD') {
                return trim($value);
            }
        }
    }
    return null;
}

$env_password = load_env_password();

session_start();

if (isset($_POST['password']) && $env_password !== null && $_POST['password'] === $env_password) {
    $_SESSION['admin_logged_in'] = true;
}

if (isset($_GET['logout'])) {
    unset($_SESSION['admin_logged_in']);
    header('Location: dashboard.php');
    exit;
}

if (empty($_SESSION['admin_logged_in'])) {
    $login_error = isset($_POST['password']);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>TCGiant Analytics — Login</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
        <style>
            *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
            body {
                font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
                background: #0a0e1a;
                color: #e2e8f0;
                display: flex;
                justify-content: center;
                align-items: center;
                min-height: 100vh;
                overflow: hidden;
            }
            /* Ambient glow */
            body::before {
                content: '';
                position: fixed;
                top: -50%;
                left: -50%;
                width: 200%;
                height: 200%;
                background: radial-gradient(ellipse at 30% 20%, rgba(99, 102, 241, 0.08) 0%, transparent 50%),
                            radial-gradient(ellipse at 70% 80%, rgba(6, 182, 212, 0.06) 0%, transparent 50%);
                pointer-events: none;
                z-index: 0;
            }
            .login-container {
                position: relative;
                z-index: 1;
                width: 100%;
                max-width: 420px;
                padding: 1.5rem;
            }
            .login-box {
                background: rgba(15, 23, 42, 0.8);
                backdrop-filter: blur(24px);
                -webkit-backdrop-filter: blur(24px);
                border: 1px solid rgba(99, 102, 241, 0.15);
                padding: 3rem 2.5rem;
                border-radius: 20px;
                box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5),
                            0 0 0 1px rgba(255, 255, 255, 0.03),
                            inset 0 1px 0 rgba(255, 255, 255, 0.05);
                animation: slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1);
            }
            @keyframes slideUp {
                from { opacity: 0; transform: translateY(30px); }
                to { opacity: 1; transform: translateY(0); }
            }
            .login-logo {
                width: 56px;
                height: 56px;
                background: linear-gradient(135deg, #6366f1, #06b6d4);
                border-radius: 16px;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0 auto 1.5rem;
                font-size: 1.5rem;
                font-weight: 800;
                color: white;
                box-shadow: 0 8px 16px rgba(99, 102, 241, 0.3);
            }
            .login-box h2 {
                text-align: center;
                font-size: 1.5rem;
                font-weight: 700;
                background: linear-gradient(135deg, #e2e8f0, #94a3b8);
                -webkit-background-clip: text;
                -webkit-text-fill-color: transparent;
                margin-bottom: 0.5rem;
            }
            .login-subtitle {
                text-align: center;
                color: #64748b;
                font-size: 0.875rem;
                margin-bottom: 2rem;
            }
            .input-group {
                position: relative;
                margin-bottom: 1.25rem;
            }
            .input-group label {
                display: block;
                font-size: 0.75rem;
                font-weight: 600;
                color: #94a3b8;
                text-transform: uppercase;
                letter-spacing: 0.05em;
                margin-bottom: 0.5rem;
            }
            input[type="password"] {
                width: 100%;
                padding: 0.875rem 1rem;
                border-radius: 12px;
                border: 1px solid rgba(99, 102, 241, 0.2);
                background: rgba(15, 23, 42, 0.6);
                color: #e2e8f0;
                font-family: 'Inter', sans-serif;
                font-size: 0.9375rem;
                transition: all 0.2s ease;
                outline: none;
            }
            input[type="password"]:focus {
                border-color: #6366f1;
                box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
            }
            input[type="password"]::placeholder {
                color: #475569;
            }
            button[type="submit"] {
                width: 100%;
                padding: 0.875rem;
                border: none;
                border-radius: 12px;
                background: linear-gradient(135deg, #6366f1, #4f46e5);
                color: white;
                font-family: 'Inter', sans-serif;
                font-weight: 600;
                font-size: 0.9375rem;
                cursor: pointer;
                transition: all 0.2s ease;
                box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
            }
            button[type="submit"]:hover {
                background: linear-gradient(135deg, #818cf8, #6366f1);
                transform: translateY(-1px);
                box-shadow: 0 6px 16px rgba(99, 102, 241, 0.4);
            }
            button[type="submit"]:active {
                transform: translateY(0);
            }
            .error-msg {
                background: rgba(239, 68, 68, 0.1);
                border: 1px solid rgba(239, 68, 68, 0.2);
                color: #fca5a5;
                padding: 0.75rem 1rem;
                border-radius: 10px;
                font-size: 0.8125rem;
                margin-bottom: 1.25rem;
                text-align: center;
            }
        </style>
    </head>
    <body>
        <div class="login-container">
            <div class="login-box">
                <div class="login-logo">TC</div>
                <h2>TCGiant Analytics</h2>
                <p class="login-subtitle">Sign in to access your dashboard</p>
                <?php if ($login_error): ?>
                    <div class="error-msg">Invalid password. Please try again.</div>
                <?php endif; ?>
                <form method="POST">
                    <div class="input-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" placeholder="Enter your password" required>
                    </div>
                    <button type="submit">Sign In</button>
                </form>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// =======================================================================
// DASHBOARD — Authenticated Zone
// =======================================================================
$db = new SQLite3(__DIR__ . '/sync.db');
$db->exec("CREATE TABLE IF NOT EXISTS sites (id INTEGER PRIMARY KEY, site_url TEXT UNIQUE, last_connected DATETIME, total_synced INTEGER DEFAULT 0, license_type TEXT DEFAULT 'free')");
@$db->exec("ALTER TABLE sites ADD COLUMN total_synced INTEGER DEFAULT 0");
@$db->exec("ALTER TABLE sites ADD COLUMN license_type TEXT DEFAULT 'free'");
@$db->exec("ALTER TABLE sites ADD COLUMN total_pushed INTEGER DEFAULT 0");
@$db->exec("ALTER TABLE sites ADD COLUMN total_pulled INTEGER DEFAULT 0");

// --- Aggregate Stats ---
$total_sites = (int)$db->querySingle("SELECT COUNT(*) FROM sites");
$total_synced_global = (int)$db->querySingle("SELECT SUM(total_synced) FROM sites");
$total_pushed_global = (int)$db->querySingle("SELECT SUM(total_pushed) FROM sites");
$total_pulled_global = (int)$db->querySingle("SELECT SUM(total_pulled) FROM sites");

// --- License Tier Counts ---
$license_counts = ['free' => 0, 'monthly' => 0, 'annual' => 0, 'lifetime' => 0];
$lc_result = $db->query("SELECT LOWER(COALESCE(license_type, 'free')) as lic, COUNT(*) as cnt FROM sites GROUP BY lic");
while ($lc_row = $lc_result->fetchArray(SQLITE3_ASSOC)) {
    $key = $lc_row['lic'] ?: 'free';
    if (isset($license_counts[$key])) {
        $license_counts[$key] = (int)$lc_row['cnt'];
    } else {
        $license_counts['free'] += (int)$lc_row['cnt'];
    }
}

// --- Revenue Calculations ---
$pricing = [
    'lifetime' => 99,
    'annual'   => 49,
    'monthly'  => 5,
    'free'     => 0,
];

$revenue_lifetime = $license_counts['lifetime'] * $pricing['lifetime'];
$revenue_annual   = $license_counts['annual']   * $pricing['annual'];
$revenue_monthly  = $license_counts['monthly']  * $pricing['monthly'];
$total_revenue    = $revenue_lifetime + $revenue_annual + $revenue_monthly;
$mrr              = $revenue_monthly + round($revenue_annual / 12, 2);
$arr              = ($mrr * 12) + $revenue_lifetime;

// --- Fetch All Sites ---
$results = $db->query("SELECT * FROM sites ORDER BY last_connected DESC");
$sites = [];
while ($row = $results->fetchArray(SQLITE3_ASSOC)) {
    $sites[] = $row;
}

// --- Chart Data as JSON ---
$chart_data = json_encode([
    'licenseCounts' => $license_counts,
    'revenue' => [
        'lifetime' => $revenue_lifetime,
        'annual'   => $revenue_annual,
        'monthly'  => $revenue_monthly,
        'total'    => $total_revenue,
        'mrr'      => $mrr,
        'arr'      => $arr,
    ],
    'pricing' => $pricing,
]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>TCGiant Sync — Analytics Dashboard</title>
    <meta name="description" content="TCGiant Sync internal analytics and revenue dashboard">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <style>
        /* ===================== CSS RESET & BASE ===================== */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --bg-base: #0a0e1a;
            --bg-surface: rgba(15, 23, 42, 0.7);
            --bg-surface-hover: rgba(30, 41, 59, 0.8);
            --border: rgba(99, 102, 241, 0.1);
            --border-hover: rgba(99, 102, 241, 0.25);
            --text-primary: #f1f5f9;
            --text-secondary: #94a3b8;
            --text-muted: #64748b;
            --accent-indigo: #6366f1;
            --accent-cyan: #06b6d4;
            --accent-emerald: #10b981;
            --accent-amber: #f59e0b;
            --accent-rose: #f43f5e;
            --accent-purple: #a855f7;
            --accent-blue: #3b82f6;
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 16px;
            --radius-xl: 20px;
            --shadow-card: 0 4px 24px rgba(0, 0, 0, 0.2), 0 0 0 1px rgba(255, 255, 255, 0.03);
            --shadow-glow-indigo: 0 0 20px rgba(99, 102, 241, 0.15);
            --shadow-glow-cyan: 0 0 20px rgba(6, 182, 212, 0.15);
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg-base);
            color: var(--text-primary);
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* Ambient background glow */
        body::before {
            content: '';
            position: fixed;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(ellipse at 20% 0%, rgba(99, 102, 241, 0.07) 0%, transparent 50%),
                        radial-gradient(ellipse at 80% 100%, rgba(6, 182, 212, 0.05) 0%, transparent 50%),
                        radial-gradient(ellipse at 50% 50%, rgba(168, 85, 247, 0.03) 0%, transparent 50%);
            pointer-events: none;
            z-index: 0;
        }

        /* ===================== LAYOUT ===================== */
        .dashboard-wrapper {
            position: relative;
            z-index: 1;
            max-width: 1440px;
            margin: 0 auto;
            padding: 2rem;
        }

        /* ===================== HEADER ===================== */
        .dash-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2.5rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid var(--border);
            animation: fadeIn 0.5s ease;
        }
        .dash-header-left {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .dash-logo {
            width: 44px;
            height: 44px;
            background: linear-gradient(135deg, var(--accent-indigo), var(--accent-cyan));
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 1rem;
            color: white;
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
            flex-shrink: 0;
        }
        .dash-title {
            font-size: 1.375rem;
            font-weight: 700;
            background: linear-gradient(135deg, #f1f5f9, #94a3b8);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .dash-subtitle {
            font-size: 0.75rem;
            color: var(--text-muted);
            font-weight: 400;
            margin-top: 2px;
        }
        .dash-header-right {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .dash-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.2);
            color: var(--accent-emerald);
            padding: 0.375rem 0.75rem;
            border-radius: 999px;
            font-size: 0.6875rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .dash-badge::before {
            content: '';
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--accent-emerald);
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.4; }
        }
        .logout-btn {
            color: var(--text-muted);
            text-decoration: none;
            font-size: 0.8125rem;
            font-weight: 500;
            padding: 0.5rem 1rem;
            border-radius: var(--radius-sm);
            transition: all 0.2s ease;
            border: 1px solid transparent;
        }
        .logout-btn:hover {
            color: var(--text-secondary);
            background: var(--bg-surface);
            border-color: var(--border);
        }

        /* ===================== STAT CARDS ===================== */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
            animation: fadeIn 0.5s ease 0.1s both;
        }
        .stat-card {
            background: var(--bg-surface);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--card-accent, var(--accent-indigo)), transparent);
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .stat-card:hover {
            border-color: var(--border-hover);
            transform: translateY(-2px);
            box-shadow: var(--shadow-card);
        }
        .stat-card:hover::before {
            opacity: 1;
        }
        .stat-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
        }
        .stat-card-label {
            font-size: 0.6875rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }
        .stat-card-icon {
            width: 36px;
            height: 36px;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            background: rgba(99, 102, 241, 0.1);
            color: var(--accent-indigo);
        }
        .stat-card-value {
            font-size: 1.875rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            line-height: 1;
            margin-bottom: 0.25rem;
        }
        .stat-card-sub {
            font-size: 0.6875rem;
            color: var(--text-muted);
        }

        /* Card accent variants */
        .stat-card.indigo { --card-accent: var(--accent-indigo); }
        .stat-card.indigo .stat-card-icon { background: rgba(99, 102, 241, 0.1); color: var(--accent-indigo); }
        .stat-card.indigo .stat-card-value { color: #a5b4fc; }

        .stat-card.cyan { --card-accent: var(--accent-cyan); }
        .stat-card.cyan .stat-card-icon { background: rgba(6, 182, 212, 0.1); color: var(--accent-cyan); }
        .stat-card.cyan .stat-card-value { color: #67e8f9; }

        .stat-card.emerald { --card-accent: var(--accent-emerald); }
        .stat-card.emerald .stat-card-icon { background: rgba(16, 185, 129, 0.1); color: var(--accent-emerald); }
        .stat-card.emerald .stat-card-value { color: #6ee7b7; }

        .stat-card.amber { --card-accent: var(--accent-amber); }
        .stat-card.amber .stat-card-icon { background: rgba(245, 158, 11, 0.1); color: var(--accent-amber); }
        .stat-card.amber .stat-card-value { color: #fcd34d; }

        .stat-card.purple { --card-accent: var(--accent-purple); }
        .stat-card.purple .stat-card-icon { background: rgba(168, 85, 247, 0.1); color: var(--accent-purple); }
        .stat-card.purple .stat-card-value { color: #c4b5fd; }

        .stat-card.rose { --card-accent: var(--accent-rose); }
        .stat-card.rose .stat-card-icon { background: rgba(244, 63, 94, 0.1); color: var(--accent-rose); }
        .stat-card.rose .stat-card-value { color: #fda4af; }

        .stat-card.blue { --card-accent: var(--accent-blue); }
        .stat-card.blue .stat-card-icon { background: rgba(59, 130, 246, 0.1); color: var(--accent-blue); }
        .stat-card.blue .stat-card-value { color: #93c5fd; }

        /* ===================== REVENUE ROW ===================== */
        .revenue-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 1.5rem;
            animation: fadeIn 0.5s ease 0.15s both;
        }

        /* ===================== SECTION HEADER ===================== */
        .section-header {
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 1rem;
            padding-left: 0.25rem;
        }

        /* ===================== CHART GRID ===================== */
        .charts-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 1rem;
            margin-bottom: 2rem;
            animation: fadeIn 0.5s ease 0.2s both;
        }
        .chart-card {
            background: var(--bg-surface);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            transition: all 0.3s ease;
        }
        .chart-card:hover {
            border-color: var(--border-hover);
            box-shadow: var(--shadow-card);
        }
        .chart-title {
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--text-secondary);
            margin-bottom: 1.25rem;
        }
        .chart-container {
            position: relative;
            width: 100%;
            height: 250px;
        }

        /* ===================== DATA TABLE ===================== */
        .table-section {
            animation: fadeIn 0.5s ease 0.3s both;
        }
        .table-card {
            background: var(--bg-surface);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            overflow: hidden;
        }
        .table-header-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--border);
        }
        .table-header-bar h3 {
            font-size: 0.9375rem;
            font-weight: 600;
            color: var(--text-primary);
        }
        .table-header-bar .count-badge {
            background: rgba(99, 102, 241, 0.1);
            color: var(--accent-indigo);
            padding: 0.25rem 0.75rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        thead th {
            padding: 0.875rem 1.5rem;
            text-align: left;
            font-size: 0.6875rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            background: rgba(0, 0, 0, 0.15);
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
        }
        tbody td {
            padding: 0.875rem 1.5rem;
            font-size: 0.8125rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.03);
            vertical-align: middle;
        }
        tbody tr {
            transition: background 0.15s ease;
        }
        tbody tr:hover {
            background: rgba(99, 102, 241, 0.04);
        }
        tbody tr:last-child td {
            border-bottom: none;
        }
        .cell-domain {
            color: var(--text-primary);
            font-weight: 500;
        }
        .cell-count {
            font-weight: 700;
            font-variant-numeric: tabular-nums;
            color: #a5b4fc;
        }
        .cell-date {
            color: var(--text-muted);
            font-size: 0.75rem;
        }

        /* License badges */
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.625rem;
            border-radius: 999px;
            font-size: 0.6875rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .badge-free {
            background: rgba(100, 116, 139, 0.15);
            color: #94a3b8;
            border: 1px solid rgba(100, 116, 139, 0.2);
        }
        .badge-monthly {
            background: rgba(16, 185, 129, 0.1);
            color: #6ee7b7;
            border: 1px solid rgba(16, 185, 129, 0.2);
        }
        .badge-annual {
            background: rgba(59, 130, 246, 0.1);
            color: #93c5fd;
            border: 1px solid rgba(59, 130, 246, 0.2);
        }
        .badge-lifetime {
            background: rgba(168, 85, 247, 0.1);
            color: #c4b5fd;
            border: 1px solid rgba(168, 85, 247, 0.2);
        }

        /* Status dot */
        .status-dot {
            display: inline-block;
            width: 7px;
            height: 7px;
            border-radius: 50%;
            margin-right: 0.5rem;
            vertical-align: middle;
        }
        .status-active { background: var(--accent-emerald); box-shadow: 0 0 6px rgba(16, 185, 129, 0.5); }
        .status-stale  { background: var(--accent-amber); box-shadow: 0 0 6px rgba(245, 158, 11, 0.4); }
        .status-inactive { background: var(--accent-rose); box-shadow: 0 0 6px rgba(244, 63, 94, 0.4); }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--text-muted);
        }
        .empty-state-icon {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        /* Revenue highlight */
        .revenue-highlight {
            font-size: 0.6875rem;
            font-weight: 500;
            padding: 0.25rem 0.5rem;
            border-radius: var(--radius-sm);
            display: inline-block;
            margin-top: 0.375rem;
        }

        /* ===================== ANIMATIONS ===================== */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(12px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* ===================== RESPONSIVE ===================== */
        @media (max-width: 1200px) {
            .charts-grid { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 900px) {
            .stats-row, .revenue-row { grid-template-columns: repeat(2, 1fr); }
            .charts-grid { grid-template-columns: 1fr; }
            .dashboard-wrapper { padding: 1rem; }
        }
        @media (max-width: 600px) {
            .stats-row, .revenue-row { grid-template-columns: 1fr; }
            .dash-header { flex-direction: column; gap: 1rem; text-align: center; }
            .dash-header-right { justify-content: center; }
            thead th, tbody td { padding: 0.625rem 0.875rem; }
        }

        /* Scrollbar */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: rgba(99, 102, 241, 0.2); border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: rgba(99, 102, 241, 0.4); }
    </style>
</head>
<body>

<div class="dashboard-wrapper">

    <!-- HEADER -->
    <div class="dash-header">
        <div class="dash-header-left">
            <div class="dash-logo">TC</div>
            <div>
                <div class="dash-title">TCGiant Sync</div>
                <div class="dash-subtitle">Analytics & Revenue Dashboard</div>
            </div>
        </div>
        <div class="dash-header-right">
            <div class="dash-badge">Live</div>
            <a href="?logout=1" class="logout-btn">Sign Out</a>
        </div>
    </div>

    <!-- OVERVIEW STATS ROW -->
    <div class="section-header">Overview</div>
    <div class="stats-row">
        <div class="stat-card indigo">
            <div class="stat-card-header">
                <span class="stat-card-label">Connected Sites</span>
                <div class="stat-card-icon">🌐</div>
            </div>
            <div class="stat-card-value"><?= number_format($total_sites) ?></div>
            <div class="stat-card-sub">Total active installations</div>
        </div>
        <div class="stat-card cyan">
            <div class="stat-card-header">
                <span class="stat-card-label">Products Synced</span>
                <div class="stat-card-icon">🔄</div>
            </div>
            <div class="stat-card-value"><?= number_format($total_synced_global) ?></div>
            <div class="stat-card-sub">Across all connected sites</div>
        </div>
        <div class="stat-card emerald">
            <div class="stat-card-header">
                <span class="stat-card-label">Total Pushed</span>
                <div class="stat-card-icon">📤</div>
            </div>
            <div class="stat-card-value"><?= number_format($total_pushed_global) ?></div>
            <div class="stat-card-sub">Products pushed to eBay</div>
        </div>
        <div class="stat-card amber">
            <div class="stat-card-header">
                <span class="stat-card-label">Total Pulled</span>
                <div class="stat-card-icon">📥</div>
            </div>
            <div class="stat-card-value"><?= number_format($total_pulled_global) ?></div>
            <div class="stat-card-sub">Products pulled from eBay</div>
        </div>
    </div>

    <!-- REVENUE STATS ROW -->
    <div class="section-header">Revenue</div>
    <div class="revenue-row">
        <div class="stat-card purple">
            <div class="stat-card-header">
                <span class="stat-card-label">Total Revenue</span>
                <div class="stat-card-icon">💰</div>
            </div>
            <div class="stat-card-value">$<?= number_format($total_revenue) ?></div>
            <div class="stat-card-sub">All-time revenue earned</div>
        </div>
        <div class="stat-card blue">
            <div class="stat-card-header">
                <span class="stat-card-label">Monthly Recurring (MRR)</span>
                <div class="stat-card-icon">📊</div>
            </div>
            <div class="stat-card-value">$<?= number_format($mrr, 2) ?></div>
            <div class="stat-card-sub"><?= $license_counts['monthly'] ?> monthly + <?= $license_counts['annual'] ?> annual subs</div>
        </div>
        <div class="stat-card rose">
            <div class="stat-card-header">
                <span class="stat-card-label">Annual Run Rate (ARR)</span>
                <div class="stat-card-icon">🚀</div>
            </div>
            <div class="stat-card-value">$<?= number_format($arr, 2) ?></div>
            <div class="stat-card-sub">Projected yearly revenue</div>
        </div>
        <div class="stat-card emerald">
            <div class="stat-card-header">
                <span class="stat-card-label">Paid Conversion</span>
                <div class="stat-card-icon">✨</div>
            </div>
            <div class="stat-card-value"><?= $total_sites > 0 ? round((($total_sites - $license_counts['free']) / $total_sites) * 100) : 0 ?>%</div>
            <div class="stat-card-sub"><?= $total_sites - $license_counts['free'] ?> of <?= $total_sites ?> sites are paid</div>
        </div>
    </div>

    <!-- CHARTS -->
    <div class="section-header">Analytics</div>
    <div class="charts-grid">
        <div class="chart-card">
            <div class="chart-title">License Distribution</div>
            <div class="chart-container">
                <canvas id="licenseDoughnut"></canvas>
            </div>
        </div>
        <div class="chart-card">
            <div class="chart-title">Revenue by Tier</div>
            <div class="chart-container">
                <canvas id="revenueBar"></canvas>
            </div>
        </div>
        <div class="chart-card">
            <div class="chart-title">Revenue Composition</div>
            <div class="chart-container">
                <canvas id="revenueBreakdown"></canvas>
            </div>
        </div>
    </div>

    <!-- SITES TABLE -->
    <div class="table-section">
        <div class="section-header">Connected Sites</div>
        <div class="table-card">
            <div class="table-header-bar">
                <h3>All Sites</h3>
                <span class="count-badge"><?= $total_sites ?> site<?= $total_sites !== 1 ? 's' : '' ?></span>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>Site URL</th>
                        <th>License</th>
                        <th>Revenue</th>
                        <th>Synced</th>
                        <th>Pushed</th>
                        <th>Pulled</th>
                        <th>Last Active</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($sites)): ?>
                        <tr>
                            <td colspan="8">
                                <div class="empty-state">
                                    <div class="empty-state-icon">📡</div>
                                    <div>No sites connected yet. Install the TCGiant Sync plugin to get started.</div>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($sites as $site):
                            $lic = strtolower($site['license_type'] ?: 'free');
                            $badge_class = 'badge-' . $lic;

                            // Revenue per site
                            $site_revenue = 0;
                            if ($lic === 'lifetime') $site_revenue = 99;
                            elseif ($lic === 'annual') $site_revenue = 49;
                            elseif ($lic === 'monthly') $site_revenue = 5;

                            // Activity status
                            $last = strtotime($site['last_connected']);
                            $diff_hours = (time() - $last) / 3600;
                            if ($diff_hours < 24) {
                                $status_class = 'status-active';
                                $status_label = 'Active';
                            } elseif ($diff_hours < 168) { // 7 days
                                $status_class = 'status-stale';
                                $status_label = 'Idle';
                            } else {
                                $status_class = 'status-inactive';
                                $status_label = 'Inactive';
                            }
                        ?>
                            <tr>
                                <td>
                                    <span class="status-dot <?= $status_class ?>"></span>
                                    <span style="font-size: 0.6875rem; color: var(--text-muted);"><?= $status_label ?></span>
                                </td>
                                <td class="cell-domain"><?= htmlspecialchars($site['site_url']) ?></td>
                                <td><span class="badge <?= $badge_class ?>"><?= htmlspecialchars($lic) ?></span></td>
                                <td style="color: <?= $site_revenue > 0 ? '#6ee7b7' : 'var(--text-muted)' ?>; font-weight: 600; font-variant-numeric: tabular-nums;">
                                    <?= $site_revenue > 0 ? '$' . $site_revenue : '—' ?>
                                    <?php if ($lic === 'monthly'): ?>
                                        <span style="font-size: 0.625rem; color: var(--text-muted);">/mo</span>
                                    <?php elseif ($lic === 'annual'): ?>
                                        <span style="font-size: 0.625rem; color: var(--text-muted);">/yr</span>
                                    <?php endif; ?>
                                </td>
                                <td class="cell-count"><?= number_format((int)$site['total_synced']) ?></td>
                                <td class="cell-count" style="color: var(--text-secondary);"><?= number_format((int)$site['total_pushed']) ?></td>
                                <td class="cell-count" style="color: var(--text-secondary);"><?= number_format((int)$site['total_pulled']) ?></td>
                                <td class="cell-date"><?= date('M j, Y g:i A', strtotime($site['last_connected'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- ===================== CHART.JS ===================== -->
<script>
const DATA = <?= $chart_data ?>;

// Shared chart defaults
Chart.defaults.color = '#94a3b8';
Chart.defaults.borderColor = 'rgba(99, 102, 241, 0.08)';
Chart.defaults.font.family = "'Inter', sans-serif";
Chart.defaults.font.size = 12;
Chart.defaults.plugins.legend.labels.padding = 16;
Chart.defaults.plugins.legend.labels.usePointStyle = true;
Chart.defaults.plugins.legend.labels.pointStyleWidth = 10;

const COLORS = {
    free: { bg: 'rgba(100, 116, 139, 0.6)', border: '#64748b' },
    monthly: { bg: 'rgba(16, 185, 129, 0.6)', border: '#10b981' },
    annual: { bg: 'rgba(59, 130, 246, 0.6)', border: '#3b82f6' },
    lifetime: { bg: 'rgba(168, 85, 247, 0.6)', border: '#a855f7' },
};

// --- LICENSE DOUGHNUT ---
new Chart(document.getElementById('licenseDoughnut'), {
    type: 'doughnut',
    data: {
        labels: ['Free', 'Monthly ($5/mo)', 'Annual ($49/yr)', 'Lifetime ($99)'],
        datasets: [{
            data: [DATA.licenseCounts.free, DATA.licenseCounts.monthly, DATA.licenseCounts.annual, DATA.licenseCounts.lifetime],
            backgroundColor: [COLORS.free.bg, COLORS.monthly.bg, COLORS.annual.bg, COLORS.lifetime.bg],
            borderColor: [COLORS.free.border, COLORS.monthly.border, COLORS.annual.border, COLORS.lifetime.border],
            borderWidth: 2,
            hoverOffset: 8,
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '65%',
        plugins: {
            legend: {
                position: 'bottom',
                labels: { padding: 12, font: { size: 11 } }
            },
            tooltip: {
                backgroundColor: 'rgba(15, 23, 42, 0.9)',
                titleColor: '#f1f5f9',
                bodyColor: '#cbd5e1',
                borderColor: 'rgba(99, 102, 241, 0.2)',
                borderWidth: 1,
                cornerRadius: 10,
                padding: 12,
                callbacks: {
                    label: function(ctx) {
                        const total = ctx.dataset.data.reduce((a,b) => a+b, 0);
                        const pct = total > 0 ? ((ctx.raw / total) * 100).toFixed(1) : 0;
                        return ` ${ctx.label}: ${ctx.raw} sites (${pct}%)`;
                    }
                }
            }
        }
    }
});

// --- REVENUE BAR ---
new Chart(document.getElementById('revenueBar'), {
    type: 'bar',
    data: {
        labels: ['Monthly', 'Annual', 'Lifetime'],
        datasets: [{
            label: 'Revenue ($)',
            data: [DATA.revenue.monthly, DATA.revenue.annual, DATA.revenue.lifetime],
            backgroundColor: [
                'rgba(16, 185, 129, 0.5)',
                'rgba(59, 130, 246, 0.5)',
                'rgba(168, 85, 247, 0.5)',
            ],
            borderColor: [
                'rgba(16, 185, 129, 0.8)',
                'rgba(59, 130, 246, 0.8)',
                'rgba(168, 85, 247, 0.8)',
            ],
            borderWidth: 2,
            borderRadius: 8,
            borderSkipped: false,
            barPercentage: 0.6,
        }]
    },
    options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: 'rgba(15, 23, 42, 0.9)',
                titleColor: '#f1f5f9',
                bodyColor: '#cbd5e1',
                borderColor: 'rgba(99, 102, 241, 0.2)',
                borderWidth: 1,
                cornerRadius: 10,
                padding: 12,
                callbacks: {
                    label: function(ctx) {
                        return ` $${ctx.raw.toLocaleString()}`;
                    }
                }
            }
        },
        scales: {
            x: {
                ticks: {
                    callback: function(value) { return '$' + value; },
                    font: { size: 11 }
                },
                grid: { color: 'rgba(99, 102, 241, 0.06)' }
            },
            y: {
                ticks: { font: { size: 12, weight: '500' } },
                grid: { display: false }
            }
        }
    }
});

// --- REVENUE COMPOSITION (Stacked bar showing MRR vs one-time) ---
new Chart(document.getElementById('revenueBreakdown'), {
    type: 'bar',
    data: {
        labels: ['One-Time', 'Recurring (Monthly)', 'Recurring (Annual)'],
        datasets: [{
            label: 'Revenue',
            data: [DATA.revenue.lifetime, DATA.revenue.monthly, DATA.revenue.annual],
            backgroundColor: [
                'rgba(168, 85, 247, 0.5)',
                'rgba(16, 185, 129, 0.5)',
                'rgba(59, 130, 246, 0.5)',
            ],
            borderColor: [
                'rgba(168, 85, 247, 0.8)',
                'rgba(16, 185, 129, 0.8)',
                'rgba(59, 130, 246, 0.8)',
            ],
            borderWidth: 2,
            borderRadius: 8,
            borderSkipped: false,
            barPercentage: 0.5,
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: 'rgba(15, 23, 42, 0.9)',
                titleColor: '#f1f5f9',
                bodyColor: '#cbd5e1',
                borderColor: 'rgba(99, 102, 241, 0.2)',
                borderWidth: 1,
                cornerRadius: 10,
                padding: 12,
                callbacks: {
                    label: function(ctx) {
                        return ` $${ctx.raw.toLocaleString()}`;
                    }
                }
            }
        },
        scales: {
            x: {
                ticks: { font: { size: 10 } },
                grid: { display: false }
            },
            y: {
                ticks: {
                    callback: function(value) { return '$' + value; },
                    font: { size: 11 }
                },
                grid: { color: 'rgba(99, 102, 241, 0.06)' }
            }
        }
    }
});
</script>

</body>
</html>
