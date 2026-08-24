# The panel

What each screen is, and every decision behind it — including the ones that were rejected.

## Where the tenant comes from

`Support\PanelTenant`, once. Every domain query and action takes the tenant as an argument and never
reads one from ambient state, so the argument has to come from somewhere at the edge. One place,
stated out loud, beats `Filament::getTenant()` repeated across two resources and a page where one of
them can quietly forget.

It raises rather than falling back. There is no nullable `tenant_id` in the schema to fall back to,
and a null in a `where` lists the orphan rows a scope was meant to hide.

## Where the sentences come from

`Support\Render`, once. Every rule in it is a sentence the host got wrong:

| Rule | The host |
|---|---|
| A read that could not happen is never a healthy empty run | `readItemStatuses` returned `[]` on transport failure, on a non-2xx, and on an empty catalogue alike; the command printed `Reconciled 0 listing(s).` and returned `SUCCESS`, hourly |
| An unrecognised remote status is unrecognised | four known values, falling through to `'pending'` — so a rejection under a new label read as *still uploading* |
| A stock flag never sent is not "out of stock" | — |
| A price with no stored currency is nothing, not `0.00` | `number_format((float) $price, 2)` and a platform-wide `DEFAULT_CURRENCY`, so a merchant pricing in EUR published a public, buyable `12.99 USD` |
| Money is the domain's decimal string and its code | the same float |

## Social shops

The list counts listings through `withCount('listings')` — the shop's own relation, which restates the
tenant on top of the foreign key. The restatement is guarded, because `withCount()` builds the relation
from a fresh instance whose `tenant_id` is null: unguarded it becomes `where('tenant_id', '')` and
reports zero for everything, which looks exactly like isolation working. `CustodyTest` asserts the
right non-zero number, not merely a scoped one.

The record page carries three things a merchant asks for and three things they may do.

**Coverage per state**, from `Queries\ShopCoverage`. Counted on read; no state count is a column, so
there is no counter to drift from the rows.

**Whether anything can be sent**, before the button is pressed: credentials held or not, adapter bound
or not. The domain's `ShopChannel` is unbound by default and an unbound seam removes exactly the claim
it controls — so saying which half is missing is worth a section rather than a surprise in a
notification.

**Connect** is `Actions\ConnectShop` behind a named action, not a Filament create page.

> **Rejected: a `CreateShop` page.** A create page hydrates a form from a record, and an edit page
> hydrates it from an existing one. The domain stores the secret write-only precisely so nothing reads
> it back; a form bound to the record is the shape that would. A named action has no record to hydrate,
> so the field writes and never round-trips — and `canCreate()` and `canEdit()` stay closed on every
> resource, which means Filament's own machinery is not a second way to write these rows.

**Replace the secret** is `ConnectShop` again, on the shop's existing network and reference. The action
is idempotent on `(tenant, network, external_ref)`, arbitrated by the unique index, so this replaces
the credential rather than making a second shop.

**Disconnect** is `Actions\DisconnectShop`, which removes the secret and nothing else. The notification
says how many items the network is left holding, because it is left holding them: the host's version
deleted the credentials under a note saying products stay listed in Meta, without saying that unlisting
them afterwards had become unreachable.

**Re-read** is `Actions\ReconcileShopListings`, and it is the reason this page exists. Four renderings,
four colours, never conflated:

| Outcome | Rendering | Colour |
|---|---|---|
| `asked: false` | the network could not be asked; nothing read, nothing changed, *n* listings as they were | danger |
| `asked, considered: 0` | nothing to re-read | success |
| `asked`, complete | accounted for all *n* | success |
| `asked`, partial | accounted for *a* of *n*, did not mention *s*, whose status is as stale as it was | warning |

## Listings

The URL key is `item_ref`, not the row id. An item reference is what a network shows an operator and it
carries none of this application's ids, so it is the handle somebody arriving from a network console
actually has. Resolution runs over the tenant-scoped query, which is what makes another merchant's
reference and one that does not exist the same `ModelNotFoundException` — one answer, no directory of
the deployment.

There is no title, description, image or link column, and there cannot be. The domain stores the price,
the stock flag, the network's identifier and what the network reported — not a copy of the content it
sent. A column captioned "what we last published" would be this panel inventing a fact nobody wrote
down.

**Withdraw** is `Actions\WithdrawListing`. A refusal leaves the listing at `withdrawal_required` and
says why; the item is still up, and the state is what a merchant and an operator both have to be able
to count.

> **Rejected: a publish or republish action.** For the same reason there is no content column. A
> re-send needs a `ListingContent`, which needs a title, a description, a brand and an absolute link —
> all of which the domain deliberately does not keep. A panel that asked an operator to type them would
> be publishing hand-typed content to shoppers under a merchant's name.
>
> This leaves a real gap and it is reported rather than papered over: a listing whose first transmission
> was refused sits at `pending` with no way back from this panel. Republishing is whatever owns the
> product calling `PublishListing` again.

The history is the append-only event table, through the listing's own guarded relation. It is the only
place a refused transmission survives after the notification has gone.

## Must be withdrawn

`Queries\ListListings::awaitingWithdrawal`, unpaginated, with a navigation badge. A row leaves this
queue when a network confirms it has stopped holding the item, and by no other means.

**The sweep is shipped.** The domain names the work and ships no batch withdrawal, so the loop is here:
iterate the domain query, call the domain action once per row, count. Nothing in it decides anything.

> **Rejected: leaving the sweep out.** The obvious argument is that a loop in a panel is business logic
> creeping out of the domain. It is not — every judgement stays in `WithdrawListing`, and the alternative
> is an operator with two hundred stranded listings clicking two hundred times, which is how a queue
> stops being read.
>
> **Rejected: a bulk action over selected rows.** The table's records are arrays rather than models,
> because the row is a shop name joined to a listing, so Filament's selection machinery has no key to
> work with. One header action over the whole queue is what the queue is: everything on it must come
> down.

The report is the point. `n of m came down`, then one sentence per refusal reason with its count, and
the colour is green only when nothing was refused — red when nothing came down at all. A sweep that
transmitted nothing can never read as a sweep that worked.

## Filament abilities

Two concerns close every ability the domain does not publish, by name, and `AuthorizationTest` asks each
one and is told no. A missing policy is permissive; a policy that exists but lacks the method asked
about is *also* permissive, because Filament falls through to allow.

`canAssociate()` and `canDissociate()` are live on a `hasMany` and default open, and they are closed
here alongside `canCreate`/`canEdit`: dissociating a listing from its shop leaves the record of what a
network holds pointing at nothing, and the history is append-only in the domain so it cannot be edited
into agreement afterwards.

The suite also asserts that no resource or relation manager declares a method named after an ability in
its own file — a subclass method wins over a trait's, so one would silently reopen it.

## Not shipped

- **An erasure or export screen.** `Actions\RedactSubject` and `Queries\ExportSubjectRecord` are
  person-wide, cross-merchant paths. Narrowing either to one merchant's desk would be a wrong answer
  rather than a partial one.
- **A handoff-recording form.** Attribution is recorded by whatever takes the order; inventing one from
  a desk is not a decision an operator should be offered.
- **Any widget.** Every figure worth a tile is already a navigation badge or a section on the record it
  belongs to, and a dashboard tile is the classic place for a stale count to live.
