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
- `symfony/console` ^6.4 || ^7.0 || ^8.0, `psr/http-factory` ^1.0 и
  `psr/http-message` ^2.0 — для `filestorage:deduplicate`, которая читает
  объекты обратно через хранилище
- `yiisoft/db-migration` ^2.1 — **не** `^2.0`, см. [Миграции](#миграции)

## Установка

```bash
composer require rasuvaeff/yii3-filestorage-db
```

Пакет биндит `RepositoryInterface`, `MaintenanceRepositoryInterface`,
`ScopedFileResolverInterface` и `BlobLedgerInterface`. Фасад биндит ядро —
этот пакет его не трогает.
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

### Как включить

Протокол выше исполняет за вас `DeduplicatingStorage`. Пакет его **не** биндит:
замена `StorageInterface` — решение корневого приложения, потому что этим
ключом владеет ядро, а два vendor-пакета на один ключ — это `Duplicate key`
`yiisoft/config` by design. Поэтому включает приложение:

```php
// config/common/di/filestorage.php
use Rasuvaeff\Yii3Filestorage\StorageInterface;
use Rasuvaeff\Yii3FilestorageDb\DedupScope;
use Rasuvaeff\Yii3FilestorageDb\DeduplicatingStorageFactory;

return [
    StorageInterface::class => static fn (
        DeduplicatingStorageFactory $factory,
    ): StorageInterface => $factory->create(
        scope: DedupScope::TenantGroup,
        dedupMaxBytes: 104_857_600,
        deleteGracePeriod: new DateInterval('PT1H'),
    ),
];
```

Это переопределение направляет `StorageInterface` на фасад, который *строит*
фабрика, — значит, сама фабрика не может запрашивать `StorageInterface`, чтобы
получить обычный: ей вернули бы объект, который она в этот момент и собирает.
Она запрашивает у ядра `Storage::class` — конкретный идентификатор, который ядро
биндит рядом с интерфейсом именно ради этого. Настраивать нечего; ровно поэтому
пакет требует ядро `^0.1.1`, и то же правило действует для любого декоратора,
который вы напишете сами.

`create()` отклоняет три конфигурации, которые иначе тихо теряют данные, и
говорит, что делать вместо: хранилище без `ContentAddressableStoreInterface`;
реализацию `BlobLedgerInterface`, отличную от `DbBlobLedger` этого пакета, —
гарантии дедупликации держатся на SQL-предикатах внутри действующих операторов,
и реализация, хранящая состояние где-то ещё, того же обещать не может; и реестр
на соединении, отличном от репозитория, — из-за чего `commit()` становится двумя
транзакциями вместо одной, и падение между ними оставляет строку без ссылки либо
ссылку без строки.

Для потребителя не меняется ничего. `add()` по-прежнему возвращает `File` со
своим id, группой, описанием и метаданными; просто две загрузки одинаковых байт
указывают на один объект. Выше `dedupMaxBytes` загрузка молча уходит на
уникальный путь: ключ содержимого *и есть* хеш, его нельзя выбрать, не прочитав
тело целиком, а такое второе чтение того не стоит.

### Насколько широко разделяются байты

`DedupScope` — это выбор по безопасности, а не регулятор «место/CPU»:

| Scope | Пул | Когда |
|---|---|---|
| `TenantGroup` *(по умолчанию)* | на тенанта и группу | Всегда безопасно. Единственный подходящий для недоверенных тенантов |
| `Tenant` | на тенанта | Группы одного тенанта делятся между собой, тенанты — нет |
| `Global` | всё вместе | Экономит больше всего и **раскрывает факт существования контента между тенантами** |

Раскрытие реальное: загружающий узнаёт по времени записи и по не сдвинувшейся
квоте, существовало ли ровно это содержимое раньше. Между собственными файлами
тенанта это не говорит ничего нового. Между тенантами — это оракул: загрузи
подозреваемый документ и посмотри, был ли он «уже здесь».

### Включение на уже существующих данных

`filestorage:backfill-hash` (ядро) считает хэши целостности и на этом
останавливается — она сознательно не делает вид, что существующие случайные пути
стали общими. Переносит их `filestorage:deduplicate` из этого пакета:

```bash
./yii filestorage:deduplicate                      # отчёт, ничего не меняет
./yii filestorage:deduplicate --apply --limit=500
./yii filestorage:deduplicate --apply --limit=500 --after=<последний напечатанный id>
./yii filestorage:gc --orphans --apply             # забрать то, на что строки указывали раньше
```

| Опция | По умолчанию | Примечания |
|---|---|---|
| `--apply` | выкл | Без неё ничего не пишется |
| `--scope` | `tenant-group` | **Обязан совпадать** с тем, что приложение передаёт в `create()`. Расхождение уводит каждую перенесённую строку на ключ, к которому ни одна будущая загрузка не присоединится |
| `--store` | хранилище по умолчанию | Должно реализовывать `ContentAddressableStoreInterface`, иначе команда откажется до первого чтения |
| `--after` / `--limit` | — / 1000 | Курсор и размер пачки. Команда печатает последний достигнутый id |
| `--max-bytes` | 104857600 | Строки крупнее сохраняют свой уникальный объект — ровно как поступил бы живой `add()` |

На каждую строку: прочитать объект, посчитать хэш, зарезервировать
content-ключ, опубликовать через `putIfAbsent()` и перенаправить строку через
`commit()` — тот же протокол, что и у живого `add()`, поэтому падение в любой
точке оставляет либо старую строку, либо новую, но не строку в никуда. Повторный
прогон по тому же диапазону — no-op: строка, уже стоящая на своём
content-ключе, распознаётся и пропускается.

**Старый объект остаётся на месте намеренно.** Он становится orphan в момент
перенаправления строки, и `filestorage:gc --orphans --apply` забирает его, когда
незавершённые чтения истекут. Удаление внутри миграции гонялось бы с читателями,
которые ещё держат старый путь.

**Область арендатора — окружающая.** Строки приходят из `DbRepository`, который
фильтрует по тому, что сообщает `FileScopeProviderInterface`, и content-ключ
подмешивает ту же область — поэтому multi-tenant установка запускает это по
разу на арендатора, устанавливая арендатора так же, как это делают остальные её
CLI-задачи. Действующая область печатается до начала работы.

**При аренде шаг уборки переезжает.** `filestorage:gc --orphans` отказывается
работать, пока привязан `FileScopeProviderInterface`: строки, отфильтрованные по
одному арендатору, не доказывают, что объект никому не нужен, и подметание
объявило бы orphan'ами объекты всех остальных арендаторов. Прогоняйте миграцию
по разу на арендатора, как выше, а подметание — **один раз**, из обслуживающей
точки входа, где провайдер областей не привязан. Команда говорит об этом в
завершающей строке, а не оставляет вас узнавать это на отказе.

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
