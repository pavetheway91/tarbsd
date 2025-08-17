<?php
/**
 * Quick fix to allow Symfony HTTP Client
 * to work without filter extension.
 * 
 * I might make this "polyfill" an actual
 * library at some point.
 */

if (!defined('INPUT_POST'))
{
    define('INPUT_POST', 0);
}
if (!defined('INPUT_GET'))
{
    define('INPUT_GET', 1);
}
if (!defined('INPUT_COOKIE'))
{
    define('INPUT_COOKIE', 2);
}
if (!defined('INPUT_ENV'))
{
    define('INPUT_ENV', 4);
}
if (!defined('INPUT_SERVER'))
{
    define('INPUT_SERVER', 5);
}
if (!defined('FILTER_FLAG_NONE'))
{
    define('FILTER_FLAG_NONE', 0);
}
if (!defined('FILTER_REQUIRE_SCALAR'))
{
    define('FILTER_REQUIRE_SCALAR', 33554432);
}
if (!defined('FILTER_REQUIRE_ARRAY'))
{
    define('FILTER_REQUIRE_ARRAY', 16777216);
}
if (!defined('FILTER_FORCE_ARRAY'))
{
    define('FILTER_FORCE_ARRAY', 67108864);
}
if (!defined('FILTER_NULL_ON_FAILURE'))
{
    define('FILTER_NULL_ON_FAILURE', 134217728);
}
if (!defined('FILTER_VALIDATE_INT'))
{
    define('FILTER_VALIDATE_INT', 257);
}
if (!defined('FILTER_VALIDATE_BOOLEAN'))
{
    define('FILTER_VALIDATE_BOOLEAN', 258);
}
if (!defined('FILTER_VALIDATE_BOOL'))
{
    define('FILTER_VALIDATE_BOOL', 258);
}
if (!defined('FILTER_VALIDATE_FLOAT'))
{
    define('FILTER_VALIDATE_FLOAT', 259);
}
if (!defined('FILTER_VALIDATE_REGEXP'))
{
    define('FILTER_VALIDATE_REGEXP', 272);
}
if (!defined('FILTER_VALIDATE_DOMAIN'))
{
    define('FILTER_VALIDATE_DOMAIN', 277);
}
if (!defined('FILTER_VALIDATE_URL'))
{
    define('FILTER_VALIDATE_URL', 273);
}
if (!defined('FILTER_VALIDATE_EMAIL'))
{
    define('FILTER_VALIDATE_EMAIL', 274);
}
if (!defined('FILTER_VALIDATE_IP'))
{
    define('FILTER_VALIDATE_IP', 275);
}
if (!defined('FILTER_VALIDATE_MAC'))
{
    define('FILTER_VALIDATE_MAC', 276);
}
if (!defined('FILTER_DEFAULT'))
{
    define('FILTER_DEFAULT', 516);
}
if (!defined('FILTER_UNSAFE_RAW'))
{
    define('FILTER_UNSAFE_RAW', 516);
}
if (!defined('FILTER_SANITIZE_STRING'))
{
    define('FILTER_SANITIZE_STRING', 513);
}
if (!defined('FILTER_SANITIZE_STRIPPED'))
{
    define('FILTER_SANITIZE_STRIPPED', 513);
}
if (!defined('FILTER_SANITIZE_ENCODED'))
{
    define('FILTER_SANITIZE_ENCODED', 514);
}
if (!defined('FILTER_SANITIZE_SPECIAL_CHARS'))
{
    define('FILTER_SANITIZE_SPECIAL_CHARS', 515);
}
if (!defined('FILTER_SANITIZE_FULL_SPECIAL_CHARS'))
{
    define('FILTER_SANITIZE_FULL_SPECIAL_CHARS', 522);
}
if (!defined('FILTER_SANITIZE_EMAIL'))
{
    define('FILTER_SANITIZE_EMAIL', 517);
}
if (!defined('FILTER_SANITIZE_URL'))
{
    define('FILTER_SANITIZE_URL', 518);
}
if (!defined('FILTER_SANITIZE_NUMBER_INT'))
{
    define('FILTER_SANITIZE_NUMBER_INT', 519);
}
if (!defined('FILTER_SANITIZE_NUMBER_FLOAT'))
{
    define('FILTER_SANITIZE_NUMBER_FLOAT', 520);
}
if (!defined('FILTER_SANITIZE_ADD_SLASHES'))
{
    define('FILTER_SANITIZE_ADD_SLASHES', 523);
}
if (!defined('FILTER_CALLBACK'))
{
    define('FILTER_CALLBACK', 1024);
}
if (!defined('FILTER_FLAG_ALLOW_OCTAL'))
{
    define('FILTER_FLAG_ALLOW_OCTAL', 1);
}
if (!defined('FILTER_FLAG_ALLOW_HEX'))
{
    define('FILTER_FLAG_ALLOW_HEX', 2);
}
if (!defined('FILTER_FLAG_STRIP_LOW'))
{
    define('FILTER_FLAG_STRIP_LOW', 4);
}
if (!defined('FILTER_FLAG_STRIP_HIGH'))
{
    define('FILTER_FLAG_STRIP_HIGH', 8);
}
if (!defined('FILTER_FLAG_STRIP_BACKTICK'))
{
    define('FILTER_FLAG_STRIP_BACKTICK', 512);
}
if (!defined('FILTER_FLAG_ENCODE_LOW'))
{
    define('FILTER_FLAG_ENCODE_LOW', 16);
}
if (!defined('FILTER_FLAG_ENCODE_HIGH'))
{
    define('FILTER_FLAG_ENCODE_HIGH', 32);
}
if (!defined('FILTER_FLAG_ENCODE_AMP'))
{
    define('FILTER_FLAG_ENCODE_AMP', 64);
}
if (!defined('FILTER_FLAG_NO_ENCODE_QUOTES'))
{
    define('FILTER_FLAG_NO_ENCODE_QUOTES', 128);
}
if (!defined('FILTER_FLAG_EMPTY_STRING_NULL'))
{
    define('FILTER_FLAG_EMPTY_STRING_NULL', 256);
}
if (!defined('FILTER_FLAG_ALLOW_FRACTION'))
{
    define('FILTER_FLAG_ALLOW_FRACTION', 4096);
}
if (!defined('FILTER_FLAG_ALLOW_THOUSAND'))
{
    define('FILTER_FLAG_ALLOW_THOUSAND', 8192);
}
if (!defined('FILTER_FLAG_ALLOW_SCIENTIFIC'))
{
    define('FILTER_FLAG_ALLOW_SCIENTIFIC', 16384);
}
if (!defined('FILTER_FLAG_PATH_REQUIRED'))
{
    define('FILTER_FLAG_PATH_REQUIRED', 262144);
}
if (!defined('FILTER_FLAG_QUERY_REQUIRED'))
{
    define('FILTER_FLAG_QUERY_REQUIRED', 524288);
}
if (!defined('FILTER_FLAG_IPV4'))
{
    define('FILTER_FLAG_IPV4', 1048576);
}
if (!defined('FILTER_FLAG_IPV6'))
{
    define('FILTER_FLAG_IPV6', 2097152);
}
if (!defined('FILTER_FLAG_NO_RES_RANGE'))
{
    define('FILTER_FLAG_NO_RES_RANGE', 4194304);
}
if (!defined('FILTER_FLAG_NO_PRIV_RANGE'))
{
    define('FILTER_FLAG_NO_PRIV_RANGE', 8388608);
}
if (!defined('FILTER_FLAG_GLOBAL_RANGE'))
{
    define('FILTER_FLAG_GLOBAL_RANGE', 268435456);
}
if (!defined('FILTER_FLAG_HOSTNAME'))
{
    define('FILTER_FLAG_HOSTNAME', 1048576);
}
if (!defined('FILTER_FLAG_EMAIL_UNICODE'))
{
    define('FILTER_FLAG_EMAIL_UNICODE', 1048576);
}

