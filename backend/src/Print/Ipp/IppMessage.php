<?php

namespace App\Print\Ipp;

/**
 * Binary encoding of IPP/2.0 requests and decoding of responses (RFC 8010), limited to what Rocket Print uses:
 * Print-Job and Get-Printer-Attributes.
 */
final class IppMessage
{
    public const PRINT_JOB = 0x0002;
    public const GET_PRINTER_ATTRIBUTES = 0x000B;

    // Delimiters
    public const OPERATION_ATTRIBUTES = 0x01;
    public const JOB_ATTRIBUTES = 0x02;
    private const END = 0x03;
    public const PRINTER_ATTRIBUTES = 0x04;

    // Value tags
    public const INTEGER = 0x21;
    public const BOOLEAN = 0x22;
    public const ENUM = 0x23;
    public const TEXT = 0x41;
    public const NAME = 0x42;
    public const KEYWORD = 0x44;
    public const URI = 0x45;
    public const CHARSET = 0x47;
    public const LANGUAGE = 0x48;
    public const MIME_TYPE = 0x49;

    /**
     * @param array<int, list<array{0: int, 1: string, 2: int|bool|string|list<int|bool|string>}>> $groups delimiter => [tag, name, value(s)]
     */
    public static function request(int $operation, int $requestId, array $groups, string $data = ''): string
    {
        $out = pack('CCnN', 2, 0, $operation, $requestId);
        foreach ($groups as $delimiter => $attributes) {
            $out .= \chr($delimiter);
            foreach ($attributes as [$tag, $name, $values]) {
                foreach (array_values(\is_array($values) ? $values : [$values]) as $i => $value) {
                    $encoded = match ($tag) {
                        self::INTEGER, self::ENUM => pack('N', (int) $value),
                        self::BOOLEAN => \chr($value ? 1 : 0),
                        default => (string) $value,
                    };
                    // Additional values of a set: empty name.
                    $attributeName = 0 === $i ? $name : '';
                    $out .= \chr($tag).pack('n', \strlen($attributeName)).$attributeName.pack('n', \strlen($encoded)).$encoded;
                }
            }
        }

        return $out.\chr(self::END).$data;
    }

    /** Operation attributes every request starts with. */
    public static function operationAttributes(string $printerUri, string $user): array
    {
        return [
            [self::CHARSET, 'attributes-charset', 'utf-8'],
            [self::LANGUAGE, 'attributes-natural-language', 'fr'],
            [self::URI, 'printer-uri', $printerUri],
            [self::NAME, 'requesting-user-name', mb_substr($user, 0, 255)],
        ];
    }

    /**
     * @return array{status: int, requestId: int, attributes: array<string, list<int|bool|string>>} attributes of every group, by name
     */
    public static function parse(string $response): array
    {
        if (\strlen($response) < 9) {
            throw new \UnexpectedValueException('Truncated IPP response.');
        }
        ['status' => $status, 'id' => $requestId] = unpack('Cmajor/Cminor/nstatus/Nid', $response);
        $attributes = [];
        $offset = 8;
        $current = null;
        $length = \strlen($response);
        while ($offset < $length) {
            $tag = \ord($response[$offset++]);
            if (self::END === $tag) {
                break;
            }
            if ($tag < 0x10) {
                continue; // start of another group
            }
            if ($offset + 2 > $length) {
                throw new \UnexpectedValueException('Truncated IPP attribute.');
            }
            $nameLength = unpack('n', $response, $offset)[1];
            $name = substr($response, $offset + 2, $nameLength);
            $offset += 2 + $nameLength;
            if ($offset + 2 > $length) {
                throw new \UnexpectedValueException('Truncated IPP attribute.');
            }
            $valueLength = unpack('n', $response, $offset)[1];
            $raw = substr($response, $offset + 2, $valueLength);
            $offset += 2 + $valueLength;
            if ('' !== $name) {
                $current = $name;
            }
            if (null === $current) {
                continue;
            }
            $attributes[$current][] = match ($tag) {
                self::INTEGER, self::ENUM => 4 === \strlen($raw) ? unpack('N', $raw)[1] - (($raw[0] >= "\x80") ? 0x100000000 : 0) : 0,
                self::BOOLEAN => "\x01" === $raw,
                default => $raw,
            };
        }

        return ['status' => $status, 'requestId' => $requestId, 'attributes' => $attributes];
    }

    public static function isSuccess(int $status): bool
    {
        return $status < 0x0100;
    }

    /** Human-readable status code (RFC 8011, section 13.1). */
    public static function statusName(int $status): string
    {
        return match ($status) {
            0x0400 => 'client-error-bad-request',
            0x0401 => 'client-error-forbidden',
            0x0402 => 'client-error-not-authenticated',
            0x0403 => 'client-error-not-authorized',
            0x0404 => 'client-error-not-possible',
            0x0405 => 'client-error-timeout',
            0x0406 => 'client-error-not-found',
            0x040A => 'client-error-document-format-not-supported',
            0x040B => 'client-error-attributes-or-values-not-supported',
            0x0500 => 'server-error-internal-error',
            0x0501 => 'server-error-operation-not-supported',
            0x0503 => 'server-error-version-not-supported',
            0x0506 => 'server-error-not-accepting-jobs',
            0x0507 => 'server-error-busy',
            0x0508 => 'server-error-job-canceled',
            default => \sprintf('0x%04X', $status),
        };
    }

    /** Server errors are worth another attempt, client errors are not (except a timeout). */
    public static function isRetryable(int $status): bool
    {
        return $status >= 0x0500 || 0x0405 === $status;
    }
}
