# Security Policy

## Supported versions

`jul6art/api-bundle` is installed by other applications through Composer, so a fix here
reaches them the moment they update. Only the current major line gets one.

| Version | Supported |
| --- | --- |
| `1.x` | ✅ |
| any older tag or fork | ❌ |

Support means security fixes on the latest release of that line — upgrade to it before
reporting, in case the problem is already gone.

## What is in scope

Everything here turns HTTP input into database queries, which is the whole reason to look
closely:

* **Injection through a filter** — a property name or a value reaching DQL or SQL without
  being validated or bound, including inside the JSON-column and multi-column search filters
  where the expression is built by hand.
* **A filter reading a property the resource does not expose**, or traversing a relation it
  should not, and so returning data the operation was never meant to return.
* **A state provider that loses the operation's security expression**, its pagination
  bounds, or applies them after loading rather than before.
* **The tenant header trusted without a check** — a client choosing its own tenant, or a
  missing header falling back to "all tenants" instead of refusing.
* **An unbounded collection** reachable through a filter or provider — a page size a client
  can raise at will is a denial of service against the database.

Out of scope: vulnerabilities in Symfony, Doctrine, API Platform or any other third-party
package — report those to the project that owns the code, and they will reach you through
your own `composer update`. Also out of scope: an application that misconfigures this bundle
in a way the README warns against, though a warning that turns out to be easy to miss is
worth an issue of its own.

## Reporting a vulnerability

**Do not open a public issue for a security problem.**

Use [GitHub's private vulnerability reporting](https://github.com/jul6art/api-bundle/security/advisories/new)
(the **Security** tab → *Report a vulnerability*). It opens a draft advisory only
you and the maintainers can read, and it is the channel this project prefers —
no email address needs to be published for it to work.

Please include:

* the version of `jul6art/api-bundle` and of Symfony you are running,
* the relevant part of your bundle configuration,
* the shortest reproduction you have — ideally a failing test against this
  repository, since that is what a fix will be built on,
* what an attacker gains: which check is bypassed, which data is read or
  written, and whether authentication is required.

## What to expect

* An acknowledgement within **7 days**.
* An assessment — accepted, out of scope, or needing more detail — within
  **14 days**.
* For an accepted report: a fix released on the supported line, a
  [security advisory](https://github.com/jul6art/api-bundle/security/advisories)
  describing the impact and the version to upgrade to, and credit in it unless
  you ask otherwise.

Please give the maintainers a reasonable window to ship a release before disclosing
publicly. This project runs no bug-bounty programme and offers no payment.