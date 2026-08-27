[English](README.md) | [Português](README.pt-BR.md) | [Deutsch](README.de.md) | [中文](README.zh-CN.md)

<div align="center">

**clicktrail/laravel**

将 Laravel 请求中观测到的获客上下文传递到配置的会话、Blade 和队列事件边界。

</div>

[![CI](https://github.com/vizuh/clicktrail-laravel/actions/workflows/ci.yml/badge.svg)](https://github.com/vizuh/clicktrail-laravel/actions/workflows/ci.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/clicktrail/laravel.svg)](https://packagist.org/packages/clicktrail/laravel)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

## 目录

- [为什么](#为什么)
- [安装](#安装)
- [快速上手](#快速上手)
- [Blade 输出](#blade-输出)
- [队列投递](#队列投递)
- [失败事件与重放](#失败事件与重放)
- [同意管理](#同意管理)
- [第一方代理](#第一方代理)
- [诊断](#诊断)
- [Webhook 签名](#webhook-签名)
- [差异对比](#差异对比)
- [测试](#测试)
- [许可协议](#许可协议)

## 为什么

此包不判断是哪次营销活动导致了线索或成交。它是 [clicktrail/php-sdk](https://github.com/vizuh/clicktrail-php) 之上的轻量适配层：SDK 负责 parse、classify、merge 和 serialize；Laravel 侧负责捕获中间件、经同意校验的会话持久化、队列投递、Blade 输出和 Artisan 诊断。

## 安装

```bash
composer require clicktrail/laravel
```

包自动发现会注册服务提供者和 `ClickTrail` 门面。发布配置文件，并在 `.env` 中填写 `CLICKTRAIL_SITE_ID` 与 `CLICKTRAIL_ENDPOINT`：

```bash
php artisan vendor:publish --tag=clicktrail-config
```

## 快速上手

`clicktrail()` 是唯一直观的入口（也存在 `ClickTrail` 门面）。

```php
// 1. 在需要构建 first/last-touch 状态的路由组上注册捕获（routes/web.php）：
Route::middleware(['web', 'clicktrail.capture'])->group(function () {
    Route::get('/', fn () => view('welcome'));
});

// 2. 访客从 Google Ads 到达；中间件完成触点合并。请求结束后：
session('clicktrail.attribution');
// JSON 中 first->source === 'google'、first->clickIds['gclid'] 已写入；
// 仅在同意策略允许 analytics storage 时持久化；未知 = 拒绝。

// 3. 在自己的代码里通过助手函数查看或合并：
$state = clicktrail()->capture($request);   // 本次请求的 StoredState
clicktrail()->pendingPayloads();            // []；队列中暂无内容

// 4. 转化发生时（表单提交、下单），派发投递任务：
\ClickTrail\Laravel\Jobs\DeliverEventsJob::dispatch();
// 以批次 POST 到 CLICKTRAIL_ENDPOINT，带幂等键；200ms/1s/5s 后重试；
// 请求本身期间不发送任何数据。
```

之后的直接访问不会改变任何东西；first touch 保持不变，已存储的 last touch 继续保留。这是 SDK 的合并法则：经过测试，而非口头承诺。

## Blade 输出

```blade
{{-- 根据配置渲染第一方加载器的 <script> 标签 --}}
@clicktrailHead

{{-- 表单内的隐藏归因字段，让服务端收到的提交原样携带
     source / 点击 ID --}}
@clicktrailAttribution

{{-- 规范化的同意快照，输出为 data-ct-consent-* 属性 --}}
<div @clicktrailConsent>...</div>

{{-- 或使用显式组件 --}}
<x-clicktrail::head />
<x-clicktrail::attribution-inputs />
```

## 队列投递

事件从不在请求期间发送。在你自己的触发点派发投递任务：

```php
\ClickTrail\Laravel\Jobs\DeliverEventsJob::dispatch();
// flush() 在遇到 429/5xx/网络错误时抛出 RetryableException；Laravel 按
// backoff([200ms, 1000ms, 5000ms]) 重跑该任务。PermanentException 则使任务
// 失败，并把 payload 写入失败事件表。
```

该任务基于 `BatchClient` 运行，因此派发前需将 PSR-18 客户端绑定到 `Psr\Http\Client\ClientInterface`，并绑定 PSR-17 的 request/stream 工厂。队列连接与队列名来自 `clicktrail.queue_connection` / `clicktrail.queue`。

## 失败事件与重放

所有重试耗尽后，payload 会按原样存入 `clicktrail_failed_events` 表（`clicktrail.persist_failed_events`，默认 `true`）。排查后再经同一助手函数重新入队：

```php
foreach (\ClickTrail\Laravel\Models\ClickTrailFailedEvent::get() as $row) {
    clicktrail()->restorePayloads(json_decode($row->payload, true));
}
// payload 回到 BatchClient 队列；下一次 DeliverEventsJob 原样发送；
// 幂等键相同，不会产生重复。
```

## 同意管理

ClickTrail 是同意数据的消费方，不是 CMP。用你的 CMP 适配器实现 `ClickTrail\Laravel\Consent\ConsentResolverInterface` 并绑定，或把其 FQCN 配置到 `clicktrail.consent_resolver`。在此之前，内置的 `NullConsentResolver` 返回未知快照，处处视为拒绝：不持久化任何标识符，也不投递任何事件。将 `clicktrail.consent_required` 设为 `false` 即声明该用途无需 CMP 门控。

## 第一方代理（可选）

设置 `CLICKTRAIL_FIRST_PARTY_PROXY=true` 后，提供者会注册 `POST /clicktrail/collect`。它对批量 payload 结构做最小校验，并经由你自己的基础设施重新排队投递。

## 诊断

```bash
php artisan clicktrail:diagnose
```

检查配置是否齐全、端点可达性（TCP 层标志）以及 consent resolver 的解析情况。

## Webhook 签名

使用 HMAC-SHA256 常量时间比较来验证 ClickTrail 的 webhook 回调：

```php
\ClickTrail\Laravel\Support\WebhookSignature::verify($payload, $request->header('X-ClickTrail-Signature'), $secret);
// 仅当签名匹配时 === true；常量时间比较，无时序泄露
```

## 差异对比

| 包 | 功能 | 边界 |
|---|---|---|
| **combindma/laravel-trail** | 将 UTM/referrer 存入 Cookie | ClickTrail 增加明确的首次/末次触点规则、由宿主解析的同意门控，以及规范事件的队列投递 |
| **DirectoryTree/Metrics** | 统计匿名事件 | 用途不同：ClickTrail 传递获客上下文；此包不提供分析面板或营收归因 |

完整分析见 `../docs/COMPETITOR-NOTES.md`。

## 测试

```bash
php tests/_runner.php                 # 完整套件，独立运行（无需启动内核）
vendor/bin/phpunit --testdox          # PHPUnit 阶段（CI，PHP 8.3）
```

CI 对所有文件做 lint，并在 PHP 8.1–8.3 上运行两个阶段（`.github/workflows/ci.yml`）。

## 许可协议

MIT; Copyright (c) 2026 Vizuh OÜ
