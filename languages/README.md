# Translations

English is the source language. German ships with the plugin: a site set to German picks it up
automatically, and every other language falls back to English.

| File | What it is |
| --- | --- |
| `snn-tickets.pot` | The template: every translatable string, untranslated. Start here for a new language. |
| `snn-tickets-de_DE.po` / `.mo` | German, formal address (*Sie*), the usual register for event and shop sites. |
| `snn-tickets-de_DE_formal.po` / `.mo` | The same, for sites set to *Deutsch (Sie)*. |
| `snn-tickets-de_AT.po` / `.mo` | The same, for Austrian German. |
| `snn-tickets-de_CH.po` / `.mo` | The same with Swiss spelling (*ss* instead of *ß*). |

The German files are copies on purpose: WordPress looks for one exact file name per locale and does
not fall back from `de_AT` or `de_CH` to `de_DE`, so without them those sites would see English.

WordPress loads the compiled `.mo`, not the `.po`. After editing a `.po`, compile it again, for
example with Poedit (it does this on save) or:

```
wp i18n make-mo languages
```

After adding or changing strings in the code, refresh the template first:

```
wp i18n make-pot . languages/snn-tickets.pot --exclude=tests,src
```

Customer-facing wording can also be changed without touching these files: emails on each event's
Emails tab, and the claim page, ticket list and shop texts under Tickets → Settings → Wording.
