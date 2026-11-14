<?php
/**
 * QueryList
 *
 * 批量采集（batchCollect）单个输入任务的结果对象。
 *
 * @author Jaeger
 * @email  JaegerCode@gmail.com
 */

namespace QL\Services\Batch;

use JsonSerializable;
use Throwable;

/**
 * Class BatchResult
 *
 * 一个输入任务（一个供应商目录）的采集结果：
 * 记录列表、最终 URL、错误信息与请求计数。
 *
 * @package QL\Services\Batch
 */
class BatchResult implements JsonSerializable
{
    /**
     * @var int|string 输入数组中的原始键
     */
    protected $index;

    /**
     * @var string 任务的入口 URL
     */
    protected $url;

    /**
     * @var string|null 最后一次请求的 URL（跟随重定向与分页之后）
     */
    protected $finalUrl;

    /**
     * @var array 所有页面按顺序累积的记录
     */
    protected $records = [];

    /**
     * @var Throwable|null 任务失败原因，成功时为 null
     */
    protected $error;

    /**
     * @var int 实际发出的 HTTP 请求数（含重试，重定向跟随计 1 次）
     */
    protected $requestCount = 0;

    public function __construct($index, string $url)
    {
        $this->index = $index;
        $this->url = $url;
    }

    /**
     * 输入数组中的原始键
     * @return int|string
     */
    public function getIndex()
    {
        return $this->index;
    }

    /**
     * 任务的入口 URL
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * 最终 URL：最后一次请求的地址（含重定向目标与分页地址）。
     * 任务一次请求都未发出时为入口 URL。
     */
    public function getFinalUrl(): ?string
    {
        return $this->finalUrl ?? $this->url;
    }

    /**
     * 采集到的记录（全部页面按顺序合并）
     */
    public function getRecords(): array
    {
        return $this->records;
    }

    /**
     * 任务是否失败
     */
    public function isFailed(): bool
    {
        return $this->error !== null;
    }

    /**
     * 任务是否成功
     */
    public function isSuccess(): bool
    {
        return $this->error === null;
    }

    /**
     * 失败原因（HTTP 错误为 GuzzleHttp 异常，其 code 为 HTTP 状态码）
     */
    public function getError(): ?Throwable
    {
        return $this->error;
    }

    /**
     * 实际发出的 HTTP 请求数（含重试次数）
     */
    public function getRequestCount(): int
    {
        return $this->requestCount;
    }

    /**
     * @internal 由 BatchCollectService 调用：追加一页解析出的记录
     */
    public function addRecords(array $records): void
    {
        foreach ($records as $record) {
            $this->records[] = $record;
        }
    }

    /**
     * @internal 由 BatchCollectService 调用：记录最终 URL
     */
    public function setFinalUrl(?string $finalUrl): void
    {
        $this->finalUrl = $finalUrl;
    }

    /**
     * @internal 由 BatchCollectService 调用：标记任务失败
     */
    public function setError(Throwable $error): void
    {
        $this->error = $error;
    }

    /**
     * @internal 由 BatchCollectService 调用：累计请求次数
     */
    public function incrementRequestCount(): void
    {
        $this->requestCount++;
    }

    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'url' => $this->url,
            'final_url' => $this->getFinalUrl(),
            'records' => $this->records,
            'error' => $this->error ? (string)$this->error : null,
            'request_count' => $this->requestCount,
        ];
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->toArray();
    }
}
