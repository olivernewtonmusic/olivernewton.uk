<?php
/*
 * RSVP collector for the party invite.
 * Upload this file to the same folder as index.html.
 *
 * Guests send their answer from the invite page. To see the replies, open
 *   https://your-site/your-folder/rsvp.php
 * and enter the password below.
 */

// ========== SETTINGS ==========
$ADMIN_PASSWORD = 'happy14th';      // Change this before you upload. The replies page stays locked until you do.
$MAX_ENTRIES    = 500;              // Stops the list growing without limit.
// ==============================

$DATA_FILE = __DIR__ . '/rsvp-data.php';
$LOCK_FILE = __DIR__ . '/rsvp-data.lock';

// The data file starts with a line that makes the server refuse to show it if someone
// requests it directly, so guest names and notes stay private on any PHP host.
$GUARD = '<' . '?php http_response_code(404); exit; ?' . ">\n";

function load_rows($file, $guard) {
    if (!is_file($file)) return [];
    $raw = file_get_contents($file);
    if ($raw === false) return [];
    if (strpos($raw, $guard) === 0) $raw = substr($raw, strlen($guard));
    $rows = json_decode($raw, true);
    return is_array($rows) ? $rows : [];
}

function save_rows($file, $guard, $rows) {
    file_put_contents($file, $guard . json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

function clean_text($value, $max) {
    $value = (string)$value;
    $value = preg_replace('/\s+/u', ' ', $value);
    if ($value === null) $value = '';
    $value = trim($value);
    return mb_substr($value, 0, $max);
}

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function csv_cell($s) {
    $s = (string)$s;
    if ($s !== '' && strpos("=+-@\t\r", $s[0]) !== false) $s = "'" . $s;   // stops spreadsheet formulas
    return '"' . str_replace('"', '""', $s) . '"';
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$type   = $_SERVER['CONTENT_TYPE'] ?? '';

/* ---------- 1. A guest sends an RSVP ---------- */
if ($method === 'POST' && strpos($type, 'application/json') !== false) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $body = file_get_contents('php://input', false, null, 0, 4096);
    $in = json_decode($body === false ? '' : $body, true);
    if (!is_array($in)) { http_response_code(400); echo '{"ok":false,"error":"bad request"}'; exit; }

    $name = clean_text($in['name'] ?? '', 80);
    if ($name === '') { http_response_code(422); echo '{"ok":false,"error":"name required"}'; exit; }

    $attending = !empty($in['attending']);
    $guests    = $attending ? max(1, min(10, (int)($in['guests'] ?? 1))) : 0;
    $notes     = clean_text($in['notes'] ?? '', 300);

    $lock = fopen($LOCK_FILE, 'c');
    if (!$lock || !flock($lock, LOCK_EX)) { http_response_code(500); echo '{"ok":false,"error":"busy"}'; exit; }

    $rows  = load_rows($DATA_FILE, $GUARD);
    $entry = ['name' => $name, 'attending' => $attending, 'guests' => $guests, 'notes' => $notes, 'time' => gmdate('c')];
    $key   = mb_strtolower($name);
    $found = false;

    foreach ($rows as $i => $row) {
        if (mb_strtolower($row['name'] ?? '') === $key) { $rows[$i] = $entry; $found = true; break; }   // same name = updated answer
    }
    if (!$found) {
        if (count($rows) >= $MAX_ENTRIES) {
            flock($lock, LOCK_UN); fclose($lock);
            http_response_code(503); echo '{"ok":false,"error":"full"}'; exit;
        }
        $rows[] = $entry;
    }

    save_rows($DATA_FILE, $GUARD, $rows);
    flock($lock, LOCK_UN); fclose($lock);
    echo '{"ok":true}';
    exit;
}

/* ---------- 2. The host views the replies ---------- */
if ($method === 'GET' || ($method === 'POST' && isset($_POST['password']))) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');

    $pw = (string)($_POST['password'] ?? '');
    $locked = ($ADMIN_PASSWORD === 'change-me');
    $ok = !$locked && $method === 'POST' && hash_equals($ADMIN_PASSWORD, $pw);

    if ($ok && isset($_POST['csv'])) {
        $rows = load_rows($DATA_FILE, $GUARD);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="rsvps.csv"');
        echo "Name,Answer,Guests,Notes,Received (UTC)\n";
        foreach ($rows as $r) {
            echo implode(',', [
                csv_cell($r['name'] ?? ''),
                csv_cell(!empty($r['attending']) ? 'Coming' : "Can't come"),
                csv_cell($r['guests'] ?? 0),
                csv_cell($r['notes'] ?? ''),
                csv_cell($r['time'] ?? ''),
            ]) . "\n";
        }
        exit;
    }

    $style = 'body{font:16px/1.5 system-ui,sans-serif;margin:0;padding:2rem 1.25rem;background:#f6f4ff;color:#10125c}'
           . 'main{max-width:900px;margin:0 auto}h1{margin:0 0 1rem}'
           . 'input,button{font:inherit;padding:.6rem .8rem;border:2px solid #10125c;border-radius:8px}'
           . 'button{background:#10125c;color:#fff;cursor:pointer}'
           . 'table{width:100%;border-collapse:collapse;margin-top:1rem;background:#fff}'
           . 'th,td{text-align:left;padding:.6rem .7rem;border-bottom:1px solid #d6d4ec;vertical-align:top}'
           . '.sum{display:flex;gap:1.5rem;flex-wrap:wrap;margin:1rem 0}.sum b{font-size:1.8rem;display:block}'
           . '.err{color:#8a0f1f;font-weight:600}.scroll{overflow-x:auto}';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<meta name="robots" content="noindex"><title>RSVP replies</title><style>' . $style . '</style></head><body><main><h1>RSVP replies</h1>';

    if ($locked) {
        echo '<p class="err">Set a password in rsvp.php first. Open the file, change the line that says $ADMIN_PASSWORD, and upload it again.</p>';
    } elseif (!$ok) {
        if ($method === 'POST') echo '<p class="err">That password is not right.</p>';
        echo '<form method="post"><label>Password<br><input type="password" name="password" autofocus required></label> <button type="submit">Show replies</button></form>';
    } else {
        $rows = load_rows($DATA_FILE, $GUARD);
        $yes = 0; $no = 0; $people = 0;
        foreach ($rows as $r) {
            if (!empty($r['attending'])) { $yes++; $people += (int)($r['guests'] ?? 0); } else { $no++; }
        }
        echo '<div class="sum"><div><b>' . $people . '</b>people coming</div><div><b>' . $yes . '</b>replies saying yes</div><div><b>' . $no . '</b>can\'t come</div></div>';
        echo '<form method="post"><input type="hidden" name="password" value="' . h($pw) . '"><input type="hidden" name="csv" value="1"><button type="submit">Download as spreadsheet (CSV)</button></form>';
        if (!$rows) {
            echo '<p>No replies yet.</p>';
        } else {
            echo '<div class="scroll"><table><thead><tr><th>Name</th><th>Answer</th><th>Guests</th><th>Notes</th><th>Received (UTC)</th></tr></thead><tbody>';
            foreach ($rows as $r) {
                echo '<tr><td>' . h($r['name'] ?? '') . '</td><td>' . (!empty($r['attending']) ? 'Coming' : 'Can\'t come') . '</td><td>'
                   . (int)($r['guests'] ?? 0) . '</td><td>' . h($r['notes'] ?? '') . '</td><td>' . h($r['time'] ?? '') . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
    }
    echo '</main></body></html>';
    exit;
}

/* ---------- 3. Anything else ---------- */
http_response_code(404);
echo 'Not found';
