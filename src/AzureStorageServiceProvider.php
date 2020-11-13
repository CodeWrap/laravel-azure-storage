<?php

namespace Matthewbdaly\LaravelAzureStorage;

use Illuminate\Filesystem\Cache;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;
use League\Flysystem\Cached\CachedAdapter;
use League\Flysystem\Cached\Storage\Memory as MemoryStore;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use MicrosoftAzure\Storage\Common\Middlewares\RetryMiddleware;
use MicrosoftAzure\Storage\Common\Middlewares\RetryMiddlewareFactory;

use RuntimeException;
use Throwable;

/**
 * Service provider for Azure Blob Storage
 */
final class AzureStorageServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        Storage::extend('azure', function ($app, $config) {
            $client = $app->make(BlobRestProxy::class, $config);
            $adapter = new AzureBlobStorageAdapter(
                $client,
                $config['container'],
                $config['key'] ?? null,
                $config['url'] ?? null,
                $config['prefix'] ?? null
            );

            $cache = Arr::pull($config, 'cache');
            if ($cache) {
                try {
                    class_exists(CachedAdapter::class);
                } catch (Throwable $e) {
                    throw new RuntimeException("Caching requires the league/flysystem-cached-adapter to be installed.");
                }

                $adapter = new CachedAdapter($adapter, $this->createCacheStore($cache));
            }

            return new Filesystem($adapter, $config);
        });
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->bind(BlobRestProxy::class, function ($app, $config) {
            $config = empty($config) ? $app->make('config')->get('filesystems.disks.azure') : $config;

            if (array_key_exists('sasToken', $config)) {
                $endpoint = sprintf(
                    'BlobEndpoint=%s;SharedAccessSignature=%s;',
                    $config['endpoint'],
                    $config['sasToken']
                );
            } else {
                $endpoint = sprintf(
                    'DefaultEndpointsProtocol=https;AccountName=%s;AccountKey=%s;',
                    $config['name'],
                    $config['key']
                );
                if (isset($config['endpoint'])) {
                    $endpoint .= sprintf("BlobEndpoint=%s;", $config['endpoint']);
                }
            }

            $blobOptions = [];
            $retry = data_get($config, 'retry');
            if (isset($retry)) {
                $blobOptions = [
                    'middlewares' => [
                        $this->createRetryMiddleware($retry)
                    ]
                ];
            }

            return BlobRestProxy::createBlobService($endpoint, $blobOptions);
        });
    }

    /**
     * Create a cache store instance.
     *
     * @param  mixed  $config
     * @return \League\Flysystem\Cached\CacheInterface
     *
     * @throws \InvalidArgumentException
     */
    protected function createCacheStore($config)
    {
        if ($config === true) {
            return new MemoryStore;
        }

        return new Cache(
            $this->app['cache']->store($config['store']),
            $config['prefix'] ?? 'flysystem',
            $config['expire'] ?? null
        );
    }

    /**
     * Create retry middleware instance.
     *
     * @param  array $config
     * @return RetryMiddleware
     */
    protected function createRetryMiddleware($config)
    {
        return RetryMiddlewareFactory::create(
            RetryMiddlewareFactory::GENERAL_RETRY_TYPE,
            $config['tries'] ?? 3,
            $config['interval'] ?? 1000,
            $config['increase'] === 'exponential' ?
                RetryMiddlewareFactory::EXPONENTIAL_INTERVAL_ACCUMULATION :
                RetryMiddlewareFactory::LINEAR_INTERVAL_ACCUMULATION,
            true  // Whether to retry connection failures too, default false
        );
    }
}
