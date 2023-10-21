<?php

declare(strict_types=1);

namespace filesystem;

use Aws\S3\S3Client;
use Aws\S3\S3ClientInterface;
use Cot;
use Google\Cloud\Storage\StorageClient;
use filesystem\exceptions\InvalidConfigurationException;
use filesystem\exceptions\UnknownFilesystemException;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\AzureBlobStorage\AzureBlobStorageAdapter;
use League\Flysystem\Ftp\FtpAdapter;
use League\Flysystem\Ftp\FtpConnectionOptions;
use League\Flysystem\GoogleCloudStorage\GoogleCloudStorageAdapter;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\PathPrefixing\PathPrefixedAdapter;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use League\Flysystem\WebDAV\WebDAVAdapter;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use PlatformCommunity\Flysystem\BunnyCDN\BunnyCDNAdapter;
use PlatformCommunity\Flysystem\BunnyCDN\BunnyCDNClient;
use PlatformCommunity\Flysystem\BunnyCDN\BunnyCDNRegion;


class FilesystemFactory
{
    /**
     * @var array<string, FilesystemOperator|LocalFilesystem>
     */
    protected static array $storages = [];

    /**
     * // File systems configuration example:
     * $cfg['filesystem'] = [
     *   'Yandex.Cloud' => [
     *      'adapter' => '\League\Flysystem\AwsS3V3\AwsS3V3Adapter',
     *      'config' => [
     *         'bucket' => 'my-bucket-name',
     *         'endpoint' => 'https://storage.yandexcloud.net',
     *         'region' => 'ru-central1',
     *         'accessKey' => 'MyAccessKey',
     *         'secretKey' => 'MySecretKey',
     *         'pathPrefix' => 'a/path/prefix',
     *      ],
     *    ]
     * ];
     *
     * @param string $fileSystemName Filesystem config name
     * @param string $pathPrefix Path prefix for filesystem
     * @return FilesystemOperator|LocalFilesystem
     */
    public static function getFilesystem(string $fileSystemName = 'local', string $pathPrefix = 'datas')
    {
        $storageKey = $fileSystemName . '-' . $pathPrefix;

        if (isset(static::$storages[$storageKey])) {
            return static::$storages[$storageKey];
        }

        $fileSystem = null;

        /* === Hook === */
        foreach (cot_getextplugins('filesystem.getFilesystem') as $pl) {
            include $pl;
        }
        /* ============ */

        if (!empty($fileSystem)) {
            static::$storages[$storageKey] = $fileSystem;
            return static::$storages[$storageKey];
        }

        if (function_exists('cot_getFilesystem')) {
            $result = cot_getFilesystem($fileSystemName, $pathPrefix);
            if ($result !== null) {
                static::$storages[$storageKey] = $result;
                return static::$storages[$storageKey];
            }
        }

        if ($fileSystemName === 'default' && empty(Cot::$cfg['filesystem']['default'])) {
            $fileSystemName = 'local';
            $storageKey = $fileSystemName . '-' . $pathPrefix;
            if (isset(static::$storages[$storageKey])) {
                return static::$storages[$storageKey];
            }
        }

        if ($fileSystemName === 'local') {
            static::$storages[$storageKey] = new LocalFilesystem($pathPrefix);
            return static::$storages[$storageKey];
        }

        if (empty(Cot::$cfg['filesystem'][$fileSystemName])) {
            throw new InvalidConfigurationException("Config for filesystem '{$fileSystemName}' is missing");
        }
        if (empty(Cot::$cfg['filesystem'][$fileSystemName]['adapter'])) {
            throw new InvalidConfigurationException("Adapter for filesystem '{$fileSystemName}' is missing");
        }

        $adapterName = Cot::$cfg['filesystem'][$fileSystemName]['adapter'];
        switch ($adapterName) {
            case 'Aws':
            case 'AwsS3':
            case '\League\Flysystem\AwsS3V3\AwsS3V3Adapter':
                static::$storages[$storageKey] = static::getAwsFileSystem(Cot::$cfg['filesystem'][$fileSystemName]['config'], $pathPrefix);
                break;

            case 'Azure':
            case '\League\Flysystem\AzureBlobStorage\AzureBlobStorageAdapter':
                static::$storages[$storageKey] = static::getAzureFileSystem(Cot::$cfg['filesystem'][$fileSystemName]['config'], $pathPrefix);
                break;

            case 'BunnyCDN':
            case '\PlatformCommunity\Flysystem\BunnyCDN\BunnyCDNAdapter':
                static::$storages[$storageKey] = static::getBunnyCDNFileSystem(Cot::$cfg['filesystem'][$fileSystemName]['config'], $pathPrefix);
                break;

            case 'GoogleCloud':
            case '\League\Flysystem\GoogleCloudStorage\GoogleCloudStorageAdapter':
                static::$storages[$storageKey] = static::getGoogleCloudFileSystem(Cot::$cfg['filesystem'][$fileSystemName]['config'], $pathPrefix);
                break;

            case 'FTP':
            case '\League\Flysystem\Ftp\FtpAdapter':
                static::$storages[$storageKey] = static::getFTPFileSystem(Cot::$cfg['filesystem'][$fileSystemName]['config'], $pathPrefix);
                break;

            case 'SFTP':
            case '\League\Flysystem\PhpseclibV3\SftpAdapter':
                static::$storages[$storageKey] = static::getSFTPFileSystem(Cot::$cfg['filesystem'][$fileSystemName]['config'], $pathPrefix);
                break;

            case 'WebDAV':
            case 'League\Flysystem\WebDAV\WebDAVAdapter':
                static::$storages[$storageKey] = static::getWebDAVFileSystem(Cot::$cfg['filesystem'][$fileSystemName]['config'], $pathPrefix);
                break;

            default:
                throw new UnknownFilesystemException("Unknown filesystem adapter '{$adapterName}'");
        }

        return static::$storages[$storageKey];
    }

