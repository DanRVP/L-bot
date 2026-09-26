<?php

declare(strict_types=1);

namespace App;

use Aws\S3\S3ClientInterface;
use Exception;
use JoliCode\Slack\Api\Model\FilesCompleteUploadExternalPostResponsedefault;
use JoliCode\Slack\Client;
use Psr\Log\LoggerInterface;
use RuntimeException;

class LCommand
{
    /**
     * Constructor
     *
     * @param Client $slack_client
     * @param LoggerInterface $logger
     * @param S3ClientInterface $s3_client
     */
    public function __construct(
        protected Client $slack_client,
        protected LoggerInterface $logger,
        protected S3ClientInterface $s3_client,
    ) {
    }

    /**
     * Invoke the command as an invokable class
     *
     * @param array<string, mixed> $slack_payload
     */
    public function __invoke(array $slack_payload): void
    {
        $l_filepath = $this->resloveL();
        try {
            $result = $this->slack_client->filesUploadV2(
                channelId: $slack_payload['channel_id'],
                files: [
                    [
                        'path' => $l_filepath,
                        'title' => 'Big Fat L',
                        'alt_text' => 'Big Fat L',
                        'initialComment' => 'Hold this L', // TODO: Include @'ing other users
                    ],
                ],
            );

            if ($result instanceof FilesCompleteUploadExternalPostResponsedefault) {
                throw new Exception("Failed to upload file");
            }
        } finally {
            if (file_exists($l_filepath)) {
                unlink($l_filepath);
            }
        }
    }

    /**
     * Get a random L image from the S3 bucket of Ls
     *
     * @return string
     */
    private function resloveL(): string
    {
        $paginator = $this->s3_client->getPaginator('ListObjects', [
            'Bucket' => '???', // TODO: Inject as scalar from DI container to prevent coupling to ENV
        ]);

        $keys = [];
        foreach ($paginator as $page) {
            foreach ($page['Contents'] ?? [] as $item) {
                if (!empty($item['Key'])) {
                    $keys[] = $item['Key'];
                }
            }
        }

        if (empty($keys)) {
            throw new RuntimeException('Unable to fetch L image key');
        }

        $key = $keys[array_rand($keys)];
        $filepath = '/tmp/' . bin2hex(random_bytes(16)) . '-' . basename($key);
        $this->s3_client->getObject([
            'Bucket' => '???',
            'Key' => $key,
            'SaveAs' => $filepath,
        ]);

        return $filepath;
    }
}
