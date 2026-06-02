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
 *
 * Sprint 119 (STRP-129) — Phase A2.
 */
class ValidationApiController extends FrontendController
{
    /** @var list<ValidationGuardInterface> */
    private array $guards = [];

    private ?ValidationRuleLoaderInterface $loader = null;

    /** @var list<MessageFormatterInterface> */
    private array $formatters = [];

    /**
     * Resolve services from the DI container once per request (R-4.2).
     */
    public function init(): void
    {
        parent::init();

        try {
            $container = ContainerFactory::getInstance()->getContainer();
            $taggedGuards = $container->get('oe.payment_base.validation_guard_iterator');
            if ($taggedGuards instanceof \Traversable || is_array($taggedGuards)) {
                /** @var iterable<ValidationGuardInterface> $taggedGuards */
                $guardArray = $taggedGuards instanceof \Traversable
                    ? iterator_to_array($taggedGuards)
                    : (array) $taggedGuards;
                $this->guards = $this->sortGuards($guardArray);
            }
            $loader = $container->get(ValidationRuleLoaderInterface::class);
            if ($loader instanceof ValidationRuleLoaderInterface) {
                $this->loader = $loader;
            }
            $taggedFormatters = $container->get('oe.payment_base.validation_message_formatter_iterator');
            if ($taggedFormatters instanceof \Traversable || is_array($taggedFormatters)) {
                /** @var iterable<MessageFormatterInterface> $taggedFormatters */
                $rawFormatters = $taggedFormatters instanceof \Traversable
                    ? iterator_to_array($taggedFormatters)
                    : (array) $taggedFormatters;
                /** @var list<MessageFormatterInterface> $listFormatters */
                $listFormatters = array_values($rawFormatters);
                $this->formatters = $listFormatters;
            }
        } catch (\Throwable $e) {
            // @phpstan-ignore-next-line — OXID Registry seam
            Registry::getLogger()->error('[ValidationApiController] init failed', ['error' => $e->getMessage()]);
        }
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
        return ValidationRequestContext::fromRequest();
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
