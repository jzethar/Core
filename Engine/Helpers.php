<?php declare(strict_types = 1);

/*  Copyright (c) 2023 Nikita Zhavoronkov, nikzh@nikzh.com
 *  Copyright (c) 2023 3xpl developers, 3@3xpl.com
 *  Distributed under the MIT software license, see the accompanying file LICENSE.md  */

/*  Various useful functions  */

// Simplest debug function
function dd(mixed $output = "Things are only impossible until they are not."): never
{
    var_dump($output) & die();
}

// print_r for exceptions
function print_e(Throwable $e, $return = false): null|string
{
    $message = rmn("{$e->getMessage()} in {$e->getFile()} on line {$e->getLine()}");

    if (!$return)
    {
        echo $message . "\n";
        return null;
    }
    else
    {
        return $message;
    }
}

// Removes excess whitespaces, etc.
function rmn($string)
{
    return trim(str_replace("\n", "⏎", preg_replace('/ +/', ' ', $string)));
}

// Return current timestamp
function pg_microtime()
{
    [$usec, $sec] = explode(' ', microtime());
    $usec = number_format((float)$usec, 6);
    $usec = str_replace("0.", ".", $usec);
    return date('Y-m-d H:i:s', (int)$sec) . $usec;
}

// Bold formatting in CLI
function cli_format_bold($string)
{
    return "\033[1m{$string}\033[0m";
}

// Dimmed formatting in CLI
function cli_format_dim($string)
{
    return "\033[2m{$string}\033[0m";
}

// Background formatting in CLI
function cli_format_reverse($string)
{
    return "\033[1;7m{$string}\033[0m";
}

// Error formatting in CLI
function cli_format_error($string)
{
    return "\033[1;37;41m{$string}\033[0m";
}

// Blue background in CLI
function cli_format_blue_reverse($string)
{
    return "\033[1;30;46m{$string}\033[0m";
}

// Convert module name to class
function module_name_to_class(string $name): string
{
    return envm(module_name: $name, key: 'CLASS',
        default: new DeveloperError("module_name_to_class(name: ({$name})): unknown name (is `CLASS` defined in the config?)"));
}

// This is a stub function which supposed to look into some database of already known currencies and return
// the list of currencies that need to be processed
function check_existing_currencies(array $input, CurrencyFormat $module_currency_format): array
{
    return $input;
}

// Math conversions

function hex2dec($num)
{
    $num = strtolower($num);
    $basestr = '0123456789abcdef';
    $base = strlen($basestr);
    $dec = '0';
    $num_arr = str_split((string)$num);
    $cnt = strlen($num);

    for ($i = 0; $i < $cnt; $i++)
    {
        $pos = strpos($basestr, $num_arr[$i]);

        if ($pos === false)
        {
            throw new ErrorException(sprintf('hex2dec: Unknown character %s at offset %d', $num_arr[$i], $i));
        }

        $dec = bcadd(bcmul($dec, (string)$base), (string)$pos);
    }

    return $dec;
}

function dec2hex($number) // https://stackoverflow.com/questions/52995138/php-gives-different-hex-value-than-an-online-tool
{
    $hexvalues = ['0','1','2','3','4','5','6','7','8','9','A','B','C','D','E','F'];
    $hexval = '';

    while ($number != '0')
    {
        $hexval = $hexvalues[bcmod($number, '16')] . $hexval;
        $number = bcdiv($number, '16', 0);
    }

    return $hexval;
}

function to_int64_from_0xhex(string $value): int
{
    if (!str_starts_with($value, '0x')) throw new DeveloperError("to_int64_from_0xhex({$value}): wrong input");
    $rvalue = ltrim(substr($value, 2), '0'); // Removes 0x and excess zeroes

    if (strlen($rvalue) > 16)
        throw new MathException("to_int64_from_0xhex({$value}): would overflow int64");

    return (int)hex2dec($rvalue);
}

function to_0xhex_from_int64(int $value): string
{
    return '0x' . dechex($value);
}

function to_int256_from_0xhex(?string $value): ?string
{
    if (is_null($value)) return null;
    if (!str_starts_with($value, '0x')) throw new DeveloperError("to_int256_from_0xhex({$value}): wrong input");

    return hex2dec(substr($value, 2));
}

function to_int256_from_hex(?string $value): ?string
{
    if (is_null($value)) return null;
    if ($value === '-1') return '-1'; // Special case

    return hex2dec($value);
}

// Reordering JSON-RPC 2.0 response by id
function reorder_by_id(&$curl_results_prepared)
{
    usort($curl_results_prepared, function($a, $b) {
        return  [$a['id'],
            ]
            <=>
            [$b['id'],
            ];
    });
}

