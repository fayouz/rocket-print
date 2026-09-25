<?php

namespace App\Print;

use App\Entity\Printer;

/** A one-page A4 PDF identifying the printer, to check it prints for real. */
final class TestPage
{
    public static function pdf(Printer $printer, string $requestedBy, \DateTimeImmutable $at): string
    {
        $lines = [
            [24, 'Rocket Print - page de test'],
            [13, ''],
            [13, 'Imprimante : '.$printer->getName()],
            [13, 'Emplacement : '.($printer->getLocation() ?? '-')],
            [13, 'Connecteur : '.$printer->getConnector()->label()],
            [13, 'Demandée par : '.$requestedBy],
            [13, 'Le '.$at->format('d/m/Y à H:i:s')],
            [13, ''],
            [13, 'Si vous lisez cette page, l\'imprimante est bien reliée à Rocket Print.'],
        ];
        $stream = "BT\n/F1 24 Tf\n56 780 Td\n";
        foreach ($lines as $i => [$size, $text]) {
            if ($i > 0) {
                $stream .= \sprintf("/F1 %d Tf\n0 -%d Td\n", $size, $size + 9);
            }
            $stream .= '('.self::escape($text).") Tj\n";
        }
        $stream .= "ET\n0.2 0.4 0.9 RG 4 w 56 740 m 539 740 l S\n";

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            \sprintf("<< /Length %d >>\nstream\n%sendstream", \strlen($stream), $stream),
        ];
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $i => $object) {
            $offsets[] = \strlen($pdf);
            $pdf .= \sprintf("%d 0 obj\n%s\nendobj\n", $i + 1, $object);
        }
        $xref = \strlen($pdf);
        $pdf .= \sprintf("xref\n0 %d\n0000000000 65535 f \n", \count($objects) + 1);
        foreach ($offsets as $offset) {
            $pdf .= \sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.\sprintf("trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n", \count($objects) + 1, $xref);
    }

    /** Latin-1 (WinAnsi) text with PDF string escapes. */
    private static function escape(string $text): string
    {
        $latin1 = mb_convert_encoding($text, 'Windows-1252', 'UTF-8');

        return strtr($latin1, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ']);
    }
}
