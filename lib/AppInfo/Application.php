<?php

namespace OCA\MediaDownloader\AppInfo;

use OCA\MediaDownloader\Aria2\Aria2;
use OCA\MediaDownloader\Cloud\GDriveResolver;
use OCA\MediaDownloader\Http\Client;
use OCA\MediaDownloader\Tools\Helper;
use OCA\MediaDownloader\Db\Settings;
use OCA\MediaDownloader\Ytdl\Ytdl;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use Symfony\Component\DomCrawler\Crawler;
use Psr\Container\ContainerInterface;

class Application extends App implements IBootstrap
{
    public function __construct(array $urlParams = array())
    {
        parent::__construct('mediadownloader', $urlParams);
    }
    public function register(IRegistrationContext $context): void
    {
        $context->registerService(Client::class, function () {
            $options = [
                'ipv4' => true,
            ];
            return Client::create($options);
        });
        $context->registerService(Crawler::class, function () {
            return new Crawler();
        });
        $sites = Helper::getSearchSites();
        foreach ($sites as $site) {
            //fully qualified class name
            $className = $site['class'];
            $context->registerService($className, function (ContainerInterface $container) use ($className) {
                $crawler = $container->get(Crawler::class);
                $client = $container->get(Client::class);
                return $className::create($crawler, $client);
            });
        }
    }

    public function boot(IBootContext $c): void
    {
        $user = Helper::getUser();
        $uid = ($user) ? $user->getUID() : '';
        $context = $c->getAppContainer();

        // Auto-setup: download bundled binaries on first boot if missing
        $this->autoSetupBinaries();

        $context->registerService(Aria2::class, function (ContainerInterface $c) use ($uid) {
            $config = Helper::getAria2Config($uid);
            return new Aria2($config);
        });
        $context->registerService(Ytdl::class, function (ContainerInterface $c) use ($uid) {
            $config = Helper::getYtdlConfig($uid);
            return new Ytdl($config);
        });
        $context->registerService(GDriveResolver::class, function (ContainerInterface $c) {
            return new GDriveResolver();
        });

        $context->registerService(Settings::class, function (ContainerInterface $c) use ($uid) {
            return new Settings($uid);
        });
        $context->registerService('uid', function (ContainerInterface $c) use ($uid) {
            return $uid;
        });
        //$context->injectFn([$this, 'registerSearchProviders']);
    }

    private function autoSetupBinaries(): void
    {
        $binDir = __DIR__ . '/../../bin';
        if (!is_dir($binDir)) {
            @mkdir($binDir, 0755, true);
        }
        if (!is_dir($binDir) || !is_writable($binDir)) {
            return; // Can't write — skip auto-setup (binaries may already be in system PATH)
        }

        // Download aria2c bundled binary if not present
        $aria2Bin = $binDir . '/aria2c';
        $ytdlBin = $binDir . '/yt-dlp';

        if (!@is_file($aria2Bin) && !Helper::findBinaryPath('aria2c')) {
            try {
                Helper::Download('https://github.com/shiningw/ncdownloader-bin/raw/master/aria2c', $aria2Bin);
                chmod($aria2Bin, 0744);
            } catch (\Exception $e) {
                // Silent fail — user will see "not installed" in UI
            }
        }

        // Download yt-dlp bundled binary if not present
        if (!@is_file($ytdlBin) && !Helper::findBinaryPath('yt-dlp')) {
            try {
                Helper::Download('https://github.com/shiningw/ncdownloader-bin/raw/master/yt-dlp', $ytdlBin);
                chmod($ytdlBin, 0744);
            } catch (\Exception $e) {
                // Silent fail
            }
        }
    }
}
