<?php

namespace App\Print\Connector;

use App\Entity\Printer;
use App\Enum\PrinterConnectorType;
use App\Print\PrinterSecrets;
use App\Print\PrintException;
use App\Print\PrintRequest;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\Process\Process;

/**
 * Printers shared by a Windows or Samba print server, through smbclient ("print" command).
 * The document is spooled as is: the printer (or the server's driver) must understand its format (PDF, PostScript, PCL…).
 * Credentials go through a temporary authentication file (mode 0600), never on the command line.
 */
final class SambaConnector implements PrinterConnectorInterface
{
    /** Errors another attempt will not fix. */
    private const PERMANENT = ['NT_STATUS_LOGON_FAILURE', 'NT_STATUS_ACCESS_DENIED', 'NT_STATUS_BAD_NETWORK_NAME', 'NT_STATUS_ACCOUNT_DISABLED', 'NT_STATUS_PASSWORD_EXPIRED', 'NT_STATUS_ACCOUNT_LOCKED_OUT', 'NT_STATUS_WRONG_PASSWORD'];

    private readonly Filesystem $fs;

    public function __construct(
        private readonly PrinterSecrets $secrets,
        #[Autowire(env: 'resolve:SMBCLIENT_BINARY')] private readonly string $binary = 'smbclient',
        private readonly int $timeout = 120,
    ) {
        $this->fs = new Filesystem();
    }

    public function type(): PrinterConnectorType
    {
        return PrinterConnectorType::Samba;
    }

    public function print(Printer $printer, PrintRequest $request): ?string
    {
        $target = $this->target($printer);
        // smbclient names the spool job after the local file: a plain name, free of quotes and spaces.
        $spool = $this->fs->tempnam(sys_get_temp_dir(), 'rocket-print-');
        $this->fs->copy($request->path, $spool, true);
        try {
            $commands = implode('; ', array_fill(0, max(1, $request->copies), 'print '.$spool));
            $this->run($printer, ['//'.$target['server'].'/'.$target['share'], '-c', $commands], $target['port']);
        } finally {
            $this->fs->remove($spool);
        }

        return null;
    }

    public function check(Printer $printer): string
    {
        $target = $this->target($printer);
        $output = $this->run($printer, ['-L', '//'.$target['server'], '-g'], $target['port']);
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $fields = explode('|', $line);
            if (\count($fields) >= 2 && 0 === strcasecmp($fields[1], $target['share'])) {
                if ('Printer' !== $fields[0]) {
                    throw new PrintException(\sprintf('The share "%s" exists but is not a printer (%s).', $target['share'], $fields[0]), false);
                }

                return trim('Partage d’impression trouvé'.('' !== trim($fields[2] ?? '') ? ' · '.trim($fields[2]) : ''));
            }
        }

        throw new PrintException(\sprintf('The server does not share a printer named "%s".', $target['share']), false);
    }

    /** @return array{server: string, share: string, port: int|null} */
    private function target(Printer $printer): array
    {
        return Printer::sambaTarget($printer->getUri()) ?? throw new PrintException('Invalid share address: expected //server/printer.', false);
    }

    /** @param list<string> $arguments */
    private function run(Printer $printer, array $arguments, ?int $port): string
    {
        $auth = null;
        $command = [$this->binary, ...$arguments];
        if (null !== $printer->getUsername()) {
            $auth = $this->fs->tempnam(sys_get_temp_dir(), 'rocket-smb-');
            $this->fs->chmod($auth, 0o600);
            $lines = ['username = '.$printer->getUsername(), 'password = '.$this->secrets->password($printer)];
            if (null !== $printer->getDomain()) {
                $lines[] = 'domain = '.$printer->getDomain();
            }
            file_put_contents($auth, implode("\n", $lines)."\n");
            array_push($command, '-A', $auth);
        } else {
            $command[] = '-N';
        }
        if (null !== $port) {
            array_push($command, '-p', (string) $port);
        }

        $process = new Process($command, timeout: $this->timeout);
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new PrintException(\sprintf('The print server did not answer within %d seconds.', $this->timeout));
        } catch (ProcessRuntimeException $e) {
            throw new PrintException(\sprintf('smbclient cannot be run (%s): install it or set SMBCLIENT_BINARY.', $e->getMessage()), false, $e);
        } finally {
            if (null !== $auth) {
                $this->fs->remove($auth);
            }
        }

        $output = $process->getOutput()."\n".$process->getErrorOutput();
        preg_match('/NT_STATUS_[A-Z_]+/', $output, $status);
        if (!$process->isSuccessful() || [] !== $status) {
            $detail = trim($status[0] ?? '') ?: trim((string) strtok(trim($process->getErrorOutput() ?: $process->getOutput()), "\n")) ?: 'exit code '.$process->getExitCode();

            throw new PrintException('smbclient: '.$detail, !\in_array($status[0] ?? '', self::PERMANENT, true));
        }

        return $process->getOutput();
    }
}
