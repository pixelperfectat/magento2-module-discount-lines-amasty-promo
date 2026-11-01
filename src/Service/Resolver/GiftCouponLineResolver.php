<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLinesAmastyPromo\Service\Resolver;

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use PixelPerfect\DiscountLines\Api\Data\DiscountLineInterface;
use PixelPerfect\DiscountLines\Api\Service\DiscountLineResolverInterface;
use PixelPerfect\DiscountLines\Api\Service\RuleLoaderInterface;
use PixelPerfect\DiscountLines\Model\Data\DiscountLine;
use PixelPerfect\DiscountLinesAmastyPromo\Api\Service\GiftRuleMatcherInterface;

/**
 * Wraps the rule resolver: the money line of an Amasty gift coupon rule becomes a `gift` line.
 *
 * Amasty books a gift as a discount of the gift item (100 %), so core reports a money amount for the coupon rule.
 * The line keeps its amount (lines must add up to the discount) but is typed `gift`, code and label = coupon.
 * Automatic gift rules keep their `automatic` line and store label.
 */
class GiftCouponLineResolver implements DiscountLineResolverInterface
{
    /**
     * @param DiscountLineResolverInterface $inner Resolver of the per-rule money lines
     * @param RuleLoaderInterface $ruleLoader
     * @param GiftRuleMatcherInterface $matcher
     */
    public function __construct(
        private readonly DiscountLineResolverInterface $inner,
        private readonly RuleLoaderInterface $ruleLoader,
        private readonly GiftRuleMatcherInterface $matcher
    ) {
    }

    /**
     * @inheritDoc
     */
    public function resolve(Address $address, Quote $quote): array
    {
        $lines = $this->inner->resolve($address, $quote);
        $couponCode = trim((string)$quote->getCouponCode());
        if ($couponCode === '') {
            return $lines;
        }

        foreach ($lines as $position => $line) {
            $ruleId = $line->getRuleId();
            if ($ruleId === null || $line->getKind() !== DiscountLineInterface::KIND_COUPON) {
                continue;
            }
            $rule = $this->ruleLoader->load($ruleId);
            if ($rule === null || !$this->matcher->isGiftCouponRule($rule)) {
                continue;
            }
            $lines[$position] = new DiscountLine(
                DiscountLineInterface::KIND_GIFT,
                $ruleId,
                $line->getCode() ?? $couponCode,
                $line->getLabel(),
                $line->getAmount(),
                $line->getBaseAmount(),
                $line->getSortOrder()
            );
        }

        return $lines;
    }
}
