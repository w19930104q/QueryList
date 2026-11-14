<?php
/**
 * QueryList
 *
 * 批量映射采集入口：同一批任务采集多个供应商目录，
 * 每个任务拥有隔离的解析上下文、Cookie 罐与请求头。
 *
 * @author Jaeger
 * @email  JaegerCode@gmail.com
 */

namespace QL\Services\Batch;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use GuzzleHttp\Promise\Each;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\TransferStats;
use QL\QueryList;
use Throwable;

/**
 * Class BatchCollectService
 *
 * 用法：
 * ```php
 * $results = QueryList::batchCollect([
 *     [
 *         'url'     => 'http://supplier-a/catalog?page=1',
 *         'headers' => ['X-Token' => 'A'],
 *         'cookies' => ['session=xxx; Path=/'],
 *         'rules'   => ['name' => ['.name', 'text']],
 *         'range'   => '.product',
 *         'success' => function (QueryList $ql) {
 *             return $ql->find('.next-page')->attr('href') ?: null;
 *         },
 *     ],
 *     // ...
 * ], ['concurrency' => 3, 'retries' => 2]);
 * ```
 *
 * 结果按输入顺序返回，每项为 {@see BatchResult}；
 * 单个任务失败不会中断其他任务。
 *
 * @package QL\Services\Batch
 */
