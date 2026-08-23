<?php
/**
 * BookMind's one lib — config, the CalMind-backed login, and the small
 * helpers the page needs. Sean, 2026-08-23: "make a clone of akisbookshelf
 * that uses calmind logins and call the new repo BookMind".
 *
 * WHY CALMIND LOGINS. This app has no account store, no signup flow and no
 * password to hash, on purpose: a username and password are proven by POSTing
 * CalMind's own `login` action — the same call every CalMind client makes —
 * and the session here holds only the verdict. CalMind going down takes
 * sign-IN down and nothing else: an open session keeps working, because the
 * token is checked at login and not per request. One account system for the
 * suite (Sean, 2026-08-23: "really core should be providing authentication
 * for all apps" — until core does, CalMind's API is the nearest thing to it).
 *
 * THE BASELINE for how to work in this repo is ~/GIT/AgentSuite/AGENTS.md.
 */

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/hitlog.php';

/**
 * Which instance this request is — '' is production, 'test'/'dev' the
 * sandboxes. Same three signals as seancheren-site's pages: the directory the
 * code runs from, the request path, and the subdomain the rewrite serves.
 */
function bm_instance(): string
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    foreach (['test', 'dev'] as $i) {
        if (preg_match('#/' . $i . '/#', __DIR__ . '/') === 1
            || strncmp($_SERVER['REQUEST_URI'] ?? '', '/' . $i . '/', strlen($i) + 2) === 0
            || strncmp($host, $i . '.', strlen($i) + 1) === 0) { return $i; }
    }
    return '';
}

/**
 * Config, derived rather than kept: this app has no secrets of its own. The
 * data key lives beside the data (store.php generates data/.datakey on first
 * use), and the one thing per instance is WHERE data lives and WHICH CalMind
 * answers logins — each instance signs in against its own CalMind, so a test
 * login can never be a production login.
 */
function app_config(): array
{
    static $cfg = null;
    if ($cfg !== null) { return $cfg; }
    $inst = bm_instance();
    $suffix = $inst === '' ? '' : '-' . $inst;
    $onHost = is_dir('/home/protected');
    $cfg = [
        'base'     => $inst === '' ? '' : '/' . $inst,
        'data_dir' => $onHost ? '/home/protected/bookmind-data' . $suffix
                              : dirname(__DIR__) . '/data' . $suffix,
        'data_key' => '',
        'calmind_api' => 'https://' . ($inst === '' ? '' : $inst . '.') . 'seancheren.com/CalMind/api/index.php',
    ];
    // The harness reroutes both taps — tools/test.php runs a mock CalMind and
    // a scratch data dir, and this is the whole seam it needs.
    if (($api = getenv('BOOKMIND_TEST_API')) !== false && $api !== '') { $cfg['calmind_api'] = $api; }
    if (($dd = getenv('BOOKMIND_TEST_DATA')) !== false && $dd !== '') { $cfg['data_dir'] = $dd; }
    return $cfg;
}

function suite_base(): string
{
    $b = trim((string) (app_config()['base'] ?? ''), '/');
    return $b === '' ? '' : '/' . $b;
}

function current_user(): ?string
{
    return $_SESSION['user'] ?? null;
}

/** Per-user data file, filename-safe — same contract as the site's. */
function user_data_file(string $dir, string $base, ?string $user = null): string
{
    $u    = $user ?? (current_user() ?? 'default');
    $safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $u);
    return rtrim($dir, '/') . "/{$base}-{$safe}.json";
}

/**
 * The verdict on a username and password, from CalMind itself. True only when
 * CalMind's login action answers ok with a token — the token is then dropped:
 * this app talks to no API afterwards, so holding a credential it never uses
 * would be pure liability.
 */
function calmind_login_ok(string $user, string $pass): bool
{
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST', 'timeout' => 8, 'ignore_errors' => true,
        'header'  => "Content-Type: application/json\r\nX-Status-Probe: 1\r\n",
        'content' => json_encode(['action' => 'login', 'username' => $user, 'password' => $pass]),
    ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $body = @file_get_contents(app_config()['calmind_api'], false, $ctx);
    $j = json_decode((string) $body, true);
    return is_array($j) && !empty($j['ok']) && !empty($j['token']);
}

/**
 * The gate. Renders a login form and exits until a CalMind account signs in;
 * the session cookie is per instance, so being signed into production's
 * BookMind says nothing about the sandboxes'.
 */
