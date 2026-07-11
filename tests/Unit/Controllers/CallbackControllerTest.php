<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use App\Controllers\CallbackController;
use App\Models\Config;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Database\Capsule\Manager;
use Psr\Http\Message\RequestInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Http\Factory\DecoratedResponseFactory;
use Slim\Http\Factory\DecoratedServerRequestFactory;
use Slim\Http\Response;
use Slim\Http\ServerRequest;

final class CallbackControllerTest extends TestCase
{
    private Manager $database;

    protected function setUp(): void
    {
        if (! class_exists('App\Services\Bot\Telegram\Telegram', false)) {
            class_alias(TelegramProcessSpy::class, 'App\Services\Bot\Telegram\Telegram');
        }
        TelegramProcessSpy::$called = false;

        $this->database = new Manager();
        $this->database->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        $this->database->setAsGlobal();
        $this->database->bootEloquent();
        $this->database->schema()->create('config', static function ($table): void {
            $table->increments('id');
            $table->string('item');
            $table->text('value');
            $table->string('type')->default('string');
        });
    }

    public function testTelegramRejectsEmptyStoredAndPresentedWebhookTokensBeforeDispatch(): void
    {
        $this->setConfig('telegram_token', 'configured-bot-token');
        $this->setConfig('telegram_webhook_token', '');

        $response = $this->controller()->telegram(
            $this->request(''),
            $this->response(),
            [],
        );

        self::assertFalse(TelegramProcessSpy::$called);
        self::assertSame(400, $response->getStatusCode());
    }

    public function testTelegramRejectsNonStringBotTokenBeforeDispatch(): void
    {
        $this->setConfig('telegram_token', '1', 'int');
        $this->setConfig('telegram_webhook_token', 'webhook-secret');

        $response = $this->controller()->telegram(
            $this->request('webhook-secret'),
            $this->response(),
            [],
        );

        self::assertFalse(TelegramProcessSpy::$called);
        self::assertSame(400, $response->getStatusCode());
    }

    public function testTelegramRejectsEmptyBotTokenBeforeDispatch(): void
    {
        $this->setConfig('telegram_token', '');
        $this->setConfig('telegram_webhook_token', 'webhook-secret');

        $response = $this->controller()->telegram(
            $this->request('webhook-secret'),
            $this->response(),
            [],
        );

        self::assertFalse(TelegramProcessSpy::$called);
        self::assertSame(400, $response->getStatusCode());
    }

    public function testTelegramRejectsMatchingNonStringStoredAndPresentedWebhookTokensBeforeDispatch(): void
    {
        $this->setConfig('telegram_token', 'configured-bot-token');
        $this->setConfig('telegram_webhook_token', '1', 'int');

        $response = $this->controller()->telegram(
            $this->request(1),
            $this->response(),
            [],
        );

        self::assertFalse(TelegramProcessSpy::$called);
        self::assertSame(400, $response->getStatusCode());
    }

    public function testTelegramRejectsMissingWebhookConfigBeforeDispatch(): void
    {
        $this->setConfig('telegram_token', 'configured-bot-token');

        $response = $this->controller()->telegram(
            $this->request('webhook-secret'),
            $this->response(),
            [],
        );

        self::assertFalse(TelegramProcessSpy::$called);
        self::assertSame(400, $response->getStatusCode());
    }

    public function testTelegramRejectsMissingQueryTokenBeforeDispatch(): void
    {
        $this->setConfig('telegram_token', 'configured-bot-token');
        $this->setConfig('telegram_webhook_token', 'webhook-secret');

        $response = $this->controller()->telegram(
            $this->requestWithQueryParams([]),
            $this->response(),
            [],
        );

        self::assertFalse(TelegramProcessSpy::$called);
        self::assertSame(400, $response->getStatusCode());
    }

    public static function invalidPresentedTokens(): iterable
    {
        yield 'empty' => [''];
        yield 'integer' => [1];
        yield 'array' => [[]];
        yield 'mismatch' => ['wrong-secret'];
    }

    #[DataProvider('invalidPresentedTokens')]
    public function testTelegramRejectsInvalidPresentedWebhookTokenBeforeDispatch(mixed $token): void
    {
        $this->setConfig('telegram_token', 'configured-bot-token');
        $this->setConfig('telegram_webhook_token', 'webhook-secret');

        $response = $this->controller()->telegram(
            $this->request($token),
            $this->response(),
            [],
        );

        self::assertFalse(TelegramProcessSpy::$called);
        self::assertSame(400, $response->getStatusCode());
    }

    public function testTelegramDispatchesMatchingNonEmptyStringTokens(): void
    {
        $this->setConfig('telegram_token', 'configured-bot-token');
        $this->setConfig('telegram_webhook_token', 'webhook-secret');

        $response = $this->controller()->telegram(
            $this->request('webhook-secret'),
            $this->response(),
            [],
        );

        self::assertTrue(TelegramProcessSpy::$called);
        self::assertSame(204, $response->getStatusCode());
    }

    private function setConfig(string $item, string $value, string $type = 'string'): void
    {
        Config::query()->create([
            'item' => $item,
            'value' => $value,
            'type' => $type,
        ]);
    }

    private function controller(): CallbackController
    {
        return (new \ReflectionClass(CallbackController::class))->newInstanceWithoutConstructor();
    }

    private function request(mixed $token): ServerRequest
    {
        return $this->requestWithQueryParams(['token' => $token]);
    }

    private function requestWithQueryParams(array $queryParams): ServerRequest
    {
        $factory = new HttpFactory();
        $request = (new DecoratedServerRequestFactory($factory))
            ->createServerRequest('POST', '/callback/telegram');

        return $request->withQueryParams($queryParams);
    }

    private function response(): Response
    {
        $factory = new HttpFactory();

        return (new DecoratedResponseFactory($factory, $factory))->createResponse();
    }
}

final class TelegramProcessSpy
{
    public static bool $called = false;

    public static function process(RequestInterface $request): void
    {
        self::$called = true;
    }
}
