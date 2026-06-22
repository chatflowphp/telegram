<?php

declare(strict_types=1);

namespace ChatFlow\Telegram;

use ChatFlow\Config\Config;
use ChatFlow\Config\ConfigInterface;
use ChatFlow\Container\Container;
use ChatFlow\Container\ContainerInterface;
use ChatFlow\Contracts\FlowRuntimeInterface;
use ChatFlow\Contracts\InboundEventInterface;
use ChatFlow\Core\Application;
use ChatFlow\Core\Context;
use ChatFlow\Core\Result;
use ChatFlow\Exception\ConfigException;
use ChatFlow\Exception\ContainerException;
use ChatFlow\Exception\DependencyException;
use ChatFlow\Exception\ErrorHandlerInterface;
use ChatFlow\Exception\ExceptionRegistry;
use ChatFlow\Exception\ValidationException;
use ChatFlow\FSM\BaseScene;
use ChatFlow\FSM\SceneRegistry;
use ChatFlow\FSM\StateManager;
use ChatFlow\Middleware\MiddlewareInterface;
use ChatFlow\Observability\RuntimeObserverInterface;
use ChatFlow\Provider\ProviderInterface;
use ChatFlow\Routing\Route;
use ChatFlow\Routing\Router;
use ChatFlow\Storage\StorageInterface;
use ChatFlow\Telegram\Callback\FileTelegramCallbackStore;
use ChatFlow\Telegram\Callback\TelegramCallbackPayloadEncoder;
use ChatFlow\Telegram\MediaGroup\FileTelegramMediaGroupStore;
use ChatFlow\Telegram\MediaGroup\TelegramMediaGroupCollector;
use ChatFlow\Telegram\UI\TelegramScreenManager;
use ChatFlow\Validation\ValidationRegistry;
use DI\ContainerBuilder;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramResponseException;
use Telegram\Bot\Exceptions\TelegramSDKException;
use Telegram\Bot\Objects\Update;
use Throwable;

class Bot implements FlowRuntimeInterface
{
    /** @var array<MiddlewareInterface|class-string<MiddlewareInterface>> */
    private array $middlewares = [];

    private Router $router;

    private ContainerInterface $container;

    private ErrorHandlerInterface $errorHandler;

    private SceneRegistry $sceneRegistry;

    private LoggerInterface $logger;

    private ValidationRegistry $validationRegistry;

    private ConfigInterface $config;

    private TelegramPlatformAdapter $adapter;

    private ?StateManager $stateManager = null;

    private Application $application;

    private ?RuntimeObserverInterface $runtimeObserver;

    private TelegramPublisher $publisher;

    private TelegramMediaGroupCollector $mediaGroupCollector;

    /** @var array<string, callable> */
    private array $telegramEventHandlers = [];

    /** @var array<string, callable> */
    private array $mediaHandlers = [];

    /**
     * @throws DependencyException
     * @throws TelegramSDKException
     * @throws ConfigException
     * @throws ContainerException
     */
    public function __construct(
        private readonly string $token,
        string $basePath,
        ?LoggerInterface $logger = null,
        ?ContainerInterface $container = null,
        private readonly ?string $webhookSecret = null,
        ?ConfigInterface $config = null,
        ?Api $api = null,
        ?ErrorHandlerInterface $errorHandler = null,
        ?Router $router = null,
        ?SceneRegistry $sceneRegistry = null,
        ?ValidationRegistry $validationRegistry = null,
        ?Application $application = null,
        ?RuntimeObserverInterface $runtimeObserver = null,
        ?TelegramCallbackPayloadEncoder $callbackPayloadEncoder = null,
        ?TelegramMediaGroupCollector $mediaGroupCollector = null,
        ?TelegramPublisher $publisher = null,
    ) {
        $this->runtimeObserver = $runtimeObserver;
        $this->config = $config ?? new Config($basePath);
        $this->container = $container ?? $this->createContainer();
        $this->container->set(ConfigInterface::class, $this->config);

        $this->logger = $logger ?? new NullLogger();
        $this->api = $api ?? new Api($this->token);

        $fileDownloader = new FileDownloader($this->api);
        $this->publisher = $publisher ?? new TelegramPublisher($this->api);
        $this->container->set(Api::class, $this->api);
        $this->container->set(FileDownloader::class, $fileDownloader);
        $this->container->set(TelegramPublisher::class, $this->publisher);

        $this->router = $router ?? new Router();
        $this->validationRegistry = $validationRegistry ?? new ValidationRegistry($this->container);
        $this->container->set(ValidationRegistry::class, $this->validationRegistry);

        $this->sceneRegistry = $sceneRegistry ?? new SceneRegistry($this->container);
        $this->errorHandler = $errorHandler ?? new ExceptionRegistry($this->logger, $this->config);
        $this->registerTelegramDefaultErrorPolicy();

        $callbackPayloadEncoder ??= new TelegramCallbackPayloadEncoder(
            new FileTelegramCallbackStore($basePath . '/storage/telegram-callbacks')
        );
        $this->container->set(TelegramCallbackPayloadEncoder::class, $callbackPayloadEncoder);

        $this->mediaGroupCollector = $mediaGroupCollector ?? new TelegramMediaGroupCollector(
            new FileTelegramMediaGroupStore($basePath . '/storage/telegram-media-groups')
        );
        $this->container->set(TelegramMediaGroupCollector::class, $this->mediaGroupCollector);

        $screenManager = new TelegramScreenManager($this->publisher);
        $this->adapter = new TelegramPlatformAdapter(
            $this->api,
            $fileDownloader,
            $callbackPayloadEncoder,
            $screenManager,
            $this->logger
        );
        $this->container->set(TelegramPlatformAdapter::class, $this->adapter);
        $this->container->set(TelegramScreenManager::class, $screenManager);

        $this->application = $application ?? $this->createApplication();
    }

