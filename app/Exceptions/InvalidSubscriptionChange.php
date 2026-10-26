<?php

namespace App\Exceptions;

use DomainException;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class InvalidSubscriptionChange extends DomainException
{
    public static function planBelongsToAnotherMerchant(): self
    {
        return new self('The plan belongs to a different merchant.');
    }

    public static function planInactive(): self
    {
        return new self('The plan is not active.');
    }

    public static function alreadySubscribed(): self
    {
        return new self('The customer already has an active subscription.');
    }

    public static function notActive(): self
    {
        return new self('Only active subscriptions can change plan.');
    }

    public static function samePlan(): self
    {
        return new self('The subscription is already on this plan.');
    }

    public static function billingCycleMismatch(): self
    {
        return new self('Plan changes must keep the same billing cycle (monthly to monthly, yearly to yearly).');
    }

    public static function effectiveDateInPast(): self
    {
        return new self('A plan change cannot take effect in the past.');
    }

    public static function effectiveBeforeCurrentSegment(): self
    {
        return new self('A plan change cannot take effect before the current plan segment starts.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
