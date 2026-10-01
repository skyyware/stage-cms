# Contribute to Stage CMS

Start with a complete user workflow. Explain who needs the change and how they
will know it works. Keep the default application small enough to understand.
Discuss new subsystems before building them.

Bug reports should include the release, PHP version, steps to reproduce,
expected behavior, and observed behavior. Do not include credentials, real
content, database copies, or private exports.

For a change:

1. Fork and clone the repository, then run `composer install`.
2. Read `AGENTS.md` and the architecture. Keep related behavior together.
3. Add a meaningful regression test when behavior or a boundary changes.
4. Run `composer check`.
5. For interface work, test an actual editing/publication flow on desktop and
   mobile, with keyboard navigation. Use synthetic content in screenshots.
6. Open a focused pull request describing the problem, behavior, and evidence.

Humans and coding agents meet the same standards. Content operations own their
permission checks. Treat untrusted content as data. Preserve other people's
work, and never include runtime data or secrets in a patch.

Reports, documentation fixes, accessibility work, and small improvements are
welcome. Be considerate and specific in reviews. By contributing, you agree
that your changes are available under this project's MIT license.

Run validation locally; repository automation is disabled. Maintainers follow
[RELEASING.md](RELEASING.md) for package and application-consumer checks.
