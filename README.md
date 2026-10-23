# Monokulo for WooCommerce

Accept Monero in a WooCommerce shop through your own
[Monokulo](https://github.com/OceanEilonwy/monokulo), the self-hosted,
non-custodial Monero payment processor. The plugin connects the shop to a
store in your Monokulo, creates an order there when a customer checks out,
and marks the WooCommerce order paid when Monokulo says it is. Monokulo only
ever holds a view key; no spend key touches the shop or Monokulo.

- WordPress **6.5** or newer, WooCommerce **8.2** or newer, PHP **8.1** or
  newer (tested on WordPress 7.1.3 and WooCommerce 11.2.1, PHP 8.1, 8.4 and
  8.5)
- Works with the Cart and Checkout blocks and the classic checkout, and
  with WooCommerce's order tables (HPOS)
- A Monokulo whose operator has set its public address
- GPL-2.0-or-later

## Install

1. Download `monokulo.zip` from the
   [latest release](https://github.com/OceanEilonwy/monokulo-woocommerce/releases/latest).
2. In WordPress: **Plugins > Add New > Upload Plugin**, upload it, then
   **Activate**.

## Connect

1. **WooCommerce > Settings > Payments > Monokulo**. **Monokulo address**
   starts at the public Monokulo, `https://monokulo.com`. If your store is
   on another Monokulo (your own, say), type its address and **Save
   changes**.
2. **Connect your Monero wallet**.
3. Sign in to Monokulo (or sign up) in the page that opens. It finds your
   store by the shop's address:
   - one of your stores has the shop's site: confirm, and you're done;
   - none has it: set up a store (its name, and the shop's site, filled in
     for you), choose where its money goes, then **Back to WooCommerce**.

   A store you made without a site can take the shop's: add the site in
   the store's settings (the *Store* card), then connect.
4. You're back on the plugin's settings, connected. Connecting fills in the
   checkout address and the store's keys and turns Monero on at checkout.

Monokulo only sends the connect flow back to the shop over **https** (plain
http only for `localhost` or a `.onion` address).

Any Monokulo store can do everything at once: take payments at the till,
show the checkout on your own pages, and take WooCommerce orders.

### While it's connected

The store's settings in Monokulo show the plugin in the *Connections* card:
the shop, the plugin's version, when it connected and its last order. The
store's website is the shop's and is locked while the plugin is connected
("Set by WooCommerce · see Connections").

### Disconnecting and reconnecting

*Disconnect…* in that Connections card cuts the plugin off:

- it waits while an order the plugin made can still be paid, and says until
  about when;
- then, with the store's name typed to confirm, Monokulo removes the
  plugin's webhook and gives the store a new secret key, so the plugin's
  key stops working: the shop stops taking Monero;
- the store's website unlocks, to change or remove.

To connect again, use **Connect** in WooCommerce as the first time: it finds
the store by its website and gets the new key and a new webhook.

## Orders

The plugin creates each order from the shop's server with the store's
secret key, and names itself (`Monokulo-Client: woocommerce/<version>`), so
the store's Orders page in Monokulo shows those orders as *WooCommerce*.

At the top of Monokulo's checkout the customer sees the store's name and
what they're paying for: "Order #1042: Blue mug + 2 more", the order number
they know from WooCommerce's emails, then the first item and how many
others. The plugin also sends the order's WordPress ID as its reference, so
you can find the order from Monokulo's dashboard and webhooks; the checkout
doesn't show it.

## Webhooks and order statuses

Connecting registers the shop's webhook address with Monokulo
(`https://<your shop>/wp-json/monokulo/v1/webhook`, or
`/?rest_route=/monokulo/v1/webhook` without pretty permalinks). Each delivery is signed (`X-Monokulo-Signature`); the
plugin refuses a missing or wrong signature and one more than five minutes
old, and a delivery seen before changes nothing.

| Monokulo event | WooCommerce order |
| --- | --- |
| `order.unconfirmed`, `order.confirming`, `order.partial` | On hold |
| `order.paid` | Paid (`payment_complete()`: Processing, or Completed for virtual and downloadable orders) |
| `order.overpaid` | Paid, and a note that the excess isn't refunded automatically |
| `order.expired` | Cancelled, and a note: any funds received stay at the address |
| `order.double_spend_detected` | A fraud alert note; any status change comes as its own event |
| `order.double_spend_reversed` | A note |

Monokulo is non-custodial: refund an overpayment or an expired order's
funds from your wallet yourself.

## Logs

The plugin logs through WooCommerce (**WooCommerce > Status > Logs**,
source `monokulo`), each line with the request's trace id, which matches
Monokulo's own logs. **Send errors to Monokulo** also sends its warnings and
errors to your Monokulo, when the store there accepts client logs.

## Removing the plugin

Deleting the plugin in WordPress removes its settings. Orders keep their
Monokulo order ids and notes. Disconnect the shop in Monokulo too (see
above), so it stops sending webhooks.

## Test first

Take payments into a **stagenet** wallet while you try it, with free test
coins, then change the store to your mainnet wallet in its Monokulo
settings and make one small real payment.

## Development

Everything runs in Docker on the host's network (Linux): WordPress 7.1.3
with WooCommerce 11.2.1 at `http://localhost:8092/`, with MariaDB.

```sh
scripts/test up          # build and start WordPress (PHP_VERSION=8.1|8.4|8.5, default 8.4)
scripts/test all         # build the zip, install it in the shop, run the wordpress suite
scripts/test wordpress   # just the suite (extra args go to PHPUnit)
COVERAGE=1 scripts/test all   # and coverage/summary.txt, coverage/html/
scripts/smoke            # against a real Monokulo (MONOKULO_BIN=path or MONOKULO_IMAGE=image)
scripts/test down
```

Admin: `http://localhost:8092/wp-admin/`, user `admin`, password
`admin-password-1`. The smoke test sets the shop's Monokulo address to
`http://localhost:8081`, where `scripts/smoke` starts one.

- **wordpress**: the plugin booted inside WordPress with WooCommerce by
  WordPress's PHPUnit test library, each test in a rolled-back
  transaction. Calls to Monokulo are answered in the test
  (`pre_http_request`).
- **smoke**: a fresh Monokulo (`docker/monokulo-smoke.toml`): sign up, add
  a watch-only wallet, connect from the plugin's settings, then a guest
  checkout through WooCommerce's Store API lands on the Monokulo checkout,
  whose status is pending. Nothing is paid.

Webhook tests use Monokulo's published test vectors, vendored in
`tests/vectors` and pinned to a monokulo commit (`tests/vectors/PIN`).
`scripts/vectors check` compares them with the pinned commit;
`scripts/vectors pin <commit>` moves the pin.

This plugin's history up to its move lives on from `plugins/woocommerce`
in the monokulo repository.

## Release

Bump `VERSION`, the `Version` in `monokulo.php`'s header and
`WC_Gateway_Monokulo::VERSION` together (a test checks), then push a tag
`v<version>`. The release workflow installs the zip in the test shop, runs
the tests and publishes `monokulo.zip` and its `.sha256`.
`scripts/build-zip` builds the same zip locally (byte for byte).