class BatchCollectService
{
    /**
     * 批量采集入口。
     *
     * @param QueryList $ql
     * @param array $tasks 任务列表，每个任务支持的键：
     *  - url        string            入口 URL（必填）
     *  - method     string            GET（默认）或 POST
     *  - args       array|string|null GET 查询参数 / POST 表单参数
     *  - headers    array             该任务独占的请求头，后续分页请求继承
     *  - cookies    array             该任务独占的 Cookie，元素为
     *                                 ['Name'=>..,'Value'=>..,'Domain'=>..] 数组
     *                                 或 "name=value; Path=/" 字符串
     *  - options    array             该任务附加的 Guzzle 请求选项
     *  - rules      array             QueryList 采集规则（配合 range 使用）
     *  - range      string            列表切片选择器
     *  - parser     Closure           function(QueryList $ql): array 自定义解析，
     *                                 返回本页记录数组；优先级高于 rules
     *  - success    Closure           function(QueryList $ql, Response $response, array $page): ?string
     *                                 分页回调，返回下一页地址（支持相对地址），返回 null 结束
     *  - retry_post bool              POST 请求默认不重试，置 true 后参与重试
     * @param array $options 全局选项：
     *  - concurrency    int   并发上限，默认 5
     *  - retries        int   失败重试次数，默认 0
     *  - retry_delay_ms int   重试前等待毫秒数，默认 0
     *  - max_pages      int   单任务最大抓取页数（0 为不限制），默认 0
     *  - client_options array 附加 Guzzle 请求选项（headers/cookies 会被剔除，
     *                         以保证任务间隔离）
     * @return BatchResult[] 按输入顺序排列的结果数组
     */
    public static function collect(QueryList $ql, array $tasks, array $options = []): array
    {
        $concurrency = max(1, (int)($options['concurrency'] ?? 5));
        $maxRetries = max(0, (int)($options['retries'] ?? 0));
        $retryDelayMs = max(0, (int)($options['retry_delay_ms'] ?? 0));
        $maxPages = max(0, (int)($options['max_pages'] ?? 0));
        $clientOptions = self::sanitizeClientOptions($options['client_options'] ?? []);

        $client = new Client();

        // 每个任务独立的运行上下文：Cookie 罐、请求头、结果对象互不共享
        $contexts = [];
        $queue = [];
        foreach ($tasks as $index => $task) {
            $task = self::normalizeTask($task);
            $result = new BatchResult($index, $task['url']);
            $contexts[$index] = [
                'task' => $task,
                'jar' => self::buildCookieJar($task['cookies']),
                'result' => $result,
            ];
            $queue[] = self::makeRequest($index, $task['url'], 1, 1);
        }

        // 分波调度：每一波在并发上限内执行完毕后才发起下一波
        // （分页续接、失败重试），因此任意时刻在途请求数不超过并发上限。
        while (!empty($queue)) {
            $wave = $queue;
            $queue = [];
            $retryScheduled = false;

            $promises = (function () use (&$wave, $client, $contexts, $clientOptions) {
                foreach ($wave as $key => $req) {
                    $context = $contexts[$req['index']];
                    $task = $context['task'];
                    [$request, $requestOptions] = self::buildRequest(
                        $client, $context, $req, $clientOptions
                    );
                    $wave[$key]['request'] = $request;
                    yield $key => $client->sendAsync($request, $requestOptions);
                }
            })();

            $onFulfilled = function (Response $response, $key) use (
                &$wave, &$queue, $contexts, $maxPages
            ) {
                $req = $wave[$key];
                $context = $contexts[$req['index']];
                $result = $context['result'];
                $result->incrementRequestCount();

                // 响应 Set-Cookie 只写入该任务自己的 Cookie 罐
                $context['jar']->extractCookies($req['request'], $response);

                // 每个响应创建隔离的解析上下文，绝不共用当前 QueryList 文档
                $pageQl = new QueryList();
                try {
                    $pageQl->setHtml((string)$response->getBody());
                    $records = self::parseRecords($pageQl, $context['task']);
                    $result->addRecords($records);

                    $nextUrl = self::resolveNextPage($pageQl, $response, $context['task'], $req);
                    if ($nextUrl !== null && ($maxPages === 0 || $req['page'] + 1 <= $maxPages)) {
                        // 分页请求沿该任务的 Cookie 罐与请求头继续发起
                        $queue[] = self::makeRequest($req['index'], $nextUrl, $req['page'] + 1, 1);
                    }
                } catch (Throwable $e) {
                    $result->setError($e);
                } finally {
                    $pageQl->destruct();
                    unset($pageQl);
                }
            };

            $onRejected = function ($reason, $key) use (
                &$wave, &$queue, $contexts, $maxRetries, &$retryScheduled
            ) {
                $req = $wave[$key];
                $context = $contexts[$req['index']];
                $task = $context['task'];
                $result = $context['result'];
                $result->incrementRequestCount();

                $retryable = strtoupper($task['method']) === 'GET' || $task['retry_post'];
                if ($retryable && $req['attempt'] <= $maxRetries) {
                    // 重试只重发请求，已产出的记录不会重复追加
                    $queue[] = self::makeRequest($req['index'], $req['url'], $req['page'], $req['attempt'] + 1);
                    $retryScheduled = true;
                    return;
                }
                $result->setError($reason instanceof Throwable ? $reason : new \RuntimeException((string)$reason));
            };

            Each::ofLimit($promises, $concurrency, $onFulfilled, $onRejected)->wait();

            if ($retryScheduled && $retryDelayMs > 0 && !empty($queue)) {
                usleep($retryDelayMs * 1000);
            }
        }

        // 按输入顺序返回
        $results = [];
        foreach ($tasks as $index => $task) {
            $results[] = $contexts[$index]['result'];
        }
        return $results;
    }

    /**
     * 剔除会破坏任务隔离的全局选项。
     */
    protected static function sanitizeClientOptions(array $options): array
    {
        unset($options['headers'], $options['cookies']);
        return $options;
    }

    protected static function normalizeTask(array $task): array
    {
        if (empty($task['url'])) {
            throw new \InvalidArgumentException('batchCollect: 每个任务必须包含 url');
        }
        return array_merge([
            'method' => 'GET',
            'args' => null,
            'headers' => [],
            'cookies' => [],
            'options' => [],
            'rules' => null,
            'range' => null,
            'parser' => null,
            'success' => null,
            'retry_post' => false,
        ], $task);
    }

    /**
     * 由任务配置构建独立的 Cookie 罐。
     */
    protected static function buildCookieJar($cookies): CookieJar
    {
        if ($cookies instanceof CookieJar) {
            // 克隆一份，避免调用方持有的罐被响应写脏、或被其他任务共享
            return clone $cookies;
        }
        $setCookies = [];
        foreach ((array)$cookies as $name => $cookie) {
            if ($cookie instanceof SetCookie) {
                $setCookies[] = $cookie;
            } elseif (is_array($cookie)) {
                $setCookies[] = new SetCookie($cookie);
            } elseif (is_string($cookie) && is_string($name) && !is_numeric($name)) {
                $setCookies[] = new SetCookie(['Name' => $name, 'Value' => $cookie]);
            } elseif (is_string($cookie)) {
                $setCookies[] = SetCookie::fromString($cookie);
            }
        }
        return new CookieJar(false, $setCookies);
    }

