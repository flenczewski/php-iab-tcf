# Security Policy

## Supported versions

| Version | Supported |
|---------|-----------|
| 2.x     | Yes       |
| 1.x     | No        |

## Known issue in 1.x

All 1.x releases are affected by a memory-exhaustion denial of service in TC
String decoding. The range-list decoder expanded every entry without an upper
bound, so a few kilobytes of attacker-controlled input could allocate hundreds
of megabytes or exhaust memory outright.

This matters because TC Strings normally arrive from cookies and query
parameters — untrusted input by definition. **Upgrading to 2.0.0 is the fix.**
There is no configuration-level mitigation in 1.x; if you cannot upgrade, bound
the length of TC Strings you accept before passing them to the decoder, which
reduces but does not eliminate the exposure.

## Reporting a vulnerability

Please report security issues privately through
[GitHub Security Advisories](https://github.com/flenczewski/php-iab-tcf/security/advisories/new)
rather than opening a public issue.

Include the affected version, a description of the impact, and the smallest
input that demonstrates the problem. Please do not include a weaponised
exploit — a description of the class of issue and a minimal reproducer is
enough to act on.

You can expect an acknowledgement within 7 days and an assessment within 30
days. Fixes for confirmed issues are released as promptly as the severity
warrants, and reporters are credited in the changelog unless they prefer
otherwise.

## Scope

This package parses untrusted input, so decoding is the primary attack surface:
`TcStringDecoder`, `RangeSection`, `Base64Url`, `BitReader` and the GVL parser.
`Gvl::bundled()` reads a file shipped with the package; `GvlFetcher` performs
outbound HTTPS requests to the IAB vendor-list host.
