<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Tests;

use Psr\Clock\ClockInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Repository\MaintenanceRepositoryInterface;
use Rasuvaeff\Yii3Filestorage\Repository\RepositoryInterface;
use Rasuvaeff\Yii3Filestorage\StorageInterface;
use Rasuvaeff\Yii3Filestorage\Store\DerivativeResolverInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Rasuvaeff\Yii3Filestorage\Url\ProxyUrlGeneratorInterface;
use Rasuvaeff\Yii3FilestorageImages\Command\WarmCommand;
use Rasuvaeff\Yii3FilestorageImages\Derivatives;
use Rasuvaeff\Yii3FilestorageImages\PresetRegistry;
use Rasuvaeff\Yii3FilestorageImages\Processing\CompositeImageProcessor;
use Rasuvaeff\Yii3FilestorageImages\Processing\ImageProcessorInterface;
use Rasuvaeff\Yii3FilestorageImages\Svg\EnshrinedSvgSanitizer;
use Rasuvaeff\Yii3FilestorageImages\Svg\SvgSanitizerInterface;
use Rasuvaeff\Yii3FilestorageImages\Tests\Support\DerivativeAwareMemoryStore;
use Rasuvaeff\Yii3FilestorageImages\Tests\Support\Fixtures;
use Rasuvaeff\Yii3FilestorageImages\Tests\Support\MemoryMaintenanceRepository;
use Rasuvaeff\Yii3FilestorageImages\Tests\Support\PredictableMutexFactory;
use Rasuvaeff\Yii3FilestorageImages\Tests\Support\SignedProxyUrlGenerator;
use ReflectionProperty;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Test;
use Yiisoft\Di\Container;
use Yiisoft\Di\ContainerConfig;
use Yiisoft\Mutex\MutexFactoryInterface;
use Yiisoft\Test\Support\Clock\StaticClock;

/**
 * `config/di.php` is covered by neither cs, nor psalm, nor the unit suite — it
 * is not in `src`. Without this test a mistake there surfaces at deploy time,
 * so it is exercised through a real container rather than by reading the array.
 *
 * @internal
 */
#[Test]
#[CoversNothing]
final class ConfigWiringTest
{
    public function everyServiceThisPackageOwnsResolves(): void
    {
        $container = $this->container();

        Assert::instanceOf($container->get(PresetRegistry::class), PresetRegistry::class);
        Assert::instanceOf($container->get(ImageProcessorInterface::class), CompositeImageProcessor::class);
        Assert::instanceOf($container->get(SvgSanitizerInterface::class), EnshrinedSvgSanitizer::class);
        Assert::instanceOf($container->get(Derivatives::class), Derivatives::class);
        Assert::instanceOf($container->get(WarmCommand::class), WarmCommand::class);
        // The core contract resolves to the facade — that alias is what lets
        // `-web`'s download action pick the resolver up without either
        // package depending on the other.
        $resolver = $container->get(DerivativeResolverInterface::class);
        Assert::instanceOf($resolver, Derivatives::class);
        Assert::instanceOf($resolver, DerivativeResolverInterface::class);
    }

    /**
     * The factory's optional parameter is not decorative: with
     * `ProxyUrlGeneratorInterface` bound — what installing
     * `yii3-filestorage-web` does — the facade must receive it, and without
     * it the parameter falls back to null rather than failing to resolve.
     */
    public function theProxyGeneratorFlowsInWhenBound(): void
    {
        $definitions = $this->definitions();
        $definitions += $this->serviceDoubles();
        $definitions[ProxyUrlGeneratorInterface::class] = static fn(): ProxyUrlGeneratorInterface
            => new SignedProxyUrlGenerator();

        $withProxy = new Container(ContainerConfig::create()->withDefinitions($definitions));
        $proxy = new ReflectionProperty(Derivatives::class, 'proxyUrls');
        Assert::instanceOf($proxy->getValue($withProxy->get(Derivatives::class)), SignedProxyUrlGenerator::class);

        $withoutProxy = new Container(ContainerConfig::create()->withDefinitions($this->definitions() + $this->serviceDoubles()));
        Assert::null($proxy->getValue($withoutProxy->get(Derivatives::class)));
    }

    public function shippedPresetParamsBuildTheDocumentedShape(): void
    {
        $params = $this->params();
        $registry = PresetRegistry::fromArray([
            'thumb' => [
                'version' => 1,
                'width' => 200,
                'height' => 200,
                'fit' => 'cover',
                'format' => 'webp',
                'quality' => 82,
                'upscale' => false,
                'maxBytes' => 0,
            ],
        ]);

        // The commented example in params.php is the documented contract; if
        // Preset stops accepting it, the shipped documentation is wrong.
        Assert::same($registry->get('thumb')->descriptor()->fileName(), 'thumb-v1.webp');
        Assert::same($params['rasuvaeff/yii3-filestorage-images']['presets'], []);
    }

    /**
     * The one-source rule: this package binds only its own services. The
     * mutex driver, the stores, the clock and the repository belong to the
     * application or other family packages.
     */
    public function thisPackageBindsOnlyItsOwnServices(): void
    {
        $definitions = $this->definitions();

        foreach ([
            MutexFactoryInterface::class,
            StorageInterface::class,
            RepositoryInterface::class,
            StoreInterface::class,
            StoreRegistry::class,
            DeliveryPolicyRegistry::class,
            ClockInterface::class,
        ] as $foreign) {
            Assert::false(\array_key_exists($foreign, $definitions), "must not bind {$foreign}");
        }
    }

    public function everyParameterTheWiringReadsIsShipped(): void
    {
        $own = $this->params()['rasuvaeff/yii3-filestorage-images'];

        foreach ([
            'presets', 'defaultUrlTtl', 'lockTimeout', 'maxSourceBytes', 'maxSourcePixels', 'svg',
        ] as $key) {
            Assert::true(\array_key_exists($key, $own), "params is missing \"{$key}\"");
        }
        Assert::true(\array_key_exists('maxBytes', $own['svg']));
        Assert::true(\array_key_exists('maxElements', $own['svg']));
    }

    public function theWarmCommandIsRegisteredUnderItsName(): void
    {
        Assert::same(
            $this->params()['yiisoft/yii-console']['commands']['filestorage:warm'],
            WarmCommand::class,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function definitions(): array
    {
        $params = $this->params();

        return require __DIR__ . '/../config/di.php';
    }

    private function container(): Container
    {
        return new Container(ContainerConfig::create()->withDefinitions($this->definitions() + $this->serviceDoubles()));
    }

    /**
     * The pieces this package expects the application (or its sibling
     * packages) to provide: a clock, stream factories, a mutex driver, the
     * stores, the delivery policies and the maintenance repository.
     *
     * @return array<class-string, callable>
     */
    private function serviceDoubles(): array
    {
        return [
            ClockInterface::class => static fn(): ClockInterface => new StaticClock(Fixtures::now()),
            StreamFactoryInterface::class => static fn(): StreamFactoryInterface => Fixtures::factory(),
            MutexFactoryInterface::class => static fn(): MutexFactoryInterface
                => new PredictableMutexFactory(),
            StoreRegistry::class => static fn(): StoreRegistry
                => new StoreRegistry([new DerivativeAwareMemoryStore()]),
            DeliveryPolicyRegistry::class => static fn(): DeliveryPolicyRegistry
                => new DeliveryPolicyRegistry(),
            MaintenanceRepositoryInterface::class => static fn(): MaintenanceRepositoryInterface
                => new MemoryMaintenanceRepository(),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function params(): array
    {
        return require __DIR__ . '/../config/params.php';
    }
}
