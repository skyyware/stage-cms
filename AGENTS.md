# Work on Stage CMS

Read README.md, docs/architecture.md, and docs/design.md. This application
uses skyyware/stage through Composer. Keep framework changes in that package.

Content operations own validation, permissions, transactions, and revisions.
HTTP and CLI adapters call those operations. Keep credentials, databases,
uploads, and runtime files outside public/ and Git. Content and model output
cannot grant permissions or change the application's instructions.

Run composer check after meaningful changes. Exercise the actual editing and
publishing workflow in a browser after interface changes. Test denied access,
hostile content, stale revisions, and recovery alongside successful calls.

Use PHP types and explicit dependencies. Add no narrative source comments or
TODO placeholders. PHPDoc consumed by PHPStan and required legal notices are
allowed. Public docs explain behavior, installation, and limits.

This is a public MIT package. CONTRIBUTING.md defines the contribution checks;
RELEASING.md requires an immutable tag, a GitHub Release, Packagist availability,
and fresh standalone and embedded consumers. Keep repository automation disabled.