    private Api $api;

    public function command(string $name, callable $handler): self
    {
        $this->router->onCommand($name, $handler);

        return $this;
    }

    public function prefix(string $prefix, callable $handler): self
    {
        $this->router->onActionPrefix($prefix, $handler);

        return $this;
    }

    public function onTextPrefix(string $prefix, callable $handler): Route
    {
        return $this->router->onTextPrefix($prefix, $handler);
    }

    public function onTextRegex(string $pattern, callable $handler): Route
    {
        return $this->router->onTextRegex($pattern, $handler);
    }

    public function onCommand(string $command, callable $handler): Route
    {
        return $this->router->onCommand($command, $handler);
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

    public function onTelegramEvent(string $updateType, callable $handler): self
    {
        $this->telegramEventHandlers[$updateType] = $handler;

        return $this;
    }

    public function onMedia(string $type, callable $handler): self
    {
        $this->mediaHandlers[$type] = $handler;

        return $this;
    }

    public function handle(Update|array $update): Result
    {
        $raw = $update instanceof Update ? $update->toArray() : $update;
        $telegramUpdateType = $this->detectTelegramUpdateType($raw);

        if ($telegramUpdateType !== null && isset($this->telegramEventHandlers[$telegramUpdateType])) {
            return $this->handleOutOfBand($this->adapter->createInboundEvent($raw), $this->telegramEventHandlers[$telegramUpdateType], $raw);
        }

        $collectedUpdate = $this->mediaGroupCollector->collect($raw);
        if ($collectedUpdate === null) {
            return Result::noMatch('telegram_media_group_pending');
        }

        $event = $this->adapter->createInboundEvent($collectedUpdate);

        if ($this->shouldHandleMediaOutOfBand($event)) {
            $handler = $this->mediaHandlerFor($event);
            if ($handler !== null) {
                return $this->handleOutOfBand($event, $handler, $collectedUpdate);
            }
        }

        return $this->application->handle($event);
    }

    /**
     * @throws TelegramSDKException
     */
    public function runWebhook(): Result
    {
        $this->validateWebhook();

        return $this->handle($this->api->getWebhookUpdate());
    }

    /**
     * @param array<string> $allowedUpdates
     *
     * @throws TelegramSDKException
     */
    public function startPolling(int $timeout = 30, array $allowedUpdates = []): void
    {
        $this->api->deleteWebhook();

        $offset = 0;
        $running = true;

        if (extension_loaded('pcntl')) {
            pcntl_signal(SIGINT, static function () use (&$running): void {
                $running = false;
            });
            pcntl_signal(SIGTERM, static function () use (&$running): void {
                $running = false;
            });
        }

        while ($running) {
            if (extension_loaded('pcntl')) {
                pcntl_signal_dispatch();
            }

            try {
                $updates = $this->api->getUpdates([
                    'offset' => $offset,
                    'limit' => 100,
                    'timeout' => $timeout,
                    'allowed_updates' => $allowedUpdates !== [] ? $allowedUpdates : null,
                ]);

                foreach ($this->preparePollingUpdates($updates) as $update) {
                    /** @var int $updateId */
                    $updateId = $update['update_id'] ?? 0;
                    $offset = $updateId + 1;
                    $this->handle($update);
                }
            } catch (Throwable $e) {
                $this->logger->error('Error in polling loop', ['message' => $e->getMessage()]);
                sleep(1);
            }
        }
    }

    /**
     * @param array<string, mixed> $params
     *
     * @throws TelegramSDKException
     */
    public function setWebhook(string $url, array $params = []): bool|string
    {
        $defaultParams = ['url' => $url];
        if ($this->webhookSecret !== null) {
            $defaultParams['secret_token'] = $this->webhookSecret;
        }

        return $this->api->setWebhook(array_merge($defaultParams, $params));
    }

    public function useStorage(StorageInterface $storage): self
    {
        $this->stateManager = new StateManager($this->sceneRegistry, $storage);
        $this->application = $this->createApplication();

        return $this;
    }

    /**
     * @param class-string<BaseScene> $sceneClass
     *
     * @throws ValidationException
     */
    public function registerScene(string $sceneClass, ?string $label = null): self
    {
        $this->sceneRegistry->register($sceneClass, $label);

        return $this;
    }

    public function getContainer(): ContainerInterface
    {
        return $this->container;
    }

    /**
     * @param array<MiddlewareInterface|class-string<MiddlewareInterface>> $middlewares
     */
    public function middleware(array $middlewares): self
    {
        foreach ($middlewares as $middleware) {
            $this->middlewares[] = $middleware;
        }

        $this->application->middleware($middlewares);

        return $this;
    }

    /**
     * @return array<MiddlewareInterface|class-string<MiddlewareInterface>>
     */
    public function getMiddlewares(): array
    {
        return $this->middlewares;
    }

    public function getValidationRegistry(): ValidationRegistry
    {
        return $this->validationRegistry;
    }

    /**
     * @throws ContainerException
     */
    public function addProvider(ProviderInterface $provider): self
    {
        $provider->register($this->container);

        return $this;
    }

    /**
     * @param callable(Throwable, \ChatFlow\Core\Context): void $handler
     */
    public function setErrorHandler(callable $handler): self
    {
        $this->errorHandler->register(Throwable::class, static function (
            Throwable $exception,
            ?\ChatFlow\Core\Context $context
        ) use ($handler): void {
            if ($context !== null) {
                $handler($exception, $context);
            }
        });

        return $this;
    }

    /**
     * @param class-string<Throwable>                            $exception
     * @param callable(Throwable, ?\ChatFlow\Core\Context): void $handler
     */
    public function onException(string $exception, callable $handler): self
    {
        $this->errorHandler->register($exception, $handler);

        return $this;
    }

    public function getConfig(): ConfigInterface
    {
        return $this->config;
    }

    public function getApi(): Api
    {
        return $this->api;
    }

    public function getApplication(): Application
    {
        return $this->application;
    }

    public function getStateManager(): ?StateManager
    {
        return $this->stateManager;
    }

    public function getPublisher(): TelegramPublisher
    {
        return $this->publisher;
    }

    /**
     * @param array<string, mixed> $rawUpdate
     */
    private function handleOutOfBand(InboundEventInterface $event, callable $handler, array $rawUpdate): Result
    {
        $context = new Context($event, $this->adapter, $this->container, $this->stateManager);

        try {
            $this->bindRuntimeDependencies($context);

            if ($this->stateManager !== null) {
                $session = $this->stateManager->loadSession($event->getConversationId());
                $context->setSession($session);
            }

            $telegramContext = $this->container->get(TelegramContext::class);
            if (!$telegramContext instanceof TelegramContext) {
                throw new ContainerException('TelegramContext is not registered.');
            }

            $this->container->call($handler, [
                Context::class => $context,
                TelegramContext::class => $telegramContext,
                InboundEventInterface::class => $event,
                'ctx' => $context,
                'context' => $context,
                'telegram' => $telegramContext,
                'event' => $event,
                'update' => $rawUpdate,
                'rawUpdate' => $rawUpdate,
            ]);

            if ($this->stateManager !== null && $context->getSession() !== null) {
                $this->stateManager->saveSession($context->session());
            }

            return $this->flushOutboundEffects($context, Result::success('telegram_handler_processed'));
        } catch (Throwable $exception) {
            $this->errorHandler->handle($exception, $context);

            return $this->flushOutboundEffects(
                $context,
                Result::error($exception->getMessage(), ['exception' => $exception::class])
            );
        } finally {
            $this->container->flush();
        }
    }

    private function bindRuntimeDependencies(Context $context): void
    {
        $this->container->set(Context::class, $context);
        $this->container->set(InboundEventInterface::class, $context->getEvent());

        if ($this->stateManager !== null) {
            $this->container->set(StateManager::class, $this->stateManager);
        }

        $this->adapter->bindRuntimeDependencies($this->container, $context);
    }

    private function flushOutboundEffects(Context $context, Result $result): Result
    {
        foreach ($context->getOutboundEffects() as $effect) {
            $delivery = $this->adapter->deliver($context, $effect);
            if ($delivery->isError()) {
                $context->clearOutboundEffects();

                return Result::error('delivery_failed', [
                    'reason' => $delivery->getMessage(),
                    'effect' => $effect->getType(),
                    'data' => $delivery->getData(),
                ]);
            }
        }

        $context->clearOutboundEffects();

        return $result;
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

    private function shouldHandleMediaOutOfBand(InboundEventInterface $event): bool
    {
        if ($event->getAttachments() === []) {
            return false;
        }

        if ($this->stateManager === null) {
            return true;
        }

        $session = $this->stateManager->loadSession($event->getConversationId());

        return !$session->hasScene();
    }

    private function mediaHandlerFor(InboundEventInterface $event): ?callable
    {
        foreach ($event->getAttachments() as $attachment) {
            if (isset($this->mediaHandlers[$attachment->getType()])) {
                return $this->mediaHandlers[$attachment->getType()];
            }
        }

        return $this->mediaHandlers['any'] ?? null;
    }

    /**
     * @param iterable<mixed> $updates
     *
     * @return list<array<string, mixed>>
     */
    private function preparePollingUpdates(iterable $updates): array
    {
        $prepared = [];
        $mediaGroups = [];

        foreach ($updates as $update) {
            $raw = $update instanceof Update ? $update->toArray() : (is_array($update) ? $update : []);
            $message = $raw['message'] ?? $raw['edited_message'] ?? null;
            if (!is_array($message)) {
                $prepared[] = $raw;
                continue;
            }

            $mediaGroupId = $message['media_group_id'] ?? null;
            if (!is_string($mediaGroupId) || $mediaGroupId === '') {
                $prepared[] = $raw;
                continue;
            }

            $chat = $message['chat'] ?? [];
            $chatId = is_array($chat) && isset($chat['id']) && is_scalar($chat['id']) ? (string) $chat['id'] : '0';
            $mediaGroups[$chatId . ':' . $mediaGroupId][] = $raw;
        }

        foreach ($mediaGroups as $groupUpdates) {
            usort($groupUpdates, static function (array $a, array $b): int {
                $aMessage = $a['message'] ?? $a['edited_message'] ?? [];
                $bMessage = $b['message'] ?? $b['edited_message'] ?? [];
                $aMessageId = is_array($aMessage) && isset($aMessage['message_id']) && is_int($aMessage['message_id'])
                    ? $aMessage['message_id']
                    : 0;
                $bMessageId = is_array($bMessage) && isset($bMessage['message_id']) && is_int($bMessage['message_id'])
                    ? $bMessage['message_id']
                    : 0;

                return $aMessageId <=> $bMessageId;
            });

            $leader = $groupUpdates[array_key_last($groupUpdates)];
            $messages = [];
            foreach ($groupUpdates as $groupUpdate) {
                $message = $groupUpdate['message'] ?? $groupUpdate['edited_message'] ?? null;
                if (is_array($message)) {
                    $messages[] = $message;
                }
            }

            $leader['message']['__chatflow_media_group_messages'] = $messages;
            $prepared[] = $leader;
        }

        usort($prepared, static fn (array $a, array $b): int => ((int) ($a['update_id'] ?? 0)) <=> ((int) ($b['update_id'] ?? 0)));

        return $prepared;
    }

    /**
     * @throws ContainerException
     */
    private function createApplication(): Application
    {
        $application = new Application(
            adapter: $this->adapter,
            router: $this->router,
            container: $this->container,
            errorHandler: $this->errorHandler,
            validationRegistry: $this->validationRegistry,
            stateManager: $this->stateManager,
            logger: $this->logger,
            runtimeObserver: $this->runtimeObserver,
        );

        if ($this->middlewares !== []) {
            $application->middleware($this->middlewares);
        }

        return $application;
    }

    private function createContainer(): Container
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        $builder->useAttributes(false);

        return new Container($builder, autowire: true, useAttributes: false);
    }

    private function registerTelegramDefaultErrorPolicy(): void
    {
        $this->errorHandler->register(TelegramResponseException::class, function (
            TelegramResponseException $exception,
            ?\ChatFlow\Core\Context $context
        ): void {
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

    /**
     * @throws ValidationException
     */
    private function validateWebhook(): void
    {
        if ($this->webhookSecret === null) {
            return;
        }

        $providedToken = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? null;
        if (!is_string($providedToken) || $providedToken !== $this->webhookSecret) {
            throw new ValidationException('Invalid webhook secret token.');
        }
    }
}
