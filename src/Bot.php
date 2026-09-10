<?php

declare(strict_types=1);

namespace ChatFlow\Telegram;

use ChatFlow\Container\Container;
use ChatFlow\Container\ContainerInterface;
use ChatFlow\Contracts\FlowRuntimeInterface;
use ChatFlow\Contracts\InboundEventInterface;
use ChatFlow\Core\Application;
use ChatFlow\Core\Context;
use ChatFlow\Core\Result;
use ChatFlow\Exception\ErrorHandlerInterface;
use ChatFlow\Exception\ExceptionRegistry;
use ChatFlow\Exception\LogicException;
use ChatFlow\Exception\UnsupportedInputException;
use ChatFlow\Exception\ValidationException;
use ChatFlow\Observability\RuntimeObserverInterface;
use ChatFlow\Routing\Route;
use ChatFlow\Routing\Router;
use ChatFlow\Scene\ConversationManager;
use ChatFlow\Scene\SceneRegistry;
use ChatFlow\Scene\SceneTransitions;
use ChatFlow\Storage\Drivers\MemoryStorage;
use ChatFlow\Storage\StorageInterface;
use ChatFlow\Telegram\Callback\FileTelegramCallbackStore;
use ChatFlow\Telegram\Callback\TelegramCallbackPayloadEncoder;
use ChatFlow\Telegram\MediaGroup\FileTelegramMediaGroupStore;
use ChatFlow\Telegram\MediaGroup\TelegramMediaGroupCollector;
use ChatFlow\Telegram\UI\TelegramScreenManager;
use ChatFlow\Validation\ValidationRegistry;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramResponseException;
use Telegram\Bot\Exceptions\TelegramSDKException;
use Telegram\Bot\Objects\Update;
use Throwable;

/**
 * Telegram-facing runtime facade: wires the SDK, the adapter and the core Application, and
 * exposes the flow registration API.
 *
 * Configuration (routes, scenes, storage, middleware) happens first; the Application is built on
 * the first handled update.
 */
class Bot implements FlowRuntimeInterface
{
    private readonly ContainerInterface $container;

    private readonly LoggerInterface $logger;

    private readonly Api $api;

    private readonly Router $router;

    private readonly SceneRegistry $scenes;

    private readonly SceneTransitions $transitions;

    private readonly ValidationRegistry $validation;

    private readonly ErrorHandlerInterface $errorHandler;

    private readonly TelegramPublisher $publisher;

    private readonly TelegramCallbackPayloadEncoder $callbackEncoder;

    private readonly TelegramMediaGroupCollector $mediaGroupCollector;

    private readonly TelegramPlatformAdapter $adapter;

    private readonly ?RuntimeObserverInterface $runtimeObserver;

    private ?StorageInterface $storage;

    private ?int $sessionTtlSeconds;

    private ?Application $application = null;

    /**
     * @var list<\ChatFlow\Middleware\MiddlewareInterface|class-string<\ChatFlow\Middleware\MiddlewareInterface>>
     */
    private array $middlewares = [];

    /**
     * @var array<string, callable>
     */
    private array $telegramEventHandlers = [];

    /**
     * @var array<string, callable>
     */
    private array $mediaHandlers = [];

