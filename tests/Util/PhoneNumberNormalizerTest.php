<?php

declare(strict_types=1);

namespace App\Tests\Util;

use App\Util\PhoneNumberNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhoneNumberNormalizerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function internationalPhoneFormats(): iterable
    {
        yield 'leading plus' => ['+33781191499', '33781191499'];
        yield 'digits only' => ['33781191499', '33781191499'];
        yield 'international prefix' => ['0033781191499', '33781191499'];
        yield 'formatted number' => ['+33 7 81 19 14 99', '33781191499'];
    }

    #[DataProvider('internationalPhoneFormats')]
    public function testItProducesTheDigitsOnlyStorageFormat(string $input, string $expected): void
    {
        self::assertSame($expected, PhoneNumberNormalizer::normalize($input));
    }
}
