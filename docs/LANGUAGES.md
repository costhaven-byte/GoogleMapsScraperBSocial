# Languages

The interface ships in **English** and **Arabic**. Each person picks one from the button in the top bar; the choice is a cookie on their own device, lasts a year, survives signing out, and changes nothing for anyone else. `APP_LOCALE` in `.env` sets what a new visitor sees first.

Arabic pages render right to left: the layout sets `dir="rtl"`, and the stylesheet uses logical properties (`margin-inline`, `padding-inline-start`, `text-align: start`) so nothing needed a mirrored copy. Dates, month names and "3 hours ago" follow the language through Carbon; phone numbers, emails, URLs, tokens and the worker log stay left-to-right inside an RTL page, because mixing digits and Latin text into RTL flow reorders them on screen.

## What is translated, and what isn't

| | Follows the language |
|---|---|
| ✔ | Every page, button, form label, filter, validation message, flash message and error page |
| ✔ | Run status and type, user roles, contact tiers, Hot/Warm/Cold |
| ✔ | Scoring area names, suggested packages, website status, contactability reason codes |
| ✔ | The **How to use** guide |
| ✘ | Free text the worker composed and saved with a result: the activity evidence ("Recent owner reply: 3 weeks ago"), the reason behind each score, the contact "why", and the worker log |
| ✘ | CSV column headings, and the business data itself (names, categories, addresses) |

The worker writes English because a result is stored once and read in either language afterwards; translating it at display time would mean parsing sentences it built from measurements. The fixed vocabularies above *are* translated, because the worker sends them as stable values (a key like `paidMedia`, or the exact string `New website`) that map cleanly.

CSV headings stay English on purpose: the files are opened in Excel and in scripts, and stable column names matter more there than readability.

## Where the strings live

```
lang/
  en/app.php       every interface string
  en/data.php      the worker's fixed vocabularies
  en/help.php      the How to use guide
  ar/app.php       ┐
  ar/data.php      │ the same keys in Arabic
  ar/help.php      ┘
  ar/validation.php, auth.php, passwords.php, pagination.php
                   Laravel's own messages; any key not listed falls back to English
```

`app/Support/Vocab.php` maps the worker's values to `data.php`, and passes anything it doesn't recognise straight through — so a newer worker that invents a package name shows that name rather than a missing-key error.

The browser bundle gets its handful of strings from `app.js` in `lang/*/app.php`, delivered as JSON in the `data-i18n` attribute on `<body>`. It cannot be an inline `<script>`: the Content-Security-Policy forbids those.

## Adding or changing a string

1. Add the key to `lang/en/app.php` and use `__('app.…')` in the view.
2. Add the same key to `lang/ar/app.php`.
3. Run the check:

```bash
php artisan lang:check
```

It reports any key present in one language but missing from the other, and fails with a non-zero exit code, so it can go in a pre-deploy script. `tests/Feature/LocalizationTest.php` runs it as part of `php artisan test`.

For `help.php` and `data.php` only the structure is compared, because their inner keys are the translated terms themselves.

## Adding a third language

1. Add it to `locales` in `config/gscraper.php` with its native name and whether it is right-to-left.
2. Copy `lang/en/` to `lang/<code>/` and translate; `php artisan lang:check` will list what is still missing.
3. Nothing else changes — the switcher, `<html lang>` and `dir` all read that config.
