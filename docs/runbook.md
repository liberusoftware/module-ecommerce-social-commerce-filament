# Runbook

## Every publish says it was not sent

**Symptom.** Notifications reading *nothing is bound to talk to this network, so the request was never
sent*, and the shop page showing **Adapter: Nothing bound**. Listings pile up at *Recorded, not sent*.

**Cause.** Working as designed. This module and its domain package ship no adapter, and the domain
binds none: an unbound `ShopChannel` means the intent is recorded and the transmission refused, as two
outcomes a caller can tell apart.

**Fix.** Install an adapter package for the network and bind
`Liberu\Ecommerce\SocialCommerce\Contracts\ShopChannel`, or name it in
`config('social-commerce.seams.channel')`. The seam is resolved at the moment of use, so a rebinding
takes effect on the next call rather than the next deploy.

## The withdrawal queue is not emptying

**Symptom.** The **Must be withdrawn** badge stays at the same number, and the sweep reports *n still
up* with a reason.

**Read the reason.** It is one of four, and each is a different job:

| Reason | What to do |
|---|---|
| nothing is bound to talk to this network | bind an adapter, above |
| this shop has no credentials | somebody disconnected it. Enter a secret on the shop page; the listings are still there and withdrawal becomes reachable again |
| the network refused it | the network's own objection is on the listing, under *What the network objected to* |
| the network could not be reached | transient. Sweep again; if it persists, the adapter's timeouts are the place to look |

**Do not delete the rows.** They are the only record that a network holds a live, buyable item. A row
leaves the queue when a network confirms, and by no other means.

## A re-read says the network could not be asked

**Symptom.** A red notification after **Re-read what the network says**, and nothing changed.

**Cause.** Either nothing is bound, or the shop has no credentials, or the adapter threw or returned
`null`. `null` from `ShopChannel::readStatuses()` means *could not ask*, which is not an empty array —
an empty array means it was asked and holds none of these items.

**What it is not.** It is not a run that read zero listings and succeeded. Nothing was changed and no
status was touched; the figures on screen are as stale as they were.

**Fix.** The same as the queue table above. If an adapter is bound and reachable and this still
happens, the adapter is returning `null` when it should return `[]`, or the reverse — that distinction
is the whole of the seam contract.

## A listing is stuck at "Recorded, not sent"

**Symptom.** A listing that has never transmitted, and no button on this panel to retry it.

**Cause.** Known and deliberate. The domain stores the price and the stock flag, not the title,
description, image or link it was asked to send, so this panel has nothing to re-transmit and will not
invent it.

**Fix.** Republish from whatever owns the product, by calling `Actions\PublishListing` again with the
content. `PublishListing` is idempotent on `(tenant, shop, item_ref)` and will reuse the existing row.

**Watch for.** A listing at *Must be withdrawn* cannot be republished at all — the domain refuses the
transition, because a subject withdrawn for cause is republished only after the remote copy is
actually gone.

## A merchant's own reference opens nothing

**Symptom.** `ModelNotFoundException` on a shop or an item reference the operator says exists.

**Cause.** Either it does not exist, or it belongs to another merchant. These are one answer on
purpose: a 404 that differs from a 403 publishes the existence of the row.

**Fix.** Check which merchant the panel is showing. If a host resolves its tenant unusually, the
resolver is `SocialCommercePlugin::make()->tenantUsing(...)` in the panel provider.

## The panel raises "could not resolve a tenant"

**Symptom.** `RuntimeException` from `Support\PanelTenant` on any screen.

**Cause.** The panel has no Filament tenant and no `tenantUsing()` resolver, or the resolver returned
an empty string.

**Why it raises rather than showing everything.** A null tenant in a `where` compiles to `is null`,
which lists exactly the orphan rows the scope exists to hide. There is no such thing here as a panel
showing every merchant.

## Coverage figures look stale

**Symptom.** Counts on a shop page that disagree with its listings table.

**Cause.** The coverage is memoised per shop for the length of one request, so five entries do not run
five `group by` queries. It is dropped when the page mounts and after every write.

**Fix.** Reload the page. If it persists across a reload the figures are not stale — the listings are.
