<?php declare(strict_types=1);
namespace TarBSD\Util;

class SignatureChecker
{
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
}
