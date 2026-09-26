<?php

namespace App\Tests\Functional;

use App\Entity\Printer;
use App\Entity\PrintJob;
use Rocket\Core\Entity\User;
use App\Enum\PrinterConnectorType;
use App\Enum\PrintJobStatus;
use App\Message\CleanUpPrintJobs;
use App\Print\DocumentStorage;
use App\Print\Ipp\IppMessage;
use App\Tests\ApiTestTrait;
use App\Tests\Support\HttpMock;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;

final class PrintTest extends WebTestCase
{
    use ApiTestTrait {
        setUp as private apiSetUp;
    }

    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    private string $dataDir;

    protected function setUp(): void
    {
        $this->apiSetUp();
        HttpMock::reset();
        $this->dataDir = static::getContainer()->getParameter('kernel.project_dir').'/var/test-data';
        (new Filesystem())->remove([$this->dataDir.'/print-folders', $this->dataDir.'/smbclient.log']);
    }

    private function jwt(User $user): string
    {
        return 'Bearer '.$this->jwtFor($user);
    }

    /** @param array<string, string> $fields */
    private function printDocument(string $authorization, string $name, string $content, array $fields = [], array $headers = []): array
    {
        $path = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($path, $content);
        $server = ['HTTP_AUTHORIZATION' => $authorization, 'HTTP_ACCEPT' => 'application/json'];
        foreach ($headers as $header => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $header))] = $value;
        }
        $this->client->request('POST', '/api/print-jobs', $fields, ['file' => new UploadedFile($path, $name, test: true)], $server);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function printer(PrinterConnectorType $connector, string $uri, string $name = 'Laser', ?string $username = null, ?string $password = null, bool $default = false): Printer
    {
        $admin = $this->em()->getRepository(User::class)->findOneBy(['email' => 'root@example.org']) ?? $this->createUser('root@example.org', ['ROLE_ADMIN']);
        $body = ['name' => $name, 'connector' => $connector->value, 'uri' => $uri, 'username' => $username, 'domain' => null === $username ? null : 'ACME',
            'colorSupported' => true, 'duplexSupported' => true, 'defaultPrinter' => $default];
        if (null !== $password) {
            $body['password'] = $password;
        }
        $created = $this->api('POST', '/api/admin/printers', $body, $this->jwt($admin));
        $this->assertStatus(201);

        return $this->em()->getRepository(Printer::class)->find($created['id']);
    }

    private function smbLog(): string
    {
        return (string) @file_get_contents($this->dataDir.'/smbclient.log');
    }

    public function testAdministratorsManagePrintersAndUsersOnlySeeWhatTheyNeed(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_ADMIN']);
        $alice = $this->createUser('alice@example.org');

        $created = $this->api('POST', '/api/admin/printers', [
            'name' => 'Laser 2e', 'connector' => 'samba', 'uri' => '\\\\print-server\\laser', 'username' => 'svc-print', 'password' => 'S3cret!', 'location' => '2e étage',
        ], $this->jwt($admin));
        $this->assertStatus(201);
        self::assertTrue($created['hasPassword']);
        self::assertArrayNotHasKey('password', $created);
        self::assertSame('samba', $created['connector']);
        $stored = $this->em()->getConnection()->fetchOne('SELECT password FROM printer WHERE id = ?', [$created['id']]);
        self::assertStringStartsWith('v1:', $stored);
        self::assertStringNotContainsString('S3cret!', $stored);

        // A PATCH without password keeps it; an empty one removes it.
        $this->api('PATCH', '/api/admin/printers/'.$created['id'], ['location' => '2e étage, open space'], $this->jwt($admin));
        $this->assertStatus(200);
        self::assertSame($stored, $this->em()->getConnection()->fetchOne('SELECT password FROM printer WHERE id = ?', [$created['id']]));

        $this->api('POST', '/api/admin/printers', ['name' => 'Bad', 'connector' => 'samba', 'uri' => 'print-server/laser'], $this->jwt($admin));
        $this->assertStatus(422);
        $this->api('POST', '/api/admin/printers', ['name' => 'Bad', 'connector' => 'folder', 'uri' => '../etc'], $this->jwt($admin));
        $this->assertStatus(422);
        $this->api('POST', '/api/admin/printers', ['name' => 'Laser 2e', 'connector' => 'ipp', 'uri' => 'ipp://printer.local/ipp/print'], $this->jwt($admin));
        $this->assertStatus(422);

        $off = $this->api('POST', '/api/admin/printers', ['name' => 'Ancienne', 'connector' => 'ipp', 'uri' => 'ipp://old.local/ipp/print', 'enabled' => false], $this->jwt($admin));
        $this->assertStatus(201);

        // Users list the enabled printers, without their connection settings, and cannot manage them.
        $printers = $this->api('GET', '/api/printers', authorization: $this->jwt($alice));
        $this->assertStatus(200);
        self::assertSame(['Laser 2e'], array_column($printers, 'name'));
        self::assertSame('Partage Windows / Samba', $printers[0]['connectorLabel']);
        foreach (['uri', 'username', 'connector', 'hasPassword'] as $hidden) {
            self::assertArrayNotHasKey($hidden, $printers[0]);
        }
        $this->api('GET', '/api/printers/'.$off['id'], authorization: $this->jwt($alice));
        $this->assertStatus(403);
        $this->api('GET', '/api/admin/printers', authorization: $this->jwt($alice));
        $this->assertStatus(403);
        $this->api('POST', '/api/admin/printers/'.$created['id'].'/check', authorization: $this->jwt($alice));
        $this->assertStatus(403);
        self::assertCount(2, $this->api('GET', '/api/admin/printers', authorization: $this->jwt($admin)));

        // A single default printer.
        $this->api('PATCH', '/api/admin/printers/'.$created['id'], ['defaultPrinter' => true], $this->jwt($admin));
        $this->api('PATCH', '/api/admin/printers/'.$off['id'], ['defaultPrinter' => true], $this->jwt($admin));
        $defaults = array_column(array_filter($this->api('GET', '/api/admin/printers', authorization: $this->jwt($admin)), static fn (array $p) => $p['defaultPrinter']), 'name');
        self::assertSame(['Ancienne'], $defaults);
    }

    public function testFolderPrinterWritesTheDocumentAndItsOptions(): void
    {
        $alice = $this->createUser('alice@example.org');
        $bob = $this->createUser('bob@example.org');
        $printer = $this->printer(PrinterConnectorType::Folder, 'tests/accueil', 'Accueil', default: true);

        // No printer given: the default one.
        $job = $this->printDocument($this->jwt($alice), 'Contrat été.pdf', self::PDF, ['copies' => '2', 'duplex' => '1', 'color' => '1']);
        $this->assertStatus(201);
        self::assertSame('Contrat été.pdf', $job['title']);
        self::assertSame('application/pdf', $job['mimeType']);
        self::assertSame('Accueil', $job['printerName']);
        self::assertSame(2, $job['copies']);
        self::assertTrue($job['duplex']);

        // The worker printed it (synchronous transport in tests).
        $job = $this->api('GET', '/api/print-jobs/'.$job['id'], authorization: $this->jwt($alice));
        self::assertSame('printed', $job['status']);
        self::assertSame(1, $job['attempts']);
        self::assertNotNull($job['printedAt']);
        $written = $this->dataDir.'/print-folders/tests/accueil/'.$job['externalId'];
        self::assertStringEndsWith('Contrat_ete.pdf', $job['externalId']);
        self::assertSame(self::PDF, file_get_contents($written));
        $options = json_decode((string) file_get_contents($written.'.json'), true);
        self::assertEquals(['title' => 'Contrat été.pdf', 'copies' => 2, 'duplex' => true, 'color' => true, 'user' => 'alice@example.org'], array_intersect_key($options, array_flip(['title', 'copies', 'duplex', 'color', 'user'])));

        // Owners read their document back; nobody else knows it exists.
        $this->client->request('GET', '/api/print-jobs/'.$job['id'].'/content', server: ['HTTP_AUTHORIZATION' => $this->jwt($alice)]);
        $this->assertStatus(200);
        self::assertSame(self::PDF, $this->client->getInternalResponse()->getContent());
        $this->client->request('GET', '/api/print-jobs/'.$job['id'].'/content', server: ['HTTP_AUTHORIZATION' => $this->jwt($bob)]);
        $this->assertStatus(404);
        $this->api('GET', '/api/print-jobs/'.$job['id'], authorization: $this->jwt($bob));
        $this->assertStatus(403);
        self::assertSame([], $this->api('GET', '/api/print-jobs', authorization: $this->jwt($bob)));
        self::assertSame([], $this->api('GET', '/api/print-jobs?all=1', authorization: $this->jwt($bob)));

        // Administrators see every job with ?all=1.
        $admin = $this->em()->getRepository(User::class)->findOneBy(['email' => 'root@example.org']);
        self::assertSame([], $this->api('GET', '/api/print-jobs', authorization: $this->jwt($admin)));
        $all = $this->api('GET', '/api/print-jobs?all=1', authorization: $this->jwt($admin));
        self::assertSame(['alice@example.org'], array_column($all, 'ownerEmail'));

        $check = $this->api('POST', '/api/admin/printers/'.$printer->getId().'/check', authorization: $this->jwt($admin));
        self::assertTrue($check['ok']);
        self::assertSame('Dossier accessible · 1 document(s)', $check['detail']);
    }

    public function testDocumentsAreChecked(): void
    {
        $alice = $this->createUser('alice@example.org');

        $this->printDocument($this->jwt($alice), 'a.pdf', self::PDF);
        $this->assertStatus(422);
        self::assertStringContainsString('No default printer', $this->client->getResponse()->getContent());

        $printer = $this->printer(PrinterConnectorType::Folder, 'checks');
        $fields = ['printer' => '/api/printers/'.$printer->getId()];
        $this->printDocument($this->jwt($alice), 'archive.zip', "PK\x03\x04".str_repeat("\0", 30), $fields);
        $this->assertStatus(422);
        self::assertStringContainsString('Unsupported format', $this->client->getResponse()->getContent());
        $this->printDocument($this->jwt($alice), 'big.txt', str_repeat('a', 2001), $fields);
        $this->assertStatus(413);
        $this->printDocument($this->jwt($alice), 'a.txt', 'Bonjour', $fields + ['copies' => '0']);
        $this->assertStatus(422);
        $this->printDocument($this->jwt($alice), 'a.txt', 'Bonjour', ['printer' => 'not-an-id']);
        $this->assertStatus(422);

        $admin = $this->em()->getRepository(User::class)->findOneBy(['email' => 'root@example.org']);
        $this->api('PATCH', '/api/admin/printers/'.$printer->getId(), ['enabled' => false], $this->jwt($admin));
        $this->printDocument($this->jwt($alice), 'a.txt', 'Bonjour', $fields);
        $this->assertStatus(422);
    }

    public function testSambaPrintsThroughSmbclientWithoutCredentialsOnTheCommandLine(): void
    {
        $alice = $this->createUser('alice@example.org');
        $printer = $this->printer(PrinterConnectorType::Samba, 'smb://print-server/laser', username: 'svc-print', password: 'S3cret!');

        $job = $this->printDocument($this->jwt($alice), 'devis.pdf', self::PDF, ['printer' => (string) $printer->getId(), 'copies' => '2']);
        $this->assertStatus(201);
        self::assertSame('printed', $this->api('GET', '/api/print-jobs/'.$job['id'], authorization: $this->jwt($alice))['status']);

        $log = $this->smbLog();
        self::assertStringContainsString('ARGS: //print-server/laser -c print ', $log);
        self::assertStringNotContainsString('S3cret!', (string) preg_replace('/^AUTH:\n(.*\n){3}/m', '', $log));
        self::assertStringContainsString("AUTH:\nusername = svc-print\npassword = S3cret!\ndomain = ACME\n", $log);
        self::assertSame(2, substr_count($log, 'PRINTED: '.strtok(self::PDF, "\n")));
        // The temporary files are gone.
        self::assertSame([], glob(sys_get_temp_dir().'/rocket-smb-*') ?: []);

        $admin = $this->em()->getRepository(User::class)->findOneBy(['email' => 'root@example.org']);
        $check = $this->api('POST', '/api/admin/printers/'.$printer->getId().'/check', authorization: $this->jwt($admin));
        self::assertSame(['ok' => true, 'detail' => 'Partage d’impression trouvé · HP LaserJet 2e étage'], array_intersect_key($check, ['ok' => 1, 'detail' => 1]));
        self::assertStringContainsString('ARGS: -L //print-server -g -A ', $this->smbLog());

        $this->api('PATCH', '/api/admin/printers/'.$printer->getId(), ['uri' => '//print-server/documents'], $this->jwt($admin));
        $check = $this->api('POST', '/api/admin/printers/'.$printer->getId().'/check', authorization: $this->jwt($admin));
        self::assertFalse($check['ok']);
        self::assertStringContainsString('not a printer (Disk)', $check['detail']);
    }

    public function testFailuresAreRetriedThenReported(): void
    {
        $alice = $this->createUser('alice@example.org');
        $offline = $this->printer(PrinterConnectorType::Samba, '//print-server/offline', 'Hors ligne');
        $denied = $this->printer(PrinterConnectorType::Samba, '//print-server/denied', 'Refus', username: 'svc', password: 'wrong');

        // Unreachable: three attempts, then failed.
        $job = $this->printDocument($this->jwt($alice), 'a.txt', 'Bonjour', ['printer' => (string) $offline->getId()]);
        $job = $this->api('GET', '/api/print-jobs/'.$job['id'], authorization: $this->jwt($alice));
        self::assertSame('failed', $job['status']);
        self::assertSame(PrintJob::MAX_ATTEMPTS, $job['attempts']);
        self::assertSame('smbclient: NT_STATUS_HOST_UNREACHABLE', $job['error']);
        self::assertTrue($job['retryable']);

        // Wrong credentials: no point retrying.
        $job = $this->printDocument($this->jwt($alice), 'b.txt', 'Bonjour', ['printer' => (string) $denied->getId()]);
        $job = $this->api('GET', '/api/print-jobs/'.$job['id'], authorization: $this->jwt($alice));
        self::assertSame(['failed', 1, 'smbclient: NT_STATUS_LOGON_FAILURE'], [$job['status'], $job['attempts'], $job['error']]);

        // Fixed by an administrator: printed again from the job list.
        $admin = $this->em()->getRepository(User::class)->findOneBy(['email' => 'root@example.org']);
        $this->api('PATCH', '/api/admin/printers/'.$denied->getId(), ['uri' => '//print-server/laser'], $this->jwt($admin));
        $retried = $this->api('POST', '/api/print-jobs/'.$job['id'].'/retry', authorization: $this->jwt($alice));
        $this->assertStatus(200);
        self::assertSame(['printed', 1, null], [$retried['status'], $retried['attempts'], $retried['error']]);
        $this->api('POST', '/api/print-jobs/'.$job['id'].'/retry', authorization: $this->jwt($alice));
        $this->assertStatus(409);

        $stats = $this->api('GET', '/api/dashboard', authorization: $this->jwt($alice));
        $kpis = array_column($stats['kpis'], null, 'id');
        self::assertSame(2, $kpis['print_jobs']['value']);
        self::assertSame(50.0, (float) $kpis['print_success']['value']);
        self::assertSame(['Impression en échec'], array_values(array_unique(array_column(array_filter($stats['activity'], static fn (array $a) => 'print.failed' === $a['type']), 'label'))));
    }

    public function testQueuedJobsCanBeCancelled(): void
    {
        $alice = $this->createUser('alice@example.org');
        $bob = $this->createUser('bob@example.org');
        $printer = $this->printer(PrinterConnectorType::Folder, 'queue');
        $alice = $this->em()->find(User::class, $alice->getId());
        $job = new PrintJob($alice, $printer, 'Brouillon.txt', 'text/plain', 5);
        static::getContainer()->get(DocumentStorage::class)->write($job, 'Hello');
        $this->em()->persist($job);
        $this->em()->flush();

        $this->api('POST', '/api/print-jobs/'.$job->getId().'/cancel', authorization: $this->jwt($bob));
        $this->assertStatus(404);
        $cancelled = $this->api('POST', '/api/print-jobs/'.$job->getId().'/cancel', authorization: $this->jwt($alice));
        $this->assertStatus(200);
        self::assertSame('cancelled', $cancelled['status']);
        $this->api('POST', '/api/print-jobs/'.$job->getId().'/cancel', authorization: $this->jwt($alice));
        $this->assertStatus(409);

        // The worker skips it.
        static::getContainer()->get(MessageBusInterface::class)->dispatch(new \App\Message\PrintDocument((string) $job->getId()));
        self::assertSame(PrintJobStatus::Cancelled, $this->em()->find(PrintJob::class, $job->getId())->getStatus());
    }

    public function testIppPrintJobAndPrinterState(): void
    {
        $alice = $this->createUser('alice@example.org');
        $printer = $this->printer(PrinterConnectorType::Ipp, 'ipps://printer.acme.test/ipp/print', 'Couleur', username: 'print', password: 'pw');
        $bodies = [];
        HttpMock::on('https://printer.acme.test:631/ipp/print', static function (string $method, string $url, array $options) use (&$bodies) {
            $body = \is_string($options['body']) ? $options['body'] : implode('', iterator_to_array(($options['body'])()));
            $bodies[] = $body;
            $operation = unpack('n', $body, 2)[1];
            $answer = IppMessage::GET_PRINTER_ATTRIBUTES === $operation
                ? IppMessage::request(0x0000, 1, [
                    IppMessage::OPERATION_ATTRIBUTES => [[IppMessage::CHARSET, 'attributes-charset', 'utf-8']],
                    IppMessage::PRINTER_ATTRIBUTES => [
                        [IppMessage::ENUM, 'printer-state', 3],
                        [IppMessage::TEXT, 'printer-make-and-model', 'Brother HL-L8360CDW'],
                        [IppMessage::BOOLEAN, 'printer-is-accepting-jobs', true],
                    ],
                ])
                : IppMessage::request(0x0000, 1, [
                    IppMessage::OPERATION_ATTRIBUTES => [[IppMessage::CHARSET, 'attributes-charset', 'utf-8']],
                    IppMessage::JOB_ATTRIBUTES => [[IppMessage::INTEGER, 'job-id', 4242], [IppMessage::ENUM, 'job-state', 3]],
                ]);

            return new MockResponse($answer, ['response_headers' => ['content-type' => 'application/ipp']]);
        });

        $job = $this->printDocument($this->jwt($alice), 'plan.pdf', self::PDF, ['printer' => (string) $printer->getId(), 'duplex' => '1']);
        $job = $this->api('GET', '/api/print-jobs/'.$job['id'], authorization: $this->jwt($alice));
        self::assertSame(['printed', '4242'], [$job['status'], $job['externalId']]);

        $request = $bodies[0];
        self::assertSame(IppMessage::PRINT_JOB, unpack('n', $request, 2)[1]);
        foreach (["printer-uri\x00\x22ipps://printer.acme.test/ipp/print", "requesting-user-name\x00\x11alice@example.org", "job-name\x00\x08plan.pdf",
            "document-format\x00\x0fapplication/pdf", "sides\x00\x13two-sided-long-edge", "print-color-mode\x00\x0amonochrome"] as $attribute) {
            self::assertStringContainsString($attribute, $request);
        }
        self::assertStringEndsWith("\x03".self::PDF, $request);
        $headers = implode("\n", HttpMock::$requests[0]['headers']);
        self::assertStringContainsString('Authorization: Basic '.base64_encode('print:pw'), $headers);

        $admin = $this->em()->getRepository(User::class)->findOneBy(['email' => 'root@example.org']);
        $check = $this->api('POST', '/api/admin/printers/'.$printer->getId().'/check', authorization: $this->jwt($admin));
        self::assertSame(['ok' => true, 'detail' => 'Prête · Brother HL-L8360CDW'], array_intersect_key($check, ['ok' => 1, 'detail' => 1]));

        // The test page is a regular job of the administrator.
        $testPage = $this->api('POST', '/api/admin/printers/'.$printer->getId().'/test-page', authorization: $this->jwt($admin));
        $this->assertStatus(201);
        self::assertSame(['Page de test.pdf', 'application/pdf'], [$testPage['title'], $testPage['mimeType']]);
        self::assertStringStartsWith('%PDF-1.4', substr(end($bodies), strpos(end($bodies), "\x03%PDF") + 1));
    }

    public function testApplicationsPrintOnBehalfOfUsers(): void
    {
        $this->createUser('alice@example.org');
        $this->printer(PrinterConnectorType::Folder, 'crm', default: true);
        [, $token] = $this->createApplication();

        $job = $this->printDocument('Bearer '.$token, 'facture.pdf', self::PDF, headers: ['X-Impersonate-User' => 'alice@example.org']);
        $this->assertStatus(201);
        self::assertSame(['Partner CRM', 'alice@example.org', 'printed'], [$job['applicationName'], $job['ownerEmail'], $job['status']]);

        // Without a user, an application cannot print.
        $this->printDocument('Bearer '.$token, 'facture.pdf', self::PDF);
        $this->assertStatus(403);
    }

    public function testCleanUpPurgesOldDocumentsAndFailsStuckJobs(): void
    {
        $alice = $this->createUser('alice@example.org');
        $printer = $this->printer(PrinterConnectorType::Folder, 'cleanup', default: true);
        $old = $this->printDocument($this->jwt($alice), 'ancien.txt', 'Ancien');
        $recent = $this->printDocument($this->jwt($alice), 'recent.txt', 'Récent');
        $stuck = new PrintJob($this->em()->find(User::class, $alice->getId()), $this->em()->find(Printer::class, $printer->getId()), 'Bloqué.txt', 'text/plain', 3);
        static::getContainer()->get(DocumentStorage::class)->write($stuck, 'abc');
        $stuck->start();
        $this->em()->persist($stuck);
        $this->em()->flush();

        $db = $this->em()->getConnection();
        $db->executeStatement("UPDATE print_job SET created_at = NOW() - INTERVAL '8 days' WHERE id = ?", [$old['id']]);
        $db->executeStatement("UPDATE print_job SET updated_at = NOW() - INTERVAL '1 hour' WHERE id = ?", [(string) $stuck->getId()]);

        $this->em()->clear();
        static::getContainer()->get(MessageBusInterface::class)->dispatch(new CleanUpPrintJobs());

        $old = $this->api('GET', '/api/print-jobs/'.$old['id'], authorization: $this->jwt($alice));
        self::assertTrue($old['contentPurged']);
        self::assertFalse($old['retryable']);
        $this->client->request('GET', '/api/print-jobs/'.$old['id'].'/content', server: ['HTTP_AUTHORIZATION' => $this->jwt($alice)]);
        $this->assertStatus(404);
        self::assertFalse($this->api('GET', '/api/print-jobs/'.$recent['id'], authorization: $this->jwt($alice))['contentPurged']);
        $stuck = $this->api('GET', '/api/print-jobs/'.$stuck->getId(), authorization: $this->jwt($alice));
        self::assertSame('failed', $stuck['status']);
        self::assertStringContainsString('Interrupted', $stuck['error']);
    }

    public function testEnabledPrintersAreCheckedOnTheDashboard(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_ADMIN']);
        $this->printer(PrinterConnectorType::Samba, '//print-server/laser', 'Laser');
        $this->printer(PrinterConnectorType::Samba, '//print-server/offline', 'Hors ligne');

        $this->api('POST', '/api/health/check', authorization: $this->jwt($admin));
        $stats = $this->api('GET', '/api/dashboard', authorization: $this->jwt($admin));
        $printers = array_column($stats['health']['services'], null, 'id')['printers'];
        self::assertSame(['degraded', 2, 1], [$printers['status'], $printers['total'], $printers['failing']]);
        self::assertStringContainsString('Hors ligne (The server does not share a printer named "offline".)', $printers['detail']);
    }
}
