# Bundled data service

`CliAnything.php` here is a byte-for-byte copy of
`plugins/Commands/Support/CliAnything.php`.

It exists for one reason: the chatbot must work when only `plugins/chatbot/`
is copied into a **stock SLiMS 9 Bulian**. `Chatbot\Engine::serviceClass()`
resolves the service in this order, and stops at the first hit:

1. `Commands\Support\CliAnything` — already loaded by the Commands plugin.
2. `plugins/Commands/Support/CliAnything.php` — the file, even when that
   plugin is not activated.
3. `plugins/chatbot/Support/vendor/CliAnything.php` — this copy.

The copy is only `require_once`d when the class is still missing, so having
both plugins installed never loads two implementations and never redeclares
the class.

## Keeping it in sync

Edit the real file, then copy it over and commit both:

```bash
cp plugins/Commands/Support/CliAnything.php plugins/chatbot/Support/vendor/CliAnything.php
php plugins/chatbot/tests/run.php    # "stock: the bundled service copy is … in sync"
```

The self-test fails when the two files differ, so a forgotten copy cannot
ship silently.