function require_login(string $area): void
{
    $inst = bm_instance();
    session_name('BMSESS' . ($inst === '' ? '' : '_' . strtoupper($inst)));
    session_set_cookie_params(['lifetime' => 365 * 24 * 3600, 'path' => '/',
                               'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax']);
    session_start();

    if (isset($_GET['logout'])) {
        session_destroy();
        header('Location: ' . strtok((string) $_SERVER['REQUEST_URI'], '?'));
        exit;
    }
    if (!empty($_SESSION['user'])) { hit_log('BookMind'); return; }

    $err = '';
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['bm_user'], $_POST['bm_pass'])) {
        $u = trim((string) $_POST['bm_user']);
        if ($u !== '' && calmind_login_ok($u, (string) $_POST['bm_pass'])) {
            session_regenerate_id(true);
            $_SESSION['user'] = $u;
            hit_log('BookMind', $u);
            header('Location: ' . strtok((string) $_SERVER['REQUEST_URI'], '?'));
            exit;
        }
        $err = 'Invalid username or password.';
        hit_log('BookMind');
    } else {
        hit_log('BookMind');
    }

    $area = htmlspecialchars($area, ENT_QUOTES);
    $msg  = $err === '' ? '' : '<p class="err">' . htmlspecialchars($err, ENT_QUOTES) . '</p>';
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>Sign in — ' . $area . '</title><style>'
       . 'body{font-family:system-ui,sans-serif;background:#15181a;color:#ece9e2;display:flex;'
       . 'min-height:100vh;align-items:center;justify-content:center;margin:0}'
       . 'form{background:#1d2124;border:1px solid #32383c;border-radius:14px;padding:30px 34px;width:min(320px,86vw)}'
       . 'h1{font-size:1.1rem;margin:0 0 4px} p.sub{margin:0 0 18px;color:#a9a59a;font-size:.85rem}'
       . 'label{display:block;font-size:.8rem;color:#a9a59a;margin:12px 0 4px}'
       . 'input{width:100%;box-sizing:border-box;background:#23282b;border:1px solid #32383c;border-radius:8px;'
       . 'color:#ece9e2;padding:9px 11px;font-size:.95rem}'
       . 'button{margin-top:18px;width:100%;background:#5fb6ac;color:#0d1f1d;border:0;border-radius:999px;'
       . 'padding:10px;font-weight:600;font-size:.95rem;cursor:pointer}'
       . '.err{color:#f0605c;font-size:.85rem;margin:12px 0 0}'
       . '</style></head><body><form method="post">'
       . '<h1>' . $area . '</h1><p class="sub">Sign in with your CalMind account.</p>'
       . '<label for="bm_user">Username</label><input id="bm_user" name="bm_user" autocomplete="username" required>'
       . '<label for="bm_pass">Password</label><input id="bm_pass" name="bm_pass" type="password" autocomplete="current-password" required>'
       . $msg . '<button>Sign in</button></form></body></html>';
    exit;
}

/**
 * BookMind's settings window — the shell of the suite's (same ids, so the
 * page's own theme-picker script binds unchanged) with the parts that cannot
 * be true here cut out. No password form: the password is a CalMind account's,
 * and the one place to change it is CalMind. No suite theme row: this app's
 * themes are the book themes the $extra slot carries.
 */
function settings_modal_html(string $extra = ''): string
{
    $u = htmlspecialchars(current_user() ?? '', ENT_QUOTES);
    return <<<HTML
<div class="setmodal-backdrop" id="setBackdrop">
  <div class="setmodal">
    <h2>Settings</h2>
    <p class="setwho">Signed in as <strong>{$u}</strong> &middot; a CalMind account</p>
    <p class="setnote">Password changes live in CalMind — this app checks yours there and stores nothing.</p>
    {$extra}
    <div class="setactions">
      <button type="button" class="setact setdone" id="setDone" title="Done" aria-label="Done">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6L9 17l-5-5"/></svg>
      </button>
    </div>
  </div>
</div>
HTML;
}

