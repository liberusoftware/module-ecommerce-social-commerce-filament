# ecommerce-social-commerce-filament

The social shops desk. A Filament panel over
[`liberusoftware/ecommerce-social-commerce`](https://github.com/liberusoftware/module-ecommerce-social-commerce):
the shops a merchant has on social networks, the items each network is believed to hold, the status
it reports back, and the queue of items it must stop holding.

It is a one-to-one adapter. It contains no business rules: every decision is a published domain
action, query or policy, and every figure is counted by the domain on read.

## The one fact that shaped it

**The catalogue is a copy of the merchant's data held by somebody else, and the only record of what
that somebody holds is a row in our database.** The host hung that row off the product *and* the
variant with `cascadeOnDelete` on both, so deleting a variant deleted the note that the network still
had a live, buyable item — and disconnecting the credentials made unlisting unreachable, because both
jobs returned early with no connection.

So withdrawal is a state that has to be reached, not a row that can be dropped, and this panel has a
screen whose only job is counting the items that have not reached it. Nothing here deletes a listing.

## What it publishes

| | |
|---|---|
| `SocialCommercePlugin` | The entry point. The host attaches it to the panels it means to. |
| **Social shops** | One merchant's shops, their coverage per state, and whether anything can be sent at all. Connect, replace the secret, disconnect, re-read. |
| **Listings** | Every item a network is believed to hold, what the network last said about it, and its history. Withdraw one. |
| **Must be withdrawn** | Everything a network holds and must not, across every shop, with a badge and a sweep. |

## What it does not do

- **No secret is ever read back.** It is entered on a named action and stored write-only by the
  domain. No column, entry, export or model array form reaches it.
- **No publish and no republish.** The domain stores the price and the stock flag, not the title,
  description, image or link it sent — so re-sending from here would mean inventing content a network
  publishes to shoppers. Publication belongs to whatever owns the product.
- **No delete, anywhere.** A listing outlives its subject on purpose.
- **No adapter.** No Graph URL, no SDK, no API version, no list of networks. A network is a slug an
  adapter names, so admitting a new one is not a release of this package.
- **No erasure or export screen.** Those are person-wide host paths, not a merchant desk affordance.

## Installing

```bash
composer require liberusoftware/ecommerce-social-commerce-filament
```

Nothing boots on install: the module manager registers the provider when the module is named in
`MODULES_ENABLED`. Attaching the desk to a panel is one call — see [`docs/adoption.md`](docs/adoption.md).

Why every screen is shaped the way it is, including the shapes that were rejected, is in
[`docs/panel.md`](docs/panel.md). What breaks and what to do about it is in
[`docs/runbook.md`](docs/runbook.md).
