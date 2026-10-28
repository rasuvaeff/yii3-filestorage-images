# rasuvaeff/yii3-filestorage-images

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/yii3-filestorage-images/v)](https://packagist.org/packages/rasuvaeff/yii3-filestorage-images)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/yii3-filestorage-images/downloads)](https://packagist.org/packages/rasuvaeff/yii3-filestorage-images)
[![Build](https://github.com/rasuvaeff/yii3-filestorage-images/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/yii3-filestorage-images/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/yii3-filestorage-images/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/yii3-filestorage-images/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/yii3-filestorage-images/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/yii3-filestorage-images/php)](https://packagist.org/packages/rasuvaeff/yii3-filestorage-images)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)
[English version](README.md)

Именованные пресеты производных изображений — миниатюры, превью, постеры — для
[rasuvaeff/yii3-filestorage](https://github.com/rasuvaeff/yii3-filestorage).
Ленивая генерация под локом от stampede, процессоры GD и Imagick за одним
интерфейсом, защита от decompression-бомб перед каждым декодированием и
SVG-санитайзер для единственного формата изображений, который одновременно
является программой.

> Работаете с AI-ассистентом? [llms.txt](llms.txt) содержит компактный API-справочник, который можно передать модели.
> Проекты с Composer-плагином [llm/skills](https://github.com/roxblnfk/skills) автоматически получают скилл этого пакета в `.agents/skills/` при установке.

**Статус: `0.x`.** API может меняться, пока интеграция отдачи вариантов через
`-web` обкатывается против этого пакета.

## Что делает

- **Пресеты, никогда — произвольные размеры.** Каждая производная, которую
  может выдать инсталляция, объявлена в `params` под именем. `w=200&h=200` в
  URL — даже подписанном — превращает одно загруженное изображение в
  неограниченное множество адресуемых производных; whitelist делает множество
  конечным по построению.
- **Версионированные рецепты.** Версия пресета — часть физического имени
  объекта (`thumb-v2.webp`). Изменение настроек пресета под тем же именем
  молча продолжало бы отдавать объекты, сгенерированные старыми настройками;
  bump `version` вместо этого создаёт новый объект.
- **Ленивая генерация с кэшем.** Первый запрос генерирует рендицию один раз,
  кладёт её рядом с оригиналом (`<key>/original.jpg` + `<key>/thumb-v1.webp`,
  без строк метаданных), каждый следующий запрос — обычное чтение. Новые
  пресеты работают ретроактивно без backfill. `filestorage:warm` прогревает
  через тот же самый залоченный путь, когда нужна eager-генерация.
- **Лок по физической идентичности блоба.** При дедупликации много строк
  `File` разделяют один каталог объекта; лок генерации ключуется по
  `BlobId` + версионированное имя производной, так что конкурентные запросы —
  включая запросы к разным логическим файлам с общими байтами — генерируют
  один раз.

- **Размеры — в метаданные при загрузке** (`ImageMetadataStorage`).
  Опциональный декоратор над фасадом: на `add()` он читает заголовок
  изображения (ограниченный префикс, без декодирования — тот же рецепт, что у
  upload-политики) и записывает `width`/`height` в `File::$metadata` —
  потребитель узнаёт размер из строки, а не декодируя объект. Проводится там
  же, где фасад:

  ```php
  StorageInterface::class => static fn (Storage $storage): StorageInterface
      => new ImageMetadataStorage($storage),
  ```

## Требования

- PHP 8.3 – 8.5, `ext-dom`, `ext-libxml`
- `rasuvaeff/yii3-filestorage` ^0.3
- Расширение-процессор: `ext-gd` и/или `ext-imagick` (при обоих предпочитается
  Imagick — он применяет EXIF-ориентацию и покрывает больше форматов)
- Драйвер `yiisoft/mutex` для лока генерации (например `yiisoft/mutex-file`)

## Установка

```bash
composer require rasuvaeff/yii3-filestorage-images
```

С `yiisoft/config` пакет подключается сам. Две вещи сознательно остаются за
приложением:

```php
// config/common/di/mutex.php — драйвер выбирается под деплой:
// file на одном хосте, db/redis на флоте.
use Yiisoft\Mutex\File\FileMutexFactory;
use Yiisoft\Mutex\MutexFactoryInterface;

return [
    MutexFactoryInterface::class => static fn (): MutexFactoryInterface
        => new FileMutexFactory(mutexPath: '/path/to/runtime/mutex'),
];
```

Стор приходит из семейства storage (`-flysystem` или файловый стор, привязанный
приложением) и обязан реализовывать core-интерфейс
`DerivativeAwareStoreInterface` — оба поставляемых файловых стора и
`FlysystemStore` его реализуют.

## Использование

### Объявить пресеты

```php
// config/common/params.php
return [
    'rasuvaeff/yii3-filestorage-images' => [
        'presets' => [
            'thumb' => [
                'version' => 1,
                'width' => 200,
                'height' => 200,
                'fit' => 'cover',      // 'contain' (по умолчанию) или 'cover'
                'format' => 'webp',    // jpeg | png | webp | gif | avif
                'quality' => 82,       // 1-100
            ],
            'poster' => [
                'width' => 1280,       // для 'contain' достаточно одной стороны
                'format' => 'jpeg',
            ],
        ],
    ],
];
```

### Сгенерировать и отдать

```php
use Rasuvaeff\Yii3FilestorageImages\Derivatives;

final readonly class AvatarPresenter
{
    public function __construct(private Derivatives $derivatives) {}

    public function thumbUrl(File $file): ?string
    {
        // Лениво генерирует при отсутствии, затем: постоянный публичный URL,
        // когда delivery-политика группы разрешает прямую публичную отдачу,
        // иначе store-native истекающий URL. Null — когда у стора нет
        // URL-возможности для производных; тогда отдавайте stream сами.
        return $this->derivatives->urlFor($file, 'thumb');
    }
}
```

| Метод | Что делает |
|---|---|
| `ensure(File $file, string $preset): bool` | Гарантирует существование рендиции; генерирует под локом. `true`, когда сгенерировал именно этот вызов |
| `stream(File $file, string $preset): ?StreamInterface` | Байты рендиции, с ленивой генерацией при необходимости |
| `urlFor(File $file, string $preset, ?DateTimeImmutable $expiresAt = null): ?string` | URL с учётом политики: прямой публичный → store-native временный → подписанный прокси (`-web`) → `null` |
| `exists(File $file, string $preset): bool` | Чистая проверка, никогда не генерирует |
| `descriptor(string $preset): DerivativeDescriptor` | Физическая идентичность (`thumb-v1.webp`), по которой стор адресует рендицию |

`Srcset::for($derivatives, $presets, $file)` превращает white-лист в
значение атрибута `srcset` — `url 200w, url 800w` — из ширин пресетов
(пресеты только с высотой не имеют дескриптора и пропускаются); null, когда
ни один пресет не дал URL, — пустой `srcset` это вообще не атрибут.
`filestorage:warm` заканчивает заметкой о каждом пресете старше версии 1:
старые рендеры остаются валидными и становятся orphan'ами — чистильщик это
`filestorage:gc --orphans`.

Фасад реализует контракт ядра `DerivativeResolverInterface` — именно его
потребляет download-экшен из `yii3-filestorage-web` для отдачи variant-токенов,
так что установка обоих пакетов и есть вся проводка: `urlFor()` начинает
отдавать подписанные прокси-URL для приватного filesystem-стора, и эти URL
разрешаются (`yii3-filestorage-web` 0.3.0+ — более ранние релизы требуют
старое ядро и variant-токены не отдают). `UnknownPresetException` наследует core-овский
`UnresolvableVariantException` — сигнал «ответь 404».

Словарь ошибок: `UnknownPresetException` (нет в whitelist — мапьте в 404),
`UnsupportedSourceException` (инсталляция не обрабатывает комбинацию — PDF в
растровом пайплайне, источник больше guard-лимита),
`ProcessingFailedException` (байты не декодируются),
`DerivativeGenerationException` (стор без capability, байты оригинала
пропали, таймаут лока). Некорректная конфигурация пресета бросает
`InvalidPresetException` при сборке контейнера, называя проблемный ключ.

### Прогреть до трафика

```bash
filestorage:warm                       # все пресеты, все файлы, резюмируемо
filestorage:warm --preset=thumb --limit=1000 --after=<file-id>
```

Аддитивна и идемпотентна — существующие рендиции считаются, не
перегенерируются. Неподдерживаемые файлы (не-изображения в смешанном бакете) —
скипы, не ошибки. Требует от бэкенда core-интерфейс
`MaintenanceRepositoryInterface` (репозиторий `-db` его реализует).

### Санитизировать SVG

SVG не обрабатывается процессорами — это не растровый формат, а документ,
который может нести скрипты, внешние ссылки и анимацию. Вместо этого пакет
предлагает opt-in санитайзер для запуска при загрузке:

```php
use Rasuvaeff\Yii3FilestorageImages\Svg\SvgSanitizerInterface;

$safe = $sanitizer->sanitize($svgBytes); // бросает SvgSanitizationException
```

`EnshrinedSvgSanitizer` = [enshrined/svg-sanitize](https://github.com/darylldoyle/svg-sanitizer)
с удалением внешних ссылок, плюс проверки, которых нет в библиотеке: лимит
байтов до парсинга, пост-проход, вырезающий `foreignObject` / `animate*` /
`set` / `mpath`, и лимит числа элементов. Даже санитизированный SVG отдавайте
attachment'ом, если он не с выделенного бескукового origin — см. документацию
delivery-политики core-пакета.

### Перекодирование при загрузке (приватность)

Фото с телефона несут в EXIF GPS-координаты и серийный номер камеры, и никто
не ожидает, что загрузка аватара опубликует домашний адрес. Imagick-процессор
вырезает метаданные из каждой производной; оригинал по дизайну сохраняет свои
байты. `examples/reencode-decorator.php` показывает декоратор storage,
перекодирующий оригиналы на `add()` — это вырезает EXIF и обезвреживает
полиглоты изображение/скрипт ценой несохранения загруженных байтов.

## Различия процессоров

| | GD | Imagick |
|---|---|---|
| EXIF-автоориентация | нет — боком снятое фото остаётся боком | да, до геометрии |
| Анимированный GIF | сплющивается в первый кадр | первый кадр |
| Метаданные в выводе | вырезаны по построению | вырезаны явно |
| Поддержка форматов | compile-time (`imagetypes()` спрашивается на каждый вызов) | зависит от сборки (`queryFormats()` спрашивается на каждый вызов) |

Оба применяют одни и те же guard'ы до декодирования: лимит байтов источника и
лимит пикселей из заголовка изображения — 200-байтовый PNG вправе *заявить*
100000×100000 пикселей, а аллокация происходит при декодировании.

## Безопасность

- Имена пресетов валидируются (`[a-z0-9][a-z0-9_-]{0,47}`) и становятся
  именами файлов только через core-`DerivativeDescriptor`, который валидирует
  повторно. Вызывающий не может протащить слэш или выбрать выходной тип
  независимо.
- Произвольные параметры трансформаций не принимаются нигде — by design.
- Guard'ы декодирования работают по заголовку до аллокации пикселей
  (`maxSourcePixels`, по умолчанию 40 МП) и по байтам при чтении
  (`maxSourceBytes`, по умолчанию 64 МиБ).
- Производная обязана быть чистой функцией байтов оригинала и версии пресета:
  при дедупликации рендиции живут в общем каталоге блоба. Пер-владельческие
  водяные знаки и redaction в модель этого пакета не входят.
- Вывод санитайзера — безопас*нее*, но не безопасен для inline-отдачи с origin
  приложения: правила отдачи активного контента из core-пакета продолжают
  действовать.

## Примеры

Исполняемые скрипты — в [`examples/`](examples/README.md).

## Разработка

```bash
make build        # validate + normalize + require-checker + cs + psalm + тесты
make test-images  # тесты с вкомпилированными gd+imagick (без скипов)
make mutation     # infection с bootstrap'нутым pcov
```

## Лицензия

BSD-3-Clause. См. [LICENSE.md](LICENSE.md).
