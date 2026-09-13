CHANGELOG
=========

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