    protected static function makeRequest($index, string $url, int $page, int $attempt): array
    {
        return [
            'index' => $index,
            'url' => $url,
            'page' => $page,
            'attempt' => $attempt,
            'request' => null,
        ];
    }

    /**
     * 构建 PSR-7 请求与 Guzzle 请求选项。
     * Cookie 由任务自己的 Cookie 罐手动写入请求头，不经任何共享状态。
     *
     * @return array [Request, array $options]
     */
    protected static function buildRequest(Client $client, array $context, array $req, array $clientOptions): array
    {
        $task = $context['task'];
        $result = $context['result'];
        $method = strtoupper($task['method']);
        $url = $req['url'];
        $headers = $task['headers'];
        $body = null;

        if ($task['args'] !== null) {
            $args = $task['args'];
            is_string($args) && parse_str($args, $args);
            if ($method === 'GET') {
                $query = http_build_query($args, '', '&');
                if ($query !== '') {
                    $url .= (strpos($url, '?') === false ? '?' : '&') . $query;
                }
            } else {
                $body = http_build_query($args, '', '&');
                $headers += ['Content-Type' => 'application/x-www-form-urlencoded'];
            }
        }

        $request = new Request($method, $url, $headers, $body);
        // 只携带该任务 Cookie 罐中的 Cookie
        $request = $context['jar']->withCookieHeader($request);

        $userOnStats = $clientOptions['on_stats'] ?? null;
        $options = array_merge([
            'verify' => false,
            'http_errors' => true,
            // 跟踪重定向后的最终 URL
            'on_stats' => function (TransferStats $stats) use ($result, $userOnStats) {
                $result->setFinalUrl((string)$stats->getEffectiveUri());
                if (is_callable($userOnStats)) {
                    $userOnStats($stats);
                }
            },
        ], $clientOptions, $task['options']);
        // on_stats 需要合并而非被覆盖
        if (isset($clientOptions['on_stats']) || isset($task['options']['on_stats'])) {
            $taskOnStats = $task['options']['on_stats'] ?? null;
            $options['on_stats'] = function (TransferStats $stats) use ($result, $userOnStats, $taskOnStats) {
                $result->setFinalUrl((string)$stats->getEffectiveUri());
                is_callable($userOnStats) && $userOnStats($stats);
                is_callable($taskOnStats) && $taskOnStats($stats);
            };
        }
        // 任务级选项同样不允许破坏 Cookie/头隔离
        unset($options['cookies']);

        return [$request, $options];
    }

    /**
     * 用隔离的 QueryList 上下文解析当前页记录。
     */
    protected static function parseRecords(QueryList $pageQl, array $task): array
    {
        if ($task['parser'] instanceof Closure) {
            $records = call_user_func($task['parser'], $pageQl);
            return is_array($records) ? $records : [];
        }
        if (!empty($task['rules'])) {
            $pageQl->rules($task['rules']);
            $task['range'] && $pageQl->range($task['range']);
            $data = $pageQl->query()->getData()->all();
            // 有 range 时每行即一条记录；无 range 时整体为一条记录
            return $task['range'] ? $data : [$data];
        }
        return [];
    }

    /**
     * 调用分页回调，解析下一页地址（支持相对地址）。
     */
    protected static function resolveNextPage(QueryList $pageQl, Response $response, array $task, array $req): ?string
    {
        if (!($task['success'] instanceof Closure)) {
            return null;
        }
        $next = call_user_func($task['success'], $pageQl, $response, [
            'index' => $req['index'],
            'page' => $req['page'],
            'url' => $req['url'],
        ]);
        if (!is_string($next) || trim($next) === '') {
            return null;
        }
        // 相对地址基于当前页 URL 解析
        return (string)UriResolver::resolve(new Uri($req['url']), new Uri(trim($next)));
    }
}
