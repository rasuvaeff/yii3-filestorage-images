<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Tests;

use DateTimeImmutable;
use Rasuvaeff\Understudy\Arg;
use Rasuvaeff\Understudy\Understudy;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\StorageInterface;
use Rasuvaeff\Yii3Filestorage\Upload;
use Rasuvaeff\Yii3FilestorageImages\ImageMetadataStorage;
use Rasuvaeff\Yii3FilestorageImages\Tests\Support\Fixtures;
use Rasuvaeff\Yii3FilestorageImages\Tests\Support\Png;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

use function Rasuvaeff\Understudy\when;

#[Test]
#[Covers(ImageMetadataStorage::class)]
final class ImageMetadataStorageTest
{
    public function recordsTheDimensionsOfAnImage(): void
    {
        $storage = $this->inner();

        $file = $this->decorated($storage)->add($this->upload(Png::header(320, 200) . 'rest of the file'));

        Assert::same($file->metadata['width'] ?? null, 320);
        Assert::same($file->metadata['height'] ?? null, 200);
        Assert::same($this->capturedMetadata($storage), ['width' => 320, 'height' => 200]);
    }

    /** The detected dimensions are facts and win over caller-supplied guesses. */
    public function detectedDimensionsOverrideCallerMetadata(): void
    {
        $storage = $this->inner();

        $this->decorated($storage)->add(
            $this->upload(Png::header(4, 4)),
            metadata: ['width' => 9999, 'source' => 'avatar-form'],
        );

        $captured = $this->capturedMetadata($storage);
        Assert::same($captured['source'] ?? null, 'avatar-form');
        Assert::same($captured['width'] ?? null, 4);
        Assert::same($captured['height'] ?? null, 4);
    }

    /** A decorator must not make uploads fail that the policy accepted. */
    public function anythingItCannotParsePassesThroughUnchanged(): void
    {
        $storage = $this->inner();

        $this->decorated($storage)->add($this->upload('just some text, no image header'), metadata: ['k' => 'v']);

        Assert::same($this->capturedMetadata($storage), ['k' => 'v']);
    }

    /** The prefix read rewinds — the storage behind must see the same bytes. */
    public function theInnerStorageSeesTheWholeUpload(): void
    {
        $storage = $this->inner();
        $body = Png::header(2, 3) . 'body-bytes';

        $this->decorated($storage)->add($this->upload($body));

        $call = Understudy::lastCall(static fn(): File => $storage->add(Arg::any(), Arg::any()));
        $upload = $call?->args[0];

        Assert::instanceOf($upload, Upload::class);
        // No rewind here: the decorator must leave the stream where it found
        // it, or the storage behind reads from wherever the prefix read
        // stopped. getContents() reads from the current position.
        Assert::same($upload->stream()->getContents(), $body);
    }

    public function everythingElseDelegates(): void
    {
        $inner = $this->inner();
        when(static fn(): string => $inner->urlFor(Arg::any(), Arg::any()))->answers(
            static fn(): string => 'https://example.test/f',
        );
        when(static fn(): string => $inner->temporaryUrl(Arg::any(), Arg::any()))->answers(
            static fn(): string => 'https://example.test/t',
        );
        when(static fn(): string => $inner->url(Arg::any()))->answers(
            static fn(): string => 'https://example.test/u',
        );
        when(static fn(): bool => $inner->exists(Arg::any()))->answers(static fn(): bool => true);
        when(static fn(): bool => $inner->remove(Arg::any()))->answers(static fn(): bool => true);
        when(static fn(): string => $inner->content(Arg::any()))->answers(static fn(): string => 'x');
        $decorated = $this->decorated($inner);

        Assert::null($decorated->find('file-1'));
        Assert::same($decorated->urlFor(Fixtures::file()), 'https://example.test/f');
        Assert::null($decorated->stream(Fixtures::file()));
        Assert::same($decorated->temporaryUrl(Fixtures::file(), new DateTimeImmutable()), 'https://example.test/t');
        Assert::same($decorated->url(Fixtures::file()), 'https://example.test/u');
        Assert::true($decorated->exists(Fixtures::file()));
        Assert::true($decorated->remove('file-1'));
        Assert::same($decorated->content(Fixtures::file()), 'x');
    }

    private function decorated(StorageInterface $inner): ImageMetadataStorage
    {
        return new ImageMetadataStorage($inner);
    }

    /**
     * @return array<string, mixed>
     */
    private function capturedMetadata(StorageInterface $inner): array
    {
        return Understudy::lastCall(static fn(): File => $inner->add(Arg::any(), Arg::any()))?->args[4] ?? [];
    }

    private function inner(): StorageInterface
    {
        $inner = Understudy::for(StorageInterface::class);
        when(static fn(): File => $inner->add(Arg::any(), Arg::any()))->answers(
            static fn($call): File => File::create(
                id: 'file-1',
                storeName: 'memory',
                groupName: 'common',
                relativePath: 'common/ab/cd/key/original.bin',
                originalName: 'a.png',
                size: 1,
                createdAt: Fixtures::now(),
                updatedAt: Fixtures::now(),
                metadata: $call->args[4],
            ),
        );

        return $inner;
    }

    private function upload(string $body): Upload
    {
        return Upload::fromStream(Fixtures::factory()->createStream($body), 'a.png', Fixtures::factory());
    }
}
