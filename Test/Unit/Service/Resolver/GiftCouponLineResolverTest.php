<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLinesAmastyPromo\Test\Unit\Service\Resolver;

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\SalesRule\Model\Rule;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\DiscountLines\Api\Data\DiscountLineInterface;
use PixelPerfect\DiscountLines\Api\Service\DiscountLineResolverInterface;
use PixelPerfect\DiscountLines\Api\Service\RuleLoaderInterface;
use PixelPerfect\DiscountLines\Model\Data\DiscountLine;
use PixelPerfect\DiscountLinesAmastyPromo\Service\GiftRuleMatcher;
use PixelPerfect\DiscountLinesAmastyPromo\Service\Resolver\GiftCouponLineResolver;

class GiftCouponLineResolverTest extends TestCase
{
    private DiscountLineResolverInterface&MockObject $inner;
    private RuleLoaderInterface&MockObject $ruleLoader;
    private GiftCouponLineResolver $resolver;

    protected function setUp(): void
    {
        $this->inner = $this->createMock(DiscountLineResolverInterface::class);
        $this->ruleLoader = $this->createMock(RuleLoaderInterface::class);
        $this->resolver = new GiftCouponLineResolver($this->inner, $this->ruleLoader, new GiftRuleMatcher());
    }

    private function rule(string $action, int $couponType): Rule
    {
        $rule = $this->getMockBuilder(Rule::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $rule->setData(['simple_action' => $action, 'coupon_type' => $couponType]);

        return $rule;
    }

    /**
     * @param array<int, Rule> $rules
     */
    private function loadRules(array $rules): void
    {
        $this->ruleLoader->method('load')->willReturnCallback(static fn(int $id): ?Rule => $rules[$id] ?? null);
    }

    private function quote(?string $coupon): Quote&MockObject
    {
        $quote = $this->getMockBuilder(Quote::class)->disableOriginalConstructor()->addMethods(['getCouponCode'])
            ->getMock();
        $quote->method('getCouponCode')->willReturn($coupon);

        return $quote;
    }

    /**
     * @param DiscountLineInterface[] $lines
     */
    private function resolve(array $lines, ?string $coupon = 'FREEBIE'): array
    {
        $this->inner->method('resolve')->willReturn($lines);

        return $this->resolver->resolve($this->createMock(Address::class), $this->quote($coupon));
    }

    public function testGiftCouponMoneyLineBecomesGiftLineKeepingAmounts(): void
    {
        $this->loadRules([11 => $this->rule('ampromo_items', Rule::COUPON_TYPE_SPECIFIC)]);

        $lines = $this->resolve([new DiscountLine('coupon', 11, 'FREEBIE', 'FREEBIE', 8.0, 7.5, 2)]);

        $this->assertSame('gift', $lines[0]->getKind());
        $this->assertSame(11, $lines[0]->getRuleId());
        $this->assertSame('FREEBIE', $lines[0]->getCode());
        $this->assertSame('FREEBIE', $lines[0]->getLabel());
        $this->assertSame(8.0, $lines[0]->getAmount());
        $this->assertSame(7.5, $lines[0]->getBaseAmount());
        $this->assertSame(2, $lines[0]->getSortOrder());
    }

    public function testAutomaticGiftRuleKeepsAutomaticLineAndStoreLabel(): void
    {
        $this->loadRules([12 => $this->rule('ampromo_cart', Rule::COUPON_TYPE_NO_COUPON)]);
        $line = new DiscountLine('automatic', 12, null, 'Free sachet', 4.0, 4.0);

        $this->assertEquals([$line], $this->resolve([$line]));
    }

    public function testNonGiftCouponLineIsUntouched(): void
    {
        $this->loadRules([1 => $this->rule('by_percent', Rule::COUPON_TYPE_SPECIFIC)]);
        $line = new DiscountLine('coupon', 1, 'SAVE10', 'SAVE10', 3.0, 3.0);

        $this->assertEquals([$line], $this->resolve([$line], 'SAVE10'));
    }

    public function testOtherLinesAreKept(): void
    {
        $this->loadRules([
            2 => $this->rule('by_percent', Rule::COUPON_TYPE_NO_COUPON),
            11 => $this->rule('ampromo_items', Rule::COUPON_TYPE_SPECIFIC),
        ]);
        $auto = new DiscountLine('automatic', 2, null, 'Sale', 0.65, 0.65, 0);

        $lines = $this->resolve([$auto, new DiscountLine('coupon', 11, 'FREEBIE', 'FREEBIE', 8.0, 8.0, 1)]);

        $this->assertEquals($auto, $lines[0]);
        $this->assertSame('gift', $lines[1]->getKind());
    }

    public function testNoCouponLeavesLinesUntouched(): void
    {
        $line = new DiscountLine('automatic', 12, null, 'Free sachet', 4.0, 4.0);

        $this->assertEquals([$line], $this->resolve([$line], null));
    }

    public function testBlankCouponLeavesLinesUntouched(): void
    {
        $this->loadRules([11 => $this->rule('ampromo_items', Rule::COUPON_TYPE_SPECIFIC)]);
        $lines = [
            new DiscountLine('coupon', 11, 'FREEBIE', 'FREEBIE', 8.0, 8.0, 0),
            new DiscountLine('automatic', 2, null, 'Sale', 1.0, 1.0, 1),
        ];

        $this->assertEquals($lines, $this->resolve($lines, '   '));
    }

    public function testLineWithoutRuleIdIsKept(): void
    {
        $line = new DiscountLine('coupon', null, 'FREEBIE', 'FREEBIE', 8.0, 8.0);

        $this->assertEquals([$line], $this->resolve([$line]));
    }

    public function testNonCouponLineOfGiftRuleIsKept(): void
    {
        $this->loadRules([11 => $this->rule('ampromo_items', Rule::COUPON_TYPE_SPECIFIC)]);
        $line = new DiscountLine('automatic', 11, null, 'Sale', 8.0, 8.0);

        $this->assertEquals([$line], $this->resolve([$line]));
    }

    public function testUnknownRuleDoesNotStopLaterLines(): void
    {
        $this->loadRules([11 => $this->rule('ampromo_items', Rule::COUPON_TYPE_SPECIFIC)]);

        $lines = $this->resolve([
            new DiscountLine('coupon', 99, 'OTHER', 'OTHER', 1.0, 1.0, 0),
            new DiscountLine('coupon', 11, 'FREEBIE', 'FREEBIE', 8.0, 8.0, 1),
        ]);

        $this->assertSame('coupon', $lines[0]->getKind());
        $this->assertSame('gift', $lines[1]->getKind());
    }

    public function testLineCodeWinsOverQuoteCoupon(): void
    {
        $this->loadRules([11 => $this->rule('ampromo_items', Rule::COUPON_TYPE_SPECIFIC)]);

        $lines = $this->resolve([new DiscountLine('coupon', 11, 'LINECODE', 'Label', 8.0, 8.0)]);

        $this->assertSame('LINECODE', $lines[0]->getCode());
    }

    public function testQuoteCouponIsUsedWhenLineHasNoCode(): void
    {
        $this->loadRules([11 => $this->rule('ampromo_items', Rule::COUPON_TYPE_SPECIFIC)]);

        $lines = $this->resolve([new DiscountLine('coupon', 11, null, 'Label', 8.0, 8.0)], ' FREEBIE ');

        $this->assertSame('FREEBIE', $lines[0]->getCode());
    }
}
