# BinaryLane API client for PHP

[![Tests](https://github.com/hampel/binarylane-api/actions/workflows/tests.yml/badge.svg)](https://github.com/hampel/binarylane-api/actions/workflows/tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/hampel/binarylane-api.svg?style=flat-square)](https://packagist.org/packages/hampel/binarylane-api)
[![Total Downloads](https://img.shields.io/packagist/dt/hampel/binarylane-api.svg?style=flat-square)](https://packagist.org/packages/hampel/binarylane-api)
[![Open Issues](https://img.shields.io/github/issues-raw/hampel/binarylane-api.svg?style=flat-square)](https://github.com/hampel/binarylane-api/issues)
[![License](https://img.shields.io/packagist/l/hampel/binarylane-api.svg?style=flat-square)](https://packagist.org/packages/hampel/binarylane-api)

By [Simon Hampel](mailto:simon@hampelgroup.com)

A PHP client for the [BinaryLane API](https://api.binarylane.com.au/reference), built on
**PSR-18**. Complete coverage of the v2 API: servers and all forty-two server actions, DNS,
images, load balancers, VPCs, SSH keys, billing and performance data.

## Installation

```bash
composer require hampel/binarylane-api
```

You also need a PSR-18 client and a PSR-17 factory. Guzzle provides both, 7 or 8:

```bash
composer require guzzlehttp/guzzle
```

## Usage

```php
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Psr7\HttpFactory;
use Hampel\BinaryLane\Api\Client;

$binarylane = Client::withToken('MY-API-TOKEN', new Guzzle());

$binarylane->verify();                       // does this token work?

foreach ($binarylane->servers()->each() as $server) {
    echo $server->describe(), "\n";
}
```

`withToken()` finds a PSR-17 factory for you — Guzzle's, Nyholm's or Diactoros', whichever is
installed. The long form names everything:

```php
use Hampel\BinaryLane\Api\Authentication\ApiToken;
use Hampel\BinaryLane\Api\Config;

$factory = new HttpFactory();   // PSR-17, fills both the request and stream roles

$binarylane = new Client(
    new Config(perPage: 200),
    new ApiToken('MY-API-TOKEN'),
    new Guzzle(),
    $factory,
    $factory,
    $logger,                    // PSR-3, optional
);
```

Requests are logged at `debug`, and nothing else is. A failure is raised rather than logged, so
an application that logs what it catches records each one once — and `find()` returning null, or
`checkRunning()` answering false, records nothing. The token is never logged: `ApiToken`
keeps it out of `__toString()`, `var_dump()` and stack traces.

## Before you start

Three things about this API change how code against it has to be written, and none of them is
what most API clients lead you to expect.

### 1. Nearly every mutation answers with an action, not a result

Powering a server on, resizing it, rebuilding it, taking a backup, creating a server — none of
those have finished when the call returns. What comes back is an `Action` with an id you can
ask about again.

```php
$action = $binarylane->serverActions()->powerOn(1234);

$binarylane->actions()->await($action);      // raises if it failed
```

`await()` is the loop, and it exists because writing it correctly means knowing the three ways
an action stops without completing:

| what happened | how it is reported | exception |
|---|---|---|
| it errored | status `errored` | `ActionFailedException` |
| it is asking a question | still `in-progress`, indefinitely | `ActionBlockedException` |
| an unpaid invoice is blocking it | still `in-progress`, indefinitely | `ActionBlockedException` |
| your deadline passed | still running — **nothing is cancelled** | `ActionTimedOutException` |

A `while ($action->status !== 'completed')` loop never escapes the middle two.

**Do not read `reason` as an explanation.** It narrates what was being attempted and reads the
same whether the action worked or not — an errored `uptime` action carries *"Your server uptime
is being checked"*. `Action::failureReason()` reads `error_message` instead, which the
specification does not declare on an action and the API returns anyway, and answers null rather
than something misleading when there is nothing to say.

Some actions can also be answered with `202` and no body at all, in which case there is no
action to wait on. Every method on `ServerActions` returns `?Action` for that reason, and
`Servers::actions($id)` is where to look for what a `null` started.

### 2. Token scope: there isn't any

BinaryLane issues one kind of API token — no scopes, no expiry, no read-only variant. A token
given to a monitoring job is a token that could cancel a server. The separation has to be on
your side.

### 3. A 404 does not mean deleted

An object belonging to another account answers `404`, not `403`. So `find()` returning `null`
means "not visible to this token", which is wider than "gone".

## Failures

Everything this package throws implements `Hampel\BinaryLane\Api\Exception\ExceptionInterface`.

```php
use Hampel\BinaryLane\Api\Exception\NotAuthenticatedException;
use Hampel\BinaryLane\Api\Exception\ValidationException;

try {
    $binarylane->servers()->create($request);
} catch (ValidationException $e) {
    foreach ($e->fieldErrors() as $field => $messages) {
        echo $field, ': ', implode('; ', $messages), "\n";
    }
} catch (NotAuthenticatedException $e) {
    // the token is not usable
}
```

| status | exception | notes |
|---|---|---|
| 400 | `ValidationException` | the only status carrying `errors`, keyed by JSON property name |
| 401 | `NotAuthenticatedException` | **carries no body at all** |
| 403 | `NotPermittedException` | declared on exactly one operation — image download |
| 404 | `NotFoundException` | also means "belongs to someone else" |
| 429 | `TooManyRequestsException` | not in the specification; mapped defensively |
| 5xx | `ServerException` | a `504` can come from the gateway while the request carries on — re-read before retrying a write |
| other 4xx | `ClientException` | |
| 2xx this package cannot act on | `MalformedResponseException` | not JSON, an empty body on any status but 202/204, or a body missing its envelope key — a proxy page read as an empty list is the accident this prevents |
| never answered | `RequestException` | DNS, TLS, timeout — **the request may still have been carried out**; re-read before retrying a write |

An action that fails raises `ActionFailedException`, `ActionBlockedException` or
`ActionTimedOutException`, none of which are `ApiException` — every request succeeded; the
*work* did not.

## Pagination

Lists come back as a `Page`, which counts, iterates and serialises:

```php
$page = $binarylane->servers()->list(perPage: 200);

$page->total;        // how many exist across every page
count($page);        // how many are on this one
$page->hasMore();
```

`each()` walks every page as far as it is consumed, following the API's own `next` link — so
stopping early stops making requests:

```php
foreach ($binarylane->servers()->each() as $server) {
    if ($server->isUnderMaintenance === true) {   // null: BinaryLane did not check
        break;                                // no further requests are made
    }
}
```

`count()` asks for the total without fetching anything:

```php
$binarylane->servers()->count();              // one request, no items
```

The default page size is **20**, which is smaller than most people expect. A list that looks
complete is often the first twenty of several hundred.

## Servers

```php
$server = $binarylane->servers()->get(1234);

$server->publicAddress();                     // null is legitimate - see below
$server->isInService();                       // provisioned and paid for - NOT "powered on"
$server->isActionable();                      // false under maintenance, or while building
$server->selectedSizeOptions?->memory;        // what THIS server has, in MB
```

**`status` does not tell you whether a server is powered on.** A server that was genuinely off
reported `active`, exactly as its running neighbours did, and nothing else in the payload
carries a power state. That is why there is no `isRunning()` on `Server` — there was one, and
it was wrong on precisely the server it mattered for. Ask the API instead:

```php
$binarylane->serverActions()->checkRunning(1234);     // bool
$binarylane->serverActions()->checkUptime(1234);      // "0 days,  0:02", or null
```

**A question-shaped action answers by completing or erroring, not in its payload.** Measured
both ways: `is_running` completes with a *null* `result_data` when the server is up and errors
when it is down. Since `await()` raises on an errored action, asking the obvious way throws
when the answer is simply "no" — which is what `checkRunning()` and `ask()` exist to avoid.

```php
$action = $binarylane->serverActions()->ask(1234, 'is_running');   // ?Action; null means "no"
```

Read `selectedSizeOptions` rather than `size` for what a server actually has. The size is the
catalogue entry it was created from; the two disagree on any server that has ever been given
extra memory, disk, transfer or addresses.

A server can legitimately have no public address at all — declining IPv4 is a discounted
option, and a VPC-only server may have nothing routable.

### Creating one

```php
use Hampel\BinaryLane\Api\Request\CreateServer;
use Hampel\BinaryLane\Api\Request\SizeOptions;

$created = $binarylane->servers()->create(
    CreateServer::of('std-2vcpu', 'ubuntu-24-04-lts', 'syd')
        ->withName('vps01.example.com')
        ->withSshKeys([12345])
        ->withOptions(SizeOptions::none()->withMemory(8192)->withDailyBackups(7))
);

$binarylane->actions()->awaitAll($created->actionIds(), timeout: 1800);

$server = $binarylane->servers()->get($created->id());
```

Four defaults are decided for you by leaving a field unset, and each surprises somebody:

- **no name** — a random hostname is generated;
- **no password** — one is generated and **emailed to the account address**, not returned;
- **no SSH keys** — every key marked *default* on the account is deployed. `withoutSshKeys()`
  sends an empty array, which deploys none. Null and empty are opposite answers;
- **port blocking on** — outgoing TCP 22, 25 and 3389 are blocked. Turning it off needs a
  verified account.

`validateAgainst($image, $size)` checks the request against what the image and size actually
permit, so the mismatch is named rather than arriving as a 400 about a field:

```php
CreateServer::for($size, $image, 'syd')->validateAgainst($image, $size);
```

### Server actions

All forty-two, typed:

```php
$binarylane->serverActions()->powerOn(1234);
$binarylane->serverActions()->rename(1234, 'vps02.example.com');
$binarylane->serverActions()->changeKernel(1234, 7);
$binarylane->serverActions()->addDisk(1234, 50, 'Data');
```

Four of them destroy data with no confirmation, in BinaryLane's own words:

- `rebuild()` and `restore()` discard the server's disks;
- `cloneUsingBackup()` is addressed to the **source** server and overwrites the **target**;
- `resize()` when it carries an image change, or reduces a retained backup count.

That last one is the quiet one — reducing `weeklyBackups` to 0 deletes every weekly backup the
server has. `Request\Resize` will tell you what a particular request would do:

```php
use Hampel\BinaryLane\Api\Request\Resize;
use Hampel\BinaryLane\Api\Request\TakeBackup;
use Hampel\BinaryLane\Api\Enum\BackupSlot;

$resize = Resize::toSize('std-4vcpu')
    ->withOptions(SizeOptions::none()->withWeeklyBackups(0));

$resize->isDestructive($server);              // true
$resize->hazards($server);                    // ['4 of the 4 retained weekly backups will be deleted']

$resize = $resize->withPreActionBackup(TakeBackup::intoFreeSlot(BackupSlot::Temporary));
```

Every option on a resize is an **absolute value**, not a delta: `withMemory(8192)` means "have
8192 MB". A resize built by adding to the current numbers doubles them.

For an action added to the API after this release:

```php
$binarylane->serverActions()->perform(1234, 'some_new_action', ['field' => 'value']);
```

## DNS

Zones are addressed by name. Names are normalised on the way in — lower-cased, trimmed, and
stripped of a trailing dot.

```php
$records = $binarylane->domains()->records('example.com');

$records->create(DomainRecord::a('www', '203.0.113.10'));
$records->create(DomainRecord::mx('mail.example.com', priority: 10));
$records->create(DomainRecord::txt('_dmarc', 'v=DMARC1; p=quarantine'));
$records->create(DomainRecord::caa('issue', 'letsencrypt.org'));
```

Four things differ from most DNS APIs:

- **the apex is `@`**, not an empty string, and `*` is a wildcard. An empty name is converted
  rather than sent;
- **an MX or SRV target ends in a dot** — `mail.example.com.` An MX without one is refused; an
  SRV without one is accepted and read relative to the zone, so `sip.example.com` would point at
  `sip.example.com.<zone>.` The dot is added when the record is sent, however it was built, and a
  single-label target is refused. CNAME and NS targets are read as full names either way;
- **the TTL is fixed at 3600** and cannot be chosen — "the default and only supported value",
  says the specification. A different value is accepted by the API and ignored, so none is sent;
- **the update is a `PUT` that retains what it is not given**, with empty string clearing a
  value and null keeping it. That is backwards from the create, so `update()` takes an array
  and passes it through unfiltered:

```php
$records->update(42, ['data' => '203.0.113.20']);   // just the address
$records->update(42, ['tag' => '']);                // clear the tag
$records->replace(42, DomainRecord::a('www', '203.0.113.20'));
```

`upsert()` is the operation a DNS updater wants and the API does not offer. It refuses rather
than guesses when several records share a name and type, because that is round-robin and not a
duplicate:

```php
$records->upsert(DomainRecord::a('www', '203.0.113.10'));
```

`domains()->records($zone)` binds the zone for a sequence of calls. `$binarylane->records()` is
the same endpoint unbound, taking the zone as its first argument — worth reaching for when the
zone varies per call rather than per block:

```php
$binarylane->records()->get('example.com', 42);
```

Adding a zone here does not delegate it. `Domain::$currentNameservers` is what the domain
**actually** resolves to:

```php
$domain = $binarylane->domains()->get('example.com');

$domain->isDelegatedTo($binarylane->domains()->publicNameservers());   // false = serving nothing
```

## Everything else

```php
$binarylane->account()->get();
$binarylane->actions()->list();
$binarylane->billing()->unpaidFailedInvoices();     // what blocks new services
$binarylane->dataUsages()->total();                 // the allowance is POOLED
$binarylane->domains()->list();
$binarylane->images()->distributions();
$binarylane->loadBalancers()->availability();
$binarylane->regions()->available();
$binarylane->reverseNames()->all();
$binarylane->sampleSets()->latest(1234);
$binarylane->sizes()->forImage('ubuntu-24-04-lts'); // only the sizes it can install on
$binarylane->software()->availableFor('ubuntu-24-04-lts');
$binarylane->sshKeys()->defaults();                 // deployed to every new server
$binarylane->vpcs()->serverIds(3);
```

Two of those repay a second look. `sizes()->list()` is the raw catalogue and the wrong thing to
build a create form from: it contains sizes your image cannot be installed on at all. Filtering
on a SQL Server edition took a 21-size catalogue down to 13. And data transfer allowance is
pooled across the account, so a single server over its own number may be fine; the total is the
comparison that means something.

## Extending it

Every endpoint is an `Endpoint` subclass, and `Client::endpoint()` will construct anybody's:

```php
use Hampel\BinaryLane\Api\Endpoint\Endpoint;

final class Fleet extends Endpoint
{
    /** @return \Generator<int, Server> */
    public function idle(): \Generator
    {
        return $this->apiEach('servers', 'servers', Server::fromArray(...));
    }
}

$binarylane->endpoint(Fleet::class)->idle();
```

There is nothing to register and no container — the class *is* the registration, and static
analysis follows the return type through. Or reach the transport directly:

```php
$binarylane->connection()->get('some/new/path')->collection('things');
```

## Entities

Every entity is a readonly value object with a `fromArray()` and a `raw` property holding the
response as it arrived — so a field added to the API after this release is readable without
waiting for a release of this package:

```php
$server->raw['something_new'];
```

They are `JsonSerializable`, and round-trip through `json_encode()` as the API sent them.

Where a value carries a credential — console URLs, user-data, image download links, invoice
URLs — the entity withholds it from `var_dump()` and `print_r()`.

## Testing against it

The package takes any PSR-18 client, so the seam it exposes to you is the seam its own suite
drives it through. A stub implementing `sendRequest()` is all a test needs; in Laravel,
`Http::fake()` works, and `Http::preventStrayRequests()` reaches your test naming the URL
rather than being dressed up as a transport failure.

## Version support

PHP 8.3, 8.4 and 8.5. CI runs the floor with `--prefer-lowest`, the floor with current
dependencies, and the ceiling; PHPStan runs at level 10 across the whole PHP range.

This package wraps specification version 0.40.0. BinaryLane describes the specification as in
active development, and states that breaking changes are possible without the API version
changing. The `X-Spec-Version` response header reports which version answered.

## Licence

MIT. See [LICENSE.md](LICENSE.md).
