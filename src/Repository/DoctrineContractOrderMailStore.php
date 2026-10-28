<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use JsonException;
use OxidEsales\PaymentBase\Mail\ClaimedOrderMails;
use OxidEsales\PaymentBase\Mail\DeferredOrderMail;
use RuntimeException;

/**
 * STRP-176 — the order-mail columns of `oe_payments_contract`.
 *
 * The table is latin1. The mails are JSON with escaped unicode (json_encode()'s
 * default), so the stored text is plain ASCII and every €, umlaut and name in
 * any script comes back byte for byte. JSON_UNESCAPED_UNICODE here would
 * silently corrupt them.
 *
 * @since 1.6.0
 */
class DoctrineContractOrderMailStore implements ContractOrderMailStoreInterface
{
    private const TABLE = 'oe_payments_contract';

    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * @inheritDoc
     */
    public function stash(string $contractId, array $mails): bool
    {
        return $this->write(
            'UPDATE ' . self::TABLE . '
             SET OXORDERMAILSTATE = :pending, OXORDERMAILS = :mails, OXORDERMAILSENTAT = NULL
             WHERE OXID = :id',
            ['pending' => self::STATE_PENDING, 'mails' => $this->encode($mails), 'id' => $contractId],
            'store the order mails of contract ' . $contractId
        ) === 1;
    }

    public function claim(string $contractId): ?ClaimedOrderMails
    {
        $claimed = $this->write(
            'UPDATE ' . self::TABLE . ' c
             JOIN oxorder o ON o.OXID = c.OXORDERID
             SET c.OXORDERMAILSTATE = :sending
             WHERE c.OXID = :id AND c.OXORDERMAILSTATE = :pending AND o.OXSTORNO = 0',
            ['sending' => self::STATE_SENDING, 'pending' => self::STATE_PENDING, 'id' => $contractId],
            'claim the order mails of contract ' . $contractId
        );
        if ($claimed !== 1) {
            return null;
        }

        $row = $this->read($contractId);

        return new ClaimedOrderMails(
            contractId: $contractId,
            orderId: (string) ($row['OXORDERID'] ?? ''),
            shopId: (int) ($row['OXSHOPID'] ?? 1),
            mails: $this->decode((string) ($row['OXORDERMAILS'] ?? '')),
        );
    }

    public function markSent(string $contractId): void
    {
        $this->write(
            'UPDATE ' . self::TABLE . '
             SET OXORDERMAILSTATE = :sent, OXORDERMAILS = NULL, OXORDERMAILSENTAT = NOW()
             WHERE OXID = :id AND OXORDERMAILSTATE = :sending',
            ['sent' => self::STATE_SENT, 'sending' => self::STATE_SENDING, 'id' => $contractId],
            'mark the order mails of contract ' . $contractId . ' as sent'
        );
    }

    /**
     * @inheritDoc
     */
    public function markFailed(string $contractId, array $unsent): void
    {
        $this->write(
            'UPDATE ' . self::TABLE . '
             SET OXORDERMAILSTATE = :failed, OXORDERMAILS = :mails
             WHERE OXID = :id AND OXORDERMAILSTATE = :sending',
            [
                'failed' => self::STATE_FAILED,
                'mails' => $this->encode($unsent),
                'sending' => self::STATE_SENDING,
                'id' => $contractId,
            ],
            'mark the order mails of contract ' . $contractId . ' as failed'
        );
    }

    public function dropByOrderId(string $orderId): int
    {
        return $this->write(
            'UPDATE ' . self::TABLE . '
             SET OXORDERMAILSTATE = :dropped, OXORDERMAILS = NULL
             WHERE OXORDERID = :orderId AND OXORDERMAILSTATE IN (:pending, :failed)',
            [
                'dropped' => self::STATE_DROPPED,
                'orderId' => $orderId,
                'pending' => self::STATE_PENDING,
                'failed' => self::STATE_FAILED,
            ],
            'drop the order mails of order ' . $orderId
        );
    }

    /**
     * @param array<string, string> $params
     */
    private function write(string $sql, array $params, string $what): int
    {
        try {
            return (int) $this->connection->executeStatement($sql, $params);
        } catch (Exception $e) {
            throw new RuntimeException('Failed to ' . $what . ': ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function read(string $contractId): array
    {
        try {
            $row = $this->connection->fetchAssociative(
                'SELECT OXORDERID, OXSHOPID, OXORDERMAILS FROM ' . self::TABLE . ' WHERE OXID = :id',
                ['id' => $contractId]
            );
        } catch (Exception $e) {
            throw new RuntimeException(
                'Failed to read the order mails of contract ' . $contractId . ': ' . $e->getMessage(),
                0,
                $e
            );
        }

        return $row === false ? [] : $row;
    }

    /**
     * @param list<DeferredOrderMail> $mails
     */
    private function encode(array $mails): string
    {
        return json_encode(
            array_map(static fn (DeferredOrderMail $mail): array => [
                'kind' => $mail->kind,
                'from' => $mail->from,
                'fromName' => $mail->fromName,
                'recipients' => $mail->recipients,
                'replyTo' => $mail->replyTo,
                'cc' => $mail->cc,
                'bcc' => $mail->bcc,
                'subject' => $mail->subject,
                'body' => $mail->body,
                'altBody' => $mail->altBody,
            ], $mails),
            JSON_THROW_ON_ERROR
        );
    }

    /**
     * Plain data only - no unserialize() of database content.
     *
     * @return list<DeferredOrderMail>
     */
    private function decode(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        $mails = [];
        foreach (is_array($decoded) ? $decoded : [] as $mail) {
            if (is_array($mail)) {
                $mails[] = $this->mailFrom($mail);
            }
        }

        return $mails;
    }

    /**
     * @param array<mixed> $mail
     */
    private function mailFrom(array $mail): DeferredOrderMail
    {
        return new DeferredOrderMail(
            kind: $this->text($mail, 'kind'),
            from: $this->text($mail, 'from'),
            fromName: $this->text($mail, 'fromName'),
            recipients: $this->addressList($mail['recipients'] ?? null),
            replyTo: $this->addressList($mail['replyTo'] ?? null),
            cc: $this->addressList($mail['cc'] ?? null),
            bcc: $this->addressList($mail['bcc'] ?? null),
            subject: $this->text($mail, 'subject'),
            body: $this->text($mail, 'body'),
            altBody: $this->text($mail, 'altBody'),
        );
    }

    /**
     * @param array<mixed> $mail
     */
    private function text(array $mail, string $key): string
    {
        return is_scalar($mail[$key] ?? null) ? (string) $mail[$key] : '';
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function addressList(mixed $pairs): array
    {
        $list = [];
        foreach (is_array($pairs) ? $pairs : [] as $pair) {
            if (is_array($pair) && isset($pair[0]) && is_scalar($pair[0])) {
                $list[] = [(string) $pair[0], is_scalar($pair[1] ?? null) ? (string) $pair[1] : ''];
            }
        }

        return $list;
    }
}
