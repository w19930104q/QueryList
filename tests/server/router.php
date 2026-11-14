<?php
/**
 * batchCollect 本地回归测试服务器（PHP 内置服务器路由脚本）。
 *
 * 启动方式（测试脚本会自动启动，无需手动执行）：
 *   PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:18924 -t tests/server tests/server/router.php
 *
 * 路由说明：
 *   /slow?i=N            慢请求，记录服务端观测到的并发峰值（/state 读取）
 *   /state               输出并发统计 JSON
 *   /siteA/products      3 页分页目录，种植 session=session-A，回显请求头/Cookie
 *   /siteB/products      2 页分页目录，种植 session=session-B，回显请求头/Cookie
 *   /redirect-entry      302 跳转到 /redirect-target
 *   /redirect-target     重定向目标页
 *   /boom                恒 500
 *   /flaky?token=T       每个 token 前 2 次 500，第 3 次起 200
 *   /endless?page=N      无限分页（始终有下一页）
 *   /echo-post           回显 Cookie 与请求头（JSON）
 *
 * 共享状态存放于 sys_get_temp_dir().'/ql_batch_server_state'，
 * 多 worker 进程通过 flock 串行化读写。
 */

$stateDir = sys_get_temp_dir() . '/ql_batch_server_state';
if (!is_dir($stateDir)) {
    @mkdir($stateDir, 0777, true);
}

/**
 * 原子读取-修改-写回共享状态。
 */
function state_update(callable $fn): array
{
    global $stateDir;
    $file = $stateDir . '/state.json';
    $fp = fopen($file, 'c+');
    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $state = $raw ? json_decode($raw, true) : [];
    is_array($state) || $state = [];
    $state += ['slow_cur' => 0, 'slow_max' => 0];
    $state = $fn($state);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($state));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $state;
}

function html_response(string $body): void
{
    header('Content-Type: text/html; charset=utf-8');
    echo $body;
}

function supplier_page(string $site, string $session, int $page, int $pages, string $itemTpl, string $nextRel): void
{
    // 每个站点种下自己的会话 Cookie；跨站泄漏会在这里暴露
    setcookie('session', $session, 0, '/');
    $token = $_SERVER['HTTP_X_SUPPLIER_TOKEN'] ?? '';
    $cookie = $_COOKIE['session'] ?? '';
    $html = '<html><head>'
        . '<meta name="token" content="' . htmlspecialchars($token, ENT_QUOTES) . '">'
        . '<meta name="cookie" content="' . htmlspecialchars($cookie, ENT_QUOTES) . '">'
        . '</head><body>';
    $html .= $itemTpl;
    if ($page < $pages) {
        // 故意使用相对地址，验证分页续接的地址解析
        $html .= '<a class="next" rel="' . $nextRel . '" href="?page=' . ($page + 1) . '">next</a>';
    }
    $html .= '</body></html>';
    html_response($html);
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
parse_str($_SERVER['QUERY_STRING'] ?? '', $query);

switch ($path) {
    case '/slow':
        state_update(function ($s) {
            $s['slow_cur']++;
            $s['slow_max'] = max($s['slow_max'], $s['slow_cur']);
            return $s;
        });
        usleep(300000);
        state_update(function ($s) {
            $s['slow_cur']--;
            return $s;
        });
        html_response('<html><body>slow-' . htmlspecialchars($query['i'] ?? '') . '</body></html>');
        break;

    case '/state':
        header('Content-Type: application/json');
        echo json_encode(state_update(function ($s) {
            return $s;
        }));
        break;

    case '/siteA/products':
        $page = max(1, (int)($query['page'] ?? 1));
        $items = '';
        for ($k = 1; $k <= 2; $k++) {
            $n = ($page - 1) * 2 + $k;
            $items .= '<div class="product"><span class="name">A-p' . $n . '</span>'
                . '<span class="price">' . (10 + $n) . '</span></div>';
        }
        supplier_page('siteA', 'session-A', $page, 3, $items, 'next');
        break;

    case '/siteB/products':
        $page = max(1, (int)($query['page'] ?? 1));
        $items = '';
        for ($k = 1; $k <= 2; $k++) {
            $n = ($page - 1) * 2 + $k;
            $items .= '<div class="item"><h3>B-p' . $n . '</h3></div>';
        }
        supplier_page('siteB', 'session-B', $page, 2, $items, 'nextpage');
        break;

    case '/redirect-entry':
        header('Location: /redirect-target', true, 302);
        break;

    case '/redirect-target':
        html_response('<html><body><div class="product"><span class="name">R-p1</span></div></body></html>');
        break;

    case '/boom':
        http_response_code(500);
        html_response('<html><body>Internal Server Error</body></html>');
        break;

    case '/flaky':
        $token = preg_replace('/[^a-zA-Z0-9_-]/', '', $query['token'] ?? 'default');
        $file = $stateDir . '/flaky_' . $token;
        $fp = fopen($file, 'c+');
        flock($fp, LOCK_EX);
        $count = (int)stream_get_contents($fp) + 1;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, (string)$count);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        if ($count <= 2) {
            http_response_code(500);
            html_response('<html><body>flaky failure ' . $count . '</body></html>');
        } else {
            html_response('<html><body><div class="product"><span class="name">F-p1</span></div></body></html>');
        }
        break;

    case '/endless':
        $page = max(1, (int)($query['page'] ?? 1));
        html_response('<html><body>'
            . '<div class="product"><span class="name">E-p' . $page . '</span></div>'
            . '<a class="next" href="?page=' . ($page + 1) . '">next</a>'
            . '</body></html>');
        break;

    case '/echo-post':
        header('Content-Type: application/json');
        echo json_encode([
            'cookie' => $_COOKIE['cookie_a'] ?? '',
            'header' => $_SERVER['HTTP_X_SUPPLIER_TOKEN'] ?? '',
        ]);
        break;

    default:
        http_response_code(404);
        html_response('<html><body>Not Found: ' . htmlspecialchars($path) . '</body></html>');
}
