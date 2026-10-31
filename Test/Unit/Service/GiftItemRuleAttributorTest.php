<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLinesAmastyPromo\Test\Unit\Service;

use Magento\Quote\Model\Quote\Item;
use Magento\SalesRule\Model\Rule;
use PHPUnit\Framework\TestCase;
use PixelPerfect\DiscountLines\Api\Service\RuleLoaderInterface;
use PixelPerfect\DiscountLinesAmastyPromo\Service\GiftItemRuleAttributor;
use PixelPerfect\DiscountLinesAmastyPromo\Service\GiftRuleMatcher;

class GiftItemRuleAttributorTest extends TestCase
{
    private function rule(string $action, int $couponType): Rule
    {
        $rule = $this->getMockBuilder(Rule::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $rule->setData(['simple_action' => $action, 'coupon_type' => $couponType]);

        return $rule;
    }

    /**
     * @param array<int, Rule> $rules
     */
    private function attributor(array $rules): GiftItemRuleAttributor
    {
        $loader = $this->createMock(RuleLoaderInterface::class);
        $loader->method('load')->willReturnCallback(static fn(int $id): ?Rule => $rules[$id] ?? null);

        return new GiftItemRuleAttributor($loader, new GiftRuleMatcher());
    }

    public function testCreditsTheGiftCouponRule(): void
    {
        $attributor = $this->attributor([
            2 => $this->rule('by_percent', Rule::COUPON_TYPE_NO_COUPON),
            11 => $this->rule('ampromo_items', Rule::COUPON_TYPE_SPECIFIC),
        ]);

        $this->assertSame(11, $attributor->attribute($this->createMock(Item::class), [2, 11]));
    }

    public function testNoGiftRuleCannotBeTold(): void
    {
        $attributor = $this->attributor([
            1 => $this->rule('by_percent', Rule::COUPON_TYPE_SPECIFIC),
            2 => $this->rule('by_percent', Rule::COUPON_TYPE_NO_COUPON),
        ]);

        $this->assertNull($attributor->attribute($this->createMock(Item::class), [1, 2]));
    }

    public function testAutomaticGiftRuleIsNotCredited(): void
    {
        $attributor = $this->attributor([
            2 => $this->rule('by_percent', Rule::COUPON_TYPE_NO_COUPON),
            12 => $this->rule('ampromo_cart', Rule::COUPON_TYPE_NO_COUPON),
        ]);

        $this->assertNull($attributor->attribute($this->createMock(Item::class), [2, 12]));
    }

    public function testTwoGiftCouponRulesCannotBeTold(): void
    {
        $attributor = $this->attributor([
            11 => $this->rule('ampromo_items', Rule::COUPON_TYPE_SPECIFIC),
            13 => $this->rule('ampromo_cart', Rule::COUPON_TYPE_SPECIFIC),
        ]);

        $this->assertNull($attributor->attribute($this->createMock(Item::class), [11, 13]));
    }
}
