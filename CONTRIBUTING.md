# Contributing

Thanks for helping out. Please keep these rules in mind so releases stay
automatic.

## Commit messages

This project uses [Conventional Commits](https://www.conventionalcommits.org/).
[release-please](https://github.com/googleapis/release-please) reads them to
pick the next version and to write the changelog, so the prefix matters:

| Prefix                          | Effect                    |
|---------------------------------|---------------------------|
| `fix:`                          | patch release (1.0.x)     |
| `feat:`                         | minor release (1.x.0)     |
| `feat!:` or `BREAKING CHANGE:`  | major release (x.0.0)     |
| `docs:`, `chore:`, `refactor:`, `test:`, `ci:` | no release |

Write the subject in English, imperative mood, under 72 characters:

```
fix: restart Horizon supervisors after a crash
feat(lang): add Vietnamese translation
```

## Before opening a pull request

```bash
composer install
composer test      # Pest
composer format    # Pint
composer analyse   # PHPStan level 6
```

All three must pass. CI runs them against PHP 8.2 - 8.5 and Laravel 12 - 13.

## Translations

Every file in `lang/*/dashboard.php` must define the same keys as `lang/en`.
The `LangParityTest` enforces this. Keep column labels short, they are table
headers in a terminal.

## Releases

Maintainers merge the release PR that release-please opens on `main`. That
creates the tag and the GitHub release; Packagist picks the tag up on its own.
