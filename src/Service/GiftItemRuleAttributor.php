<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLinesAmastyPromo\Service;

use Magento\Quote\Model\Quote\Item\AbstractItem;
use PixelPerfect\DiscountLines\Api\Service\ItemRuleAttributorInterface;
use PixelPerfect\DiscountLines\Api\Service\RuleLoaderInterface;
use PixelPerfect\DiscountLinesAmastyPromo\Api\Service\GiftRuleMatcherInterface;

/**
 * Credits the discount of an item without per-rule breakdown to the gift coupon rule among its applied rules.
 *
 * Amasty adds the gift item during the totals collection and discounts it itself, next to automatic rules that
 * also match it. Such an item is the gift, so the gift coupon rule owns its discount.
 */
class GiftItemRuleAttributor implements ItemRuleAttributorInterface
{
    /**
     * @param RuleLoaderInterface $ruleLoader
     * @param GiftRuleMatcherInterface $matcher
     */
    public function __construct(
        private readonly RuleLoaderInterface $ruleLoader,
        private readonly GiftRuleMatcherInterface $matcher
    ) {
    }

    /**
     * @inheritDoc
     */
    public function attribute(AbstractItem $item, array $ruleIds): ?int
    {
        $found = [];
        foreach ($ruleIds as $ruleId) {
            $rule = $this->ruleLoader->load($ruleId);
            if ($rule !== null && $this->matcher->isGiftCouponRule($rule)) {
                $found[] = $ruleId;
            }
        }

        return count($found) === 1 ? $found[0] : null;
    }
}
