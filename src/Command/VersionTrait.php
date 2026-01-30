<?php declare(strict_types=1);
namespace TarBSD\Command;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use TarBSD\App;
use DateTimeImmutable;
use Phar;

trait VersionTrait
{
    protected function getLatest(
        HttpClientInterface $client,
        bool $preRelease
    ) : ?array {
        $res = $client->request(
            'GET',
            TARBSD_GITHUB_API . '/repos/' . SelfUpdate::REPO . '/releases?per_page=20',
            [
                'headers' => [
                    'accept' => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28'
                ],
                'timeout' => 5
            ]
        );

        if (($code = $res->getStatusCode()) !== 200)
        {
            throw new \Exception('api.github.com responded with: ' . $code);
        }

        $payload = json_decode($res->getContent(), true);
        $currentBuildDate = App::getBuildDate();
        $currentSHA256 = App::hashPhar();

        foreach($payload as $release)
        {
            $pub = new DateTimeImmutable($release['created_at']);
            $name = $release['name'];

            // some wiggle room between a tag time and a build time
            $currentBuildDate = $currentBuildDate->modify('-1 hour');

            if (
                $pub > $currentBuildDate
                && (!$release['prerelease'] || $preRelease)
            ) {
                $phar = $size = $sig = $sha256 = null;

                foreach($release['assets'] as $asset)
                {
                    if (
                        $asset['name'] === 'tarbsd'
                        && preg_match('/sha256:([a-z0-9]{64})/', $asset['digest'], $m)
                    ) {
                        $phar = $asset['browser_download_url'];
                        $size = $asset['size'];
                        $sha256 = $m[1];
                    }
                    if ($asset['name'] === 'tarbsd.sig.secp384r1')
                    {
                        $sig = $asset['browser_download_url'];
                    }
                }

                if ($phar && $sig && $sha256 !== $currentSHA256)
                {
                    return [
                        $name,
                        $phar,
                        $size,
                        $sig
                    ];
                }
            }
        }
        return null;
    }
}