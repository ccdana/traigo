<?php

namespace App\Services\Management;

use App\Exceptions\ApiException;
use App\Support\Dates;

/** La bandeja de Dirección: todos los tipos juntos, lo más antiguo primero. */
final class Authorizations
{
    public const KINDS = [
        'weekly_bonus' => WeeklyBonusAuthorization::class,
        'commissions' => CommissionAuthorization::class,
        'price_change' => PriceAuthorization::class,
        'promotion' => PromotionAuthorization::class,
    ];

    public static function of(string $type): AuthorizationKind
    {
        return app(self::KINDS[$type]);
    }

    /** @return list<array<string, mixed>> */
    public static function pending(): array
    {
        return collect(array_keys(self::KINDS))
            ->flatMap(fn (string $type) => self::of($type)->pending())
            ->sortBy(fn (array $item) => strtotime($item['requested_at']))
            ->values()
            ->all();
    }

    /**
     * Una línea de la bandeja.
     *
     * @return array<string, mixed>
     */
    public static function item(
        string $type,
        string $id,
        string $title,
        string $requestedBy,
        string $requestedAt,
        ?string $amount = null,
    ): array {
        return [
            'type' => $type,
            'id' => $id,
            'title' => $title,
            'requested_by' => $requestedBy,
            'requested_at' => Dates::toApi($requestedAt),
            'amount' => $amount,
        ];
    }

    public static function gone(): ApiException
    {
        return ApiException::notFound('Eso ya no espera autorización.');
    }
}
