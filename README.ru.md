# rasuvaeff/yii3-filestorage-db

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/yii3-filestorage-db/v)](https://packagist.org/packages/rasuvaeff/yii3-filestorage-db)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/yii3-filestorage-db/downloads)](https://packagist.org/packages/rasuvaeff/yii3-filestorage-db)
[![Build](https://github.com/rasuvaeff/yii3-filestorage-db/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/yii3-filestorage-db/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/yii3-filestorage-db/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/yii3-filestorage-db/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/yii3-filestorage-db/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/yii3-filestorage-db/php)](https://packagist.org/packages/rasuvaeff/yii3-filestorage-db)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)
[English version](README.md)

Половина семейства [`rasuvaeff/yii3-filestorage`](https://github.com/rasuvaeff/yii3-filestorage),
отвечающая за метаданные: записи о файлах в таблице БД, опциональный тенантный
scope, который нельзя отключить ни из одного кода, и реестр, делающий удаление
дедуплицированных байт безопасным.

> Используете AI-ассистента? [llms.txt](llms.txt) — компактный справочник по API, который можно отдать модели.
> Проекты с Composer-плагином [llm/skills](https://github.com/roxblnfk/skills) получают skill пакета в `.agents/skills/` автоматически при установке.

**Статус: `0.x`.** API ещё может измениться, пока против него пишутся пакеты
Flysystem и web.

## Требования

- PHP 8.3+
- `ext-json`
- `rasuvaeff/yii3-filestorage` ^0.1
- `yiisoft/db` ^2.0 (плюс драйвер: `yiisoft/db-sqlite`, `-mysql`, `-pgsql`, …)
- `yiisoft/db-migration` ^2.1 — **не** `^2.0`, см. [Миграции](#миграции)

## Установка

```bash
composer require rasuvaeff/yii3-filestorage-db
```

Пакет биндит `RepositoryInterface`, `MaintenanceRepositoryInterface`,
`ScopedFileResolverInterface` и `BlobLedgerInterface`. Фасад биндит ядро.
`StoreInterface` вы биндите сами — локальный `FileSystemStore` или
`rasuvaeff/yii3-filestorage-flysystem` для S3 и подобных.

## Миграции

Регистрация по namespace:

```php
// config/common/di/db-migration.php
use Yiisoft\Db\Migration\Service\MigrationService;

return [
    MigrationService::class => [
        'setSourceNamespaces()' => [['Rasuvaeff\\Yii3FilestorageDb\\Migration']],
    ],
];
```

```bash
./yii migrate:up
```

Три таблицы: `filestorage_file`, `filestorage_blob` и
`filestorage_blob_reservation`.

> **`yiisoft/db-migration` ^2.1 — жёсткое требование.** На 2.0.x ровно эта
> регистрация молча не находила ничего: резолв namespace принимал *соседний*
> namespace за родительский, попадал в каталог пакета-ядра, а `migrate:up`
> печатал «up-to-date», возвращал 0 и не создавал ни одной таблицы. В 2.1.0
> фикс есть ([#350](https://github.com/yiisoft/db-migration/pull/350)), и
> интеграционный набор этого пакета прогоняет рецепт выше на каждой сборке CI,
> а не верит ему на слово.

## Конфигурация

```php
// config/common/params.php
return [
    'rasuvaeff/yii3-filestorage-db' => [
        'fileTable' => 'filestorage_file',
        'blobTable' => 'filestorage_blob',
        'blobReservationTable' => 'filestorage_blob_reservation',
        'tablePrefix' => '',
    ],
];
```

Префикс подставляется ко всем трём именам. Имена валидируются типизированными
VO — `FileTableName`, `BlobTableName`, `BlobReservationTableName`, — которые
миграции резолвят из контейнера, поэтому схема и запросы не могут разойтись в
имени.

## Тенантный scope

Забиндите `FileScopeProviderInterface` — и каждый запрос пакета получит
предикат по `scope_id`. Не биндите — установка однотенантная: ни предиката, ни
накладных расходов.

```php
// config/common/di/filestorage.php
use Rasuvaeff\Yii3Filestorage\Repository\FileScopeProviderInterface;

return [
    FileScopeProviderInterface::class => static fn (TenantContext $tenants): FileScopeProviderInterface
        => new class ($tenants) implements FileScopeProviderInterface {
            public function __construct(private TenantContext $tenants) {}

            public function currentScopeId(): ?string
            {
                return $this->tenants->currentId();
            }
        },
];
```

Что такое тенант, знает только ваше приложение — `rasuvaeff/yii3-tenancy`,
сессия, поддомен, — поэтому пакет не может забиндить это за вас, не ошибившись
в каждой установке, где сделано иначе.

**Метода, обходящего предикат, нет.** Ни для скачивания, ни для обслуживания. У
подписанной загрузки нет окружающего тенанта — в этом и смысл подписи, — а
соблазнительный обходной путь (поиск по id с отключённым фильтром) читает любой
файл, чей id утёк. Вместо этого — `ScopedFileResolverInterface`: scope был
аутентифицирован при выпуске токена, едет внутри HMAC и сверяется вторым
предикатом.

```php
$file = $resolver->findInScope($payload->fileId, $payload->scopeId);
```

`null` — это scope, а не его отсутствие: он матчится в `scope_id IS NULL`,
именно это лежит в строках приложения без тенантов. Токен без scope никогда не
становится wildcard.

## Дедупликация

Двое загрузили один и тот же файл. Один объект на диске, две строки в БД — и
объект не должен исчезнуть, когда исчезнет первая строка.
`BlobLedgerInterface` координирует это; данный пакет — транзакционная
реализация.

```php
$blob = BlobId::create('upload', $contentAddressedPath);

$reservation = $ledger->reserve($blob, $sha256, $size, $expiresAt);  // занять ключ
$store->putIfAbsent($upload, $blob->object);                          // опубликовать байты
$ledger->commit($reservation, $file);                                 // строка + ссылка, одна транзакция
```

При любом сбое между шагами — `release($reservation, $deleteAfter)`. Удаление —
`releaseFile($fileId, $deleteAfter)`.

| Правило | Почему |
|---|---|
| Разделяемые байты **никогда** не удаляются внутри запроса | Удалённый в запросе объект — это тот, который какой-то параллельный add уже решил переиспользовать. Последнее освобождение только помечает blob как `pending_delete` |
| Удаляет только сборщик и только под lease | Эксклюзивный и истекающий, поэтому воркер, умерший в середине удаления, восстанавливается следующим, перехватившим lease, а не блокирует навсегда |
| Каждая проверка — в том же операторе, который действует | `SELECT`, а потом `UPDATE` — это два момента с зазором, и в зазоре закоммиченная строка начинает указывать на удалённые байты |
| Владение — это `BlobId`, а не хеш содержимого | Хеш одинаков для разных хранилищ, групп и тенантов, поэтому счётчик по хешу позволяет одному тенанту удалить то, что читает другой |
| Колонки `reference_count` нет | Ссылка — это и есть строка файла с этим `blob_id`, а проверки спрашивают `NOT EXISTS`. Счётчик — второй источник истины, который может разойтись с первым, и любая защита от underflow ловит расхождение уже постфактум |

Состояния: `writing`, `active`, `pending_delete`, `deleting`. Писатель может
присоединиться к любому, кроме последнего — там он получает
`BlobBusyException` и повторяет после истечения lease.

Проход сборки:

```php
$ledger->expireReservations($now, $deleteAfter);         // сначала смести брошенных писателей

while ($lease = $ledger->claimForDeletion($now, $now->add($leaseTtl))) {
    try {
        $store->deleteObject($lease->blob->object);
        $ledger->completeDeletion($lease);               // откажет, если blob ожил
    } catch (StoreException) {
        $ledger->abandonDeletion($lease, $retryAfter);   // backoff, строка остаётся
    }
}
```

`DeduplicatingStorage` — фасад, исполняющий этот протокол за вас, — приедет
вместе с операционными командами. До этого контракт, против которого пишут, —
сам реестр.

## Обслуживание

`DbRepository` реализует `MaintenanceRepositoryInterface`, поэтому долгая
задача идёт страницами по id и продолжается с места остановки:

```php
$afterId = null;
do {
    $page = iterator_to_array($repository->files($afterId, limit: 500), false);
    foreach ($page as $file) {
        // …
        $afterId = $file->id;
    }
} while ($page !== []);
```

Курсор сохраняет тенантный предикат.

## Формат хранения

| Решение | Почему |
|---|---|
| Метки времени — текст `Y-m-d\TH:i:s.uP`, нормализованный в UTC | Микросекунды сохраняются (обрезающая колонка сломала бы round-trip-контракт `File`), а реестр сравнивает дедлайны как строки — это совпадает с хронологическим порядком только если у всех строк одинаковое смещение |
| `metadata` — JSON в текстовой колонке | Форма — плоский `array<string, scalar\|null>`, внутрь никто не запрашивает, так что нативная JSON-колонка ничего не дала бы и стоила бы переносимости |
| `save()` — scoped-update с фолбэком в insert, а не upsert | Upsert матчится только по primary key, поэтому чужая строка была бы молча перехвачена. Id неугадываемы, но «неугадываемо» — не граница |
| Битая строка — исключение, а не приведённый `File` | `InvalidFileRowException`. Приводить значения, пока `File::create()` не перестанет ругаться, — способ превратить повреждённую строку в правдоподобную запись, указывающую не на тот объект |
| Primary key блоба — `sha256(store:path)` | Сама пара превышает лимит длины индексного ключа MySQL при пути в 512 символов, а производный ключ заставляет двух писателей, гоняющихся за одним блобом, столкнуться на primary key вместо создания двух строк на один объект |

## Примеры

Исполняемые, самодостаточные, сервер не нужен — см. [`examples/`](examples/).

## Разработка

PHP и Composer на хосте нет — всё через Docker.

```bash
make build            # validate, normalize, require-checker, cs, psalm, test
make test-integration # документированный рецепт миграций, исполняемый
make cs-fix
make mutation
make release-check
```

## Лицензия

BSD-3-Clause. См. [LICENSE.md](LICENSE.md).
