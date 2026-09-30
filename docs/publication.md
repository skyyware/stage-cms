# Validate the content being published

A draft can be unfinished. Publication must satisfy the page type's required
fields and any rule supplied by the application. All publication paths use the
same rule: PHP create/save with publication, the browser, and the API.

Implement `PublicationRule` and pass it to the application's `Cms` constructor:

```php
use StageCms\Content\PublicationCandidate;
use StageCms\Content\PublicationRule;
use StageCms\Failure;

final readonly class EditorialReview implements PublicationRule
{
    public function validate(PublicationCandidate $candidate): void
    {
        if ($candidate->draft->type === 'service'
            && trim($candidate->draft->fields['source.url'] ?? '') === '') {
            throw new Failure(422, 'source_required', 'Add the official source.', [
                'fields.source.url' => 'An official source is required.',
            ]);
        }
    }
}

$cms = new StageCms\Cms($config, $types, $locales, new EditorialReview());
```

Declare `source.url` in the application's page type. This example only checks
presence. Applications own authority, freshness, completeness, and source review;
a URL alone proves none of them. Use required fields for simple presence checks.
A publication rule is useful for conditions across fields or trusted local data.

The candidate contains the page ID, the new revision number, and its complete
immutable draft. Validate that candidate, not a previous publication or a stale
editorial read. Permission and expected-version checks run first. A thrown
`Failure` rolls back publication; a successful check records the candidate and
moves the live pointer in the same transaction. Reconcile stale content before
retrying. Validation has no external side effects and should not call a network
service while the database writer lock is held.

Construct the same configured Cms instance for every write surface. The generic
package CLI handles setup, identity, export, restore, and diagnosis; it does not
load application-specific types or rules. Application scripts that mutate
content must use the application's bootstrap.

A successful check is evidence at publication time. Public reads do not rerun
rules. Applications serving time-sensitive information must also exclude expired
content at read time. A portable restore reinstates historical publications;
review their current validity before opening the restored site to traffic.
