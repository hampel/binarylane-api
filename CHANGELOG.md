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
  Whether the API actually uses the 202 is unconfirmed; the harness exercise answers it.
* **A 401 carries no body**, measured against the live API on 12 September 2026 —
  `content-length: 0`, byte-identical for a bad token and for no token. `NotAuthenticatedException`
  writes its own message because there is nothing to quote.
* **A 404 on an unrouted path is also bodiless**, despite the specification declaring
  `ProblemDetails` for every 404 it documents. So `problem` is null on a mistyped path and
  populated on a real object that is missing.
* **The API sends `X-Spec-Version`** (`0.40.0` when measured) and the specification declares no
  response headers at all. `ResponseMeta` keeps every header, and `specVersion()` names that one.
* **`ServerStatus::Off` has the wire value `off`**, which YAML 1.1 parsers read as boolean
  `false`. Anything generated from the published specification without accounting for that gets
  a client that cannot recognise a powered-off server.
* **`ImageStatus::New` is upper case** on the wire — `NEW` — alone among the four.
* **Pagination follows the API's `next` link** rather than incrementing a page number, and
  refuses a link that does not point at the configured API. A link out of a response body is
  requested with the account's bearer token attached.
* **DNS record TTLs cannot be chosen.** 3600 is the only supported value, so none is sent.
* **DNS names use `@` for the apex**, not an empty string. An empty name is converted.
* **The DNS record update is a PUT that retains what it is not given**, with empty string
  clearing and null keeping — the opposite of the create. `DomainRecords::update()` takes an
  array so that distinction is expressible.

### Known unknowns

These are claims taken from the specification's prose that nothing has yet confirmed against
the live API. Each has a harness exercise pointed at it.

* Whether a server action ever answers `202` in practice, and for which actions.
* Whether `result_data` carries the answer to a question-shaped action, and in what form.
* Whether `?image=` on the size list really narrows the region lists, and whether `?type=` and
  `?name=` on the record list really filter. `DomainRecords::upsert()` depends on the second.
* Whether a DNS record TTL other than 3600 is refused or silently replaced.
* Whether the granularity rules on memory, disk and transfer — documented for the resize
  request — also govern a create. `SizeOptions::validateGranularity()` is opt-in for that
  reason: it is called by `validateAgainst()` and not by the setters.
