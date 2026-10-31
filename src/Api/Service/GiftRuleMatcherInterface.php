<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLinesAmastyPromo\Api\Service;

use Magento\SalesRule\Model\Rule;

interface GiftRuleMatcherInterface
{
    /**
     * Whether the rule is a gift rule that is triggered by a coupon.
     *
     * @param Rule $rule
     * @return bool
     */
    public function isGiftCouponRule(Rule $rule): bool;
}
