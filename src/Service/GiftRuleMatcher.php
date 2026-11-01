<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLinesAmastyPromo\Service;

use Magento\SalesRule\Model\Rule;
use PixelPerfect\DiscountLinesAmastyPromo\Api\Service\GiftRuleMatcherInterface;

/**
 * Identifies Amasty Free Gift rules by the prefix of the sales rule action; no Amasty class is needed.
 */
class GiftRuleMatcher implements GiftRuleMatcherInterface
{
    public const ACTION_PREFIX = 'ampromo_';

    /**
     * @param string $actionPrefix Prefix of the sales rule actions that add gifts
     */
    public function __construct(
        private readonly string $actionPrefix = self::ACTION_PREFIX
    ) {
    }

    /**
     * @inheritDoc
     */
    public function isGiftCouponRule(Rule $rule): bool
    {
        return (int)$rule->getCouponType() !== Rule::COUPON_TYPE_NO_COUPON
            && str_starts_with((string)$rule->getSimpleAction(), $this->actionPrefix);
    }
}
