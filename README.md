# Discount Lines for Amasty Free Gift

Adapter for [`pixelperfectat/magento2-module-discount-lines`](https://github.com/pixelperfectat/magento2-module-discount-lines):
an Amasty Free Gift coupon appears as a **gift line** ("Gift" in place of an amount) on the cart, in the checkout,
on the order and in the sales emails and documents.

## Requirements

- `pixelperfectat/magento2-module-discount-lines`
- Amasty Free Gift (`amasty/promo`, commercial; not a hard dependency of this package, the module is sequenced after
  `Amasty_Promo`)

## Installation

```bash
composer require pixelperfectat/magento2-module-discount-lines-amasty-promo
bin/magento setup:upgrade
```

## How it works

The module adds one resolver (`amasty_promo`) to the base module's composite resolver. For a quote with a coupon it
looks at the applied sales rules and returns a `gift` line (amount 0, code and label = the coupon) for each rule that

- needs a coupon, and
- has a `simple_action` starting with `ampromo_` (`ampromo_items`, `ampromo_cart`, `ampromo_spent`,
  `ampromo_product`, `ampromo_eachn`).

Rules are only identified by this action string; no Amasty class is used.

- **Automatic gift rules** (no coupon) produce no line.
- **Non-gift coupons** are ignored.
- **No duplicates:** when the gift rule also discounts an item (a gift priced below 100 % is booked as a discount),
  the money line of the base module already represents the rule and no gift line is added.

The action prefix is a constructor argument of `AmastyGiftResolver` and can be changed in `di.xml`.

## Development

```bash
composer install
composer check
```

## License

MIT
