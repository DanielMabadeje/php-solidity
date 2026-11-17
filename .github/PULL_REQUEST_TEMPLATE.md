## What does this change?

<!-- One or two sentences. Link the issue it closes, if any. -->

## Checklist

- [ ] `composer check` passes (PHPStan level 8 and the test suite)
- [ ] New behaviour has a test (a golden fixture for emitter changes, a data-provider row for validator rules)
- [ ] If the generated Solidity changed on purpose: ran `composer golden:update`, reviewed the diff, and `composer solc:check` passes
- [ ] Docs updated (`docs/language-reference.md` for syntax, `docs/validator.md` for rule codes)
- [ ] `CHANGELOG.md` has an entry under "Unreleased"
