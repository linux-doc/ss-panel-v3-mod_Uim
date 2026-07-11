<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers\Admin\Setting;

use App\Controllers\Admin\Setting\ImController;
use App\Models\Config;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Database\Capsule\Manager;
use PHPUnit\Framework\TestCase;
use Slim\Http\Factory\DecoratedResponseFactory;
use Slim\Http\Factory\DecoratedServerRequestFactory;
use Slim\Http\Response;
use Slim\Http\ServerRequest;

final class ImControllerTest extends TestCase
{
    private Manager $database;

    protected function setUp(): void
    {
        if (! class_exists('Telegram\Bot\Api', false)) {
            class_alias(TelegramApiSpy::class, 'Telegram\Bot\Api');
        }
        TelegramApiSpy::$constructed = false;
        TelegramApiSpy::$constructorToken = null;
        TelegramApiSpy::$webhookUrl = null;

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

        $_ENV['baseUrl'] = 'https://example.com';
    }

    public function testSetWebhookRejectsEmptyStoredSecretBeforeConstructingTelegramApi(): void
    {
        Config::query()->create([
            'item' => 'telegram_webhook_token',
            'value' => '',
            'type' => 'string',
        ]);

        $response = $this->controller()->setWebhook(
            $this->request(['bot_token' => 'configured-bot-token']),
            $this->response(),
            ['type' => 'telegram'],
        );

        $json = $this->json($response);

        self::assertFalse(TelegramApiSpy::$constructed);
        self::assertSame(0, $json['ret']);
        self::assertSame('Please reset the Telegram webhook token first', $json['msg']);
    }

    public function testSetWebhookRejectsUnprovisionedSecretBeforeConstructingTelegramApi(): void
    {
        $response = $this->controller()->setWebhook(
            $this->request(['bot_token' => 'configured-bot-token']),
            $this->response(),
            ['type' => 'telegram'],
        );

        $json = $this->json($response);

        self::assertFalse(TelegramApiSpy::$constructed);
        self::assertSame(0, $json['ret']);
        self::assertSame('Please reset the Telegram webhook token first', $json['msg']);
    }

    public function testSetWebhookRejectsNonStringStoredSecretBeforeConstructingTelegramApi(): void
    {
        Config::query()->create([
            'item' => 'telegram_webhook_token',
            'value' => '1',
            'type' => 'int',
        ]);

        $response = $this->controller()->setWebhook(
            $this->request(['bot_token' => 'configured-bot-token']),
            $this->response(),
            ['type' => 'telegram'],
        );

        $json = $this->json($response);

        self::assertFalse(TelegramApiSpy::$constructed);
        self::assertSame(0, $json['ret']);
        self::assertSame('Please reset the Telegram webhook token first', $json['msg']);
    }

    public function testSetWebhookUsesProvisionedSecret(): void
    {
        Config::query()->create([
            'item' => 'telegram_webhook_token',
            'value' => 'webhook-secret',
            'type' => 'string',
        ]);

        $response = $this->controller()->setWebhook(
            $this->request(['bot_token' => 'configured-bot-token']),
            $this->response(),
            ['type' => 'telegram'],
        );

        self::assertTrue(TelegramApiSpy::$constructed);
        self::assertSame('configured-bot-token', TelegramApiSpy::$constructorToken);
        self::assertSame(
            'https://example.com/callback/telegram?token=webhook-secret',
            TelegramApiSpy::$webhookUrl,
        );
        self::assertSame(1, $this->json($response)['ret']);
    }

    private function controller(): ImController
    {
        return (new \ReflectionClass(ImController::class))->newInstanceWithoutConstructor();
    }

    private function request(array $params): ServerRequest
    {
        $factory = new HttpFactory();
        $request = (new DecoratedServerRequestFactory($factory))
            ->createServerRequest('POST', '/admin/setting/im/telegram/webhook');

        return $request->withParsedBody($params);
    }

    private function response(): Response
    {
        $factory = new HttpFactory();

        return (new DecoratedResponseFactory($factory, $factory))->createResponse();
    }

    private function json(Response $response): array
    {
        $response->getBody()->rewind();

        return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }
}

final class TelegramApiSpy
{
    public static bool $constructed = false;
    public static mixed $constructorToken = null;
    public static ?string $webhookUrl = null;

    public function __construct(mixed $token)
    {
        self::$constructed = true;
        self::$constructorToken = $token;
    }

    public function removeWebhook(): void
    {
        self::$webhookUrl = null;
    }

    public function setWebhook(array $params): void
    {
        self::$webhookUrl = $params['url'];
    }

    public function getMe(): object
    {
        return new class() {
            public function getUsername(): string
            {
                return 'test-bot';
            }
        };
    }
}