    /**
     * @param array{
     *    accessKey: string,
     *    secretKey: string,
     *    bucket: string,
     *    client?: array<string, string>,
     *    endpoint: string,
     *    region: string,
     *    version?: string,
     *    pathPrefix?: string
     * } $config
     * @return Filesystem
     * @see https://flysystem.thephpleague.com/docs/adapter/aws-s3-v3/
     * @see https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/guide_configuration.html
     */
    public static function getAwsFileSystem(array $config, string $pathPrefix = ''): Filesystem
    {
        // For some reason AWS adapter works this way only
        putenv('AWS_ACCESS_KEY_ID=' . $config['accessKey']);
        putenv('AWS_SECRET_ACCESS_KEY=' . $config['secretKey']);

        /** @var S3ClientInterface $client */
        if (isset($config['client'])) {
            $client = new S3Client($config['client']);
        } else {
            $client = new S3Client([
                'version' => $config['version'] ?? 'latest',
                'endpoint' => $config['endpoint'],
                'region' => $config['region'],
            ]);
        }

        // The internal adapter
        $adapter = new AwsS3V3Adapter(
            $client, // S3Client
            $config['bucket'], // Bucket name
            $pathPrefix
        );

        if (!empty($config['pathPrefix'])) {
            // Turn it into a path-prefixed adapter
            $adapter = new PathPrefixedAdapter($adapter, $config['pathPrefix']);
        }

        // The FilesystemOperator
        return new Filesystem($adapter);
    }

    /**
     * @param array{
     *     dsn: string,
     *     container: string,
     *     pathPrefix?: string
     *  } $config
     * @see https://flysystem.thephpleague.com/docs/adapter/azure-blob-storage/
     */
    public static function getAzureFileSystem(array $config, string $pathPrefix = ''): Filesystem
    {
        $client = BlobRestProxy::createBlobService($config['dsn']);
        $adapter = new AzureBlobStorageAdapter(
            $client,
            $config['container'],
            $pathPrefix,
        );

        if (!empty($config['pathPrefix'])) {
            // Turn it into a path-prefixed adapter
            $adapter = new PathPrefixedAdapter($adapter, $config['pathPrefix']);
        }

        // The FilesystemOperator
        return new Filesystem($adapter);
    }

    /**
     * @param array{
     *     storageZoneName: string,
     *     apiKey: string,
     *     region?: string,
     *     pathPrefix?: string,
     *     pullZoneUrl?: string
     * } $config
     *
     * @see https://blog.sinn.io/bunny-net-php-flysystem-v3/
     * @see https://github.com/PlatformCommunity/flysystem-bunnycdn
     *
     * PrefixPath is no longer supported directly. Using PathPrefixedAdapter instead.
     * @see https://flysystem.thephpleague.com/docs/adapter/path-prefixing/
     */
    public static function getBunnyCDNFileSystem(array $config, string $pathPrefix = ''): Filesystem
    {
        $adapter = new BunnyCDNAdapter(
            new BunnyCDNClient(
                $config['storageZoneName'],
                $config['apiKey'],
                $config['region'] ?? BunnyCDNRegion::FALKENSTEIN
            ),
            $config['pullZoneUrl'] ?? ''
        );

        if (!empty($config['pathPrefix']) || !empty($pathPrefix)) {
            $path = '';
            if (!empty($config['pathPrefix'])) {
                $path .= '/' . trim($config['pathPrefix'], '/');
            }

            if (!empty($pathPrefix)) {
                $path .= '/' . trim($pathPrefix, '/');
            }

            // Turn it into a path-prefixed adapter
            $adapter = new PathPrefixedAdapter($adapter, $path);
        }

        // The FilesystemOperator
        return new Filesystem($adapter);
    }

