<?php declare(strict_types=1);
namespace TarBSD\Util;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use TarBSD\App;

use DateTimeImmutable;

class UpdateUtil
{
    const REPO = 'pavetheway91/tarbsd';

    const PUB_KEY_EC = <<<PEM
-----BEGIN PUBLIC KEY-----
MHYwEAYHKoZIzj0CAQYFK4EEACIDYgAEqmhBm7R/7/DWZS86Z9YIOy9VMEmai7pD
HpzlkL8TRap+jCxPX9GIXEueNz6PXUY/rV0lY5nis1ZWInteYwIYnjC8eXVV4WAp
CmPnnm1exuq4iHWn0MdVpnNE1WLGAO9P
-----END PUBLIC KEY-----
PEM;

    public static function validateEC(string $phar, string $sig) : bool
    {
        $pubKey = openssl_get_publickey(self::PUB_KEY_EC);

        return 1 === openssl_verify(
            file_get_contents($phar),
            base64_decode($sig),
            $pubKey
        );
    }

    public static function getLatest(
        HttpClientInterface $client,
        bool $preRelease
    ) : ?array {

        $res = $client->request(
            'GET',
            TARBSD_GITHUB_API . '/repos/' . self::REPO . '/releases?per_page=20',
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

            if (
                $pub >= $currentBuildDate
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
