<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Tests\Support;

use Override;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Repository\MaintenanceRepositoryInterface;

/**
 * @internal
 */
final class MemoryMaintenanceRepository implements MaintenanceRepositoryInterface
{
    /** @var array<non-empty-string, File> */
    private array $files = [];

    /**
     * @param list<File> $files
     */
    public function __construct(array $files = [])
    {
        foreach ($files as $file) {
            $this->files[$file->id] = $file;
        }
        ksort($this->files);
    }

    #[Override]
    public function find(string $id): ?File
    {
        return $this->files[$id] ?? null;
    }

    #[Override]
    public function save(File $file): void
    {
        $this->files[$file->id] = $file;
        ksort($this->files);
    }

    #[Override]
    public function delete(string $id): bool
    {
        $existed = isset($this->files[$id]);
        unset($this->files[$id]);

        return $existed;
    }

    #[Override]
    public function files(?string $afterId = null, int $limit = 1000): iterable
    {
        $emitted = 0;
        foreach ($this->files as $id => $file) {
            if ($afterId !== null && $id <= $afterId) {
                continue;
            }
            if ($emitted >= $limit) {
                break;
            }
            $emitted++;

            yield $file;
        }
    }

    #[Override]
    public function updateContentHash(string $id, string $contentHash): bool
    {
        return isset($this->files[$id]);
    }
}
