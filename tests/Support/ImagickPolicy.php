<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageImages\Tests\Support;

/**
 * Runs one `process()` call in a child PHP whose ImageMagick reads a
 * `policy.xml` forbidding one coder. ImageMagick loads its policy once, at
 * start-up, so the only way to test a forbidding policy is a fresh process.
 *
 * @internal
 */
final class ImagickPolicy
{
    /**
     * @param non-empty-string $coder ImageMagick coder the policy forbids.
     * @param non-empty-string $processor PHP expression building the processor.
     *
     * @return string The class of the exception `process()` threw, or `none`.
     */
    public static function run(string $coder, string $processor, string $mediaType, string $bytes): string
    {
        $dir = sys_get_temp_dir() . '/filestorage-images-policy-' . bin2hex(random_bytes(6));
        mkdir($dir);
        file_put_contents(
            "{$dir}/policy.xml",
            "<policymap><policy domain=\"coder\" rights=\"none\" pattern=\"{$coder}\" /></policymap>",
        );
        file_put_contents("{$dir}/source", $bytes);
        $autoload = \dirname(__DIR__, 2) . '/vendor/autoload.php';
        $script = <<<PHP
            require '{$autoload}';
            \$streams = new \\Nyholm\\Psr7\\Factory\\Psr17Factory();
            try {
                ({$processor})->process(
                    \$streams->createStream(file_get_contents('{$dir}/source')),
                    '{$mediaType}',
                    new \\Rasuvaeff\\Yii3FilestorageImages\\Preset(name: 'x', format: \\Rasuvaeff\\Yii3FilestorageImages\\Format::Png, width: 10),
                );
                echo 'none';
            } catch (\\Throwable \$e) {
                echo \$e::class;
            }
            PHP;

        try {
            $process = proc_open(
                [\PHP_BINARY, '-r', $script],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                [...getenv(), 'MAGICK_CONFIGURE_PATH' => $dir],
            );
            if ($process === false) {
                return 'proc_open failed';
            }
            $out = (string) stream_get_contents($pipes[1]);
            $err = (string) stream_get_contents($pipes[2]);
            proc_close($process);

            return $out !== '' ? $out : "no output: {$err}";
        } finally {
            @unlink("{$dir}/policy.xml");
            @unlink("{$dir}/source");
            @rmdir($dir);
        }
    }
}
