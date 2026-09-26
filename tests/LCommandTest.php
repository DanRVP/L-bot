<?php

declare(strict_types=1);

namespace Tests\App;

use App\LCommand;
use Aws\CommandInterface;
use Aws\MockHandler as AwsMockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler as GuzzleMockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use JoliCode\Slack\ClientFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

class LCommandTest extends TestCase
{
    private AwsMockHandler $aws_mock_handler;
    private GuzzleMockHandler $slack_http_mock;
    private LCommand $command;

    protected function setUp(): void
    {
        $this->aws_mock_handler = new AwsMockHandler();
        $s3_client = new S3Client([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => [
                'key' => 'fake-key',
                'secret' => 'fake-secret',
            ],
            'handler' => $this->aws_mock_handler,
        ]);

        $this->slack_http_mock = new GuzzleMockHandler();
        $handler_stack = HandlerStack::create($this->slack_http_mock);
        $http_client = new GuzzleClient(['handler' => $handler_stack]);

        $slack_client = ClientFactory::create('fake-token', $http_client);
        $this->command = new LCommand(
            $slack_client,
            new NullLogger(),
            $s3_client
        );
    }

    public function testInvokeUploadsFileAndCleansUpSuccessfully(): void
    {
        // Mock ListObjects
        $this->aws_mock_handler->append(new Result([
            'Contents' => [['Key' => 'fat-L-image.jpg']],
        ]));

        // Mock GetObject
        $downloaded_file = '';
        $this->aws_mock_handler->append(function (CommandInterface $cmd) use (&$downloaded_file) {
            $downloaded_file = $cmd['@http']['sink'] ?? $cmd['SaveAs'];
            if (is_string($downloaded_file)) {
                file_put_contents($downloaded_file, 'fake-image-content');
            }

            return new Result(['Body' => 'fake-image-content']);
        });

        // Mock Slack success
        $this->slack_http_mock->append(
            /** @phpstan-ignore-next-line */
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'ok' => true,
                'file_id' => 'F12345678',
                'upload_url' => 'https://test.com',
            ])),
            new Response(200),
            /** @phpstan-ignore-next-line */
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'ok' => true,
            ])),
        );

        ($this->command)(['channel_id' => 'C12345678']);

        $slack_request = $this->slack_http_mock->getLastRequest();
        $this->assertNotNull($slack_request);
        $this->assertStringContainsString('files.completeUploadExternal', (string) $slack_request->getUri());

        // Check cleanup
        $this->assertNotEmpty($downloaded_file);
        $this->assertFileDoesNotExist($downloaded_file);
    }

    public function testInvokeThrowsExceptionWhenSlackUploadFailsButStillCleansUp(): void
    {
        $this->aws_mock_handler->append(new Result([
            'Contents' => [['Key' => 'fat-L-image.jpg']],
        ]));

        $downloaded_file = '';
        $this->aws_mock_handler->append(function (CommandInterface $cmd) use (&$downloaded_file) {
            $downloaded_file = $cmd['@http']['sink'] ?? $cmd['SaveAs'];
            if (is_string($downloaded_file)) {
                file_put_contents($downloaded_file, 'fake-image-content');
            }
            return new Result(['Body' => 'fake-image-content']);
        });

        $this->slack_http_mock->append(
            /** @phpstan-ignore-next-line */
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'ok' => false,
                'error' => 'invalid_auth',
            ])),
        );

        $exception_thrown = false;
        try {
            ($this->command)(['channel_id' => 'C12345678']);
        } catch (\Throwable $e) {
            $this->assertSame('Slack returned error code "invalid_auth"', $e->getMessage());
            $exception_thrown = true;
        }

        $this->assertTrue($exception_thrown, 'Expected Exception was not thrown.');
        $this->assertNotEmpty($downloaded_file);
        $this->assertFileDoesNotExist($downloaded_file);
    }

    public function testThrowsRuntimeExceptionWhenS3BucketIsEmpty(): void
    {
        $this->aws_mock_handler->append(new Result([
            'Contents' => [],
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Unable to fetch L image key/i');

        ($this->command)(['channel_id' => 'C12345678']);

        $this->assertNull($this->slack_http_mock->getLastRequest());
    }
}
