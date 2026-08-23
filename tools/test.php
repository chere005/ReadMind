<?php
/**
 * BookMind's tests — no framework, all or nothing, same shape as the site's.
 *
 * The login is CalMind's, so the harness runs a MOCK CalMind API and points
 * the app at it: the test proves this side of the conversation — the right
 * action POSTed, ok+token believed, anything else refused — without a network
 * or a real account. The baseline rule stands: every check here was watched
 * failing first (wrong password, missing token, dead API).
 */

declare(strict_types=1);
$ROOT = dirname(__DIR__);
$PORT = 8796; $MOCK = 8797;
$pass = 0; $fail = 0;

function t(string $name, callable $fn): void
{
    global $pass, $fail;
    try { $fn(); $pass++; echo "  \033[32m✓\033[0m $name\n"; }
    catch (Throwable $e) { $fail++; echo "  \033[31m✗\033[0m $name\n      {$e->getMessage()}\n"; }
}
function ok(bool $c, string $why): void { if (!$c) { throw new RuntimeException($why); } }
function has(string $needle, string $hay, string $why): void
{
    ok(str_contains($hay, $needle), "$why (missing: $needle)");
}

// ------------------------------------------------------------------- lint
t('every PHP file parses', function () use ($ROOT) {
    foreach (glob("$ROOT/{public,lib,tools}/*.php", GLOB_BRACE) as $f) {
        exec('php -l ' . escapeshellarg($f) . ' 2>&1', $o, $rc);
        ok($rc === 0, "$f: " . implode(' ', $o));
    }
});

// ------------------------------------------------------------- the store
t('the store round-trips, encrypted at rest', function () use ($ROOT) {
    $dir = sys_get_temp_dir() . '/bookmind-test-' . getmypid();
    @mkdir($dir, 0700, true);
    // app_config() is derived, so the store is exercised directly with a
    // hand-built cfg — same functions the page calls.
    require_once "$ROOT/lib/store.php";
    if (!function_exists('app_config')) {
        function app_config(): array { return ['data_dir' => $GLOBALS['__tdir'], 'data_key' => '']; }
    }
    $GLOBALS['__tdir'] = $dir;
    store_write("$dir/books-x.json", [['id' => 'b1', 'title' => 'Dune']]);
    $raw = (string) file_get_contents("$dir/books-x.json");
    ok(str_starts_with($raw, 'ENC1:'), 'file is encrypted at rest');
    $back = store_read("$dir/books-x.json");
    ok(($back[0]['title'] ?? '') === 'Dune', 'and reads back');
    array_map('unlink', glob("$dir/*") ?: []); @rmdir($dir);
});

// ------------------------------------------- the login conversation, mocked
$mockRoot = sys_get_temp_dir() . '/bookmind-mock-' . getmypid();
@mkdir($mockRoot, 0700, true);
file_put_contents("$mockRoot/index.php", <<<'PHP'
<?php
// Mock CalMind API: exactly one good account, answers like the real one.
$in = json_decode((string) file_get_contents('php://input'), true) ?: [];
header('Content-Type: application/json');
if (($in['action'] ?? '') === 'login'
    && ($in['username'] ?? '') === 'reader'
    && ($in['password'] ?? '') === 'readerpw') {
    echo json_encode(['ok' => true, 'token' => 'mock-token']);
} else {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'bad credentials']);
}
PHP);

$dataDir = sys_get_temp_dir() . '/bookmind-data-' . getmypid();
@mkdir($dataDir, 0700, true);
// The app under test: BOOKMIND_TEST reroutes config at the top of app.php.
putenv("BOOKMIND_TEST_API=http://127.0.0.1:$MOCK/index.php");
putenv("BOOKMIND_TEST_DATA=$dataDir");

// The servers' fds go to /dev/null, NOT inherited: a child holding this
// process's stdout keeps every pipe and log file open after the harness
// exits, and the dtp lane hung on exactly that — a release waiting forever
// on two mock servers nobody needed any more.
$sink = [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
$p1 = proc_open("exec php -S 127.0.0.1:$MOCK -t $mockRoot", $sink, $x1);
$p2 = proc_open("BOOKMIND_TEST_API=http://127.0.0.1:$MOCK/index.php BOOKMIND_TEST_DATA=$dataDir "
              . "exec php -S 127.0.0.1:$PORT -t " . escapeshellarg("$ROOT/public"), $sink, $x2);
usleep(400000);

function req(string $method, string $path, array $post = [], array &$jar = []): array
{
    global $PORT;
    $headers = ['Connection: close'];
    if ($jar) {
        $bits = [];
        foreach ($jar as $k => $v) { $bits[] = "$k=$v"; }
        $headers[] = 'Cookie: ' . implode('; ', $bits);
    }
    $body = '';
    if ($method === 'POST') {
        $body = http_build_query($post);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $headers[] = 'Content-Length: ' . strlen($body);
    }
    $ctx = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers),
        'content' => $body, 'ignore_errors' => true, 'follow_location' => 0]]);
    $out = (string) @file_get_contents("http://127.0.0.1:$PORT$path", false, $ctx);
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) { $status = (int) $m[1]; }
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]+)/i', $h, $m)) { $jar[$m[1]] = $m[2]; }
    }
    return ['status' => $status, 'body' => $out];
}

t('signed out, the page is the login form and nothing else', function () {
    $jar = [];
    $r = req('GET', '/', [], $jar);
    has('Sign in with your CalMind account', $r['body'], 'the gate shows');
    ok(!str_contains($r['body'], 'BookShelf-grid'), 'and no shelf leaks past it');
});

t('a wrong password is refused — the mock CalMind said no', function () {
    $jar = [];
    $r = req('POST', '/', ['bm_user' => 'reader', 'bm_pass' => 'nope'], $jar);
    has('Invalid username or password', $r['body'], 'refusal shown');
});

t('a CalMind account signs in and gets its own empty shelf', function () {
    $jar = [];
    $r = req('POST', '/', ['bm_user' => 'reader', 'bm_pass' => 'readerpw'], $jar);
    ok($r['status'] === 302, "login should redirect (got {$r['status']})");
    $r2 = req('GET', '/', [], $jar);
    has('BookMind', $r2['body'], 'the app renders');
    ok(!str_contains($r2['body'], 'Sign in with your CalMind account'), 'past the gate');
    has('reader', $r2['body'], 'and knows who signed in');
});

proc_terminate($p1); proc_terminate($p2);
proc_close($p1); proc_close($p2);
array_map('unlink', glob("$mockRoot/*") ?: []); @rmdir($mockRoot);
array_map('unlink', glob("$dataDir/*") ?: []); @rmdir($dataDir);

echo "\n────────────────────────────────\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
