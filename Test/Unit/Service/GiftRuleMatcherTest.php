<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLinesAmastyPromo\Test\Unit\Service;

use Magento\SalesRule\Model\Rule;
use PHPUnit\Framework\TestCase;
use PixelPerfect\DiscountLinesAmastyPromo\Service\GiftRuleMatcher;

class GiftRuleMatcherTest extends TestCase
{
    private function rule(string $action, int|string $couponType): Rule
    {
        $rule = $this->getMockBuilder(Rule::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $rule->setData(['simple_action' => $action, 'coupon_type' => $couponType]);

        return $rule;
    }

    public function testCouponTypeStoredAsStringIsCompared(): void
    {
        $matcher = new GiftRuleMatcher();

        $this->assertFalse($matcher->isGiftCouponRule($this->rule('ampromo_items', (string)Rule::COUPON_TYPE_NO_COUPON)));
        $this->assertTrue($matcher->isGiftCouponRule($this->rule('ampromo_items', (string)Rule::COUPON_TYPE_SPECIFIC)));
    }

    public function testCustomPrefixIsHonoured(): void
    {
        $matcher = new GiftRuleMatcher('custom_');

        $this->assertTrue($matcher->isGiftCouponRule($this->rule('custom_gift', Rule::COUPON_TYPE_SPECIFIC)));
        $this->assertFalse($matcher->isGiftCouponRule($this->rule('ampromo_items', Rule::COUPON_TYPE_SPECIFIC)));
    }
}