    /**
     * @param array{
     *      client: array<string, string>,
     *      bucket: string,
     *      pathPrefix?: string,
     *  } $config
     * @see https://flysystem.thephpleague.com/docs/adapter/google-cloud-storage/
     * @see https://cloud.google.com/php/docs/reference/cloud-storage/latest/StorageClient
     */
    public static function getGoogleCloudFileSystem(array $config, string $pathPrefix = ''): Filesystem
    {
        $storageClient = new StorageClient($config['client']);
        $bucket = $storageClient->bucket($config['bucket']);

        $adapter = new GoogleCloudStorageAdapter($bucket, $pathPrefix);

        if (!empty($config['pathPrefix'])) {
            // Turn it into a path-prefixed adapter
            $adapter = new PathPrefixedAdapter($adapter, $config['pathPrefix']);
        }

        return new Filesystem($adapter);
    }

    /**
     * @param array{
     *       client: array<string, string|int|bool>,
     *       pathPrefix?: string,
     *   } $config
     * @see https://flysystem.thephpleague.com/docs/adapter/ftp/
     */
    public static function getFTPFileSystem(array $config, string $pathPrefix = ''): Filesystem
    {
        $defaultOptions = [
            //'host' => 'hostname', // required
            //'root' => '/root/path/', // required
            //'username' => 'username', // required
            //'password' => 'password', // required
            //'port' => 21, // default 21
            'ssl' => true,  // default false
            //'timeout' => 90, // default 90
            //'utf8' => false, // default false
            //'passive' => true, // default true
            //'transferMode' => FTP_BINARY,
            //'systemType' => null, // 'windows' or 'unix'
            //'ignorePassiveAddress' => null, // true or false
            //'timestampsOnUnixListingsEnabled' => false, // true or false
            //'recurseManually' => true // true
        ];

        $options = array_merge($defaultOptions, $config['client']);
        if ($pathPrefix !== '') {
            $separator = $options['systemType'] === 'windows' ? '\\' : '/';
            $options['root'] = rtrim($options['root'], '\\/') . $separator . trim($pathPrefix, '\\/');
        }

        // The internal adapter
        $adapter = new FtpAdapter(
            // Connection options
            FtpConnectionOptions::fromArray($options)
        );

        if (!empty($config['pathPrefix'])) {
            // Turn it into a path-prefixed adapter
            $adapter = new PathPrefixedAdapter($adapter, $config['pathPrefix']);
        }

        return new Filesystem($adapter);
    }

    /**
     * @param array{
     *     host: string,
     *     username: string,
     *     password?: string,
     *     privateKey?: string,
     *     passphrase?: string,
     *     port?: int,
     *     root?: string,
     *     useAgent?: bool,
     *     timeout?: int,
     *     maxTries?: int,
     *     hostFingerprint?: string,
     *     pathPrefix?: string,
     * } $config
     * @see https://flysystem.thephpleague.com/docs/adapter/sftp-v3/
     */
    public static function getSFTPFileSystem(array $config, string $pathPrefix = ''): Filesystem
    {
        $path = $pathPrefix;
        if (!empty($config['root'])) {
            $separator = '/';
            $path = rtrim($config['root'], '/');
            if ($pathPrefix !== '') {
                $path .= $separator . trim($pathPrefix, '/');
            }
        }

        $adapter = new SftpAdapter(
            new SftpConnectionProvider(
                $config['host'], // host (required)
                $config['username'], // username (required)
                $config['password'] ?? null, // password (optional, default: null) set to null if privateKey is used
                $config['privateKey'] ?? null, // private key (optional, default: null) can be used instead of password, set to null if password is set
                $config['passphrase'] ?? null, // passphrase (optional, default: null), set to null if privateKey is not used or has no passphrase
                $config['port'] ?? 22, // port (optional, default: 22)
                $config['useAgent'] ?? false, // use agent (optional, default: false)
                $config['timeout'] ?? 10, // timeout (optional, default: 10)
                $config['maxTries'] ?? 4, // max tries (optional, default: 4)
                $config['hostFingerprint'] ?? null, // host fingerprint (optional, default: null),
                null, // connectivity checker (must be an implementation of 'League\Flysystem\PhpseclibV2\ConnectivityChecker' to check if a connection can be established (optional, omit if you don't need some special handling for setting reliable connections)
            ),
            $path // root path (required)
        );

        if (!empty($config['pathPrefix'])) {
            // Turn it into a path-prefixed adapter
            $adapter = new PathPrefixedAdapter($adapter, $config['pathPrefix']);
        }

        return new Filesystem($adapter);
    }

    /**
     * @param array{
     *     client: array<string, string|int|bool>,
     *     pathPrefix?: string,
     * } $config
     * @see https://flysystem.thephpleague.com/docs/adapter/webdav/
     */
    public static function getWebDAVFileSystem(array $config, string $pathPrefix = ''): Filesystem
    {
        $client = new \Sabre\DAV\Client($config['client']);
        $adapter = new WebDAVAdapter($client, $pathPrefix);

        if (!empty($config['pathPrefix'])) {
            // Turn it into a path-prefixed adapter
            $adapter = new PathPrefixedAdapter($adapter, $config['pathPrefix']);
        }

        return new Filesystem($adapter);
    }
}