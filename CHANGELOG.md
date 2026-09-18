# Changelog

## Unreleased

**Breaking:** `DomainRecord::mx()` requires a priority, and `DomainRecord::srv()` a priority and a
weight.

- `DomainRecord::mx()` `$priority` has no default; it was `10`
- `DomainRecord::srv()` `$priority` and `$weight` have no default; they were `0`
- `RequestException`'s message says the outcome of the request is unknown
- an MX or SRV target is sent with a trailing dot; a single-label MX or SRV target raises
  `InvalidArgumentException`

## 0.5.0 (2026-09-14)

**Breaking:** `Server::$isUnderMaintenance` is `?bool`.

- `Server::$isUnderMaintenance` is `null` when BinaryLane did not check, rather than `false`
- `Server::isActionable()` and `permitsPowerOn()` treat only `true` as blocking

## 0.4.0 (2026-09-14)

**Breaking:** `DomainRecord::$type` is nullable.

- fixed `ReverseNames::all()` sending the API token to a host named in a pagination link
- `Connection::request()` refuses any URI that is not the configured API
- `DomainRecord::$type` is `null` for a record type this package does not recognise, rather than `A`
- added `DomainRecord::typeName()`
- `DomainRecords::create()`, `replace()` and `upsert()` refuse a record of unrecognised type
- an empty record name is sent as `@` from the constructor and `fromArray()`, as from the named
  constructors

## 0.3.0 (2026-09-14)

**Breaking:** failures are no longer logged. Nothing is logged above `debug`.

- rejected requests, transport failures, malformed responses, refused links, and failed, blocked
  or unclassifiable actions are raised without being logged
- `find()` returning `null`, and `ServerActions::checkRunning()` or `ask()` answering no, log
  nothing
- `LoadBalancers::each()` accepts a `name` filter
- `ActionTimedOutException`'s message says its seconds were spent between polls

## 0.2.0 (2026-09-13)

**Breaking:** a `2xx` response without the expected body raises `MalformedResponseException`.

- an empty-bodied `200` raises `MalformedResponseException`; only `202` and `204` may be empty
- a `2xx` without its envelope key raises `MalformedResponseException`, including for the
  unpaginated collections: firewall rules, threshold alerts, nameservers, load balancer
  availability and unpaid invoices
- `Actions::await()` raises `MalformedResponseException` for an action status it cannot classify
- added `ApiResponse::requireObject()`, `requireCollection()` and `requireArray()`
- a bodiless `202` still answers `null` from every `ServerActions` method

## 0.1.0 (2026-09-13)

First release. Covers BinaryLane's v2 API at specification version 0.40.0: 116 operations and
26 enums.

- `Client`, `Connection` and `Config` over any PSR-18 client, with PSR-17 factories discovered
  when none is passed, and an optional PSR-3 logger
- endpoints for every documented operation: `Account`, `Actions`, `Billing`, `DataUsages`,
  `Domains`, `DomainRecords`, `Images`, `LoadBalancers`, `Regions`, `ReverseNames`,
  `SampleSets`, `ServerActions`, `Servers`, `Sizes`, `SoftwareCatalogue`, `SshKeys`, `Vpcs`
- `ServerActions` wraps all 42 server action types, each returning `?Action`
- readonly entities for every response schema, each keeping the raw response on `raw`
- request builders for `TakeBackup`, `UploadImage`, `CreateServer`, `Resize`, `SizeOptions`,
  `AdvancedFeatures`, `ImageOptions`, `ChangeImage`, `License` and `ThresholdAlert`
- `Actions::await()` raises separately for an errored action, a blocked one, and a timeout
- `ServerActions::ask()`, `checkRunning()` and `checkUptime()` for question-shaped actions
- `Server::$status` is a lifecycle state, not a power state; there is no `Server::isRunning()`
- `Page` walking follows the API's `next` link; `Connection::follow()` refuses a link to any
  other host
