CHANGELOG
=========

Unreleased
----------

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
