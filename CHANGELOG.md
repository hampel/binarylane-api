CHANGELOG
=========

Unreleased
----------

First working version. Complete coverage of BinaryLane's v2 API as published at specification
version 0.40.0: all 116 documented operations, 26 enums and the entity graph behind them.

**Not yet released, and the version number matters.** BinaryLane's own introduction says the
specification is "currently in active development" and that "breaking changes are possible
without the version changing" — by which it means the API version, `v2`. A client cannot be
more stable than the API it wraps, so this starts at 0.x and the first release will say what
it covers.

### What is here

* `Client`, `Connection` and `Config` over any PSR-18 client, with PSR-17 factories discovered
  when none is passed and an optional PSR-3 logger. No HTTP library is required.
* Endpoints for every documented operation: `Account`, `Actions`, `Billing`, `DataUsages`,
  `Domains`, `DomainRecords`, `Images`, `LoadBalancers`, `Regions`, `ReverseNames`,
  `SampleSets`, `ServerActions`, `Servers`, `Sizes`, `SoftwareCatalogue`, `SshKeys`, `Vpcs`.
* `ServerActions` wraps all 42 discriminator values the specification declares, each with the
  fields that action accepts as typed arguments.
* Readonly entities for every response schema, each keeping the raw response on `raw` so a
  field added to the API after this release is readable without a release of this package.
* Request builders whose named constructors make the specification's conditional rules
  unexpressible when wrong — `TakeBackup`, `UploadImage`, `CreateServer`, `Resize`,
  `SizeOptions`, `AdvancedFeatures`, `ImageOptions`, `ChangeImage`, `License`,
  `ThresholdAlert`.
* `Actions::await()`, which is the loop this API needs and which raises for each of the three
  ways an action stops without completing.

### Behaviour worth knowing before the first release

* **Every `ServerActions` method returns `?Action`.** The specification declares a bodiless
  `202` alongside the `200` on every one of them, so null means "accepted, nothing to follow".
  One action measured against the live API answered `200` with the action, so the nullable
  return is defensive rather than routine — but it is declared on all 42 and stays.
* **`Action` reads an undocumented `error_message`.** The specification declares it on `Image`
  and not on `Action`, and the API returns it on an action anyway. It is the only field that
  could explain a failure: `reason` narrates what was being attempted and reads identically
  whether the action worked or not. `Action::failureReason()` and `ActionFailedException`
  prefer it and say plainly when there is nothing to say.
* **`result_data` on an errored action is an empty string, not null** — so `=== null` is not
  the test for "no answer". `Action::hasResult()` is.
* **A question-shaped action answers by COMPLETING OR ERRORING, not in its payload**, and
  `ServerActions::ask()`, `checkRunning()` and `checkUptime()` exist because of it. Measured
  both ways on 13 September 2026 against a server stopped and then started: `is_running`
  completed with a null `result_data` when the server was up and errored when it was down;
  `uptime` did the same with `"0 days,  0:02"` in place of the null. Because `Actions::await()`
  raises on an errored action, asking "is this server running?" the obvious way THROWS when the
  answer is no — these map that to `false` instead, while still raising for a blocked action or
  a timeout, neither of which is an answer.
* **An uptime is a preformatted string** — `"0 days,  0:02"`, doubled space and all. There is
  no numeric form.
* **A 401 carries no body**, measured against the live API on 12 September 2026 —
  `content-length: 0`, byte-identical for a bad token and for no token. `NotAuthenticatedException`
  writes its own message because there is nothing to quote.
* **A 404 on an unrouted path is also bodiless**, despite the specification declaring
  `ProblemDetails` for every 404 it documents. So `problem` is null on a mistyped path and
  populated on a real object that is missing.
* **The API sends `X-Spec-Version`** (`0.40.0` when measured) and the specification declares no
  response headers at all. `ResponseMeta` keeps every header, and `specVersion()` names that one.
* **`status` IS NOT A POWER STATE, and `Server` has no `isRunning()` because of it.** Measured
  on 13 September 2026: a server that was genuinely powered off reported `active`, exactly as
  its sixteen running neighbours did, and no other field in the payload carries a power state.
  `active` means provisioned and in service. The API's own design agrees — it provides a
  dedicated `is_running` ACTION, which would be redundant if the field answered the question.
  `Server::isInService()`, `isExplicitlyPoweredOff()` and `permitsPowerOn()` replace the three
  methods that read the field as though it were a power state. `ServerStatus::Off` was never
  observed on a server that was in exactly that condition, so treat its absence as meaningless
  and its presence as reliable.
* **`ServerStatus::Off` has the wire value `off`**, which YAML 1.1 parsers read as boolean
  `false`. Anything generated from the published specification without accounting for that gets
  a client that cannot recognise a powered-off server when one is reported.
* **`ImageStatus::New` is upper case** on the wire — `NEW` — alone among the four.
* **Pagination follows the API's `next` link** rather than incrementing a page number, and
  refuses a link that does not point at the configured API. A link out of a response body is
  requested with the account's bearer token attached.
* **DNS record TTLs cannot be chosen, and a different one is IGNORED rather than refused.**
  Measured: a record created with no TTL came back 3600, and a subsequent update setting 300
  was accepted with no error and left the record on 3600. A silent no-op is the reason the
  package does not offer the field at all.
* **DNS names use `@` for the apex**, not an empty string — measured on a real zone. An empty
  name is converted rather than sent.
* **The DNS record update is a PUT that retains what it is not given**, with empty string
  clearing and null keeping — the opposite of the create. Measured: a record updated with only
  `data` kept its name. `DomainRecords::update()` takes an array so that distinction is
  expressible.
* **The `?type=` and `?name=` filters on the record list are honoured** — measured, which
  matters because `DomainRecords::upsert()` decides whether to create or replace on the
  strength of a filtered list.
* **The `?image=` filter on the size list restricts which SIZES come back**, dropping the ones
  that image cannot be installed on: 21 sizes became 13 for a SQL Server edition and 17 for
  Windows Server 2025. So the raw catalogue is the wrong thing to build a create form from.

### Known unknowns

What a single account and a single run could not settle. Each has a harness exercise pointed at
it, so re-running on a different account may answer them.

* Whether any server action answers `202` in practice. The one measured answered `200`.
* Whether `ping` follows the same complete-or-error pattern as `is_running` and `uptime`. It
  is the same shape and has not been watched.
* Whether `ServerStatus::Off` is ever reported. A guest-initiated shutdown leaves `active`, and
  BinaryLane's own web UI offers no power-off at all — only power-cycle — so an API-initiated
  `power_off` or `shutdown` is the only thing that could set it, and that was not tested.
* Whether a size kept by the `?image=` filter ever has its region list narrowed. The filter is
  honoured — it drops sizes the image cannot be installed on — but no narrowing was observable
  on an account where every distribution is offered in every region.
* Whether the granularity rules on memory, disk and transfer — documented for the resize
  request — also govern a create. `SizeOptions::validateGranularity()` is opt-in for that
  reason: it is called by `validateAgainst()` and not by the setters.
