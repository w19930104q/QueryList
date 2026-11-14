<?php
/**
 * batchCollect 本地 HTTP 回归测试。
 *
 * 使用 PHP 内置服务器（tests/server/router.php）验证：
 * 并发交错与上限、跨站 Cookie/请求头隔离、分页续接、
 * 部分失败不中断、重试不重复产出、重跑顺序一致。
 */

namespace Tests\Feature;

use QL\QueryList;
use QL\Services\Batch\BatchResult;
use Tests\TestCaseBase;

class BatchCollectTest extends TestCaseBase
{
    protected static $proc;
    protected static $pipes;
    protected static $port = 18925;
    protected static $base;

    public static function setUpBeforeClass(): void
    {
        self::$base = 'http://127.0.0.1:' . self::$port;
        self::cleanState();
        $docRoot = dirname(__DIR__) . '/server';
        $cmd = sprintf(
            'PHP_CLI_SERVER_WORKERS=8 %s -S 127.0.0.1:%d -t %s %s/router.php',
            PHP_BINARY,
            self::$port,
            escapeshellarg($docRoot),
            escapeshellarg($docRoot)
        );
        self::$proc = proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['file', sys_get_temp_dir() . '/ql_batch_test.out', 'a'],
            2 => ['file', sys_get_temp_dir() . '/ql_batch_test.err', 'a'],
        ], self::$pipes);

        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $fp = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.2);
            if ($fp !== false) {
                fclose($fp);
                return;
            }
            usleep(100000);
        }
        self::fail('测试服务器启动失败');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$proc)) {
            proc_terminate(self::$proc);
            proc_close(self::$proc);
        }
        self::cleanState();
    }

    protected static function cleanState(): void
    {
        $dir = sys_get_temp_dir() . '/ql_batch_server_state';
        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
    }

    protected function setUp(): void
    {
        self::cleanState();
    }

    /**
     * @test
     * 并发交错：峰值不超过上限，且确实发生了并行
     */
    public function concurrency_limit_is_respected()
    {
        $tasks = [];
        for ($i = 0; $i < 10; $i++) {
            $tasks[] = ['url' => self::$base . '/slow?i=' . $i];
        }
        $results = QueryList::batchCollect($tasks, ['concurrency' => 3]);
        $this->assertCount(10, $results);
        foreach ($results as $r) {
            $this->assertInstanceOf(BatchResult::class, $r);
            $this->assertTrue($r->isSuccess());
        }
        $state = json_decode(file_get_contents(self::$base . '/state'), true);
        $this->assertLessThanOrEqual(3, $state['slow_max'], '服务端观测峰值超过并发上限');
        $this->assertGreaterThan(1, $state['slow_max'], '请求没有发生交错');
    }

    /**
     * @test
     * 跨站 Cookie / 请求头隔离：后继分页请求只继承本任务的 Cookie 与头
     */
    public function cookies_and_headers_are_isolated_per_task()
    {
        $makeTask = function (string $site, string $token, string $nextSelector) {
            return [
                'url' => self::$base . '/' . $site . '/products?page=1',
                'headers' => ['X-Supplier-Token' => $token],
                'parser' => function (QueryList $ql) {
                    return [[
                        'token' => $ql->find('meta[name="token"]')->attr('content'),
                        'cookie' => $ql->find('meta[name="cookie"]')->attr('content'),
                    ]];
                },
                'success' => function (QueryList $ql) use ($nextSelector) {
                    return $ql->find($nextSelector)->attr('href') ?: null;
                },
            ];
        };
        $results = QueryList::batchCollect([
            $makeTask('siteA', 'token-A', '.next'),
            $makeTask('siteB', 'token-B', 'a[rel="nextpage"]'),
        ]);

        $aRows = $results[0]->getRecords();
        $bRows = $results[1]->getRecords();
        $this->assertSame('token-A', $aRows[0]['token']);
        $this->assertSame('token-B', $bRows[0]['token']);
        // 第 2 页请求继承了本站的 Cookie
        $this->assertSame('session-A', $aRows[1]['cookie']);
        $this->assertSame('session-B', $bRows[1]['cookie']);
        // 无跨站泄漏
        $this->assertStringNotContainsString('session-B', implode('', array_column($aRows, 'cookie')));
        $this->assertStringNotContainsString('session-A', implode('', array_column($bRows, 'cookie')));
    }

    /**
     * @test
     * 分页续接：相对地址解析、记录按页序累积、最终 URL 与请求计数
     */
    public function pagination_continues_and_tracks_final_url()
    {
        $results = QueryList::batchCollect([
            [
                'url' => self::$base . '/siteA/products?page=1',
                'rules' => ['name' => ['.name', 'text'], 'price' => ['.price', 'text']],
                'range' => '.product',
                'success' => function (QueryList $ql) {
                    return $ql->find('.next')->attr('href') ?: null;
                },
            ],
        ]);
        $names = array_column($results[0]->getRecords(), 'name');
        $this->assertSame(['A-p1', 'A-p2', 'A-p3', 'A-p4', 'A-p5', 'A-p6'], $names);
        $this->assertSame(self::$base . '/siteA/products?page=3', $results[0]->getFinalUrl());
        $this->assertSame(3, $results[0]->getRequestCount());
    }

    /**
     * @test
     * 重定向后最终 URL 指向目标页
     */
    public function final_url_follows_redirects()
    {
        $results = QueryList::batchCollect([
            [
                'url' => self::$base . '/redirect-entry',
                'rules' => ['name' => ['.name', 'text']],
                'range' => '.product',
            ],
        ]);
        $this->assertTrue($results[0]->isSuccess());
        $this->assertSame(self::$base . '/redirect-target', $results[0]->getFinalUrl());
        $this->assertSame('R-p1', $results[0]->getRecords()[0]['name']);
    }

    /**
     * @test
     * 部分失败：单站失败不中断其他任务，结果仍按输入顺序返回
     */
    public function partial_failure_does_not_interrupt_others()
    {
        $results = QueryList::batchCollect([
            ['url' => self::$base . '/boom'],
            [
                'url' => self::$base . '/siteB/products?page=1',
                'rules' => ['title' => ['h3', 'text']],
                'range' => '.item',
            ],
            ['url' => self::$base . '/boom'],
        ]);
        $this->assertCount(3, $results);
        $this->assertTrue($results[0]->isFailed());
        $this->assertTrue($results[2]->isFailed());
        $this->assertSame(500, $results[0]->getError()->getCode());
        $this->assertTrue($results[1]->isSuccess());
        $this->assertCount(2, $results[1]->getRecords());
        $this->assertSame([0, 1, 2], array_map(function (BatchResult $r) {
            return $r->getIndex();
        }, $results));
    }

    /**
     * @test
     * 重试：失败后重发请求，成功页的记录只产出一次
     */
    public function retries_do_not_duplicate_records()
    {
        $token = 'phpunit-' . uniqid();
        $results = QueryList::batchCollect([
            [
                'url' => self::$base . '/flaky?token=' . $token,
                'rules' => ['name' => ['.name', 'text']],
                'range' => '.product',
            ],
        ], ['retries' => 3, 'retry_delay_ms' => 10]);
        $this->assertTrue($results[0]->isSuccess());
        $this->assertSame(3, $results[0]->getRequestCount());
        $this->assertSame([['name' => 'F-p1']], $results[0]->getRecords());

        // 重试额度耗尽后标记失败，且不产出记录
        $token2 = 'phpunit-exhaust-' . uniqid();
        $results2 = QueryList::batchCollect([
            ['url' => self::$base . '/flaky?token=' . $token2],
        ], ['retries' => 1, 'retry_delay_ms' => 10]);
        $this->assertTrue($results2[0]->isFailed());
        $this->assertSame(2, $results2[0]->getRequestCount());
        $this->assertSame([], $results2[0]->getRecords());
    }

    /**
     * @test
     * 重跑：相同输入两次运行结果一致，顺序与输入一致
     */
    public function rerun_produces_identical_ordered_results()
    {
        $taskSet = [
            ['url' => self::$base . '/siteA/products?page=1', 'rules' => ['name' => ['.name', 'text']], 'range' => '.product'],
            ['url' => self::$base . '/boom'],
            ['url' => self::$base . '/siteB/products?page=1', 'rules' => ['title' => ['h3', 'text']], 'range' => '.item'],
        ];
        $snapshot = function (BatchResult $r) {
            return [
                $r->getIndex(), $r->getUrl(), $r->getRecords(), $r->getFinalUrl(),
                $r->isFailed(), $r->isFailed() ? $r->getError()->getCode() : null,
                $r->getRequestCount(),
            ];
        };
        $run1 = QueryList::batchCollect($taskSet, ['concurrency' => 2]);
        $run2 = QueryList::batchCollect($taskSet, ['concurrency' => 2]);
        $this->assertSame(array_map($snapshot, $run1), array_map($snapshot, $run2));
        $this->assertSame([0, 1, 2], array_map(function (BatchResult $r) {
            return $r->getIndex();
        }, $run1));
    }

    /**
     * @test
     * 防护：无限分页被 max_pages 截断
     */
    public function max_pages_caps_endless_pagination()
    {
        $results = QueryList::batchCollect([
            [
                'url' => self::$base . '/endless?page=1',
                'rules' => ['name' => ['.name', 'text']],
                'range' => '.product',
                'success' => function (QueryList $ql) {
                    return $ql->find('.next')->attr('href');
                },
            ],
        ], ['max_pages' => 4]);
        $this->assertTrue($results[0]->isSuccess());
        $this->assertSame(4, $results[0]->getRequestCount());
        $this->assertCount(4, $results[0]->getRecords());
    }
}
