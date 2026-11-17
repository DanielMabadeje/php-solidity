# Security policy

PHP-Solidity generates code for systems that hold money. We take reports about it seriously.

## What counts as a security issue

Report privately if the transpiler can produce Solidity that:

- behaves differently from what the PHP source says in a way that could lose funds or bypass a check (a wrong operator, a dropped statement, a mis-resolved name),
- silently drops a safety check such as a `require`, a modifier or a reentrancy guard, or
- is accepted by the validator but contains a construct the validator claims to reject.

Ordinary bugs (a crash, a rejected valid construct, output that does not compile) belong in a normal issue.

## How to report

Use GitHub's private vulnerability reporting: **Security → Report a vulnerability** on the repository. If that is unavailable, email mabadejedaniel1@gmail.com with the subject "php-solidity security".

Please include the smallest PHP contract that reproduces the problem, the Solidity that was generated, and the Solidity you expected.

## What to expect

We will acknowledge a report within a few days, work on a fix with you, and credit you in the changelog if you wish. Please give us reasonable time to release a fix before disclosing publicly.

## Supported versions

This project is pre-1.0. Only the latest release receives fixes.

## Reminder for users

The transpiler is unaudited. Review the generated Solidity, test the compiled contract, and get an independent audit before deploying anything of value. See [docs/security.md](docs/security.md).
