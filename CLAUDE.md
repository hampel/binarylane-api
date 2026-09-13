# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this package is

`hampel/binarylane-api` — a PHP client for the BinaryLane API, written to be usable from any host
application rather than tied to one HTTP library or framework.

It covers the whole of the published v2 API. A Laravel integration will live in a separate package
that depends on this one, so **nothing in here may depend on `illuminate/*`** — that boundary is
the point of the split, and it is what keeps this package usable from an application with no
Laravel in it at all.

## Commands

```bash
composer install
composer check                                  # lint, analyse, test - what CI runs
composer test                                   # phpunit
composer analyse                                # phpstan, level 10 - see Version support
composer format                                 # pint, PSR-12
vendor/bin/phpunit --filter test_name           # one test
vendor/bin/phpunit tests/ConfigTest.php         # one file
vendor/bin/rig                                  # the live-API exercises, below
```

## The API this wraps, in four facts

Everything else in the design follows from these, and each is documented at the class that
implements it.

**1. Nearly every mutation answers with an action rather than a result.** Power, resize, rebuild,
backup, create — the work has not happened when the call returns. `Endpoint\Actions::await()` is
the polling loop, and it raises for the three ways an action stops without completing: it errored,
it is waiting on a question, or an unpaid invoice is blocking it. The middle two keep reporting
`in-progress` indefinitely, so a naive loop never escapes them.

**2. Several statuses carry no body, and two of them are successes.** A `202` on a server action
and a `204` on a delete are bodiless by design, so `Connection` must not treat them as malformed.
A `401` is bodiless too, and so is a `404` on an unrouted path — both measured, not inferred.

**3. Collections are `{<key>: [...], meta: {total}, links: {pages: {next}}}`**, with the key
differing per endpoint. `Page` takes the key as an argument for that reason. Walking follows the
`next` link rather than incrementing a page number.

**4. The token carries no scopes and does not expire.** There is one kind of credential and it can
cancel a server. Anything in this package that guards a destructive operation is doing work the
credential cannot.

## The PSR-18 seam is the whole design

The client is injected as `Psr\Http\Client\ClientInterface` with PSR-17 factories, never as a
concrete class, and `Connection` is the only file that touches HTTP.

This is not abstraction for its own sake. Some host applications cannot use an arbitrary HTTP
client at all: they require every outbound request to go through their own stack, for proxy support
and SSRF protection. A package that hardcodes Guzzle is unusable there, and what happens next is
that a second API client gets written rather than this one adopted. Under PSR-18 the host writes a
small adapter instead.

Three consequences to keep in mind when changing `Connection`:

- **A PSR-18 client does not throw on an HTTP status.** It throws `ClientExceptionInterface` only
  when the request never completed. That is what keeps `RequestException` (never reached BinaryLane,
  safe to retry) cleanly separate from `ApiException` (BinaryLane answered, and said no).
- **The catch is `ClientExceptionInterface`, not `\Throwable`.** Laravel's `StrayRequestException`
  is a plain `RuntimeException`, and it reaches a consumer's test naming the URL only because it
  passes through untouched. Widened, it would arrive as "could not reach the BinaryLane API",
  which is the wrong diagnosis in the one place a wrong diagnosis costs most.
- **There is no `'json' => $payload` convenience.** The body is encoded and wrapped in a stream
  through the PSR-17 factory by hand. Do not reach for a Guzzle option to avoid it.

**Do not add `php-http/discovery`** as a dependency. It is a Composer plugin, and a consumer may
refuse to run it — `"allow-plugins": {"php-http/discovery": false}` is a realistic line to find in
an application's `composer.json`. `Support\Psr17Discovery` looks for the common implementations by
class name instead, which is also what keeps the declared dependencies honest: no symbol is written,
so nothing undeclared can be found.

## Following a link out of a response body is an SSRF if you do not check

`Page` walking and any use of `Connection::follow()` request a URL that came out of a response, with
the account's bearer token attached. `Config::ownsUri()` is why that is safe, and
`Connection::follow()` refuses anything that does not point at the configured API.

Nothing in the specification suggests BinaryLane would ever emit such a link — which is exactly why
a client that followed anything would never find out. Do not relax this for convenience.

## Dependency constraints are load-bearing

`psr/log` is constrained to `^1.1|^2.0|^3.0` **on purpose**. A host application may bundle its own
`psr/log 1.x` and install a package's `vendor/` alongside it — requiring `^3.0` here would then put
a second, signature-incompatible `LoggerInterface` on the autoloader, and the failure arrives at
runtime rather than at install. The same reasoning applies to `psr/http-message` (`^1.1|^2.0`).

Neither range is there to be tidied up. Widening or narrowing either is a compatibility decision
about the applications that actually consume this package.

`ext-filter` is declared because `Entity\Network` uses `filter_var()`. It went undeclared until
`composer-require-checker` caught it — which is the check that catches a missing extension at all,
since a function from an absent extension looks exactly like one from core to static analysis.

## Structure

