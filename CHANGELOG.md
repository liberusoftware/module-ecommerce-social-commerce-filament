# Changelog

## 0.1.0

The social shops desk, extracted alongside `ecommerce-social-commerce` 0.1.0.

### Screens

- **Social shops.** One merchant's shops, with the listing count taken through the shop's own
  relation and the coverage per state counted on read by `Queries\ShopCoverage`. Connect, replace the
  secret and disconnect are `Actions\ConnectShop` and `Actions\DisconnectShop` behind named actions.
- **Listings.** Every item a network is believed to hold, keyed in the URL by `item_ref` — the
  reference a network shows an operator, which carries none of the host's own ids. Withdrawal is
  `Actions\WithdrawListing`. The history is the append-only event table.
- **Must be withdrawn.** Everything `Queries\ListListings::awaitingWithdrawal` returns, with a
  navigation badge and a sweep.

### Decisions

- **The sweep is shipped, and it is a loop.** The domain names the work and ships no batch
  withdrawal; iterating its query and calling its action once per row keeps every business decision
  in the domain. A sweep that transmitted nothing reports in red and names why, per reason; it never
  reports as a sweep that worked.
- **A re-read has four renderings, not two.** It accounted for all of them, for some of them, for
  none because there were none, or it could not be asked at all. The last is never green. The host's
  equivalent printed `Reconciled 0 listing(s).` and returned success, hourly, because a failed read
  and an empty one were both `[]`.
- **Connecting is a named action rather than a Filament create page.** There is no record to hydrate,
  so the credential field cannot round-trip, and `canCreate()` stays closed on every resource.
- **No publication and no republication.** The domain deliberately stores no copy of the content it
  sent, so this panel has nothing to re-send and will not invent any.
- **No delete on any resource or relation manager**, and `canAssociate`/`canDissociate` are closed by
  name alongside `canCreate`/`canEdit`.
- **The network is a validated slug**, because the domain ships no `Network` enum: admitting a new
  network is a new adapter, not a release here.
- **A price with no stored currency renders as nothing, and a stock flag never sent renders as
  nothing** rather than as out of stock. An unrecognised remote status renders as unrecognised rather
  than as still uploading.

### Deliberately not shipped

- A publish or republish action, for the reason above.
- An erasure or export screen. `Actions\RedactSubject` and `Queries\ExportSubjectRecord` are
  person-wide host paths; a merchant desk is the wrong place for them and narrowing them to one
  merchant would be a wrong answer rather than a partial one.
- A handoff-recording form. Attribution is recorded by whatever takes the order.
- Any widget. There is no figure here worth a dashboard tile that is not already a navigation badge.
