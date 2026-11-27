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
- `rasuvaeff/yii3-filestorage` ^0.9
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
`ScopedFileResolverInterface` и `BlobLedgerInterface`. Ядро биндит фасад —
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
`filestorage_blob_reservation`, индекс на `filestorage_file`
`(store_name, relative_path)` для зачистки сирот (`M261127000000…`, добавлен в
0.3.0 — существующая установка получит его при следующем `migrate:up`). На
MySQL он индексирует первые 191 символ пути, на PostgreSQL использует
`varchar_pattern_ops`, чтобы `LIKE 'dir/%'` мог по нему идти. MySQL 8 строит его
без блокировки записи; на PostgreSQL обычный `CREATE INDEX` блокирует запись на
время построения, поэтому для большой нагруженной таблицы создайте его заранее:
`CREATE INDEX CONCURRENTLY idx_filestorage_file_store_path ON filestorage_file
(store_name, relative_path varchar_pattern_ops)` — миграция использует
`IF NOT EXISTS` и тогда только отметит себя применённой.

`M261128000000…` (0.4.0) переводит `filestorage_file.scope_id` в `utf8mb4_bin`
на MySQL и MariaDB, чьи collation по умолчанию считают `Acme`, `acme` и `Acmé`
одним тенантом. Сравнение только становится строже, поэтому на существующих
строках миграция упасть не может; на SQLite и PostgreSQL она ничего не делает.

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
        'allowGlobalAccess' => false,
    ],
];
```

Префикс подставляется ко всем трём именам. Имена валидируются типизированными
VO — `FileTableName`, `BlobTableName`, `BlobReservationTableName`, — которые
миграции резолвят из контейнера, поэтому схема и запросы не могут разойтись в
имени. `allowGlobalAccess` — opt-in для обслуживания, описанный в разделе
[Тенантный scope](#тенантный-scope).

## Тенантный scope

Забиндите `FileScopeProviderInterface` — и каждый запрос пакета получит
предикат по `scope_id`. Не биндите — установка однотенантная: ни предиката, ни
накладных расходов. Колонка сравнивается побайтно, на MySQL тоже.

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

**Привязанный провайдер, вернувший `null`, получает отказ, а не исполнение.**
Привязан — значит, установка мультитенантная, и null там — тенант, который не
определился: анонимный маршрут, сбой поиска, задача, запущенная без контекста.
Все методы `DbRepository`, `DeduplicatingStorage::add()` и
`filestorage:deduplicate` тогда бросают `MissingScopeException` (команда —
завершается с ошибкой), а не снимают предикат. До 0.4.0 предикат там молча
снимался, и `find()`/`remove()` доставали строки всех тенантов.

**Глобальный доступ — явный opt-in и только для обслуживания.** Точка входа
обслуживания либо оставляет провайдер непривязанным, либо сохраняет его и
включает `allowGlobalAccess` — тогда null-scope означает «все тенанты» для
чтения, обновления и удаления (новой строке тенант всё равно нужен). Команды
ядра `filestorage:gc`, `stat`, `verify` и `backfill-hash` читают через этот
репозиторий, поэтому мультитенантная cron-задача, где тенант не определился,
теперь падает, пока её точка входа не сделает одно из двух — см.
[UPGRADE.md](UPGRADE.md):

```php
// собственные params консольной точки входа, никогда не веб-точки
return [
    'rasuvaeff/yii3-filestorage-db' => ['allowGlobalAccess' => true],
];
```

**Для определившегося тенанта метода, обходящего предикат, нет.** Ни для
скачивания, ни для обслуживания. У подписанной загрузки нет окружающего
тенанта — в этом и смысл подписи, — а соблазнительный обходной путь (поиск по
id с отключённым фильтром) читает любой файл, чей id утёк. Вместо этого —
`ScopedFileResolverInterface`: scope был аутентифицирован при выпуске токена,
едет внутри HMAC и сверяется вторым предикатом.

```php
$file = $resolver->findInScope($payload->fileId, $payload->scopeId);
```

`null` — это scope, а не его отсутствие: он матчится в `scope_id IS NULL`,
именно это лежит в строках приложения без тенантов. Токен без scope никогда не
становится wildcard — резолвер и репозиторий согласны, что null не означает
«все тенанты», если этого не сказал `allowGlobalAccess`.

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

Состояния: `writing`, `active`, `pending_delete`, `deleting`. Писатель
присоединяется к любому. Blob в `deleting` с ещё живым lease отвечает
`BlobBusyException` — повторите после истечения lease; после этого писатель
забирает blob себе (сборщик, умерший под lease, больше не блокирует содержимое
до следующего `gc`, а его устаревший `completeDeletion()` получает отказ).
`commit()` не активирует blob, который сборщик захватил после присоединения.
Строка, которую `commit()` переносит на другой content-ключ, планирует к сборке
покинутый blob.

Проход сборки — это `filestorage:gc` (ядро). Используйте его, не пишите свой.
Если всё же пишете, порядок такой: **карантин → `completeDeletion()` → purge,
либо restore при отказе**. Удаление объекта до `completeDeletion()` — та самая
гонка, ради которой в ядре 0.9 появился карантин: сборщик, у которого
перехватили lease, удаляет байты, только что заново опубликованные писателем, а
отказ приходит уже после удаления.

```php
$ledger->expireReservations($now, $deleteAfter);         // сначала смести брошенных писателей

