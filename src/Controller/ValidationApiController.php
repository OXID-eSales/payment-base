<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Controller;

use OxidEsales\Eshop\Application\Controller\FrontendController;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\PaymentBase\Validation\Guard\GuardFailure;
use OxidEsales\PaymentBase\Validation\Guard\ValidationGuardInterface;
use OxidEsales\PaymentBase\Validation\Guard\ValidationRequestContext;
use OxidEsales\PaymentBase\Validation\Message\MessageFormatterInterface;
use OxidEsales\PaymentBase\Validation\ValidationBase;
use OxidEsales\PaymentBase\Validation\ValidationRuleLoaderInterface;
use OxidEsales\PaymentBase\Webhook\Guard\TrustedProxyClientIp;
use Psr\Container\ContainerInterface;

/**
 * Central frontend validation endpoint for all PSP modules.
 *
 * URL: /index.php?cl=oepaymentvalidationapi&fnc=validate
 *
 * Executes the guard chain in priority order. On the first `GuardFailure`
 * the response is an HTTP status with an empty body (no fingerprint for scanners).
 * When all guards pass, runs ValidationBase for the posted fields and returns
 * JSON `{valid: bool, errors: [{field, code, char, message}]}`.
 *
 * OXID admin controllers cannot use constructor DI; this class resolves its
 * dependencies via ContainerFactory inside `init()` into typed properties, which
 * are exposed as protected getters (the testable seam — R-4.2).
 */
class ValidationApiController extends FrontendController
{
    /** @var list<ValidationGuardInterface> */
    private array $guards = [];

    private ?ValidationRuleLoaderInterface $loader = null;

    /** Sprint 29 (VRL): the rate limit counts per visitor address; set in init(), required. */
    private ?TrustedProxyClientIp $clientIpResolver = null;

    /** @var list<MessageFormatterInterface> */
    private array $formatters = [];

    /**
     * Resolve services from the DI container once per request (R-4.2).
     *
     * A failure here is fatal on purpose: an endpoint that swallowed it would
     * run with no guards (no CSRF, origin or rate checks) and answer as if
     * they had passed.
     */
    public function init(): void
    {
        parent::init();

        $container = $this->container();
        $loader = $container->get(ValidationRuleLoaderInterface::class);
        if (!$loader instanceof ValidationRuleLoaderInterface) {
            throw new \RuntimeException('The validation rule loader is not available from the container');
        }
        $clientIpResolver = $container->get(TrustedProxyClientIp::class);
        if (!$clientIpResolver instanceof TrustedProxyClientIp) {
            throw new \RuntimeException('The client address resolver is not available from the container');
        }
        $this->clientIpResolver = $clientIpResolver;

        $this->initWithDependencies(
            $this->taggedServices($container, 'oe.payment_base.validation_guard_iterator', ValidationGuardInterface::class),
            $loader,
            $this->taggedServices($container, 'oe.payment_base.validation_message_formatter_iterator', MessageFormatterInterface::class),
        );
    }

    /**
     * Seam: the shop's DI container.
     */
    protected function container(): ContainerInterface
    {
        // @phpstan-ignore-next-line - OXID container seam
        return ContainerFactory::getInstance()->getContainer();
    }

    /**
     * @template T of object
     * @param class-string<T> $type
     * @return list<T>
     */
    private function taggedServices(ContainerInterface $container, string $iteratorId, string $type): array
    {
        $services = $container->get($iteratorId);
        if (!is_iterable($services)) {
            throw new \RuntimeException(sprintf('Service "%s" is not iterable', $iteratorId));
        }

        $typed = [];
        foreach ($services as $service) {
            if (!$service instanceof $type) {
                throw new \RuntimeException(sprintf('Service "%s" yielded something that is not a %s', $iteratorId, $type));
            }
            $typed[] = $service;
        }

        return $typed;
    }

    /**
     * Initialise collaborators directly (used by the testable subclass;
     * bypasses ContainerFactory so unit tests don't boot OXID).
     *
     * The $formatters argument is trailing and defaults to [] so all existing
     * callers that don't pass formatters continue to work without modification
     * (backwards-compatible additive change — Sprint 119 Phase E).
     *
     * @param iterable<ValidationGuardInterface>  $guards
     * @param iterable<MessageFormatterInterface> $formatters
     */
    protected function initWithDependencies(
        iterable $guards,
        ValidationRuleLoaderInterface $loader,
        iterable $formatters = [],
    ): void {
        $this->guards = $this->sortGuards(
            $guards instanceof \Traversable ? iterator_to_array($guards) : (array) $guards
        );
        $this->loader = $loader;
        $rawFormatters = $formatters instanceof \Traversable
            ? iterator_to_array($formatters)
            : (array) $formatters;
        /** @var list<MessageFormatterInterface> $listFormatters */
        $listFormatters = array_values($rawFormatters);
        $this->formatters = $listFormatters;
    }

