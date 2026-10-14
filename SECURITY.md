# Security policy

This package signs people into admin panels, so a flaw in it is a way into
someone's back office. Please report vulnerabilities privately.

## Reporting

Use GitHub's private vulnerability reporting:
**Security → Report a vulnerability** on
[github.com/dskripchenko/laravel-admin-oidc](https://github.com/dskripchenko/laravel-admin-oidc/security/advisories/new),
or write to denskrp90@gmail.com. Please include the version, the provider
(Keycloak, Entra ID, …) and the steps to reproduce. Do not open a public issue
for a vulnerability.

You will get an answer within five working days. A confirmed issue is fixed in
a patch release of every supported line, and published as a GitHub security
advisory once the release is out.

## Supported versions

| Version | Supported |
|---|---|
| 0.1.x | ✅ |

## Scope

In scope: the sign-in flow, ID token validation, account linking and
provisioning, role mapping, the outgoing request policy, the storage of
client secrets. Out of scope: the identity provider itself, and a host's own
implementations of the package's contracts.
