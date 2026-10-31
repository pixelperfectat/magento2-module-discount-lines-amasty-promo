<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLinesAmastyPromo\Service\Resolver;

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\SalesRule\Model\Rule;
use PixelPerfect\DiscountLines\Api\Data\DiscountLineInterface;
use PixelPerfect\DiscountLines\Api\Service\DiscountLineResolverInterface;
use PixelPerfect\DiscountLines\Api\Service\ItemRuleAttributorInterface;
use PixelPerfect\DiscountLines\Api\Service\LabelResolverInterface;
use PixelPerfect\DiscountLines\Api\Service\RuleLoaderInterface;
use PixelPerfect\DiscountLines\Model\Data\DiscountLine;
use PixelPerfect\DiscountLinesAmastyPromo\Api\Service\GiftRuleMatcherInterface;

/**
 * Turns an applied Amasty Free Gift coupon into a `gift` discount line (amount 0, shown as "Gift").
 *
 * Automatic gift rules (no coupon) produce no line. Only the rule's `simple_action` string is read, so no class of
 * the Amasty package is required. This resolver covers the zero-amount case (coupon applied, no gift item in the
 * cart yet). When the gift rule already has a money line (gift item discounted), no second line is added; that line
 * is typed `gift` by {@see GiftCouponLineResolver}.
 */
class AmastyGiftResolver implements DiscountLineResolverInterface
{
    private const EPSILON = 0.00001;

    /**
     * @param RuleLoaderInterface $ruleLoader
     * @param LabelResolverInterface $labelResolver
     * @param GiftRuleMatcherInterface $matcher
     * @param ItemRuleAttributorInterface $attributor Tells which rule owns an item discount with several rules
     */
    public function __construct(
        private readonly RuleLoaderInterface $ruleLoader,
        private readonly LabelResolverInterface $labelResolver,
        private readonly GiftRuleMatcherInterface $matcher,
        private readonly ItemRuleAttributorInterface $attributor
    ) {
    }

    /**
     * @inheritDoc
     */
    public function resolve(Address $address, Quote $quote): array
    {
        $couponCode = trim((string)$quote->getCouponCode());
        if ($couponCode === '') {
            return [];
        }

        $moneyRuleIds = $this->ruleIdsWithItemDiscount($address);
        $ruleIds = array_unique(array_filter(array_map('intval', explode(',', (string)$address->getAppliedRuleIds()))));

        $lines = [];
        foreach ($ruleIds as $ruleId) {
            $rule = $this->ruleLoader->load($ruleId);
            if ($rule === null || !$this->matcher->isGiftCouponRule($rule) || isset($moneyRuleIds[$ruleId])) {
                continue;
            }
            $resolved = $this->labelResolver->resolve($rule, $couponCode, (int)$quote->getStoreId());
            $lines[] = new DiscountLine(
                DiscountLineInterface::KIND_GIFT,
                $ruleId,
                $resolved['code'] ?? $couponCode,
                $resolved['label'],
                0.0,
                0.0,
                count($lines)
            );
        }

        return $lines;
    }

    /**
     * Rules that discount an item by money (the rule resolver then already reports them).
     *
     * @param Address $address
     * @return array<int, true> rule id => true
     */
    private function ruleIdsWithItemDiscount(Address $address): array
    {
        $result = [];
        $extension = $address->getExtensionAttributes();
        // The extension interface is generated at runtime and unknown to static analysis.
        // @phpstan-ignore-next-line
        $discounts = $extension !== null ? $extension->getDiscounts() : null;
        foreach ((array)$discounts as $discount) {
            if (abs((float)$discount->getDiscountData()->getAmount()) >= self::EPSILON) {
                $result[(int)$discount->getRuleID()] = true;
            }
        }

        foreach ($address->getAllItems() as $item) {
            if ($this->isChild($item)) {
                continue;
            }
            $ruleId = $this->ruleOfItemWithoutBreakdown($item);
            if ($ruleId !== null) {
                $result[$ruleId] = true;
            }
        }

        return $result;
    }

    /**
     * Rule of an item that carries a discount but no per-rule breakdown (e.g. a gift added mid-collect).
     *
     * @param AbstractItem $item
     * @return int|null Null when the item has a breakdown, no discount or the rule cannot be told
     */
    private function ruleOfItemWithoutBreakdown(AbstractItem $item): ?int
    {
        if (abs((float)$item->getDiscountAmount()) < self::EPSILON) {
            return null;
        }
        $itemExtension = $item->getExtensionAttributes();
        // The extension interface is generated at runtime and unknown to static analysis.
        // @phpstan-ignore-next-line
        if ($itemExtension !== null && $itemExtension->getDiscounts()) {
            return null;
        }
        $ruleIds = array_unique(array_filter(array_map('intval', explode(',', (string)$item->getAppliedRuleIds()))));

        if (count($ruleIds) === 1) {
            return (int)reset($ruleIds);
        }

        return $this->attributor->attribute($item, array_values($ruleIds));
    }

    /**
     * Whether the item is a child of a composite item (the parent carries the discount).
     *
     * @param AbstractItem $item
     * @return bool
     */
    private function isChild(AbstractItem $item): bool
    {
        // getParentItem() is documented as non-null but returns null for top-level items.
        // @phpstan-ignore-next-line
        return $item->getParentItem() !== null;
    }
}
