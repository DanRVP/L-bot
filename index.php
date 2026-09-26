<?php

declare(strict_types=1);

use App\LCommand;
use Aws\S3\S3Client;
use Aws\S3\S3ClientInterface;
use Bref\Monolog\CloudWatchFormatter;
use DI\ContainerBuilder;
use GuzzleHttp\Client;
use JoliCode\Slack\Client as SlackClient;
use JoliCode\Slack\ClientFactory;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use SlackPhp\Slick;

use function DI\create;

$token = getenv('SLACK_BOT_TOKEN');
if (empty($token)) {
    // Mimic's Slick's default error handler
    http_response_code(500);
    echo '';
    return;
}

$builder = new ContainerBuilder();
$builder->addDefinitions([
    S3ClientInterface::class => create(S3Client::class),
    SlackClient::class =>  fn() => ClientFactory::create($token, new Client()),
    LoggerInterface::class => function () {
        $logger = new Logger('default');
        $handler = new StreamHandler('php://stderr', Level::Info);
        $handler->setFormatter(new CloudWatchFormatter());
        $logger->pushHandler($handler);
        return $logger;
    },
]);

// TODO: Add options for dev mode so we can build DI compiliation into CI/CD, but not when testing locally
$container = $builder->build();

Slick::app()->route('command', 'L', $container->get(LCommand::class))->run();
