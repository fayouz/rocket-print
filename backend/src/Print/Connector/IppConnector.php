<?php

namespace App\Print\Connector;

use App\Entity\Printer;
use App\Enum\PrinterConnectorType;
use App\Print\Ipp\IppMessage;
use App\Print\PrinterSecrets;
use App\Print\PrintException;
use App\Print\PrintRequest;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Network printers and CUPS queues, over IPP (Internet Printing Protocol, RFC 8010/8011): ipp://, ipps://, http(s)://.
 * Driverless printers (IPP Everywhere, AirPrint) accept PDF and JPEG directly; a CUPS queue converts what its filters know.
 */
final class IppConnector implements PrinterConnectorInterface
{
    private const PRINTER_STATES = [3 => 'Prête', 4 => 'Occupée', 5 => 'Arrêtée'];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly PrinterSecrets $secrets,
    ) {
    }

    public function type(): PrinterConnectorType
    {
        return PrinterConnectorType::Ipp;
    }

    public function print(Printer $printer, PrintRequest $request): ?string
    {
        $document = @file_get_contents($request->path);
        if (false === $document) {
            throw new PrintException('The document to print is missing.', false);
        }
        $operation = IppMessage::operationAttributes($this->printerUri($printer), $request->user);
        $operation[] = [IppMessage::NAME, 'job-name', mb_substr($request->title, 0, 255)];
        $operation[] = [IppMessage::MIME_TYPE, 'document-format', $request->mimeType];
        $job = [[IppMessage::INTEGER, 'copies', max(1, $request->copies)]];
        if ($printer->isDuplexSupported()) {
            $job[] = [IppMessage::KEYWORD, 'sides', $request->duplex ? 'two-sided-long-edge' : 'one-sided'];
        }
        if ($printer->isColorSupported()) {
            $job[] = [IppMessage::KEYWORD, 'print-color-mode', $request->color ? 'color' : 'monochrome'];
        }

        $response = $this->send($printer, IppMessage::request(IppMessage::PRINT_JOB, 1, [
            IppMessage::OPERATION_ATTRIBUTES => $operation,
            IppMessage::JOB_ATTRIBUTES => $job,
        ], $document));
        // Some printers refuse options they do not know instead of ignoring them: print with their defaults.
        if (0x040B === $response['status']) {
            $response = $this->send($printer, IppMessage::request(IppMessage::PRINT_JOB, 2, [
                IppMessage::OPERATION_ATTRIBUTES => $operation,
                IppMessage::JOB_ATTRIBUTES => [[IppMessage::INTEGER, 'copies', max(1, $request->copies)]],
            ], $document));
        }
        $this->assertSuccess($response);

        $jobId = $response['attributes']['job-id'][0] ?? null;

        return null === $jobId ? null : (string) $jobId;
    }

    public function check(Printer $printer): string
    {
        $operation = IppMessage::operationAttributes($this->printerUri($printer), 'rocket-print');
        $operation[] = [IppMessage::KEYWORD, 'requested-attributes', ['printer-state', 'printer-state-message', 'printer-make-and-model', 'printer-is-accepting-jobs']];
        $response = $this->send($printer, IppMessage::request(IppMessage::GET_PRINTER_ATTRIBUTES, 1, [IppMessage::OPERATION_ATTRIBUTES => $operation]));
        $this->assertSuccess($response);

        $attributes = $response['attributes'];
        $model = (string) ($attributes['printer-make-and-model'][0] ?? '');
        $state = (int) ($attributes['printer-state'][0] ?? 3);
        $message = trim((string) ($attributes['printer-state-message'][0] ?? ''));
        if (5 === $state || false === ($attributes['printer-is-accepting-jobs'][0] ?? true)) {
            throw new PrintException(trim('The printer is stopped or does not accept jobs'.('' !== $message ? ': '.$message : '').'.'));
        }

        return implode(' · ', array_filter([self::PRINTER_STATES[$state] ?? 'Prête', $model, $message]));
    }

    /** The printer-uri attribute: ipp(s):// as declared, http(s):// addresses given as ipp(s)://. */
    private function printerUri(Printer $printer): string
    {
        return (string) preg_replace(['#^http://#i', '#^https://#i'], ['ipp://', 'ipps://'], $printer->getUri());
    }

    /** HTTP address of the printer: ipp:// is HTTP on port 631, ipps:// HTTPS on port 631. */
    public static function httpUrl(string $uri): string
    {
        if (!preg_match('#^(ipps?|https?)://([^/:]+|\[[^]]+\])(?::(\d+))?(/.*)?$#i', $uri, $m)) {
            throw new PrintException('Invalid IPP address.', false);
        }
        $scheme = strtolower($m[1]);
        $https = \in_array($scheme, ['ipps', 'https'], true);
        $port = ($m[3] ?? '') !== '' ? $m[3] : (str_starts_with($scheme, 'ipp') ? '631' : null);

        return ($https ? 'https' : 'http').'://'.$m[2].(null !== $port ? ':'.$port : '').(($m[4] ?? '') ?: '/ipp/print');
    }

    /** @return array{status: int, requestId: int, attributes: array<string, list<int|bool|string>>} */
    private function send(Printer $printer, string $body): array
    {
        $options = ['headers' => ['Content-Type' => 'application/ipp'], 'body' => $body, 'timeout' => 30, 'max_duration' => 300];
        if (null !== $printer->getUsername()) {
            $options['auth_basic'] = [$printer->getUsername(), $this->secrets->password($printer)];
        }
        try {
            $response = $this->httpClient->request('POST', self::httpUrl($printer->getUri()), $options);
            $status = $response->getStatusCode();
            if (401 === $status || 403 === $status) {
                throw new PrintException(\sprintf('The printer refused the credentials (HTTP %d).', $status), false);
            }
            if ($status >= 400) {
                throw new PrintException(\sprintf('The printer answered HTTP %d.', $status), $status >= 500 || 404 !== $status);
            }
            $content = $response->getContent();
        } catch (HttpException $e) {
            throw new PrintException('The printer cannot be reached: '.$e->getMessage(), true, $e);
        }

        try {
            return IppMessage::parse($content);
        } catch (\UnexpectedValueException $e) {
            throw new PrintException('Invalid answer of the printer: '.$e->getMessage(), true, $e);
        }
    }

    /** @param array{status: int, attributes: array<string, list<int|bool|string>>} $response */
    private function assertSuccess(array $response): void
    {
        if (IppMessage::isSuccess($response['status'])) {
            return;
        }
        $message = trim((string) ($response['attributes']['status-message'][0] ?? ''));

        throw new PrintException(
            \sprintf('The printer refused the request: %s%s.', IppMessage::statusName($response['status']), '' !== $message ? ' ('.$message.')' : ''),
            IppMessage::isRetryable($response['status']),
        );
    }
}
