[English](README.md) | [Português](README.pt-BR.md) | [Deutsch](README.de.md) | [中文](README.zh-CN.md)

<div align="center">

**clicktrail/laravel**

Veja qual campanha, palavra-chave, click ID e página de destino gerou cada envio de formulário e conversão — em qualquer app Laravel 10/11.

</div>

[![CI](https://github.com/vizuh/clicktrail-laravel/actions/workflows/ci.yml/badge.svg)](https://github.com/vizuh/clicktrail-laravel/actions/workflows/ci.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/clicktrail/laravel.svg)](https://packagist.org/packages/clicktrail/laravel)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

## Índice

- [Por quê](#por-quê)
- [Instalação](#instalação)
- [Início rápido](#início-rápido)
- [Saída Blade](#saída-blade)
- [Entrega em fila](#entrega-em-fila)
- [Eventos com falha e replay](#eventos-com-falha-e-replay)
- [Consentimento](#consentimento)
- [Proxy first-party](#proxy-first-party)
- [Diagnóstico](#diagnóstico)
- [Assinaturas de webhook](#assinaturas-de-webhook)
- [Como se diferencia](#como-se-diferencia)
- [Testes](#testes)
- [Licença](#licença)

## Por quê

A maioria dos pacotes de tracking guarda o que a página mostrou. O ClickTrail prova qual campanha criou o lead ou a venda. Este pacote é um adaptador fino sobre o [clicktrail/php-sdk](https://github.com/vizuh/clicktrail-php): o SDK cuida de parse/classify/merge/serialize; o Laravel cuida do middleware de captura, persistência em sessão gated por consentimento, entrega em fila, saída Blade e diagnóstico via artisan.

## Instalação

```bash
composer require clicktrail/laravel
```

O auto-discovery registra o service provider e a facade `ClickTrail`. Publique a config e preencha `CLICKTRAIL_SITE_ID` e `CLICKTRAIL_ENDPOINT` no `.env`:

```bash
php artisan vendor:publish --tag=clicktrail-config
```

## Início rápido

`clicktrail()` é o ponto de entrada único e óbvio (a facade `ClickTrail` também existe).

```php
// 1. Registre a captura nos grupos de rotas cujo tráfego deve construir
//    estado de first/last-touch (routes/web.php):
Route::middleware(['web', 'clicktrail.capture'])->group(function () {
    Route::get('/', fn () => view('welcome'));
});

// 2. Um visitante chega do Google Ads; o middleware faz o merge do touch.
//    Depois da request:
session('clicktrail.attribution');
// JSON com first->source === 'google', first->clickIds['gclid'] preenchido —
// persistido SOMENTE quando o consentimento permite analytics storage;
// desconhecido = negado.

// 3. Inspecione ou faça merge do seu próprio código pelo helper:
$state = clicktrail()->capture($request);   // StoredState desta request
clicktrail()->pendingPayloads();            // [] — nada na fila ainda

// 4. Na conversão (submit de formulário, pedido), despache a entrega:
\ClickTrail\Laravel\Jobs\DeliverEventsJob::dispatch(clicktrail()->pendingPayloads());
// POST em lote para CLICKTRAIL_ENDPOINT com idempotency keys; retries após
// 200ms/1s/5s; nada é enviado durante a própria request.
```

Uma visita direta depois não muda nada — o first touch permanece, o last touch armazenado persiste. Essa é a merge law do SDK: testada, não prometida.

## Saída Blade

```blade
{{-- renderiza a tag <script> do loader first-party a partir da config --}}
@clicktrailHead

{{-- inputs ocultos de atribuição dentro de um <form>, para que o submit
     no servidor carregue source / click IDs verbatim --}}
@clicktrailAttribution

{{-- snapshot de consentimento normalizado como atributos data-ct-consent-* --}}
<div @clicktrailConsent>...</div>

{{-- ou como componentes explícitos --}}
<x-clicktrail::head />
<x-clicktrail::attribution-inputs />
```

## Entrega em fila

Os eventos nunca são enviados durante a request. Despache o job de entrega a partir dos seus próprios gatilhos:

```php
\ClickTrail\Laravel\Jobs\DeliverEventsJob::dispatch(clicktrail()->pendingPayloads());
// flush() lança RetryableException em 429/5xx/rede — o Laravel reexecuta o
// job via backoff([200ms, 1000ms, 5000ms]). Uma PermanentException falha o
// job e manda os payloads para a tabela de eventos com falha.
```

O job usa o `BatchClient`, então faça bind de um client PSR-18 como `Psr\Http\Client\ClientInterface` mais as factories PSR-17 de request/stream antes de despachar. Conexão de fila e nome da fila vêm de `clicktrail.queue_connection` / `clicktrail.queue`.

## Eventos com falha e replay

Depois de esgotadas as retries, os payloads são guardados verbatim na tabela `clicktrail_failed_events` (`clicktrail.persist_failed_events`, padrão `true`). Diagnostique e depois replique pelo mesmo helper:

```php
foreach (\ClickTrail\Laravel\Models\ClickTrailFailedEvent::get() as $row) {
    clicktrail()->restorePayloads(json_decode($row->payload, true));
}
\ClickTrail\Laravel\Jobs\DeliverEventsJob::dispatch(clicktrail()->pendingPayloads());
// Os payloads são enviados sem alteração, com as mesmas idempotency keys.
```

## Consentimento

O ClickTrail é consumidor de consentimento, não um CMP. Implemente `ClickTrail\Laravel\Consent\ConsentResolverInterface` com o adapter do seu CMP e faça o bind, ou defina `clicktrail.consent_resolver` com o FQCN dele. Até lá, o `NullConsentResolver` embarcado devolve um snapshot desconhecido, tratado como negado em toda parte: nenhum identificador é persistido e nenhum evento é entregue. Definir `clicktrail.consent_required` como `false` declara que este uso dispensa gating por CMP.

## Proxy first-party (opcional)

Com `CLICKTRAIL_FIRST_PARTY_PROXY=true`, o provider registra `POST /clicktrail/collect`. Ele valida minimamente o formato do payload em lote e reenfileira a entrega pela sua própria infraestrutura.

## Diagnóstico

```bash
php artisan clicktrail:diagnose
```

Verifica presença da config, alcançabilidade do endpoint (flag em nível TCP) e resolução do consent resolver.

## Assinaturas de webhook

Verifique callbacks de webhook do ClickTrail com comparação HMAC-SHA256 em tempo constante:

```php
\ClickTrail\Laravel\Support\WebhookSignature::verify($payload, $request->header('X-ClickTrail-Signature'), $secret);
// === true somente quando a assinatura bate; tempo constante, sem timing leak
```

## Como se diferencia

| Pacote | O que faz | Fronteira |
|---|---|---|
| **combindma/laravel-trail** | Guarda UTMs/referrers em cookies | O ClickTrail prova qual campanha criou o lead ou a venda: leis determinísticas de merge first/last-touch validadas por golden fixtures compartilhadas com nossos engines WordPress e GTM, persistência gated por consentimento, entrega em lote com idempotency keys |
| **DirectoryTree/Metrics** | Conta eventos anônimos | Complementar — o ClickTrail conecta campanhas a identidades e receita |

Veja `../docs/COMPETITOR-NOTES.md` para a análise completa.

## Testes

```bash
php tests/_runner.php                 # suite completa, standalone (sem boot de kernel)
vendor/bin/phpunit --testdox          # etapa PHPUnit (CI, PHP 8.3)
```

O CI faz lint de todos os arquivos e roda as duas etapas no PHP 8.1–8.3 (`.github/workflows/ci.yml`).

## Licença

MIT — Copyright (c) 2026 Vizuh OÜ
