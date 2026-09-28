<?php
declare(strict_types=1);

namespace StageCms;

/** @template-covariant T */
final readonly class Listing
{
    public const int LIMIT = 50;

    /** @var list<T> */
    public array $items;
    public ?int $nextPage;

    /** @param list<T> $items */
    public function __construct(array $items, public int $number)
    {
        self::offset($number);
        $this->items = array_slice($items, 0, self::LIMIT);
        $this->nextPage = count($items) > self::LIMIT && $number < 1000000 ? $number + 1 : null;
    }

    public static function offset(int $page): int
    {
        if ($page < 1 || $page > 1000000) {
            throw new Failure(422, 'invalid_page', 'Choose a page number between 1 and 1000000.');
        }
        return ($page - 1) * self::LIMIT;
    }
}
