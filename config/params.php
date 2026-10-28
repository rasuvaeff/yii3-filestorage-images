<?php

declare(strict_types=1);

use Rasuvaeff\Yii3FilestorageImages\Command\WarmCommand;

return [
    'rasuvaeff/yii3-filestorage-images' => [
        // Preset name => options. The whitelist of every derivative this
        // installation can produce — a name not in here is a 404, never a
        // processor invocation.
        //
        // 'thumb' => [
        //     'version' => 1,          // bump when changing any option below
        //     'width' => 200,          // at least one of width/height
        //     'height' => 200,
        //     'fit' => 'cover',        // 'contain' (default) or 'cover'
        //     'format' => 'webp',      // jpeg | png | webp | gif | avif
        //     'quality' => 82,         // 1-100
        //     'upscale' => false,      // enlarge sources smaller than the box
        //     'maxBytes' => 0,         // cap on the encoded output; 0 = none
        // ],
        'presets' => [],
        'defaultUrlTtl' => 'PT1H',
        'lockTimeout' => 30,
        'maxSourceBytes' => 67_108_864,
        'maxSourcePixels' => 40_000_000,
        'svg' => [
            'maxBytes' => 2_097_152,
            'maxElements' => 10_000,
        ],
    ],
    'yiisoft/yii-console' => [
        'commands' => [
            'filestorage:warm' => WarmCommand::class,
        ],
    ],
];