    /**
     * @param string $basePath Directory for default file stores (`storage/telegram-callbacks`, `storage/telegram-media-groups`).
     * @param string|null $callbackSecret Key used to sign inline callback payloads; derived from the token by default.
     *
     * @throws TelegramSDKException
     */
    public function __construct(
        private readonly string $token,
        private readonly string $basePath,
        ?LoggerInterface $logger = null,
        ?ContainerInterface $container = null,
        private readonly ?string $webhookSecret = null,
        ?Api $api = null,
        ?ErrorHandlerInterface $errorHandler = null,
        ?Router $router = null,
        ?SceneRegistry $sceneRegistry = null,
        ?ValidationRegistry $validationRegistry = null,
        ?RuntimeObserverInterface $runtimeObserver = null,
        ?TelegramCallbackPayloadEncoder $callbackPayloadEncoder = null,
        ?TelegramMediaGroupCollector $mediaGroupCollector = null,
        ?TelegramPublisher $publisher = null,
        ?StorageInterface $storage = null,
        ?int $sessionTtlSeconds = null,
        bool $debug = false,
        ?string $callbackSecret = null,
    ) {
        $this->container = $container ?? new Container();
        $this->logger = $logger ?? new NullLogger();
        $this->runtimeObserver = $runtimeObserver;
        $this->api = $api ?? new Api($this->token);
        $this->storage = $storage;
        $this->sessionTtlSeconds = $sessionTtlSeconds;

        $fileDownloader = new FileDownloader($this->api);
        $this->publisher = $publisher ?? new TelegramPublisher($this->api);
        $this->router = $router ?? new Router();
        $this->scenes = $sceneRegistry ?? new SceneRegistry($this->container);
        $this->transitions = new SceneTransitions($this->scenes);
        $this->validation = $validationRegistry ?? new ValidationRegistry($this->container);
        $this->errorHandler = $errorHandler ?? new ExceptionRegistry($this->logger, $debug);
        $this->registerTelegramDefaultErrorPolicy();

        $this->callbackEncoder = $callbackPayloadEncoder ?? new TelegramCallbackPayloadEncoder(
            new FileTelegramCallbackStore($this->basePath . '/storage/telegram-callbacks'),
            $callbackSecret ?? self::deriveCallbackSecret($this->token),
        );
        $this->mediaGroupCollector = $mediaGroupCollector ?? new TelegramMediaGroupCollector(
            new FileTelegramMediaGroupStore($this->basePath . '/storage/telegram-media-groups'),
        );

        $screenManager = new TelegramScreenManager($this->publisher);
        $this->adapter = new TelegramPlatformAdapter(
            $this->api,
            $fileDownloader,
            $this->callbackEncoder,
            $screenManager,
            $this->logger,
        );

        $this->container->set(Api::class, $this->api);
        $this->container->set(FileDownloader::class, $fileDownloader);
        $this->container->set(TelegramPublisher::class, $this->publisher);
        $this->container->set(TelegramCallbackPayloadEncoder::class, $this->callbackEncoder);
        $this->container->set(TelegramMediaGroupCollector::class, $this->mediaGroupCollector);
        $this->container->set(TelegramPlatformAdapter::class, $this->adapter);
        $this->container->set(TelegramScreenManager::class, $screenManager);
        $this->container->set(self::class, $this);
    }

    // -- Telegram sugar ------------------------------------------------------------------------

    public function command(string $name, callable $handler): static
    {
        $this->router->onCommand($name, $handler);

        return $this;
    }

    public function prefix(string $prefix, callable $handler): static
    {
        $this->router->onActionPrefix($prefix, $handler);

        return $this;
    }

    /**
     * Handles a Telegram update type that is not a message or a callback (`my_chat_member`,
     * `chat_member`). The handler runs as a global route with middleware and session access and
     * receives the raw update as `array $update`.
     */
    public function onTelegramEvent(string $updateType, callable $handler): static
    {
        $this->telegramEventHandlers[$updateType] = $handler;

        return $this;
    }

    /**
     * Handles messages with attachments of the given type (`any` for every type) when no scene is
     * active. Inside scenes attachments go to the scene.
     */
    public function onMedia(string $type, callable $handler): static
    {
        $this->mediaHandlers[$type] = $handler;

        return $this;
    }

    // -- FlowRuntimeInterface ------------------------------------------------------------------

    public function onCommand(string $command, callable $handler): Route
    {
        return $this->router->onCommand($command, $handler);
    }

    public function onTextPrefix(string $prefix, callable $handler): Route
    {
        return $this->router->onTextPrefix($prefix, $handler);
    }

    public function onTextRegex(string $pattern, callable $handler): Route
    {
        return $this->router->onTextRegex($pattern, $handler);
    }

    public function onAction(string $action, callable $handler): Route
    {
        return $this->router->onAction($action, $handler);
    }

    public function onActionPrefix(string $prefix, callable $handler): Route
    {
        return $this->router->onActionPrefix($prefix, $handler);
    }

