CHANGELOG
=========

0.4.0 (2026-09-14)
------------------

**Breaking, hence 0.4.0:** `DomainRecord::$type` is nullable. Code reading `$record->type->value`
needs `$record->typeName()`, or a null check.

* **`ReverseNames::all()` could send the API token to another host.** It walked
  `links.pages.next` through `get()` rather than `follow()`, and an absolute URI passes through
  `Config::resolve()` unchanged, so a link naming another host was requested with the token on it.
  It walks through `follow()` now, and `Connection::request()` — which builds every request —
  refuses any URI that is not the configured API, whichever method was called
* `DomainRecord::fromArray()` reads a record type this package does not know as `null`, not as `A`.
  Such a record could previously be sent back by `replace()` as an A record. `typeName()` answers
  with the type's name either way; `create()`, `replace()` and `upsert()` refuse an unknown type
* an empty record name is sent as `@` however the record was built. Only the named constructors and
  `withName()` converted it, so a record from the constructor or from another provider's export
  via `fromArray()` sent the empty string

0.3.0 (2026-09-14)
------------------

**Breaking in behaviour, though no signature changes:** the client no longer logs a failure it
raises. An application that relied on it for its only record of API failures now needs to log
what it catches.

* nothing is logged above `debug`. 0.2.0 logged rejected requests, transport failures,
  non-JSON bodies, refused links and failed, blocked or unclassifiable actions at `error` or
  `warning` before raising them — and did not log a missing envelope key or a timed-out action.
  An application that logged what it caught therefore recorded most failures twice, and could
  not skip the logged types without losing the rest
* `servers()->find()` and the other `find()` methods no longer log `error` when there is
  nothing to find, and `serverActions()->checkRunning()` and `ask()` no longer log a failed
  action when the answer is no. Both were ordinary answers reported as faults
* `loadBalancers()->each()` takes the `name` filter `list()` already had. The specification
  does not say a name matches only one load balancer, unlike a server hostname
* `ActionTimedOutException` says its seconds were spent between polls, which is what
  `Actions::await()`'s `$timeout` has always counted — not elapsed time
* `ImageDiskDownload::url()` documents that its fallback to the raw URL is a different format

0.2.0 (2026-09-13)
------------------

**Breaking, hence 0.2.0 rather than 0.1.1:** code that relied on an unreadable `200` resolving
to an empty result now gets an exception.

* an empty-bodied `200` raises `MalformedResponseException`. Only `202` and `204` are successes
  with no body, which is what the specification declares — all 104 of its `200`s carry a
  content schema
* a `2xx` that parsed and does not carry its envelope key raises `MalformedResponseException`.
  Previously a `200` of `{"unexpected":true}` read as an empty page from `servers()->list()`
  and as a `Server` with id 0 from `servers()->get()`
* the collections the API does not paginate — firewall rules, threshold alerts, nameservers,
  load balancer availability, unpaid invoices — are checked the same way
* `Actions::await()` raises `MalformedResponseException` for an action whose status it cannot
  classify, rather than treating it as still running and polling until the timeout
* added `ApiResponse::requireObject()`, `requireCollection()` and `requireArray()`. `object()`,
  `collection()` and `array()` keep their lenient behaviour for callers working through
  `connection()`
* a bodiless `202` still answers `null` from every `ServerActions` method

0.1.0 (2026-09-13)
------------------

First working version. Complete coverage of BinaryLane's v2 API at specification version
0.40.0: 116 operations, 26 enums, and the entity graph behind them.

* `Client`, `Connection` and `Config` over any PSR-18 client, with PSR-17 factories discovered
  when none is passed and an optional PSR-3 logger
* endpoints for every documented operation: `Account`, `Actions`, `Billing`, `DataUsages`,
  `Domains`, `DomainRecords`, `Images`, `LoadBalancers`, `Regions`, `ReverseNames`,
  `SampleSets`, `ServerActions`, `Servers`, `Sizes`, `SoftwareCatalogue`, `SshKeys`, `Vpcs`
* `ServerActions` wraps all 42 server action types
* readonly entities for every response schema, each keeping the raw response on `raw`
* request builders for the schemas with conditional rules: `TakeBackup`, `UploadImage`,
  `CreateServer`, `Resize`, `SizeOptions`, `AdvancedFeatures`, `ImageOptions`, `ChangeImage`,
  `License`, `ThresholdAlert`
* `Actions::await()`, which raises separately for an errored action, one waiting on a question
  or on an invoice, and a timeout
* `ServerActions::ask()`, `checkRunning()` and `checkUptime()` for the actions that answer by
  completing rather than in their payload
* `Server` has no `isRunning()`: `status` is not a power state
* every `ServerActions` method returns `?Action`; the specification declares a bodiless `202`
  alongside the `200` on each of them
* `NotAuthenticatedException` carries no `ProblemDetail`: a 401 response has no body
* `Page` walking follows the API's `next` link, and `Connection::follow()` refuses a link that
  does not point at the configured API