// Removing array values
function delete_array_values(array $arr, array $remove): array // https://stackoverflow.com/questions/7225070/php-array-delete-by-value-not-key
{
    return array_filter($arr, fn($e) => !in_array($e, $remove));
}

// Not showing passwords in CLI output
function remove_passwords($url)
{
    $url = parse_url($url);
    return ($url['scheme'] ?? '').'://'.($url['host'] ?? '').($url['path'] ?? '').($url['query'] ?? '');
}

class InvalidByteError {
    private $byte;

    public function __construct($byte) {
        $this->byte = $byte;
    }

    public function getByte() {
        return $this->byte;
    }

    public function __toString() {
        return "InvalidByteError: " . $this->byte;
    }
}

function decode(&$dst, $src) {
    $i = 0;
    $j = 1;
    $srcLength = count($src);
    while ($j < $srcLength) {
        $a = fromHexChar($src[$j - 1]);
        if (!$a[1]) {
            return array($i, $src[$j - 1]);
        }
        $b = fromHexChar($src[$j]);
        if (!$b[1]) {
            return array($i, $src[$j]);
        }
        $dst[$i] = ($a[0] << 4) | $b[0];
        $i++;
        $j += 2;
    }
    if ($srcLength % 2 === 1) {
        if (!fromHexChar($src[$j - 1])[1]) {
            return array($i, $src[$j - 1]);
        }
        return array($i, new ErrLengthException());
    }
    return array($i, null);
}

function fromHexChar($c) {
    if ('0' <= $c && $c <= '9') {
        return array(ord($c) - ord('0'), true);
    } elseif ('a' <= $c && $c <= 'f') {
        return array(ord($c) - ord('a') + 10, true);
    } elseif ('A' <= $c && $c <= 'F') {
        return array(ord($c) - ord('A') + 10, true);
    }

    return array(0, false);
}

class ErrLengthException extends Exception {
    public function __construct() {
        parent::__construct("encoding/hex: odd length hex string");
    }
}

function DecodeString($s) {
    $src = str_split($s);
    $n = decode($src, $src);
    return array_slice($src, 0, $n[0]);
}

function MustParseHex($hexString) {
    $hexString = str_replace("0x", "", $hexString);
    $data = DecodeString($hexString);
    if ($data === false) {
        die("Error: Invalid hex string");
    }
    return $data;
}

function BitAt($b, $idx) {
    $upperBounds = BitLen($b);
    if ($idx >= $upperBounds) {
        return false;
    }

    $i = (1 << ($idx % 8));
    if (($b[(int)($idx / 8)] & $i) === $i) {
        return 1;
    } else return 0;
}

function BitLen($b) {
    $numBytes = count($b);
    if ($numBytes === 0) {
        return 0;
    }
    // The most significant bit is present in the last byte in the array.
    $last = $b[$numBytes - 1];

    // Determine the position of the most significant bit.
    $msb = Len8($last);

    if ($msb === -1) {
        return 0;
    }

    // The absolute position of the most significant bit will be the number of
    // bits in the preceding bytes plus the position of the most significant
    // bit. Subtract this value by 1 to determine the length of the bitlist.
    return (8*(count($b)-1) + $msb - 1);
}

const len8tab = "\x00\x01\x02\x02\x03\x03\x03\x03\x04\x04\x04\x04\x04\x04\x04\x04" .
           "\x05\x05\x05\x05\x05\x05\x05\x05\x05\x05\x05\x05\x05\x05\x05\x05" .
           "\x06\x06\x06\x06\x06\x06\x06\x06\x06\x06\x06\x06\x06\x06\x06\x06" .
           "\x06\x06\x06\x06\x06\x06\x06\x06\x06\x06\x06\x06\x06\x06\x06\x06" .
           "\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07" .
           "\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07" .
           "\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07" .
           "\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07\x07" .
           "\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08" .
           "\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08" .
           "\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08" .
           "\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08" .
           "\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08" .
           "\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08" .
           "\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08" .
           "\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08\x08";

function Len8($x) {
    return ord(len8tab[$x]);
}

print_r(MustParseHex("0x010000000000000004000000000000000000000000000000000000000000000000000010"));

$test = MustParseHex("0xffdfffffeffffffd7ffffffffffffffffffffffffffffffffffffbfffdffffefffffff1e");
echo BitLen($test) . "\n";
for($i = 0; $i < BitLen($test); $i++) {
    echo BitAt($test, $i);
}