    public function onActionRegex(string $pattern, callable $handler): Route
    {
        return $this->router->onActionRegex($pattern, $handler);
    }

    public function fallback(callable $handler): Route
    {
        return $this->router->fallback($handler);
    }

    public function registerScene(string $sceneClass, ?string $label = null): static
    {
        $this->scenes->register($sceneClass, $label);

        return $this;
    }

    public function allowTransition(string $from, string $to, ?callable $guard = null): static
    {
        $this->transitions->allow($from, $to, $guard);

        return $this;
    }

    public function middleware(array $middlewares): static
    {
        foreach ($middlewares as $middleware) {
            $this->middlewares[] = $middleware;
        }

        $this->application?->middleware($middlewares);

        return $this;
    }

    public function setErrorHandler(callable $handler): static
    {
        $this->errorHandler->register(Throwable::class, static function (Throwable $exception, ?Context $context) use ($handler): void {
            if ($context !== null) {
                $handler($exception, $context);
            }
        });

        return $this;
    }

    public function onException(string $exception, callable $handler): static
    {
        $this->errorHandler->register($exception, $handler);

        return $this;
    }

    public function getContainer(): ContainerInterface
    {
        return $this->container;
    }

    public function getValidationRegistry(): ValidationRegistry
    {
        return $this->validation;
    }

    public function getScenes(): SceneRegistry
    {
        return $this->scenes;
    }

    public function getTransitions(): SceneTransitions
    {
        return $this->transitions;
    }

    // -- configuration -------------------------------------------------------------------------

    /**
     * @throws LogicException When called after the first handled update.
     */
    public function useStorage(StorageInterface $storage, ?int $sessionTtlSeconds = null): static
    {
        if ($this->application !== null) {
            throw new LogicException('Storage must be configured before the bot handles its first update.');
        }

        $this->storage = $storage;
        $this->sessionTtlSeconds = $sessionTtlSeconds ?? $this->sessionTtlSeconds;

        return $this;
    }

    // -- handling ------------------------------------------------------------------------------

    /**
     * @param Update|array<string, mixed> $update
     */
    public function handle(Update|array $update): Result
    {
        $raw = self::stringKeys($update instanceof Update ? $update->toArray() : $update);
        $telegramEventType = $this->detectTelegramUpdateType($raw);

        if ($telegramEventType !== null && isset($this->telegramEventHandlers[$telegramEventType])) {
            $event = $this->inboundEvent($raw);

            if ($event === null) {
                return Result::noMatch('unsupported_update');
            }

            return $this->application()->handle($event, $this->telegramEventRoute($telegramEventType, $raw));
        }

        $collected = $this->mediaGroupCollector->collect($raw);

        if ($collected === null) {
            return Result::noMatch('telegram_media_group_pending');
        }

        $event = $this->inboundEvent($collected);

        if ($event === null) {
            return Result::noMatch('unsupported_update');
        }

        return $this->application()->handle($event, $this->mediaRouteFor($event));
    }

    /**
     * Handles the update Telegram posted to the webhook endpoint.
     *
     * @param string|null $secretToken The `X-Telegram-Bot-Api-Secret-Token` header; read from `$_SERVER` when omitted.
     *
     * @throws ValidationException When a webhook secret is configured and the token does not match.
     * @throws TelegramSDKException
     */
    public function runWebhook(?string $secretToken = null): Result
    {
        $provided = $secretToken ?? ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? null);

        if (!$this->verifyWebhookSecret(\is_string($provided) ? $provided : null)) {
            throw new ValidationException('Invalid webhook secret token.');
        }

