<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Tests\Command;

use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Rasuvaeff\Yii3FilestorageImages\Command\WarmCommand;
use Rasuvaeff\Yii3FilestorageImages\Derivatives;
use Rasuvaeff\Yii3FilestorageImages\Format;
use Rasuvaeff\Yii3FilestorageImages\Preset;
use Rasuvaeff\Yii3FilestorageImages\PresetRegistry;
use Rasuvaeff\Yii3FilestorageImages\Processing\ImageProcessorInterface;
use Rasuvaeff\Yii3FilestorageImages\Tests\Support\DerivativeAwareMemoryStore;
use Rasuvaeff\Yii3FilestorageImages\Tests\Support\Doubles;
use Rasuvaeff\Yii3FilestorageImages\Tests\Support\Fixtures;
use Rasuvaeff\Yii3FilestorageImages\Tests\Support\MemoryMaintenanceRepository;
use Symfony\Component\Console\Tester\CommandTester;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;
use Yiisoft\Test\Support\Clock\StaticClock;

#[Test]
#[Covers(WarmCommand::class)]
final class WarmCommandTest
{
    public function warmsEveryConfiguredPresetForEveryFile(): void
    {
        $store = $this->storeWith('file-1', 'file-2');
        $tester = $this->tester($store, files: [
            Fixtures::file(id: 'file-1'),
            Fixtures::file(id: 'file-2'),
        ]);

        Assert::same($tester->execute([]), 0);
        Assert::string($this->display($tester))->contains('Files: 2. Renditions: 4 generated, 0 already present');
        Assert::same(\count($store->derivativeWrites), 4);
    }

    public function warmingTwiceGeneratesNothingNew(): void
    {
        $store = $this->storeWith('file-1');
        $files = [Fixtures::file(id: 'file-1')];

        $this->tester($store, files: $files)->execute([]);
        $tester = $this->tester($store, files: $files);

        Assert::same($tester->execute([]), 0);
        Assert::string($this->display($tester))->contains('0 generated, 2 already present');
    }

    public function presetOptionNarrowsTheWork(): void
    {
        $store = $this->storeWith('file-1');
        $tester = $this->tester($store, files: [Fixtures::file(id: 'file-1')]);

        Assert::same($tester->execute(['--preset' => ['thumb']]), 0);
        Assert::string($this->display($tester))->contains('1 generated');
        Assert::same(\count($store->derivativeWrites), 1);
    }

    public function unknownPresetOptionFailsBeforeTouchingAnything(): void
    {
        $store = $this->storeWith('file-1');
        $tester = $this->tester($store, files: [Fixtures::file(id: 'file-1')]);

        Assert::same($tester->execute(['--preset' => ['nope']]), 1);
        Assert::string($this->display($tester))->contains('Unknown preset "nope"');
        Assert::same($store->derivativeWrites, []);
    }

    public function unsupportedFilesAreSkipsNotFailures(): void
    {
        $store = $this->storeWith('file-1');
        $tester = $this->tester($store, files: [
            Fixtures::file(id: 'file-1'),
            Fixtures::file(id: 'file-2', mimeType: 'application/pdf'),
        ], processor: Doubles::processor(supports: false));

        // Nothing is supported, so everything is a skip — and skips exit 0.
        Assert::same($tester->execute([]), 0);
        Assert::string($this->display($tester))->contains('0 generated, 0 already present, 4 skipped, 0 failed');
    }

    public function missingBytesAreFailuresAndFailTheRun(): void
    {
        // file-2's row exists but its object does not.
        $store = $this->storeWith('file-1');
        $tester = $this->tester($store, files: [
            Fixtures::file(id: 'file-1'),
            Fixtures::file(id: 'file-2'),
        ]);

        Assert::same($tester->execute([]), 1);
        Assert::string($this->display($tester))->contains('2 generated, 0 already present, 0 skipped, 2 failed');
        Assert::string($this->display($tester))->contains('failed: file-2');
    }

    public function limitAndAfterPageThroughTheRepository(): void
    {
        $store = $this->storeWith('file-1', 'file-2', 'file-3');
        $files = [
            Fixtures::file(id: 'file-1'),
            Fixtures::file(id: 'file-2'),
            Fixtures::file(id: 'file-3'),
        ];

        $first = $this->tester($store, files: $files);
        Assert::same($first->execute(['--limit' => '2', '--preset' => ['thumb']]), 0);
        Assert::string($this->display($first))->contains('Files: 2.');
        Assert::string($this->display($first))->contains('Last id: file-2');

        $second = $this->tester($store, files: $files);
        Assert::same($second->execute(['--after' => 'file-2', '--preset' => ['thumb']]), 0);
        Assert::string($this->display($second))->contains('Files: 1.');
        Assert::string($this->display($second))->contains('Last id: file-3');
    }

    public function noConfiguredPresetsIsAWarningNotAnError(): void
    {
        $store = $this->storeWith('file-1');
        $tester = $this->tester(
            $store,
            files: [Fixtures::file(id: 'file-1')],
            presets: new PresetRegistry(),
        );

        Assert::same($tester->execute([]), 0);
        Assert::string($this->display($tester))->contains('No presets are configured');
    }

    /**
     * SymfonyStyle wraps its success/warning blocks at the terminal width, so
     * the summary sentence may arrive with line breaks inside it.
     */
    private function display(CommandTester $tester): string
    {
        return (string) preg_replace(pattern: '/\\s+/', replacement: ' ', subject: $tester->getDisplay());
    }

    private function storeWith(string ...$ids): DerivativeAwareMemoryStore
    {
        $store = new DerivativeAwareMemoryStore();
        foreach ($ids as $id) {
            $store->setObject(Fixtures::file(id: $id)->relativePath, 'original bytes');
        }

        return $store;
    }

    /**
     * @param list<File> $files
     */
    private function tester(
        DerivativeAwareMemoryStore $store,
        array $files,
        ?ImageProcessorInterface $processor = null,
        ?PresetRegistry $presets = null,
    ): CommandTester {
        $presets ??= new PresetRegistry([
            new Preset(name: 'thumb', format: Format::Webp, width: 100),
            new Preset(name: 'poster', format: Format::Jpeg, width: 1280),
        ]);

        return new CommandTester(new WarmCommand(
            derivatives: new Derivatives(
                stores: new StoreRegistry([$store]),
                presets: $presets,
                processor: $processor ?? Doubles::processor(),
                mutexes: Doubles::mutexes()[0],
                deliveryPolicies: new DeliveryPolicyRegistry(),
                clock: new StaticClock(Fixtures::now()),
            ),
            presets: $presets,
            repository: new MemoryMaintenanceRepository($files),
        ));
    }
}
