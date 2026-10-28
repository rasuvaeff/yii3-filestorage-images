<?php

declare(strict_types=1);

use Psr\Clock\ClockInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicyRegistry;
use Rasuvaeff\Yii3Filestorage\Repository\MaintenanceRepositoryInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreRegistry;
use Rasuvaeff\Yii3FilestorageImages\Command\WarmCommand;
use Rasuvaeff\Yii3FilestorageImages\Derivatives;
use Rasuvaeff\Yii3FilestorageImages\PresetRegistry;
use Rasuvaeff\Yii3FilestorageImages\Processing\CompositeImageProcessor;
use Rasuvaeff\Yii3FilestorageImages\Processing\ImageProcessorInterface;
use Rasuvaeff\Yii3FilestorageImages\Svg\EnshrinedSvgSanitizer;
use Rasuvaeff\Yii3FilestorageImages\Svg\SvgSanitizerInterface;
use Yiisoft\Mutex\MutexFactoryInterface;

/** @var array $params */

// This package binds only its own services. It deliberately does NOT bind
// MutexFactoryInterface: the generation lock needs a driver that matches the
// deployment (yiisoft/mutex-file on one host, -db or -redis across a fleet),
// and that choice belongs to the application — exactly like StoreInterface
// belongs to a storage backend.
return [
    PresetRegistry::class => static fn (): PresetRegistry => PresetRegistry::fromArray(
        $params['rasuvaeff/yii3-filestorage-images']['presets'],
    ),

    ImageProcessorInterface::class => static fn (
        StreamFactoryInterface $streams,
    ): ImageProcessorInterface => CompositeImageProcessor::fromAvailable(
        streams: $streams,
        maxSourceBytes: $params['rasuvaeff/yii3-filestorage-images']['maxSourceBytes'],
        maxSourcePixels: $params['rasuvaeff/yii3-filestorage-images']['maxSourcePixels'],
    ),

    SvgSanitizerInterface::class => static fn (): SvgSanitizerInterface => new EnshrinedSvgSanitizer(
        maxBytes: $params['rasuvaeff/yii3-filestorage-images']['svg']['maxBytes'],
        maxElements: $params['rasuvaeff/yii3-filestorage-images']['svg']['maxElements'],
    ),

    Derivatives::class => static fn (
        StoreRegistry $stores,
        PresetRegistry $presets,
        ImageProcessorInterface $processor,
        MutexFactoryInterface $mutexes,
        DeliveryPolicyRegistry $deliveryPolicies,
        ClockInterface $clock,
    ): Derivatives => new Derivatives(
        stores: $stores,
        presets: $presets,
        processor: $processor,
        mutexes: $mutexes,
        deliveryPolicies: $deliveryPolicies,
        clock: $clock,
        defaultUrlTtl: new DateInterval($params['rasuvaeff/yii3-filestorage-images']['defaultUrlTtl']),
        lockTimeoutSeconds: $params['rasuvaeff/yii3-filestorage-images']['lockTimeout'],
        maxSourceBytes: $params['rasuvaeff/yii3-filestorage-images']['maxSourceBytes'],
    ),

    WarmCommand::class => static fn (
        Derivatives $derivatives,
        PresetRegistry $presets,
        MaintenanceRepositoryInterface $repository,
    ): WarmCommand => new WarmCommand(
        derivatives: $derivatives,
        presets: $presets,
        repository: $repository,
    ),
];
