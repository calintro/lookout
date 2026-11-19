# Contributing

Bug reports, fixes and small features are welcome.

## Before you start

- For a bug, open an issue with the steps to reproduce, your PHP, Laravel and `nightowl/agent` versions, and what you expected.
- For a larger change, open an issue first so we can agree on the approach before you write the code.
- For a security problem, do not open an issue. See [SECURITY.md](SECURITY.md).

## Setup

```bash
git clone https://github.com/nikolai-vysotskyi/lookout.git
cd lookout
composer install
composer test
```

The suite runs on Orchestra Testbench with SQLite in memory. It does not need PostgreSQL or a running agent.

## Front end

The SPA is plain JavaScript in `resources/js/01..09-*.js` and one stylesheet in `resources/css/app.css`. There is no bundler. After you change them, rebuild and commit `dist/` together with the sources:

```bash
sh bin/build.sh
```

A test fails if `dist/` does not match the sources.

## Pull requests

- Keep a pull request to one change, with a test when the change is in PHP.
- Make sure `composer test` passes on PHP 8.3+.
- Add a line to `CHANGELOG.md` under an `Unreleased` heading.
- By contributing you agree that your work is released under the [MIT license](LICENSE).