        return $this->handle($this->api->getWebhookUpdate());
    }

    /**
     * Constant-time comparison of the secret token header against the configured webhook secret.
     */
    public function verifyWebhookSecret(?string $providedToken): bool
    {
        if ($this->webhookSecret === null) {
            return true;
        }

        return $providedToken !== null && hash_equals($this->webhookSecret, $providedToken);
    }

    /**
     * @param list<string> $allowedUpdates
     *
     * @throws TelegramSDKException
     */
    public function startPolling(int $timeout = 30, array $allowedUpdates = []): void
    {
        $this->api->deleteWebhook();

        $offset = 0;
        $running = true;

        if (\extension_loaded('pcntl')) {
            pcntl_signal(SIGINT, static function () use (&$running): void {
                $running = false;
            });
            pcntl_signal(SIGTERM, static function () use (&$running): void {
                $running = false;
            });
        }

        while ($running) {
            if (\extension_loaded('pcntl')) {
                pcntl_signal_dispatch();
            }

            try {
                $params = ['offset' => $offset, 'limit' => 100, 'timeout' => $timeout];

                if ($allowedUpdates !== []) {
                    $params['allowed_updates'] = $allowedUpdates;
                }

                foreach ($this->preparePollingUpdates($this->api->getUpdates($params)) as $update) {
                    $updateId = $update['update_id'] ?? 0;
                    $offset = (\is_int($updateId) ? $updateId : 0) + 1;
                    $this->handle($update);
                }
            } catch (Throwable $e) {
                $this->logger->error('Error in polling loop', ['exception' => $e::class, 'message' => $e->getMessage()]);
                sleep(1);
            }
        }
    }

    /**
     * @param array<string, mixed> $params
     *
     * @throws TelegramSDKException
     */
    public function setWebhook(string $url, array $params = []): bool
    {
        $defaults = ['url' => $url];

        if ($this->webhookSecret !== null) {
            $defaults['secret_token'] = $this->webhookSecret;
        }

        return $this->api->setWebhook(array_merge($defaults, $params));
    }

    // -- accessors -----------------------------------------------------------------------------

    public function getApi(): Api
    {
        return $this->api;
    }

    public function getApplication(): Application
    {
        return $this->application();
    }

    public function getConversations(): ConversationManager
    {
        return $this->application()->getConversations();
    }

    public function getPublisher(): TelegramPublisher
    {
        return $this->publisher;
    }

    public function getAdapter(): TelegramPlatformAdapter
    {
        return $this->adapter;
    }

    public function getCallbackEncoder(): TelegramCallbackPayloadEncoder
    {
        return $this->callbackEncoder;
    }

    public function getErrorHandler(): ErrorHandlerInterface
    {
        return $this->errorHandler;
    }

    /**
     * @return list<\ChatFlow\Middleware\MiddlewareInterface|class-string<\ChatFlow\Middleware\MiddlewareInterface>>
     */
    public function getMiddlewares(): array
    {
        return $this->middlewares;
    }

    // -- internals -----------------------------------------------------------------------------

    private function application(): Application
    {
        if ($this->application === null) {
            $conversations = new ConversationManager(
                $this->scenes,
                $this->validation,
                $this->storage ?? new MemoryStorage(),
                $this->transitions,
                $this->sessionTtlSeconds,
                null,
                $this->runtimeObserver,
            );

            $application = new Application(
                adapter: $this->adapter,
                container: $this->container,
                router: $this->router,
                conversations: $conversations,
                errorHandler: $this->errorHandler,
                validationRegistry: $this->validation,
                logger: $this->logger,
                runtimeObserver: $this->runtimeObserver,
            );
            $application->middleware($this->middlewares);

            $this->application = $application;
        }

        return $this->application;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function inboundEvent(array $raw): ?InboundEventInterface
    {
        try {
            return $this->adapter->createInboundEvent($raw);
        } catch (UnsupportedInputException $exception) {
            $this->logger->info('Telegram update skipped', ['reason' => $exception->getMessage()]);

            return null;
        }
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function telegramEventRoute(string $type, array $raw): Route
    {
        $handler = $this->telegramEventHandlers[$type];

        return Route::custom('telegram:' . $type, static function (Context $ctx) use ($handler, $raw): void {
            $ctx->getContainer()->call($handler, [
                Context::class => $ctx,
                InboundEventInterface::class => $ctx->getEvent(),
                'ctx' => $ctx,
                'context' => $ctx,
                'event' => $ctx->getEvent(),
                'update' => $raw,
                'rawUpdate' => $raw,
            ]);
        }, global: true);
    }

    private function mediaRouteFor(InboundEventInterface $event): ?Route
    {
        if ($event->getAttachments() === []) {
            return null;
        }

        foreach ($event->getAttachments() as $attachment) {
            if (isset($this->mediaHandlers[$attachment->getType()])) {
                return Route::custom('media:' . $attachment->getType(), $this->mediaHandlers[$attachment->getType()]);
            }
        }

        if (isset($this->mediaHandlers['any'])) {
            return Route::custom('media:any', $this->mediaHandlers['any']);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $update
     */
    private function detectTelegramUpdateType(array $update): ?string
    {
        foreach (['my_chat_member', 'chat_member'] as $type) {
            if (isset($update[$type])) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Groups album parts that arrived in the same getUpdates() batch so that no waiting is needed.
     *
     * @param iterable<mixed> $updates
     *
     * @return list<array<string, mixed>>
     */
    private function preparePollingUpdates(iterable $updates): array
    {
        $prepared = [];
        $mediaGroups = [];

        foreach ($updates as $update) {
            $raw = $update instanceof Update ? $update->toArray() : (\is_array($update) ? $update : []);
            $raw = self::stringKeys($raw);
            $message = $raw['message'] ?? $raw['edited_message'] ?? null;

            if (!\is_array($message)) {
                $prepared[] = $raw;

                continue;
            }

            $mediaGroupId = $message['media_group_id'] ?? null;

            if (!\is_string($mediaGroupId) || $mediaGroupId === '') {
                $prepared[] = $raw;

                continue;
            }

            $chat = $message['chat'] ?? [];
            $chatId = \is_array($chat) && isset($chat['id']) && \is_scalar($chat['id']) ? (string) $chat['id'] : '0';
            $mediaGroups[$chatId . ':' . $mediaGroupId][] = $raw;
        }

        foreach ($mediaGroups as $groupUpdates) {
            usort($groupUpdates, static fn(array $a, array $b): int => self::messageIdOf($a) <=> self::messageIdOf($b));

            $leader = $groupUpdates[array_key_last($groupUpdates)];
            $messages = [];

            foreach ($groupUpdates as $groupUpdate) {
                $message = $groupUpdate['message'] ?? $groupUpdate['edited_message'] ?? null;

                if (\is_array($message)) {
                    $messages[] = $message;
                }
            }

            $key = isset($leader['message']) ? 'message' : 'edited_message';
            $leaderMessage = \is_array($leader[$key] ?? null) ? $leader[$key] : [];
            $leaderMessage[TelegramMediaGroupCollector::AGGREGATE_KEY] = $messages;
            $leader[$key] = $leaderMessage;
            $prepared[] = $leader;
        }

        usort($prepared, static function (array $a, array $b): int {
            $left = $a['update_id'] ?? 0;
            $right = $b['update_id'] ?? 0;

            return (\is_int($left) ? $left : 0) <=> (\is_int($right) ? $right : 0);
        });

        return $prepared;
    }

    /**
     * @param array<string, mixed> $update
     */
    private static function messageIdOf(array $update): int
    {
        $message = $update['message'] ?? $update['edited_message'] ?? null;

        if (!\is_array($message)) {
            return 0;
        }

        $messageId = $message['message_id'] ?? 0;

        return \is_int($messageId) ? $messageId : 0;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function stringKeys(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            $result[(string) $key] = $value;
        }

        return $result;
    }

    private function registerTelegramDefaultErrorPolicy(): void
    {
        $this->errorHandler->register(TelegramResponseException::class, function (Throwable $exception, ?Context $context): void {
            $message = strtolower($exception->getMessage());

            if (str_contains($message, 'message is not modified') || str_contains($message, 'query is too old')) {
                return;
            }

            if ($context !== null && ($exception->getCode() === 403 || str_contains($message, 'forbidden'))) {
                return;
            }

            $this->logger->error('Telegram API error', [
                'code' => $exception->getCode(),
                'message' => $exception->getMessage(),
            ]);
        });
    }

    private static function deriveCallbackSecret(string $token): string
    {
        return hash('sha256', 'chatflow-telegram-callback:' . $token);
    }
}