```
src/
  Client.php              entry point; named accessors over endpoint()
  Connection.php          the only file that touches HTTP
  Config.php              base URI, version segment, page size, ownsUri()
  ProblemDetail.php       the RFC 7807 body a failure carries
  Authentication/         how a request proves who it is
  Endpoint/               one class per area of the API; Endpoint is the extension point
  Entity/                 readonly value objects for response schemas
  Enum/                   the 26 enums the specification declares
  Exception/              the hierarchy, split by what a caller can do about it
  Request/                builders for the request schemas with conditional rules
  Result/                 ApiResponse, Page, PageLinks, ResponseMeta, CreatedServer
  Support/                Cast, Json, Psr17Discovery
```

**Entities are data and hold no connection.** Anything needing a request goes on an endpoint —
which is why `await()` lives on `Actions` and not on `Action`.

**Every entity keeps `raw`.** A field added to the API after a release stays readable, and
`jsonSerialize()` round-trips what the API sent rather than what this package understood.

**`Cast` is the only place that reads an untrusted value.** Everything returns null or an empty
collection rather than throwing: the specification has a great many legitimately-null fields, and a
client that treated any of them as an error would break on the ordinary case.

## Conventions that are decisions, not style

- **Endpoint helpers carry an `api` prefix** — `apiGet`, `apiPaginate`, `apiEach`. An endpoint
  group wants to call its own methods `get()`, `create()` and `delete()`, and PHP will not let a
  subclass redeclare an inherited method with a different signature. The prefix leaves the good
  names free.
- **A request builder sends only the fields it was given.** Several endpoints distinguish absent
  from null from empty — SSH keys, advanced features, firewall ports, disk descriptions, DNS
  updates — and a builder that filled in defaults would destroy that distinction.
- **Validation that costs a request is opt-in.** `validateAgainst()` on a request builder is
  called by the caller, never automatically, because the rules come from the specification's prose
  and a false rejection would be unappealable.
- **A named constructor per shape, where the specification has a conditional rule.**
  `TakeBackup::intoFreeSlot()` versus `::replacing()` exists because `backup_type` is required
  unless the strategy is `specified`. Written as constructors, the impossible combinations cannot
  be expressed.
- **Anything carrying a credential withholds it from `__debugInfo()`** — console URLs, user-data,
  image download links, invoice URLs, the token itself.
- **A failure is raised, never logged.** Requests are logged at `debug` and that is all.
  Whether an exception is a failure is decided by whoever catches it, and this package catches
  some of its own — `apiFind()` turns a 404 into null, `ServerActions::ask()` turns an errored
  action into an answer — so an `error` written before the throw reports ordinary answers as
  faults, and makes every failure a consumer logs arrive twice. Every exception carries what
  its log line did — the request and response, or the action. `LoggingTest` pins this.

## Testing

`tests/StubClient.php` is a PSR-18 client answering from a queue. The suite needs no network and no
Guzzle mock handler, because the seam the package exposes to consumers is the seam the tests drive
it through. Guzzle is present only for its PSR-7 objects.

Two tests exist to catch a class of rot rather than a bug:

- `ServerActionsTest::testEveryDocumentedServerActionHasAMethod()` calls all 42 actions and asserts
  each sends its own discriminator value. The API picks between them by a string, so an action that
  was never wrapped is invisible except by counting.
- `ReadmeTest` checks that everything the README claims exists. Renaming a method is exactly the
  change that passes every other test here — the suite calls the new name and only the README
  still calls the old one.

**A green suite cannot tell you the API still behaves this way.** Every mock encodes the same
assumption the code does, so when the remote moves they stay agreed with each other and disagreed
with reality. That is what the harness is for.

## The harness

`vendor/bin/rig` runs the exercises in `harness/`. They are not tests: they make real calls and
print what happened for a person to read.

Four read and change nothing. Two act, and are guarded in three layers:

| exercise | opt-in, lives in `.env` | agent override, never does |
|---|---|---|
| `dns-write` | `BINARYLANE_DNS_WRITE=1` | `BINARYLANE_AGENT_MAY_WRITE_DNS=1` |
| `server-action` | `BINARYLANE_RUN_ACTION=1` | `BINARYLANE_AGENT_MAY_RUN_ACTION=1` |

The layers are: the rig withholds `.env` entirely under an agent; each exercise defaults to the
harmless thing; and the opt-in is itself refused under an agent, because the `.env` belongs to
whoever owns the token and generally authorises the real thing.

**If an exercise fails for want of a credential, that is the guard working.** Do not go looking for
the token, do not edit `.env`, and do not pass an override to get past the error. Ask.

The read exercises carry probes rather than demonstrations — they ask the same question filtered
and unfiltered and compare the counts, because the failure worth catching is a filter the API has
quietly stopped honouring, which answers 200 and looks like success.

## Version support

PHP 8.3, 8.4 and 8.5 — every version with upstream security support, plus the current ceiling.
PHPStan runs at level 10 with `phpVersion` spanning the whole range, so the PHP axis of the support
matrix is covered without running it. Keep `phpstan.neon.dist` in step with the `php` constraint in
`composer.json`.

CI tests three corners: the floor with `--prefer-lowest`, the floor with current dependencies, and
the ceiling. It also runs `composer-require-checker` with dev dependencies present and PHPStan with
them removed — two different checks, and the first is the only one that sees a missing extension.

## Git

Commit freely; never push.
