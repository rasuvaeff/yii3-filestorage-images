<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Command;

use Override;
use Rasuvaeff\Yii3Filestorage\Exception\FilestorageException;
use Rasuvaeff\Yii3Filestorage\Repository\MaintenanceRepositoryInterface;
use Rasuvaeff\Yii3FilestorageImages\Derivatives;
use Rasuvaeff\Yii3FilestorageImages\Exception\UnknownPresetException;
use Rasuvaeff\Yii3FilestorageImages\Exception\UnsupportedSourceException;
use Rasuvaeff\Yii3FilestorageImages\PresetRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Pre-generates derivatives so the first visitor does not pay for them.

 * Eager generation is opt-in and goes through {@see Derivatives::ensure()} —
 * the exact locked path lazy generation uses. There is deliberately no second
 * implementation to warm with: a preset that warms differently from how it
 * serves would be two presets pretending to be one.
 *
 * Additive and idempotent — existing renditions are skipped, so there is no
 * `--force` and no dry-run: a second run over the same range does nothing.
 * Resumable via `--after`, like every long command in the family.
 *
 * @api
 */
#[AsCommand(name: 'filestorage:warm', description: 'Pre-generate derivative presets for stored files')]
final class WarmCommand extends Command
{
    private const int PAGE = 500;

    public function __construct(
        private readonly Derivatives $derivatives,
        private readonly PresetRegistry $presets,
        private readonly MaintenanceRepositoryInterface $repository,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addOption(
                'preset',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Preset to generate (repeatable); defaults to every configured preset',
            )
            ->addOption('after', null, InputOption::VALUE_REQUIRED, 'Resume after this file id')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many files', '1000');
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $presetNames = $this->presetNames($input);
        } catch (UnknownPresetException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }
        if ($presetNames === []) {
            $io->warning('No presets are configured — nothing to warm.');

            return Command::SUCCESS;
        }

        $limit = max(1, (int) $input->getOption('limit'));
        $after = $this->stringOption($input, name: 'after');

        $seen = 0;
        $generated = 0;
        $existing = 0;
        $skipped = 0;
        $failed = 0;
        $last = null;

        while ($seen < $limit) {
            $page = iterator_to_array(
                $this->repository->files($after, max(1, min(self::PAGE, $limit - $seen))),
                preserve_keys: false,
            );
            if ($page === []) {
                break;
            }

            foreach ($page as $file) {
                $seen++;
                $last = $file->id;
                $after = $file->id;

                foreach ($presetNames as $presetName) {
                    try {
                        $this->derivatives->ensure($file, $presetName) ? $generated++ : $existing++;
                    } catch (UnsupportedSourceException) {
                        // Not an image, or not one this installation
                        // processes. Expected in any mixed bucket — a skip,
                        // not a failure.
                        $skipped++;
                    } catch (FilestorageException $exception) {
                        $failed++;
                        $io->text(sprintf(
                            '  failed: %s %s: %s',
                            $file->id,
                            $presetName,
                            $exception->getMessage(),
                        ));
                    }
                }
            }
        }

        if ($last !== null) {
            $io->text(sprintf('Last id: %s', $last));
        }

        $io->{$failed === 0 ? 'success' : 'warning'}(sprintf(
            'Files: %d. Renditions: %d generated, %d already present, %d skipped, %d failed.',
            $seen,
            $generated,
            $existing,
            $skipped,
            $failed,
        ));

        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @return list<non-empty-string>
     *
     * @throws UnknownPresetException
     */
    private function presetNames(InputInterface $input): array
    {
        // Through getOptions() rather than getOption(): an array offset is
        // something psalm narrows across accesses, where a method call's
        // `mixed` needs a `@var` tag that rector removes as redundant.
        $options = $input->getOptions();
        $requested = isset($options['preset']) && \is_array($options['preset']) ? $options['preset'] : [];
        if ($requested === []) {
            return $this->presets->names();
        }

        $names = [];
        foreach ($requested as $name) {
            if (!\is_string($name) || $name === '') {
                throw new UnknownPresetException('Preset name must not be empty');
            }
            // get() validates against the whitelist and names the configured
            // presets in its message — the operator sees their options.
            $names[] = $this->presets->get($name)->name;
        }

        return $names;
    }

    /**
     * @return non-empty-string|null
     */
    private function stringOption(InputInterface $input, string $name): ?string
    {
        $options = $input->getOptions();

        return isset($options[$name]) && \is_string($options[$name]) && $options[$name] !== ''
            ? $options[$name]
            : null;
    }
}