if (!function_exists('filter_var'))
{
    function filter_var(mixed $value, int $filter = FILTER_DEFAULT, int $options = 0) : mixed
    {
        if ($filter !== FILTER_VALIDATE_IP)
        {
            throw new \Exception('not impemented!');
        }

        if (!in_array($options, [FILTER_FLAG_IPV4, FILTER_FLAG_IPV6, 0]))
        {
            throw new \Exception('not impemented!');
        }

        if (!is_string($value))
        {
            return false;
        }

        if (in_array($options, [FILTER_FLAG_IPV4, 0]) && is_int(ip2long($value)))
        {
            return $value;
        }

        if (in_array($options, [FILTER_FLAG_IPV6, 0]) && preg_match(
            '/^(([0-9a-fA-F]{1,4}:){7,7}[0-9a-fA-F]{1,4}|([0-9a-fA-F]{1,4}:){1,7}:|([0-9a-fA-F]{1,4}:){1,6}:[0-9a-fA-F]{1,4}|([0-9a-fA-F]{1,4}:){1,5}(:[0-9a-fA-F]{1,4}){1,2}|([0-9a-fA-F]{1,4}:){1,4}(:[0-9a-fA-F]{1,4}){1,3}|([0-9a-fA-F]{1,4}:){1,3}(:[0-9a-fA-F]{1,4}){1,4}|([0-9a-fA-F]{1,4}:){1,2}(:[0-9a-fA-F]{1,4}){1,5}|[0-9a-fA-F]{1,4}:((:[0-9a-fA-F]{1,4}){1,6})|:((:[0-9a-fA-F]{1,4}){1,7}|:)|fe80:(:[0-9a-fA-F]{0,4}){0,4}%[0-9a-zA-Z]{1,}|::(ffff(:0{1,4}){0,1}:){0,1}((25[0-5]|(2[0-4]|1{0,1}[0-9]){0,1}[0-9])\\.){3,3}(25[0-5]|(2[0-4]|1{0,1}[0-9]){0,1}[0-9])|([0-9a-fA-F]{1,4}:){1,4}:((25[0-5]|(2[0-4]|1{0,1}[0-9]){0,1}[0-9])\\.){3,3}(25[0-5]|(2[0-4]|1{0,1}[0-9]){0,1}[0-9]))$/',
            $value
        )) {
            return $value;
        }

        return false;
    }
}
