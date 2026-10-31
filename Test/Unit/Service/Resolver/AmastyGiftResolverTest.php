<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLinesAmastyPromo\Test\Unit\Service\Resolver;

use Magento\Framework\Api\ExtensionAttributesInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Item;
use Magento\SalesRule\Api\Data\DiscountDataInterface;
use Magento\SalesRule\Api\Data\RuleDiscountInterface;
use Magento\SalesRule\Model\Rule;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\DiscountLines\Api\Data\DiscountLineInterface;
use PixelPerfect\DiscountLines\Api\Service\LabelResolverInterface;
use PixelPerfect\DiscountLines\Api\Service\RuleLoaderInterface;
use PixelPerfect\DiscountLinesAmastyPromo\Service\Resolver\AmastyGiftResolver;

class AmastyGiftResolverTest extends TestCase
{
    private RuleLoaderInterface&MockObject $ruleLoader;
    private LabelResolverInterface&MockObject $labelResolver;
    private AmastyGiftResolver $resolver;

    protected function setUp(): void
    {
        $this->ruleLoader = $this->createMock(RuleLoaderInterface::class);
        $this->labelResolver = $this->createMock(LabelResolverInterface::class);
        $this->labelResolver->method('resolve')->willReturnCallback(
            static fn(Rule $rule, ?string $coupon): array => (int)$rule->getCouponType() === Rule::COUPON_TYPE_NO_COUPON
                ? ['kind' => 'automatic', 'code' => null, 'label' => 'Store label']
                : ['kind' => 'coupon', 'code' => $coupon, 'label' => (string)$coupon]
        );
        $this->resolver = new AmastyGiftResolver($this->ruleLoader, $this->labelResolver);
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
        $this->ruleLoader->method('load')->willReturnCallback(
            static fn(int $id): ?Rule => $rules[$id] ?? null
        );
    }

    private function quote(?string $coupon): Quote&MockObject
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getStoreId'])
            ->addMethods(['getCouponCode'])
            ->getMock();
        $quote->method('getCouponCode')->willReturn($coupon);
        $quote->method('getStoreId')->willReturn(1);

