<?php

namespace App\Tests\Unit;

use App\Entity\Printer;
use App\Print\Connector\IppConnector;
use App\Print\Ipp\IppMessage;
use App\Print\PrintSpooler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PrinterAddressTest extends TestCase
{
    /** @return iterable<array{string, ?array{server: string, share: string, port: int|null}}> */
    public static function sambaAddresses(): iterable
    {
        yield ['//print-server/laser', ['server' => 'print-server', 'share' => 'laser', 'port' => null]];
        yield ['smb://print-server.acme.lan:1445/HP 2e', ['server' => 'print-server.acme.lan', 'share' => 'HP 2e', 'port' => 1445]];
        yield ['\\\\PRINT\\laser\\', ['server' => 'PRINT', 'share' => 'laser', 'port' => null]];
        yield ['print-server/laser', null];
        yield ['//print-server/a/b', null];
        yield ['//print-server/laser;rm', null];
        yield ['//-oProxy/laser', ['server' => '-oProxy', 'share' => 'laser', 'port' => null]];
    }

    #[DataProvider('sambaAddresses')]
    public function testSambaAddresses(string $uri, ?array $expected): void
    {
        self::assertSame($expected, Printer::sambaTarget($uri));
    }

    public function testIppAddressesUsePort631(): void
    {
        self::assertSame('http://printer.lan:631/ipp/print', IppConnector::httpUrl('ipp://printer.lan'));
        self::assertSame('https://printer.lan:631/printers/laser', IppConnector::httpUrl('ipps://printer.lan/printers/laser'));
        self::assertSame('http://cups.lan:8631/printers/laser', IppConnector::httpUrl('ipp://cups.lan:8631/printers/laser'));
        self::assertSame('https://printer.lan/ipp/print', IppConnector::httpUrl('https://printer.lan/ipp/print'));
    }

    public function testIppResponsesAreDecoded(): void
    {
        $response = IppMessage::request(0x040A, 7, [
            IppMessage::OPERATION_ATTRIBUTES => [[IppMessage::TEXT, 'status-message', 'Unsupported format']],
            IppMessage::JOB_ATTRIBUTES => [[IppMessage::INTEGER, 'job-id', -1], [IppMessage::KEYWORD, 'sides-supported', ['one-sided', 'two-sided-long-edge']]],
        ]);
        $parsed = IppMessage::parse($response);

        self::assertSame(0x040A, $parsed['status']);
        self::assertSame(7, $parsed['requestId']);
        self::assertSame(['Unsupported format'], $parsed['attributes']['status-message']);
        self::assertSame([-1], $parsed['attributes']['job-id']);
        self::assertSame(['one-sided', 'two-sided-long-edge'], $parsed['attributes']['sides-supported']);
        self::assertFalse(IppMessage::isSuccess($parsed['status']));
        self::assertFalse(IppMessage::isRetryable($parsed['status']));
        self::assertSame('client-error-document-format-not-supported', IppMessage::statusName($parsed['status']));

        $this->expectException(\UnexpectedValueException::class);
        IppMessage::parse(substr($response, 0, 20));
    }

    public function testFormatsAreRecognisedByContentThenExtension(): void
    {
        $pcl = tempnam(sys_get_temp_dir(), 'pcl');
        file_put_contents($pcl, "\x1BE\x1B&l0O".random_bytes(64));
        self::assertSame('application/vnd.hp-pcl', PrintSpooler::mimeType($pcl, 'facture.pcl'));
        $text = tempnam(sys_get_temp_dir(), 'txt');
        file_put_contents($text, "Bonjour\n");
        self::assertSame('text/plain', PrintSpooler::mimeType($text, 'note.txt'));
    }
}