while ($lease = $ledger->claimForDeletion($now, $now->add($leaseTtl))) {
    try {
        $entry = $store->quarantine($store->layout()->deletion($lease->blob->object), $now);
    } catch (StoreException) {
        $ledger->abandonDeletion($lease, $retryAfter);   // backoff, строка остаётся

        continue;
    }

    if ($ledger->completeDeletion($lease)) {             // откажет, если blob ожил
        $store->purge($entry);
    } else {
        $store->restore($entry);                         // сохранит повторную публикацию писателя
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
биндит рядом с интерфейсом именно ради этого, начиная с 0.1.1. Настраивать
нечего, и то же правило действует для любого декоратора, который вы напишете сами.

`reservationTtl` и `deleteGracePeriod` обязаны быть положительными (иначе
`InvalidArgumentException`): при нулевой паузе «запланирован» и «можно собирать»
— один момент, и писатель, присоединяющийся к blob, теряющему последнюю ссылку,
гоняется со сборщиком, а не опережает его.

`create()` отклоняет три конфигурации, которые иначе тихо теряют данные, и
говорит, что делать вместо: хранилище без `ContentAddressableStoreInterface`;
реализацию `BlobLedgerInterface`, отличную от `DbBlobLedger` этого пакета, —
гарантии дедупликации держатся на SQL-предикатах внутри действующих операторов,
и реализация, хранящая состояние где-то ещё, того же обещать не может; и реестр
на соединении, отличном от репозитория, — из-за чего `commit()` становится двумя
транзакциями вместо одной, и падение между ними оставляет строку без ссылки либо
ссылку без строки.

Ключ содержимого строит `ContentAddressedKeyGeneratorInterface` ядра
(`Sha256KeyGenerator`, если ничего не привязано), и он получает расширение
*определённого* медиатипа через `ExtensionMap` ядра — тот же, с теми же
`extensionOverrides`, что именует уникальные файлы. `Sha256KeyGenerator` его
игнорирует, так что существующие ключи не сдвигаются. Для плоского дерева вида
`sha/ab/cd/<sha>.<ext>` привяжите `FlatSha256KeyGenerator` ядра и дайте
хранилищу `FlatSiblingLayout` — **оба, всегда**. Пара пока не проверяется: с
`DirectoryLayout` по умолчанию собранный blob уносит весь каталог `ab/cd/`, а с
ним и все blob'ы с теми же первыми четырьмя hex-символами:

```php
use Rasuvaeff\Yii3Filestorage\Path\ContentAddressedKeyGeneratorInterface;
use Rasuvaeff\Yii3Filestorage\Path\FlatSha256KeyGenerator;

return [
    ContentAddressedKeyGeneratorInterface::class => FlatSha256KeyGenerator::class,
];
```

`filestorage:deduplicate` берёт тот же генератор и тот же `ExtensionMap` и
выводит расширение из медиатипа, записанного в строке, — определённого при
добавлении файла, — так что мигрированная строка ложится ровно туда, куда лёг бы
живой `add()` тех же байт.

Для потребителя не меняется ничего. `add()` по-прежнему возвращает `File` со
своим id, группой, описанием и метаданными; просто две загрузки одинаковых байт
указывают на один объект. Выше `dedupMaxBytes` загрузка молча уходит на
уникальный путь: ключ содержимого *и есть* хеш, его нельзя выбрать, не прочитав
тело целиком, а такое второе чтение того не стоит. Загрузка остаётся в
хранилище дедупликации, и `remove()` удаляет её байты — ровно как базовый
фасад; разделяемые байты удаляет только сборщик. Тело без объявленной длины
при хешировании тоже останавливается на `maxBytes` группы — с тем же
`UploadSizeException`, что у базового фасада.

### Чего она не делает

| Ожидание | На деле | Вместо этого |
|---|---|---|
| Локальное файловое хранилище с дедупликацией | `FileSystemStore` и `PublicFileSystemStore` ядра не реализуют `ContentAddressableStoreInterface`; `create()` их отклоняет | `FlysystemContentAddressableStore` из `-flysystem`, в том числе поверх локального адаптера |
| Существующие адреса `blobs/ab/cd/<sha>.<ext>` сохраняются | `DedupScope::keyFor()` всегда добавляет префикс `sha`, `sha/<tenant>` или `sha/<tenant>/<group>`, и `FlatSha256KeyGenerator` этот префикс требует | Свой `ContentAddressedKeyGeneratorInterface`, игнорирующий префикс, с `DedupScope::Global` и `FlatSiblingLayout` |
| Принять объекты, уже лежащие на своём content-ключе, без копирования | Команды `filestorage:adopt` нет; `filestorage:deduplicate` читает, хеширует и заново публикует каждую строку | В планах. Пока — `filestorage:deduplicate` |
| Одинаковые байты делятся между группами | `TenantGroup` по умолчанию кладёт группу в ключ | `DedupScope::Global` (или `Tenant`), см. ниже |

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
content-ключе, распознаётся и пропускается. Прогон с другим ключом — другим
`--scope`, генератором ключей или `extensionOverrides` — переносит уже
разделённые строки ещё раз, и `commit()` планирует к сборке покинутый каждой
строкой blob, так что `filestorage:gc` заберёт его после паузы.

**Старый объект остаётся на месте намеренно.** Он становится orphan в момент
перенаправления строки, и `filestorage:gc --orphans --apply` забирает его, когда
незавершённые чтения истекут. Удаление внутри миграции гонялось бы с читателями,
которые ещё держат старый путь.

**Область арендатора — окружающая.** Строки приходят из `DbRepository`, который
фильтрует по тому, что сообщает `FileScopeProviderInterface`, и content-ключ
подмешивает ту же область — поэтому multi-tenant установка запускает это по
разу на арендатора, устанавливая арендатора так же, как это делают остальные её
CLI-задачи. Действующая область печатается до начала работы, а привязанный
провайдер без тенанта получает отказ — даже при `allowGlobalAccess`, который
слил бы строки всех тенантов в один пул.

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

`referencedPaths($storeName, $directories)` — то, что `filestorage:gc --orphans`
спрашивает на каждой странице листинга: пути строк хранилища, лежащих в любом из
указанных каталогов. Это один параметризованный запрос на 200 каталогов
(`relative_path LIKE '<dir>/%' ESCAPE '!'` или равенство пути корневого уровня),
`_` и `%` в каталоге понимаются буквально — escape-символ объявлен в самом
операторе, поэтому режим MySQL `NO_BACKSLASH_ESCAPES` не превращает их обратно в
шаблоны, — а затем хранилище и каталог сравниваются точно в PHP — иначе collation MySQL по умолчанию ответил бы про `A/x` на вопрос о `a`.
Тенантный предикат сохраняется и здесь — ещё одна причина запускать зачистку из
точки входа без привязанного провайдера scope.

`findMany($ids)` читает пачку строк одним запросом на 500 id, со scope как у
`find()`; несуществующих id и id другого тенанта в результате просто нет.

## Формат хранения

| Решение | Почему |
|---|---|
| Метки времени — текст `Y-m-d\TH:i:s.uP`, нормализованный в UTC | Микросекунды сохраняются (обрезающая колонка сломала бы round-trip-контракт `File`), а реестр сравнивает дедлайны как строки — это совпадает с хронологическим порядком только если у всех строк одинаковое смещение |
| `metadata` — JSON в текстовой колонке | Форма — плоский `array<string, scalar\|null>`, внутрь никто не запрашивает, так что нативная JSON-колонка ничего не дала бы и стоила бы переносимости |
| `save()` — scoped-update с фолбэком в insert, а не upsert | Upsert матчится только по primary key, поэтому чужая строка была бы молча перехвачена. Id неугадываемы, но «неугадываемо» — не граница |
| Битая строка — исключение, а не приведённый `File` | `InvalidFileRowException`. Приводить значения, пока `File::create()` не перестанет ругаться, — способ превратить повреждённую строку в правдоподобную запись, указывающую не на тот объект |
| Primary key блоба — `sha256(store:path)` | Уникальному индексу по паре нужен весь путь в 512 символов (префикс уникальность не обеспечит), а MySQL отказывает в таком ключе при формате строк `COMPACT`; производный ключ заставляет двух писателей, гоняющихся за одним блобом, столкнуться на primary key вместо создания двух строк на один объект |

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

`make test-integration` гоняет SQLite. Тот же suite идёт на MySQL или
PostgreSQL, если `FILESTORAGE_TEST_DB` называет сервер (CI гоняет оба), —
случаи конкурентности реестра тогда идут через два серверных соединения, на
MySQL — под `NO_BACKSLASH_ESCAPES,ANSI_QUOTES`; образу нужны
`pdo_mysql`/`pdo_pgsql`, которых в `composer:2` нет:

```bash
docker run --rm --network host -v "$PWD":/app -w /app \
  -e FILESTORAGE_TEST_DB=mysql -e FILESTORAGE_TEST_MYSQL_PORT=3306 \
  <php-image-with-pdo_mysql-and-pdo_pgsql> vendor/bin/testo --suite=Integration
```

## Лицензия

BSD-3-Clause. См. [LICENSE.md](LICENSE.md).