    /**
     * Handle POST /index.php?cl=oepaymentvalidationapi&fnc=validate
     *
     * @return string JSON body (empty on guard failure)
     */
    public function validate(): string
    {
        $ctx = $this->buildRequestContext();

        $guardResult = $this->runGuards($ctx);
        if ($guardResult !== null) {
            return $this->sendFailureResponse($guardResult->httpStatus);
        }

        $json = $this->runValidation($ctx);
        $this->setHttpStatus(200);
        // @phpstan-ignore-next-line — OXID Registry seam
        Registry::getUtils()->setHeader('Content-Type: application/json');

        return $this->sendJsonResponse($json);
    }

    /**
     * Emit the JSON body and stop, so OXID does not render a page template
     * around it (the controller extends FrontendController). Mirrors
     * sendFailureResponse()'s exit pattern.
     *
     * Protected so the testable subclass returns the body instead of exiting.
     */
    protected function sendJsonResponse(string $json): string
    {
        echo $json;
        exit;
    }

    /**
     * Build the request context from PHP globals.
     * Protected so the testable subclass can return a stub.
     */
    protected function buildRequestContext(): ValidationRequestContext
    {
        if ($this->clientIpResolver === null) {
            throw new \RuntimeException('TrustedProxyClientIp not initialised');
        }

        return ValidationRequestContext::fromRequest($this->clientIpResolver);
    }

    /**
     * Emit the HTTP failure status and return an empty body.
     * Protected so the testable subclass can capture the status without exit.
     *
     * @return never-return in production (exit); string '' in tests via override
     */
    protected function sendFailureResponse(int $httpStatus): string
    {
        $this->setHttpStatus($httpStatus);
        exit;
    }

    /**
     * Set HTTP status via header(). Protected for testable override.
     */
    protected function setHttpStatus(int $status): void
    {
        http_response_code($status);
    }

    /**
     * Iterate guards in priority order; return first failure or null.
     */
    private function runGuards(ValidationRequestContext $ctx): ?GuardFailure
    {
        foreach ($this->guards as $guard) {
            $failure = $guard->check($ctx);
            if ($failure !== null) {
                return $failure;
            }
        }

        return null;
    }

    /**
     * Run field-level validation via ValidationBase and encode as JSON.
     */
    private function runValidation(ValidationRequestContext $ctx): string
    {
        $pluginModuleId = $ctx->getPluginModuleId();
        $validator = new ValidationBase($pluginModuleId, $this->getLoader());
        $formatter = $this->findFormatter($pluginModuleId);
        $errors = [];

        foreach ($ctx->getFields() as $fieldName => $value) {
            $result = $validator->validateField((string) $fieldName, $value);
            if (!$result->valid) {
                $errors[] = [
                    'field'   => $fieldName,
                    'code'    => $result->code,
                    'char'    => $result->offendingChar,
                    'message' => $formatter !== null && $result->code !== null
                        ? $formatter->format((string) $fieldName, $result->code, $result->offendingChar)
                        : null,
                ];
            }
        }

        return (string) json_encode(['valid' => $errors === [], 'errors' => $errors]);
    }

    /**
     * Find the first formatter whose plugin module ID matches, or null if none registered.
     */
    private function findFormatter(string $pluginModuleId): ?MessageFormatterInterface
    {
        foreach ($this->formatters as $formatter) {
            if ($formatter->getPluginModuleId() === $pluginModuleId) {
                return $formatter;
            }
        }

        return null;
    }

    private function getLoader(): ValidationRuleLoaderInterface
    {
        if ($this->loader === null) {
            throw new \RuntimeException('ValidationRuleLoaderInterface not initialised');
        }

        return $this->loader;
    }

    /**
     * @param array<ValidationGuardInterface> $guards
     * @return list<ValidationGuardInterface>
     */
    private function sortGuards(array $guards): array
    {
        usort($guards, static fn (ValidationGuardInterface $a, ValidationGuardInterface $b): int => $a->getPriority() <=> $b->getPriority());

        return $guards;
    }
}