        return $quote;
    }

    /**
     * @param RuleDiscountInterface[] $discounts
     * @param Item[] $items
     */
    private function address(string $appliedRuleIds, array $discounts = [], array $items = []): Address&MockObject
    {
        $extension = $this->getMockBuilder(ExtensionAttributesInterface::class)
            ->addMethods(['getDiscounts'])
            ->getMockForAbstractClass();
        $extension->method('getDiscounts')->willReturn($discounts);
        $address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getExtensionAttributes', 'getAllItems'])
            ->addMethods(['getAppliedRuleIds'])
            ->getMock();
        $address->method('getAppliedRuleIds')->willReturn($appliedRuleIds);
        $address->method('getExtensionAttributes')->willReturn($extension);
        $address->method('getAllItems')->willReturn($items);

        return $address;
    }

    private function moneyDiscount(int $ruleId, float $amount): RuleDiscountInterface
    {
        $data = $this->createMock(DiscountDataInterface::class);
        $data->method('getAmount')->willReturn($amount);
        $discount = $this->createMock(RuleDiscountInterface::class);
        $discount->method('getRuleID')->willReturn($ruleId);
        $discount->method('getDiscountData')->willReturn($data);

        return $discount;
    }

    public function testGiftCouponProducesGiftLine(): void
    {
        $this->loadRules([11 => $this->rule('ampromo_items', Rule::COUPON_TYPE_SPECIFIC)]);

        $lines = $this->resolver->resolve($this->address('11'), $this->quote('FREEBIE'));

        $this->assertCount(1, $lines);
        $this->assertSame(DiscountLineInterface::KIND_GIFT, $lines[0]->getKind());
        $this->assertSame(11, $lines[0]->getRuleId());
        $this->assertSame('FREEBIE', $lines[0]->getCode());
        $this->assertSame('FREEBIE', $lines[0]->getLabel());
        $this->assertSame(0.0, $lines[0]->getAmount());
        $this->assertSame(0.0, $lines[0]->getBaseAmount());
    }

    public function testEveryGiftActionIsRecognised(): void
    {
        $actions = ['ampromo_items', 'ampromo_cart', 'ampromo_spent', 'ampromo_product', 'ampromo_eachn'];
        foreach ($actions as $action) {
            $resolver = new AmastyGiftResolver(
                $this->createConfiguredMock(
                    RuleLoaderInterface::class,
                    ['load' => $this->rule($action, Rule::COUPON_TYPE_SPECIFIC)]
                ),
                $this->labelResolver
            );
            $this->assertCount(1, $resolver->resolve($this->address('11'), $this->quote('FREEBIE')), $action);
        }
    }

    public function testAutomaticGiftProducesNoLine(): void
    {
        $this->loadRules([12 => $this->rule('ampromo_cart', Rule::COUPON_TYPE_NO_COUPON)]);

        $this->assertSame([], $this->resolver->resolve($this->address('12'), $this->quote('FREEBIE')));
    }

    public function testNoCouponOnQuoteProducesNoLine(): void
    {
        $this->loadRules([12 => $this->rule('ampromo_cart', Rule::COUPON_TYPE_NO_COUPON)]);

        $this->assertSame([], $this->resolver->resolve($this->address('12'), $this->quote(null)));
    }

    public function testNonPromoCouponIgnored(): void
    {
        $this->loadRules([1 => $this->rule('by_percent', Rule::COUPON_TYPE_SPECIFIC)]);

        $this->assertSame([], $this->resolver->resolve($this->address('1'), $this->quote('SAVE10')));
    }

    public function testAutomaticGiftNextToNonPromoCouponProducesNoLine(): void
    {
        $this->loadRules([
            1 => $this->rule('by_percent', Rule::COUPON_TYPE_SPECIFIC),
            12 => $this->rule('ampromo_items', Rule::COUPON_TYPE_NO_COUPON),
        ]);

        $this->assertSame([], $this->resolver->resolve($this->address('1,12'), $this->quote('SAVE10')));
    }

    public function testNoDuplicateWhenRuleAlsoHasMoneyLine(): void
    {
        $this->loadRules([11 => $this->rule('ampromo_items', Rule::COUPON_TYPE_SPECIFIC)]);
        $address = $this->address('11', [$this->moneyDiscount(11, 4.0)]);

        $this->assertSame([], $this->resolver->resolve($address, $this->quote('FREEBIE')));
    }

    public function testNoDuplicateWhenGiftItemHasDiscountWithoutBreakdown(): void
    {
        $this->loadRules([11 => $this->rule('ampromo_items', Rule::COUPON_TYPE_SPECIFIC)]);
        $item = $this->getMockBuilder(Item::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getExtensionAttributes'])
            ->addMethods(['getDiscountAmount', 'getAppliedRuleIds'])
            ->getMock();
        $item->method('getDiscountAmount')->willReturn(4.0);
        $item->method('getAppliedRuleIds')->willReturn('11');
        $item->method('getExtensionAttributes')->willReturn(null);

        $this->assertSame([], $this->resolver->resolve($this->address('11', [], [$item]), $this->quote('FREEBIE')));
    }

    public function testMoneyDiscountOfAnotherRuleDoesNotSuppressGiftLine(): void
    {
        $this->loadRules([
            2 => $this->rule('by_percent', Rule::COUPON_TYPE_NO_COUPON),
            11 => $this->rule('ampromo_items', Rule::COUPON_TYPE_SPECIFIC),
        ]);
        $address = $this->address('2,11', [$this->moneyDiscount(2, 0.65)]);

        $lines = $this->resolver->resolve($address, $this->quote('FREEBIE'));

        $this->assertCount(1, $lines);
        $this->assertSame(11, $lines[0]->getRuleId());
    }

    public function testUnknownRuleIsSkipped(): void
    {
        $this->loadRules([]);

        $this->assertSame([], $this->resolver->resolve($this->address('99'), $this->quote('FREEBIE')));
    }
}