function settings_modal_styles(): string
{
    return <<<'CSS'
    .setmodal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.55); display: none;
                         align-items: center; justify-content: center; z-index: 60; }
    .setmodal-backdrop.open { display: flex; }
    .setmodal { background: var(--surface, #1d2124); border: 1px solid var(--line, #32383c);
                border-radius: 14px; padding: 1.4rem 1.6rem; width: min(340px, 88vw); }
    .setmodal h2 { margin: 0 0 0.3rem; font-size: 1.05rem; }
    .setmodal .setwho { margin: 0; color: var(--text-dim, #a9a59a); font-size: 0.85rem; }
    .setmodal .setnote { margin: 0.5rem 0 0; color: var(--muted, #726e64); font-size: 0.78rem; }
    .setmodal .setactions { display: flex; justify-content: flex-end; margin-top: 1.2rem; }
    .setmodal .setact { background: var(--accent, #5fb6ac); color: var(--accent-ink, #0d1f1d);
                        border: 0; border-radius: 999px; padding: 0.5rem 0.9rem; cursor: pointer;
                        display: inline-flex; align-items: center; }
CSS;
}

function settings_modal_script(): string
{
    return <<<'JS'
<script>(function () {
  var btn = document.getElementById('setBtn'), back = document.getElementById('setBackdrop');
  if (!btn || !back) { return; }
  var close = function () { back.classList.remove('open'); };
  btn.addEventListener('click', function (e) {
    e.stopPropagation();
    var menu = document.getElementById('userMenu'); if (menu) { menu.hidden = true; }
    back.classList.add('open');
  });
  document.getElementById('setDone').addEventListener('click', close);
  back.addEventListener('click', function (e) { if (e.target === back) { close(); } });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { close(); } });
})();</script>
JS;
}

/** Per-user prefs file — the book theme lives in it, same as the bookshelf's. */
function theme_file(): string
{
    return user_data_file(app_config()['data_dir'], 'prefs');
}

/**
 * The suite theme layer, deliberately empty. The book themes are COMPLETE
 * palettes — every variable the page reads — so the suite's base layer would
 * only paint variables the very next rule overwrites. The page hides the
 * suite theme row for the same reason; this keeps the same call sites as the
 * bookshelf so a future diff between the two stays readable.
 */
function theme_css(): string { return ''; }

/**
 * Two-press delete, used everywhere instead of a confirm() box or an Undo button.
 * Mark any delete control `needs-confirm`: the first press arms it (fills red), the
 * second goes through. Arming disarms itself after a few seconds, and only one
 * control is ever armed, so a stray tap can't leave a landmine somewhere off screen.
 *
 * Emit these in pages that don't already use chrome_styles()/chrome_script().
 */
function confirm_delete_styles(): string
{
    return <<<CSS
    .needs-confirm.armed {
      background: #b3261e; border-color: #f66; color: #fff; font-weight: 700;
    }
    .needs-confirm.armed:hover { background: #d0342c; border-color: #f88; color: #fff; }
    CSS;
}

function confirm_delete_script(): string
{
    // Capture phase, so this runs before the page's own submit/click handlers.
    return <<<'JS'
<script>(function () {
  var armed = null, timer = null;
  // Tell the server this was confirmed. Destructive handlers refuse without it, so a
  // stale or broken page can't delete anything on a single tap.
  function confirmed(b) {
    if (b.form && !b.form.querySelector('input[name="confirm"]')) {
      var c = document.createElement('input');
      c.type = 'hidden'; c.name = 'confirm'; c.value = '1';
      b.form.appendChild(c);
    }
    disarm();
  }
  function disarm() {
    if (timer) { clearTimeout(timer); timer = null; }
    if (armed) { armed.classList.remove('armed'); armed = null; }
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('.needs-confirm');
    if (!b) { disarm(); return; }          // tapping anything else calls it off
    if (b === armed) { confirmed(b); return; }   // second press: let the click through
    if (b.closest('.swiped')) { confirmed(b); return; }   // the swipe was the first press
    e.preventDefault();
    e.stopPropagation();
    disarm();
    armed = b;
    // The button keeps its own label — arming just fills it red. A × that turned into
    // the word "Delete?" resized the row it sat in and read as a different control.
    b.classList.add('armed');
    timer = setTimeout(disarm, 4000);
  }, true);
})();</script>
JS;
}

/**
 * Carry edit mode through a POST. Any form submitted while editing picks up an
 * `edit` field, so the handler's redirect can hand edit mode back and you aren't
 * dropped out of it just for adding something.
 *
 * This posted flag is the ONLY thing that may put edit mode in a redirect: the
 * server echoes it and never originates it, so the sole ways into edit mode are the
 * gestures (long-press / double-click). A handler that appended edit=1 on its own
 * meant a swipe-delete — made from outside edit mode on purpose — dumped you into it.
 */
function keep_edit_script(): string
{
    // Both halves are needed: the submit listener covers real submits, and the patched
    // prototype covers programmatic form.submit() (the rename fields' Enter/blur, the
    // pencil window's Save), which fires no submit event at all — without it, gating
    // the server on the posted flag would kick you out of edit mode on every rename.
    return <<<'JS'
<script>(function () {
  function stamp(f) {
    if (!f || f.tagName !== 'FORM') { return; }
    if (!document.body.classList.contains('editing')) { return; }
    if (f.querySelector('input[name="edit"]')) { return; }
    var i = document.createElement('input');
    i.type = 'hidden'; i.name = 'edit'; i.value = '1';
    f.appendChild(i);
  }
  document.addEventListener('submit', function (e) { stamp(e.target); }, true);
  var native = HTMLFormElement.prototype.submit;
  HTMLFormElement.prototype.submit = function () { stamp(this); return native.apply(this, arguments); };
})();</script>
JS;
}
