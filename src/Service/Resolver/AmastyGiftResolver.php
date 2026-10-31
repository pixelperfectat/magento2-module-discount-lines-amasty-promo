<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLinesAmastyPromo\Service\Resolver;

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Item\AbstractItem;
use Magento\SalesRule\Model\Rule;
use PixelPerfect\DiscountLines\Api\Data\DiscountLineInterface;
use PixelPerfect\DiscountLines\Api\Service\DiscountLineResolverInterface;
use PixelPerfect\DiscountLines\Api\Service\LabelResolverInterface;
use PixelPerfect\DiscountLines\Api\Service\RuleLoaderInterface;
use PixelPerfect\DiscountLines\Model\Data\DiscountLine;

/**
 * Turns an applied Amasty Free Gift coupon into a `gift` discount line (amount 0, shown as "Gift").
 *
 * Automatic gift rules (no coupon) produce no line. Only the rule's `simple_action` string is read, so no class of
 * the Amasty package is required. A gift rule that also discounts an item (gift priced below 100 %) already has a
 * money line from the rule resolver; it gets no second, gift line.
 */
class AmastyGiftResolver implements DiscountLineResolverInterface
{
    public const ACTION_PREFIX = 'ampromo_';

    private const EPSILON = 0.00001;

    /**
     * @param RuleLoaderInterface $ruleLoader
     * @param LabelResolverInterface $labelResolver
     * @param string $actionPrefix Prefix of the sales rule actions that add gifts
     */
    public function __construct(
        private readonly RuleLoaderInterface $ruleLoader,
        private readonly LabelResolverInterface $labelResolver,
        private readonly string $actionPrefix = self::ACTION_PREFIX
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
            if ($rule === null || !$this->isGiftCouponRule($rule) || isset($moneyRuleIds[$ruleId])) {
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
     * Whether the rule is a gift rule that is triggered by a coupon.
     *
     * @param Rule $rule
     * @return bool
     */
    private function isGiftCouponRule(Rule $rule): bool
    {
        return (int)$rule->getCouponType() !== Rule::COUPON_TYPE_NO_COUPON
            && str_starts_with((string)$rule->getSimpleAction(), $this->actionPrefix);
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
            $ruleId = $this->soleRuleOfItemWithoutBreakdown($item);
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
     * @return int|null Null when the item has a breakdown, no discount or several rules
     */
    private function soleRuleOfItemWithoutBreakdown(AbstractItem $item): ?int
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

        return count($ruleIds) === 1 ? (int)reset($ruleIds) : null;
    }
}
